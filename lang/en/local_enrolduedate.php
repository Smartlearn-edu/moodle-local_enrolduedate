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
 * English language strings for local_enrolduedate.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action_edit'] = 'Edit settings';
$string['action_recalculate'] = 'Recalculate now';
$string['action_unlock'] = 'Unlock & reset to relative rule';
$string['brokenrefalert'] = 'Reference activity is missing or no longer supports required completion tracking.';
$string['col_actions'] = 'Actions';
$string['col_activity'] = 'Activity';
$string['col_anchor'] = 'Anchor date';
$string['col_cutoff'] = 'Cut-off date';
$string['col_due'] = 'Due date';
$string['col_offsets'] = 'Offsets';
$string['col_open'] = 'Allow submissions from';
$string['col_reference'] = 'Reference activity';
$string['col_rulestatus'] = 'Rule status';
$string['col_student'] = 'Student';
$string['col_studentstatus'] = 'Status';
$string['col_trigger'] = 'Trigger';
$string['coursemanagement_heading'] = 'Relative due dates';
$string['coursesettings_enrolanchormode'] = 'Course enrolment anchor policy';
$string['coursesettings_enrolanchormode_help'] = 'Determines which timestamp acts as day zero for enrolment-triggered rules '
    . 'in this course when a student has multiple enrolments or enrolled before the course start date.';
$string['coursesettings_heading'] = 'Course anchor settings';
$string['coursesettings_recalculateonupdate'] = 'Recalculate active enrolment-based rules immediately';
$string['coursesettings_saved'] = 'Course anchor settings saved.';
$string['enablelabel'] = 'Enable';
$string['enrolanchormode_earliest_floored'] = 'Earliest active enrolment, floored at course start date';
$string['enrolanchormode_earliest_raw'] = 'Earliest active enrolment (exact)';
$string['enrolanchormode_latest_raw'] = 'Most recent active enrolment';
$string['enrolduedate:manage'] = 'Configure relative due dates and unlock overrides';
$string['enrolduedate:viewreport'] = 'View relative due date status reports';
$string['enrolduedate_cutoff'] = 'Cut-off date offset';
$string['enrolduedate_cutoff_help'] = 'Optional relative duration after the anchor timestamp for the assignment cut-off date.';
$string['enrolduedate_due'] = 'Due date offset';
$string['enrolduedate_due_help'] = 'Relative duration after the student anchor timestamp when submissions are due.';
$string['enrolduedate_enabled'] = 'Enable relative due dates';
$string['enrolduedate_enabled_help'] = 'When enabled, standard fixed assignment dates are cleared and personalised '
    . 'per-student user overrides are calculated from each student anchor timestamp.';
$string['enrolduedate_gradepenalty'] = 'Apply late submission grade penalties';
$string['enrolduedate_gradepenalty_help'] = 'Controls Moodle grade penalties for late submissions when fixed assignment '
    . 'due dates are replaced by relative per-student overrides.';
$string['enrolduedate_open'] = 'Allow submissions from offset';
$string['enrolduedate_open_help'] = 'Optional relative delay after the anchor timestamp before the student can submit. '
    . 'A zero offset grants immediate access at the anchor timestamp.';
$string['enrolduedate_refcmid'] = 'Reference activity';
$string['enrolduedate_refcmid_help'] = 'Select the prerequisite activity whose completion or view unlocks this assignment.';
$string['enrolduedate_refcmid_select'] = 'Choose a reference activity...';
$string['enrolduedate_restrictuntiltriggered'] = 'Restrict access until reference activity trigger is met';
$string['enrolduedate_restrictuntiltriggered_help'] = 'Automatically manages a completion access restriction on this '
    . 'assignment so students cannot submit before triggering their personal anchor.';
$string['enrolduedate_snapmode'] = 'Time-of-day snapping';
$string['enrolduedate_snapmode_help'] = 'End-of-day snapping aligns day/week deadlines to 23:59 in each student\'s profile '
    . 'timezone (and positive open delays to 00:00). Exact mode keeps the exact time of day of the anchor.';
