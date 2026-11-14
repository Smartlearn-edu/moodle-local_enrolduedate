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
 * Course management and student schedule report page for local_enrolduedate.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

use local_enrolduedate\form\course_settings_form;
use local_enrolduedate\local\rule_repository;
use local_enrolduedate\local\sync_manager;
use local_enrolduedate\output\course_overview;

$courseid = required_param('courseid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$filterruleid = optional_param('ruleid', 0, PARAM_INT);
$filterstatus = optional_param('status', '', PARAM_ALPHANUMEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 25;

$course = get_course($courseid);
require_login($course);

$context = context_course::instance((int)$course->id);
$canmanage = has_capability('local/enrolduedate:manage', $context);
$canview = has_capability('local/enrolduedate:viewreport', $context) || $canmanage;
if (!$canview) {
    require_capability('local/enrolduedate:viewreport', $context);
}

$allowedstatuses = [
    rule_repository::APPLIED_STATUS_APPLIED,
    rule_repository::APPLIED_STATUS_WAITING,
    rule_repository::APPLIED_STATUS_MANUAL_LOCKED,
    rule_repository::APPLIED_STATUS_SKIPPED,
];
if ($filterstatus !== '' && !in_array($filterstatus, $allowedstatuses, true)) {
    $filterstatus = '';
}

$pageurl = new moodle_url('/local/enrolduedate/index.php', [
    'courseid' => (int)$course->id,
    'ruleid' => $filterruleid,
    'status' => $filterstatus,
    'page' => $page,
]);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('coursemanagement_heading', 'local_enrolduedate'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));

// Handle explicit GET actions initiated from action buttons.
if ($action === 'recalculate' && $canmanage) {
    require_sesskey();
    $ruleid = required_param('ruleid', PARAM_INT);
    $rule = rule_repository::get_rule($ruleid);
    if ($rule && (int)$rule->courseid === (int)$course->id) {
        $inlined = sync_manager::dispatch_rule_sync($rule, true, false);
        $message = $inlined
            ? get_string('recalculate_completed', 'local_enrolduedate', '')
            : get_string('recalculate_queued', 'local_enrolduedate');
        $message = trim($message);
        if ($inlined) {
            $count = $DB->count_records('local_enrolduedate_applied', ['ruleid' => (int)$rule->id]);
            $message = get_string('recalculate_completed', 'local_enrolduedate', $count);
        }
        redirect(
            new moodle_url('/local/enrolduedate/index.php', ['courseid' => (int)$course->id]),
            $message,
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
} else if ($action === 'unlock' && $canmanage) {
    require_sesskey();
    $appliedid = required_param('appliedid', PARAM_INT);
    $applied = $DB->get_record('local_enrolduedate_applied', ['id' => $appliedid, 'courseid' => (int)$course->id]);
    if ($applied && sync_manager::unlock_manual_override($appliedid)) {
        $targetuser = core_user::get_user((int)$applied->userid);
        $displayname = $targetuser ? fullname($targetuser) : (string)$applied->userid;
        redirect(
            new moodle_url('/local/enrolduedate/index.php', [
                'courseid' => (int)$course->id,
                'ruleid' => $filterruleid,
                'status' => $filterstatus,
            ]),
            get_string('unlock_completed', 'local_enrolduedate', $displayname),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

$coursesettingshtml = '';
if ($canmanage) {
    $form = new course_settings_form(new moodle_url('/local/enrolduedate/index.php', ['courseid' => (int)$course->id]));
    $form->set_data((object)[
        'courseid' => (int)$course->id,
        'enrolanchormode' => rule_repository::get_effective_enrol_anchor_mode((int)$course->id),
        'recalculateonupdate' => 1,
    ]);

    if ($data = $form->get_data()) {
        rule_repository::upsert_course_settings((int)$course->id, (string)$data->enrolanchormode, (int)$USER->id);
        if (!empty($data->recalculateonupdate)) {
            $enrolrules = rule_repository::get_active_rules_by_trigger(
                (int)$course->id,
                rule_repository::TRIGGER_ENROLMENT
            );
            foreach ($enrolrules as $enrolrule) {
                sync_manager::dispatch_rule_sync($enrolrule, true, false);
            }
        }
        redirect(
            new moodle_url('/local/enrolduedate/index.php', ['courseid' => (int)$course->id]),
            get_string('coursesettings_saved', 'local_enrolduedate'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $coursesettingshtml = $form->render();
}

$rules = rule_repository::get_rules_for_course((int)$course->id, false);

// Query paginated student schedule tracking records.
$userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false);
$where = ['a.courseid = :courseid'];
$params = ['courseid' => (int)$course->id];

if ($filterruleid > 0) {
    $where[] = 'a.ruleid = :ruleid';
    $params['ruleid'] = $filterruleid;
}
if ($filterstatus !== '') {
    $where[] = 'a.status = :status';
    $params['status'] = $filterstatus;
}

$wheresql = implode(' AND ', $where);
$countsql = "SELECT COUNT(1)
               FROM {local_enrolduedate_applied} a
               JOIN {user} u ON u.id = a.userid
              WHERE {$wheresql}
                AND u.deleted = 0";
$totalcount = $DB->count_records_sql($countsql, $params);

$rowsql = "SELECT a.*, u.timezone, {$userfields->selects}
             FROM {local_enrolduedate_applied} a
             JOIN {user} u ON u.id = a.userid
            WHERE {$wheresql}
              AND u.deleted = 0
         ORDER BY u.lastname ASC, u.firstname ASC, a.cmid ASC";
$appliedrows = $DB->get_records_sql($rowsql, $params, $page * $perpage, $perpage);

$pagingurl = new moodle_url('/local/enrolduedate/index.php', [
    'courseid' => (int)$course->id,
    'ruleid' => $filterruleid,
    'status' => $filterstatus,
]);
$pagingbarhtml = $OUTPUT->paging_bar($totalcount, $page, $perpage, $pagingurl);

$overview = new course_overview(
    $course,
    $rules,
    $appliedrows,
    $coursesettingshtml,
    $pagingbarhtml,
    $canmanage,
    $filterruleid,
    $filterstatus
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursemanagement_heading', 'local_enrolduedate'));
echo $OUTPUT->render($overview);
echo $OUTPUT->footer();
