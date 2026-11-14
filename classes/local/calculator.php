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

use DateTimeImmutable;
use stdClass;

/**
 * Timezone-aware relative date calculator and end-of-day snapping engine.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calculator {
    /** @var array<string, int> Conversion multipliers from duration unit to hours. */
    public const UNIT_HOURS_MAP = [
        rule_repository::UNIT_HOURS => 1,
        rule_repository::UNIT_DAYS => 24,
        rule_repository::UNIT_WEEKS => 168,
    ];

    /**
     * Converts a duration value and unit into equivalent hours for form ordering validation.
     *
     * @param int $value Offset integer value.
     * @param string $unit Unit string (hours, days, weeks).
     * @return int
     */
    public static function to_equivalent_hours(int $value, string $unit): int {
        $multiplier = self::UNIT_HOURS_MAP[$unit] ?? 1;
        return $value * $multiplier;
    }

    /**
     * Checks whether a duration unit is valid.
     *
     * @param string|null $unit Candidate unit.
     * @return bool
     */
    public static function is_valid_unit(?string $unit): bool {
        return $unit !== null && array_key_exists($unit, self::UNIT_HOURS_MAP);
    }

    /**
     * Computes open, due, and cut-off Unix timestamps from an anchor timestamp.
     *
     * @param int $anchortime Student anchor Unix timestamp.
     * @param stdClass $rule Relative due date rule record.
     * @param stdClass|null $user User object with timezone field (null falls back to server default).
     * @return stdClass Object with open (?int), due (int), and cutoff (?int) timestamps.
     */
    public static function compute_dates(int $anchortime, stdClass $rule, ?stdClass $user = null): stdClass {
        $tzuser = $user ?? (object)['timezone' => '99'];
        $tz = \core_date::get_user_timezone_object($tzuser);
        $anchordt = (new DateTimeImmutable('@' . $anchortime))->setTimezone($tz);
        $snapmode = $rule->snapmode ?? rule_repository::SNAP_ENDOFDAY;

        $computeddue = self::apply_offset(
            $anchordt,
            $anchortime,
            (int)$rule->dueoffsetvalue,
            (string)$rule->dueoffsetunit,
            $snapmode,
            'due'
        );

        $computedopen = null;
        if ($rule->openoffsetvalue !== null && $rule->openoffsetunit !== null) {
            $computedopen = self::apply_offset(
                $anchordt,
                $anchortime,
                (int)$rule->openoffsetvalue,
                (string)$rule->openoffsetunit,
                $snapmode,
                'open'
            );
            if ($computedopen > $computeddue) {
                $computedopen = $computeddue;
            }
        }

        $computedcutoff = null;
        if ($rule->cutoffoffsetvalue !== null && $rule->cutoffoffsetunit !== null) {
            $computedcutoff = self::apply_offset(
                $anchordt,
                $anchortime,
                (int)$rule->cutoffoffsetvalue,
                (string)$rule->cutoffoffsetunit,
                $snapmode,
                'cutoff'
            );
            if ($computedcutoff < $computeddue) {
                $computedcutoff = $computeddue;
            }
        }

        return (object)[
            'open' => $computedopen,
            'due' => $computeddue,
            'cutoff' => $computedcutoff,
        ];
    }

    /**
     * Applies one offset and snapping rule in the resolved timezone.
     *
     * @param DateTimeImmutable $anchordt Anchor datetime already set to student timezone.
     * @param int $anchortime Raw anchor Unix timestamp.
     * @param int $value Non-negative offset quantity.
     * @param string $unit Unit string (hours, days, weeks).
     * @param string $snapmode Snap mode (exact or endofday).
     * @param string $field Field role: open, due, or cutoff.
     * @return int Computed Unix timestamp.
     */
    private static function apply_offset(
        DateTimeImmutable $anchordt,
        int $anchortime,
        int $value,
        string $unit,
        string $snapmode,
        string $field
    ): int {
        if ($field === 'open' && $value === 0) {
            // Preserve immediate access instead of jumping to midnight/end-of-day.
            return $anchortime;
        }

        if ($value === 0 && $snapmode === rule_repository::SNAP_EXACT) {
            return $anchortime;
        }

        $targetdt = $anchordt->modify("+{$value} {$unit}");

        if ($snapmode === rule_repository::SNAP_EXACT || $unit === rule_repository::UNIT_HOURS) {
            return $targetdt->getTimestamp();
        }

        if ($field === 'open') {
            return $targetdt->setTime(0, 0, 0)->getTimestamp();
        }

        return $targetdt->setTime(23, 59, 0)->getTimestamp();
    }
}