$string['enrolduedate_triggertype'] = 'Anchor trigger event';
$string['enrolduedate_triggertype_help'] = 'Choose what student event starts the relative due date clock for this assignment.';
$string['enrolduedateheader'] = 'Relative due dates';
$string['error_completionnotenabled'] = 'Completion tracking must be enabled at the course level for this trigger.';
$string['error_cutoffbeforedue'] = 'The cut-off date offset must be greater than or equal to the due date offset.';
$string['error_dueoffsetrequired'] = 'A positive due date offset is required when relative due dates are enabled.';
$string['error_invalidunit'] = 'Please select a valid duration unit.';
$string['error_negativeoffset'] = 'Offset values cannot be negative.';
$string['error_openafterdue'] = 'The open offset must be earlier than the due date offset.';
$string['error_refcmidnocompletion'] = 'The selected reference activity must have completion tracking enabled.';
$string['error_refcmidnotfound'] = 'The selected reference activity could not be found in this course.';
$string['error_refcmidnoviewcompletion'] = 'The selected reference activity must require "Students must view" completion.';
$string['error_refcmidrequired'] = 'Please select a reference activity for this trigger type.';
$string['error_refcmidself'] = 'An activity cannot reference itself as its anchor trigger.';
$string['error_teamsubmission'] = 'Relative per-student due dates cannot be enabled alongside team/group submissions.';
$string['error_unsupportedmod'] = 'Relative due dates are not yet supported for this activity module.';
$string['filter_all'] = 'All';
$string['filter_apply'] = 'Filter';
$string['filter_assignment'] = 'Assignment';
$string['filter_status'] = 'Status';
$string['messageprovider:duedateunlocked'] = 'Personalised due date unlocked notifications';
$string['missingactivity'] = 'Missing activity';
$string['nav_relativeduedates'] = 'Relative due dates';
$string['norulesconfigured'] = 'No assignments in this course currently use relative due dates.';
$string['nostudentrecords'] = 'No student relative due date records match the selected filters.';
$string['notification_cutoff_line'] = 'Cut-off date: {$a}';
$string['notification_due_line'] = 'Due date: {$a}';
$string['notification_open_line'] = 'Submissions open: {$a}';
$string['notification_unlocked_body'] = 'Hello {$a->firstname},' . "\n\n"
    . 'Your personalised schedule for "{$a->activityname}" in {$a->coursename} is now active:' . "\n"
    . '{$a->dates}' . "\n\n"
    . 'View assignment: {$a->url}';
$string['notification_unlocked_small'] = 'Due date unlocked for {$a->activityname}: {$a->duedate}';
$string['notification_unlocked_subject'] = '{$a->coursename}: Personalised due date set for {$a->activityname}';
$string['notset'] = '—';
$string['offset_cutoff_short'] = 'Cut-off: +{$a->value} {$a->unit}';
$string['offset_due_short'] = 'Due: +{$a->value} {$a->unit}';
$string['offset_open_short'] = 'Open: +{$a->value} {$a->unit}';
$string['pluginname'] = 'Relative due dates';
$string['privacy:metadata:local_enrolduedate_applied'] = 'Stores computed relative dates, anchor timestamps, override links, '
    . 'and lock states per student.';
