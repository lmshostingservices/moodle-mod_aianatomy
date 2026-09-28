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
use mod_aianatomy\local\ai\generator;

/**
 * Translates one structure's content, name and questions (or the pack's group names and tips) into the
 * activity language with LMS Labs AI. Structure translations are drafts for teacher approval.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class translate extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'what' => new external_value(PARAM_ALPHA, 'structure or groups'),
                'structureid' => new external_value(PARAM_ALPHANUMEXT, 'Structure id (structure only)', VALUE_DEFAULT, ''),
            ]
        );
    }

    /**
     * Translates.
     *
     * @param int $cmid
     * @param string $what
     * @param string $structureid
     * @return array
     */
    public static function execute(int $cmid, string $what, string $structureid = ''): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'what' => $what, 'structureid' => $structureid]
        );
        [$instance, , , $context] = self::load_cm($params['cmid'], ['mod/aianatomy:manage', 'mod/aianatomy:useai']);
        \core_php_time_limit::raise(180);
        // Release the session lock before calling LMS Labs, so the student's or teacher's other requests
        // (saving answers, finishing an attempt) are not blocked while audio or text is generated.
        \core\session\manager::write_close();
        $result = ['structureid' => $params['structureid'], 'name' => '', 'questions' => [], 'groups' => [],
            'pending' => false, 'retryafter' => 0, 'credits' => '', 'balance' => ''];
        // A translation already in progress is resumed (same Idempotency-Key and body).
        if ($params['what'] === 'structure') {
            $t = generator::translate_structure($instance, $context, $params['structureid'], (int)$USER->id);
            if (!$t['pending']) {
                $result['draft'] = $t['content'];
                $result['name'] = $t['name'];
                $result['questions'] = $t['questions'];
            }
        } else if ($params['what'] === 'groups') {
            $t = generator::translate_groups($instance, $context, (int)$USER->id);
            foreach ($t['groups'] ?? [] as $id => $g) {
                $result['groups'][] = ['id' => $id] + $g;
            }
        } else {
            throw new \moodle_exception('invalidaction', 'mod_aianatomy');
        }
        foreach (['pending', 'retryafter', 'credits', 'balance'] as $k) {
            if (isset($t[$k])) {
                $result[$k] = $t[$k];
            }
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
                'draft' => save_content::content_structure(VALUE_OPTIONAL),
                'name' => new external_value(PARAM_TEXT, 'Translated name (draft)'),
                'questions' => new external_multiple_structure(save_question::question_structure()),
                'groups' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'id' => new external_value(PARAM_ALPHANUMEXT, 'Group id'),
                            'label' => new external_value(PARAM_TEXT, 'Label'),
                            'tip' => new external_value(PARAM_TEXT, 'Tip'),
                        ]
                    )
                ),
                'pending' => new external_value(PARAM_BOOL, 'Still being translated: call again after retryafter seconds'),
                'retryafter' => new external_value(PARAM_INT, 'Seconds to wait before calling again'),
                'credits' => new external_value(PARAM_TEXT, 'LMS Labs credits charged (if reported)'),
                'balance' => new external_value(PARAM_TEXT, 'LMS Labs credit balance (if reported)'),
            ]
        );
    }
}
