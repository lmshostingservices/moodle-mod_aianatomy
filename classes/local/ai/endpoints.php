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
namespace mod_aianatomy\local\ai;

/**
 * LMS Labs endpoints used by AI Anatomy (built in; nothing to configure).
 *
 * Admins only enter the Site ID and API key (Central Config). For development or a private LMS Labs
 * deployment, a site can still point elsewhere with forced settings in config.php, for example:
 *   $CFG->forced_plugin_settings['mod_aianatomy']['lmslabs_endpoint'] = 'https://staging.example/...';
 *   $CFG->forced_plugin_settings['mod_aianatomy']['lmslabs_tts_endpoint'] = 'https://staging.example/...';
 *   $CFG->forced_plugin_settings['mod_aianatomy']['lmslabs_tts_capabilities'] = 'https://staging.example/...';
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class endpoints {
    /** LMS Labs base URL. */
    const BASE = 'https://lms-labs.com';

    /** Text generation (content, questions, translation, grouptranslation). */
    const TEXT = self::BASE . '/api/moodle/ai-anatomy/text';

    /** Speech (voiceover). */
    const SPEECH = self::BASE . '/api/moodle/ai-anatomy/speech/tts';

    /** Voice catalogue (which voices exist for which locale). */
    const CAPABILITIES = self::BASE . '/api/moodle/ai-anatomy/speech/capabilities';

    /**
     * Voice catalogue endpoint.
     *
     * @return string
     */
    public static function capabilities(): string {
        return self::pick('lmslabs_tts_capabilities', self::CAPABILITIES);
    }

    /**
     * Text generation endpoint.
     *
     * @return string
     */
    public static function text(): string {
        return self::pick('lmslabs_endpoint', self::TEXT);
    }

    /**
     * Speech endpoint.
     *
     * @return string
     */
    public static function speech(): string {
        return self::pick('lmslabs_tts_endpoint', self::SPEECH);
    }

    /**
     * Built-in URL unless config.php forces another one.
     *
     * @param string $name
     * @param string $default
     * @return string
     */
    protected static function pick(string $name, string $default): string {
        global $CFG;
        $forced = trim((string)($CFG->forced_plugin_settings['mod_aianatomy'][$name] ?? ''));
        return $forced !== '' ? $forced : $default;
    }
}
