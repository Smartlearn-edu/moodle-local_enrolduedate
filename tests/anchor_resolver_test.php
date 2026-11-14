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
use local_enrolduedate\local\anchor_resolver;
use local_enrolduedate\local\rule_repository;

/**
 * Unit tests for bulk anchor resolution across enrolment, completion, and view triggers.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_enrolduedate\local\anchor_resolver
 */
final class anchor_resolver_test extends advanced_testcase {
    /**
     * Tests earliest_floored, earliest_raw, and latest_raw enrolment anchor policies.
     *
     * @return void
     */
    public function test_enrolment_anchor_modes(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');

        $this->resetAfterTest();

        $coursestart = 1800000000;
        $earlyenroltime = 1799000000; // Before course start.
        $lateenroltime = 1800500000;  // After course start.

        $course = $this->getDataGenerator()->create_course(['startdate' => $coursestart]);
        $student = $this->getDataGenerator()->create_user();
        $suspendedstudent = $this->getDataGenerator()->create_user();

        // Add manual + self enrol instances so one student can hold two distinct enrolments.
        $manualinstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $selfplugin = enrol_get_plugin('self');
        $selfenrolid = $selfplugin->add_instance($course);
        $selfinstance = $DB->get_record('enrol', ['id' => $selfenrolid], '*', MUST_EXIST);

        $manualplugin = enrol_get_plugin('manual');
        $manualplugin->enrol_user($manualinstance, $student->id, null, $earlyenroltime);
        $selfplugin->enrol_user($selfinstance, $student->id, null, $lateenroltime);
        $manualplugin->enrol_user($manualinstance, $suspendedstudent->id, null, $earlyenroltime, 0, ENROL_USER_SUSPENDED);

        $rule = (object)[
            'triggertype' => rule_repository::TRIGGER_ENROLMENT,
            'status' => rule_repository::RULE_STATUS_ACTIVE,
        ];

        // Default / earliest_floored.
        $floored = anchor_resolver::resolve_for_users($rule, $course, [$student->id, $suspendedstudent->id]);
        $this->assertEquals($coursestart, $floored[$student->id]);
        $this->assertNull($floored[$suspendedstudent->id]);

        // Earliest_raw ignores course startdate floor.
        rule_repository::upsert_course_settings($course->id, rule_repository::ANCHOR_EARLIEST_RAW);
        $rawearliest = anchor_resolver::resolve_for_users($rule, $course, [$student->id]);
        $this->assertEquals($earlyenroltime, $rawearliest[$student->id]);

        // Latest_raw chooses the most recent active enrolment timestamp.
        rule_repository::upsert_course_settings($course->id, rule_repository::ANCHOR_LATEST_RAW);
        $rawlatest = anchor_resolver::resolve_for_users($rule, $course, [$student->id]);
        $this->assertEquals($lateenroltime, $rawlatest[$student->id]);
    }

    /**
     * Tests completion anchor states and immutability of already-captured completion anchors.
     *
     * @return void
     */
    public function test_completion_anchor_and_immutability(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $this->resetAfterTest();
        $CFG->enablecompletion = 1;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $pagecm = get_coursemodule_from_instance('page', $page->id);

        $rule = (object)[
            'triggertype' => rule_repository::TRIGGER_ACTIVITY_COMPLETED,
            'refcmid' => $pagecm->id,
            'status' => rule_repository::RULE_STATUS_ACTIVE,
        ];

        // No completion record yet.
        $initial = anchor_resolver::resolve_for_users($rule, $course, [$student->id]);
        $this->assertNull($initial[$student->id]);

        // Mark complete and inspect resolved timestamp.
        $completion = new completion_info($course);
        $completion->update_state($pagecm, COMPLETION_COMPLETE, $student->id);
        $DB->set_field('course_modules_completion', 'timemodified', 1800100000, [
            'coursemoduleid' => $pagecm->id,
            'userid' => $student->id,
        ]);

        $completed = anchor_resolver::resolve_for_users($rule, $course, [$student->id]);
        $this->assertEquals(1800100000, $completed[$student->id]);

        // Later timemodified mutation must keep the locked anchor when $keepexistinganchors is true.
        $DB->set_field('course_modules_completion', 'timemodified', 1800999999, [
            'coursemoduleid' => $pagecm->id,
            'userid' => $student->id,
        ]);
        $existing = [
            $student->id => (object)['anchortime' => 1800100000],
        ];
        $immutable = anchor_resolver::resolve_for_users($rule, $course, [$student->id], $existing, true);
        $this->assertEquals(1800100000, $immutable[$student->id]);

        // Reset completion returns null so waiting cleanup takes over.
        $completion->update_state($pagecm, COMPLETION_INCOMPLETE, $student->id);
        $afterreset = anchor_resolver::resolve_for_users($rule, $course, [$student->id], $existing, true);
        $this->assertNull($afterreset[$student->id]);
    }

    /**
     * Tests activity_viewed anchor resolution from {course_modules_viewed}.
     *
     * @return void
     */
    public function test_viewed_anchor_resolution(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $this->resetAfterTest();
        $CFG->enablecompletion = 1;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => COMPLETION_VIEW_REQUIRED,
        ]);
        $pagecm = get_coursemodule_from_instance('page', $page->id);

        $rule = (object)[
            'triggertype' => rule_repository::TRIGGER_ACTIVITY_VIEWED,
            'refcmid' => $pagecm->id,
            'status' => rule_repository::RULE_STATUS_ACTIVE,
        ];

        $this->assertNull(anchor_resolver::resolve_for_users($rule, $course, [$student->id])[$student->id]);

        $viewtime = 1800250000;
        $DB->insert_record('course_modules_viewed', (object)[
            'coursemoduleid' => $pagecm->id,
            'userid' => $student->id,
            'timecreated' => $viewtime,
        ]);

        $resolved = anchor_resolver::resolve_for_users($rule, $course, [$student->id]);
        $this->assertEquals($viewtime, $resolved[$student->id]);
    }
}
