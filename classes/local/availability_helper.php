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

namespace local_enrolduedate\local;

/**
 * Helper to manage non-duplicative completion availability restrictions on target activities.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class availability_helper {
    /**
     * Ensures the target course module has a completion restriction matching the reference CM.
     *
     * Does not duplicate an existing completion condition for the same reference CM.
     *
     * @param int $targetcmid Target activity course module ID.
     * @param int $refcmid Reference prerequisite course module ID.
     * @return bool True if availability JSON was modified, false otherwise.
     */
    public static function ensure_completion_condition(int $targetcmid, int $refcmid): bool {
        global $CFG, $DB;

        if (empty($CFG->enableavailability) || $refcmid <= 0 || $targetcmid === $refcmid) {
            return false;
        }

        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $cm = $DB->get_record('course_modules', ['id' => $targetcmid], 'id, course, availability');
        if (!$cm) {
            return false;
        }

        $newcondition = [
            'type' => 'completion',
            'cm' => $refcmid,
            'e' => COMPLETION_COMPLETE,
        ];

        $tree = null;
        if (!empty($cm->availability)) {
            $decoded = json_decode($cm->availability, true);
            if (is_array($decoded) && isset($decoded['op'])) {
                if (self::tree_has_completion_condition($decoded, $refcmid)) {
                    return false;
                }
                if ($decoded['op'] === '&' && isset($decoded['c']) && is_array($decoded['c'])) {
                    $decoded['c'][] = $newcondition;
                    if (!isset($decoded['showc']) || !is_array($decoded['showc'])) {
                        $decoded['showc'] = array_fill(0, count($decoded['c']), true);
                    } else {
                        $decoded['showc'][] = true;
                    }
                    $tree = $decoded;
                } else {
                    $tree = [
                        'op' => '&',
                        'c' => [$decoded, $newcondition],
                        'showc' => [true, true],
                    ];
                }
            }
        }

        if ($tree === null) {
            $tree = [
                'op' => '&',
                'c' => [$newcondition],
                'showc' => [true],
            ];
        }

        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $targetcmid]);
        \course_modinfo::purge_course_module_cache((int)$cm->course, $targetcmid);
        rebuild_course_cache((int)$cm->course, true, true);

        return true;
    }

    /**
     * Recursively checks whether an availability JSON tree already restricts on a given refcmid.
     *
     * @param array $node Availability node or tree array.
     * @param int $refcmid Reference course module ID.
     * @return bool
     */
    private static function tree_has_completion_condition(array $node, int $refcmid): bool {
        if (($node['type'] ?? null) === 'completion' && (int)($node['cm'] ?? 0) === $refcmid) {
            return true;
        }
        if (!empty($node['c']) && is_array($node['c'])) {
            foreach ($node['c'] as $child) {
                if (is_array($child) && self::tree_has_completion_condition($child, $refcmid)) {
                    return true;
                }
            }
        }
        return false;
    }
}
