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

use core_external\external_api;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Shared loading for external functions.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base extends external_api {
    /**
     * Loads the activity from a course module id, validates the context and checks capabilities.
     *
     * @param int $cmid
     * @param string[] $caps
     * @param bool $activitylang switch strings to the activity language (student-facing calls)
     * @return array [instance, cm, course, context]
     */
    protected static function load_cm(int $cmid, array $caps, bool $activitylang = false): array {
        global $DB;
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'aianatomy');
        $instance = $DB->get_record('aianatomy', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        foreach ($caps as $cap) {
            require_capability($cap, $context);
        }
        // Strings built for students (prompts, voiceover text) must be in the activity language.
        if ($activitylang) {
            \mod_aianatomy\local\language::apply($instance);
        }
        return [$instance, $cm, $course, $context];
    }

    /**
     * Loads the current user's attempt and its activity.
     *
     * @param int $attemptid
     * @return array [attempt, instance, cm, course, context]
     */
    protected static function load_attempt(int $attemptid): array {
        global $DB, $USER;
        $attempt = \mod_aianatomy\local\manager::get_user_attempt($attemptid, (int)$USER->id);
        $instance = $DB->get_record('aianatomy', ['id' => $attempt->aianatomyid], '*', MUST_EXIST);
        [$course, $cm] = get_course_and_cm_from_instance($instance->id, 'aianatomy');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aianatomy:attempt', $context);
        \mod_aianatomy\local\language::apply($instance);
        return [$attempt, $instance, $cm, $course, $context];
    }

    /**
     * A signed voiceover item (plain text, speed, signature).
     *
     * @param int $required VALUE_REQUIRED or VALUE_OPTIONAL
     * @return external_single_structure
     */
    public static function voice_item_structure(int $required = VALUE_OPTIONAL): external_single_structure {
        return new external_single_structure(
            [
                'text' => new external_value(PARAM_TEXT, 'Plain text to speak (exactly as signed)'),
                'speed' => new external_value(PARAM_ALPHA, 'normal or slow'),
                'sig' => new external_value(PARAM_ALPHANUM, 'Signature'),
            ], 'Voiceover item', $required
        );
    }
}
