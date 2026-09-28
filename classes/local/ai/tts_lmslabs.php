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

use mod_aianatomy\local\credentials;
use mod_aianatomy\local\voice;
use moodle_exception;

/**
 * LMS Labs speech route for AI Anatomy (Google Chirp 3 HD voices, 1 LMS Labs credit per successful clip).
 *
 *   POST https://lms-labs.com/api/moodle/ai-anatomy/speech/tts
 *   X-Site-ID, X-API-Key, Idempotency-Key (persisted, see {@see jobs}), Content-Type: application/json
 *   {"text": "...", "locale": "de-DE", "voice": "Kore", "speed": "normal"|"slow"}   (text: 1-200 code points)
 *   200 audio/mpeg (complete MP3) with X-Request-Id, X-Credits-Charged, X-Credits-Balance, X-Idempotent-Replay.
 *   GET .../speech/capabilities: the voice catalogue (which voices exist for which locale).
 * Status handling is shared with the text route, see {@see lmslabs::outcome()}.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tts_lmslabs {
    /** Maximum text length of one request, in Unicode code points. */
    const MAXCHARS = 200;

    /** How long the voice catalogue is cached. */
    const CAPSCACHE = 12 * HOURSECS;

    /**
     * Endpoint URL.
     *
     * @return string
     */
    public static function endpoint(): string {
        return endpoints::speech();
    }

    /**
     * Voiceover can be used: a complete credential pair is available.
     *
     * @return bool
     */
    public static function is_ready(): bool {
        return self::endpoint() !== '' && credentials::source() !== 'missing';
    }

    /**
     * Request body for one clip.
     *
     * @param string $text
     * @param string $locale
     * @param string $voice short name
     * @param string $speed
     * @return string JSON
     */
    public static function body(string $text, string $locale, string $voice, string $speed): string {
        if (\core_text::strlen($text) > self::MAXCHARS || trim($text) === '') {
            throw new moodle_exception('voiceinvalidtext', 'mod_aianatomy');
        }
        return json_encode(
            ['text' => $text, 'locale' => $locale, 'voice' => $voice,
            'speed' => $speed === 'slow' ? 'slow' : 'normal'], JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Sends (or re-sends) a clip job.
     *
     * @param \stdClass $job
     * @return array ['state' => 'done', 'audio', 'mimetype', 'credits', 'balance', 'requestid']
     *               or ['state' => 'pending', 'retryafter']
     */
    public static function request(\stdClass $job): array {
        [$status, $headers, $body] = lmslabs::send(
            'POST', self::endpoint(), $job->body, 'audio/mpeg, application/json',
            $job->idemkey
        );
        $o = lmslabs::outcome($status, $headers, $body, 'voice');
        if ($o['state'] !== 'done') {
            return $o;
        }
        $type = strtolower(lmslabs::header($headers, 'Content-Type'));
        $audio = null;
        $mimetype = 'audio/mpeg';
        if (str_starts_with($type, 'audio/')) {
            $audio = $body;
            $mimetype = trim(explode(';', $type)[0]);
        } else {
            $data = json_decode($body, true);
            $b64 = is_array($data) ? ($data['audioContent'] ?? $data['audio'] ?? null) : null;
            $audio = is_string($b64) ? (base64_decode($b64, true) ?: null) : null;
        }
        if ($audio === null || $audio === '') {
            throw new moodle_exception('voiceerror', 'mod_aianatomy', '', 'HTTP 200 without audio');
        }
        return ['state' => 'done', 'audio' => $audio, 'mimetype' => $mimetype,
            'credits' => \core_text::substr(lmslabs::header($headers, 'X-Credits-Charged'), 0, 20),
            'balance' => \core_text::substr(lmslabs::header($headers, 'X-Credits-Balance'), 0, 20),
            'requestid' => \core_text::substr(lmslabs::header($headers, 'X-Request-Id'), 0, 100)];
    }

    /**
     * The voice catalogue: locale => short voice names, or null when unknown (not reachable yet).
     *
     * @param bool $refresh ignore the cache
     * @return array|null
     */
    public static function capabilities(bool $refresh = false): ?array {
        $cached = json_decode((string)get_config('mod_aianatomy', 'ttscapabilities'), true);
        if (!$refresh && is_array($cached) && ($cached['time'] ?? 0) > time() - self::CAPSCACHE) {
            return $cached['locales'] ?: null;
        }
        if (!self::is_ready()) {
            return is_array($cached) ? ($cached['locales'] ?: null) : null;
        }
        [$status, , $body] = lmslabs::send('GET', endpoints::capabilities(), null, 'application/json', null, 10);
        $locales = $status === 200 ? self::parse_capabilities(json_decode($body, true)) : [];
        if (!$locales) {
            // Keep the last good catalogue (or none); try again in an hour, not on every page.
            $keep = is_array($cached) ? ($cached['locales'] ?? null) : null;
            set_config(
                'ttscapabilities', json_encode(
                    ['time' => time() - self::CAPSCACHE + HOURSECS,
                    'locales' => $keep]
                ), 'mod_aianatomy'
            );
            return $keep ?: null;
        }
        set_config('ttscapabilities', json_encode(['time' => time(), 'locales' => $locales]), 'mod_aianatomy');
        return $locales;
    }

    /**
     * Reads locale/voice pairs from a catalogue response (tolerant of the exact JSON layout).
     *
     * Accepts objects with a locale ("locale", "languageCode", "language_code") and either a list of voices
     * ("voices": names or objects with "name"/"shortName"/"voice"/"id") or one voice name, anywhere in the
     * response, and maps keyed by locale ({"de-DE": ["Kore", ...]}). Full names ("de-DE-Chirp3-HD-Kore") are
     * reduced to the short name.
     *
     * @param mixed $data
     * @return array locale => string[]
     */
    public static function parse_capabilities($data): array {
        $out = [];
        $add = function (string $locale, $voice) use (&$out) {
            if (is_array($voice)) {
                $voice = $voice['shortName'] ?? $voice['short_name'] ?? $voice['name'] ?? $voice['voice'] ?? $voice['id'] ?? '';
            }
            $voice = (string)$voice;
            if (preg_match('/-Chirp3-HD-([A-Za-z]+)$/', $voice, $m)) {
                $voice = $m[1];
            }
            if ($locale !== '' && isset(voice::VOICES[$voice])) {
                $out[$locale][$voice] = $voice;
            }
        };
        $walk = function ($node) use (&$walk, $add) {
            if (!is_array($node)) {
                return;
            }
            $locale = $node['locale'] ?? $node['languageCode'] ?? $node['language_code'] ?? null;
            if (is_string($locale)) {
                if (isset($node['voices']) && is_array($node['voices'])) {
                    foreach ($node['voices'] as $v) {
                        $add($locale, $v);
                    }
                } else if (isset($node['name']) || isset($node['shortName']) || isset($node['voice'])) {
                    $add($locale, $node);
                }
            } else if (isset($node['name']) && is_string($node['name'])
                    && preg_match('/^([a-z]{2,3}-[A-Z]{2})-Chirp3-HD-/', $node['name'], $m)) {
                $add($m[1], $node['name']);
            }
            foreach ($node as $k => $child) {
                if (is_string($k) && preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $k) && is_array($child)) {
                    foreach ($child as $v) {
                        if (is_array($v) || is_string($v)) {
                            $add($k, $v);
                        }
                    }
                }
                $walk($child);
            }
        };
        $walk($data);
        return array_map('array_values', $out);
    }
}
