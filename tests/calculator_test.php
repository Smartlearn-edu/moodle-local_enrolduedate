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

namespace local_enrolduedate;

use advanced_testcase;
use DateTimeImmutable;
use DateTimeZone;
use local_enrolduedate\local\calculator;
use local_enrolduedate\local\rule_repository;

/**
 * Unit tests for timezone-aware relative date calculations and snapping.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_enrolduedate\local\calculator
 */
final class calculator_test extends advanced_testcase {
    /**
     * Tests exact timestamp calculations across hours, days, and weeks units.
     *
     * @return void
     */
    public function test_exact_mode_hours_days_weeks(): void {
        $this->resetAfterTest();

        $anchor = (new DateTimeImmutable('2027-02-01 10:15:30', new DateTimeZone('UTC')))->getTimestamp();
        $user = (object)['timezone' => 'UTC'];
        $rule = (object)[
            'openoffsetvalue' => 2,
            'openoffsetunit' => rule_repository::UNIT_HOURS,
            'dueoffsetvalue' => 3,
            'dueoffsetunit' => rule_repository::UNIT_DAYS,
            'cutoffoffsetvalue' => 1,
            'cutoffoffsetunit' => rule_repository::UNIT_WEEKS,
            'snapmode' => rule_repository::SNAP_EXACT,
        ];

        $dates = calculator::compute_dates($anchor, $rule, $user);

        $this->assertEquals($anchor + 2 * HOURSECS, $dates->open);
        $this->assertEquals($anchor + 3 * DAYSECS, $dates->due);
        $this->assertEquals($anchor + 7 * DAYSECS, $dates->cutoff);
    }

    /**
     * Tests end-of-day snapping in distinct student timezones and zero-offset open behavior.
     *
     * @return void
     */
    public function test_endofday_user_timezone_and_zero_open(): void {
        $this->resetAfterTest();

        // 2027-02-10 02:30:00 UTC is 2027-02-09 21:30:00 in America/New_York (EST, UTC-5),
        // and 2027-02-10 05:30:00 in Asia/Riyadh (UTC+3).
        $anchor = (new DateTimeImmutable('2027-02-10 02:30:00', new DateTimeZone('UTC')))->getTimestamp();

        $rule = (object)[
            'openoffsetvalue' => 0,
            'openoffsetunit' => rule_repository::UNIT_DAYS,
            'dueoffsetvalue' => 7,
            'dueoffsetunit' => rule_repository::UNIT_DAYS,
            'cutoffoffsetvalue' => 2,
            'cutoffoffsetunit' => rule_repository::UNIT_WEEKS,
            'snapmode' => rule_repository::SNAP_ENDOFDAY,
        ];

        $nyuser = (object)['timezone' => 'America/New_York'];
        $nydates = calculator::compute_dates($anchor, $rule, $nyuser);

        // Zero-offset open must keep immediate access at exact anchor time.
        $this->assertEquals($anchor, $nydates->open);
        $expectednydue = (new DateTimeImmutable('2027-02-16 23:59:00', new DateTimeZone('America/New_York')))->getTimestamp();
        $expectednycut = (new DateTimeImmutable('2027-02-23 23:59:00', new DateTimeZone('America/New_York')))->getTimestamp();
        $this->assertEquals($expectednydue, $nydates->due);
        $this->assertEquals($expectednycut, $nydates->cutoff);

        $riyadhuser = (object)['timezone' => 'Asia/Riyadh'];
        $riyadhdates = calculator::compute_dates($anchor, $rule, $riyadhuser);
        $expectedriyadhdue = (new DateTimeImmutable('2027-02-17 23:59:00', new DateTimeZone('Asia/Riyadh')))->getTimestamp();
        $this->assertEquals($expectedriyadhdue, $riyadhdates->due);

        // Positive day open delay in endofday mode snaps to 00:00:00 in student timezone.
        $rule->openoffsetvalue = 2;
        $nydelayed = calculator::compute_dates($anchor, $rule, $nyuser);
        $expectednyopen = (new DateTimeImmutable('2027-02-11 00:00:00', new DateTimeZone('America/New_York')))->getTimestamp();
        $this->assertEquals($expectednyopen, $nydelayed->open);
    }

    /**
     * Tests wall-clock preservation across Daylight Saving Time transitions.
     *
     * @return void
     */
    public function test_dst_transition_day_preservation(): void {
        $this->resetAfterTest();

        // 2026-03-08 02:00 is US DST spring-forward in America/New_York.
        $anchor = (new DateTimeImmutable('2026-03-07 10:00:00', new DateTimeZone('America/New_York')))->getTimestamp();
        $user = (object)['timezone' => 'America/New_York'];

        $exactrule = (object)[
            'openoffsetvalue' => null,
            'openoffsetunit' => null,
            'dueoffsetvalue' => 3,
            'dueoffsetunit' => rule_repository::UNIT_DAYS,
            'cutoffoffsetvalue' => null,
            'cutoffoffsetunit' => null,
            'snapmode' => rule_repository::SNAP_EXACT,
        ];
        $exactdates = calculator::compute_dates($anchor, $exactrule, $user);
        $expectedexact = (new DateTimeImmutable('2026-03-10 10:00:00', new DateTimeZone('America/New_York')))->getTimestamp();
        $this->assertEquals($expectedexact, $exactdates->due);

        $eodrule = clone $exactrule;
        $eodrule->snapmode = rule_repository::SNAP_ENDOFDAY;
        $eoddates = calculator::compute_dates($anchor, $eodrule, $user);
        $expectedeod = (new DateTimeImmutable('2026-03-10 23:59:00', new DateTimeZone('America/New_York')))->getTimestamp();
        $this->assertEquals($expectedeod, $eoddates->due);
    }

    /**
     * Tests ordering consistency clamp open <= due <= cutoff even on unaligned inputs.
     *
     * @return void
     */
    public function test_consistency_clamp(): void {
        $this->resetAfterTest();

        $anchor = (new DateTimeImmutable('2027-02-10 12:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $user = (object)['timezone' => 'UTC'];
        $rule = (object)[
            'openoffsetvalue' => 5,
            'openoffsetunit' => rule_repository::UNIT_DAYS,
            'dueoffsetvalue' => 2,
            'dueoffsetunit' => rule_repository::UNIT_DAYS,
            'cutoffoffsetvalue' => 1,
            'cutoffoffsetunit' => rule_repository::UNIT_DAYS,
            'snapmode' => rule_repository::SNAP_EXACT,
        ];

        $dates = calculator::compute_dates($anchor, $rule, $user);
        $this->assertLessThanOrEqual($dates->due, $dates->open);
        $this->assertGreaterThanOrEqual($dates->due, $dates->cutoff);
        $this->assertEquals($dates->due, $dates->open);
        $this->assertEquals($dates->due, $dates->cutoff);
    }
}
