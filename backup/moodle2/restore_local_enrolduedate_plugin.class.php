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

/**
 * Restore definition for local_enrolduedate.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_enrolduedate\adapter\adapter_factory;
use local_enrolduedate\local\availability_helper;
use local_enrolduedate\local\rule_repository;
use local_enrolduedate\local\sync_manager;

/**
 * Restores course anchor policies, module rules, tracking data, and remaps refcmid references.
 */
class restore_local_enrolduedate_plugin extends restore_local_plugin {
    /**
     * Defines restore paths for course-level plugin data.
     *
     * @return restore_path_element[]
     */
    protected function define_course_plugin_structure(): array {
        return [
            new restore_path_element('enrolduedate_course', $this->get_pathfor('/enrolduedate_course')),
        ];
    }

    /**
     * Processes restored course-level anchor policy data.
     *
     * @param array $data XML element data.
     * @return void
     */
    public function process_enrolduedate_course($data): void {
        global $DB;

        $data = (object)$data;
        $courseid = (int)$this->task->get_courseid();
        if ($DB->record_exists('local_enrolduedate_course', ['courseid' => $courseid])) {
            return;
        }

        $record = (object)[
            'courseid' => $courseid,
            'enrolanchormode' => $data->enrolanchormode ?? rule_repository::ANCHOR_EARLIEST_FLOORED,
            'usermodified' => !empty($data->usermodified) ? ((int)$this->get_mappingid('user', $data->usermodified) ?: 0) : 0,
            'timecreated' => (int)($data->timecreated ?? time()),
            'timemodified' => time(),
        ];
        $DB->insert_record('local_enrolduedate_course', $record);
    }

    /**
     * Defines restore paths for module-level plugin data.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure(): array {
        return [
            new restore_path_element('enrolduedate_rule', $this->get_pathfor('/enrolduedate_rule')),
            new restore_path_element(
                'enrolduedate_applied',
                $this->get_pathfor('/enrolduedate_rule/enrolduedate_applieds/enrolduedate_applied')
            ),
        ];
    }

    /**
     * Processes a restored relative due date rule.
     *
     * Refcmid stays in its pre-mapping state until after_restore_module() runs,
     * because reference activities may be restored later in the same restore plan.
     *
     * @param array $data XML element data.
     * @return void
     */
    public function process_enrolduedate_rule($data): void {
        global $DB;

        $data = (object)$data;
        $oldid = (int)$data->id;
        $cmid = (int)$this->task->get_moduleid();
        $courseid = (int)$this->task->get_courseid();

        if ($DB->record_exists('local_enrolduedate_rule', ['cmid' => $cmid])) {
            return;
        }

        $record = (object)[
            'courseid' => $courseid,
            'cmid' => $cmid,
            'modname' => $data->modname ?? 'assign',
            'enabled' => (int)($data->enabled ?? 0),
            'triggertype' => $data->triggertype ?? rule_repository::TRIGGER_ENROLMENT,
            'refcmid' => !empty($data->refcmid) ? (int)$data->refcmid : null,
            'restrictuntiltriggered' => (int)($data->restrictuntiltriggered ?? 1),
            'openoffsetvalue' => isset($data->openoffsetvalue) && $data->openoffsetvalue !== ''
                ? (int)$data->openoffsetvalue
                : null,
            'openoffsetunit' => !empty($data->openoffsetunit) ? $data->openoffsetunit : null,
            'dueoffsetvalue' => isset($data->dueoffsetvalue) && $data->dueoffsetvalue !== ''
                ? (int)$data->dueoffsetvalue
                : null,
            'dueoffsetunit' => !empty($data->dueoffsetunit) ? $data->dueoffsetunit : null,
            'cutoffoffsetvalue' => isset($data->cutoffoffsetvalue) && $data->cutoffoffsetvalue !== ''
                ? (int)$data->cutoffoffsetvalue
                : null,
            'cutoffoffsetunit' => !empty($data->cutoffoffsetunit) ? $data->cutoffoffsetunit : null,
            'snapmode' => $data->snapmode ?? rule_repository::SNAP_ENDOFDAY,
            'status' => $data->status ?? rule_repository::RULE_STATUS_ACTIVE,
            'usermodified' => !empty($data->usermodified) ? ((int)$this->get_mappingid('user', $data->usermodified) ?: 0) : 0,
            'timecreated' => (int)($data->timecreated ?? time()),
            'timemodified' => time(),
        ];

        $newid = $DB->insert_record('local_enrolduedate_rule', $record);
        $this->set_mapping('enrolduedate_rule', $oldid, $newid);
    }

