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

/**
 * Resolves the site's LMS Labs credentials (Site ID + API key).
 *
 * Central Config (local_aiconfig) wins by default. This plugin's own pair is only used when it is
 * complete and either Central Config has no complete pair, or the admin has explicitly ticked
 * "Use this plugin's own LMS Labs credentials". A central value is never mixed with a local one,
 * and central values are never copied into this plugin's settings.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class credentials {
    /**
     * The complete central pair, or null.
     *
     * @param callable|null $central test seam: returns ['siteid' => ..., 'apikey' => ...]
     * @return array|null
     */
    public static function central(?callable $central = null): ?array {
        if ($central) {
            $values = $central();
        } else if (class_exists('\\local_aiconfig\\config')) {
            $values = \local_aiconfig\config::get_credentials();
        } else {
            return null;
        }
        return self::pair($values['siteid'] ?? '', $values['apikey'] ?? '');
    }

    /**
     * This plugin's own complete pair, or null.
     *
     * @return array|null
     */
    public static function local(): ?array {
        return self::pair(
            (string)get_config('mod_aianatomy', 'lmslabs_siteid'),
            (string)get_config('mod_aianatomy', 'lmslabs_apikey')
        );
    }

    /**
     * A complete pair from two values, or null.
     *
     * @param mixed $siteid
     * @param mixed $apikey
     * @return array|null
     */
    protected static function pair($siteid, $apikey): ?array {
        $siteid = trim((string)$siteid);
        $apikey = trim((string)$apikey);
        return ($siteid !== '' && $apikey !== '') ? ['siteid' => $siteid, 'apikey' => $apikey] : null;
    }

    /**
     * Resolves a complete credential pair, central first.
     *
     * @param callable|null $central test seam for Central Config
     * @return array ['siteid' => string, 'apikey' => string, 'source' => central|local]
     * @throws \moodle_exception when no complete pair is available
     */
    public static function resolve(?callable $central = null): array {
        $centralpair = self::central($central);
        $localpair = self::local();
        if ($localpair && get_config('mod_aianatomy', 'lmslabs_useown')) {
            return $localpair + ['source' => 'local'];
        }
        if ($centralpair) {
            return $centralpair + ['source' => 'central'];
        }
        if ($localpair) {
            return $localpair + ['source' => 'local'];
        }
        throw new \moodle_exception('lmslabscredentialsmissing', 'mod_aianatomy');
    }

    /**
     * Where credentials will come from, for the admin settings page (never returns the key).
     *
     * @return string central|local|missing
     */
    public static function source(): string {
        try {
            return self::resolve()['source'];
        } catch (\moodle_exception $e) {
            return 'missing';
        }
    }

    /**
     * Whether Central Config is installed.
     *
     * @return bool
     */
    public static function central_installed(): bool {
        return class_exists('\\local_aiconfig\\config');
    }
}
