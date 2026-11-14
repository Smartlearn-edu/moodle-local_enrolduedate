<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_enrolduedate;

use advanced_testcase;
use completion_info;
use context_module;
use local_enrolduedate\local\availability_helper;
use local_enrolduedate\local\rule_repository;
use local_enrolduedate\local\sync_manager;
use local_enrolduedate\task\reconcile_scheduled;

/**
 * Integration tests covering sync_manager, observers, manual locks, calendar sync, and notifications.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_enrolduedate\local\sync_manager
 */
final class sync_manager_test extends advanced_testcase {
    /**
     * Tests enrolment + role_assigned event flow creating assign_overrides and calendar events.
     *
     * @return void
     */
    public function test_enrolment_role_assigned_flow_and_calendar(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $coursestart = 1800000000;
        $course = $this->getDataGenerator()->create_course(['startdate' => $coursestart]);
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'allowsubmissionsfromdate' => 0,
            'duedate' => 0,
            'cutoffdate' => 0,
        ]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $rule = rule_repository::upsert_rule((object)[
            'courseid' => $course->id,
            'cmid' => $cm->id,
            'modname' => 'assign',
            'enabled' => 1,
            'triggertype' => rule_repository::TRIGGER_ENROLMENT,
            'refcmid' => null,
            'restrictuntiltriggered' => 0,
            'openoffsetvalue' => 0,
            'openoffsetunit' => rule_repository::UNIT_DAYS,
            'dueoffsetvalue' => 7,
            'dueoffsetunit' => rule_repository::UNIT_DAYS,
            'cutoffoffsetvalue' => null,
            'cutoffoffsetunit' => null,
            'snapmode' => rule_repository::SNAP_EXACT,
            'status' => rule_repository::RULE_STATUS_ACTIVE,
            'usermodified' => 2,
        ]);

        // Enrol student after course start via manual enrolment.
        $student = $this->getDataGenerator()->create_user(['timezone' => 'UTC']);
        $enroltime = $coursestart + 2 * DAYSECS;
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student', 'manual', $enroltime);

