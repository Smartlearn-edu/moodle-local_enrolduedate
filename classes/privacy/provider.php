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

namespace local_enrolduedate\privacy;

use context;
use context_course;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_enrolduedate\local\rule_repository;

/**
 * Privacy Subsystem implementation for local_enrolduedate.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Returns metadata about personal data stored by this plugin.
     *
     * @param collection $collection Metadata collection to populate.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_enrolduedate_course',
            [
                'courseid' => 'privacy:metadata:local_enrolduedate_course:courseid',
                'enrolanchormode' => 'privacy:metadata:local_enrolduedate_course:enrolanchormode',
                'usermodified' => 'privacy:metadata:local_enrolduedate_course:usermodified',
                'timecreated' => 'privacy:metadata:local_enrolduedate_course:timecreated',
                'timemodified' => 'privacy:metadata:local_enrolduedate_course:timemodified',
            ],
            'privacy:metadata:local_enrolduedate_course'
        );

        $collection->add_database_table(
            'local_enrolduedate_rule',
            [
                'cmid' => 'privacy:metadata:local_enrolduedate_rule:cmid',
                'usermodified' => 'privacy:metadata:local_enrolduedate_rule:usermodified',
                'timecreated' => 'privacy:metadata:local_enrolduedate_rule:timecreated',
                'timemodified' => 'privacy:metadata:local_enrolduedate_rule:timemodified',
            ],
            'privacy:metadata:local_enrolduedate_rule'
        );

        $collection->add_database_table(
            'local_enrolduedate_applied',
            [
                'ruleid' => 'privacy:metadata:local_enrolduedate_applied:ruleid',
                'cmid' => 'privacy:metadata:local_enrolduedate_applied:cmid',
                'userid' => 'privacy:metadata:local_enrolduedate_applied:userid',
                'anchortime' => 'privacy:metadata:local_enrolduedate_applied:anchortime',
                'computedopen' => 'privacy:metadata:local_enrolduedate_applied:computedopen',
                'computeddue' => 'privacy:metadata:local_enrolduedate_applied:computeddue',
                'computedcutoff' => 'privacy:metadata:local_enrolduedate_applied:computedcutoff',
                'manualoverride' => 'privacy:metadata:local_enrolduedate_applied:manualoverride',
                'notified' => 'privacy:metadata:local_enrolduedate_applied:notified',
                'status' => 'privacy:metadata:local_enrolduedate_applied:status',
                'timemodified' => 'privacy:metadata:local_enrolduedate_applied:timemodified',
            ],
            'privacy:metadata:local_enrolduedate_applied'
        );

        return $collection;
    }

    /**
     * Finds all contexts storing personal data for a given user ID.
     *
     * @param int $userid User ID.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $appliedsql = "SELECT ctx.id
                         FROM {context} ctx
                         JOIN {local_enrolduedate_applied} a ON a.cmid = ctx.instanceid
                        WHERE ctx.contextlevel = :contextlevel
                          AND a.userid = :userid";
        $contextlist->add_from_sql($appliedsql, [
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid,
        ]);

        $rulesql = "SELECT ctx.id
                      FROM {context} ctx
                      JOIN {local_enrolduedate_rule} r ON r.cmid = ctx.instanceid
                     WHERE ctx.contextlevel = :contextlevel
                       AND r.usermodified = :userid";
        $contextlist->add_from_sql($rulesql, [
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid,
        ]);

        $coursesql = "SELECT ctx.id
                        FROM {context} ctx
                        JOIN {local_enrolduedate_course} c ON c.courseid = ctx.instanceid
                       WHERE ctx.contextlevel = :contextlevel
                         AND c.usermodified = :userid";
        $contextlist->add_from_sql($coursesql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Collects all users having personal data inside a specific context.
     *
     * @param userlist $userlist User list to populate.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context instanceof context_module) {
            $appliedsql = "SELECT userid
                             FROM {local_enrolduedate_applied}
                            WHERE cmid = :cmid";
            $userlist->add_from_sql('userid', $appliedsql, ['cmid' => (int)$context->instanceid]);

            $rulesql = "SELECT usermodified
                          FROM {local_enrolduedate_rule}
                         WHERE cmid = :cmid
                           AND usermodified > 0";
            $userlist->add_from_sql('usermodified', $rulesql, ['cmid' => (int)$context->instanceid]);
        } else if ($context instanceof context_course) {
            $coursesql = "SELECT usermodified
                            FROM {local_enrolduedate_course}
                           WHERE courseid = :courseid
                             AND usermodified > 0";
            $userlist->add_from_sql('usermodified', $coursesql, ['courseid' => (int)$context->instanceid]);
        }
    }

    /**
     * Exports personal data for approved contexts of one user.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int)$contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_course) {
                $record = $DB->get_record('local_enrolduedate_course', [
                    'courseid' => (int)$context->instanceid,
                    'usermodified' => $userid,
                ]);
                if ($record) {
                    writer::with_context($context)->export_data(
                        [get_string('privacy:path:coursesettings', 'local_enrolduedate')],
                        (object)[
                            'enrolanchormode' => $record->enrolanchormode,
                            'timecreated' => transform::datetime((int)$record->timecreated),
                            'timemodified' => transform::datetime((int)$record->timemodified),
                        ]
                    );
                }
            } else if ($context instanceof context_module) {
                $cmid = (int)$context->instanceid;
                $rule = $DB->get_record('local_enrolduedate_rule', [
                    'cmid' => $cmid,
                    'usermodified' => $userid,
                ]);
                if ($rule) {
                    writer::with_context($context)->export_data(
                        [get_string('privacy:path:rules', 'local_enrolduedate')],
                        (object)[
                            'enabled' => transform::yesno(!empty($rule->enabled)),
                            'triggertype' => $rule->triggertype,
                            'snapmode' => $rule->snapmode,
                            'timecreated' => transform::datetime((int)$rule->timecreated),
                            'timemodified' => transform::datetime((int)$rule->timemodified),
                        ]
                    );
                }

                $appliedrows = $DB->get_records('local_enrolduedate_applied', [
                    'cmid' => $cmid,
                    'userid' => $userid,
                ]);
                foreach ($appliedrows as $applied) {
                    writer::with_context($context)->export_data(
                        [get_string('privacy:path:studentdates', 'local_enrolduedate')],
                        (object)[
                            'anchortime' => !empty($applied->anchortime)
                                ? transform::datetime((int)$applied->anchortime)
                                : '',
                            'computedopen' => !empty($applied->computedopen)
                                ? transform::datetime((int)$applied->computedopen)
                                : '',
                            'computeddue' => !empty($applied->computeddue)
                                ? transform::datetime((int)$applied->computeddue)
                                : '',
                            'computedcutoff' => !empty($applied->computedcutoff)
                                ? transform::datetime((int)$applied->computedcutoff)
                                : '',
                            'manualoverride' => transform::yesno(!empty($applied->manualoverride)),
                            'notified' => transform::yesno(!empty($applied->notified)),
                            'status' => $applied->status,
                            'timemodified' => transform::datetime((int)$applied->timemodified),
                        ]
                    );
                }
            }
        }
    }

    /**
     * Deletes all plugin data within a given context.
     *
     * @param context $context Context to wipe.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        if ($context instanceof context_course) {
            rule_repository::delete_course_data((int)$context->instanceid);
        } else if ($context instanceof context_module) {
            $rule = rule_repository::get_rule_by_cmid((int)$context->instanceid);
            if ($rule) {
                rule_repository::delete_rule_and_applied((int)$rule->id);
            }
        }
    }

    /**
     * Deletes/anonymizes personal data for one user across approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_course) {
                $DB->set_field(
                    'local_enrolduedate_course',
                    'usermodified',
                    0,
                    ['courseid' => (int)$context->instanceid, 'usermodified' => $userid]
                );
            } else if ($context instanceof context_module) {
                $cmid = (int)$context->instanceid;
                $DB->set_field(
                    'local_enrolduedate_rule',
                    'usermodified',
                    0,
                    ['cmid' => $cmid, 'usermodified' => $userid]
                );
                $DB->delete_records('local_enrolduedate_applied', [
                    'cmid' => $cmid,
                    'userid' => $userid,
                ]);
            }
        }
    }

    /**
     * Deletes/anonymizes personal data for multiple users inside one context.
     *
     * @param approved_userlist $userlist Approved users within one context.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        $context = $userlist->get_context();
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');

        if ($context instanceof context_course) {
            $params = array_merge($inparams, ['courseid' => (int)$context->instanceid]);
            $DB->set_field_select(
                'local_enrolduedate_course',
                'usermodified',
                0,
                "courseid = :courseid AND usermodified {$insql}",
                $params
            );
        } else if ($context instanceof context_module) {
            $params = array_merge($inparams, ['cmid' => (int)$context->instanceid]);
            $DB->set_field_select(
                'local_enrolduedate_rule',
                'usermodified',
                0,
                "cmid = :cmid AND usermodified {$insql}",
                $params
            );
            $DB->delete_records_select(
                'local_enrolduedate_applied',
                "cmid = :cmid AND userid {$insql}",
                $params
            );
        }
    }
}
