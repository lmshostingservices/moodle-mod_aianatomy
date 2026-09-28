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
 * Core business logic: structures, teaching content, questions, attempts and mastery.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var int Seconds of grace allowed after a time limit expires (network latency). */
    public const TIME_GRACE = 30;

    /** @var string[] Label colour palette (readable with white text). */
    public const PALETTE = ['#4f46e5', '#0369a1', '#047857', '#b45309', '#b91c1c', '#be185d', '#7c3aed', '#0f766e',
        '#c2410c', '#4d7c0f', '#0e7490', '#a21caf'];

    /** @var string[] Statuses of live content that students can see. */
    public const VISIBLE_QUESTION = ['library', 'approved'];

    /**
     * Creates or refreshes the per-activity structure rows and library questions for the instance's pack.
     *
     * @param stdClass $instance
     */
    public static function sync_structures(stdClass $instance): void {
        global $DB;
        $pack = pack::get($instance->pack);
        $existing = $DB->get_records('aianatomy_structure', ['aianatomyid' => $instance->id], '', 'structureid, id');
        $fresh = !$existing;
        // New activities assess the pack's main regions (e.g. the whole hand); context-only groups stay off.
        $defaultgroups = $pack['defaultgroups'] ?? $pack['rootframe'] ?? (isset($pack['defaultgroup'])
            ? [$pack['defaultgroup']] : null);
        $now = time();
        foreach ($pack['structures'] as $i => $s) {
            if (isset($existing[$s['id']])) {
                $DB->set_field('aianatomy_structure', 'sortorder', $i, ['id' => $existing[$s['id']]->id]);
                unset($existing[$s['id']]);
                continue;
            }
            $enabled = $defaultgroups ? (int)in_array(pack::top_group($pack, $s['group']), $defaultgroups, true) : 1;
            $DB->insert_record(
                'aianatomy_structure', (object)[
                    'aianatomyid' => $instance->id,
                    'structureid' => $s['id'],
                    'enabled' => $enabled,
                    'sortorder' => $i,
                    'label' => '',
                    'anchor' => '',
                    'labelpos' => '',
                    'content' => json_encode(pack::library_content($s)),
                    'contentstatus' => 'library',
                    'contentlang' => 'en',
                    'draft' => null,
                    'draftlang' => '',
                    'aigenerated' => 0,
                    'aimodel' => '',
                    'timegenerated' => 0,
                    'approvedby' => 0,
                    'timeapproved' => 0,
                    'timemodified' => $now,
                ]
            );
            if ($fresh || !$DB->record_exists('aianatomy_question', ['aianatomyid' => $instance->id, 'structureid' => $s['id']])) {
                foreach ($s['questions'] ?? [] as $k => $q) {
                    $DB->insert_record(
                        'aianatomy_question', (object)[
                            'aianatomyid' => $instance->id,
                            'structureid' => $s['id'],
                            'sortorder' => $k,
                            'kind' => $q['kind'] ?? 'function',
                            'questiontext' => $q['text'],
                            'options' => json_encode(array_values($q['options'])),
                            'answer' => (int)$q['answer'],
                            'explanation' => $q['explanation'] ?? '',
                            'status' => 'library',
                            'lang' => 'en',
                            'aigenerated' => 0,
                            'timemodified' => $now,
                        ]
                    );
                }
            }
        }
        // Structures no longer in the pack (pack changed): remove them and their questions.
        foreach ($existing as $sid => $row) {
            $DB->delete_records('aianatomy_structure', ['id' => $row->id]);
            $DB->delete_records('aianatomy_question', ['aianatomyid' => $instance->id, 'structureid' => $sid]);
        }
    }

    /**
     * Structure rows of an instance, keyed by structure id, in pack order.
     *
     * @param int $instanceid
     * @return stdClass[]
     */
    public static function get_structures(int $instanceid): array {
        global $DB;
        $rows = $DB->get_records('aianatomy_structure', ['aianatomyid' => $instanceid], 'sortorder ASC, id ASC');
        $out = [];
        foreach ($rows as $r) {
            $out[$r->structureid] = $r;
        }
        return $out;
    }

    /**
     * Questions of an instance.
     *
     * @param int $instanceid
     * @param bool $visibleonly only questions students may see
     * @return stdClass[]
     */
    public static function get_questions(int $instanceid, bool $visibleonly = false): array {
        global $DB;
        $params = ['aid' => $instanceid];
        $where = 'aianatomyid = :aid';
        if ($visibleonly) {
            [$insql, $inparams] = $DB->get_in_or_equal(self::VISIBLE_QUESTION, SQL_PARAMS_NAMED);
            $where .= " AND status $insql";
            $params += $inparams;
        }
        return $DB->get_records_select('aianatomy_question', $where, $params, 'structureid, sortorder, id');
    }

    /**
     * Decoded live content of a structure row.
     *
     * @param stdClass $row
     * @return array
     */
    public static function content(stdClass $row): array {
        $c = json_decode((string)$row->content, true);
        return pack::clean_content(is_array($c) ? $c : []);
    }

    /**
     * Display name of a structure (teacher override or pack name).
     *
     * @param stdClass|null $row
     * @param array $structure pack structure
     * @return string
     */
    public static function display_name(?stdClass $row, array $structure): string {
        if ($row && trim($row->label) !== '') {
            return trim($row->label);
        }
        return $structure['names']['preferred'];
    }

    /**
     * Fields students see in Study.
     *
     * @param stdClass $instance
     * @return string[]
     */
    public static function studyfields(stdClass $instance): array {
        $fields = array_filter(array_map('trim', explode(',', (string)$instance->studyfields)));
        return $fields ?: pack::DEFAULT_STUDYFIELDS;
    }

    /**
     * Parses "x,y,z".
     *
     * @param string $value
     * @return float[]|null
     */
    public static function parse_anchor(string $value): ?array {
        $p = array_map('floatval', explode(',', $value));
        return count($p) === 3 && trim($value) !== '' ? $p : null;
    }

    /**
     * Parses "x,y".
     *
     * @param string $value
     * @return array|null
     */
    public static function parse_labelpos(string $value): ?array {
        $p = array_map('floatval', explode(',', $value));
        if (count($p) !== 2 || trim($value) === '') {
            return null;
        }
        return ['x' => max(0, min(1, $p[0])), 'y' => max(0, min(1, $p[1]))];
    }

    /**
     * Label colour for a structure index.
     *
     * @param int $index
     * @return string
     */
    public static function colour(int $index): string {
        return self::PALETTE[$index % count(self::PALETTE)];
    }

    /**
     * Keeps only valid translated group texts: {groupid: {label, tip}}.
     *
     * @param array $pack
     * @param array $data
     * @return array
     */
    public static function clean_grouptext(array $pack, array $data): array {
        $out = [];
        foreach (self::text_groups($pack) as $g) {
            $d = $data[$g['id']] ?? null;
            if (!is_array($d)) {
                continue;
            }
            $label = \core_text::substr(trim(clean_param(strip_tags((string)($d['label'] ?? '')), PARAM_TEXT)), 0, 255);
            $tip = \core_text::substr(trim(clean_param(strip_tags((string)($d['tip'] ?? '')), PARAM_TEXT)), 0, 1000);
            if ($label !== '' || $tip !== '') {
                $out[$g['id']] = ['label' => $label, 'tip' => $tip];
            }
        }
        return $out;
    }

    /**
     * Translatable groups of a pack: its groups, plus the view presets as "_preset_<id>" (label only).
     *
     * @param array $pack
     * @return array
     */
    public static function text_groups(array $pack): array {
        $out = $pack['groups'];
        foreach ($pack['presets'] ?? [] as $p) {
            $out[] = ['id' => '_preset_' . $p['id'], 'label' => $p['label'], 'tip' => ''];
        }
        return $out;
    }

    /**
     * Group label and tip in the activity language (translated text when present, else the pack's).
     *
     * @param stdClass $instance
     * @param array $group pack group
     * @return array [label, tip]
     */
    public static function group_text(stdClass $instance, array $group): array {
        static $cache = [];
        $key = $instance->id . ':' . md5((string)($instance->grouptext ?? ''));
        if (!isset($cache[$key])) {
            $cache[$key] = json_decode((string)($instance->grouptext ?? ''), true) ?: [];
        }
        $t = $cache[$key][$group['id']] ?? [];
        return [($t['label'] ?? '') !== '' ? $t['label'] : $group['label'],
            ($t['tip'] ?? '') !== '' ? $t['tip'] : ($group['tip'] ?? '')];
    }

    /**
     * Questions students get: visible ones in the activity language; if none exist in that language yet,
     * all visible questions (so a newly switched activity still has a quiz).
     *
     * @param stdClass $instance
     * @return stdClass[]
     */
    public static function student_questions(stdClass $instance): array {
        $all = self::get_questions($instance->id, true);
        $lang = $instance->language ?? 'en';
        $inlang = array_filter($all, fn($q) => language::same($q->lang ?? 'en', $lang));
        return $inlang ?: $all;
    }

    /**
     * Voiceover settings for the page (no secrets).
     *
     * @param stdClass $instance
     * @return array
     */
    public static function voice_config(stdClass $instance): array {
        return [
            'enabled' => voice::enabled($instance),
            'places' => voice::places($instance),
            'auto' => (bool)($instance->voiceauto ?? 0),
            'phrases' => voice::enabled($instance) ? array_filter(voice::phrases($instance)) : [],
        ];
    }

    /**
     * Pack information shared by the player and the editor.
     *
     * @param stdClass $instance
     * @return array
     */
    public static function pack_meta(stdClass $instance): array {
        $pack = pack::get($instance->pack);
        $groups = [];
        foreach ($pack['groups'] as $g) {
            [$label, $tip] = self::group_text($instance, $g);
            $groups[] = [
                'id' => $g['id'],
                'label' => $label,
                'parent' => $g['parent'],
                'tip' => $tip,
                'top' => $g['parent'] === $pack['root'],
            ];
        }
        $explodedirs = [];
        foreach ($pack['structures'] as $s) {
            if (!empty($s['explode']) && count($s['explode']) === 3) {
                $explodedirs[$s['node']] = array_map('floatval', $s['explode']);
            }
        }
        return [
            'id' => $pack['id'],
            'name' => $pack['name'],
            'explodedirs' => (object)$explodedirs,
            'rootframe' => array_values($pack['rootframe'] ?? []),
            'root' => $pack['root'],
            'model' => pack::model_url(
                $instance->pack,
                (int)get_coursemodule_from_instance('aianatomy', $instance->id, $instance->course, false, MUST_EXIST)->id
            ),
            'presets' => array_map(
                function ($p) use ($instance) {
                    $p['label'] = self::group_text($instance, ['id' => '_preset_' . $p['id'], 'label' => $p['label']])[0];
                    return $p;
                }, $pack['presets']
            ),
            'defaultpreset' => $pack['defaultpreset'] ?? ($pack['presets'][0]['id'] ?? ''),
            'groups' => $groups,
            'explode' => max(0, min(200, (int)$instance->explode)) / 100,
            'showcontext' => (int)$instance->showcontext,
            'attribution' => $pack['source']['attribution'] ?? '',
            'licenseurl' => $pack['source']['license_url'] ?? '',
            'license' => $pack['source']['license'] ?? '',
        ];
    }

    /**
     * Data for Study mode (all content students may see).
     *
     * @param stdClass $instance
     * @param \context $context
     * @return array
     */
    public static function study_data(stdClass $instance, \context $context): array {
        $pack = pack::get($instance->pack);
        $rows = self::get_structures($instance->id);
        $fields = self::studyfields($instance);
        $names = [];
        foreach ($pack['structures'] as $s) {
            $names[$s['id']] = self::display_name($rows[$s['id']] ?? null, $s);
        }
        $structures = [];
        $i = 0;
        foreach ($pack['structures'] as $s) {
            $row = $rows[$s['id']] ?? null;
            if (!$row) {
                continue;
            }
            $enabled = (bool)$row->enabled;
            // Study shows every structure's approved content; "enabled" only decides what Practice and Test assess.
            $content = [];
            foreach (self::content($row) as $k => $v) {
                if ($v !== '' && in_array($k, $fields, true)) {
                    $content[$k] = format_string($v, true, ['context' => $context]);
                }
            }
            $related = [];
            if (in_array('relationships', $fields, true)) {
                foreach ($s['relationships'] as $r) {
                    if (isset($names[$r['target']])) {
                        $related[] = ['id' => $r['target'], 'name' => format_string(
                            $names[$r['target']], true,
                            ['context' => $context]
                        ), 'type' => $r['type']];
                    }
                }
            }
            $voiceitems = [];
            if (voice::on($instance, 'cards')) {
                $latin = in_array('latin', $fields, true) ? ($s['names']['latin'] ?? '') : '';
                $voiceitems = array_filter(
                    [
                        'name' => voice::item($instance, $names[$s['id']], 'slow'),
                        'card' => voice::item($instance, voice::card_text($names[$s['id']], $latin, $content)),
                    ]
                );
            }
            $structures[] = [
                'id' => $s['id'],
                'node' => $s['node'],
                'voice' => (object)$voiceitems,
                'name' => format_string($names[$s['id']], true, ['context' => $context]),
                'synonyms' => array_map(
                    fn($x) => format_string($x, true, ['context' => $context]),
                    $s['names']['synonyms'] ?? []
                ),
                'latinsearch' => $s['names']['latin'] ?? '',
                'group' => $s['group'],
                'top' => pack::top_group($pack, $s['group']),
                'type' => $s['type'],
                'enabled' => $enabled,
                'content' => $content,
                'related' => $related,
                'anchor' => self::parse_anchor($row->anchor),
                'labelpos' => self::parse_labelpos($row->labelpos),
                'colour' => self::colour($i++),
            ];
        }
        return [
            'structures' => $structures,
            'fields' => $fields,
            'studytip' => format_text((string)$instance->studytip, FORMAT_PLAIN, ['context' => $context]),
        ];
    }

    /**
     * Data for the teacher editor (includes drafts, statuses and all questions).
     *
     * @param stdClass $instance
     * @param \context $context
     * @return array
     */
    public static function editor_data(stdClass $instance, \context $context): array {
        $pack = pack::get($instance->pack);
        $rows = self::get_structures($instance->id);
        $questions = self::get_questions($instance->id);
        $structures = [];
        foreach ($pack['structures'] as $s) {
            $row = $rows[$s['id']] ?? null;
            if (!$row) {
                continue;
            }
            $draft = json_decode((string)$row->draft, true);
            $structures[] = [
                'id' => $s['id'],
                'node' => $s['node'],
                'packname' => $s['names']['preferred'],
                'label' => $row->label,
                'latin' => $s['names']['latin'] ?? '',
                'group' => $s['group'],
                'top' => pack::top_group($pack, $s['group']),
                'fma' => $s['ontology'][0]['id'] ?? '',
                'enabled' => (bool)$row->enabled,
                'anchor' => self::parse_anchor($row->anchor),
                'labelpos' => self::parse_labelpos($row->labelpos),
                'content' => self::content($row),
                'library' => pack::library_content($s),
                'status' => $row->contentstatus,
                'aigenerated' => (bool)$row->aigenerated,
                'aimodel' => $row->aimodel,
                'draft' => is_array($draft) ? pack::clean_content($draft) : null,
                'draftname' => is_array($draft) ? (string)($draft['_name'] ?? '') : '',
                'draftlang' => (string)($row->draftlang ?? ''),
                'contentlang' => (string)($row->contentlang ?? 'en'),
                'timeapproved' => (int)$row->timeapproved,
            ];
        }
        $qs = [];
        foreach ($questions as $q) {
            $qs[] = self::question_export($q);
        }
        $lang = language::normalise($instance->language ?? 'en');
        $groups = [];
        foreach (self::text_groups($pack) as $g) {
            [$label, $tip] = self::group_text($instance, $g);
            $groups[] = ['id' => $g['id'], 'packlabel' => $g['label'], 'packtip' => $g['tip'] ?? '',
                'label' => $label, 'tip' => $tip];
        }
        return [
            'structures' => $structures,
            'questions' => $qs,
            'fields' => pack::FIELDS,
            'level' => $instance->level,
            'studytip' => (string)$instance->studytip,
            'ai' => ai\generator::status(),
            'language' => ['code' => $lang, 'name' => language::english_name($lang),
                'english' => language::same($lang, 'en'), 'installed' => language::installed($lang)],
            'groups' => $groups,
            'grouptranslated' => !empty(json_decode((string)($instance->grouptext ?? ''), true)),
            'jobs' => ai\jobs::pending_text((int)$instance->id),
            'voice' => [
                'enabled' => voice::enabled($instance),
                'activity' => (bool)($instance->voice ?? 0),
                'configured' => ai\tts_lmslabs::is_ready(),
                'voicename' => voice::voicename($instance),
                'locale' => language::locale($lang),
                'stats' => voice::stats($instance),
                'failed' => ai\jobs::failed_speech((int)$instance->id),
                'available' => voice::available(language::locale($lang)),
                'effective' => voice::voicename($instance),
                'items' => voice::enabled($instance) ? self::voice_items($instance, $context) : [],
            ],
        ];
    }

    /**
     * Every text students can hear in this activity, as signed items (for teacher pre-generation).
     * Built in the activity language so the texts match what students get.
     *
     * @param stdClass $instance
     * @param \context $context
     * @return array
     */
    public static function voice_items(stdClass $instance, \context $context): array {
        $prev = null;
        $code = language::normalise($instance->language ?? 'en');
        if (current_language() !== $code && ($code === 'en' || language::installed($code))) {
            $prev = force_current_language($code);
        }
        try {
            $items = [];
            $add = function (?array $item) use (&$items) {
                if ($item) {
                    $items[$item['sig']] = $item;
                }
            };
            $pack = pack::get($instance->pack);
            $rows = self::get_structures($instance->id);
            foreach (self::study_data($instance, $context)['structures'] as $st) {
                foreach ((array)$st['voice'] as $item) {
                    $add($item);
                }
            }
            foreach ($pack['structures'] as $s) {
                $row = $rows[$s['id']] ?? null;
                if ($row && $row->enabled && voice::on($instance, 'prompts')) {
                    $add(voice::item($instance, get_string('findprompt', 'mod_aianatomy', self::display_name($row, $s))));
                }
            }
            foreach (self::student_questions($instance) as $q) {
                if (!isset($rows[$q->structureid]) || !$rows[$q->structureid]->enabled) {
                    continue;
                }
                $options = array_values(json_decode($q->options, true) ?: []);
                if (voice::on($instance, 'questions')) {
                    $add(voice::item($instance, $q->questiontext));
                    foreach ($options as $o) {
                        $add(voice::item($instance, $o));
                    }
                }
                if (voice::on($instance, 'feedback')) {
                    $add(
                        voice::item(
                            $instance, get_string(
                                'voice_answeris', 'mod_aianatomy',
                                rtrim(voice::plain($options[$q->answer] ?? ''), '.')
                            ) . ' ' . voice::plain((string)$q->explanation)
                        )
                    );
                }
            }
            foreach (voice::phrases($instance) as $item) {
                $add($item);
            }
            return array_values($items);
        } finally {
            if ($prev !== null) {
                force_current_language($prev);
            }
        }
    }

    /**
     * Exports a question for the editor.
     *
     * @param stdClass $q
     * @return array
     */
    public static function question_export(stdClass $q): array {
        return [
            'id' => (int)$q->id,
            'structureid' => $q->structureid,
            'kind' => $q->kind,
            'text' => $q->questiontext,
            'options' => array_values(json_decode($q->options, true) ?: []),
            'answer' => (int)$q->answer,
            'explanation' => (string)$q->explanation,
            'status' => $q->status,
            'aigenerated' => (bool)$q->aigenerated,
            'lang' => (string)($q->lang ?? 'en'),
        ];
    }

    /**
     * Attempts used / left and best test grade.
     *
     * @param stdClass $instance
     * @param int $userid
     * @return array
     */
    public static function user_summary(stdClass $instance, int $userid): array {
        global $DB;
        $used = $DB->count_records(
            'aianatomy_attempt', ['aianatomyid' => $instance->id, 'userid' => $userid,
            'mode' => 'test']
        );
        $best = $DB->get_field_sql(
            'SELECT MAX(grade) FROM {aianatomy_attempt}
              WHERE aianatomyid = :aid AND userid = :userid AND mode = :mode AND state = :state',
            ['aid' => $instance->id, 'userid' => $userid, 'mode' => 'test', 'state' => 'finished']
        );
        $left = $instance->maxattempts ? max(0, $instance->maxattempts - $used) : -1;
        return [
            'attemptsused' => $used,
            'attemptsleft' => $left,
            'best' => $best !== false && $best !== null ? round((float)$best, 1) : null,
        ];
    }

    /**
     * Mastery state of each enabled structure for a user.
     *
     * @param stdClass $instance
     * @param int $userid
     * @return array structureid => ['state' => new|learning|difficult|mastered, 'correct', 'wrong']
     */
    public static function mastery(stdClass $instance, int $userid): array {
        global $DB;
        $rows = $DB->get_records(
            'aianatomy_mastery', ['aianatomyid' => $instance->id, 'userid' => $userid], '',
            'structureid, correct, wrong, streak'
        );
        $out = [];
        foreach (self::get_structures($instance->id) as $sid => $s) {
            if (!$s->enabled) {
                continue;
            }
            $m = $rows[$sid] ?? null;
            $out[$sid] = [
                'state' => self::mastery_state($m),
                'correct' => $m ? (int)$m->correct : 0,
                'wrong' => $m ? (int)$m->wrong : 0,
            ];
        }
        return $out;
    }

    /**
     * Classifies a mastery row.
     *
     * @param stdClass|null $m
     * @return string
     */
    public static function mastery_state(?stdClass $m): string {
        if (!$m || ($m->correct + $m->wrong) == 0) {
            return 'new';
        }
        if ($m->streak >= 2 && $m->correct > $m->wrong) {
            return 'mastered';
        }
        if ($m->wrong > 0 && $m->wrong >= $m->correct) {
            return 'difficult';
        }
        return 'learning';
    }

    /**
     * Updates mastery after an answer.
     *
     * @param int $instanceid
     * @param int $userid
     * @param string $structureid
     * @param bool $firsttry correct on the first try
     */
    public static function update_mastery(int $instanceid, int $userid, string $structureid, bool $firsttry): void {
        global $DB;
        $m = $DB->get_record(
            'aianatomy_mastery', ['aianatomyid' => $instanceid, 'userid' => $userid,
            'structureid' => $structureid]
        );
        if (!$m) {
            $m = (object)['aianatomyid' => $instanceid, 'userid' => $userid, 'structureid' => $structureid,
                'correct' => 0, 'wrong' => 0, 'streak' => 0, 'timemodified' => 0];
        }
        if ($firsttry) {
            $m->correct++;
            $m->streak++;
        } else {
            $m->wrong++;
            $m->streak = 0;
        }
        $m->timemodified = time();
        if (empty($m->id)) {
            $DB->insert_record('aianatomy_mastery', $m);
        } else {
            $DB->update_record('aianatomy_mastery', $m);
        }
    }

    /**
     * Pass mark from the gradebook (Grade to pass), as a percentage.
     *
     * @param stdClass $instance
     * @return float
     */
    public static function pass_percent(stdClass $instance): float {
        global $CFG;
        if ($instance->grade <= 0) {
            return 0.0;
        }
        require_once($CFG->libdir . '/gradelib.php');
        $item = \grade_item::fetch(
            ['itemtype' => 'mod', 'itemmodule' => 'aianatomy', 'iteminstance' => $instance->id,
            'courseid' => $instance->course, 'itemnumber' => 0]
        );
        if ($item && $item->gradepass > 0 && $item->grademax > 0) {
            return round($item->gradepass / $item->grademax * 100, 1);
        }
        return 0.0;
    }

    /**
     * Starts a practice or test attempt and returns the player payload.
     *
     * Test mode never sends the answer key: pins and labels use random per-attempt tokens and the
     * server marks every answer. Practice mode includes the answers for instant feedback.
     *
     * @param stdClass $instance
     * @param \cm_info|stdClass $cm
     * @param stdClass $course
     * @param \context $context
     * @param string $mode
     * @param int $userid
     * @param string $focus all|weak
     * @return array
     */
    public static function start_attempt(stdClass $instance, $cm, stdClass $course, \context $context, string $mode,
            int $userid, string $focus = 'all'): array {
        global $DB;
        if (!in_array($mode, ['practice', 'test'], true)) {
            throw new moodle_exception('invalidmode', 'mod_aianatomy');
        }
        if (($mode === 'practice' && !$instance->allowpractice) || ($mode === 'test' && !$instance->allowtest)) {
            throw new moodle_exception('modedisabled', 'mod_aianatomy');
        }
        $focus = $mode === 'practice' && $focus === 'weak' ? 'weak' : 'all';

        // Close unfinished attempts in the same mode (graded as they stand).
        $open = $DB->get_records(
            'aianatomy_attempt', ['aianatomyid' => $instance->id, 'userid' => $userid,
            'mode' => $mode, 'state' => 'inprogress']
        );
        foreach ($open as $attempt) {
            self::finish_attempt($instance, $cm, $course, $context, $attempt);
        }
        if ($mode === 'test' && $instance->maxattempts) {
            $used = $DB->count_records(
                'aianatomy_attempt', ['aianatomyid' => $instance->id, 'userid' => $userid,
                'mode' => 'test']
            );
            if ($used >= $instance->maxattempts) {
                throw new moodle_exception('nomoreattempts', 'mod_aianatomy');
            }
        }

        $pack = pack::get($instance->pack);
        $rows = self::get_structures($instance->id);
        $targets = array_filter($rows, fn($r) => $r->enabled);
        if (!$targets) {
            throw new moodle_exception('nostructures', 'mod_aianatomy');
        }
        $mastery = self::mastery($instance, $userid);
        if ($focus === 'weak') {
            $weak = array_filter($targets, fn($r) => ($mastery[$r->structureid]['state'] ?? 'new') !== 'mastered');
            if ($weak) {
                $targets = $weak;
            }
        }

        $map = ['pins' => [], 'labels' => [], 'questions' => [], 'rounds' => [], 'submitted' => [], 'quizdone' => 0];
        $rounds = [];
        $identtotal = 0;
        $colour = 0;
        foreach (pack::top_groups($pack) as $g) {
            $members = array_filter($pack['structures'], fn($s) => pack::top_group($pack, $s['group']) === $g['id']);
            $groupnodes = array_values(array_map(fn($s) => $s['node'], $members));
            $pins = [];
            $labels = [];
            $answers = [];
            $hints = [];
            $n = 0;
            foreach ($members as $s) {
                if (!isset($targets[$s['id']])) {
                    continue;
                }
                $row = $targets[$s['id']];
                $content = self::content($row);
                $pt = 'p' . random_string(12);
                $lt = 'l' . random_string(12);
                $map['pins'][$pt] = $s['id'];
                $map['labels'][$lt] = $s['id'];
                $pin = [
                    'token' => $pt,
                    'node' => $s['node'],
                    'number' => ++$n,
                    'colour' => self::colour($colour++),
                ];
                // Optional values are omitted (not null) so they validate against the web service structure.
                if ($anchor = self::parse_anchor($row->anchor)) {
                    $pin['anchor'] = $anchor;
                }
                if ($labelpos = self::parse_labelpos($row->labelpos)) {
                    $pin['labelpos'] = $labelpos;
                }
                if ($mode === 'practice' && voice::on($instance, 'prompts')
                        && ($item = voice::item(
                            $instance, get_string(
                                'findprompt', 'mod_aianatomy',
                                self::display_name($row, $s)
                            )
                        ))) {
                    $pin['voice'] = $item;
                }
                $pins[] = $pin;
                $labels[] = ['token' => $lt, 'text' => format_string(
                    self::display_name($row, $s), true,
                    ['context' => $context]
                )];
                if ($mode === 'practice') {
                    $answers[] = ['pin' => $pt, 'label' => $lt];
                    $hints[] = ['pin' => $pt, 'text' => format_string(
                        $content['hint'] ?: $content['location'], true,
                        ['context' => $context]
                    )];
                }
            }
            if (!$pins) {
                continue;
            }
            if ($mode === 'practice' && $focus === 'weak') {
                usort(
                    $pins, fn($a, $b) => self::weakness($mastery, $map['pins'][$b['token']])
                    <=> self::weakness($mastery, $map['pins'][$a['token']])
                );
            }
            shuffle($labels);
            $map['rounds'][] = array_map(fn($p) => $p['token'], $pins);
            $identtotal += count($pins);
            [$glabel, $gtip] = self::group_text($instance, $g);
            $rounds[] = [
                'group' => $g['id'],
                'title' => format_string($glabel, true, ['context' => $context]),
                'tip' => $mode === 'practice' ? format_string($gtip, true, ['context' => $context]) : '',
                'nodes' => $groupnodes,
                'pins' => $pins,
                'labels' => $labels,
                'answers' => $answers,
                'hints' => $hints,
            ];
        }

        // Knowledge questions for the target structures.
        $questions = [];
        $count = (int)$instance->quizcount;
        if ($count > 0) {
            $pool = array_filter(self::student_questions($instance), fn($q) => isset($targets[$q->structureid]));
            $pool = array_values($pool);
            shuffle($pool);
            if ($mode === 'practice') {
                usort(
                    $pool, fn($a, $b) => self::weakness($mastery, $b->structureid)
                    <=> self::weakness($mastery, $a->structureid)
                );
            }
            // Prefer one question per structure before repeating a structure.
            $picked = [];
            $seen = [];
            foreach ([true, false] as $unique) {
                foreach ($pool as $q) {
                    if (count($picked) >= $count || isset($picked[$q->id])) {
                        continue;
                    }
                    if ($unique && isset($seen[$q->structureid])) {
                        continue;
                    }
                    $picked[$q->id] = $q;
                    $seen[$q->structureid] = true;
                }
            }
            foreach ($picked as $q) {
                $options = array_values(json_decode($q->options, true) ?: []);
                if (count($options) < 2) {
                    continue;
                }
                $order = array_keys($options);
                shuffle($order);
                $qt = 'q' . random_string(12);
                $map['questions'][$qt] = ['id' => (int)$q->id, 'order' => $order];
                $item = [
                    'token' => $qt,
                    'text' => format_string($q->questiontext, true, ['context' => $context]),
                    'options' => array_map(fn($i) => format_string($options[$i], true, ['context' => $context]), $order),
                ];
                if (voice::on($instance, 'questions') && ($vi = voice::item($instance, $q->questiontext))) {
                    // Stem and options are separate clips, so the per-attempt option order never creates new audio.
                    $item['voice'] = $vi;
                    $item['voiceoptions'] = array_values(
                        array_filter(
                            array_map(
                            fn($i) => voice::item($instance, $options[$i]), $order)
                        )
                    );
                }
                if ($mode === 'practice') {
                    $item['answer'] = (int)array_search((int)$q->answer, $order, true);
                    $item['explanation'] = format_string((string)$q->explanation, true, ['context' => $context]);
                    $correcttext = $options[$q->answer] ?? '';
                    if (voice::on($instance, 'feedback') && ($vi = voice::item(
                        $instance, get_string(
                            'voice_answeris',
                            'mod_aianatomy', rtrim(voice::plain($correcttext), '.')
                        ) . ' ' .
                        voice::plain((string)$q->explanation)
                    ))) {
                        $item['voicefeedback'] = $vi;
                    }
                }
                $questions[] = $item;
            }
        }

        $attemptno = 1 + (int)$DB->get_field_sql(
            'SELECT MAX(attempt) FROM {aianatomy_attempt} WHERE aianatomyid = :aid AND userid = :userid AND mode = :mode',
            ['aid' => $instance->id, 'userid' => $userid, 'mode' => $mode]
        );
        $attempt = (object)[
            'aianatomyid' => $instance->id,
            'userid' => $userid,
            'attempt' => $attemptno,
            'mode' => $mode,
            'state' => 'inprogress',
            'focus' => $focus,
            'tokenmap' => json_encode($map),
            'timestart' => time(),
            'timefinish' => 0,
            'identcorrect' => 0,
            'identtotal' => $identtotal,
            'quizcorrect' => 0,
            'quiztotal' => count($questions),
            'grade' => null,
            'duration' => 0,
        ];
        $attempt->id = $DB->insert_record('aianatomy_attempt', $attempt);

        // Names for practice feedback ("That is the lunate").
        $names = [];
        if ($mode === 'practice') {
            foreach ($pack['structures'] as $s) {
                $names[] = ['node' => $s['node'], 'name' => format_string(
                    self::display_name($rows[$s['id']] ?? null, $s),
                    true, ['context' => $context]
                )];
            }
        }
        $timeleft = 0;
        if ($mode === 'test' && $instance->timelimit) {
            $timeleft = (int)$instance->timelimit;
        }
        return [
            'attemptid' => (int)$attempt->id,
            'attempt' => $attemptno,
            'voice' => self::voice_config($instance),
            'mode' => $mode,
            'focus' => $focus,
            'timelimit' => $timeleft,
            'rounds' => $rounds,
            'questions' => $questions,
            'names' => $names,
        ];
    }

    /**
     * Sort weight: higher = weaker (practised first).
     *
     * @param array $mastery
     * @param string $sid
     * @return int
     */
    protected static function weakness(array $mastery, string $sid): int {
        $state = $mastery[$sid]['state'] ?? 'new';
        return ['difficult' => 3, 'learning' => 2, 'new' => 1, 'mastered' => 0][$state] ?? 1;
    }

    /**
     * Loads an attempt belonging to a user.
     *
     * @param int $attemptid
     * @param int $userid
     * @return stdClass
     */
    public static function get_user_attempt(int $attemptid, int $userid): stdClass {
        global $DB;
        $attempt = $DB->get_record('aianatomy_attempt', ['id' => $attemptid], '*', MUST_EXIST);
        if ((int)$attempt->userid !== $userid) {
            throw new moodle_exception('invalidattempt', 'mod_aianatomy');
        }
        return $attempt;
    }

    /**
     * Whether the attempt's time limit (plus grace) has passed.
     *
     * @param stdClass $instance
     * @param stdClass $attempt
     * @return bool
     */
    public static function is_overdue(stdClass $instance, stdClass $attempt): bool {
        return $attempt->mode === 'test' && $instance->timelimit > 0
            && time() > $attempt->timestart + $instance->timelimit + self::TIME_GRACE;
    }

    /**
     * Records practice results. Items: [kind => label|find|quiz, token, correct, tries].
     *
     * @param stdClass $instance
     * @param stdClass $attempt
     * @param array $items
     * @return int number recorded
     */
    public static function record_practice(stdClass $instance, stdClass $attempt, array $items): int {
        global $DB;
        if ($attempt->mode !== 'practice' || $attempt->state !== 'inprogress') {
            throw new moodle_exception('invalidattempt', 'mod_aianatomy');
        }
        $map = json_decode($attempt->tokenmap, true);
        $done = array_flip($map['recorded'] ?? []);
        $n = 0;
        $now = time();
        foreach ($items as $item) {
            $kind = $item['kind'];
            $key = $kind . ':' . $item['token'];
            if (isset($done[$key])) {
                continue;
            }
            $sid = '';
            $qid = 0;
            if ($kind === 'quiz') {
                if (!isset($map['questions'][$item['token']])) {
                    continue;
                }
                $qid = $map['questions'][$item['token']]['id'];
                $sid = (string)$DB->get_field('aianatomy_question', 'structureid', ['id' => $qid]);
            } else if (in_array($kind, ['label', 'find'], true)) {
                if (!isset($map['pins'][$item['token']])) {
                    continue;
                }
                $sid = $map['pins'][$item['token']];
            } else {
                continue;
            }
            $firsttry = !empty($item['correct']) && (int)$item['tries'] <= 1;
            $DB->insert_record(
                'aianatomy_response', (object)[
                    'attemptid' => $attempt->id,
                    'kind' => $kind,
                    'structureid' => $sid,
                    'questionid' => $qid,
                    'answer' => '',
                    'correct' => $firsttry ? 1 : 0,
                    'tries' => max(1, (int)$item['tries']),
                    'roundno' => 0,
                    'timecreated' => $now,
                ]
            );
            if ($sid !== '') {
                self::update_mastery((int)$instance->id, (int)$attempt->userid, $sid, $firsttry);
            }
            $done[$key] = true;
            $n++;
        }
        $map['recorded'] = array_keys($done);
        $DB->set_field('aianatomy_attempt', 'tokenmap', json_encode($map), ['id' => $attempt->id]);
        return $n;
    }

    /**
     * Submits (and locks) one test round of label placements. Nothing about correctness is returned
     * until the attempt is finished.
     *
     * @param stdClass $instance
     * @param stdClass $attempt
     * @param int $round
     * @param array $placements [pin, label]
     * @return int number of pins answered
     */
    public static function submit_round(stdClass $instance, stdClass $attempt, int $round, array $placements): int {
        global $DB;
        if ($attempt->mode !== 'test' || $attempt->state !== 'inprogress') {
            throw new moodle_exception('invalidattempt', 'mod_aianatomy');
        }
        if (self::is_overdue($instance, $attempt)) {
            throw new moodle_exception('timeup', 'mod_aianatomy');
        }
        $map = json_decode($attempt->tokenmap, true);
        if (!isset($map['rounds'][$round])) {
            throw new moodle_exception('invalidround', 'mod_aianatomy');
        }
        if (in_array($round, $map['submitted'], true)) {
            throw new moodle_exception('alreadysubmitted', 'mod_aianatomy');
        }
        $pins = array_flip($map['rounds'][$round]);
        $given = [];
        foreach ($placements as $p) {
            if (isset($pins[$p['pin']]) && !isset($given[$p['pin']])) {
                $given[$p['pin']] = $p['label'];
            }
        }
        $usedlabels = [];
        $now = time();
        $answered = 0;
        foreach (array_keys($pins) as $pin) {
            $target = $map['pins'][$pin];
            $label = $given[$pin] ?? '';
            $placed = ($label !== '' && isset($map['labels'][$label]) && !isset($usedlabels[$label]))
                ? $map['labels'][$label] : '';
            if ($placed !== '') {
                $usedlabels[$label] = true;
                $answered++;
            }
            $DB->insert_record(
                'aianatomy_response', (object)[
                    'attemptid' => $attempt->id,
                    'kind' => 'label',
                    'structureid' => $target,
                    'questionid' => 0,
                    'answer' => $placed,
                    'correct' => (int)($placed !== '' && $placed === $target),
                    'tries' => 1,
                    'roundno' => $round,
                    'timecreated' => $now,
                ]
            );
        }
        $map['submitted'][] = $round;
        $DB->set_field('aianatomy_attempt', 'tokenmap', json_encode($map), ['id' => $attempt->id]);
        return $answered;
    }

    /**
     * Submits the knowledge question answers (test). Answers: [token, option] (option = displayed index, -1 none).
     *
     * @param stdClass $instance
     * @param stdClass $attempt
     * @param array $answers
     * @return int answered
     */
    public static function submit_quiz(stdClass $instance, stdClass $attempt, array $answers): int {
        global $DB;
        if ($attempt->mode !== 'test' || $attempt->state !== 'inprogress') {
            throw new moodle_exception('invalidattempt', 'mod_aianatomy');
        }
        if (self::is_overdue($instance, $attempt)) {
            throw new moodle_exception('timeup', 'mod_aianatomy');
        }
        $map = json_decode($attempt->tokenmap, true);
        if (!empty($map['quizdone'])) {
            throw new moodle_exception('alreadysubmitted', 'mod_aianatomy');
        }
        $given = [];
        foreach ($answers as $a) {
            $given[$a['token']] = (int)$a['option'];
        }
        $now = time();
        $answered = 0;
        foreach ($map['questions'] as $token => $info) {
            $q = $DB->get_record('aianatomy_question', ['id' => $info['id']]);
            $choice = $given[$token] ?? -1;
            $original = ($choice >= 0 && isset($info['order'][$choice])) ? (int)$info['order'][$choice] : -1;
            if ($original >= 0) {
                $answered++;
            }
            $DB->insert_record(
                'aianatomy_response', (object)[
                    'attemptid' => $attempt->id,
                    'kind' => 'quiz',
                    'structureid' => $q ? $q->structureid : '',
                    'questionid' => (int)$info['id'],
                    'answer' => (string)$original,
                    'correct' => (int)($q && $original >= 0 && $original === (int)$q->answer),
                    'tries' => 1,
                    'roundno' => 0,
                    'timecreated' => $now,
                ]
            );
        }
        $map['quizdone'] = 1;
        $DB->set_field('aianatomy_attempt', 'tokenmap', json_encode($map), ['id' => $attempt->id]);
        return $answered;
    }

    /**
     * Combines identification and knowledge scores into a percentage.
     *
     * @param stdClass $instance
     * @param int $ic
     * @param int $it
     * @param int $qc
     * @param int $qt
     * @return float
     */
    public static function combine(stdClass $instance, int $ic, int $it, int $qc, int $qt): float {
        $ident = $it > 0 ? $ic / $it * 100 : null;
        $quiz = $qt > 0 ? $qc / $qt * 100 : null;
        if ($ident !== null && $quiz !== null) {
            $w = max(0, min(100, (int)$instance->identweight)) / 100;
            return round($ident * $w + $quiz * (1 - $w), 5);
        }
        return round((float)($ident ?? $quiz ?? 0), 5);
    }

    /**
     * Finishes an attempt, grades it (test) and returns the results.
     *
     * @param stdClass $instance
     * @param \cm_info|stdClass $cm
     * @param stdClass $course
     * @param \context $context
     * @param stdClass $attempt
     * @return array
     */
    public static function finish_attempt(stdClass $instance, $cm, stdClass $course, \context $context,
            stdClass $attempt): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/aianatomy/lib.php');
        $responses = $DB->get_records('aianatomy_response', ['attemptid' => $attempt->id], 'id');
        $pack = pack::get($instance->pack);
        $rows = self::get_structures($instance->id);
        $name = function (string $sid) use ($pack, $rows, $context): string {
            if (!isset($pack['byid'][$sid])) {
                return '';
            }
            return format_string(
                self::display_name($rows[$sid] ?? null, $pack['byid'][$sid]), true,
                ['context' => $context]
            );
        };

        $ic = 0;
        $qc = 0;
        $review = [];
        $quizreview = [];
        if ($attempt->mode === 'test') {
            $answeredquiz = [];
            foreach ($responses as $r) {
                if ($r->kind === 'label') {
                    $ic += (int)$r->correct;
                    $review[] = ['name' => $name($r->structureid), 'correct' => (bool)$r->correct,
                        'given' => $r->answer !== '' ? $name($r->answer) : ''];
                } else if ($r->kind === 'quiz') {
                    $qc += (int)$r->correct;
                    $answeredquiz[$r->questionid] = $r;
                }
            }
            $map = json_decode($attempt->tokenmap, true);
            // Rounds never submitted count as unanswered (already included in identtotal).
            foreach ($map['rounds'] as $i => $pins) {
                if (in_array($i, $map['submitted'], true)) {
                    continue;
                }
                foreach ($pins as $pin) {
                    $review[] = ['name' => $name($map['pins'][$pin]), 'correct' => false, 'given' => ''];
                }
            }
            foreach ($map['questions'] as $info) {
                $q = $DB->get_record('aianatomy_question', ['id' => $info['id']]);
                if (!$q) {
                    continue;
                }
                $options = json_decode($q->options, true) ?: [];
                $r = $answeredquiz[$q->id] ?? null;
                $given = $r && $r->answer !== '' && (int)$r->answer >= 0 ? ($options[(int)$r->answer] ?? '') : '';
                $quizreview[] = [
                    'text' => format_string($q->questiontext, true, ['context' => $context]),
                    'correct' => $r && $r->correct,
                    'given' => format_string($given, true, ['context' => $context]),
                    'answer' => format_string($options[$q->answer] ?? '', true, ['context' => $context]),
                    'explanation' => format_string((string)$q->explanation, true, ['context' => $context]),
                ];
            }
        } else {
            foreach ($responses as $r) {
                if ($r->kind === 'quiz') {
                    $qc += (int)$r->correct;
                } else {
                    $ic += (int)$r->correct;
                }
            }
        }

        if ($attempt->state === 'inprogress') {
            $attempt->state = 'finished';
            $attempt->timefinish = time();
            $attempt->duration = $attempt->timefinish - $attempt->timestart;
            if ($instance->timelimit && $attempt->mode === 'test') {
                $attempt->duration = min($attempt->duration, (int)$instance->timelimit + self::TIME_GRACE);
            }
            $attempt->identcorrect = $ic;
            $attempt->quizcorrect = $qc;
            if ($attempt->mode === 'practice') {
                // Practice: first-try results actually recorded.
                $attempt->identtotal = count(array_filter($responses, fn($r) => $r->kind !== 'quiz'));
                $attempt->quiztotal = count(array_filter($responses, fn($r) => $r->kind === 'quiz'));
            }
            $attempt->grade = self::combine($instance, $ic, (int)$attempt->identtotal, $qc, (int)$attempt->quiztotal);
            $DB->update_record('aianatomy_attempt', $attempt);

            if ($attempt->mode === 'test') {
                aianatomy_update_grades($instance, (int)$attempt->userid);
            }
            $event = \mod_aianatomy\event\attempt_finished::create(
                [
                    'objectid' => $attempt->id,
                    'context' => $context,
                    'relateduserid' => $attempt->userid,
                    'other' => ['mode' => $attempt->mode, 'grade' => (float)$attempt->grade],
                ]
            );
            $event->add_record_snapshot('aianatomy_attempt', $attempt);
            $event->trigger();

            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm)) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, (int)$attempt->userid);
            }
        }

        $pass = self::pass_percent($instance);
        $summary = self::user_summary($instance, (int)$attempt->userid);
        $mastery = self::mastery($instance, (int)$attempt->userid);
        $counts = ['mastered' => 0, 'learning' => 0, 'difficult' => 0, 'new' => 0];
        $difficult = [];
        foreach ($mastery as $sid => $m) {
            $counts[$m['state']]++;
            if ($m['state'] === 'difficult') {
                $difficult[] = $name($sid);
            }
        }
        return [
            'mode' => $attempt->mode,
            'grade' => round((float)$attempt->grade, 1),
            'identcorrect' => (int)$attempt->identcorrect,
            'identtotal' => (int)$attempt->identtotal,
            'quizcorrect' => (int)$attempt->quizcorrect,
            'quiztotal' => (int)$attempt->quiztotal,
            'passpercent' => $pass,
            'passed' => $attempt->mode === 'test' && (!$pass || $attempt->grade >= $pass),
            'duration' => (int)$attempt->duration,
            'attemptsleft' => (int)$summary['attemptsleft'],
            'review' => $review,
            'quizreview' => $quizreview,
            'mastery' => $counts,
            'difficult' => $difficult,
        ];
    }

    /**
     * Saves structure settings from the editor.
     *
     * @param stdClass $instance
     * @param array $items [structureid, enabled, label, anchor, labelpos]
     * @return int saved
     */
    public static function save_structures(stdClass $instance, array $items): int {
        global $DB;
        $rows = self::get_structures($instance->id);
        $n = 0;
        foreach ($items as $item) {
            $row = $rows[$item['structureid']] ?? null;
            if (!$row) {
                continue;
            }
            $anchor = self::parse_anchor((string)$item['anchor']);
            $pos = self::parse_labelpos((string)$item['labelpos']);
            $row->enabled = empty($item['enabled']) ? 0 : 1;
            $row->label = \core_text::substr(trim(clean_param($item['label'], PARAM_TEXT)), 0, 255);
            $row->anchor = $anchor ? implode(',', array_map(fn($v) => round($v, 3), $anchor)) : '';
            $row->labelpos = $pos ? round($pos['x'], 4) . ',' . round($pos['y'], 4) : '';
            $row->timemodified = time();
            $DB->update_record('aianatomy_structure', $row);
            $n++;
        }
        return $n;
    }

    /**
     * Saves or reviews the teaching content of a structure.
     *
     * Actions: save (teacher edit, becomes live), approve (live = given content, typically the AI draft
     * after review), discard (drop the draft), reset (back to library content).
     *
     * @param stdClass $instance
     * @param string $structureid
     * @param string $action
     * @param array $content
     * @param int $userid
     * @return array editor record
     */
    public static function save_content(stdClass $instance, string $structureid, string $action, array $content,
            int $userid): array {
        global $DB;
        $row = $DB->get_record(
            'aianatomy_structure', ['aianatomyid' => $instance->id, 'structureid' => $structureid],
            '*', MUST_EXIST
        );
        $pack = pack::get($instance->pack);
        $now = time();
        switch ($action) {
            case 'save':
            case 'approve':
                $fromdraft = $action === 'approve' && !empty($row->draft);
                if ($fromdraft) {
                    // A translated draft brings its name with it; the language is the draft's language.
                    $d = json_decode((string)$row->draft, true);
                    if (is_array($d) && !empty($d['_name'])) {
                        $row->label = \core_text::substr(trim(clean_param((string)$d['_name'], PARAM_TEXT)), 0, 255);
                    }
                    $row->contentlang = $row->draftlang ?: language::normalise($instance->language ?? 'en');
                } else {
                    $row->contentlang = language::normalise($instance->language ?? 'en');
                }
                $row->content = json_encode(pack::clean_content($content), JSON_UNESCAPED_UNICODE);
                $row->contentstatus = $action === 'approve' ? 'approved' : 'edited';
                $row->aigenerated = $fromdraft ? 1 : ($action === 'save' ? 0 : (int)$row->aigenerated);
                $row->approvedby = $userid;
                $row->timeapproved = $now;
                if ($action === 'approve') {
                    $row->draft = null;
                    $row->draftlang = '';
                }
                break;
            case 'discard':
                $row->draft = null;
                $row->draftlang = '';
                break;
            case 'reset':
                $row->content = json_encode(pack::library_content($pack['byid'][$structureid]));
                $row->contentstatus = 'library';
                $row->contentlang = 'en';
                $row->aigenerated = 0;
                $row->aimodel = '';
                $row->approvedby = 0;
                $row->timeapproved = 0;
                break;
            default:
                throw new moodle_exception('invalidaction', 'mod_aianatomy');
        }
        $row->timemodified = $now;
        $DB->update_record('aianatomy_structure', $row);
        $draft = json_decode((string)$row->draft, true);
        return [
            'structureid' => $structureid,
            'content' => self::content($row),
            'status' => $row->contentstatus,
            'aigenerated' => (bool)$row->aigenerated,
            'draft' => is_array($draft) ? pack::clean_content($draft) : null,
            'label' => (string)$row->label,
            'contentlang' => (string)$row->contentlang,
        ];
    }

    /**
     * Stores an AI (or pasted) draft for a structure.
     *
     * @param stdClass $instance
     * @param string $structureid
     * @param array $content
     * @param string $model
     * @param string $lang language of the draft ('' = the activity language)
     * @param string $name translated structure name (translations only)
     */
    public static function store_draft(stdClass $instance, string $structureid, array $content, string $model,
            string $lang = '', string $name = ''): void {
        global $DB;
        $row = $DB->get_record(
            'aianatomy_structure', ['aianatomyid' => $instance->id, 'structureid' => $structureid],
            '*', MUST_EXIST
        );
        $draft = pack::clean_content($content);
        if ($name !== '') {
            $draft['_name'] = $name;
        }
        $row->draft = json_encode($draft, JSON_UNESCAPED_UNICODE);
        $row->draftlang = language::normalise($lang !== '' ? $lang : ($instance->language ?? 'en'));
        $row->aimodel = \core_text::substr($model, 0, 100);
        $row->timegenerated = time();
        $row->timemodified = time();
        $DB->update_record('aianatomy_structure', $row);
    }

    /**
     * Validates and stores a question.
     *
     * @param stdClass $instance
     * @param array $data id, structureid, kind, text, options, answer, explanation, status
     * @param bool $aigenerated
     * @return array exported question
     */
    public static function save_question(stdClass $instance, array $data, bool $aigenerated = false): array {
        global $DB;
        $pack = pack::get($instance->pack);
        if (!isset($pack['byid'][$data['structureid']])) {
            throw new moodle_exception('invalidstructure', 'mod_aianatomy');
        }
        // Drop empty options, keeping the correct answer pointing at the same option.
        $options = [];
        $answer = -1;
        foreach (array_values((array)$data['options']) as $i => $o) {
            $o = \core_text::substr(trim(clean_param(strip_tags((string)$o), PARAM_TEXT)), 0, 500);
            if ($o === '') {
                continue;
            }
            if ($i === (int)$data['answer']) {
                $answer = count($options);
            }
            $options[] = $o;
        }
        $text = trim(clean_param(strip_tags((string)$data['text']), PARAM_TEXT));
        if ($text === '' || count($options) < 2 || count($options) > 6 || $answer < 0) {
            throw new moodle_exception('invalidquestion', 'mod_aianatomy');
        }
        $kinds = ['function', 'location', 'relationship', 'terminology', 'clinical'];
        $status = in_array($data['status'] ?? '', ['draft', 'approved', 'library'], true) ? $data['status'] : 'draft';
        $record = (object)[
            'aianatomyid' => $instance->id,
            'structureid' => $data['structureid'],
            'kind' => in_array($data['kind'] ?? '', $kinds, true) ? $data['kind'] : 'function',
            'questiontext' => \core_text::substr($text, 0, 1000),
            'options' => json_encode($options),
            'answer' => $answer,
            'explanation' => \core_text::substr(
                trim(
                    clean_param(
                        strip_tags((string)($data['explanation'] ?? '')),
                        PARAM_TEXT
                    )
                ), 0, 1000
            ),
            'status' => $status,
            'timemodified' => time(),
        ];
        if (!empty($data['lang'])) {
            $record->lang = language::normalise($data['lang']);
        }
        if (!empty($data['id'])) {
            $existing = $DB->get_record(
                'aianatomy_question', ['id' => (int)$data['id'], 'aianatomyid' => $instance->id],
                '*', MUST_EXIST
            );
            $record->id = $existing->id;
            $record->sortorder = $existing->sortorder;
            $record->aigenerated = $existing->aigenerated;
            if ($existing->status === 'library' && $status === 'library') {
                // Edited library questions become the teacher's own approved questions.
                $record->status = 'approved';
            }
            $DB->update_record('aianatomy_question', $record);
        } else {
            $record->sortorder = 1 + (int)$DB->get_field_sql(
                'SELECT MAX(sortorder) FROM {aianatomy_question} WHERE aianatomyid = :aid AND structureid = :sid',
                ['aid' => $instance->id, 'sid' => $data['structureid']]
            );
            $record->aigenerated = $aigenerated ? 1 : 0;
            $record->lang = $record->lang ?? language::normalise($instance->language ?? 'en');
            $record->id = $DB->insert_record('aianatomy_question', $record);
        }
        return self::question_export($DB->get_record('aianatomy_question', ['id' => $record->id]));
    }

    /**
     * Deletes all attempt data for an instance (optionally for one user).
     *
     * @param int $instanceid
     * @param int $userid 0 = all users
     */
    public static function delete_user_data(int $instanceid, int $userid = 0): void {
        global $DB;
        $params = ['aid' => $instanceid];
        $where = 'aianatomyid = :aid';
        if ($userid) {
            $where .= ' AND userid = :userid';
            $params['userid'] = $userid;
        }
        $ids = $DB->get_fieldset_select('aianatomy_attempt', 'id', $where, $params);
        if ($ids) {
            [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('aianatomy_response', "attemptid $insql", $inparams);
        }
        $DB->delete_records_select('aianatomy_attempt', $where, $params);
        $DB->delete_records_select('aianatomy_mastery', $where, $params);
    }
}
