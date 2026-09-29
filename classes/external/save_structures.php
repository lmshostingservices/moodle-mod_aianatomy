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
 * Saves structure selection, label text, anchors and label positions (teacher editor).
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_structures extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'structures' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'structureid' => new external_value(PARAM_ALPHANUMEXT, 'Structure id'),
                            'enabled' => new external_value(PARAM_INT, '1 = used'),
                            'label' => new external_value(PARAM_TEXT, 'Label override', VALUE_DEFAULT, ''),
                            'anchor' => new external_value(PARAM_TEXT, 'x,y,z or empty', VALUE_DEFAULT, ''),
                            'labelpos' => new external_value(PARAM_TEXT, 'x,y or empty', VALUE_DEFAULT, ''),
                        ]
                    )
                ),
                'studytip' => new external_value(PARAM_TEXT, 'Study tip (null = unchanged)', VALUE_DEFAULT, null),
            ]
        );
    }

    /**
     * Saves.
     *
     * @param int $cmid
     * @param array $structures
     * @param string|null $studytip
     * @return array
     */
    public static function execute(int $cmid, array $structures, ?string $studytip = null): array {
        global $DB;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'structures' => $structures, 'studytip' => $studytip]
        );
        [$instance] = self::load_cm($params['cmid'], ['mod/aianatomy:manage']);
        $saved = manager::save_structures($instance, $params['structures']);
        if ($params['studytip'] !== null) {
            $DB->set_field('aianatomy', 'studytip', trim($params['studytip']), ['id' => $instance->id]);
        }
        \mod_aianatomy\local\voice::queue($instance);
        return ['saved' => $saved];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['saved' => new external_value(PARAM_INT, 'Structures saved')]);
    }
}
