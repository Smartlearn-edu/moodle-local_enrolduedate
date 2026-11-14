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

namespace local_enrolduedate\output;

use local_enrolduedate\local\rule_repository;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Renderable data model for the course relative due dates management and monitoring page.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_overview implements renderable, templatable {
    /** @var stdClass Course record. */
    private stdClass $course;

    /** @var stdClass[] Rules belonging to this course. */
    private array $rules;

    /** @var stdClass[] Paginated applied student rows. */
    private array $appliedrows;

    /** @var string Rendered course settings form HTML or empty string. */
    private string $coursesettingshtml;

    /** @var string Rendered paging bar HTML. */
    private string $pagingbarhtml;

    /** @var bool Whether current user can manage rules/locks. */
    private bool $canmanage;

    /** @var int Active rule ID filter (0 = all). */
    private int $filterruleid;

    /** @var string Active status filter ('' = all). */
    private string $filterstatus;

    /**
     * Constructs the course overview renderable.
     *
     * @param stdClass $course Course record.
     * @param stdClass[] $rules Course rules list.
     * @param stdClass[] $appliedrows Paginated joined student applied rows.
     * @param string $coursesettingshtml Course settings form HTML.
     * @param string $pagingbarhtml Rendered paging bar HTML.
     * @param bool $canmanage Whether user holds manage capability.
     * @param int $filterruleid Selected rule filter ID.
     * @param string $filterstatus Selected status filter key.
     */
    public function __construct(
        stdClass $course,
        array $rules,
        array $appliedrows,
        string $coursesettingshtml,
        string $pagingbarhtml,
        bool $canmanage,
        int $filterruleid,
        string $filterstatus
    ) {
        $this->course = $course;
        $this->rules = $rules;
        $this->appliedrows = $appliedrows;
        $this->coursesettingshtml = $coursesettingshtml;
        $this->pagingbarhtml = $pagingbarhtml;
        $this->canmanage = $canmanage;
        $this->filterruleid = $filterruleid;
        $this->filterstatus = $filterstatus;
    }

    /**
     * Exports structured context for templates/course_overview.mustache.
     *
     * @param renderer_base $output Renderer instance.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $modinfo = get_fast_modinfo($this->course);
        $cms = $modinfo->get_cms();
        $coursecontext = \context_course::instance((int)$this->course->id);

        $ruleactivitynames = [];
        $formattedrules = [];
        foreach ($this->rules as $rule) {
            $cm = $cms[(int)$rule->cmid] ?? null;
            $activityname = $cm
                ? format_string($cm->name, true, ['context' => $coursecontext])
                : get_string('missingactivity', 'local_enrolduedate');
            $ruleactivitynames[(int)$rule->id] = $activityname;

            $refname = get_string('notset', 'local_enrolduedate');
            $hasref = false;
            if (!empty($rule->refcmid)) {
                $refcm = $cms[(int)$rule->refcmid] ?? null;
                $refname = $refcm
                    ? format_string($refcm->name, true, ['context' => $coursecontext])
                    : get_string('missingactivity', 'local_enrolduedate');
                $hasref = true;
            }

            $offsets = [];
            if ($rule->openoffsetvalue !== null && $rule->openoffsetunit !== null) {
                $offsets[] = get_string('offset_open_short', 'local_enrolduedate', (object)[
                    'value' => (int)$rule->openoffsetvalue,
                    'unit' => get_string('unit_' . $rule->openoffsetunit, 'local_enrolduedate'),
                ]);
            }
            if ($rule->dueoffsetvalue !== null && $rule->dueoffsetunit !== null) {
                $offsets[] = get_string('offset_due_short', 'local_enrolduedate', (object)[
                    'value' => (int)$rule->dueoffsetvalue,
                    'unit' => get_string('unit_' . $rule->dueoffsetunit, 'local_enrolduedate'),
                ]);
            }
            if ($rule->cutoffoffsetvalue !== null && $rule->cutoffoffsetunit !== null) {
                $offsets[] = get_string('offset_cutoff_short', 'local_enrolduedate', (object)[
                    'value' => (int)$rule->cutoffoffsetvalue,
                    'unit' => get_string('unit_' . $rule->cutoffoffsetunit, 'local_enrolduedate'),
                ]);
            }

            $isbrokenref = ($rule->status === rule_repository::RULE_STATUS_BROKEN_REF);
            if ($isbrokenref) {
                $statuslabel = get_string('rulestatus_broken_ref', 'local_enrolduedate');
                $badgeclass = 'badge bg-danger badge-danger text-white';
            } else if (!empty($rule->enabled)) {
                $statuslabel = get_string('rulestatus_active', 'local_enrolduedate');
                $badgeclass = 'badge bg-success badge-success text-white';
            } else {
                $statuslabel = get_string('rulestatus_disabled', 'local_enrolduedate');
                $badgeclass = 'badge bg-secondary badge-secondary text-white';
            }

            $editurl = (new moodle_url('/course/modedit.php', ['update' => (int)$rule->cmid]))->out(false);
            $recalcurl = (new moodle_url('/local/enrolduedate/index.php', [
                'courseid' => (int)$this->course->id,
                'action' => 'recalculate',
                'ruleid' => (int)$rule->id,
                'sesskey' => sesskey(),
            ]))->out(false);

            $formattedrules[] = [
                'id' => (int)$rule->id,
                'activityname' => $activityname,
                'triggertypelabel' => get_string('trigger_' . $rule->triggertype, 'local_enrolduedate'),
                'referencename' => $refname,
                'hasreference' => $hasref,
                'offsetslabel' => implode(' | ', $offsets),
                'statuslabel' => $statuslabel,
                'statusbadgeclass' => $badgeclass,
                'isbrokenref' => $isbrokenref,
                'editurl' => $editurl,
                'recalculateurl' => $recalcurl,
                'canrecalculate' => ($this->canmanage && !empty($rule->enabled) && !$isbrokenref),
            ];
        }

        $rulefilters = [[
            'id' => 0,
            'label' => get_string('filter_all', 'local_enrolduedate'),
            'selected' => ($this->filterruleid === 0),
        ]];
        foreach ($ruleactivitynames as $rid => $name) {
            $rulefilters[] = [
                'id' => $rid,
                'label' => $name,
                'selected' => ($this->filterruleid === $rid),
            ];
        }

        $statuses = [
            rule_repository::APPLIED_STATUS_APPLIED,
            rule_repository::APPLIED_STATUS_WAITING,
            rule_repository::APPLIED_STATUS_MANUAL_LOCKED,
            rule_repository::APPLIED_STATUS_SKIPPED,
        ];
        $statusfilters = [[
            'value' => '',
            'label' => get_string('filter_all', 'local_enrolduedate'),
            'selected' => ($this->filterstatus === ''),
        ]];
        foreach ($statuses as $statuskey) {
            $statusfilters[] = [
                'value' => $statuskey,
                'label' => get_string('status_' . $statuskey, 'local_enrolduedate'),
                'selected' => ($this->filterstatus === $statuskey),
            ];
        }

        $notset = get_string('notset', 'local_enrolduedate');
        $formattedrecords = [];
        foreach ($this->appliedrows as $row) {
            $unlockurl = (new moodle_url('/local/enrolduedate/index.php', [
                'courseid' => (int)$this->course->id,
                'action' => 'unlock',
                'appliedid' => (int)$row->id,
                'ruleid' => $this->filterruleid,
                'status' => $this->filterstatus,
                'sesskey' => sesskey(),
            ]))->out(false);

            $statusbadgeclass = match ($row->status) {
                rule_repository::APPLIED_STATUS_APPLIED => 'badge bg-success badge-success text-white',
                rule_repository::APPLIED_STATUS_MANUAL_LOCKED => 'badge bg-warning badge-warning text-dark',
                rule_repository::APPLIED_STATUS_WAITING => 'badge bg-info badge-info text-white',
                default => 'badge bg-secondary badge-secondary text-white',
            };

            $tz = $row->timezone ?? '99';
            $formattedrecords[] = [
                'studentname' => fullname($row),
                'activityname' => $ruleactivitynames[(int)$row->ruleid] ?? get_string('missingactivity', 'local_enrolduedate'),
                'anchordate' => !empty($row->anchortime) ? userdate((int)$row->anchortime, '', $tz) : $notset,
                'opendate' => !empty($row->computedopen) ? userdate((int)$row->computedopen, '', $tz) : $notset,
                'duedate' => !empty($row->computeddue) ? userdate((int)$row->computeddue, '', $tz) : $notset,
                'cutoffdate' => !empty($row->computedcutoff) ? userdate((int)$row->computedcutoff, '', $tz) : $notset,
                'statuslabel' => get_string('status_' . $row->status, 'local_enrolduedate'),
                'statusbadgeclass' => $statusbadgeclass,
                'canunlock' => ($this->canmanage && !empty($row->manualoverride)),
                'unlockurl' => $unlockurl,
            ];
        }

        return [
            'courseid' => (int)$this->course->id,
            'canmanage' => $this->canmanage,
            'coursesettingsform' => $this->coursesettingshtml,
            'hasrules' => !empty($formattedrules),
            'rules' => $formattedrules,
            'filteractionurl' => (new moodle_url('/local/enrolduedate/index.php'))->out(false),
            'rulefilters' => $rulefilters,
            'statusfilters' => $statusfilters,
            'hasrecords' => !empty($formattedrecords),
            'records' => $formattedrecords,
            'pagingbar' => $this->pagingbarhtml,
        ];
    }
}
