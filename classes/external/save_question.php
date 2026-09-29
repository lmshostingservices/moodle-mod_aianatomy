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
 * Creates, updates, approves or deletes a knowledge question.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_question extends base {
    /**
     * Exported question structure.
     *
     * @return external_single_structure
     */
    public static function question_structure(): external_single_structure {
        return new external_single_structure(
            [
                'id' => new external_value(PARAM_INT, 'Question id'),
                'structureid' => new external_value(PARAM_ALPHANUMEXT, 'Structure id'),
                'kind' => new external_value(PARAM_ALPHA, 'Kind'),
                'text' => new external_value(PARAM_TEXT, 'Question'),
                'options' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Option')),
                'answer' => new external_value(PARAM_INT, 'Correct option index'),
                'explanation' => new external_value(PARAM_TEXT, 'Explanation'),
                'status' => new external_value(PARAM_ALPHA, 'library, draft or approved'),
                'aigenerated' => new external_value(PARAM_BOOL, 'AI generated'),
                'lang' => new external_value(PARAM_ALPHANUMEXT, 'Language of the question', VALUE_OPTIONAL),
            ]
        );
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
                'action' => new external_value(PARAM_ALPHA, 'save or delete'),
                'question' => new external_single_structure(
                    [
                        'id' => new external_value(PARAM_INT, 'Question id, 0 = new', VALUE_DEFAULT, 0),
                        'structureid' => new external_value(PARAM_ALPHANUMEXT, 'Structure id', VALUE_DEFAULT, ''),
                        'kind' => new external_value(PARAM_ALPHA, 'Kind', VALUE_DEFAULT, 'function'),
                        'text' => new external_value(PARAM_TEXT, 'Question', VALUE_DEFAULT, ''),
                        'options' => new external_multiple_structure(
                            new external_value(PARAM_TEXT, 'Option'), 'Options',
                            VALUE_DEFAULT, []
                        ),
                        'answer' => new external_value(PARAM_INT, 'Correct option index', VALUE_DEFAULT, 0),
                        'explanation' => new external_value(PARAM_TEXT, 'Explanation', VALUE_DEFAULT, ''),
                        'status' => new external_value(PARAM_ALPHA, 'draft or approved', VALUE_DEFAULT, 'approved'),
                    ]
                ),
            ]
        );
    }

    /**
     * Saves or deletes.
     *
     * @param int $cmid
     * @param string $action
     * @param array $question
     * @return array
     */
    public static function execute(int $cmid, string $action, array $question): array {
        global $DB;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'action' => $action, 'question' => $question]
        );
        [$instance] = self::load_cm($params['cmid'], ['mod/aianatomy:manage']);
        $q = $params['question'];
        if ($params['action'] === 'delete') {
            $DB->delete_records('aianatomy_question', ['id' => $q['id'], 'aianatomyid' => $instance->id]);
            return ['deleted' => true];
        }
        $saved = manager::save_question($instance, $q);
        \mod_aianatomy\local\voice::queue($instance);
        return ['deleted' => false, 'question' => $saved];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $q = self::question_structure();
        $q->required = VALUE_OPTIONAL;
        return new external_single_structure(
            [
                'deleted' => new external_value(PARAM_BOOL, 'Deleted'),
                'question' => $q,
            ]
        );
    }
}
