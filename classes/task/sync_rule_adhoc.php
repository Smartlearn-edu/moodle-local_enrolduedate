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

namespace local_enrolduedate\task;

use local_enrolduedate\local\rule_repository;
use local_enrolduedate\local\sync_manager;

/**
 * Adhoc background task to synchronize relative due dates for a rule in large courses.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_rule_adhoc extends \core\task\adhoc_task {
    /**
     * Executes background synchronization for the rule specified in custom data.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (empty($data->ruleid)) {
            return;
        }

        $rule = rule_repository::get_rule((int)$data->ruleid);
        if (!$rule || empty($rule->enabled)) {
            return;
        }

        $keepexistinganchors = !isset($data->keepexistinganchors) || !empty($data->keepexistinganchors);
        $sendnotifications = !empty($data->sendnotifications);
        sync_manager::sync_rule_for_course($rule, $keepexistinganchors, $sendnotifications);
    }
}
