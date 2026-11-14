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

namespace local_enrolduedate\adapter;

use coding_exception;

/**
 * Factory resolving per-module override adapters.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adapter_factory {
    /** @var array<string, class-string<activity_adapter>> Registry mapping modname to adapter class. */
    private const ADAPTER_MAP = [
        'assign' => assign_adapter::class,
    ];

    /**
     * Checks whether a given module name is supported.
     *
     * @param string $modname Activity module name.
     * @return bool
     */
    public static function is_supported(string $modname): bool {
        return array_key_exists($modname, self::ADAPTER_MAP);
    }

    /**
     * Returns all supported activity module names.
     *
     * @return string[]
     */
    public static function get_supported_modnames(): array {
        return array_keys(self::ADAPTER_MAP);
    }

    /**
     * Instantiates the adapter for a supported activity module.
     *
     * @param string $modname Activity module name.
     * @return activity_adapter
     * @throws coding_exception If the module is not supported.
     */
    public static function get_adapter(string $modname): activity_adapter {
        if (!self::is_supported($modname)) {
            throw new coding_exception("Unsupported activity module for relative due dates: {$modname}");
        }
        $class = self::ADAPTER_MAP[$modname];
        return new $class();
    }
}
