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
use moodle_url;

/**
 * Anatomy packs: verified 3D models plus their anatomical data (ids, names, hierarchy, relationships,
 * provenance and library teaching content). Packs live in mod/aianatomy/packs/<id>/pack.json.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pack {
    /** @var array Loaded packs, by id. */
    protected static $cache = [];

    /** @var string[] Teaching content fields, in display order. */
    public const FIELDS = ['latin', 'pronunciation', 'origin', 'location', 'description', 'function', 'mnemonic',
        'clinical', 'hint'];

    /** @var string[] Fields shown to students by default. */
    public const DEFAULT_STUDYFIELDS = ['latin', 'pronunciation', 'origin', 'location', 'function', 'mnemonic',
        'clinical', 'relationships'];

    /** @var string[] Learner levels for AI content. */
    public const LEVELS = ['school', 'certificate', 'diploma', 'undergraduate', 'medical'];

    /**
     * Folder containing all packs.
     *
     * @return string
     */
    public static function root(): string {
        return __DIR__ . '/../../packs';
    }

    /**
     * Whether an id is a syntactically valid pack id.
     *
     * @param string $id
     * @return bool
     */
    public static function valid_id(string $id): bool {
        return (bool)preg_match('/^[a-z0-9_]{1,64}$/', $id);
    }

    /**
     * Available packs, id => name.
     *
     * @return array
     */
    public static function list(): array {
        $list = [];
        foreach (glob(self::root() . '/*/pack.json') ?: [] as $file) {
            $id = basename(dirname($file));
            if (!self::valid_id($id)) {
                continue;
            }
            try {
                $list[$id] = self::get($id)['name'];
            } catch (\Throwable $e) {
                debugging('Invalid anatomy pack ' . $id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        asort($list);
        return $list;
    }

    /**
     * Installed packs grouped by body system (for the activity settings).
     *
     * @return array system label => [id => name]
     */
    public static function list_grouped(): array {
        $groups = [];
        foreach (self::list() as $id => $name) {
            $system = self::get($id)['system'] ?? 'other';
            $sm = get_string_manager();
            $label = $sm->string_exists('system_' . $system, 'mod_aianatomy')
                ? get_string('system_' . $system, 'mod_aianatomy') : ucfirst($system);
            $groups[$label][$id] = $name;
        }
        ksort($groups);
        return $groups;
    }

    /**
     * Loads a pack.
     *
     * @param string $id
     * @return array
     */
    public static function get(string $id): array {
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }
        if (!self::valid_id($id)) {
            throw new moodle_exception('invalidpack', 'mod_aianatomy');
        }
        $file = self::root() . '/' . $id . '/pack.json';
        if (!is_readable($file)) {
            throw new moodle_exception('invalidpack', 'mod_aianatomy');
        }
        $pack = json_decode(file_get_contents($file), true);
        if (!is_array($pack) || empty($pack['structures']) || empty($pack['groups'])) {
            throw new moodle_exception('invalidpack', 'mod_aianatomy');
        }
        $pack['byid'] = [];
        foreach ($pack['structures'] as $s) {
            $pack['byid'][$s['id']] = $s;
        }
        $pack['groupbyid'] = [];
        foreach ($pack['groups'] as $g) {
            $pack['groupbyid'][$g['id']] = $g;
        }
        self::$cache[$id] = $pack;
        return $pack;
    }

    /**
     * Path of the pack's model file.
     *
     * @param string $id
     * @return string
     */
    public static function model_path(string $id): string {
        $pack = self::get($id);
        $file = basename($pack['model'] ?? 'model.glb');
        return self::root() . '/' . $id . '/' . $file;
    }

    /**
     * URL of an activity's 3D model (served by model.php to users who can view the activity).
     *
     * @param string $id pack id
     * @param int $cmid course module id
     * @return string
     */
    public static function model_url(string $id, int $cmid): string {
        $pack = self::get($id);
        return (new moodle_url('/mod/aianatomy/model.php', ['id' => $cmid, 'v' => $pack['version']]))->out(false);
    }

    /**
     * The top-level group (child of the root group) a structure belongs to.
     *
     * @param array $pack
     * @param string $groupid
     * @return string
     */
    public static function top_group(array $pack, string $groupid): string {
        $root = $pack['root'];
        $guard = 0;
        while (isset($pack['groupbyid'][$groupid]) && $pack['groupbyid'][$groupid]['parent'] !== $root && $guard++ < 20) {
            $parent = $pack['groupbyid'][$groupid]['parent'];
            if ($parent === null) {
                break;
            }
            $groupid = $parent;
        }
        return $groupid;
    }

    /**
     * Top-level groups in pack order.
     *
     * @param array $pack
     * @return array[]
     */
    public static function top_groups(array $pack): array {
        return array_values(array_filter($pack['groups'], fn($g) => $g['parent'] === $pack['root']));
    }

    /**
     * Names of structures in the same group (used as distractors and context).
     *
     * @param array $pack
     * @param string $structureid
     * @return string[]
     */
    public static function sibling_names(array $pack, string $structureid): array {
        $s = $pack['byid'][$structureid] ?? null;
        if (!$s) {
            return [];
        }
        $names = [];
        foreach ($pack['structures'] as $other) {
            if ($other['group'] === $s['group'] && $other['id'] !== $structureid) {
                $names[] = $other['names']['preferred'];
            }
        }
        return $names;
    }

    /**
     * Library content of a structure (fields only).
     *
     * @param array $structure
     * @return array
     */
    public static function library_content(array $structure): array {
        $c = $structure['content'] ?? [];
        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f] = $f === 'latin' ? ($structure['names']['latin'] ?? '') : (string)($c[$f] ?? '');
        }
        return $out;
    }

    /**
     * Cleans a content array to the known fields with plain text values.
     *
     * @param array $content
     * @return array
     */
    public static function clean_content(array $content): array {
        $out = [];
        foreach (self::FIELDS as $f) {
            $v = $content[$f] ?? '';
            if (is_array($v)) {
                $v = implode(' ', array_map('strval', $v));
            }
            $v = trim(clean_param(strip_tags((string)$v), PARAM_TEXT));
            $out[$f] = \core_text::substr($v, 0, 2000);
        }
        return $out;
    }
}
