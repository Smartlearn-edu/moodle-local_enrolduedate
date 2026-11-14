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

namespace local_enrolduedate\local;

use stdClass;

/**
 * Data access repository for local_enrolduedate tables.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_repository {
    /** @var string Triggered when student enrols in course. */
    public const TRIGGER_ENROLMENT = 'enrolment';

    /** @var string Triggered when reference activity is completed. */
    public const TRIGGER_ACTIVITY_COMPLETED = 'activity_completed';

    /** @var string Triggered when reference activity is viewed. */
    public const TRIGGER_ACTIVITY_VIEWED = 'activity_viewed';

    /** @var string Snap due/cutoff dates to 23:59 in student timezone. */
    public const SNAP_ENDOFDAY = 'endofday';

    /** @var string Preserve exact anchor time of day. */
    public const SNAP_EXACT = 'exact';

    /** @var string Earliest active enrolment floored at course start date. */
    public const ANCHOR_EARLIEST_FLOORED = 'earliest_floored';

    /** @var string Earliest active enrolment timestamp as-is. */
    public const ANCHOR_EARLIEST_RAW = 'earliest_raw';

    /** @var string Most recent active enrolment timestamp. */
    public const ANCHOR_LATEST_RAW = 'latest_raw';

    /** @var string Rule health status: active. */
    public const RULE_STATUS_ACTIVE = 'active';

    /** @var string Rule health status: reference activity broken or missing. */
    public const RULE_STATUS_BROKEN_REF = 'broken_ref';

    /** @var string Tracking status: override applied by plugin. */
    public const APPLIED_STATUS_APPLIED = 'applied';

    /** @var string Tracking status: waiting for anchor trigger. */
    public const APPLIED_STATUS_WAITING = 'waiting';

    /** @var string Tracking status: locked due to manual override. */
    public const APPLIED_STATUS_MANUAL_LOCKED = 'manual_locked';

    /** @var string Tracking status: skipped due to non-eligible user. */
    public const APPLIED_STATUS_SKIPPED = 'skipped';

    /** @var string Duration unit: hours. */
    public const UNIT_HOURS = 'hours';

    /** @var string Duration unit: days. */
    public const UNIT_DAYS = 'days';

    /** @var string Duration unit: weeks. */
    public const UNIT_WEEKS = 'weeks';

    /**
     * Fetches the course-level anchor policy record if configured.
     *
     * @param int $courseid Course ID.
     * @return stdClass|null
     */
    public static function get_course_settings(int $courseid): ?stdClass {
        global $DB;
        $record = $DB->get_record('local_enrolduedate_course', ['courseid' => $courseid]);
        return $record ?: null;
    }

    /**
     * Returns the effective enrolment anchor mode for a course.
     *
     * @param int $courseid Course ID.
     * @return string
     */
    public static function get_effective_enrol_anchor_mode(int $courseid): string {
        $coursesetting = self::get_course_settings($courseid);
        if ($coursesetting && !empty($coursesetting->enrolanchormode)) {
            return $coursesetting->enrolanchormode;
        }
        $default = get_config('local_enrolduedate', 'defaultenrolanchormode');
        return !empty($default) ? $default : self::ANCHOR_EARLIEST_FLOORED;
    }

    /**
     * Inserts or updates course-level anchor settings.
     *
     * @param int $courseid Course ID.
     * @param string $enrolanchormode Anchor mode constant.
     * @param int $userid Modifying user ID.
     * @return stdClass
     */
    public static function upsert_course_settings(int $courseid, string $enrolanchormode, int $userid = 0): stdClass {
        global $DB;

        $now = time();
        $existing = self::get_course_settings($courseid);
        if ($existing) {
            $existing->enrolanchormode = $enrolanchormode;
            $existing->usermodified = $userid;
            $existing->timemodified = $now;
            $DB->update_record('local_enrolduedate_course', $existing);
            return $existing;
        }

        $record = (object)[
            'courseid' => $courseid,
            'enrolanchormode' => $enrolanchormode,
            'usermodified' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_enrolduedate_course', $record);
        return $record;
    }

    /**
     * Fetches a rule by primary ID.
     *
     * @param int $ruleid Rule ID.
     * @return stdClass|null
     */
    public static function get_rule(int $ruleid): ?stdClass {
        global $DB;
        $record = $DB->get_record('local_enrolduedate_rule', ['id' => $ruleid]);
        return $record ?: null;
    }

    /**
     * Fetches a rule by course module ID.
     *
     * @param int $cmid Course module ID.
     * @return stdClass|null
     */
    public static function get_rule_by_cmid(int $cmid): ?stdClass {
        global $DB;
        $record = $DB->get_record('local_enrolduedate_rule', ['cmid' => $cmid]);
        return $record ?: null;
    }

    /**
     * Fetches all rules belonging to a course.
     *
     * @param int $courseid Course ID.
     * @param bool $enabledonly Whether to return only enabled rules.
     * @return stdClass[]
     */
    public static function get_rules_for_course(int $courseid, bool $enabledonly = false): array {
        global $DB;
        $params = ['courseid' => $courseid];
        if ($enabledonly) {
            $params['enabled'] = 1;
        }
        return $DB->get_records('local_enrolduedate_rule', $params, 'id ASC');
    }

    /**
     * Fetches enabled active rules in a course for a specific trigger type.
     *
     * @param int $courseid Course ID.
     * @param string $triggertype Trigger constant.
     * @return stdClass[]
     */
    public static function get_active_rules_by_trigger(int $courseid, string $triggertype): array {
        global $DB;
        return $DB->get_records('local_enrolduedate_rule', [
            'courseid' => $courseid,
            'triggertype' => $triggertype,
            'enabled' => 1,
            'status' => self::RULE_STATUS_ACTIVE,
        ], 'id ASC');
    }

    /**
     * Fetches all rules that reference a given course module ID.
     *
     * @param int $refcmid Reference course module ID.
     * @param bool $enabledonly Whether to filter to enabled active rules only.
     * @return stdClass[]
     */
    public static function get_rules_by_refcmid(int $refcmid, bool $enabledonly = true): array {
        global $DB;
        $params = ['refcmid' => $refcmid];
        if ($enabledonly) {
            $params['enabled'] = 1;
            $params['status'] = self::RULE_STATUS_ACTIVE;
        }
        return $DB->get_records('local_enrolduedate_rule', $params, 'id ASC');
    }

    /**
     * Returns all enabled rules across the site.
     *
     * @return stdClass[]
     */
    public static function get_all_enabled_rules(): array {
        global $DB;
        return $DB->get_records('local_enrolduedate_rule', [
            'enabled' => 1,
            'status' => self::RULE_STATUS_ACTIVE,
        ], 'courseid ASC, id ASC');
    }

    /**
     * Inserts or updates a rule keyed by cmid.
     *
     * @param stdClass $data Rule fields.
     * @return stdClass
     */
    public static function upsert_rule(stdClass $data): stdClass {
        global $DB;

        $now = time();
        $existing = self::get_rule_by_cmid((int)$data->cmid);
        if ($existing) {
            $data->id = $existing->id;
            $data->timecreated = $existing->timecreated;
            $data->timemodified = $now;
            $DB->update_record('local_enrolduedate_rule', $data);
            return self::get_rule((int)$existing->id);
        }

        $data->timecreated = $now;
        $data->timemodified = $now;
        $id = $DB->insert_record('local_enrolduedate_rule', $data);
        return self::get_rule((int)$id);
    }

    /**
     * Marks a rule as broken reference and disabled.
     *
     * @param int $ruleid Rule ID.
     * @return void
     */
    public static function mark_rule_broken(int $ruleid): void {
        global $DB;
        $DB->update_record('local_enrolduedate_rule', (object)[
            'id' => $ruleid,
            'enabled' => 0,
            'status' => self::RULE_STATUS_BROKEN_REF,
            'refcmid' => null,
            'timemodified' => time(),
        ]);
    }

    /**
     * Disables a rule by ID.
     *
     * @param int $ruleid Rule ID.
     * @return void
     */
    public static function disable_rule(int $ruleid): void {
        global $DB;
        $DB->update_record('local_enrolduedate_rule', (object)[
            'id' => $ruleid,
            'enabled' => 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * Deletes a rule and all its tracking records.
     *
     * @param int $ruleid Rule ID.
     * @return void
     */
    public static function delete_rule_and_applied(int $ruleid): void {
        global $DB;
        $DB->delete_records('local_enrolduedate_applied', ['ruleid' => $ruleid]);
        $DB->delete_records('local_enrolduedate_rule', ['id' => $ruleid]);
    }

    /**
     * Deletes all plugin records belonging to a course.
     *
     * @param int $courseid Course ID.
     * @return void
     */
    public static function delete_course_data(int $courseid): void {
        global $DB;
        $DB->delete_records('local_enrolduedate_applied', ['courseid' => $courseid]);
        $DB->delete_records('local_enrolduedate_rule', ['courseid' => $courseid]);
        $DB->delete_records('local_enrolduedate_course', ['courseid' => $courseid]);
    }

    /**
     * Fetches a single applied tracking record by rule and user.
     *
     * @param int $ruleid Rule ID.
     * @param int $userid User ID.
     * @return stdClass|null
     */
    public static function get_applied(int $ruleid, int $userid): ?stdClass {
        global $DB;
        $record = $DB->get_record('local_enrolduedate_applied', [
            'ruleid' => $ruleid,
            'userid' => $userid,
        ]);
        return $record ?: null;
    }

    /**
     * Fetches all applied tracking records for a rule keyed by userid.
     *
     * @param int $ruleid Rule ID.
     * @return stdClass[] Indexed by userid.
     */
    public static function get_applied_map_for_rule(int $ruleid): array {
        global $DB;
        $records = $DB->get_records('local_enrolduedate_applied', ['ruleid' => $ruleid]);
        $map = [];
        foreach ($records as $record) {
            $map[(int)$record->userid] = $record;
        }
        return $map;
    }

    /**
     * Fetches an applied tracking record matching a module type and override ID.
     *
     * @param string $modname Activity module name (e.g. assign).
     * @param int $overrideid Override row ID in module overrides table.
     * @param int|null $cmid Optional course module ID filter.
     * @return stdClass|null
     */
    public static function get_applied_by_overrideid(string $modname, int $overrideid, ?int $cmid = null): ?stdClass {
        global $DB;

        $params = [
            'modname' => $modname,
            'overrideid' => $overrideid,
        ];
        $cmclause = '';
        if ($cmid !== null) {
            $cmclause = 'AND a.cmid = :cmid';
            $params['cmid'] = $cmid;
        }

        $sql = "SELECT a.*
                  FROM {local_enrolduedate_applied} a
                  JOIN {local_enrolduedate_rule} r ON r.id = a.ruleid
                 WHERE r.modname = :modname
                   AND a.overrideid = :overrideid
                   {$cmclause}";
        $record = $DB->get_record_sql($sql, $params);
        return $record ?: null;
    }

    /**
     * Inserts or updates an applied tracking row.
     *
     * @param stdClass $record Applied fields.
     * @return stdClass
     */
    public static function upsert_applied(stdClass $record): stdClass {
        global $DB;

        $record->timemodified = time();
        if (!empty($record->id)) {
            $DB->update_record('local_enrolduedate_applied', $record);
            return $record;
        }

        $existing = self::get_applied((int)$record->ruleid, (int)$record->userid);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_enrolduedate_applied', $record);
            return $record;
        }

        $record->id = $DB->insert_record('local_enrolduedate_applied', $record);
        return $record;
    }

    /**
     * Deletes a single applied record by ID.
     *
     * @param int $appliedid Applied record ID.
     * @return void
     */
    public static function delete_applied(int $appliedid): void {
        global $DB;
        $DB->delete_records('local_enrolduedate_applied', ['id' => $appliedid]);
    }
}
