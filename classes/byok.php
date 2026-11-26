<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_aianatomy;

use mod_aianatomy\local\byok\engine;

/**
 * Bring your own AI: library content, questions, translations and voiceover made on the site's own AI.
 *
 * Each part follows its service in AI Grader Central Config: writing follows Text and voiceover follows Voice. A part
 * set to the site's own AI runs here, from this Moodle site straight to the AI provider, with the LMS Labs service's
 * checks, and costs no LMS Labs credits; a part left on LMS Labs credits runs on LMS Labs as before.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byok {
    /** @var string This plugin, as AI Grader Central Config knows it. */
    const COMPONENT = 'mod_aianatomy';

    /** @var string[] The closest OpenAI voice to each Chirp 3 HD voice the plugin offers. */
    const OPENAI_VOICES = ['Aoede' => 'nova', 'Kore' => 'shimmer', 'Leda' => 'coral', 'Zephyr' => 'sage',
        'Puck' => 'onyx', 'Charon' => 'echo', 'Fenrir' => 'ash', 'Orus' => 'ballad'];

    /**
     * Whether a service runs on the site's own AI now.
     *
     * @param string $service text or voice
     * @return bool
     */
    public static function uses(string $service): bool {
        return class_exists('\local_aiconfig\byok\ai') && \local_aiconfig\byok\ai::uses_own_ai(self::COMPONENT, $service);
    }

    /**
     * Run a text job (POST /api/moodle/ai-anatomy/text) on the site's own AI.
     *
     * @param string $body The job's request body.
     * @return string The validated JSON, as the service returns it in choices[0].message.content.
     */
    public static function text(string $body): string {
        engine::$text = function (string $system, string $user, int $maxtokens, float $temperature, array $schema): string {
            \core_php_time_limit::raise(300);
            return \local_aiconfig\byok\ai::text(self::COMPONENT, $system, $user, ['maxtokens' => $maxtokens,
                'temperature' => $temperature, 'json' => true, 'timeout' => 120, 'schema' => $schema]);
        };
        [$status, $out] = engine::text($body);
        if ($status !== 200) {
            throw new \moodle_exception('aierror', 'mod_aianatomy', '', get_string('byok_name', 'mod_aianatomy') . ': ' . $out);
        }
        return $out;
    }

    /**
     * Run a voiceover clip job (POST /api/moodle/ai-anatomy/speech/tts) on the site's own voice.
     *
     * A Google key uses the service's Chirp 3 HD voice; an OpenAI key the closest OpenAI voice; ElevenLabs the voice
     * the administrator chose.
     *
     * @param string $body The job's request body.
     * @return string MP3 audio.
     */
    public static function speech(string $body): string {
        engine::$speech = function (string $text, string $language, string $voice, float $rate): string {
            $vendor = \local_aiconfig\byok\router::vendor(\local_aiconfig\byok\router::VOICE);
            $short = preg_match('/-Chirp3-HD-([A-Za-z]+)$/', $voice, $m) ? $m[1] : $voice;
            $name = $vendor === 'googletts' ? $voice : ($vendor === 'openai' ? (self::OPENAI_VOICES[$short] ?? 'shimmer') : '');
            $options = ['language' => $language, 'voice' => $name, 'format' => 'mp3', 'rate' => $rate];
            return \local_aiconfig\byok\ai::speech(self::COMPONENT, $text, $options);
        };
        [$status, $out] = engine::speech($body);
        if ($status !== 200) {
            throw new \moodle_exception('voiceerror', 'mod_aianatomy', '', get_string('byok_name', 'mod_aianatomy') . ': ' . $out);
        }
        return $out;
    }
}
