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

namespace local_enrolduedate\task;

use local_enrolduedate\local\rule_repository;
use local_enrolduedate\local\sync_manager;

/**
 * Hourly scheduled safety-net reconciliation task.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_scheduled extends \core\task\scheduled_task {
    /**
     * Returns localized display name for admin task scheduling UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_reconcile_scheduled', 'local_enrolduedate');
    }

    /**
     * Reconciles active rules, catches bulk completions, expired enrolments, and broken references.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $rules = rule_repository::get_all_enabled_rules();
        if (empty($rules)) {
            return;
        }

        $modinfocache = [];
        $coursecache = [];

        foreach ($rules as $rule) {
            $courseid = (int)$rule->courseid;
            if (!isset($coursecache[$courseid])) {
                $course = $DB->get_record('course', ['id' => $courseid]);
                $coursecache[$courseid] = $course ?: false;
            }
            $course = $coursecache[$courseid];
            if (!$course) {
                rule_repository::delete_course_data($courseid);
                continue;
            }

            if (!isset($modinfocache[$courseid])) {
                $modinfocache[$courseid] = get_fast_modinfo($course);
            }
            $modinfo = $modinfocache[$courseid];
            $cms = $modinfo->get_cms();
            $targetcm = $cms[(int)$rule->cmid] ?? null;

            if (!$targetcm || !empty($targetcm->deletioninprogress)) {
                rule_repository::delete_rule_and_applied((int)$rule->id);
                continue;
            }

            if ($rule->triggertype !== rule_repository::TRIGGER_ENROLMENT) {
                $refcm = !empty($rule->refcmid) ? ($cms[(int)$rule->refcmid] ?? null) : null;
                $broken = empty($course->enablecompletion)
                    || !$refcm
                    || !empty($refcm->deletioninprogress)
                    || (int)$refcm->completion === COMPLETION_TRACKING_NONE
                    || ($rule->triggertype === rule_repository::TRIGGER_ACTIVITY_VIEWED && empty($refcm->completionview));

                if ($broken) {
                    sync_manager::disable_rule_and_cleanup($rule);
                    rule_repository::mark_rule_broken((int)$rule->id);
                    continue;
                }
            }

            sync_manager::sync_rule_for_course($rule, true, true);
        }
    }
}
