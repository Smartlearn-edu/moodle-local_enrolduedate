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

namespace local_enrolduedate\adapter;

use local_enrolduedate\local\anchor_resolver;
use local_enrolduedate\local\sync_manager;
use stdClass;

/**
 * Phase 1 adapter implementing per-student user overrides for mod_assign.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_adapter implements activity_adapter {
    /**
     * Returns the module name handled by this adapter.
     *
     * @return string
     */
    public function get_modname(): string {
        return 'assign';
    }

    /**
     * Checks whether the assignment instance supports per-student relative due dates.
     *
     * Team/group submissions share a single group schedule and are disallowed.
     *
     * @param stdClass $cm Course module record.
     * @param stdClass|null $instance Optional preloaded assign record.
     * @return bool
     */
    public function supports_relative_due_dates(stdClass $cm, ?stdClass $instance = null): bool {
        global $DB;
        if ($instance === null && !empty($cm->instance)) {
            $instance = $DB->get_record('assign', ['id' => $cm->instance], 'id, teamsubmission');
        }
        if ($instance && !empty($instance->teamsubmission)) {
            return false;
        }
        return true;
    }

    /**
     * Returns eligible enrolled students holding mod/assign:submit capability.
     *
     * @param stdClass $cm Course module record.
     * @param stdClass $course Course record.
     * @return array<int, stdClass> User records keyed by userid.
     */
    public function get_eligible_users(stdClass $cm, stdClass $course): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');

        $cmcontext = \context_module::instance((int)$cm->id);
        $fields = 'u.id, u.timezone, u.firstname, u.lastname, u.email, u.mailformat, u.lang';
        $capusers = get_users_by_capability($cmcontext, 'mod/assign:submit', $fields, 'u.id ASC', '', '', '', '', false);
        if (empty($capusers)) {
            return [];
        }

        $now = time();
        $sql = "SELECT DISTINCT ue.userid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid
                   AND e.status = :enrolenabled
                   AND ue.status = :useractive
                   AND (ue.timeend = 0 OR ue.timeend > :now)";
        $activeids = $DB->get_fieldset_sql($sql, [
            'courseid' => (int)$course->id,
            'enrolenabled' => ENROL_INSTANCE_ENABLED,
            'useractive' => ENROL_USER_ACTIVE,
            'now' => $now,
        ]);
        if (empty($activeids)) {
            return [];
        }

        $activemap = array_flip(array_map('intval', $activeids));
        $eligible = [];
        foreach ($capusers as $user) {
            $uid = (int)$user->id;
            if (isset($activemap[$uid])) {
                $eligible[$uid] = $user;
            }
        }
        return $eligible;
    }

    /**
     * Checks whether a single user is eligible for relative assignment overrides.
     *
     * @param stdClass $cm Course module record.
     * @param int $userid User ID.
     * @return bool
     */
    public function is_user_eligible(stdClass $cm, int $userid): bool {
        $cmcontext = \context_module::instance((int)$cm->id);
        if (!has_capability('mod/assign:submit', $cmcontext, $userid, false)) {
            return false;
        }
        return anchor_resolver::has_any_active_enrolment((int)$cm->course, $userid);
    }

    /**
     * Zeroes out fixed assignment base dates and preserves Moodle 5.x gradepenalty if applicable.
     *
     * @param stdClass $cm Course module record.
     * @param stdClass|null $formdata Submitted form data object.
     * @return void
     */
    public function clear_base_dates(stdClass $cm, ?stdClass $formdata = null): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');

        $assignrecord = $DB->get_record('assign', ['id' => (int)$cm->instance], '*', MUST_EXIST);
        $update = (object)[
            'id' => $assignrecord->id,
            'allowsubmissionsfromdate' => 0,
            'duedate' => 0,
            'cutoffdate' => 0,
            'timemodified' => time(),
        ];

        if (
            $formdata !== null &&
            property_exists($formdata, 'enrolduedate_gradepenalty') &&
            property_exists($assignrecord, 'gradepenalty') &&
            class_exists('\core_grades\penalty_manager') &&
            \core_grades\penalty_manager::is_penalty_enabled_for_module('assign')
        ) {
            $update->gradepenalty = (int)$formdata->enrolduedate_gradepenalty;
        }

        $DB->update_record('assign', $update);

        $course = get_course((int)$cm->course);
        $cmcontext = \context_module::instance((int)$cm->id);
        $assign = new \assign($cmcontext, $cm, $course);
        assign_update_events($assign);
    }

    /**
     * Fetches existing user overrides in {assign_overrides} keyed by userid.
     *
     * @param int $instanceid Assignment ID.
     * @param int[] $userids Student user IDs.
     * @return array<int, stdClass>
     */
    public function get_overrides_by_users(int $instanceid, array $userids): array {
        global $DB;
        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (empty($userids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $sql = "SELECT *
                  FROM {assign_overrides}
                 WHERE assignid = :assignid
                   AND userid {$insql}";
        $params = array_merge($inparams, ['assignid' => $instanceid]);
        $records = $DB->get_records_sql($sql, $params);

        $byuser = [];
        foreach ($records as $record) {
            $byuser[(int)$record->userid] = $record;
        }
        return $byuser;
    }

    /**
     * Fetches a single {assign_overrides} row by primary ID.
     *
     * @param int $overrideid Override ID.
     * @return stdClass|null
     */
    public function get_override_by_id(int $overrideid): ?stdClass {
        global $DB;
        $record = $DB->get_record('assign_overrides', ['id' => $overrideid]);
        return $record ?: null;
    }

    /**
     * Creates or updates one user override in {assign_overrides}.
     *
     * @param stdClass $cm Course module record.
     * @param stdClass $course Course record.
     * @param int $userid Student user ID.
     * @param stdClass $dates Object with open (?int), due (int), cutoff (?int).
     * @param int|null $existingoverrideid Existing override ID if present.
     * @param bool $updateevents Whether to run single-override calendar event sync immediately.
     * @return stdClass
     */
    public function apply_user_override(
        stdClass $cm,
        stdClass $course,
        int $userid,
        stdClass $dates,
        ?int $existingoverrideid = null,
        bool $updateevents = true
    ): stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');

        $assignid = (int)$cm->instance;
        $cmcontext = \context_module::instance((int)$cm->id);

        $existing = null;
        if ($existingoverrideid !== null) {
            $existing = $this->get_override_by_id($existingoverrideid);
        }
        if (!$existing) {
            $existing = $DB->get_record('assign_overrides', [
                'assignid' => $assignid,
                'userid' => $userid,
            ]) ?: null;
        }

        $targetopen = $dates->open !== null ? (int)$dates->open : null;
        $targetdue = (int)$dates->due;
        $targetcutoff = $dates->cutoff !== null ? (int)$dates->cutoff : null;

        if (
            $existing &&
            self::same_nullable_int($existing->allowsubmissionsfromdate, $targetopen) &&
            self::same_nullable_int($existing->duedate, $targetdue) &&
            self::same_nullable_int($existing->cutoffdate, $targetcutoff)
        ) {
            return (object)[
                'id' => (int)$existing->id,
                'open' => $targetopen,
                'due' => $targetdue,
                'cutoff' => $targetcutoff,
                'changed' => false,
            ];
        }

        $override = sync_manager::run_as_internal(function () use (
            $DB,
            $assignid,
            $cm,
            $course,
            $cmcontext,
            $userid,
            $existing,
            $targetopen,
            $targetdue,
            $targetcutoff,
            $updateevents
        ): stdClass {
            $record = (object)[
                'assignid' => $assignid,
                'groupid' => null,
                'userid' => $userid,
                'sortorder' => null,
                'allowsubmissionsfromdate' => $targetopen,
                'duedate' => $targetdue,
                'cutoffdate' => $targetcutoff,
            ];

            if ($existing) {
                $record->id = (int)$existing->id;
                $DB->update_record('assign_overrides', $record);
                $eventclass = \mod_assign\event\user_override_updated::class;
            } else {
                $record->id = (int)$DB->insert_record('assign_overrides', $record);
                $eventclass = \mod_assign\event\user_override_created::class;
            }

            \cache::make('mod_assign', 'overrides')->delete("{$assignid}_u_{$userid}");

            $eventclass::create([
                'context' => $cmcontext,
                'objectid' => $record->id,
                'relateduserid' => $userid,
                'other' => ['assignid' => $assignid],
            ])->trigger();

            if ($updateevents) {
                $assign = new \assign($cmcontext, $cm, $course);
                assign_update_events($assign, $record);
            }

            return $record;
        });

        return (object)[
            'id' => (int)$override->id,
            'open' => $targetopen,
            'due' => $targetdue,
            'cutoff' => $targetcutoff,
            'changed' => true,
        ];
    }

    /**
     * Deletes a plugin-generated user override from {assign_overrides}.
     *
     * Uses Moodle core assign::delete_override() so override rows, caches,
     * calendar events, and delete events stay fully consistent.
     *
     * @param stdClass $cm Course module record.
     * @param stdClass $course Course record.
     * @param int $overrideid Override ID to delete.
     * @param bool $updateevents Unused; core delete_override handles calendar cleanup.
     * @return void
     */
    public function delete_user_override(stdClass $cm, stdClass $course, int $overrideid, bool $updateevents = true): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        if (!$DB->record_exists('assign_overrides', ['id' => $overrideid])) {
            return;
        }

        $cmcontext = \context_module::instance((int)$cm->id);
        sync_manager::run_as_internal(function () use ($cm, $course, $cmcontext, $overrideid): void {
            $assign = new \assign($cmcontext, $cm, $course);
            $assign->delete_override($overrideid);
        });
    }

    /**
     * Reconciles all calendar action events for this assignment in a single pass.
     *
     * @param stdClass $cm Course module record.
     * @param stdClass $course Course record.
     * @return void
     */
    public function reconcile_events(stdClass $cm, stdClass $course): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');

        $cmcontext = \context_module::instance((int)$cm->id);
        $assign = new \assign($cmcontext, $cm, $course);
        assign_update_events($assign);
    }

    /**
     * Returns the formatted activity display name.
     *
     * @param stdClass $cm Course module record.
     * @return string
     */
    public function get_activity_name(stdClass $cm): string {
        global $DB;
        $name = $DB->get_field('assign', 'name', ['id' => (int)$cm->instance]);
        return format_string((string)$name, true);
    }

    /**
     * Compares a database field value against an expected nullable integer.
     *
     * @param mixed $actual DB column value.
     * @param int|null $expected Expected timestamp or null.
     * @return bool
     */
    public static function same_nullable_int($actual, ?int $expected): bool {
        if ($actual === null && $expected === null) {
            return true;
        }
        if ($actual === null || $expected === null) {
            return false;
        }
        return (int)$actual === $expected;
    }
}
