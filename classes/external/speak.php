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
use mod_aianatomy\local\voice;

/**
 * Returns voiceover clips for a signed text, generating missing clips with LMS Labs text to speech.
 *
 * Only text the server signed for this activity is accepted, so the site's AI credits can only be spent
 * on this activity's own content. Clips are generated once and then served from the activity's files.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class speak extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'text' => new external_value(PARAM_TEXT, 'Text exactly as signed by the server'),
                'sig' => new external_value(PARAM_ALPHANUM, 'Signature'),
                'speed' => new external_value(PARAM_ALPHA, 'normal or slow', VALUE_DEFAULT, 'normal'),
                'prefetch' => new external_value(PARAM_BOOL, 'Teacher pre-generation (no playback)', VALUE_DEFAULT, false),
            ]
        );
    }

    /**
     * Speaks.
     *
     * @param int $cmid
     * @param string $text
     * @param string $sig
     * @param string $speed
     * @param bool $prefetch
     * @return array
     */
    public static function execute(int $cmid, string $text, string $sig, string $speed = 'normal',
            bool $prefetch = false): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'text' => $text, 'sig' => $sig, 'speed' => $speed, 'prefetch' => $prefetch]
        );
        [$instance, , , $context] = self::load_cm($params['cmid'], ['mod/aianatomy:view'], true);
        $teacher = has_capability('mod/aianatomy:manage', $context);
        if ($params['prefetch']) {
            require_capability('mod/aianatomy:manage', $context);
        }
        // Students may trigger generation of a missing clip unless the site only plays pre-generated audio.
        $generate = $teacher || (bool)get_config('mod_aianatomy', 'lmslabs_tts_studentgenerate');
        \core_php_time_limit::raise(120);
        $result = voice::speak(
            $instance, $context, $params['text'], $params['sig'], $params['speed'], $generate,
            $teacher && $params['prefetch']
        );
        if ($result['missing'] && !$result['clips'] && !$result['pending']) {
            throw new \moodle_exception('voicenotready', 'mod_aianatomy');
        }
        if (!$teacher) {
            $result['balance'] = '';
            $result['credits'] = 0;
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
                'clips' => new external_multiple_structure(new external_value(PARAM_URL, 'Audio URL')),
                'generated' => new external_value(PARAM_INT, 'Clips generated now (billable)'),
                'missing' => new external_value(PARAM_INT, 'Clips not available'),
                'pending' => new external_value(PARAM_BOOL, 'Audio is being generated: call again after retryafter seconds'),
                'retryafter' => new external_value(PARAM_INT, 'Seconds to wait before calling again'),
                'balance' => new external_value(PARAM_TEXT, 'LMS Labs credit balance (teachers only)'),
                'credits' => new external_value(PARAM_FLOAT, 'Credits reported for clips generated now (teachers only)'),
            ]
        );
    }
}