$string['privacy:metadata:local_enrolduedate_applied:anchortime'] = 'Resolved anchor Unix timestamp for the student.';
$string['privacy:metadata:local_enrolduedate_applied:cmid'] = 'Target course module ID.';
$string['privacy:metadata:local_enrolduedate_applied:computedcutoff'] = 'Computed cut-off timestamp written to the override.';
$string['privacy:metadata:local_enrolduedate_applied:computeddue'] = 'Computed due date timestamp written to the override.';
$string['privacy:metadata:local_enrolduedate_applied:computedopen'] = 'Computed open date timestamp written to the override.';
$string['privacy:metadata:local_enrolduedate_applied:manualoverride'] = 'Whether the student override is locked by manual edits.';
$string['privacy:metadata:local_enrolduedate_applied:notified'] = 'Whether an unlock notification has been sent.';
$string['privacy:metadata:local_enrolduedate_applied:ruleid'] = 'Identifier of the associated relative due date rule.';
$string['privacy:metadata:local_enrolduedate_applied:status'] = 'Current sync state for the student.';
$string['privacy:metadata:local_enrolduedate_applied:timemodified'] = 'Timestamp when this tracking row was last updated.';
$string['privacy:metadata:local_enrolduedate_applied:userid'] = 'ID of the student the computed dates belong to.';
$string['privacy:metadata:local_enrolduedate_course'] = 'Stores course-level relative due date anchor settings.';
$string['privacy:metadata:local_enrolduedate_course:courseid'] = 'ID of the configured course.';
$string['privacy:metadata:local_enrolduedate_course:enrolanchormode'] = 'Selected course enrolment anchor resolution mode.';
$string['privacy:metadata:local_enrolduedate_course:timecreated'] = 'Timestamp when the course setting was created.';
$string['privacy:metadata:local_enrolduedate_course:timemodified'] = 'Timestamp when the course setting was last modified.';
$string['privacy:metadata:local_enrolduedate_course:usermodified'] = 'ID of the user who last modified the course settings.';
$string['privacy:metadata:local_enrolduedate_rule'] = 'Stores per-activity relative due date rules configured by teachers.';
$string['privacy:metadata:local_enrolduedate_rule:cmid'] = 'Course module ID of the target activity.';
$string['privacy:metadata:local_enrolduedate_rule:timecreated'] = 'Timestamp when the rule was created.';
$string['privacy:metadata:local_enrolduedate_rule:timemodified'] = 'Timestamp when the rule was last modified.';
$string['privacy:metadata:local_enrolduedate_rule:usermodified'] = 'ID of the user who last modified the rule.';
$string['privacy:path:coursesettings'] = 'Relative due dates course settings';
$string['privacy:path:rules'] = 'Relative due dates activity rules';
$string['privacy:path:studentdates'] = 'Relative due dates student records';
$string['recalculate_completed'] = 'Relative due dates recalculated ({$a} student records processed).';
$string['recalculate_queued'] = 'Recalculation has been queued as a background task because this course exceeds '
    . 'the inline sync threshold.';
$string['relativedatesmodewarning'] = 'Moodle core experimental "Relative dates mode" is enabled in this course. '
    . 'Using both relative systems simultaneously is discouraged.';
$string['rulestatus_active'] = 'Active';
$string['rulestatus_broken_ref'] = 'Broken reference';
$string['rulestatus_disabled'] = 'Disabled';
$string['setting_defaultenrolanchormode'] = 'Default course enrolment anchor';
$string['setting_defaultenrolanchormode_desc'] = 'Default strategy used to determine a student\'s enrolment anchor timestamp '
    . 'when a course has not configured its own setting.';
$string['setting_defaultsnapmode'] = 'Default time-of-day snapping';
$string['setting_defaultsnapmode_desc'] = 'Default snapping behaviour preselected when teachers enable relative due dates '
    . 'on a new assignment.';
$string['setting_enablenotifications'] = 'Send due date unlocked notifications';
$string['setting_enablenotifications_desc'] = 'Notify students via Moodle messaging when completing or viewing a prerequisite '
    . 'activity unlocks a personalised assignment due date.';
$string['setting_syncthreshold'] = 'Inline sync student threshold';
$string['setting_syncthreshold_desc'] = 'Maximum number of eligible students synced inline when saving an assignment rule. '
    . 'Larger courses automatically queue an adhoc background task.';
$string['snapmode_endofday'] = 'End of day (23:59 in student timezone)';
$string['snapmode_exact'] = 'Exact time from anchor';
$string['status_applied'] = 'Applied';
$string['status_manual_locked'] = 'Manual lock';
$string['status_skipped'] = 'Skipped';
$string['status_waiting'] = 'Waiting for trigger';
$string['studentstatus_heading'] = 'Student schedule status';
$string['task_reconcile_scheduled'] = 'Reconcile relative due dates and broken references';
$string['teamsubmissionnotice'] = 'Disable group/team submissions above if you wish to use per-student relative due dates.';
$string['trigger_activity_completed'] = 'Reference activity completed';
$string['trigger_activity_viewed'] = 'Reference activity viewed';
$string['trigger_enrolment'] = 'Course enrolment';
$string['unit_days'] = 'days';
$string['unit_hours'] = 'hours';
$string['unit_weeks'] = 'weeks';
$string['unlock_completed'] = 'Manual lock cleared and relative due date re-applied for {$a}.';
