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
 * Starts a practice or test attempt.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class start_attempt extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'mode' => new external_value(PARAM_ALPHA, 'practice or test'),
                'focus' => new external_value(PARAM_ALPHA, 'all or weak', VALUE_DEFAULT, 'all'),
            ]
        );
    }

    /**
     * Starts the attempt.
     *
     * @param int $cmid
     * @param string $mode
     * @param string $focus
     * @return array
     */
    public static function execute(int $cmid, string $mode, string $focus = 'all'): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'mode' => $mode, 'focus' => $focus]);
        [$instance, $cm, $course, $context] = self::load_cm($params['cmid'], ['mod/aianatomy:attempt'], true);
        return manager::start_attempt($instance, $cm, $course, $context, $params['mode'], (int)$USER->id, $params['focus']);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
                'attempt' => new external_value(PARAM_INT, 'Attempt number'),
                'voice' => new external_single_structure(
                    [
                        'enabled' => new external_value(PARAM_BOOL, 'Voiceover available'),
                        'places' => new external_multiple_structure(new external_value(PARAM_ALPHA, 'Place')),
                        'auto' => new external_value(PARAM_BOOL, 'Read automatically'),
                        'phrases' => new external_single_structure(
                            [
                                'correct' => self::voice_item_structure(),
                                'incorrect' => self::voice_item_structure(),
                                'welldone' => self::voice_item_structure(),
                            ]
                        ),
                    ]
                ),
                'mode' => new external_value(PARAM_ALPHA, 'Mode'),
                'focus' => new external_value(PARAM_ALPHA, 'Focus'),
                'timelimit' => new external_value(PARAM_INT, 'Seconds, 0 = none'),
                'rounds' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'group' => new external_value(PARAM_ALPHANUMEXT, 'Group id'),
                            'title' => new external_value(PARAM_TEXT, 'Title'),
                            'tip' => new external_value(PARAM_TEXT, 'Study tip'),
                            'nodes' => new external_multiple_structure(new external_value(PARAM_ALPHANUMEXT, 'Node')),
                            'pins' => new external_multiple_structure(
                                new external_single_structure(
                                    [
                                        'token' => new external_value(PARAM_ALPHANUM, 'Pin token'),
                                        'node' => new external_value(PARAM_ALPHANUMEXT, 'Model node'),
                                        'anchor' => new external_multiple_structure(
                                            new external_value(PARAM_FLOAT, 'Coordinate'), 'Anchor',
                                            VALUE_OPTIONAL
                                        ),
                                        'labelpos' => new external_single_structure(
                                            [
                                                'x' => new external_value(PARAM_FLOAT, 'x'),
                                                'y' => new external_value(PARAM_FLOAT, 'y'),
                                            ], 'Label position', VALUE_OPTIONAL
                                        ),
                                        'number' => new external_value(PARAM_INT, 'Number'),
                                        'colour' => new external_value(PARAM_TEXT, 'Colour'),
                                        'voice' => self::voice_item_structure(),
                                    ]
                                )
                            ),
                            'labels' => new external_multiple_structure(
                                new external_single_structure(
                                    [
                                        'token' => new external_value(PARAM_ALPHANUM, 'Label token'),
                                        'text' => new external_value(PARAM_TEXT, 'Text'),
                                    ]
                                )
                            ),
                            'answers' => new external_multiple_structure(
                                new external_single_structure(
                                    [
                                        'pin' => new external_value(PARAM_ALPHANUM, 'Pin'),
                                        'label' => new external_value(PARAM_ALPHANUM, 'Label'),
                                    ]
                                )
                            ),
                            'hints' => new external_multiple_structure(
                                new external_single_structure(
                                    [
                                        'pin' => new external_value(PARAM_ALPHANUM, 'Pin'),
                                        'text' => new external_value(PARAM_TEXT, 'Hint'),
                                    ]
                                )
                            ),
                        ]
                    )
                ),
                'questions' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'token' => new external_value(PARAM_ALPHANUM, 'Question token'),
                            'text' => new external_value(PARAM_TEXT, 'Question'),
                            'options' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Option')),
                            'answer' => new external_value(PARAM_INT, 'Correct option (practice only)', VALUE_OPTIONAL),
                            'explanation' => new external_value(PARAM_TEXT, 'Explanation (practice only)', VALUE_OPTIONAL),
                            'voice' => self::voice_item_structure(),
                            'voiceoptions' => new external_multiple_structure(
                                self::voice_item_structure(VALUE_REQUIRED),
                                'Options, in display order', VALUE_OPTIONAL
                            ),
                            'voicefeedback' => self::voice_item_structure(),
                        ]
                    )
                ),
                'names' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'node' => new external_value(PARAM_ALPHANUMEXT, 'Node'),
                            'name' => new external_value(PARAM_TEXT, 'Name'),
                        ]
                    )
                ),
            ]
        );
    }
}
