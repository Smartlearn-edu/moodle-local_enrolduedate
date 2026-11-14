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
use backup;
use backup_controller;
use local_enrolduedate\local\rule_repository;
use restore_controller;
use restore_dbops;

/**
 * Integration tests for Moodle Backup, Restore, and single-module duplication.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \restore_local_enrolduedate_plugin
 */
final class backup_restore_test extends advanced_testcase {
    /**
     * Tests full course backup and restore remapping course anchor settings and rule refcmid.
     *
     * @return void
     */
    public function test_course_backup_restore_remaps_refcmid(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->libdir . '/completionlib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enablecompletion = 1;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        rule_repository::upsert_course_settings($course->id, rule_repository::ANCHOR_LATEST_RAW, 2);

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Prerequisite Reading',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $pagecm = get_coursemodule_from_instance('page', $page->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Relative Essay',
        ]);
        $assigncm = get_coursemodule_from_instance('assign', $assign->id);

        rule_repository::upsert_rule((object)[
            'courseid' => $course->id,
            'cmid' => $assigncm->id,
            'modname' => 'assign',
            'enabled' => 1,
            'triggertype' => rule_repository::TRIGGER_ACTIVITY_COMPLETED,
            'refcmid' => $pagecm->id,
            'restrictuntiltriggered' => 1,
            'openoffsetvalue' => 0,
            'openoffsetunit' => rule_repository::UNIT_DAYS,
            'dueoffsetvalue' => 7,
            'dueoffsetunit' => rule_repository::UNIT_DAYS,
            'cutoffoffsetvalue' => null,
            'cutoffoffsetunit' => null,
            'snapmode' => rule_repository::SNAP_ENDOFDAY,
            'status' => rule_repository::RULE_STATUS_ACTIVE,
            'usermodified' => 2,
        ]);

        // Execute course backup.
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            2
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        // Execute restore into a brand new target course.
        $newcourseid = restore_dbops::create_new_course('Restored Course', 'REST1', $course->category);
        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            2,
            backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        // Verify course anchor settings copied.
        $newcoursesetting = rule_repository::get_course_settings($newcourseid);
        $this->assertNotNull($newcoursesetting);
        $this->assertEquals(rule_repository::ANCHOR_LATEST_RAW, $newcoursesetting->enrolanchormode);

        // Verify new assignment rule points to the restored Page cmid (not original $pagecm->id).
        $newmodinfo = get_fast_modinfo($newcourseid);
        $newpages = $newmodinfo->get_instances_of('page');
        $newassigns = $newmodinfo->get_instances_of('assign');
        $newpagecm = reset($newpages);
        $newassigncm = reset($newassigns);

        $newrule = rule_repository::get_rule_by_cmid((int)$newassigncm->id);
        $this->assertNotNull($newrule);
        $this->assertEquals(rule_repository::RULE_STATUS_ACTIVE, $newrule->status);
        $this->assertEquals(1, (int)$newrule->enabled);
        $this->assertEquals((int)$newpagecm->id, (int)$newrule->refcmid);
        $this->assertNotEquals((int)$pagecm->id, (int)$newrule->refcmid);

        // Verify adhoc sync task was queued for the restored rule.
        $this->assertTrue($DB->record_exists('task_adhoc', ['classname' => '\local_enrolduedate\task\sync_rule_adhoc']));
    }

    /**
     * Tests single-activity duplication preserving existing course refcmid.
     *
     * @return void
     */
    public function test_single_module_duplication_preserves_refcmid(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enablecompletion = 1;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $pagecm = get_coursemodule_from_instance('page', $page->id);

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $assigncm = get_coursemodule_from_instance('assign', $assign->id);

        rule_repository::upsert_rule((object)[
            'courseid' => $course->id,
            'cmid' => $assigncm->id,
            'modname' => 'assign',
            'enabled' => 1,
            'triggertype' => rule_repository::TRIGGER_ACTIVITY_COMPLETED,
            'refcmid' => $pagecm->id,
            'restrictuntiltriggered' => 1,
            'openoffsetvalue' => null,
            'openoffsetunit' => null,
            'dueoffsetvalue' => 10,
            'dueoffsetunit' => rule_repository::UNIT_DAYS,
            'cutoffoffsetvalue' => null,
            'cutoffoffsetunit' => null,
            'snapmode' => rule_repository::SNAP_ENDOFDAY,
            'status' => rule_repository::RULE_STATUS_ACTIVE,
            'usermodified' => 2,
        ]);

        $newcm = duplicate_module($course, get_fast_modinfo($course)->get_cm($assigncm->id));
        $this->assertNotNull($newcm);

        $duprule = rule_repository::get_rule_by_cmid((int)$newcm->id);
        $this->assertNotNull($duprule);
        $this->assertEquals(1, (int)$duprule->enabled);
        $this->assertEquals(rule_repository::RULE_STATUS_ACTIVE, $duprule->status);
        $this->assertEquals((int)$pagecm->id, (int)$duprule->refcmid);
    }
}
