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

use mod_aianatomy\local\language;
use mod_aianatomy\local\manager;
use mod_aianatomy\local\pack;
use moodle_exception;
use stdClass;

/**
 * AI authoring assistant.
 *
 * AI is never the source of anatomical truth: the structure's identity, names, group and
 * relationships come from the verified anatomy pack and are passed into every prompt. AI only drafts
 * teaching text and questions, which are stored as drafts until a teacher approves them.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generator {
    /** One writing style for every learner (the same as the built-in library content). */
    public const STYLE = 'Write for every learner, from school and vocational students to nurses and university students: ' .
        'simple, clear and interesting, like a friendly tutor talking to them, never like a medical textbook. ' .
        'Use everyday words; when an anatomical term matters, explain it in plain words in the same sentence. ' .
        'Help it stick: link to the learner\'s own body (where they can feel it, what it lets them do), use an ' .
        'everyday comparison or a vivid real-life fact. Use active verbs and "you" where natural. ' .
        'The text is read aloud by a voiceover: short sentences of about 8 to 18 words, 1 or 2 sentences per field, ' .
        'no abbreviations or symbols (no e.g., i.e., etc., arrows, =, +, /, &, semicolons, per cent signs), ' .
        'no brackets, small numbers as words. Keep every fact correct.';

    /**
     * The LMS Labs provider when it is configured (endpoint plus a complete credential pair), else null.
     * LMS Labs AI credits are the only way to generate content.
     *
     * @return provider|null
     */
    public static function provider(): ?provider {
        $p = new provider_lmslabs();
        return $p->is_ready() ? $p : null;
    }

    /**
     * Status for the editor UI.
     *
     * @return array
     */
    public static function status(): array {
        $p = self::provider();
        return ['ready' => (bool)$p, 'name' => $p ? $p->get_model_name() : ''];
    }

    /**
     * System instructions.
     *
     * @return string
     */
    public static function system_prompt(): string {
        return 'You are an anatomy and physiology educator writing short teaching content for an interactive 3D ' .
            'anatomy activity. Accuracy matters more than creativity. Only state well-established anatomical facts ' .
            '(Terminologia Anatomica naming). Never invent structures, attachments, nerves or relationships; if you ' .
            'are not sure of a fact, leave that field empty. Use plain text only: no HTML, no Markdown, no emojis. ' .
            self::STYLE . ' ' .
            'Reply with a single JSON object and nothing else.';
    }

    /**
     * Output language instruction for the activity language.
     *
     * @param stdClass $instance
     * @return string empty for English
     */
    public static function language_line(stdClass $instance): string {
        $code = language::normalise($instance->language ?? 'en');
        if (language::same($code, 'en')) {
            return '';
        }
        $name = language::english_name($code);
        return "Language: write every text value in {$name}, using the anatomical and medical terms normally taught " .
            "in {$name}-language health education. Keep the Latin (TA) name in Latin. For \"pronunciation\", give a " .
            "simple pronunciation guide for {$name} speakers.\n\n";
    }

    /**
     * Verified facts about a structure, taken from the pack (never from AI).
     *
     * @param stdClass $instance
     * @param string $structureid
     * @return string
     */
    public static function facts(stdClass $instance, string $structureid): string {
        $pack = pack::get($instance->pack);
        $s = $pack['byid'][$structureid] ?? null;
        if (!$s) {
            throw new moodle_exception('invalidstructure', 'mod_aianatomy');
        }
        $group = $pack['groupbyid'][$s['group']]['label'] ?? $s['group'];
        $related = [];
        foreach ($s['relationships'] as $r) {
            if (isset($pack['byid'][$r['target']])) {
                $related[] = str_replace('_', ' ', $r['type']) . ' ' . $pack['byid'][$r['target']]['names']['preferred'];
            }
        }
        $lines = [
            'Structure id: ' . $s['id'],
            'Preferred name: ' . $s['names']['preferred'],
            'Latin (TA) name: ' . ($s['names']['latin'] ?? ''),
            'Structure type: ' . $s['type'],
            'System: ' . $pack['system'],
            'Region: ' . str_replace('_', ' ', $pack['region']),
            'Group: ' . $group,
            'Side: ' . $s['side'],
            'Verified relationships: ' . ($related ? implode('; ', $related) : 'none listed'),
            'Other structures in the same group: ' . implode(', ', pack::sibling_names($pack, $structureid)),
        ];
        foreach ($s['ontology'] ?? [] as $o) {
            $lines[] = 'Ontology: ' . $o['system'] . ' ' . $o['id'];
        }
        return implode("\n", $lines);
    }

    /**
     * Prompt for teaching content.
     *
     * @param stdClass $instance
     * @param string $structureid
     * @return string
     */
    public static function content_prompt(stdClass $instance, string $structureid): string {
        return "Write teaching content for this anatomical structure.\n\n" .
            self::facts($instance, $structureid) . "\n\n" .
            "Style: " . self::STYLE . "\n\n" .
            self::language_line($instance) .
            "Return JSON with exactly these keys (each a plain-text string of 1 or 2 short sentences, at most 220 " .
            "characters, unless noted):\n" .
            "{\n" .
            "  \"latin\": \"Terminologia Anatomica Latin name only\",\n" .
            "  \"pronunciation\": \"simple sound-it-out spelling with the stressed syllable in capitals, like SKAF-oyd\",\n" .
            "  \"origin\": \"where the name comes from, as a tiny story: the Greek or Latin word and what it means\",\n" .
            "  \"location\": \"where to find it, using landmarks the learner knows or can feel on their own body\",\n" .
            "  \"description\": \"what it looks like, with one good everyday comparison\",\n" .
            "  \"function\": \"what it does for you, in active voice\",\n" .
            "  \"mnemonic\": \"a short, memorable, respectful memory trick suitable for school students\",\n" .
            "  \"clinical\": \"why it matters in real life: a common injury, condition or everyday situation, " .
            "explained simply\",\n" .
            "  \"hint\": \"one sentence that helps find it on a 3D model without naming it\"\n" .
            "}";
    }

    /**
     * Prompt for knowledge questions.
     *
     * @param stdClass $instance
     * @param string $structureid
     * @param int $count
     * @return string
     */
    public static function questions_prompt(stdClass $instance, string $structureid, int $count = 3): string {
        global $DB;
        $row = $DB->get_record(
            'aianatomy_structure', ['aianatomyid' => $instance->id, 'structureid' => $structureid],
            '*', MUST_EXIST
        );
        $approved = [];
        foreach (manager::content($row) as $k => $v) {
            if ($v !== '') {
                $approved[] = "{$k}: {$v}";
            }
        }
        return "Write {$count} multiple-choice questions about this anatomical structure.\n\n" .
            self::facts($instance, $structureid) . "\n\n" .
            "Teacher-approved teaching content (base every question ONLY on these facts):\n" .
            implode("\n", $approved) . "\n\n" .
            "Style: " . self::STYLE . "\n" .
            "Rules: 4 options each, exactly one correct; use other structures from the same group as distractors " .
            "where it makes sense; mix question kinds (function, location, relationship, terminology, clinical); " .
            "no 'all of the above'. Question at most 140 characters, each option at most 60 characters, and a " .
            "friendly explanation of 1 or 2 short sentences saying why the answer is right.\n\n" .
            self::language_line($instance) .
            "Return JSON: {\"questions\": [{\"kind\": \"function|location|relationship|terminology|clinical\", " .
            "\"text\": \"...\", \"options\": [\"...\", \"...\", \"...\", \"...\"], \"answer\": 0, " .
            "\"explanation\": \"...\"}]}";
    }

    /**
     * Extracts a JSON object from model output (tolerates code fences and surrounding text).
     *
     * @param string $text
     * @return array
     */
    public static function parse_json(string $text): array {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $m)) {
            $text = trim($m[1]);
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $data = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }
        if (!is_array($data)) {
            throw new moodle_exception('aibadjson', 'mod_aianatomy');
        }
        return $data;
    }

    /**
     * Runs (or resumes) one LMS Labs text job.
     *
     * A job that is already in progress for this operation and structure is resumed with its original key and
     * body. The result is handled by $onresult (which stores it in Moodle) before the job is removed.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param string $operation
     * @param string $target structure id or _groups
     * @param callable $build fn(): array [system, prompt, source|null, meta|null] for a new job
     * @param callable $onresult fn(string $text, array $meta): array
     * @return array ['pending' => false] + $onresult's result + credits/balance, or ['pending' => true, 'retryafter']
     */
    protected static function run(stdClass $instance, \context $context, string $operation, string $target,
            callable $build, callable $onresult): array {
        $provider = self::provider();
        if (!$provider) {
            throw new moodle_exception('ainotconfigured', 'mod_aianatomy');
        }
        $job = jobs::start(
            (int)$instance->id, $operation, $target, function () use ($build, $provider, $operation, $context) {
                [$system, $prompt, $source, $meta] = $build();
                return [$provider->body($operation, $system, $prompt, $source, $context), $meta];
            }
        );
        try {
            $result = $provider->request($job);
        } catch (moodle_exception $e) {
            // Terminal status: a later click is a new, intentional request with a new key.
            jobs::finish($job);
            throw $e;
        }
        if ($result['state'] === 'pending') {
            jobs::wait($job, $result['retryafter']);
            return ['pending' => true, 'retryafter' => $result['retryafter']];
        }
        try {
            $out = $onresult($result['text'], json_decode((string)$job->meta, true) ?: []);
        } catch (moodle_exception $e) {
            if (in_array($e->errorcode, ['aibadjson', 'ainoquestions', 'aiemptyreply'], true)) {
                // The reply itself is unusable: replaying it would give the same result, so the key is retired.
                jobs::finish($job);
            }
            // Anything else (for example a database error while storing) keeps the job: the next attempt replays
            // the stored result with the same key within LMS Labs' 24-hour retention, without another debit.
            throw $e;
        }
        // The result is stored in Moodle; only now is the key retired.
        jobs::finish($job);
        self::log($context, $target === '_groups' ? '' : $target, $operation, $provider->get_model_name());
        return ['pending' => false, 'retryafter' => 0, 'credits' => $result['credits'], 'balance' => $result['balance']]
            + $out;
    }

    /**
     * Generates (or resumes) a content draft for one structure.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param string $structureid
     * @param int $userid
     * @return array ['pending' => bool, 'retryafter' => int, 'draft' => array]
     */
    public static function generate_content(stdClass $instance, \context $context, string $structureid, int $userid): array {
        return self::run(
            $instance, $context, 'content', $structureid,
            fn() => [self::system_prompt(), self::content_prompt($instance, $structureid), null, null],
            function (string $text) use ($instance, $structureid) {
                $draft = pack::clean_content(self::parse_json($text));
                manager::store_draft(
                    $instance, $structureid, $draft, 'LMS Labs',
                    language::normalise($instance->language ?? 'en')
                );
                return ['draft' => $draft];
            }
        );
    }

    /**
     * Generates (or resumes) three draft questions for one structure.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param string $structureid
     * @param int $userid
     * @return array ['pending' => bool, 'retryafter' => int, 'questions' => array]
     */
    public static function generate_questions(stdClass $instance, \context $context, string $structureid,
            int $userid): array {
        return self::run(
            $instance, $context, 'questions', $structureid,
            fn() => [self::system_prompt(), self::questions_prompt($instance, $structureid, 3), null, null],
            function (string $text) use ($instance, $structureid) {
                $data = self::parse_json($text);
                $stored = self::store_questions($instance, $structureid, $data['questions'] ?? []);
                if (!$stored) {
                    throw new moodle_exception('ainoquestions', 'mod_aianatomy');
                }
                return ['questions' => $stored];
            }
        );
    }

    /**
     * Stores AI questions as drafts, skipping invalid ones.
     *
     * @param stdClass $instance
     * @param string $structureid
     * @param array $questions
     * @return array exported questions
     */
    public static function store_questions(stdClass $instance, string $structureid, array $questions,
            ?string $lang = null): array {
        $out = [];
        $lang = $lang ?? language::normalise($instance->language ?? 'en');
        foreach (array_slice($questions, 0, 10) as $q) {
            if (!is_array($q)) {
                continue;
            }
            try {
                $out[] = manager::save_question(
                    $instance, [
                        'structureid' => $structureid,
                        'kind' => (string)($q['kind'] ?? 'function'),
                        'text' => (string)($q['text'] ?? ''),
                        'options' => (array)($q['options'] ?? []),
                        'answer' => (int)($q['answer'] ?? -1),
                        'explanation' => (string)($q['explanation'] ?? ''),
                        'status' => 'draft',
                        'lang' => $lang,
                    ], true
                );
            } catch (moodle_exception $e) {
                continue;
            }
        }
        return $out;
    }

    /**
     * Translation request for one structure: prompt, structured source and the source question ids by ref.
     *
     * Only student-visible questions in the content's language with exactly four distinct options are
     * translated (at most ten). The translated questions keep the source's kind and answer index.
     *
     * @param stdClass $instance
     * @param string $structureid
     * @return array [prompt, source, meta]
     */
    public static function translate_request(stdClass $instance, string $structureid): array {
        global $DB;
        $pack = pack::get($instance->pack);
        $s = $pack['byid'][$structureid] ?? null;
        if (!$s) {
            throw new moodle_exception('invalidstructure', 'mod_aianatomy');
        }
        $row = $DB->get_record(
            'aianatomy_structure', ['aianatomyid' => $instance->id, 'structureid' => $structureid],
            '*', MUST_EXIST
        );
        $target = language::normalise($instance->language ?? 'en');
        $from = language::english_name($row->contentlang ?: 'en');
        $to = language::english_name($target);
        $content = [];
        foreach (manager::content($row) as $k => $v) {
            if ($v !== '') {
                $content[$k] = $v;
            }
        }
        $questions = [];
        $refs = [];
        foreach (manager::get_questions($instance->id, true) as $q) {
            $options = array_values(json_decode($q->options, true) ?: []);
            if ($q->structureid !== $structureid || !language::same($q->lang ?? 'en', $row->contentlang ?: 'en')
                    || count($options) !== 4 || count(array_unique($options)) !== 4 || count($questions) >= 10) {
                continue;
            }
            $ref = count($questions) + 1;
            $questions[] = ['ref' => $ref, 'text' => $q->questiontext, 'options' => $options,
                'explanation' => (string)$q->explanation];
            $refs[$ref] = (int)$q->id;
        }
        $source = [
            'name' => manager::display_name($row, $s),
            'synonyms' => array_slice(array_values($s['names']['synonyms'] ?? []), 0, 20),
            'content' => (object)$content,
            'questions' => $questions,
        ];
        $prompt = "Translate this anatomy teaching material from {$from} into {$to}.\n\n" .
            "Keep the same simple, clear, friendly style (it is read aloud to learners): " . self::STYLE . "\n\n" .
            self::facts($instance, $structureid) . "\n\n" .
            "Rules: translate faithfully; do not add, remove or change any facts. Use the anatomical and medical " .
            "terms normally taught in {$to}-language health education. Keep Latin (TA) names in Latin. Translate " .
            "\"mnemonic\" so that it still works as a memory aid in {$to} (adapt it if a literal translation would " .
            "not work, but keep the same facts). For \"pronunciation\", give a simple pronunciation guide of the " .
            "translated name for {$to} speakers. Keep the same content keys, keep empty fields empty, keep every " .
            "question (same refs, same order) and keep each question's options in the same positions, so the same " .
            "option stays correct. Plain text only.\n\n" .
            "Source (also sent as the request's source):\n" . json_encode($source, JSON_UNESCAPED_UNICODE) . "\n\n" .
            "Return JSON: {\"name\": \"...\", \"synonyms\": [\"...\"], \"content\": {same keys as the source " .
            "content}, \"questions\": [{\"ref\": 1, \"text\": \"...\", \"options\": [\"...\"], \"explanation\": \"...\"}]}";
        return [$prompt, $source, ['refs' => $refs]];
    }

    /**
     * Translates (or resumes translating) one structure: a content draft with the translated name, and draft
     * questions in the activity language.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param string $structureid
     * @param int $userid
     * @return array ['pending' => bool, 'retryafter' => int, 'content' => array, 'name' => string, 'questions' => array]
     */
    public static function translate_structure(stdClass $instance, \context $context, string $structureid,
            int $userid): array {
        $target = language::normalise($instance->language ?? 'en');
        return self::run(
            $instance, $context, 'translation', $structureid,
            function () use ($instance, $structureid) {
                [$prompt, $source, $meta] = self::translate_request($instance, $structureid);
                return [self::system_prompt(), $prompt, $source, $meta];
            },
            function (string $text, array $meta) use ($instance, $structureid, $target) {
                global $DB;
                $data = self::parse_json($text);
                $draft = pack::clean_content(is_array($data['content'] ?? null) ? $data['content'] : []);
                $name = \core_text::substr(trim(clean_param(strip_tags((string)($data['name'] ?? '')), PARAM_TEXT)), 0, 255);
                manager::store_draft($instance, $structureid, $draft, 'LMS Labs', $target, $name);
                $new = [];
                foreach ((array)($data['questions'] ?? []) as $tq) {
                    $qid = is_array($tq) ? ($meta['refs'][(int)($tq['ref'] ?? 0)] ?? null) : null;
                    $src = $qid ? $DB->get_record('aianatomy_question', ['id' => $qid]) : null;
                    if (!$src) {
                        continue;
                    }
                    // The kind and the correct answer come from the source question, never from the reply.
                    $new[] = ['kind' => $src->kind, 'text' => (string)($tq['text'] ?? ''),
                        'options' => (array)($tq['options'] ?? []), 'answer' => (int)$src->answer,
                        'explanation' => (string)($tq['explanation'] ?? '')];
                }
                return ['content' => $draft, 'name' => $name,
                    'questions' => self::store_questions($instance, $structureid, $new, $target)];
            }
        );
    }

    /**
     * Translates (or resumes translating) the pack's group names, view names and study tips.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param int $userid
     * @return array ['pending' => bool, 'retryafter' => int, 'groups' => array groupid => [label, tip]]
     */
    public static function translate_groups(stdClass $instance, \context $context, int $userid): array {
        $pack = pack::get($instance->pack);
        return self::run(
            $instance, $context, 'grouptranslation', '_groups',
            function () use ($instance, $pack) {
                $to = language::english_name($instance->language ?? 'en');
                $source = [];
                foreach (manager::text_groups($pack) as $g) {
                    $source[$g['id']] = ['label' => $g['label'], 'tip' => $g['tip'] ?? ''];
                }
                $prompt = "Translate these anatomy group names, view names (ids starting with _preset_) and study " .
                    "tips from English into {$to}. Use the terms normally taught in {$to}-language health education; " .
                    "keep Latin names in Latin; adapt mnemonics so they still work in {$to} without changing the " .
                    "facts; keep every id exactly; keep empty tips empty. Plain text only.\n\n" .
                    "Pack: " . $pack['name'] . "\n" .
                    "Source (also sent as the request's source, keyed by id):\n" .
                    json_encode($source, JSON_UNESCAPED_UNICODE) .
                    "\n\nReturn JSON with the same keys: {\"<id>\": {\"label\": \"...\", \"tip\": \"...\"}}";
                return [self::system_prompt(), $prompt, $source, null];
            },
            function (string $text) use ($instance, $pack) {
                global $DB;
                $out = manager::clean_grouptext($pack, self::parse_json($text));
                $DB->set_field(
                    'aianatomy', 'grouptext', json_encode($out, JSON_UNESCAPED_UNICODE),
                    ['id' => $instance->id]
                );
                return ['groups' => $out];
            }
        );
    }

    /**
     * Logs AI generation (for audit of AI-authored teaching material).
     *
     * @param \context $context
     * @param string $structureid
     * @param string $what
     * @param string $model
     */
    protected static function log(\context $context, string $structureid, string $what, string $model): void {
        $event = \mod_aianatomy\event\content_generated::create(
            [
                'context' => $context,
                'other' => ['structureid' => $structureid, 'what' => $what, 'model' => $model],
            ]
        );
        $event->trigger();
    }
}
