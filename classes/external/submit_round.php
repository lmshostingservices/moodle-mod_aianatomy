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

namespace mod_aianatomy\external;

use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_aianatomy\local\manager;

/**
 * Submits one test round of labels.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_round extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
                'round' => new external_value(PARAM_INT, 'Round index'),
                'placements' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'pin' => new external_value(PARAM_ALPHANUM, 'Pin token'),
                            'label' => new external_value(PARAM_ALPHANUM, 'Label token, empty if none', VALUE_DEFAULT, ''),
                        ]
                    ), 'Placements', VALUE_DEFAULT, []
                ),
            ]
        );
    }

    /**
     * Submits the round.
     *
     * @param int $attemptid
     * @param int $round
     * @param array $placements
     * @return array
     */
    public static function execute(int $attemptid, int $round, array $placements = []): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['attemptid' => $attemptid, 'round' => $round, 'placements' => $placements]
        );
        [$attempt, $instance] = self::load_attempt($params['attemptid']);
        return ['answered' => manager::submit_round($instance, $attempt, $params['round'], $params['placements'])];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['answered' => new external_value(PARAM_INT, 'Pins answered')]);
    }
}
