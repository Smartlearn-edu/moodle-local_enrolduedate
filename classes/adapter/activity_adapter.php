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

use stdClass;

/**
 * Contract for per-activity override adapters.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface activity_adapter {
    /**
     * Returns the supported Moodle module name (e.g. assign).
     *
     * @return string
     */
    public function get_modname(): string;

    /**
     * Checks whether the specific activity instance can use relative due dates.
     *
     * @param stdClass $cm Course module record (needs id, course, instance).
     * @param stdClass|null $instance Optional loaded module instance record.
     * @return bool
     */
    public function supports_relative_due_dates(stdClass $cm, ?stdClass $instance = null): bool;

    /**
     * Returns eligible enrolled students keyed by userid.
     *
     * @param stdClass $cm Course module record.
     * @param stdClass $course Course record.
     * @return array<int, stdClass> User records keyed by userid.
     */
    public function get_eligible_users(stdClass $cm, stdClass $course): array;

    /**
     * Checks whether a single user is eligible for relative overrides on this activity.
     *
     * @param stdClass $cm Course module record.
     * @param int $userid User ID.
     * @return bool
     */
    public function is_user_eligible(stdClass $cm, int $userid): bool;

    /**
     * Clears fixed base activity dates when relative due dates are enabled.
     *
     * @param stdClass $cm Course module record.
     * @param stdClass|null $formdata Optional submitted course module form data.
     * @return void
     */
    public function clear_base_dates(stdClass $cm, ?stdClass $formdata = null): void;

    /**
     * Loads existing module overrides for a list of users, keyed by userid.
     *
     * @param int $instanceid Activity instance ID.
     * @param int[] $userids User IDs.
     * @return array<int, stdClass> Override records keyed by userid.
     */
    public function get_overrides_by_users(int $instanceid, array $userids): array;

    /**
     * Loads a single override record by its primary ID.
     *
     * @param int $overrideid Override table ID.
     * @return stdClass|null
     */
    public function get_override_by_id(int $overrideid): ?stdClass;

    /**
     * Creates or updates a student user override in the target module.
     *
     * @param stdClass $cm Course module record.
     * @param stdClass $course Course record.
     * @param int $userid Student user ID.
     * @param stdClass $dates Object containing open (?int), due (int), cutoff (?int).
     * @param int|null $existingoverrideid Existing override row ID if known.
     * @param bool $updateevents Whether to immediately reconcile calendar events for this override.
     * @return stdClass Result with id (int), open (?int), due (?int), cutoff (?int), changed (bool).
     */
    public function apply_user_override(
        stdClass $cm,
        stdClass $course,
        int $userid,
        stdClass $dates,
        ?int $existingoverrideid = null,
        bool $updateevents = true
    ): stdClass;

    /**
     * Deletes a plugin-generated student override from the target module.
     *
     * @param stdClass $cm Course module record.
     * @param stdClass $course Course record.
     * @param int $overrideid Override row ID.
     * @param bool $updateevents Whether to immediately reconcile calendar events.
     * @return void
     */
    public function delete_user_override(stdClass $cm, stdClass $course, int $overrideid, bool $updateevents = true): void;

    /**
     * Reconciles all calendar action events for the activity in one pass.
     *
     * @param stdClass $cm Course module record.
     * @param stdClass $course Course record.
     * @return void
     */
    public function reconcile_events(stdClass $cm, stdClass $course): void;

    /**
     * Returns the formatted activity name for UI and notifications.
     *
     * @param stdClass $cm Course module record.
     * @return string
     */
    public function get_activity_name(stdClass $cm): string;
}
