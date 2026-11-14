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

namespace local_enrolduedate\observer;

use local_enrolduedate\local\anchor_resolver;
use local_enrolduedate\local\rule_repository;
use local_enrolduedate\local\sync_manager;

/**
 * Static event observers maintaining relative due date overrides across lifecycle events.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class event_observer {
    /**
     * Handles new user enrolment events.
     *
     * @param \core\event\user_enrolment_created $event Event object.
     * @return void
     */
    public static function user_enrolment_created(\core\event\user_enrolment_created $event): void {
        $courseid = (int)$event->courseid;
        $userid = (int)$event->relateduserid;
        if ($courseid <= 0 || $userid <= 0) {
            return;
        }

        $rules = rule_repository::get_active_rules_by_trigger($courseid, rule_repository::TRIGGER_ENROLMENT);
        foreach ($rules as $rule) {
            sync_manager::sync_user_for_rule($rule, $userid, true, false);
        }
    }

    /**
     * Handles role assignments which in Moodle core fire after user_enrolment_created.
     *
     * @param \core\event\role_assigned $event Event object.
     * @return void
     */
    public static function role_assigned(\core\event\role_assigned $event): void {
        $userid = (int)$event->relateduserid;
        if ($userid <= 0) {
            return;
        }

        $context = $event->get_context();
        if (!$context) {
            return;
        }

        if ((int)$context->contextlevel === CONTEXT_COURSE) {
            $rules = rule_repository::get_rules_for_course((int)$context->instanceid, true);
            foreach ($rules as $rule) {
                if (($rule->status ?? '') === rule_repository::RULE_STATUS_ACTIVE) {
                    sync_manager::sync_user_for_rule($rule, $userid, true, false);
                }
            }
        } else if ((int)$context->contextlevel === CONTEXT_MODULE) {
            $rule = rule_repository::get_rule_by_cmid((int)$context->instanceid);
            if ($rule && !empty($rule->enabled) && ($rule->status ?? '') === rule_repository::RULE_STATUS_ACTIVE) {
                sync_manager::sync_user_for_rule($rule, $userid, true, false);
            }
        }
    }

    /**
     * Handles role unassignments, removing plugin overrides if student submit capability is lost.
     *
     * @param \core\event\role_unassigned $event Event object.
     * @return void
     */
    public static function role_unassigned(\core\event\role_unassigned $event): void {
        $userid = (int)$event->relateduserid;
        if ($userid <= 0) {
            return;
        }

        $context = $event->get_context();
        if (!$context) {
            return;
        }

        if ((int)$context->contextlevel === CONTEXT_COURSE) {
            $rules = rule_repository::get_rules_for_course((int)$context->instanceid, true);
            foreach ($rules as $rule) {
                sync_manager::sync_user_for_rule($rule, $userid, true, false);
            }
        } else if ((int)$context->contextlevel === CONTEXT_MODULE) {
            $rule = rule_repository::get_rule_by_cmid((int)$context->instanceid);
            if ($rule && !empty($rule->enabled)) {
                sync_manager::sync_user_for_rule($rule, $userid, true, false);
            }
        }
    }

    /**
     * Handles enrolment updates (timestart changes, suspension, re-activation, expiry).
     *
     * @param \core\event\user_enrolment_updated $event Event object.
     * @return void
     */
    public static function user_enrolment_updated(\core\event\user_enrolment_updated $event): void {
        $courseid = (int)$event->courseid;
        $userid = (int)$event->relateduserid;
        if ($courseid <= 0 || $userid <= 0) {
            return;
        }

        $rules = rule_repository::get_rules_for_course($courseid, true);
        foreach ($rules as $rule) {
            if (($rule->status ?? '') === rule_repository::RULE_STATUS_ACTIVE) {
                sync_manager::sync_user_for_rule($rule, $userid, true, false);
            }
        }
    }

    /**
     * Handles enrolment deletion, removing non-locked overrides or recalculating remaining enrolments.
     *
     * @param \core\event\user_enrolment_deleted $event Event object.
     * @return void
     */
    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event): void {
        $courseid = (int)$event->courseid;
        $userid = (int)$event->relateduserid;
        if ($courseid <= 0 || $userid <= 0) {
            return;
        }

        if (!anchor_resolver::has_any_active_enrolment($courseid, $userid)) {
            sync_manager::cleanup_user_for_course($courseid, $userid);
            return;
        }

        $rules = rule_repository::get_active_rules_by_trigger($courseid, rule_repository::TRIGGER_ENROLMENT);
        foreach ($rules as $rule) {
            sync_manager::sync_user_for_rule($rule, $userid, true, false);
        }
    }

    /**
     * Handles course updates so startdate changes recalculate 'earliest_floored' rules.
     *
     * @param \core\event\course_updated $event Event object.
     * @return void
     */
    public static function course_updated(\core\event\course_updated $event): void {
        $courseid = (int)$event->courseid;
        if ($courseid <= 0) {
            return;
        }

        $mode = rule_repository::get_effective_enrol_anchor_mode($courseid);
        if ($mode !== rule_repository::ANCHOR_EARLIEST_FLOORED) {
            return;
        }

        $rules = rule_repository::get_active_rules_by_trigger($courseid, rule_repository::TRIGGER_ENROLMENT);
        foreach ($rules as $rule) {
            sync_manager::dispatch_rule_sync($rule, true, false);
        }
    }

    /**
     * Handles activity completion state changes (unlocking due dates or cleaning up on reset).
     *
     * @param \core\event\course_module_completion_updated $event Event object.
     * @return void
     */
    public static function course_module_completion_updated(
        \core\event\course_module_completion_updated $event
    ): void {
        $refcmid = (int)$event->contextinstanceid;
        $userid = (int)$event->relateduserid;
        if ($refcmid <= 0 || $userid <= 0) {
            return;
        }

        $rules = rule_repository::get_rules_by_refcmid($refcmid, true);
        foreach ($rules as $rule) {
            sync_manager::sync_user_for_rule($rule, $userid, true, true);
        }
    }

    /**
     * Locks tracking rows when a teacher manually edits a plugin-managed override.
     *
     * @param \mod_assign\event\user_override_updated $event Event object.
     * @return void
     */
    public static function assign_override_updated(\mod_assign\event\user_override_updated $event): void {
        if (sync_manager::is_internal_write()) {
            return;
        }

        $overrideid = (int)$event->objectid;
        $cmid = (int)$event->contextinstanceid;
        $applied = rule_repository::get_applied_by_overrideid('assign', $overrideid, $cmid);
        if ($applied && empty($applied->manualoverride)) {
            $applied->manualoverride = 1;
            $applied->status = rule_repository::APPLIED_STATUS_MANUAL_LOCKED;
            rule_repository::upsert_applied($applied);
        }
    }

    /**
     * Locks tracking rows when a teacher manually deletes a plugin-managed override.
     *
     * @param \mod_assign\event\user_override_deleted $event Event object.
     * @return void
     */
    public static function assign_override_deleted(\mod_assign\event\user_override_deleted $event): void {
        if (sync_manager::is_internal_write()) {
            return;
        }

        $overrideid = (int)$event->objectid;
        $cmid = (int)$event->contextinstanceid;
        $applied = rule_repository::get_applied_by_overrideid('assign', $overrideid, $cmid);
        if ($applied) {
            $applied->overrideid = null;
            $applied->manualoverride = 1;
            $applied->status = rule_repository::APPLIED_STATUS_MANUAL_LOCKED;
            rule_repository::upsert_applied($applied);
        }
    }

    /**
     * Reconciles tracking rows after course reset completes.
     *
     * @param \core\event\course_reset_ended $event Event object.
     * @return void
     */
    public static function course_reset_ended(\core\event\course_reset_ended $event): void {
        $courseid = (int)$event->courseid;
        if ($courseid > 0) {
            sync_manager::reconcile_course_after_reset($courseid);
        }
    }

    /**
     * Purges all plugin tables associated with a deleted course.
     *
     * @param \core\event\course_deleted $event Event object.
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        $courseid = (int)$event->courseid;
        if ($courseid > 0) {
            rule_repository::delete_course_data($courseid);
        }
    }
}
