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
 * Event observer definitions for local_enrolduedate.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback' => '\local_enrolduedate\observer\event_observer::user_enrolment_created',
    ],
    [
        'eventname' => '\core\event\role_assigned',
        'callback' => '\local_enrolduedate\observer\event_observer::role_assigned',
    ],
    [
        'eventname' => '\core\event\role_unassigned',
        'callback' => '\local_enrolduedate\observer\event_observer::role_unassigned',
    ],
    [
        'eventname' => '\core\event\user_enrolment_updated',
        'callback' => '\local_enrolduedate\observer\event_observer::user_enrolment_updated',
    ],
    [
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => '\local_enrolduedate\observer\event_observer::user_enrolment_deleted',
    ],
    [
        'eventname' => '\core\event\course_updated',
        'callback' => '\local_enrolduedate\observer\event_observer::course_updated',
    ],
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\local_enrolduedate\observer\event_observer::course_module_completion_updated',
    ],
    [
        'eventname' => '\mod_assign\event\user_override_updated',
        'callback' => '\local_enrolduedate\observer\event_observer::assign_override_updated',
    ],
    [
        'eventname' => '\mod_assign\event\user_override_deleted',
        'callback' => '\local_enrolduedate\observer\event_observer::assign_override_deleted',
    ],
    [
        'eventname' => '\core\event\course_reset_ended',
        'callback' => '\local_enrolduedate\observer\event_observer::course_reset_ended',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\local_enrolduedate\observer\event_observer::course_deleted',
    ],
];
