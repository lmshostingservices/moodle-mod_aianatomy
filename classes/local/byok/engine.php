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

namespace mod_aianatomy\local\byok;

/**
 * The LMS Labs AI Anatomy routes, ported to run on the site's own AI.
 *
 * POST /api/moodle/ai-anatomy/text and /api/moodle/ai-anatomy/speech/tts (server/aiAnatomy.ts and aiAnatomyText.ts):
 * the request checks, the extra instruction and response schema the service adds, and its checks of the AI's answer.
 * Only the calls to the AI go to the site's own AI, through the hooks below. The text route's behaviour was compared
 * with the service's code on generated requests and AI answers.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {
    /** @var callable|null fn(string $system, string $user, int $maxtokens, float $temperature, array $schema): string */
    public static $text = null;
    /** @var callable|null fn(string $text, string $language, string $voice, float $rate): string (MP3 bytes) */
    public static $speech = null;

    /** @var string[] Card fields, in the service's order. */
    const FIELDS = ['latin', 'pronunciation', 'origin', 'location', 'description', 'function', 'mnemonic', 'clinical', 'hint'];
    /** @var string[] Operations of the text route. */
    const OPERATIONS = ['content', 'questions', 'translation', 'grouptranslation'];
    /** @var string[] Question kinds. */
    const KINDS = ['function', 'location', 'relationship', 'terminology', 'clinical'];
    /** @var string The model the service pins (a request may name only this one). */
    const MODEL = 'gpt-4o-2024-08-06';
    /** @var int Largest request and result, in UTF-8 bytes of their JSON. */
    const MAX_BYTES = 32768;
    /** @var string[] The short Chirp 3 HD voice names. */
    const SHORT_VOICES = ['Aoede', 'Kore', 'Leda', 'Zephyr', 'Charon', 'Fenrir', 'Orus', 'Puck'];
    /** @var string[] Locales whose Google voices carry another language code. */
    const TTS_CODES = ['zh-CN' => 'cmn-CN', 'zh-HK' => 'yue-HK'];

    /**
     * Run the text route on the site's own AI.
     *
     * @param string $json The request body the plugin sends to LMS Labs.
     * @return array [HTTP status, the validated result as JSON (status 200) or an error code]
     */
    public static function text(string $json): array {
        try {
            $input = js::parse($json);
        } catch (\Throwable $e) {
            return [422, 'INVALID_JSON'];
        }
        if (strlen(js::stringify($input)) > self::MAX_BYTES) {
            return [413, 'BODY_TOO_LARGE'];
        }
        if (!self::valid_text_input($input)) {
            return [422, 'INVALID_INPUT'];
        }
        $op = $input->metadata->operation;
        $system = [];
        $user = [];
        foreach ($input->messages as $message) {
            if ($message->role === 'system') {
                $system[] = $message->content;
            } else {
                $user[] = $message->content;
            }
        }
        $system[] = "Operation: {$op}. Return usable plain text, no markup. Questions: exactly three, four distinct options " .
            'each, answer is zero-based. Translation: preserve question refs and order AND option order; preserve empty ' .
            'source fields. Structured source is authoritative: ' .
            js::stringify(property_exists($input, 'source') ? $input->source : null);
        try {
            $schema = ['name' => 'anatomy', 'schema' => self::response_schema($input)];
            $answer = (self::$text)(implode("\n\n", $system), implode("\n\n", $user), 4096, 0.3, $schema);
            $output = self::validate_result($input, js::parse($answer));
        } catch (\Throwable $e) {
            return [502, $e->getMessage()];
        }
        return [200, js::stringify($output)];
    }

    /**
     * Run the speech route on the site's own AI.
     *
     * @param string $json The request body the plugin sends to LMS Labs.
     * @return array [HTTP status, MP3 bytes (status 200) or an error code]
     */
    public static function speech(string $json): array {
        try {
            $input = js::parse($json);
        } catch (\Throwable $e) {
            return [422, 'INVALID_JSON'];
        }
        if (
            !self::strict($input, ['text', 'locale', 'voice', 'speed']) || !is_string($input->text)
            || js::trim($input->text) === '' || mb_strlen($input->text, 'UTF-8') > 200
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $input->text)
            || !is_string($input->locale) || js::len($input->locale) < 2 || js::len($input->locale) > 32
            || !is_string($input->voice) || js::len($input->voice) < 1 || js::len($input->voice) > 150
            || !in_array($input->speed, ['normal', 'slow'], true)
        ) {
            return [422, 'INVALID_INPUT'];
        }
        $language = self::TTS_CODES[$input->locale] ?? $input->locale;
        $voice = in_array($input->voice, self::SHORT_VOICES, true) ? "{$language}-Chirp3-HD-{$input->voice}" : $input->voice;
        try {
            $audio = (self::$speech)($input->text, $language, $voice, $input->speed === 'slow' ? 0.8 : 1.0);
        } catch (\Throwable $e) {
            return [502, $e->getMessage()];
        }
        if ($audio === '' || strlen($audio) > 1024 * 1024) {
            return [502, 'INVALID_AUDIO'];
        }
        return [200, $audio];
    }

    /**
     * Whether a value is an object with all required keys and no others (a strict zod object).
     *
     * @param mixed $o
     * @param string[] $required
     * @param string[] $optional
     * @return bool
     */
    protected static function strict($o, array $required, array $optional = []): bool {
        if (!($o instanceof \stdClass)) {
            return false;
        }
        $keys = array_map('strval', array_keys(get_object_vars($o)));
        return !array_diff($keys, $required, $optional) && !array_diff($required, $keys);
    }

    /**
     * A plain text field: at most 1600 characters, no angle brackets or control characters.
     *
     * @param mixed $v
     * @return bool
     */
    protected static function plain($v): bool {
        return is_string($v) && js::len($v) <= 1600 && !preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/', $v);
    }

    /**
     * A required text field: plain and not blank.
     *
     * @param mixed $v
     * @return bool
     */
    protected static function required($v): bool {
        return self::plain($v) && js::trim($v) !== '';
    }

    /**
     * Four distinct answer options.
     *
     * @param mixed $v
     * @return bool
     */
    protected static function options($v): bool {
        if (!is_array($v) || count($v) !== 4) {
            return false;
        }
        foreach ($v as $o) {
            if (!self::required($o)) {
                return false;
            }
        }
        return count(array_unique(array_map(fn($o) => js::lower(js::trim($o)), $v))) === 4;
    }

    /**
     * Whether a whole number is in range (z.number().int()).
     *
     * @param mixed $n
     * @param int|float $min
     * @param int|float $max
     * @return bool
     */
    protected static function int($n, $min, $max): bool {
        return js::is_integer($n) && $n >= $min && $n <= $max;
    }

    /**
     * The service's translation source, shaped as its schema returns it; null when it does not pass.
     *
     * @param mixed $v
     * @return \stdClass|null
     */
    protected static function translation($v): ?\stdClass {
        if (
            !self::strict($v, ['name', 'synonyms', 'content', 'questions']) || !self::required($v->name)
            || !is_array($v->synonyms) || count($v->synonyms) > 20 || !self::strict($v->content, [], self::FIELDS)
            || !is_array($v->questions) || count($v->questions) > 10
        ) {
            return null;
        }
        foreach ($v->synonyms as $s) {
            if (!self::required($s)) {
                return null;
            }
        }
        $content = new \stdClass();
        foreach (self::FIELDS as $field) {
            if (property_exists($v->content, $field)) {
                if (!self::plain($v->content->$field)) {
                    return null;
                }
                $content->$field = $v->content->$field;
            }
        }
        $questions = [];
        foreach ($v->questions as $q) {
            if (
                !self::strict($q, ['ref', 'text', 'options', 'explanation']) || !self::int($q->ref, 1, INF)
                || !self::required($q->text) || !self::options($q->options) || !self::plain($q->explanation)
            ) {
                return null;
            }
            $questions[] = (object) ['ref' => $q->ref, 'text' => $q->text, 'options' => $q->options,
                'explanation' => $q->explanation];
        }
        return (object) ['name' => $v->name, 'synonyms' => $v->synonyms, 'content' => $content, 'questions' => $questions];
    }

    /**
     * The service's group translation source; null when it does not pass.
     *
     * @param mixed $v
     * @return \stdClass|null
     */
    protected static function groups($v): ?\stdClass {
        if (!($v instanceof \stdClass)) {
            return null;
        }
        $keys = js::keys($v);
        if (!$keys || count($keys) > 50) {
            return null;
        }
        $out = new \stdClass();
        foreach ($keys as $key) {
            $g = $v->$key;
            if (
                !preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $key) || !self::strict($g, ['label', 'tip'])
                || !self::required($g->label) || !self::plain($g->tip)
            ) {
                return null;
            }
            $out->$key = (object) ['label' => $g->label, 'tip' => $g->tip];
        }
        return $out;
    }

    /**
     * Whether a text request passes the service's input schema (anatomyTextInput).
     *
     * @param mixed $v
     * @return bool
     */
    public static function valid_text_input($v): bool {
        if (!self::strict($v, ['messages', 'metadata'], ['source', 'model', 'temperature', 'response_format'])) {
            return false;
        }
        if (!is_array($v->messages) || count($v->messages) < 1 || count($v->messages) > 8) {
            return false;
        }
        $chars = 0;
        foreach ($v->messages as $m) {
            if (
                !self::strict($m, ['role', 'content']) || !in_array($m->role, ['system', 'user'], true)
                || !is_string($m->content) || js::len($m->content) < 1 || js::len($m->content) > 12000
            ) {
                return false;
            }
            $chars += mb_strlen($m->content, 'UTF-8');
        }
        $meta = $v->metadata;
        if (
            !self::strict($meta, ['plugin', 'operation'], ['siteid', 'contextid']) || $meta->plugin !== 'mod_aianatomy'
            || !in_array($meta->operation, self::OPERATIONS, true)
            || (property_exists($meta, 'siteid') && (!is_string($meta->siteid) || js::len($meta->siteid) > 512))
            || (property_exists($meta, 'contextid') && !self::int($meta->contextid, 1, INF))
        ) {
            return false;
        }
        if (property_exists($v, 'model') && $v->model !== self::MODEL) {
            return false;
        }
        if (
            property_exists($v, 'temperature')
            && (!(is_int($v->temperature) || is_float($v->temperature)) || $v->temperature < 0 || $v->temperature > 1)
        ) {
            return false;
        }
        if (
            property_exists($v, 'response_format')
            && (!self::strict($v->response_format, ['type']) || $v->response_format->type !== 'json_object')
        ) {
            return false;
        }
        if ($chars > 12000) {
            return false;
        }
        $op = $meta->operation;
        $source = property_exists($v, 'source');
        if ($op === 'translation') {
            $t = $source ? self::translation($v->source) : null;
            if (!$t) {
                return false;
            }
            $refs = array_map(fn($q) => js::stringify($q->ref), $t->questions);
            if (count(array_unique($refs)) !== count($refs)) {
                return false;
            }
        } else if ($op === 'grouptranslation') {
            if (!$source || !self::groups($v->source)) {
                return false;
            }
        } else if ($source) {
            return false;
        }
        return true;
    }

    /**
     * The response schema the service gives the AI (anatomyResponseSchema).
     *
     * @param \stdClass $input
     * @return array
     */
    public static function response_schema(\stdClass $input): array {
        $str = ['type' => 'string'];
        $obj = fn(array $properties) => ['type' => 'object', 'properties' => $properties ?: new \stdClass(),
            'required' => array_map('strval', array_keys($properties)), 'additionalProperties' => false];
        $arr = fn($items) => ['type' => 'array', 'items' => $items];
        switch ($input->metadata->operation) {
            case 'content':
                return $obj(array_fill_keys(self::FIELDS, $str));
            case 'questions':
                return $obj(['questions' => $arr($obj([
                    'kind' => ['type' => 'string', 'enum' => self::KINDS],
                    'text' => $str, 'options' => $arr($str), 'answer' => ['type' => 'integer'], 'explanation' => $str,
                ]))]);
            case 'translation':
                $content = array_keys(get_object_vars(self::translation($input->source)->content));
                return $obj(['name' => $str, 'synonyms' => $arr($str), 'content' => $obj(array_fill_keys($content, $str)),
                    'questions' => $arr($obj(['ref' => ['type' => 'integer'], 'text' => $str, 'options' => $arr($str),
                    'explanation' => $str]))]);
            default:
                $groups = [];
                foreach (js::keys($input->source) as $key) {
                    $groups[$key] = $obj(['label' => $str, 'tip' => $str]);
                }
                return $obj($groups);
        }
    }

    /**
     * Check the AI's answer as the service does (validateAnatomyResult).
     *
     * @param \stdClass $input
     * @param mixed $value
     * @return \stdClass The answer, shaped as the service's schema returns it.
     */
    public static function validate_result(\stdClass $input, $value): \stdClass {
        $op = $input->metadata->operation;
        $fail = function (string $code) {
            throw new \RuntimeException($code);
        };
        if ($op === 'content') {
            if (!self::strict($value, self::FIELDS)) {
                $fail('INVALID_PROVIDER_RESULT');
            }
            $out = new \stdClass();
            foreach (self::FIELDS as $field) {
                if (!self::plain($value->$field)) {
                    $fail('INVALID_PROVIDER_RESULT');
                }
                $out->$field = $value->$field;
            }
            if (js::trim($out->description) === '') {
                $fail('EMPTY_CONTENT');
            }
        } else if ($op === 'questions') {
            if (!self::strict($value, ['questions']) || !is_array($value->questions) || count($value->questions) !== 3) {
                $fail('INVALID_PROVIDER_RESULT');
            }
            $questions = [];
            foreach ($value->questions as $q) {
                if (
                    !self::strict($q, ['kind', 'text', 'options', 'answer', 'explanation'])
                    || !in_array($q->kind, self::KINDS, true) || !self::required($q->text) || !self::options($q->options)
                    || !self::int($q->answer, 0, 3) || !self::required($q->explanation)
                ) {
                    $fail('INVALID_PROVIDER_RESULT');
                }
                $questions[] = (object) ['kind' => $q->kind, 'text' => $q->text, 'options' => $q->options,
                    'answer' => $q->answer, 'explanation' => $q->explanation];
            }
            $out = (object) ['questions' => $questions];
        } else if ($op === 'translation') {
            $out = self::translation($value);
            if (!$out) {
                $fail('INVALID_PROVIDER_RESULT');
            }
            $src = self::translation($input->source);
            $srckeys = array_keys(get_object_vars($src->content));
            $outkeys = array_keys(get_object_vars($out->content));
            $filled = fn(\stdClass $c, string $k) => isset($c->$k) && js::trim($c->$k) !== '';
            $mismatch = count($out->synonyms) !== count($src->synonyms) || count($out->questions) !== count($src->questions)
                || count($srckeys) !== count($outkeys) || array_diff($srckeys, $outkeys);
            foreach ($out->questions as $i => $q) {
                $mismatch = $mismatch || ($src->questions[$i] ?? null) === null || $q->ref != $src->questions[$i]->ref
                    || (js::trim($q->explanation) !== '') !== (js::trim($src->questions[$i]->explanation) !== '');
            }
            foreach (self::FIELDS as $field) {
                $mismatch = $mismatch || $filled($src->content, $field) !== $filled($out->content, $field);
            }
            if ($mismatch) {
                $fail('TRANSLATION_SHAPE_MISMATCH');
            }
        } else {
            $out = self::groups($value);
            if (!$out) {
                $fail('INVALID_PROVIDER_RESULT');
            }
            $src = self::groups($input->source);
            $srckeys = js::keys($src);
            $mismatch = count($srckeys) !== count(js::keys($out));
            foreach ($srckeys as $key) {
                $mismatch = $mismatch || !property_exists($out, $key)
                    || (js::trim($src->$key->tip) !== '') !== (js::trim($out->$key->tip) !== '');
            }
            if ($mismatch) {
                $fail('GROUP_KEYS_MISMATCH');
            }
        }
        if (strlen(js::stringify($out)) > self::MAX_BYTES) {
            $fail('RESULT_TOO_LARGE');
        }
        return $out;
    }
}
