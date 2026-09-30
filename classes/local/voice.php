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

    /**
     * @var string[] Where voiceover is offered: only the cards (Study cards and the Practice pop-up card of each
     * ticked structure). Find prompts, questions and feedback are not voiced, which keeps the credits spent per
     * activity low. Values from older versions ('prompts', 'questions', 'feedback') are ignored.
     */
    const PLACES = ['cards'];

    /** Card fields in the order the card shows them (and the voiceover reads them). */
    const CARDFIELDS = ['origin', 'location', 'description', 'function', 'mnemonic', 'clinical'];

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
            if (!$file && $generate) {
                // Free: the same clip (same text, language, voice and speed) made for another activity on this site.
                $file = self::reuse($instance, $context, $hash, $locale, $voicename, $speed, $chunk);
            }
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
                if ((int)$job->retryafter > time()) {
                    // LMS Labs asked us to wait (202/429 Retry-After): do not re-send before then.
                    $out['pending'] = true;
                    $out['retryafter'] = max($out['retryafter'], (int)$job->retryafter - time());
                    continue;
                }
                try {
                    $result = ai\tts_lmslabs::request($job);
                } catch (moodle_exception $e) {
                    if (in_array($e->errorcode, self::PERMANENT, true)) {
                        // 409/410 (and 413/422, rejected unchanged text): keep a marker so automatic requests never
                        // re-send it with a new key. A changed text is a new clip; a teacher can retry explicitly.
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
        if ($out['pending'] || $out['missing']) {
            // Play nothing until every clip of the text is ready: never read out only part of a card.
            $out['clips'] = [];
        }
        $out['credits'] = round($out['credits'], 4);
        return $out;
    }

    /**
     * Copies a clip another AI Anatomy activity on this site already has (identical audio, no LMS Labs request).
     *
     * @param stdClass $instance
     * @param \context_module $context
     * @param string $hash clip hash (locale, voice, speed and text)
     * @param string $locale
     * @param string $voicename
     * @param string $speed
     * @param string $chunk clip text
     * @return \stored_file|null
     */
    protected static function reuse(stdClass $instance, \context_module $context, string $hash, string $locale,
            string $voicename, string $speed, string $chunk): ?\stored_file {
        global $DB;
        $fs = get_file_storage();
        $others = $DB->get_records_select(
            'aianatomy_voice', 'hash = :hash AND aianatomyid <> :id', ['hash' => $hash, 'id' => $instance->id], 'id ASC',
            'id, aianatomyid', 0, 5
        );
        foreach ($others as $other) {
            $cm = get_coursemodule_from_instance('aianatomy', $other->aianatomyid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $source = $fs->get_file(\context_module::instance($cm->id)->id, 'mod_aianatomy', 'voice', 0, '/', $hash . '.mp3');
            if (!$source) {
                continue;
            }
            $file = $fs->create_file_from_storedfile(
                ['contextid' => $context->id, 'component' => 'mod_aianatomy', 'filearea' => 'voice', 'itemid' => 0,
                'filepath' => '/', 'filename' => $hash . '.mp3'],
                $source
            );
            if (!$DB->record_exists('aianatomy_voice', ['aianatomyid' => $instance->id, 'hash' => $hash])) {
                $DB->insert_record(
                    'aianatomy_voice', (object)['aianatomyid' => $instance->id, 'hash' => $hash, 'locale' => $locale,
                    'voicename' => $voicename, 'speed' => $speed, 'textlength' => \core_text::strlen($chunk),
                    'credits' => '0', 'requestid' => 'reused', 'timecreated' => time()]
                );
            }
            return $file;
        }
        return null;
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

    /** Errors of one clip that automatic preparation never retries (conflict, expired, invalid text). */
    const PERMANENT = ['lmslabsconflict', 'lmslabsexpired', 'lmslabsinvalid'];

    /** Errors that stop preparation until something changes (credentials, entitlement, credits, service). */
    const STOPPERS = ['aiauth', 'voicenoentitlement', 'ainocredits', 'voicenotavailable', 'voicenotconfigured',
        'lmslabscredentialsmissing'];

    /**
     * Voiceover readiness of an activity: every text students can hear, split into clips.
     *
     * @param stdClass $instance
     * @param \context_module $context
     * @return array ['total' => clips needed, 'ready' => clips stored, 'blocked' => clips that failed for good,
     *               'blockedcode' => their most common error, 'items' => [[item, clips still to create]]]
     */
    public static function status(stdClass $instance, \context_module $context): array {
        $locale = language::locale($instance->language ?? 'en');
        $voicename = self::voicename($instance);
        $have = [];
        foreach (get_file_storage()->get_area_files($context->id, 'mod_aianatomy', 'voice', 0, 'id', false) as $file) {
            $have[$file->get_filename()] = true;
        }
        $failed = ai\jobs::failed_targets((int)$instance->id);
        $seen = [];
        $items = [];
        $blocked = [];
        foreach (manager::voice_items($instance, $context) as $item) {
            $missing = 0;
            $itemmissing = [];
            $itemfailed = '';
            foreach (self::chunks($item['text']) as $chunk) {
                $hash = self::hash($locale, $voicename, $item['speed'], $chunk);
                if (isset($seen[$hash])) {
                    continue;
                }
                $seen[$hash] = true;
                if (isset($have[$hash . '.mp3'])) {
                    continue;
                }
                if (isset($failed[$hash])) {
                    // Failed for good (409/410/413/422): never re-sent automatically; a teacher can retry it.
                    $blocked[$hash] = $failed[$hash];
                    $itemfailed = $failed[$hash];
                } else {
                    $missing++;
                    $itemmissing[] = $hash;
                }
            }
            if ($itemfailed !== '') {
                // A card with a failed clip can never play (no partial playback), so its other missing clips are not
                // paid for either; they are created when a teacher retries the card.
                foreach ($itemmissing as $hash) {
                    $blocked[$hash] = $itemfailed;
                }
                $missing = 0;
            }
            $items[] = [$item, $missing];
        }
        $total = count($seen);
        $ready = $total - array_sum(array_column($items, 1)) - count($blocked);
        return ['total' => $total, 'ready' => $ready, 'blocked' => count($blocked), 'items' => $items,
            'blockedcode' => $blocked ? array_search(max(array_count_values($blocked)), array_count_values($blocked)) : ''];
    }

    /**
     * Prepares the voiceover of an activity before students need it: generates missing clips (paid with LMS Labs
     * credits, 1 per clip) for up to $budget seconds. Safe to call from several places at once: every clip uses the
     * persisted job and Idempotency-Key, so a clip is never paid twice.
     *
     * @param stdClass $instance
     * @param \context_module $context
     * @param int $budget seconds of generation (0 = only report)
     * @return array ['state' => off|ready|incomplete|preparing|failed, 'total', 'ready', 'blocked', 'retryafter',
     *               'error' => string code]
     */
    public static function prepare(stdClass $instance, \context_module $context, int $budget): array {
        global $DB;
        if (!self::enabled($instance)) {
            return ['state' => 'off', 'total' => 0, 'ready' => 0, 'blocked' => 0, 'retryafter' => 0, 'error' => ''];
        }
        $status = self::status($instance, $context);
        $error = (string)($instance->voiceerror ?? '');
        $retry = 0;
        $deadline = time() + $budget;
        $todo = $status['total'] - $status['ready'] - $status['blocked'];
        if ($budget > 0 && $todo > 0) {
            $error = '';
            foreach ($status['items'] as [$item, $missing]) {
                if (!$missing) {
                    continue;
                }
                if (time() >= $deadline) {
                    break;
                }
                try {
                    $r = self::speak($instance, $context, $item['text'], $item['sig'], $item['speed'], true);
                    if ($r['pending']) {
                        $retry = max($retry, (int)$r['retryafter']);
                    }
                } catch (moodle_exception $e) {
                    if (in_array($e->errorcode, self::STOPPERS, true)) {
                        // Nothing more can be generated until the site's LMS Labs setup changes.
                        $error = $e->errorcode;
                        break;
                    }
                    // One text failed (409/410/413/422 leave a marker; others are retried later): go on.
                }
            }
            $status = self::status($instance, $context);
        }
        if ($status['ready'] >= $status['total']) {
            $state = 'ready';
            $error = '';
        } else if (in_array($error, self::STOPPERS, true)) {
            $state = 'failed';
        } else if ($status['ready'] + $status['blocked'] >= $status['total']) {
            // Everything that can be created exists; the rest failed for good. Stop here (no endless retries):
            // students get the voiceover that exists, and the teacher sees why the rest is missing.
            $state = 'incomplete';
            $error = $status['blockedcode'];
        } else {
            $state = 'preparing';
            $error = in_array($error, self::STOPPERS, true) ? $error : '';
        }
        if ($budget > 0 && (string)($instance->voiceerror ?? '') !== $error) {
            $DB->set_field('aianatomy', 'voiceerror', $error, ['id' => $instance->id]);
            $instance->voiceerror = $error;
        }
        return ['state' => $state, 'total' => $status['total'], 'ready' => $status['ready'],
            'blocked' => $status['blocked'], 'retryafter' => $retry, 'error' => $error];
    }

    /**
     * Queues background preparation of an activity's voiceover (after it is created or its texts change).
     *
     * @param stdClass $instance
     */
    public static function queue(stdClass $instance): void {
        global $DB;
        if (!self::enabled($instance)) {
            return;
        }
        if ((string)($instance->voiceerror ?? '') !== '') {
            // Something changed (texts, settings): try again; the reason is recorded again if it still applies.
            $DB->set_field('aianatomy', 'voiceerror', '', ['id' => $instance->id]);
        }
        $task = new \mod_aianatomy\task\prepare_voice();
        $task->set_custom_data(['instanceid' => (int)$instance->id]);
        \core\task\manager::queue_adhoc_task($task, true);
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
     * @param array $related related structures [['type', 'name']] (shown on the card when ticked)
     * @return string
     */
    public static function card_text(string $name, string $latin, array $content, array $related = []): string {
        $parts = [rtrim(self::plain($name), '.') . '.'];
        if ($latin !== '' && self::plain($latin) !== self::plain($name)) {
            $parts[] = rtrim(self::plain($latin), '.') . '.';
        }
        if (!empty($content['pronunciation'])) {
            // The card shows a respelling (BRONG-kee-al tree) for reading. Spoken aloud a respelling sounds wrong, so the
            // voice says the real term instead, which the voice pronounces correctly.
            $parts[] = get_string('voice_sayit', 'mod_aianatomy', self::spoken_term($name, $content['pronunciation'])) . '.';
        }
        foreach (self::CARDFIELDS as $f) {
            if (!empty($content[$f])) {
                $parts[] = get_string('field_' . $f, 'mod_aianatomy') . ': ' . rtrim(self::plain($content[$f]), '.') . '.';
            }
        }
        foreach (self::related_groups($related) as $label => $list) {
            $parts[] = $label . ': ' . self::plain(implode(', ', $list)) . '.';
        }
        return self::speakable(implode(' ', $parts));
    }

    /** Capitalised memory words that are said as words, not letter by letter (text on screen is unchanged). */
    const SPOKENWORDS = ['WET' => 'wet', 'BED' => 'bed', 'VAN' => 'van', 'SITS' => 'sits', 'SAIL' => 'sail', 'TAP' => 'tap',
        'GORD' => 'gord', 'UM' => 'um', 'OK' => 'okay', 'MILC' => 'milk'];

    /**
     * Text as the voice should hear it. Capital letters are read out one by one (BRONG becomes B, R, O, N, G), so
     * respellings such as SKAF-oyd are lowered, and capitalised memory words are said as words. Real acronyms
     * (ECG, CPR, LAD) stay spelt out.
     *
     * @param string $text
     * @return string
     */
    public static function speakable(string $text): string {
        $text = preg_replace_callback(
            '/\b[A-Za-z]+(?:-[A-Za-z]+)+\b/u',
            fn($m) => preg_match('/(^|-)[A-Z]{2,}(-|$)/', $m[0]) ? strtolower($m[0]) : $m[0],
            $text
        );
        return preg_replace_callback(
            '/\b(' . implode('|', array_keys(self::SPOKENWORDS)) . ')\b/',
            fn($m) => self::SPOKENWORDS[$m[1]],
            $text
        );
    }

    /**
     * The words of the name that a pronunciation respelling covers, so the voice can say them as real words.
     * The respelling is matched to the run of name words that sounds most like it: "Right bronchial tree" with
     * "BRONG-kee-al tree" gives "bronchial tree", "Middle lobe of right lung" with "LOBE" gives "lobe". When nothing
     * matches well, the whole name is used.
     *
     * @param string $name display name
     * @param string $pronunciation respelling shown on the card
     * @return string
     */
    public static function spoken_term(string $name, string $pronunciation): string {
        $strip = fn($t) => trim(preg_replace('/\s*\([^)]*\)/u', '', self::plain($t)));
        $name = rtrim($strip($name), '.');
        // Only the main respelling: "KOH-lon; SEE-kum" and "FAL-anks (plural: ...)" keep the first part.
        $respelled = $strip(preg_split('/[;,]/u', $pronunciation)[0]);
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = count(preg_split('/\s+/u', $respelled, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($count < 1 || $count >= count($words)) {
            return $name;
        }
        $target = self::sound($respelled);
        $best = null;
        $bestscore = 1.0;
        for ($i = 0; $i + $count <= count($words); $i++) {
            $run = implode(' ', array_slice($words, $i, $count));
            $sound = self::sound($run);
            $longest = max(strlen($sound), strlen($target), 1);
            $score = levenshtein($sound, $target) / $longest;
            if ($score < $bestscore) {
                $bestscore = $score;
                $best = $run;
            }
        }
        return ($best !== null && $bestscore <= 0.5) ? $best : $name;
    }

    /**
     * Rough consonant sound of Latin-script text, for matching a respelling to the words it spells.
     *
     * @param string $text
     * @return string
     */
    protected static function sound(string $text): string {
        $t = \core_text::strtolower(\core_text::specialtoascii($text));
        $t = preg_replace('/[^a-z]/', '', $t);
        $t = strtr($t, ['ph' => 'f', 'ch' => 'k', 'ck' => 'k', 'qu' => 'kw', 'x' => 'ks', 'c' => 'k', 'q' => 'k',
            'z' => 's', 'ng' => 'n', 'th' => 't', 'gh' => 'g', 'wh' => 'w', 'j' => 'g']);
        $t = preg_replace('/[aeiouyhw]/', '', $t);
        return preg_replace('/(.)\1+/', '$1', $t);
    }

    /**
     * Related structures grouped by relationship type, in pack order: label => names.
     *
     * @param array $related [['type', 'name']]
     * @return array
     */
    public static function related_groups(array $related): array {
        $out = [];
        $sm = get_string_manager();
        foreach ($related as $r) {
            $label = $sm->string_exists('rel_' . $r['type'], 'mod_aianatomy') ? get_string('rel_' . $r['type'], 'mod_aianatomy')
                : get_string('field_relationships', 'mod_aianatomy');
            $out[$label][] = $r['name'];
        }
        return $out;
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
