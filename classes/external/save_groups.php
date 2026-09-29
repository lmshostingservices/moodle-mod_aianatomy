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
use mod_aianatomy\local\pack;

/**
 * Saves the activity's own group names and tips (e.g. after translation). Empty values use the pack's text.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_groups extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'groups' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'id' => new external_value(PARAM_ALPHANUMEXT, 'Group id'),
                            'label' => new external_value(PARAM_TEXT, 'Label', VALUE_DEFAULT, ''),
                            'tip' => new external_value(PARAM_TEXT, 'Tip', VALUE_DEFAULT, ''),
                        ]
                    )
                ),
            ]
        );
    }

    /**
     * Saves.
     *
     * @param int $cmid
     * @param array $groups
     * @return array
     */
    public static function execute(int $cmid, array $groups): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'groups' => $groups]);
        [$instance] = self::load_cm($params['cmid'], ['mod/aianatomy:manage']);
        $data = [];
        foreach ($params['groups'] as $g) {
            $data[$g['id']] = ['label' => $g['label'], 'tip' => $g['tip']];
        }
        $clean = manager::clean_grouptext(pack::get($instance->pack), $data);
        $DB->set_field(
            'aianatomy', 'grouptext', $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null,
            ['id' => $instance->id]
        );
        \mod_aianatomy\local\voice::queue($instance);
        return ['saved' => count($clean)];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['saved' => new external_value(PARAM_INT, 'Groups with own text')]);
    }
}
