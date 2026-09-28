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
 * Finishes an attempt.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finish_attempt extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(['attemptid' => new external_value(PARAM_INT, 'Attempt id')]);
    }

    /**
     * Finishes the attempt.
     *
     * @param int $attemptid
     * @return array
     */
    public static function execute(int $attemptid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid]);
        [$attempt, $instance, $cm, $course, $context] = self::load_attempt($params['attemptid']);
        return manager::finish_attempt($instance, $cm, $course, $context, $attempt);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'mode' => new external_value(PARAM_ALPHA, 'Mode'),
                'grade' => new external_value(PARAM_FLOAT, 'Percentage'),
                'identcorrect' => new external_value(PARAM_INT, 'Structures identified correctly'),
                'identtotal' => new external_value(PARAM_INT, 'Structures'),
                'quizcorrect' => new external_value(PARAM_INT, 'Questions correct'),
                'quiztotal' => new external_value(PARAM_INT, 'Questions'),
                'passpercent' => new external_value(PARAM_FLOAT, 'Pass mark percentage, 0 = none'),
                'passed' => new external_value(PARAM_BOOL, 'Passed'),
                'duration' => new external_value(PARAM_INT, 'Seconds'),
                'attemptsleft' => new external_value(PARAM_INT, 'Attempts left, -1 = unlimited'),
                'review' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(PARAM_TEXT, 'Structure'),
                            'correct' => new external_value(PARAM_BOOL, 'Correct'),
                            'given' => new external_value(PARAM_TEXT, 'Label placed'),
                        ]
                    )
                ),
                'quizreview' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'text' => new external_value(PARAM_TEXT, 'Question'),
                            'correct' => new external_value(PARAM_BOOL, 'Correct'),
                            'given' => new external_value(PARAM_TEXT, 'Answer given'),
                            'answer' => new external_value(PARAM_TEXT, 'Correct answer'),
                            'explanation' => new external_value(PARAM_TEXT, 'Explanation'),
                        ]
                    )
                ),
                'mastery' => new external_single_structure(
                    [
                        'mastered' => new external_value(PARAM_INT, 'Mastered'),
                        'learning' => new external_value(PARAM_INT, 'Learning'),
                        'difficult' => new external_value(PARAM_INT, 'Difficult'),
                        'new' => new external_value(PARAM_INT, 'Not yet practised'),
                    ]
                ),
                'difficult' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Structure name')),
            ]
        );
    }
}
