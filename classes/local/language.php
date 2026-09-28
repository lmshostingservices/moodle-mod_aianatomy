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
 * Activity languages: Moodle language code, voice locale (Chirp 3 HD) and names.
 *
 * The activity language decides the interface (when the Moodle language pack is installed), the language
 * AI writes and translates content into, which questions are used, and the voiceover locale.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class language {
    /** @var array Moodle code => [voice locale, English name, native name] */
    const LANGUAGES = [
        'en' => ['en-GB', 'English (UK)', 'English'],
        'en_us' => ['en-US', 'English (US)', 'English (US)'],
        'en_au' => ['en-AU', 'English (Australia)', 'English (Australia)'],
        'en_in' => ['en-IN', 'English (India)', 'English (India)'],
        'ar' => ['ar-XA', 'Arabic', 'العربية'],
        'bg' => ['bg-BG', 'Bulgarian', 'Български'],
        'bn' => ['bn-IN', 'Bengali', 'বাংলা'],
        'cs' => ['cs-CZ', 'Czech', 'Čeština'],
        'da' => ['da-DK', 'Danish', 'Dansk'],
        'de' => ['de-DE', 'German', 'Deutsch'],
        'el' => ['el-GR', 'Greek', 'Ελληνικά'],
        'es' => ['es-ES', 'Spanish (Spain)', 'Español'],
        'es_mx' => ['es-US', 'Spanish (Latin America)', 'Español (Latinoamérica)'],
        'et' => ['et-EE', 'Estonian', 'Eesti'],
        'fi' => ['fi-FI', 'Finnish', 'Suomi'],
        'fr' => ['fr-FR', 'French', 'Français'],
        'fr_ca' => ['fr-CA', 'French (Canada)', 'Français (Canada)'],
        'gu' => ['gu-IN', 'Gujarati', 'ગુજરાતી'],
        'he' => ['he-IL', 'Hebrew', 'עברית'],
        'hi' => ['hi-IN', 'Hindi', 'हिन्दी'],
        'hr' => ['hr-HR', 'Croatian', 'Hrvatski'],
        'hu' => ['hu-HU', 'Hungarian', 'Magyar'],
        'id' => ['id-ID', 'Indonesian', 'Bahasa Indonesia'],
        'it' => ['it-IT', 'Italian', 'Italiano'],
        'ja' => ['ja-JP', 'Japanese', '日本語'],
        'kn' => ['kn-IN', 'Kannada', 'ಕನ್ನಡ'],
        'ko' => ['ko-KR', 'Korean', '한국어'],
        'lt' => ['lt-LT', 'Lithuanian', 'Lietuvių'],
        'lv' => ['lv-LV', 'Latvian', 'Latviešu'],
        'ml' => ['ml-IN', 'Malayalam', 'മലയാളം'],
        'mr' => ['mr-IN', 'Marathi', 'मराठी'],
        'nl' => ['nl-NL', 'Dutch', 'Nederlands'],
        'no' => ['nb-NO', 'Norwegian', 'Norsk bokmål'],
        'pl' => ['pl-PL', 'Polish', 'Polski'],
        'pt_br' => ['pt-BR', 'Portuguese (Brazil)', 'Português (Brasil)'],
        'ro' => ['ro-RO', 'Romanian', 'Română'],
        'ru' => ['ru-RU', 'Russian', 'Русский'],
        'sk' => ['sk-SK', 'Slovak', 'Slovenčina'],
        'sl' => ['sl-SI', 'Slovenian', 'Slovenščina'],
        'sr_cr' => ['sr-RS', 'Serbian', 'Српски'],
        'sv' => ['sv-SE', 'Swedish', 'Svenska'],
        'sw' => ['sw-KE', 'Swahili', 'Kiswahili'],
        'ta' => ['ta-IN', 'Tamil', 'தமிழ்'],
        'te' => ['te-IN', 'Telugu', 'తెలుగు'],
        'th' => ['th-TH', 'Thai', 'ไทย'],
        'tr' => ['tr-TR', 'Turkish', 'Türkçe'],
        'uk' => ['uk-UA', 'Ukrainian', 'Українська'],
        'ur' => ['ur-IN', 'Urdu', 'اردو'],
        'vi' => ['vi-VN', 'Vietnamese', 'Tiếng Việt'],
        'zh_cn' => ['cmn-CN', 'Chinese (Mandarin, Simplified)', '简体中文'],
    ];

    /**
     * Normalises a language code to one this plugin supports.
     *
     * @param string|null $code
     * @return string
     */
    public static function normalise(?string $code): string {
        $code = strtolower(trim((string)$code));
        if (isset(self::LANGUAGES[$code])) {
            return $code;
        }
        // Moodle variants such as de_du or fr_kids fall back to their base language.
        $base = explode('_', $code)[0];
        return isset(self::LANGUAGES[$base]) ? $base : 'en';
    }

    /**
     * Voice locale (BCP-47) for a language.
     *
     * @param string $code
     * @return string
     */
    public static function locale(string $code): string {
        return self::LANGUAGES[self::normalise($code)][0];
    }

    /**
     * English name, used in AI prompts.
     *
     * @param string $code
     * @return string
     */
    public static function english_name(string $code): string {
        return self::LANGUAGES[self::normalise($code)][1];
    }

    /**
     * Whether two codes are the same content language (en, en_us and en_au all count as English).
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    public static function same(string $a, string $b): bool {
        $base = fn($c) => explode('_', self::normalise($c))[0];
        return $base($a) === $base($b);
    }

    /**
     * Whether the Moodle language pack is installed (needed for the interface to switch).
     *
     * @param string $code
     * @return bool
     */
    public static function installed(string $code): bool {
        return get_string_manager()->translation_exists(self::normalise($code), false);
    }

    /**
     * Options for the activity settings: "Deutsch - German".
     *
     * @return array
     */
    public static function options(): array {
        $out = [];
        foreach (self::LANGUAGES as $code => [$locale, $english, $native]) {
            $label = $native === $english ? $english : $native . ' - ' . $english;
            if ($code !== 'en' && !self::installed($code)) {
                $label .= ' *';
            }
            $out[$code] = $label;
        }
        return $out;
    }

    /**
     * Switches the interface to the activity language for this request, when its language pack is installed.
     * (English variants without their own pack keep the user's English interface.)
     *
     * @param \stdClass $instance
     * @return bool true if switched
     */
    public static function apply(\stdClass $instance): bool {
        $code = self::normalise($instance->language ?? 'en');
        if (current_language() === $code || ($code !== 'en' && !self::installed($code))) {
            return false;
        }
        force_current_language($code);
        return true;
    }
}
