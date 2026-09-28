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
 * Generates AI drafts (content or questions) for one structure using LMS Labs AI credits.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'what' => new external_value(PARAM_ALPHA, 'content or questions'),
                'structureids' => new external_multiple_structure(new external_value(PARAM_ALPHANUMEXT, 'Structure id')),
            ]
        );
    }

    /**
     * Generates.
     *
     * @param int $cmid
     * @param string $what
     * @param array $structureids
     * @return array
     */
    public static function execute(int $cmid, string $what, array $structureids): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'what' => $what, 'structureids' => $structureids]
        );
        $result = ['content' => [], 'questions' => [], 'pending' => false, 'retryafter' => 0, 'credits' => '',
            'balance' => ''];
        [$instance, , , $context] = self::load_cm($params['cmid'], ['mod/aianatomy:manage', 'mod/aianatomy:useai']);
        \core_php_time_limit::raise(180);
        // One structure per call keeps each request short; the editor loops for "Generate all".
        $sid = $params['structureids'][0] ?? '';
        // A request already in progress for this structure is resumed (same Idempotency-Key and body).
        if ($params['what'] === 'content') {
            $r = generator::generate_content($instance, $context, $sid, (int)$USER->id);
            if (!$r['pending']) {
                $result['content'][] = ['structureid' => $sid] + $r['draft'];
            }
        } else if ($params['what'] === 'questions') {
            $r = generator::generate_questions($instance, $context, $sid, (int)$USER->id);
            $result['questions'] = $r['questions'] ?? [];
        } else {
            throw new \moodle_exception('invalidaction', 'mod_aianatomy');
        }
        foreach (['pending', 'retryafter', 'credits', 'balance'] as $k) {
            if (isset($r[$k])) {
                $result[$k] = $r[$k];
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
        $content = ['structureid' => new external_value(PARAM_ALPHANUMEXT, 'Structure id')];
        foreach (\mod_aianatomy\local\pack::FIELDS as $f) {
            $content[$f] = new external_value(PARAM_TEXT, $f);
        }
        return new external_single_structure(
            [
                'content' => new external_multiple_structure(new external_single_structure($content)),
                'questions' => new external_multiple_structure(save_question::question_structure()),
                'pending' => new external_value(PARAM_BOOL, 'Still being generated: call again after retryafter seconds'),
                'retryafter' => new external_value(PARAM_INT, 'Seconds to wait before calling again'),
                'credits' => new external_value(PARAM_TEXT, 'LMS Labs credits charged (if reported)'),
                'balance' => new external_value(PARAM_TEXT, 'LMS Labs credit balance (if reported)'),
            ]
        );
    }
}
