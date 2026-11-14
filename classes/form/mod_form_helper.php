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

namespace local_enrolduedate\form;

use local_enrolduedate\adapter\adapter_factory;
use local_enrolduedate\local\availability_helper;
use local_enrolduedate\local\calculator;
use local_enrolduedate\local\rule_repository;
use local_enrolduedate\local\sync_manager;
use MoodleQuickForm;
use moodleform_mod;
use stdClass;

/**
 * Integrates relative due date controls, validation, and save hooks into coursemodule forms.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_form_helper {
    /**
     * Appends the Relative due dates form section to supported activity mod_form instances.
     *
     * @param moodleform_mod $formwrapper Wrapper activity form.
     * @param MoodleQuickForm $mform Underlying QuickForm instance.
     * @return void
     */
    public static function add_standard_elements(moodleform_mod $formwrapper, MoodleQuickForm $mform): void {
        global $CFG, $OUTPUT, $PAGE;
        require_once($CFG->libdir . '/completionlib.php');

        $current = $formwrapper->get_current();
        $modname = $current->modulename ?? '';
        if (!adapter_factory::is_supported($modname)) {
            return;
        }

        $course = $formwrapper->get_course();
        $coursecontext = \context_course::instance((int)$course->id);
        if (!has_capability('local/enrolduedate:manage', $coursecontext)) {
            return;
        }

        $cmid = !empty($current->coursemodule) ? (int)$current->coursemodule : 0;
        $existingrule = $cmid > 0 ? rule_repository::get_rule_by_cmid($cmid) : null;

        $mform->addElement('header', 'enrolduedateheader', get_string('enrolduedateheader', 'local_enrolduedate'));
        $hasactiveorbroken = $existingrule && (
            !empty($existingrule->enabled) ||
            $existingrule->status === rule_repository::RULE_STATUS_BROKEN_REF
        );
        if ($hasactiveorbroken) {
            $mform->setExpanded('enrolduedateheader', true);
        }

        if (!empty($course->relativedatesmode)) {
            $warning = $OUTPUT->notification(
                get_string('relativedatesmodewarning', 'local_enrolduedate'),
                \core\output\notification::NOTIFY_WARNING
            );
            $mform->addElement('static', 'enrolduedate_relativedateswarning', '', $warning);
        }

        if ($existingrule && $existingrule->status === rule_repository::RULE_STATUS_BROKEN_REF) {
            $alert = $OUTPUT->notification(
                get_string('brokenrefalert', 'local_enrolduedate'),
                \core\output\notification::NOTIFY_ERROR
            );
            $mform->addElement('static', 'enrolduedate_brokenrefalert', '', $alert);
        }

        if ($modname === 'assign' && $mform->elementExists('teamsubmission')) {
            $teamnotice = $OUTPUT->notification(
                get_string('teamsubmissionnotice', 'local_enrolduedate'),
                \core\output\notification::NOTIFY_INFO
            );
            $mform->addElement('static', 'enrolduedate_teamsubmissionnotice', '', $teamnotice);
            $mform->hideIf('enrolduedate_teamsubmissionnotice', 'teamsubmission', 'eq', 0);
        }

        $mform->addElement('advcheckbox', 'enrolduedate_enabled', get_string('enrolduedate_enabled', 'local_enrolduedate'));
        $mform->setType('enrolduedate_enabled', PARAM_INT);
        $mform->setDefault('enrolduedate_enabled', 0);
        $mform->addHelpButton('enrolduedate_enabled', 'enrolduedate_enabled', 'local_enrolduedate');

        if ($modname === 'assign' && $mform->elementExists('teamsubmission')) {
            $mform->disabledIf('enrolduedate_enabled', 'teamsubmission', 'eq', 1);
        }

        foreach (['allowsubmissionsfromdate', 'duedate', 'cutoffdate'] as $datefield) {
            if ($mform->elementExists($datefield)) {
                $mform->disabledIf($datefield, 'enrolduedate_enabled', 'checked');
            }
        }

        $triggeroptions = [
            rule_repository::TRIGGER_ENROLMENT => get_string('trigger_enrolment', 'local_enrolduedate'),
            rule_repository::TRIGGER_ACTIVITY_COMPLETED => get_string('trigger_activity_completed', 'local_enrolduedate'),
            rule_repository::TRIGGER_ACTIVITY_VIEWED => get_string('trigger_activity_viewed', 'local_enrolduedate'),
        ];
        $mform->addElement(
            'select',
            'enrolduedate_triggertype',
            get_string('enrolduedate_triggertype', 'local_enrolduedate'),
            $triggeroptions
        );
        $mform->setDefault('enrolduedate_triggertype', rule_repository::TRIGGER_ENROLMENT);
        $mform->addHelpButton('enrolduedate_triggertype', 'enrolduedate_triggertype', 'local_enrolduedate');
        $mform->hideIf('enrolduedate_triggertype', 'enrolduedate_enabled', 'notchecked');

        $refselect = $mform->createElement(
            'select',
            'enrolduedate_refcmid',
            get_string('enrolduedate_refcmid', 'local_enrolduedate')
        );
        $refselect->addOption(get_string('enrolduedate_refcmid_select', 'local_enrolduedate'), 0, [
            'data-has-completion' => '1',
            'data-has-view-completion' => '1',
        ]);

        $modinfo = get_fast_modinfo($course);
        foreach ($modinfo->get_cms() as $candidatecm) {
            if ($cmid > 0 && (int)$candidatecm->id === $cmid) {
                continue;
            }
            if (!empty($candidatecm->deletioninprogress)) {
                continue;
            }
            $hascompletion = ((int)$candidatecm->completion !== COMPLETION_TRACKING_NONE) ? '1' : '0';
            $hasview = ($hascompletion === '1' && !empty($candidatecm->completionview)) ? '1' : '0';
            $label = format_string($candidatecm->name, true, ['context' => $coursecontext])
                . ' (' . $candidatecm->modfullname . ')';
            $refselect->addOption($label, (int)$candidatecm->id, [
                'data-has-completion' => $hascompletion,
                'data-has-view-completion' => $hasview,
            ]);
        }
        $mform->addElement($refselect);
        $mform->setType('enrolduedate_refcmid', PARAM_INT);
        $mform->setDefault('enrolduedate_refcmid', 0);
        $mform->addHelpButton('enrolduedate_refcmid', 'enrolduedate_refcmid', 'local_enrolduedate');
        $mform->hideIf('enrolduedate_refcmid', 'enrolduedate_enabled', 'notchecked');
        $mform->hideIf('enrolduedate_refcmid', 'enrolduedate_triggertype', 'eq', rule_repository::TRIGGER_ENROLMENT);

        $mform->addElement(
            'advcheckbox',
            'enrolduedate_restrictuntiltriggered',
            get_string('enrolduedate_restrictuntiltriggered', 'local_enrolduedate')
        );
        $mform->setType('enrolduedate_restrictuntiltriggered', PARAM_INT);
        $mform->setDefault('enrolduedate_restrictuntiltriggered', 1);
        $mform->addHelpButton('enrolduedate_restrictuntiltriggered', 'enrolduedate_restrictuntiltriggered', 'local_enrolduedate');
        $mform->hideIf('enrolduedate_restrictuntiltriggered', 'enrolduedate_enabled', 'notchecked');
        $mform->hideIf('enrolduedate_restrictuntiltriggered', 'enrolduedate_triggertype', 'eq', rule_repository::TRIGGER_ENROLMENT);

        $unitoptions = [
            rule_repository::UNIT_HOURS => get_string('unit_hours', 'local_enrolduedate'),
            rule_repository::UNIT_DAYS => get_string('unit_days', 'local_enrolduedate'),
            rule_repository::UNIT_WEEKS => get_string('unit_weeks', 'local_enrolduedate'),
        ];

        // Allow submissions from offset group.
        $opengroup = [
            $mform->createElement('advcheckbox', 'enrolduedate_openenabled', '', get_string('enablelabel', 'local_enrolduedate')),
            $mform->createElement('text', 'enrolduedate_openvalue', '', ['size' => 4]),
            $mform->createElement('select', 'enrolduedate_openunit', '', $unitoptions),
        ];
        $mform->addGroup($opengroup, 'enrolduedate_open', get_string('enrolduedate_open', 'local_enrolduedate'), ' ', false);
        $mform->setType('enrolduedate_openenabled', PARAM_INT);
        $mform->setType('enrolduedate_openvalue', PARAM_INT);
        $mform->setType('enrolduedate_openunit', PARAM_ALPHA);
        $mform->setDefault('enrolduedate_openenabled', 0);
        $mform->setDefault('enrolduedate_openvalue', 0);
        $mform->setDefault('enrolduedate_openunit', rule_repository::UNIT_DAYS);
        $mform->addHelpButton('enrolduedate_open', 'enrolduedate_open', 'local_enrolduedate');
        $mform->disabledIf('enrolduedate_openvalue', 'enrolduedate_openenabled', 'notchecked');
        $mform->disabledIf('enrolduedate_openunit', 'enrolduedate_openenabled', 'notchecked');
        $mform->hideIf('enrolduedate_open', 'enrolduedate_enabled', 'notchecked');

        // Primary due date offset group.
        $duegroup = [
            $mform->createElement('text', 'enrolduedate_duevalue', '', ['size' => 4]),
            $mform->createElement('select', 'enrolduedate_dueunit', '', $unitoptions),
        ];
        $mform->addGroup($duegroup, 'enrolduedate_due', get_string('enrolduedate_due', 'local_enrolduedate'), ' ', false);
        $mform->setType('enrolduedate_duevalue', PARAM_INT);
        $mform->setType('enrolduedate_dueunit', PARAM_ALPHA);
        $mform->setDefault('enrolduedate_duevalue', 7);
        $mform->setDefault('enrolduedate_dueunit', rule_repository::UNIT_DAYS);
        $mform->addHelpButton('enrolduedate_due', 'enrolduedate_due', 'local_enrolduedate');
        $mform->hideIf('enrolduedate_due', 'enrolduedate_enabled', 'notchecked');

        // Cut-off offset group.
        $cutoffgroup = [
            $mform->createElement(
                'advcheckbox',
                'enrolduedate_cutoffenabled',
                '',
                get_string('enablelabel', 'local_enrolduedate')
            ),
            $mform->createElement('text', 'enrolduedate_cutoffvalue', '', ['size' => 4]),
            $mform->createElement('select', 'enrolduedate_cutoffunit', '', $unitoptions),
        ];
        $mform->addGroup($cutoffgroup, 'enrolduedate_cutoff', get_string('enrolduedate_cutoff', 'local_enrolduedate'), ' ', false);
        $mform->setType('enrolduedate_cutoffenabled', PARAM_INT);
        $mform->setType('enrolduedate_cutoffvalue', PARAM_INT);
        $mform->setType('enrolduedate_cutoffunit', PARAM_ALPHA);
        $mform->setDefault('enrolduedate_cutoffenabled', 0);
        $mform->setDefault('enrolduedate_cutoffvalue', 14);
        $mform->setDefault('enrolduedate_cutoffunit', rule_repository::UNIT_DAYS);
        $mform->addHelpButton('enrolduedate_cutoff', 'enrolduedate_cutoff', 'local_enrolduedate');
        $mform->disabledIf('enrolduedate_cutoffvalue', 'enrolduedate_cutoffenabled', 'notchecked');
        $mform->disabledIf('enrolduedate_cutoffunit', 'enrolduedate_cutoffenabled', 'notchecked');
        $mform->hideIf('enrolduedate_cutoff', 'enrolduedate_enabled', 'notchecked');

        // Snap mode selector.
        $defaultsnap = get_config('local_enrolduedate', 'defaultsnapmode');
        if (empty($defaultsnap)) {
            $defaultsnap = rule_repository::SNAP_ENDOFDAY;
        }
        $snapoptions = [
            rule_repository::SNAP_ENDOFDAY => get_string('snapmode_endofday', 'local_enrolduedate'),
            rule_repository::SNAP_EXACT => get_string('snapmode_exact', 'local_enrolduedate'),
        ];
        $mform->addElement(
            'select',
            'enrolduedate_snapmode',
            get_string('enrolduedate_snapmode', 'local_enrolduedate'),
            $snapoptions
        );
        $mform->setDefault('enrolduedate_snapmode', $defaultsnap);
        $mform->addHelpButton('enrolduedate_snapmode', 'enrolduedate_snapmode', 'local_enrolduedate');
        $mform->hideIf('enrolduedate_snapmode', 'enrolduedate_enabled', 'notchecked');

        // Moodle 5.x Grade Penalty support (hidden in core when duedate[enabled] is unchecked).
        if (
            $modname === 'assign' &&
            class_exists('\core_grades\penalty_manager') &&
            \core_grades\penalty_manager::is_penalty_enabled_for_module('assign')
        ) {
            $mform->addElement(
                'selectyesno',
                'enrolduedate_gradepenalty',
                get_string('enrolduedate_gradepenalty', 'local_enrolduedate')
            );
            $mform->setDefault('enrolduedate_gradepenalty', (int)($current->gradepenalty ?? 0));
            $mform->addHelpButton('enrolduedate_gradepenalty', 'enrolduedate_gradepenalty', 'local_enrolduedate');
            $mform->hideIf('enrolduedate_gradepenalty', 'enrolduedate_enabled', 'notchecked');
        }

        $PAGE->requires->js_call_amd('local_enrolduedate/modform', 'init');
    }

    /**
     * Populates saved rule values after course module data has been loaded into the form.
     *
     * @param moodleform_mod $formwrapper Wrapper activity form.
     * @param MoodleQuickForm $mform Underlying QuickForm instance.
     * @return void
     */
    public static function definition_after_data(moodleform_mod $formwrapper, MoodleQuickForm $mform): void {
        if ($mform->isSubmitted() || !$mform->elementExists('enrolduedate_enabled')) {
            return;
        }

        $current = $formwrapper->get_current();
        if (empty($current->coursemodule)) {
            return;
        }

        $rule = rule_repository::get_rule_by_cmid((int)$current->coursemodule);
        if (!$rule) {
            return;
        }

        $values = [
            'enrolduedate_enabled' => (int)$rule->enabled,
            'enrolduedate_triggertype' => $rule->triggertype,
            'enrolduedate_refcmid' => (int)($rule->refcmid ?? 0),
            'enrolduedate_restrictuntiltriggered' => (int)$rule->restrictuntiltriggered,
            'enrolduedate_snapmode' => $rule->snapmode,
        ];

        if ($rule->openoffsetvalue !== null && $rule->openoffsetunit !== null) {
            $values['enrolduedate_openenabled'] = 1;
            $values['enrolduedate_openvalue'] = (int)$rule->openoffsetvalue;
            $values['enrolduedate_openunit'] = $rule->openoffsetunit;
        } else {
            $values['enrolduedate_openenabled'] = 0;
        }

        if ($rule->dueoffsetvalue !== null && $rule->dueoffsetunit !== null) {
            $values['enrolduedate_duevalue'] = (int)$rule->dueoffsetvalue;
            $values['enrolduedate_dueunit'] = $rule->dueoffsetunit;
        }

        if ($rule->cutoffoffsetvalue !== null && $rule->cutoffoffsetunit !== null) {
            $values['enrolduedate_cutoffenabled'] = 1;
            $values['enrolduedate_cutoffvalue'] = (int)$rule->cutoffoffsetvalue;
            $values['enrolduedate_cutoffunit'] = $rule->cutoffoffsetunit;
        } else {
            $values['enrolduedate_cutoffenabled'] = 0;
        }

        if (property_exists($current, 'gradepenalty') && $mform->elementExists('enrolduedate_gradepenalty')) {
            $values['enrolduedate_gradepenalty'] = (int)$current->gradepenalty;
        }

        foreach ($values as $elementname => $value) {
            if ($mform->elementExists($elementname)) {
                $mform->getElement($elementname)->setValue($value);
            }
        }
    }

    /**
     * Validates submitted relative due date fields on a course module form.
     *
     * @param moodleform_mod $formwrapper Wrapper activity form.
     * @param array $data Submitted form values.
     * @return array<string, string> Field-keyed validation error messages.
     */
    public static function validate(moodleform_mod $formwrapper, array $data): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        if (empty($data['enrolduedate_enabled'])) {
            return [];
        }

        $current = $formwrapper->get_current();
        $course = $formwrapper->get_course();
        $modname = $current->modulename ?? ($data['modulename'] ?? '');
        $coursecontext = \context_course::instance((int)$course->id);

        if (!adapter_factory::is_supported($modname)) {
            return ['enrolduedate_enabled' => get_string('error_unsupportedmod', 'local_enrolduedate')];
        }
        if (!has_capability('local/enrolduedate:manage', $coursecontext)) {
            return [];
        }

        $errors = [];

        if ($modname === 'assign' && !empty($data['teamsubmission'])) {
            $errors['enrolduedate_enabled'] = get_string('error_teamsubmission', 'local_enrolduedate');
        }

        $dueval = (int)($data['enrolduedate_duevalue'] ?? 0);
        $dueunit = (string)($data['enrolduedate_dueunit'] ?? '');
        $duevalid = true;
        if (!calculator::is_valid_unit($dueunit)) {
            $errors['enrolduedate_due'] = get_string('error_invalidunit', 'local_enrolduedate');
            $duevalid = false;
        } else if ($dueval <= 0) {
            $errors['enrolduedate_due'] = get_string('error_dueoffsetrequired', 'local_enrolduedate');
            $duevalid = false;
        }

        $snapmode = (string)($data['enrolduedate_snapmode'] ?? rule_repository::SNAP_ENDOFDAY);

        if (!empty($data['enrolduedate_openenabled'])) {
            $openval = (int)($data['enrolduedate_openvalue'] ?? 0);
            $openunit = (string)($data['enrolduedate_openunit'] ?? '');
            if ($openval < 0) {
                $errors['enrolduedate_open'] = get_string('error_negativeoffset', 'local_enrolduedate');
            } else if (!calculator::is_valid_unit($openunit)) {
                $errors['enrolduedate_open'] = get_string('error_invalidunit', 'local_enrolduedate');
            } else if ($duevalid) {
                $openhours = calculator::to_equivalent_hours($openval, $openunit);
                $duehours = calculator::to_equivalent_hours($dueval, $dueunit);
                if ($snapmode === rule_repository::SNAP_EXACT) {
                    if ($openhours >= $duehours) {
                        $errors['enrolduedate_open'] = get_string('error_openafterdue', 'local_enrolduedate');
                    }
                } else {
                    if ($openunit === rule_repository::UNIT_HOURS) {
                        if ($openval > 0 && $openhours >= $duehours) {
                            $errors['enrolduedate_open'] = get_string('error_openafterdue', 'local_enrolduedate');
                        }
                    } else if ($openhours > $duehours) {
                        $errors['enrolduedate_open'] = get_string('error_openafterdue', 'local_enrolduedate');
                    }
                }
            }
        }

        if (!empty($data['enrolduedate_cutoffenabled'])) {
            $cutoffval = (int)($data['enrolduedate_cutoffvalue'] ?? 0);
            $cutoffunit = (string)($data['enrolduedate_cutoffunit'] ?? '');
            if ($cutoffval < 0) {
                $errors['enrolduedate_cutoff'] = get_string('error_negativeoffset', 'local_enrolduedate');
            } else if (!calculator::is_valid_unit($cutoffunit)) {
                $errors['enrolduedate_cutoff'] = get_string('error_invalidunit', 'local_enrolduedate');
            } else if ($duevalid) {
                $cutoffhours = calculator::to_equivalent_hours($cutoffval, $cutoffunit);
                $duehours = calculator::to_equivalent_hours($dueval, $dueunit);
                if ($cutoffhours < $duehours) {
                    $errors['enrolduedate_cutoff'] = get_string('error_cutoffbeforedue', 'local_enrolduedate');
                }
            }
        }

        $triggertype = (string)($data['enrolduedate_triggertype'] ?? rule_repository::TRIGGER_ENROLMENT);
        if (
            $triggertype === rule_repository::TRIGGER_ACTIVITY_COMPLETED ||
            $triggertype === rule_repository::TRIGGER_ACTIVITY_VIEWED
        ) {
            if (empty($course->enablecompletion)) {
                $errors['enrolduedate_triggertype'] = get_string('error_completionnotenabled', 'local_enrolduedate');
            } else {
                $cmid = !empty($current->coursemodule) ? (int)$current->coursemodule : (int)($data['coursemodule'] ?? 0);
                $refcmid = (int)($data['enrolduedate_refcmid'] ?? 0);
                if ($refcmid <= 0) {
                    $errors['enrolduedate_refcmid'] = get_string('error_refcmidrequired', 'local_enrolduedate');
                } else if ($cmid > 0 && $refcmid === $cmid) {
                    $errors['enrolduedate_refcmid'] = get_string('error_refcmidself', 'local_enrolduedate');
                } else {
                    $modinfo = get_fast_modinfo($course);
                    $cms = $modinfo->get_cms();
                    $refcm = $cms[$refcmid] ?? null;
                    if (!$refcm || !empty($refcm->deletioninprogress)) {
                        $errors['enrolduedate_refcmid'] = get_string('error_refcmidnotfound', 'local_enrolduedate');
                    } else if ((int)$refcm->completion === COMPLETION_TRACKING_NONE) {
                        $errors['enrolduedate_refcmid'] = get_string('error_refcmidnocompletion', 'local_enrolduedate');
                    } else if (
                        $triggertype === rule_repository::TRIGGER_ACTIVITY_VIEWED &&
                        empty($refcm->completionview)
                    ) {
                        $errors['enrolduedate_refcmid'] = get_string('error_refcmidnoviewcompletion', 'local_enrolduedate');
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Persists relative due date rules after core module creation/update and triggers sync.
     *
     * @param stdClass $moduleinfo Submitted module object from modlib.php.
     * @param stdClass $course Course record.
     * @return stdClass Modified $moduleinfo object.
     */
    public static function handle_edit_post_actions(stdClass $moduleinfo, stdClass $course): stdClass {
        global $USER;

        $modname = $moduleinfo->modulename ?? '';
        $cmid = (int)($moduleinfo->coursemodule ?? 0);
        if ($cmid <= 0 || !adapter_factory::is_supported($modname)) {
            return $moduleinfo;
        }

        if (!property_exists($moduleinfo, 'enrolduedate_enabled')) {
            return $moduleinfo;
        }

        $coursecontext = \context_course::instance((int)$course->id);
        if (!has_capability('local/enrolduedate:manage', $coursecontext)) {
            return $moduleinfo;
        }

        $existingrule = rule_repository::get_rule_by_cmid($cmid);

        if (empty($moduleinfo->enrolduedate_enabled)) {
            if ($existingrule && !empty($existingrule->enabled)) {
                sync_manager::disable_rule_and_cleanup($existingrule);
            }
            return $moduleinfo;
        }

        $triggertype = (string)($moduleinfo->enrolduedate_triggertype ?? rule_repository::TRIGGER_ENROLMENT);
        $isactivitytrigger = ($triggertype !== rule_repository::TRIGGER_ENROLMENT);
        $refcmid = $isactivitytrigger ? (int)($moduleinfo->enrolduedate_refcmid ?? 0) : null;
        if ($isactivitytrigger && $refcmid <= 0) {
            $refcmid = null;
        }

        $keepanchors = (
            $existingrule !== null &&
            $existingrule->triggertype === $triggertype &&
            (int)($existingrule->refcmid ?? 0) === (int)($refcmid ?? 0)
        );

        $openenabled = !empty($moduleinfo->enrolduedate_openenabled);
        $cutoffenabled = !empty($moduleinfo->enrolduedate_cutoffenabled);

        $ruledata = (object)[
            'courseid' => (int)$course->id,
            'cmid' => $cmid,
            'modname' => $modname,
            'enabled' => 1,
            'triggertype' => $triggertype,
            'refcmid' => $refcmid,
            'restrictuntiltriggered' => $isactivitytrigger ? (int)!empty($moduleinfo->enrolduedate_restrictuntiltriggered) : 0,
            'openoffsetvalue' => $openenabled ? (int)($moduleinfo->enrolduedate_openvalue ?? 0) : null,
            'openoffsetunit' => $openenabled ? (string)($moduleinfo->enrolduedate_openunit ?? rule_repository::UNIT_DAYS) : null,
            'dueoffsetvalue' => (int)($moduleinfo->enrolduedate_duevalue ?? 0),
            'dueoffsetunit' => (string)($moduleinfo->enrolduedate_dueunit ?? rule_repository::UNIT_DAYS),
            'cutoffoffsetvalue' => $cutoffenabled ? (int)($moduleinfo->enrolduedate_cutoffvalue ?? 0) : null,
            'cutoffoffsetunit' => $cutoffenabled
                ? (string)($moduleinfo->enrolduedate_cutoffunit ?? rule_repository::UNIT_DAYS)
                : null,
            'snapmode' => (string)($moduleinfo->enrolduedate_snapmode ?? rule_repository::SNAP_ENDOFDAY),
            'status' => rule_repository::RULE_STATUS_ACTIVE,
            'usermodified' => (int)($USER->id ?? 0),
        ];

        $rule = rule_repository::upsert_rule($ruledata);

        if (!empty($rule->restrictuntiltriggered) && $isactivitytrigger && !empty($rule->refcmid)) {
            availability_helper::ensure_completion_condition($cmid, (int)$rule->refcmid);
        }

        $cm = get_coursemodule_from_id($modname, $cmid, 0, false, MUST_EXIST);
        $adapter = adapter_factory::get_adapter($modname);
        $adapter->clear_base_dates($cm, $moduleinfo);

        $moduleinfo->allowsubmissionsfromdate = 0;
        $moduleinfo->duedate = 0;
        $moduleinfo->cutoffdate = 0;
        if (property_exists($moduleinfo, 'enrolduedate_gradepenalty')) {
            $moduleinfo->gradepenalty = (int)$moduleinfo->enrolduedate_gradepenalty;
        }

        // Purge CM cache after modlib.php line 410 already executed prior to post-actions hook.
        \course_modinfo::purge_course_module_cache((int)$course->id, $cmid);
        rebuild_course_cache((int)$course->id, true, true);

        sync_manager::dispatch_rule_sync($rule, $keepanchors, true);

        return $moduleinfo;
    }
}