        $override = $DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $student->id]);
        $this->assertNotFalse($override);
        $this->assertEquals($enroltime, (int)$override->allowsubmissionsfromdate);
        $this->assertEquals($enroltime + 7 * DAYSECS, (int)$override->duedate);

        $applied = rule_repository::get_applied((int)$rule->id, (int)$student->id);
        $this->assertNotNull($applied);
        $this->assertEquals(rule_repository::APPLIED_STATUS_APPLIED, $applied->status);

        // Verify native calendar action event exists for this user override.
        $calendarevent = $DB->get_record('event', [
            'modulename' => 'assign',
            'instance' => $assign->id,
            'userid' => $student->id,
        ]);
        $this->assertNotFalse($calendarevent);
        $this->assertEquals($enroltime + 7 * DAYSECS, (int)$calendarevent->timestart);
    }

    /**
     * Tests that shifting course startdate triggers recalculation for earliest_floored enrolment rules.
     *
     * @return void
     */
    public function test_course_startdate_shift_recalculates_earliest_floored(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $coursestart = 1800000000;
        $course = $this->getDataGenerator()->create_course(['startdate' => $coursestart]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $student = $this->getDataGenerator()->create_user(['timezone' => 'UTC']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student', 'manual', $coursestart - 5 * DAYSECS);

        $rule = rule_repository::upsert_rule((object)[
            'courseid' => $course->id,
            'cmid' => $cm->id,
            'modname' => 'assign',
            'enabled' => 1,
            'triggertype' => rule_repository::TRIGGER_ENROLMENT,
            'refcmid' => null,
            'restrictuntiltriggered' => 0,
            'openoffsetvalue' => null,
            'openoffsetunit' => null,
            'dueoffsetvalue' => 5,
            'dueoffsetunit' => rule_repository::UNIT_DAYS,
            'cutoffoffsetvalue' => null,
            'cutoffoffsetunit' => null,
            'snapmode' => rule_repository::SNAP_EXACT,
            'status' => rule_repository::RULE_STATUS_ACTIVE,
            'usermodified' => 2,
        ]);

        sync_manager::sync_rule_for_course($rule);
        $before = $DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $student->id], '*', MUST_EXIST);
        $this->assertEquals($coursestart + 5 * DAYSECS, (int)$before->duedate);

        // Shift course startdate forward by 3 days via update_course().
        $newstart = $coursestart + 3 * DAYSECS;
        $course->startdate = $newstart;
        update_course($course);

        $after = $DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $student->id], '*', MUST_EXIST);
        $this->assertEquals($newstart + 5 * DAYSECS, (int)$after->duedate);
    }

    /**
     * Tests manual override conflict detection, teacher lock preservation, unlock action, and extensionduedate.
     *
     * @return void
     */
    public function test_manual_override_protection_unlock_and_extension(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $coursestart = 1800000000;
        $course = $this->getDataGenerator()->create_course(['startdate' => $coursestart]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $student1 = $this->getDataGenerator()->create_user(['timezone' => 'UTC']);
        $student2 = $this->getDataGenerator()->create_user(['timezone' => 'UTC']);
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student', 'manual', $coursestart);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student', 'manual', $coursestart);

        // Pre-existing manual override for student1 before rule exists.
        $manualdue = 1850000000;
        $DB->insert_record('assign_overrides', (object)[
            'assignid' => $assign->id,
            'userid' => $student1->id,
            'duedate' => $manualdue,
        ]);

        // Independent extensionduedate for student2 must remain intact.
        $extdue = 1860000000;
        $DB->insert_record('assign_user_flags', (object)[
            'assignment' => $assign->id,
            'userid' => $student2->id,
            'extensionduedate' => $extdue,
        ]);

        $rule = rule_repository::upsert_rule((object)[
            'courseid' => $course->id,
            'cmid' => $cm->id,
            'modname' => 'assign',
            'enabled' => 1,
            'triggertype' => rule_repository::TRIGGER_ENROLMENT,
            'refcmid' => null,
            'restrictuntiltriggered' => 0,
            'openoffsetvalue' => null,
            'openoffsetunit' => null,
            'dueoffsetvalue' => 7,
            'dueoffsetunit' => rule_repository::UNIT_DAYS,
            'cutoffoffsetvalue' => null,
            'cutoffoffsetunit' => null,
            'snapmode' => rule_repository::SNAP_EXACT,
            'status' => rule_repository::RULE_STATUS_ACTIVE,
            'usermodified' => 2,
        ]);

        sync_manager::sync_rule_for_course($rule);

        // Student1 kept manual override and was locked.
        $override1 = $DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $student1->id], '*', MUST_EXIST);
        $this->assertEquals($manualdue, (int)$override1->duedate);
        $applied1 = rule_repository::get_applied((int)$rule->id, (int)$student1->id);
        $this->assertEquals(1, (int)$applied1->manualoverride);
        $this->assertEquals(rule_repository::APPLIED_STATUS_MANUAL_LOCKED, $applied1->status);

        // Student2 received relative override AND kept extensionduedate.
        $override2 = $DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $student2->id], '*', MUST_EXIST);
        $this->assertEquals($coursestart + 7 * DAYSECS, (int)$override2->duedate);
        $flags2 = $DB->get_record('assign_user_flags', ['assignment' => $assign->id, 'userid' => $student2->id], '*', MUST_EXIST);
        $this->assertEquals($extdue, (int)$flags2->extensionduedate);

        // Simulate teacher editing student2's override externally via event.
        $editeddue = 1855000000;
        $DB->set_field('assign_overrides', 'duedate', $editeddue, ['id' => $override2->id]);
        \mod_assign\event\user_override_updated::create([
            'context' => context_module::instance($cm->id),
            'objectid' => $override2->id,
            'relateduserid' => $student2->id,
            'other' => ['assignid' => $assign->id],
        ])->trigger();

        $applied2 = rule_repository::get_applied((int)$rule->id, (int)$student2->id);
        $this->assertEquals(1, (int)$applied2->manualoverride);

        // Unlock action clears manual lock and re-applies calculated relative due date.
        $this->assertTrue(sync_manager::unlock_manual_override((int)$applied2->id));
        $reapplied2 = $DB->get_record('assign_overrides', ['id' => $override2->id], '*', MUST_EXIST);
        $this->assertEquals($coursestart + 7 * DAYSECS, (int)$reapplied2->duedate);
        $unlocked2 = rule_repository::get_applied((int)$rule->id, (int)$student2->id);
        $this->assertEquals(0, (int)$unlocked2->manualoverride);
        $this->assertEquals(rule_repository::APPLIED_STATUS_APPLIED, $unlocked2->status);
    }

    /**
     * Tests activity completion trigger, availability restriction, unlock notifications, and completion reset cleanup.
     *
     * @return void
     */
    public function test_activity_completion_trigger_restriction_and_notification_dedup(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enablecompletion = 1;
        $CFG->enableavailability = 1;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $pagecm = get_coursemodule_from_instance('page', $page->id);

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $assigncm = get_coursemodule_from_instance('assign', $assign->id);

        $student = $this->getDataGenerator()->create_user(['timezone' => 'UTC']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $rule = rule_repository::upsert_rule((object)[
            'courseid' => $course->id,
            'cmid' => $assigncm->id,
            'modname' => 'assign',
            'enabled' => 1,
            'triggertype' => rule_repository::TRIGGER_ACTIVITY_COMPLETED,
            'refcmid' => $pagecm->id,
            'restrictuntiltriggered' => 1,
            'openoffsetvalue' => 0,
            'openoffsetunit' => rule_repository::UNIT_DAYS,
            'dueoffsetvalue' => 14,
            'dueoffsetunit' => rule_repository::UNIT_DAYS,
            'cutoffoffsetvalue' => null,
            'cutoffoffsetunit' => null,
            'snapmode' => rule_repository::SNAP_EXACT,
            'status' => rule_repository::RULE_STATUS_ACTIVE,
            'usermodified' => 2,
        ]);

        // Ensure availability_completion restriction is added idempotently.
        $this->assertTrue(availability_helper::ensure_completion_condition($assigncm->id, $pagecm->id));
        $this->assertFalse(availability_helper::ensure_completion_condition($assigncm->id, $pagecm->id));
        $rawavail = $DB->get_field('course_modules', 'availability', ['id' => $assigncm->id]);
        $this->assertStringContainsString('"cm":' . $pagecm->id, $rawavail);

        // Initial sync leaves student in waiting state without override.
        sync_manager::sync_rule_for_course($rule);
        $this->assertFalse($DB->record_exists('assign_overrides', ['assignid' => $assign->id, 'userid' => $student->id]));
        $waiting = rule_repository::get_applied((int)$rule->id, (int)$student->id);
        $this->assertEquals(rule_repository::APPLIED_STATUS_WAITING, $waiting->status);

        // Completing the prerequisite must unlock override and send one notification.
        $sink = $this->redirectMessages();
        $completion = new completion_info($course);
        $completion->update_state($pagecm, COMPLETION_COMPLETE, $student->id);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $this->assertEquals('duedateunlocked', $messages[0]->eventtype);
        $this->assertTrue($DB->record_exists('assign_overrides', ['assignid' => $assign->id, 'userid' => $student->id]));

        // Subsequent rule sync with edited offset must NOT duplicate notification.
        $sink2 = $this->redirectMessages();
        $DB->set_field('local_enrolduedate_rule', 'dueoffsetvalue', 21, ['id' => $rule->id]);
        $updatedrule = rule_repository::get_rule((int)$rule->id);
        sync_manager::sync_rule_for_course($updatedrule, true, true);
        $messages2 = $sink2->get_messages();
        $sink2->close();
        $this->assertCount(0, $messages2);

        // Resetting prerequisite completion must clean up plugin override and reset tracking row.
        $completion->update_state($pagecm, COMPLETION_INCOMPLETE, $student->id);
        $this->assertFalse($DB->record_exists('assign_overrides', ['assignid' => $assign->id, 'userid' => $student->id]));
        $afterreset = rule_repository::get_applied((int)$rule->id, (int)$student->id);
        $this->assertEquals(rule_repository::APPLIED_STATUS_WAITING, $afterreset->status);
        $this->assertEquals(0, (int)$afterreset->notified);
        $this->assertNull($afterreset->anchortime);
    }

    /**
     * Tests cleanup on unenrolment and rule disable while protecting manual locks.
     *
     * @return void
     */
    public function test_unenrolment_and_disable_cleanup(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $coursestart = 1800000000;
        $course = $this->getDataGenerator()->create_course(['startdate' => $coursestart]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $student1 = $this->getDataGenerator()->create_user(['timezone' => 'UTC']);
        $student2 = $this->getDataGenerator()->create_user(['timezone' => 'UTC']);
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student', 'manual', $coursestart);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student', 'manual', $coursestart);

        $rule = rule_repository::upsert_rule((object)[
            'courseid' => $course->id,
            'cmid' => $cm->id,
            'modname' => 'assign',
            'enabled' => 1,
            'triggertype' => rule_repository::TRIGGER_ENROLMENT,
            'refcmid' => null,
            'restrictuntiltriggered' => 0,
            'openoffsetvalue' => null,
            'openoffsetunit' => null,
            'dueoffsetvalue' => 7,
            'dueoffsetunit' => rule_repository::UNIT_DAYS,
            'cutoffoffsetvalue' => null,
            'cutoffoffsetunit' => null,
            'snapmode' => rule_repository::SNAP_EXACT,
            'status' => rule_repository::RULE_STATUS_ACTIVE,
            'usermodified' => 2,
        ]);

        sync_manager::sync_rule_for_course($rule);

        // Lock student2 manually.
        $applied2 = rule_repository::get_applied((int)$rule->id, (int)$student2->id);
        $DB->set_field('local_enrolduedate_applied', 'manualoverride', 1, ['id' => $applied2->id]);
        $DB->set_field('local_enrolduedate_applied', 'status', rule_repository::APPLIED_STATUS_MANUAL_LOCKED, [
            'id' => $applied2->id,
        ]);

        // Unenrol student1: override & tracking deleted immediately.
        $manualinstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($manualinstance, $student1->id);
        $this->assertFalse($DB->record_exists('assign_overrides', ['assignid' => $assign->id, 'userid' => $student1->id]));
        $this->assertNull(rule_repository::get_applied((int)$rule->id, (int)$student1->id));

        // Disable rule: student2's manual lock override remains preserved.
        sync_manager::disable_rule_and_cleanup($rule);
        $disabled = rule_repository::get_rule((int)$rule->id);
        $this->assertEquals(0, (int)$disabled->enabled);
        $this->assertTrue($DB->record_exists('assign_overrides', ['assignid' => $assign->id, 'userid' => $student2->id]));
    }

    /**
     * Tests no-op short-circuit, external override deletion locking, and scheduled reconciliation.
     *
     * @return void
     */
    public function test_noop_override_deleted_and_scheduled_reconcile(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enablecompletion = 1;

        $coursestart = 1800000000;
        $course = $this->getDataGenerator()->create_course(['startdate' => $coursestart, 'enablecompletion' => 1]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $pagecm = get_coursemodule_from_instance('page', $page->id);

        $assign1 = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $assigncm1 = get_coursemodule_from_instance('assign', $assign1->id);
        $assign2 = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $assigncm2 = get_coursemodule_from_instance('assign', $assign2->id);

        $student = $this->getDataGenerator()->create_user(['timezone' => 'UTC']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student', 'manual', $coursestart);

        $rule1 = rule_repository::upsert_rule((object)[
            'courseid' => $course->id,
            'cmid' => $assigncm1->id,
            'modname' => 'assign',
            'enabled' => 1,
            'triggertype' => rule_repository::TRIGGER_ENROLMENT,
            'refcmid' => null,
            'restrictuntiltriggered' => 0,
            'openoffsetvalue' => null,
            'openoffsetunit' => null,
            'dueoffsetvalue' => 7,
            'dueoffsetunit' => rule_repository::UNIT_DAYS,
            'cutoffoffsetvalue' => null,
            'cutoffoffsetunit' => null,
            'snapmode' => rule_repository::SNAP_EXACT,
            'status' => rule_repository::RULE_STATUS_ACTIVE,
            'usermodified' => 2,
        ]);

        sync_manager::sync_rule_for_course($rule1);
        $first = rule_repository::get_applied((int)$rule1->id, (int)$student->id);
        $this->assertNotNull($first->overrideid);

        // No-op re-sync does not alter override ID or record.
        sync_manager::sync_rule_for_course($rule1);
        $second = rule_repository::get_applied((int)$rule1->id, (int)$student->id);
        $this->assertEquals((int)$first->overrideid, (int)$second->overrideid);

        // External override deletion triggers manual lock so sync will not recreate it.
        $override = $DB->get_record('assign_overrides', ['id' => $first->overrideid], '*', MUST_EXIST);
        $DB->delete_records('assign_overrides', ['id' => $override->id]);
        $devent = \mod_assign\event\user_override_deleted::create([
            'context' => context_module::instance($assigncm1->id),
            'objectid' => $override->id,
            'relateduserid' => $student->id,
            'other' => ['assignid' => $assign1->id],
        ]);
        $devent->add_record_snapshot('assign_overrides', $override);
        $devent->trigger();

        sync_manager::sync_rule_for_course($rule1);
        $this->assertFalse($DB->record_exists('assign_overrides', ['assignid' => $assign1->id, 'userid' => $student->id]));
        $locked = rule_repository::get_applied((int)$rule1->id, (int)$student->id);
        $this->assertEquals(rule_repository::APPLIED_STATUS_MANUAL_LOCKED, $locked->status);

        // Broken reference activity detection in reconcile_scheduled.
        $rule2 = rule_repository::upsert_rule((object)[
            'courseid' => $course->id,
            'cmid' => $assigncm2->id,
            'modname' => 'assign',
            'enabled' => 1,
            'triggertype' => rule_repository::TRIGGER_ACTIVITY_COMPLETED,
            'refcmid' => $pagecm->id,
            'restrictuntiltriggered' => 1,
            'openoffsetvalue' => null,
            'openoffsetunit' => null,
            'dueoffsetvalue' => 5,
            'dueoffsetunit' => rule_repository::UNIT_DAYS,
            'cutoffoffsetvalue' => null,
            'cutoffoffsetunit' => null,
            'snapmode' => rule_repository::SNAP_EXACT,
            'status' => rule_repository::RULE_STATUS_ACTIVE,
            'usermodified' => 2,
        ]);

        // Disable completion tracking on referenced page to simulate broken reference.
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_NONE, ['id' => $pagecm->id]);
        rebuild_course_cache($course->id, true);

        $task = new reconcile_scheduled();
        $task->execute();

        $broken = rule_repository::get_rule((int)$rule2->id);
        $this->assertEquals(0, (int)$broken->enabled);
        $this->assertEquals(rule_repository::RULE_STATUS_BROKEN_REF, $broken->status);
    }
}