    /**
     * Processes a restored student tracking row when userinfo is restored.
     *
     * @param array $data XML element data.
     * @return void
     */
    public function process_enrolduedate_applied($data): void {
        global $DB;

        $data = (object)$data;
        $newruleid = (int)$this->get_new_parentid('enrolduedate_rule');
        if ($newruleid <= 0) {
            return;
        }

        $newuserid = !empty($data->userid) ? $this->get_mappingid('user', $data->userid) : false;
        if (!$newuserid) {
            return;
        }

        $record = (object)[
            'ruleid' => $newruleid,
            'courseid' => (int)$this->task->get_courseid(),
            'cmid' => (int)$this->task->get_moduleid(),
            'userid' => (int)$newuserid,
            'overrideid' => null,
            'anchortime' => isset($data->anchortime) && $data->anchortime !== '' ? (int)$data->anchortime : null,
            'computedopen' => isset($data->computedopen) && $data->computedopen !== '' ? (int)$data->computedopen : null,
            'computeddue' => isset($data->computeddue) && $data->computeddue !== '' ? (int)$data->computeddue : null,
            'computedcutoff' => isset($data->computedcutoff) && $data->computedcutoff !== '' ? (int)$data->computedcutoff : null,
            'manualoverride' => (int)($data->manualoverride ?? 0),
            'notified' => (int)($data->notified ?? 0),
            'status' => $data->status ?? rule_repository::APPLIED_STATUS_WAITING,
            'timemodified' => time(),
        ];
        $DB->insert_record('local_enrolduedate_applied', $record);
    }

    /**
     * Remaps reference CM IDs after all modules in the restore plan exist, and queues rule sync.
     *
     * Handles single-module duplication (duplicate_module) on the same site when the
     * referenced activity was not part of the duplicate backup package.
     *
     * @return void
     */
    public function after_restore_module(): void {
        global $DB;

        $cmid = (int)$this->task->get_moduleid();
        $courseid = (int)$this->task->get_courseid();
        $rule = rule_repository::get_rule_by_cmid($cmid);
        if (!$rule) {
            return;
        }

        // Reconnect restored override IDs by matching (instance, userid) in target module.
        if (adapter_factory::is_supported($rule->modname)) {
            $cm = get_coursemodule_from_id($rule->modname, $cmid, 0, false, IGNORE_MISSING);
            $appliedmap = rule_repository::get_applied_map_for_rule((int)$rule->id);
            if ($cm && !empty($appliedmap)) {
                $adapter = adapter_factory::get_adapter($rule->modname);
                $overrides = $adapter->get_overrides_by_users((int)$cm->instance, array_keys($appliedmap));
                foreach ($appliedmap as $userid => $applied) {
                    if (!empty($overrides[$userid])) {
                        $applied->overrideid = (int)$overrides[$userid]->id;
                        rule_repository::upsert_applied($applied);
                    }
                }
            }
        }

        if ($rule->triggertype !== rule_repository::TRIGGER_ENROLMENT) {
            $oldrefcmid = (int)($rule->refcmid ?? 0);
            $mappedref = $oldrefcmid > 0 ? $this->get_mappingid('course_module', $oldrefcmid) : false;

            $resolvedref = null;
            if ($mappedref) {
                $resolvedref = (int)$mappedref;
            } else if (
                $oldrefcmid > 0 &&
                $this->task->is_samesite() &&
                $DB->record_exists('course_modules', ['id' => $oldrefcmid, 'course' => $courseid])
            ) {
                // Single-module duplication within the same course preserves the existing refcmid.
                $resolvedref = $oldrefcmid;
            }

            if ($resolvedref !== null && $resolvedref !== $cmid) {
                $DB->set_field('local_enrolduedate_rule', 'refcmid', $resolvedref, ['id' => $rule->id]);
                $rule->refcmid = $resolvedref;
                if (!empty($rule->restrictuntiltriggered) && !empty($rule->enabled)) {
                    availability_helper::ensure_completion_condition($cmid, $resolvedref);
                }
            } else {
                rule_repository::mark_rule_broken((int)$rule->id);
                return;
            }
        }

        if (!empty($rule->enabled) && ($rule->status ?? '') === rule_repository::RULE_STATUS_ACTIVE) {
            sync_manager::queue_rule_sync((int)$rule->id, true, false);
        }
    }
}
