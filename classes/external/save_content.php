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
use core_external\external_single_structure;
use core_external\external_value;
use mod_aianatomy\local\manager;
use mod_aianatomy\local\pack;

/**
 * Saves, approves, discards or resets the teaching content of a structure.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_content extends base {
    /**
     * Content fields structure.
     *
     * @param int $required
     * @return external_single_structure
     */
    public static function content_structure(int $required = VALUE_REQUIRED): external_single_structure {
        $fields = [];
        foreach (pack::FIELDS as $f) {
            $fields[$f] = new external_value(PARAM_TEXT, $f, VALUE_DEFAULT, '');
        }
        return new external_single_structure($fields, 'Content', $required, $required === VALUE_DEFAULT ? [] : null);
    }

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'structureid' => new external_value(PARAM_ALPHANUMEXT, 'Structure id'),
                'action' => new external_value(PARAM_ALPHA, 'save, approve, discard or reset'),
                'content' => self::content_structure(VALUE_DEFAULT),
            ]
        );
    }

    /**
     * Saves.
     *
     * @param int $cmid
     * @param string $structureid
     * @param string $action
     * @param array $content
     * @return array
     */
    public static function execute(int $cmid, string $structureid, string $action, ?array $content = []): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'structureid' => $structureid, 'action' => $action, 'content' => $content]
        );
        [$instance] = self::load_cm($params['cmid'], ['mod/aianatomy:manage']);
        $result = manager::save_content(
            $instance, $params['structureid'], $params['action'], $params['content'] ?? [],
            (int)$USER->id
        );
        if ($result['draft'] === null) {
            unset($result['draft']);
        }
        return $result;
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'structureid' => new external_value(PARAM_ALPHANUMEXT, 'Structure id'),
                'content' => self::content_structure(),
                'status' => new external_value(PARAM_ALPHA, 'Status'),
                'aigenerated' => new external_value(PARAM_BOOL, 'AI generated'),
                'draft' => self::content_structure(VALUE_OPTIONAL),
                'label' => new external_value(PARAM_TEXT, 'Label shown to students', VALUE_OPTIONAL),
                'contentlang' => new external_value(PARAM_ALPHANUMEXT, 'Language of the content', VALUE_OPTIONAL),
            ]
        );
    }
}
