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
 * Backup definition for local_enrolduedate.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines backup elements for course settings and module rules/tracking.
 */
class backup_local_enrolduedate_plugin extends backup_local_plugin {
    /**
     * Defines backup structure attached to course elements.
     *
     * @return backup_plugin_element
     */
    protected function define_course_plugin_structure(): backup_plugin_element {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $coursesetting = new backup_nested_element('enrolduedate_course', ['id'], [
            'enrolanchormode',
            'usermodified',
            'timecreated',
            'timemodified',
        ]);
        $wrapper->add_child($coursesetting);

        $coursesetting->set_source_table('local_enrolduedate_course', ['courseid' => backup::VAR_COURSEID]);
        $coursesetting->annotate_ids('user', 'usermodified');

        return $plugin;
    }

    /**
     * Defines backup structure attached to course module elements.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure(): backup_plugin_element {
        $userinfo = $this->get_setting_value('userinfo');

        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $rule = new backup_nested_element('enrolduedate_rule', ['id'], [
            'modname',
            'enabled',
            'triggertype',
            'refcmid',
            'restrictuntiltriggered',
            'openoffsetvalue',
            'openoffsetunit',
            'dueoffsetvalue',
            'dueoffsetunit',
            'cutoffoffsetvalue',
            'cutoffoffsetunit',
            'snapmode',
            'status',
            'usermodified',
            'timecreated',
            'timemodified',
        ]);
        $wrapper->add_child($rule);

        $applieds = new backup_nested_element('enrolduedate_applieds');
        $applied = new backup_nested_element('enrolduedate_applied', ['id'], [
            'userid',
            'overrideid',
            'anchortime',
            'computedopen',
            'computeddue',
            'computedcutoff',
            'manualoverride',
            'notified',
            'status',
            'timemodified',
        ]);
        $rule->add_child($applieds);
        $applieds->add_child($applied);

        $rule->set_source_table('local_enrolduedate_rule', ['cmid' => backup::VAR_MODID]);
        $rule->annotate_ids('user', 'usermodified');

        if ($userinfo) {
            $applied->set_source_table('local_enrolduedate_applied', ['ruleid' => backup::VAR_PARENTID]);
            $applied->annotate_ids('user', 'userid');
        }

        return $plugin;
    }
}
