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

use local_enrolduedate\local\rule_repository;

/**
 * Course-level enrolment anchor policy configuration form.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_settings_form extends \moodleform {
    /**
     * Defines the course enrolment anchor form fields.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'coursesettingsheader', get_string('coursesettings_heading', 'local_enrolduedate'));

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        $choices = [
            rule_repository::ANCHOR_EARLIEST_FLOORED => get_string('enrolanchormode_earliest_floored', 'local_enrolduedate'),
            rule_repository::ANCHOR_EARLIEST_RAW => get_string('enrolanchormode_earliest_raw', 'local_enrolduedate'),
            rule_repository::ANCHOR_LATEST_RAW => get_string('enrolanchormode_latest_raw', 'local_enrolduedate'),
        ];
        $mform->addElement(
            'select',
            'enrolanchormode',
            get_string('coursesettings_enrolanchormode', 'local_enrolduedate'),
            $choices
        );
        $mform->addHelpButton('enrolanchormode', 'coursesettings_enrolanchormode', 'local_enrolduedate');

        $mform->addElement(
            'advcheckbox',
            'recalculateonupdate',
            get_string('coursesettings_recalculateonupdate', 'local_enrolduedate')
        );
        $mform->setType('recalculateonupdate', PARAM_INT);
        $mform->setDefault('recalculateonupdate', 1);

        $this->add_action_buttons(false);
    }
}
