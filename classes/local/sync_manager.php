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

use local_enrolduedate\adapter\activity_adapter;
use local_enrolduedate\adapter\adapter_factory;
use local_enrolduedate\adapter\assign_adapter;
use local_enrolduedate\task\sync_rule_adhoc;
use stdClass;

/**
 * Orchestrates relative due date synchronization, conflict protection, and cleanup.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_manager {
    /** @var int Re-entrancy depth counter for internal override writes. */
    private static int $internaldepth = 0;

    /**
     * Executes a callback while marking override changes as plugin-internal.
     *
     * @param callable $callback Callable returning any value.
     * @return mixed
     */
    public static function run_as_internal(callable $callback) {
        self::$internaldepth++;
        try {
            return $callback();
        } finally {
            self::$internaldepth--;
        }
    }

    /**
     * Indicates whether the plugin is currently performing an internal override write.
     *
     * @return bool
     */
    public static function is_internal_write(): bool {
        return self::$internaldepth > 0;
    }

    /**
     * Returns the configured student threshold above which rule syncs run asynchronously.
     *
     * @return int
     */
    public static function get_sync_threshold(): int {
        $value = get_config('local_enrolduedate', 'syncthreshold');
        if ($value === false || (int)$value < 1) {
            return 30;
        }
        return (int)$value;
    }

    /**
     * Queues an adhoc background task to synchronize a rule across its course.
     *
     * @param int $ruleid Rule ID.
     * @param bool $keepexistinganchors Lock previously captured completion/view anchors.
     * @param bool $sendnotifications Send notifications when activity triggers unlock dates.
     * @return void
     */
    public static function queue_rule_sync(
        int $ruleid,
        bool $keepexistinganchors = true,
        bool $sendnotifications = false
    ): void {
        $task = new sync_rule_adhoc();
        $task->set_custom_data((object)[
            'ruleid' => $ruleid,
            'keepexistinganchors' => $keepexistinganchors,
            'sendnotifications' => $sendnotifications,
        ]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Runs either inline or queued sync for a rule depending on eligible student count.
     *
     * @param stdClass $rule Rule record.
     * @param bool $keepexistinganchors Lock existing completion/view anchors.
     * @param bool $sendnotifications Notify on newly unlocked activity-triggered dates.
     * @return bool True if synced inline, false if queued to adhoc task.
     */
    public static function dispatch_rule_sync(
        stdClass $rule,
        bool $keepexistinganchors = true,
        bool $sendnotifications = false
    ): bool {
        if (!adapter_factory::is_supported($rule->modname)) {
            return true;
        }
        $cm = get_coursemodule_from_id($rule->modname, (int)$rule->cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return true;
        }
        $course = get_course((int)$rule->courseid);
        $adapter = adapter_factory::get_adapter($rule->modname);
        $eligiblecount = count($adapter->get_eligible_users($cm, $course));

        if ($eligiblecount <= self::get_sync_threshold()) {
            self::sync_rule_for_course($rule, $keepexistinganchors, $sendnotifications);
            return true;
        }

        self::queue_rule_sync((int)$rule->id, $keepexistinganchors, $sendnotifications);
        return false;
    }

    /**
     * Synchronizes one relative due date rule for all eligible students in its course.
     *
     * Uses bulk SQL pre-fetching and reconciles assignment calendar events once at completion.
     *
     * @param stdClass $rule Rule record.
     * @param bool $keepexistinganchors Lock existing completion/view anchors.
     * @param bool $sendnotifications Send unlock notifications for activity triggers.
     * @param int|null $now Reference timestamp (defaults to time()).
     * @return int Number of eligible students processed.
     */
    public static function sync_rule_for_course(
        stdClass $rule,
        bool $keepexistinganchors = true,
        bool $sendnotifications = false,
        ?int $now = null
    ): int {
        global $DB;

        if (!adapter_factory::is_supported($rule->modname)) {
            return 0;
        }
        if (empty($rule->enabled) || ($rule->status ?? '') !== rule_repository::RULE_STATUS_ACTIVE) {
            return 0;
        }

        $cm = get_coursemodule_from_id($rule->modname, (int)$rule->cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return 0;
        }
        $course = $DB->get_record('course', ['id' => (int)$rule->courseid]);
        if (!$course) {
            return 0;
        }

        $adapter = adapter_factory::get_adapter($rule->modname);
        if (!$adapter->supports_relative_due_dates($cm)) {
            return 0;
        }

        $now = $now ?? time();
        $eligibleusers = $adapter->get_eligible_users($cm, $course);
        $existingapplied = rule_repository::get_applied_map_for_rule((int)$rule->id);

        // Clean up tracked users who are no longer eligible (unenrolled, expired, or role removed).
        foreach ($existingapplied as $userid => $applied) {
            if (isset($eligibleusers[$userid])) {
                continue;
            }
            self::handle_ineligible_tracked_user($rule, $cm, $course, $adapter, $applied, $now);
        }

        if (empty($eligibleusers)) {
            return 0;
        }

        $userids = array_keys($eligibleusers);
        $existingoverrides = $adapter->get_overrides_by_users((int)$cm->instance, $userids);
        $anchors = anchor_resolver::resolve_for_users(
            $rule,
            $course,
            $userids,
            $existingapplied,
            $keepexistinganchors,
            $now
        );

        $anychanged = false;
        $activityname = null;
        foreach ($eligibleusers as $userid => $user) {
            $changed = self::sync_resolved_user(
                $rule,
                $cm,
                $course,
                $adapter,
                $user,
                $anchors[$userid] ?? null,
                $existingapplied[$userid] ?? null,
                $existingoverrides[$userid] ?? null,
                $sendnotifications,
                false,
                false,
                $activityname
            );
            if ($changed) {
                $anychanged = true;
            }
        }

        if ($anychanged) {
            $adapter->reconcile_events($cm, $course);
        }

        return count($eligibleusers);
    }

    /**
     * Synchronizes a single student against one active rule.
     *
     * @param stdClass $rule Rule record.
     * @param int $userid User ID.
     * @param bool $keepexistinganchors Preserve recorded completion/view anchor timestamps.
     * @param bool $sendnotifications Send notification on unlock if eligible.
     * @param stdClass|null $cm Optional course module record.
     * @param stdClass|null $course Optional course record.
     * @param int|null $now Reference timestamp.
     * @return void
     */
    public static function sync_user_for_rule(
        stdClass $rule,
        int $userid,
        bool $keepexistinganchors = true,
        bool $sendnotifications = false,
        ?stdClass $cm = null,
        ?stdClass $course = null,
        ?int $now = null
    ): void {
        global $DB;

        if (!adapter_factory::is_supported($rule->modname)) {
            return;
        }
        if (empty($rule->enabled) || ($rule->status ?? '') !== rule_repository::RULE_STATUS_ACTIVE) {
            return;
        }

        $cm = $cm ?? get_coursemodule_from_id($rule->modname, (int)$rule->cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $course = $course ?? $DB->get_record('course', ['id' => (int)$rule->courseid]);
        if (!$course) {
            return;
        }

        $adapter = adapter_factory::get_adapter($rule->modname);
        if (!$adapter->supports_relative_due_dates($cm)) {
            return;
        }

        $existing = rule_repository::get_applied((int)$rule->id, $userid);
        if (!$adapter->is_user_eligible($cm, $userid)) {
            if ($existing) {
                self::handle_ineligible_tracked_user($rule, $cm, $course, $adapter, $existing, $now ?? time());
            }
            return;
        }

        $user = $DB->get_record(
            'user',
            ['id' => $userid, 'deleted' => 0],
            'id, timezone, firstname, lastname, email, mailformat, lang'
        );
        if (!$user) {
            return;
        }

        $existingmap = $existing ? [$userid => $existing] : [];
        $anchors = anchor_resolver::resolve_for_users($rule, $course, [$userid], $existingmap, $keepexistinganchors, $now);
        $overrides = $adapter->get_overrides_by_users((int)$cm->instance, [$userid]);
        $activityname = null;

        self::sync_resolved_user(
            $rule,
            $cm,
            $course,
            $adapter,
            $user,
            $anchors[$userid] ?? null,
            $existing,
            $overrides[$userid] ?? null,
            $sendnotifications,
            true,
            false,
            $activityname
        );
    }

    /**
     * Disables a rule and removes all plugin-generated overrides while preserving manual ones.
     *
     * @param stdClass $rule Rule record.
     * @return void
     */
    public static function disable_rule_and_cleanup(stdClass $rule): void {
        global $DB;

        if (!adapter_factory::is_supported($rule->modname)) {
            rule_repository::disable_rule((int)$rule->id);
            return;
        }

        $cm = get_coursemodule_from_id($rule->modname, (int)$rule->cmid, 0, false, IGNORE_MISSING);
        $course = $DB->get_record('course', ['id' => (int)$rule->courseid]);
        $adapter = adapter_factory::get_adapter($rule->modname);
        $appliedmap = rule_repository::get_applied_map_for_rule((int)$rule->id);

        if ($cm && $course) {
            $overrides = $adapter->get_overrides_by_users((int)$cm->instance, array_keys($appliedmap));
            foreach ($appliedmap as $userid => $applied) {
                $override = $overrides[$userid] ?? null;
                if (!empty($applied->manualoverride) || self::has_manual_conflict($applied, $override)) {
                    if (empty($applied->manualoverride)) {
                        $applied->manualoverride = 1;
                        $applied->status = rule_repository::APPLIED_STATUS_MANUAL_LOCKED;
                        rule_repository::upsert_applied($applied);
                    }
                    continue;
                }
                if (!empty($applied->overrideid)) {
                    $adapter->delete_user_override($cm, $course, (int)$applied->overrideid, true);
                }
                rule_repository::delete_applied((int)$applied->id);
            }
        }

        rule_repository::disable_rule((int)$rule->id);
    }

    /**
     * Cleans up plugin-generated overrides and tracking rows for a user removed from a course.
     *
     * @param int $courseid Course ID.
     * @param int $userid User ID.
     * @return void
     */
    public static function cleanup_user_for_course(int $courseid, int $userid): void {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        $rules = rule_repository::get_rules_for_course($courseid, false);
        foreach ($rules as $rule) {
            $applied = rule_repository::get_applied((int)$rule->id, $userid);
            if (!$applied) {
                continue;
            }
            if (!empty($applied->manualoverride)) {
                continue;
            }
            if ($course && adapter_factory::is_supported($rule->modname)) {
                $cm = get_coursemodule_from_id($rule->modname, (int)$rule->cmid, 0, false, IGNORE_MISSING);
                if ($cm) {
                    $adapter = adapter_factory::get_adapter($rule->modname);
                    $overrides = $adapter->get_overrides_by_users((int)$cm->instance, [$userid]);
                    $override = $overrides[$userid] ?? null;
                    if (self::has_manual_conflict($applied, $override)) {
                        $applied->manualoverride = 1;
                        $applied->status = rule_repository::APPLIED_STATUS_MANUAL_LOCKED;
                        rule_repository::upsert_applied($applied);
                        continue;
                    }
                    if (!empty($applied->overrideid)) {
                        $adapter->delete_user_override($cm, $course, (int)$applied->overrideid, true);
                    }
                }
            }
            rule_repository::delete_applied((int)$applied->id);
        }
    }

    /**
     * Unlocks a manual lock row and re-applies the active relative due date rule.
     *
     * @param int $appliedid Primary ID of {local_enrolduedate_applied}.
     * @return bool True if unlocked successfully.
     */
    public static function unlock_manual_override(int $appliedid): bool {
        global $DB;

        $applied = $DB->get_record('local_enrolduedate_applied', ['id' => $appliedid]);
        if (!$applied) {
            return false;
        }
        $rule = rule_repository::get_rule((int)$applied->ruleid);
        if (!$rule || !adapter_factory::is_supported($rule->modname)) {
            return false;
        }

        $cm = get_coursemodule_from_id($rule->modname, (int)$rule->cmid, 0, false, IGNORE_MISSING);
        $course = $DB->get_record('course', ['id' => (int)$rule->courseid]);
        if (!$cm || !$course) {
            return false;
        }

        $adapter = adapter_factory::get_adapter($rule->modname);

        if (empty($rule->enabled) || ($rule->status ?? '') !== rule_repository::RULE_STATUS_ACTIVE) {
            if (!empty($applied->overrideid)) {
                $adapter->delete_user_override($cm, $course, (int)$applied->overrideid, true);
            }
            rule_repository::delete_applied((int)$applied->id);
            return true;
        }

        $userid = (int)$applied->userid;
        if (!$adapter->is_user_eligible($cm, $userid)) {
            if (!empty($applied->overrideid)) {
                $adapter->delete_user_override($cm, $course, (int)$applied->overrideid, true);
            }
            rule_repository::delete_applied((int)$applied->id);
            return true;
        }

        $user = $DB->get_record(
            'user',
            ['id' => $userid, 'deleted' => 0],
            'id, timezone, firstname, lastname, email, mailformat, lang'
        );
        if (!$user) {
            return false;
        }

        $applied->manualoverride = 0;
        $applied->status = rule_repository::APPLIED_STATUS_WAITING;
        $overrides = $adapter->get_overrides_by_users((int)$cm->instance, [$userid]);
        $anchors = anchor_resolver::resolve_for_users($rule, $course, [$userid], [$userid => $applied], true);
        $activityname = null;

        self::sync_resolved_user(
            $rule,
            $cm,
            $course,
            $adapter,
            $user,
            $anchors[$userid] ?? null,
            $applied,
            $overrides[$userid] ?? null,
            false,
            true,
            true,
            $activityname
        );

        return true;
    }

    /**
     * Reconciles all rules and tracking records in a course after a course reset finishes.
     *
     * @param int $courseid Course ID.
     * @return void
     */
    public static function reconcile_course_after_reset(int $courseid): void {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return;
        }

        $rules = rule_repository::get_rules_for_course($courseid, false);
        foreach ($rules as $rule) {
            if (!adapter_factory::is_supported($rule->modname)) {
                continue;
            }
            $cm = get_coursemodule_from_id($rule->modname, (int)$rule->cmid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }

            $adapter = adapter_factory::get_adapter($rule->modname);
            $appliedmap = rule_repository::get_applied_map_for_rule((int)$rule->id);
            if (!empty($appliedmap)) {
                $overrides = $adapter->get_overrides_by_users((int)$cm->instance, array_keys($appliedmap));
                foreach ($appliedmap as $userid => $applied) {
                    if (empty($overrides[$userid])) {
                        // Override was wiped by course reset; clear stale lock/anchor tracking so it can re-sync cleanly.
                        if (!anchor_resolver::has_any_active_enrolment($courseid, $userid)) {
                            rule_repository::delete_applied((int)$applied->id);
                        } else {
                            $applied->overrideid = null;
                            $applied->anchortime = null;
                            $applied->computedopen = null;
                            $applied->computeddue = null;
                            $applied->computedcutoff = null;
                            $applied->manualoverride = 0;
                            $applied->notified = 0;
                            $applied->status = rule_repository::APPLIED_STATUS_WAITING;
                            rule_repository::upsert_applied($applied);
                        }
                    }
                }
            }

            if (!empty($rule->enabled) && ($rule->status ?? '') === rule_repository::RULE_STATUS_ACTIVE) {
                self::dispatch_rule_sync($rule, false, false);
            }
        }
    }

    /**
     * Processes one resolved student against a rule, protecting manual overrides and avoiding no-ops.
     *
     * @param stdClass $rule Rule record.
     * @param stdClass $cm Course module record.
     * @param stdClass $course Course record.
     * @param activity_adapter $adapter Activity adapter.
     * @param stdClass $user Student user record.
     * @param int|null $anchortime Resolved anchor timestamp or null if untriggered.
     * @param stdClass|null $existingapplied Existing applied row or null.
     * @param stdClass|null $existingoverride Existing module override row or null.
     * @param bool $sendnotifications Send notification on unlock if eligible.
     * @param bool $updateevents Whether adapter should immediately sync calendar events per user.
     * @param bool $forceoverwrite Ignore manual-conflict guard (used only for explicit teacher unlock).
     * @param string|null $activityname Lazy-cached activity display name.
     * @return bool True if the underlying module override record was created or updated.
     */
    private static function sync_resolved_user(
        stdClass $rule,
        stdClass $cm,
        stdClass $course,
        activity_adapter $adapter,
        stdClass $user,
        ?int $anchortime,
        ?stdClass $existingapplied,
        ?stdClass $existingoverride,
        bool $sendnotifications,
        bool $updateevents,
        bool $forceoverwrite,
        ?string &$activityname
    ): bool {
        $userid = (int)$user->id;

        if (!$forceoverwrite) {
            if ($existingapplied && !empty($existingapplied->manualoverride)) {
                return false;
            }
            if (self::has_manual_conflict($existingapplied, $existingoverride)) {
                $lock = $existingapplied ?? (object)[
                    'ruleid' => (int)$rule->id,
                    'courseid' => (int)$rule->courseid,
                    'cmid' => (int)$rule->cmid,
                    'userid' => $userid,
                    'anchortime' => null,
                    'computedopen' => null,
                    'computeddue' => null,
                    'computedcutoff' => null,
                    'notified' => 0,
                ];
                $lock->overrideid = $existingoverride ? (int)$existingoverride->id : null;
                $lock->manualoverride = 1;
                $lock->status = rule_repository::APPLIED_STATUS_MANUAL_LOCKED;
                rule_repository::upsert_applied($lock);
                return false;
            }
        }

        if ($anchortime === null) {
            $overrideidtodelete = null;
            if ($existingapplied && !empty($existingapplied->overrideid)) {
                $overrideidtodelete = (int)$existingapplied->overrideid;
            } else if ($forceoverwrite && $existingoverride) {
                $overrideidtodelete = (int)$existingoverride->id;
            }
            if ($overrideidtodelete !== null) {
                $adapter->delete_user_override($cm, $course, $overrideidtodelete, true);
            }

            if (
                $existingapplied &&
                $existingapplied->status === rule_repository::APPLIED_STATUS_WAITING &&
                $existingapplied->overrideid === null &&
                $existingapplied->anchortime === null &&
                empty($existingapplied->manualoverride) &&
                empty($existingapplied->notified)
            ) {
                return false;
            }

            $waiting = $existingapplied ?? (object)[
                'ruleid' => (int)$rule->id,
                'courseid' => (int)$rule->courseid,
                'cmid' => (int)$rule->cmid,
                'userid' => $userid,
            ];
            $waiting->overrideid = null;
            $waiting->anchortime = null;
            $waiting->computedopen = null;
            $waiting->computeddue = null;
            $waiting->computedcutoff = null;
            $waiting->manualoverride = 0;
            $waiting->notified = 0;
            $waiting->status = rule_repository::APPLIED_STATUS_WAITING;
            rule_repository::upsert_applied($waiting);
            return false;
        }

        $dates = calculator::compute_dates($anchortime, $rule, $user);

        // No-op short-circuit when everything already matches both applied tracking and target override.
        if (
            !$forceoverwrite &&
            $existingapplied &&
            $existingapplied->status === rule_repository::APPLIED_STATUS_APPLIED &&
            empty($existingapplied->manualoverride) &&
            !empty($existingapplied->overrideid) &&
            $existingoverride &&
            (int)$existingoverride->id === (int)$existingapplied->overrideid &&
            (int)$existingapplied->anchortime === $anchortime &&
            assign_adapter::same_nullable_int($existingapplied->computedopen, $dates->open) &&
            assign_adapter::same_nullable_int($existingapplied->computeddue, $dates->due) &&
            assign_adapter::same_nullable_int($existingapplied->computedcutoff, $dates->cutoff)
        ) {
            return false;
        }

        $targetoverrideid = null;
        if ($existingapplied && !empty($existingapplied->overrideid)) {
            $targetoverrideid = (int)$existingapplied->overrideid;
        } else if ($existingoverride) {
            $targetoverrideid = (int)$existingoverride->id;
        }

        $result = $adapter->apply_user_override($cm, $course, $userid, $dates, $targetoverrideid, $updateevents);

        $applied = $existingapplied ?? (object)[
            'ruleid' => (int)$rule->id,
            'courseid' => (int)$rule->courseid,
            'cmid' => (int)$rule->cmid,
            'userid' => $userid,
            'notified' => 0,
        ];
        $applied->overrideid = $result->id;
        $applied->anchortime = $anchortime;
        $applied->computedopen = $dates->open;
        $applied->computeddue = $dates->due;
        $applied->computedcutoff = $dates->cutoff;
        $applied->manualoverride = 0;
        $applied->status = rule_repository::APPLIED_STATUS_APPLIED;
        $applied = rule_repository::upsert_applied($applied);

        if ($sendnotifications && ($result->changed || $forceoverwrite) && empty($applied->notified)) {
            $activityname = $activityname ?? $adapter->get_activity_name($cm);
            if (notification_helper::send_if_eligible($rule, $course, $user, $applied, $activityname)) {
                $applied->notified = 1;
                rule_repository::upsert_applied($applied);
            }
        }

        return (bool)$result->changed;
    }

    /**
     * Handles a previously tracked user who is no longer eligible for a rule.
     *
     * @param stdClass $rule Rule record.
     * @param stdClass $cm Course module record.
     * @param stdClass $course Course record.
     * @param activity_adapter $adapter Activity adapter.
     * @param stdClass $applied Applied tracking row.
     * @param int $now Reference timestamp.
     * @return void
     */
    private static function handle_ineligible_tracked_user(
        stdClass $rule,
        stdClass $cm,
        stdClass $course,
        activity_adapter $adapter,
        stdClass $applied,
        int $now
    ): void {
        if (!empty($applied->manualoverride)) {
            return;
        }

        $overrides = $adapter->get_overrides_by_users((int)$cm->instance, [(int)$applied->userid]);
        $override = $overrides[(int)$applied->userid] ?? null;

        if (self::has_manual_conflict($applied, $override)) {
            $applied->overrideid = $override ? (int)$override->id : null;
            $applied->manualoverride = 1;
            $applied->status = rule_repository::APPLIED_STATUS_MANUAL_LOCKED;
            rule_repository::upsert_applied($applied);
            return;
        }

        if (!empty($applied->overrideid)) {
            $adapter->delete_user_override($cm, $course, (int)$applied->overrideid, true);
        }

        if (!anchor_resolver::has_any_active_enrolment((int)$course->id, (int)$applied->userid, $now)) {
            rule_repository::delete_applied((int)$applied->id);
            return;
        }

        if ($applied->status !== rule_repository::APPLIED_STATUS_SKIPPED || !empty($applied->overrideid)) {
            $applied->overrideid = null;
            $applied->anchortime = null;
            $applied->computedopen = null;
            $applied->computeddue = null;
            $applied->computedcutoff = null;
            $applied->status = rule_repository::APPLIED_STATUS_SKIPPED;
            rule_repository::upsert_applied($applied);
        }
    }

    /**
     * Detects whether a target override diverges from what the plugin wrote or pre-existed manually.
     *
     * @param stdClass|null $existingapplied Tracking record.
     * @param stdClass|null $existingoverride Actual override row in module table.
     * @return bool True if manual conflict detected.
     */
    private static function has_manual_conflict(?stdClass $existingapplied, ?stdClass $existingoverride): bool {
        if ($existingapplied === null || empty($existingapplied->overrideid)) {
            return $existingoverride !== null;
        }

        if ($existingoverride === null) {
            return true;
        }

        if ((int)$existingoverride->id !== (int)$existingapplied->overrideid) {
            return true;
        }

        return !assign_adapter::same_nullable_int($existingoverride->allowsubmissionsfromdate, $existingapplied->computedopen)
            || !assign_adapter::same_nullable_int($existingoverride->duedate, $existingapplied->computeddue)
            || !assign_adapter::same_nullable_int($existingoverride->cutoffdate, $existingapplied->computedcutoff);
    }
}
