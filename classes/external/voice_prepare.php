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
use mod_aianatomy\local\voice;

/**
 * Voiceover preparation for the "Preparing voiceover" screen: reports progress and, where the site allows it,
 * generates the next missing clips (about 20 seconds of work per call).
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class voice_prepare extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'generate' => new external_value(PARAM_BOOL, 'Create missing clips (false: only report)', VALUE_DEFAULT, true),
            ]
        );
    }

    /**
     * Prepares (or reports) the voiceover.
     *
     * @param int $cmid
     * @param bool $generate
     * @return array
     */
    public static function execute(int $cmid, bool $generate = true): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'generate' => $generate]);
        [$instance, , , $context] = self::load_cm($params['cmid'], ['mod/aianatomy:view'], true);
        $teacher = has_capability('mod/aianatomy:manage', $context);
        $allowed = $teacher || (bool)get_config('mod_aianatomy', 'lmslabs_tts_studentgenerate');
        $generate = $allowed && $params['generate'];
        \core_php_time_limit::raise(120);
        // Generating takes a while: never hold the session lock meanwhile.
        \core\session\manager::write_close();
        $r = voice::prepare($instance, $context, $generate ? 20 : 0);
        $message = '';
        if ($r['state'] === 'failed') {
            // Teachers see why; students see a general note.
            $message = $teacher && get_string_manager()->string_exists($r['error'], 'mod_aianatomy')
                ? get_string($r['error'], 'mod_aianatomy') : get_string('voiceprep_failed', 'mod_aianatomy');
        }
        if (!$allowed && $r['state'] === 'preparing') {
            voice::queue($instance);
        }
        return ['state' => $r['state'], 'total' => $r['total'], 'ready' => $r['ready'],
            'retryafter' => $r['retryafter'], 'message' => $message];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'state' => new external_value(PARAM_ALPHA, 'off, ready, preparing or failed'),
                'total' => new external_value(PARAM_INT, 'Clips needed'),
                'ready' => new external_value(PARAM_INT, 'Clips ready'),
                'retryafter' => new external_value(PARAM_INT, 'Seconds to wait before asking again'),
                'message' => new external_value(PARAM_TEXT, 'Why the voiceover is not available'),
            ]
        );
    }
}
