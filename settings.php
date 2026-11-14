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
 * Admin settings for local_enrolduedate.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_enrolduedate', get_string('pluginname', 'local_enrolduedate'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $anchorchoices = [
            'earliest_floored' => get_string('enrolanchormode_earliest_floored', 'local_enrolduedate'),
            'earliest_raw' => get_string('enrolanchormode_earliest_raw', 'local_enrolduedate'),
            'latest_raw' => get_string('enrolanchormode_latest_raw', 'local_enrolduedate'),
        ];
        $settings->add(new admin_setting_configselect(
            'local_enrolduedate/defaultenrolanchormode',
            get_string('setting_defaultenrolanchormode', 'local_enrolduedate'),
            get_string('setting_defaultenrolanchormode_desc', 'local_enrolduedate'),
            'earliest_floored',
            $anchorchoices
        ));

        $snapchoices = [
            'endofday' => get_string('snapmode_endofday', 'local_enrolduedate'),
            'exact' => get_string('snapmode_exact', 'local_enrolduedate'),
        ];
        $settings->add(new admin_setting_configselect(
            'local_enrolduedate/defaultsnapmode',
            get_string('setting_defaultsnapmode', 'local_enrolduedate'),
            get_string('setting_defaultsnapmode_desc', 'local_enrolduedate'),
            'endofday',
            $snapchoices
        ));

        $settings->add(new admin_setting_configtext(
            'local_enrolduedate/syncthreshold',
            get_string('setting_syncthreshold', 'local_enrolduedate'),
            get_string('setting_syncthreshold_desc', 'local_enrolduedate'),
            '30',
            PARAM_INT
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_enrolduedate/enablenotifications',
            get_string('setting_enablenotifications', 'local_enrolduedate'),
            get_string('setting_enablenotifications_desc', 'local_enrolduedate'),
            1
        ));
    }
}
