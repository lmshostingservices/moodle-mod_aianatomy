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

namespace mod_aianatomy\local;

use moodle_exception;
use stdClass;

/**
 * Voiceover: Chirp 3 HD voices through LMS Labs text to speech.
 *
 * - Only text the server produced can be spoken: every speakable text is sent to the page with an HMAC
 *   signature, and the speak web service refuses unsigned text. Nobody can spend the site's credits on
 *   arbitrary text.
 * - Text is split into clips of at most 200 code points (at sentence, then clause, then word boundaries).
 * - Each clip is generated once per activity and kept in the activity's "voice" file area, so replays
 *   are free. LMS Labs deduplicates simultaneous requests through the Idempotency-Key.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class voice {
    /** @var array The eight Chirp 3 HD voices offered to teachers => gender */
    const VOICES = [
        'Aoede' => 'female', 'Kore' => 'female', 'Leda' => 'female', 'Zephyr' => 'female',
        'Charon' => 'male', 'Fenrir' => 'male', 'Orus' => 'male', 'Puck' => 'male',
    ];

    /** @var string[] Where voiceover can be offered */
    const PLACES = ['cards', 'prompts', 'questions', 'feedback'];

    /**
     * Voiceover is on for this activity and the site has LMS Labs text to speech set up.
     *
     * @param stdClass $instance
     * @return bool
     */
    public static function enabled(stdClass $instance): bool {
        return !empty($instance->voice) && ai\tts_lmslabs::is_ready();
    }

    /**
     * Places where voiceover is offered.
     *
     * @param stdClass $instance
     * @return string[]
     */
    public static function places(stdClass $instance): array {
        if (!self::enabled($instance)) {
            return [];
        }
        $p = array_map('trim', explode(',', (string)($instance->voiceplaces ?? '')));
        return array_values(array_intersect(self::PLACES, $p));
    }

    /**
     * Whether a place is on.
     *
     * @param stdClass $instance
     * @param string $place
     * @return bool
     */
    public static function on(stdClass $instance, string $place): bool {
        return in_array($place, self::places($instance), true);
    }

    /**
     * The voice for the activity.
     *
     * @param stdClass $instance
     * @return string
     */
    public static function voicename(stdClass $instance): string {
        $chosen = isset(self::VOICES[$instance->voicename ?? '']) ? $instance->voicename : 'Kore';
        $available = self::available(language::locale($instance->language ?? 'en'));
        if (!$available || in_array($chosen, $available, true)) {
            return $chosen;
        }
        // Not offered for this locale: the first available voice of the same gender, else the first available.
        foreach ($available as $v) {
            if (self::VOICES[$v] === self::VOICES[$chosen]) {
                return $v;
            }
        }
        return $available[0];
    }

    /**
     * Voices (of the eight offered) that LMS Labs lists for a locale. When the catalogue cannot be read yet,
     * all eight are returned (LMS Labs then rejects an unavailable combination with a clear message).
     *
     * @param string $locale
     * @param bool $known set to whether the catalogue was known
     * @return string[]
     */
    public static function available(string $locale, ?bool &$known = null): array {
        $caps = ai\tts_lmslabs::capabilities();
        $known = $caps !== null;
        if ($caps === null) {
            return array_keys(self::VOICES);
        }
        $list = $caps[$locale] ?? [];
        return array_values(array_filter(array_keys(self::VOICES), fn($v) => in_array($v, $list, true)));
    }

    /**
     * Site secret for signatures (generated once, never sent to the browser).
     *
     * @return string
     */
    protected static function secret(): string {
        $secret = (string)get_config('mod_aianatomy', 'voicesecret');
        if (strlen($secret) < 32) {
            $secret = random_string(48);
            set_config('voicesecret', $secret, 'mod_aianatomy');
        }
        return $secret;
    }

    /**
     * Plain text for speech (tags removed, entities decoded, whitespace collapsed).
     *
     * @param string $text
     * @return string
     */
    public static function plain(string $text): string {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Cleaned as PARAM_TEXT so the signed text survives web service cleaning unchanged.
        $text = clean_param($text, PARAM_TEXT);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Signature of a speakable text.
     *
     * @param stdClass $instance
     * @param string $text plain text
     * @param string $speed
     * @return string
     */
    public static function sign(stdClass $instance, string $text, string $speed = 'normal'): string {
        return hash_hmac('sha256', $instance->id . '|' . $speed . '|' . $text, self::secret());
    }

    /**
     * A speakable item for the page: the plain text, its speed and its signature.
     *
     * @param stdClass $instance
     * @param string $text
     * @param string $speed normal|slow
     * @return array|null null when there is nothing to say
     */
    public static function item(stdClass $instance, string $text, string $speed = 'normal'): ?array {
        $text = self::plain($text);
        if ($text === '') {
            return null;
        }
        $text = \core_text::substr($text, 0, 4000);
        return ['text' => $text, 'speed' => $speed, 'sig' => self::sign($instance, $text, $speed)];
    }

    /**
     * Splits text into clips of at most MAXCHARS code points.
     *
     * @param string $text
     * @return string[]
     */
    public static function chunks(string $text): array {
        $max = ai\tts_lmslabs::MAXCHARS;
        $len = fn($s) => \core_text::strlen($s);
        $out = [];
        $pack = function (array $parts, string $glue) use (&$out, $max, $len) {
            $cur = '';
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') {
                    continue;
                }
                $try = $cur === '' ? $p : $cur . $glue . $p;
                if ($len($try) <= $max) {
                    $cur = $try;
                    continue;
                }
                if ($cur !== '') {
                    $out[] = $cur;
                }
                $cur = $p;
            }
            if ($cur !== '') {
                $out[] = $cur;
            }
        };
        $sentences = preg_split('/(?<=[.!?。！？؟])\s+/u', trim($text)) ?: [];
        $pieces = [];
        foreach ($sentences as $s) {
            if ($len($s) <= $max) {
                $pieces[] = $s;
                continue;
            }
            // Long sentence: split at clause marks, then at spaces, then hard.
            foreach (preg_split('/(?<=[,;:，；：、])\s*/u', $s) as $clause) {
                if ($len($clause) <= $max) {
                    $pieces[] = $clause;
                    continue;
                }
                $words = preg_split('/\s+/u', $clause);
                $cur = '';
                foreach ($words as $w) {
                    while ($len($w) > $max) {
                        $pieces[] = \core_text::substr($w, 0, $max);
                        $w = \core_text::substr($w, $max);
                    }
                    $try = $cur === '' ? $w : $cur . ' ' . $w;
                    if ($len($try) > $max) {
                        $pieces[] = $cur;
                        $cur = $w;
                    } else {
                        $cur = $try;
                    }
                }
                if ($cur !== '') {
                    $pieces[] = $cur;
                }
            }
        }
        $pack($pieces, ' ');
        return $out;
    }

    /**
     * Cache key of a clip.
     *
     * @param string $locale
     * @param string $voice
     * @param string $speed
     * @param string $text
     * @return string
     */
    public static function hash(string $locale, string $voice, string $speed, string $text): string {
        return hash('sha256', $locale . '|' . $voice . '|' . $speed . '|' . $text);
    }

    /**
     * Returns the clip URLs for a signed text, generating missing clips with LMS Labs.
     *
     * Each missing clip is a job with its own persisted Idempotency-Key and body. When LMS Labs answers 202
     * (or 429, or cannot be reached), the job stays pending and the caller polls again after "retryafter"
     * seconds with the same key; clips are stored in Moodle before their job is removed.
     *
     * @param stdClass $instance
     * @param \context_module $context
     * @param string $text
     * @param string $sig
     * @param string $speed
     * @param bool $generate may call LMS Labs for missing clips
     * @param bool $explicit a teacher explicitly asked for the audio (may retry clips that failed with 409/410)
     * @return array ['clips' => string[], 'generated' => int, 'missing' => int, 'pending' => bool,
     *                'retryafter' => int, 'balance' => string, 'credits' => float]
     */
    public static function speak(stdClass $instance, \context_module $context, string $text, string $sig,
            string $speed, bool $generate, bool $explicit = false): array {
        global $DB;
        if (!self::enabled($instance)) {
            throw new moodle_exception('voicenotconfigured', 'mod_aianatomy');
        }
        $speed = $speed === 'slow' ? 'slow' : 'normal';
        if (!hash_equals(self::sign($instance, $text, $speed), $sig)) {
            throw new moodle_exception('voicebadsignature', 'mod_aianatomy');
        }
        $locale = language::locale($instance->language ?? 'en');
        $voicename = self::voicename($instance);
        $fs = get_file_storage();
        $out = ['clips' => [], 'generated' => 0, 'missing' => 0, 'pending' => false, 'retryafter' => 0,
            'balance' => '', 'credits' => 0.0];
        foreach (self::chunks($text) as $chunk) {
            $hash = self::hash($locale, $voicename, $speed, $chunk);
            $file = $fs->get_file($context->id, 'mod_aianatomy', 'voice', 0, '/', $hash . '.mp3');
            if (!$file) {
                if (!$generate) {
                    $out['missing']++;
                    continue;
                }
                $job = ai\jobs::start(
                    (int)$instance->id, 'speech', $hash,
                    fn() => [ai\tts_lmslabs::body($chunk, $locale, $voicename, $speed), null]
                );
                if ($failed = ai\jobs::is_failed($job)) {
                    if (!$explicit) {
                        // Failed with 409/410 earlier: never create a new billable key automatically.
                        throw new moodle_exception($failed, 'mod_aianatomy');
                    }
                    // A teacher explicitly asked again: this is an intentional new request with a new key.
                    ai\jobs::finish($job);
                    $job = ai\jobs::start(
                        (int)$instance->id, 'speech', $hash,
                        fn() => [ai\tts_lmslabs::body($chunk, $locale, $voicename, $speed), null]
                    );
                }
                try {
                    $result = ai\tts_lmslabs::request($job);
                } catch (moodle_exception $e) {
                    if (in_array($e->errorcode, ['lmslabsconflict', 'lmslabsexpired'], true)) {
                        // 409/410: keep a marker so automatic plays do not create a new billable key.
                        ai\jobs::fail($job, $e->errorcode);
                    } else {
                        // 401/402/403/404/413/422 are not charged; the next attempt starts a new request.
                        ai\jobs::finish($job);
                    }
                    throw $e;
                }
                if ($result['state'] === 'pending') {
                    ai\jobs::wait($job, $result['retryafter']);
                    $out['pending'] = true;
                    $out['retryafter'] = max($out['retryafter'], $result['retryafter']);
                    continue;
                }
                // Store the audio durably before the job (and its key) is discarded. If storing fails, the job stays,
                // so the next attempt replays the result with the same key (no new debit within 24 hours).
                $file = $fs->get_file($context->id, 'mod_aianatomy', 'voice', 0, '/', $hash . '.mp3');
                if (!$file) {
                    $file = $fs->create_file_from_string(
                        ['contextid' => $context->id, 'component' => 'mod_aianatomy',
                        'filearea' => 'voice', 'itemid' => 0, 'filepath' => '/', 'filename' => $hash . '.mp3',
                        'mimetype' => $result['mimetype']], $result['audio']
                    );
                }
                if (!$DB->record_exists('aianatomy_voice', ['aianatomyid' => $instance->id, 'hash' => $hash])) {
                    try {
                        $DB->insert_record(
                            'aianatomy_voice', (object)['aianatomyid' => $instance->id, 'hash' => $hash,
                            'locale' => $locale, 'voicename' => $voicename, 'speed' => $speed,
                            'textlength' => \core_text::strlen($chunk), 'credits' => $result['credits'],
                            'requestid' => $result['requestid'], 'timecreated' => time()]
                        );
                    } catch (\dml_exception $e) {
                        debugging('Voice clip already recorded: ' . $hash, DEBUG_DEVELOPER);
                    }
                }
                ai\jobs::finish($job);
                $out['generated']++;
                $out['balance'] = $result['balance'] ?: $out['balance'];
                $out['credits'] += is_numeric($result['credits']) ? (float)$result['credits'] : 0;
            }
            $out['clips'][] = \moodle_url::make_pluginfile_url(
                $context->id, 'mod_aianatomy', 'voice', 0, '/',
                $file->get_filename()
            )->out(false);
        }
        if ($out['pending']) {
            // Play nothing until every clip is ready (the caller polls again).
            $out['clips'] = [];
        }
        $out['credits'] = round($out['credits'], 4);
        return $out;
    }

    /**
     * Clip statistics for the editor.
     *
     * @param stdClass $instance
     * @return array ['clips' => int, 'credits' => float]
     */
    public static function stats(stdClass $instance): array {
        global $DB;
        $rows = $DB->get_records('aianatomy_voice', ['aianatomyid' => $instance->id], '', 'id, credits');
        $credits = 0.0;
        foreach ($rows as $r) {
            $credits += is_numeric($r->credits) ? (float)$r->credits : 0;
        }
        return ['clips' => count($rows), 'credits' => round($credits, 2)];
    }

    /**
     * Deletes all stored clips of an activity.
     *
     * @param stdClass $instance
     * @param \context|null $context
     */
    public static function clear(stdClass $instance, ?\context $context): void {
        global $DB;
        if ($context) {
            get_file_storage()->delete_area_files($context->id, 'mod_aianatomy', 'voice');
        }
        $DB->delete_records('aianatomy_voice', ['aianatomyid' => $instance->id]);
    }

    /**
     * Text read out for a study card: name, Latin name and the visible fields, with their labels.
     *
     * @param string $name
     * @param string $latin
     * @param array $content visible content, field => text
     * @return string
     */
    public static function card_text(string $name, string $latin, array $content): string {
        $parts = [rtrim($name, '.') . '.'];
        if ($latin !== '' && $latin !== $name) {
            $parts[] = rtrim($latin, '.') . '.';
        }
        foreach (['description', 'location', 'function', 'origin', 'mnemonic', 'clinical'] as $f) {
            if (!empty($content[$f])) {
                $parts[] = get_string('field_' . $f, 'mod_aianatomy') . ': ' . rtrim(self::plain($content[$f]), '.') . '.';
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Fixed phrases used for spoken feedback, as signed items.
     *
     * @param stdClass $instance
     * @return array
     */
    public static function phrases(stdClass $instance): array {
        $out = [];
        foreach (['correct', 'incorrect', 'welldone'] as $k) {
            $out[$k] = self::item($instance, get_string('voice_phrase_' . $k, 'mod_aianatomy'));
        }
        return $out;
    }
}
