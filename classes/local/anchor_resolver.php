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
 * Bulk and per-user anchor timestamp resolver.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class anchor_resolver {
    /**
     * Resolves anchor timestamps for a list of users using bulk queries (no N+1).
     *
     * @param stdClass $rule Rule record.
     * @param stdClass $course Course record (must include id and startdate).
     * @param int[] $userids Eligible student user IDs.
     * @param array<int, stdClass> $existingapplied Existing applied records keyed by userid.
     * @param bool $keepexistinganchors Lock previously captured completion/view anchor timestamps.
     * @param int|null $now Reference timestamp (defaults to time()).
     * @return array<int, int|null> Anchor timestamps keyed by userid.
     */
    public static function resolve_for_users(
        stdClass $rule,
        stdClass $course,
        array $userids,
        array $existingapplied = [],
        bool $keepexistinganchors = true,
        ?int $now = null
    ): array {
        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (empty($userids)) {
            return [];
        }

        $now = $now ?? time();

        switch ($rule->triggertype) {
            case rule_repository::TRIGGER_ENROLMENT:
                return self::resolve_enrolment_anchors($course, $userids, $now);

            case rule_repository::TRIGGER_ACTIVITY_COMPLETED:
                return self::resolve_completion_anchors($rule, $userids, $existingapplied, $keepexistinganchors, $now);

            case rule_repository::TRIGGER_ACTIVITY_VIEWED:
                return self::resolve_viewed_anchors($rule, $userids, $existingapplied, $keepexistinganchors, $now);

            default:
                return array_fill_keys($userids, null);
        }
    }

    /**
     * Checks whether a user currently holds at least one active enrolment in a course.
     *
     * @param int $courseid Course ID.
     * @param int $userid User ID.
     * @param int|null $now Reference timestamp (defaults to time()).
     * @return bool
     */
    public static function has_any_active_enrolment(int $courseid, int $userid, ?int $now = null): bool {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');

        $now = $now ?? time();
        $sql = "SELECT 1
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid
                   AND e.status = :enrolenabled
                   AND ue.userid = :userid
                   AND ue.status = :useractive
                   AND (ue.timeend = 0 OR ue.timeend > :now)";
        $params = [
            'courseid' => $courseid,
            'enrolenabled' => ENROL_INSTANCE_ENABLED,
            'userid' => $userid,
            'useractive' => ENROL_USER_ACTIVE,
            'now' => $now,
        ];
        return $DB->record_exists_sql($sql, $params);
    }

    /**
     * Resolves enrolment-based anchor timestamps in one bulk query.
     *
     * @param stdClass $course Course record.
     * @param int[] $userids Student user IDs.
     * @param int $now Current timestamp.
     * @return array<int, int|null>
     */
    private static function resolve_enrolment_anchors(stdClass $course, array $userids, int $now): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');

        $mode = rule_repository::get_effective_enrol_anchor_mode((int)$course->id);
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');

        $sql = "SELECT ue.id, ue.userid, ue.timestart, ue.timecreated
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid
                   AND e.status = :enrolenabled
                   AND ue.status = :useractive
                   AND (ue.timeend = 0 OR ue.timeend > :now)
                   AND ue.userid {$insql}";
        $params = array_merge($inparams, [
            'courseid' => (int)$course->id,
            'enrolenabled' => ENROL_INSTANCE_ENABLED,
            'useractive' => ENROL_USER_ACTIVE,
            'now' => $now,
        ]);

        $rows = $DB->get_records_sql($sql, $params);
        $grouped = [];
        foreach ($rows as $row) {
            $effective = ((int)$row->timestart > 0) ? (int)$row->timestart : (int)$row->timecreated;
            $grouped[(int)$row->userid][] = $effective;
        }

        $coursestart = (int)($course->startdate ?? 0);
        $results = [];
        foreach ($userids as $userid) {
            if (empty($grouped[$userid])) {
                $results[$userid] = null;
                continue;
            }
            $effectives = $grouped[$userid];
            if ($mode === rule_repository::ANCHOR_LATEST_RAW) {
                $results[$userid] = max($effectives);
            } else if ($mode === rule_repository::ANCHOR_EARLIEST_RAW) {
                $results[$userid] = min($effectives);
            } else {
                $min = min($effectives);
                $results[$userid] = ($coursestart > 0) ? max($min, $coursestart) : $min;
            }
        }

        return $results;
    }

    /**
     * Resolves completion-based anchor timestamps in one bulk query.
     *
     * @param stdClass $rule Rule record.
     * @param int[] $userids Student user IDs.
     * @param array<int, stdClass> $existingapplied Existing applied rows keyed by userid.
     * @param bool $keepexistinganchors Lock already-captured completion anchors.
     * @param int $now Current timestamp fallback.
     * @return array<int, int|null>
     */
    private static function resolve_completion_anchors(
        stdClass $rule,
        array $userids,
        array $existingapplied,
        bool $keepexistinganchors,
        int $now
    ): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        if (empty($rule->refcmid) || ($rule->status ?? '') === rule_repository::RULE_STATUS_BROKEN_REF) {
            return array_fill_keys($userids, null);
        }

        $completedstates = [
            COMPLETION_COMPLETE,
            COMPLETION_COMPLETE_PASS,
            COMPLETION_COMPLETE_FAIL,
        ];

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $sql = "SELECT userid, completionstate, timemodified
                  FROM {course_modules_completion}
                 WHERE coursemoduleid = :refcmid
                   AND userid {$insql}";
        $params = array_merge($inparams, ['refcmid' => (int)$rule->refcmid]);

        $cmcs = $DB->get_records_sql($sql, $params);
        $results = [];
        foreach ($userids as $userid) {
            $cmc = $cmcs[$userid] ?? null;
            if (!$cmc || !in_array((int)$cmc->completionstate, $completedstates, true)) {
                $results[$userid] = null;
                continue;
            }

            if ($keepexistinganchors && !empty($existingapplied[$userid]->anchortime)) {
                $results[$userid] = (int)$existingapplied[$userid]->anchortime;
                continue;
            }

            $results[$userid] = ((int)$cmc->timemodified > 0) ? (int)$cmc->timemodified : $now;
        }

        return $results;
    }

    /**
     * Resolves view-based anchor timestamps in one bulk query.
     *
     * @param stdClass $rule Rule record.
     * @param int[] $userids Student user IDs.
     * @param array<int, stdClass> $existingapplied Existing applied rows keyed by userid.
     * @param bool $keepexistinganchors Lock already-captured view anchors.
     * @param int $now Current timestamp fallback.
     * @return array<int, int|null>
     */
    private static function resolve_viewed_anchors(
        stdClass $rule,
        array $userids,
        array $existingapplied,
        bool $keepexistinganchors,
        int $now
    ): array {
        global $DB;

        if (empty($rule->refcmid) || ($rule->status ?? '') === rule_repository::RULE_STATUS_BROKEN_REF) {
            return array_fill_keys($userids, null);
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $sql = "SELECT userid, timecreated
                  FROM {course_modules_viewed}
                 WHERE coursemoduleid = :refcmid
                   AND userid {$insql}";
        $params = array_merge($inparams, ['refcmid' => (int)$rule->refcmid]);

        $cmvs = $DB->get_records_sql($sql, $params);
        $results = [];
        foreach ($userids as $userid) {
            $cmv = $cmvs[$userid] ?? null;
            if (!$cmv) {
                $results[$userid] = null;
                continue;
            }

            if ($keepexistinganchors && !empty($existingapplied[$userid]->anchortime)) {
                $results[$userid] = (int)$existingapplied[$userid]->anchortime;
                continue;
            }

            $results[$userid] = ((int)$cmv->timecreated > 0) ? (int)$cmv->timecreated : $now;
        }

        return $results;
    }
}
