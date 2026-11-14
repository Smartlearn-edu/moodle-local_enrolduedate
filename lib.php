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
 * Core callback hooks for local_enrolduedate.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds Relative due dates controls to supported activity forms.
 *
 * @param moodleform_mod $formwrapper Activity module form wrapper.
 * @param MoodleQuickForm $mform QuickForm instance.
 * @return void
 */
function local_enrolduedate_coursemodule_standard_elements($formwrapper, $mform): void {
    \local_enrolduedate\form\mod_form_helper::add_standard_elements($formwrapper, $mform);
}

/**
 * Populates saved relative due date values when editing an existing activity.
 *
 * @param moodleform_mod $formwrapper Activity module form wrapper.
 * @param MoodleQuickForm $mform QuickForm instance.
 * @return void
 */
function local_enrolduedate_coursemodule_definition_after_data($formwrapper, $mform): void {
    \local_enrolduedate\form\mod_form_helper::definition_after_data($formwrapper, $mform);
}

/**
 * Validates relative due date settings submitted on course module forms.
 *
 * @param moodleform_mod $formwrapper Activity module form wrapper.
 * @param array $data Submitted form data.
 * @return array<string, string>
 */
function local_enrolduedate_coursemodule_validation($formwrapper, $data): array {
    return \local_enrolduedate\form\mod_form_helper::validate($formwrapper, $data);
}

/**
 * Saves rules, clears fixed activity dates, and synchronizes overrides after activity save.
 *
 * @param stdClass $moduleinfo Saved course module data object.
 * @param stdClass $course Course record.
 * @return stdClass Updated $moduleinfo object.
 */
function local_enrolduedate_coursemodule_edit_post_actions($moduleinfo, $course): stdClass {
    return \local_enrolduedate\form\mod_form_helper::handle_edit_post_actions($moduleinfo, $course);
}

/**
 * Cleans up relative due date rules or marks dependent rules broken before CM deletion.
 *
 * @param stdClass $cm Course module record about to be deleted.
 * @return void
 */
function local_enrolduedate_pre_course_module_delete($cm): void {
    if (empty($cm->id)) {
        return;
    }

    $cmid = (int)$cm->id;
    $ownrule = \local_enrolduedate\local\rule_repository::get_rule_by_cmid($cmid);
    if ($ownrule) {
        \local_enrolduedate\local\rule_repository::delete_rule_and_applied((int)$ownrule->id);
    }

    $dependentrules = \local_enrolduedate\local\rule_repository::get_rules_by_refcmid($cmid, false);
    foreach ($dependentrules as $deprule) {
        if (!empty($deprule->enabled)) {
            \local_enrolduedate\local\sync_manager::disable_rule_and_cleanup($deprule);
        }
        \local_enrolduedate\local\rule_repository::mark_rule_broken((int)$deprule->id);
    }
}

/**
 * Adds the Relative due dates course management link to course navigation.
 *
 * @param navigation_node $navigation Course navigation node.
 * @param stdClass $course Course record.
 * @param context_course $context Course context.
 * @return void
 */
function local_enrolduedate_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    if (
        !has_capability('local/enrolduedate:viewreport', $context) &&
        !has_capability('local/enrolduedate:manage', $context)
    ) {
        return;
    }

    $url = new moodle_url('/local/enrolduedate/index.php', ['courseid' => (int)$course->id]);
    $navigation->add(
        get_string('nav_relativeduedates', 'local_enrolduedate'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_enrolduedate',
        new pix_icon('i/calendar', '')
    );
}
