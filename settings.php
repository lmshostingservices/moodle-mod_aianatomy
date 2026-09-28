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

/**
 * Admin settings for mod_aianatomy.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(
        new admin_setting_heading(
            'mod_aianatomy/aiheading', get_string('aisettings', 'mod_aianatomy'),
            get_string('aisettings_desc', 'mod_aianatomy')
        )
    );

    // Credentials: Central Config (local_aiconfig) first; this plugin's pair only as a complete pair.
    $source = \mod_aianatomy\local\credentials::source();
    $status = get_string('credentials_' . $source, 'mod_aianatomy');
    if (!\mod_aianatomy\local\credentials::central_installed()) {
        $status .= ' ' . get_string('credentials_nocentral', 'mod_aianatomy');
    }
    $settings->add(
        new admin_setting_heading(
            'mod_aianatomy/credentialstatus', get_string('credentials', 'mod_aianatomy'),
            html_writer::div(s($status), $source === 'missing' ? 'alert alert-warning' : 'alert alert-info')
        )
    );
    // The LMS Labs endpoints are built in (see \mod_aianatomy\local\ai\endpoints); shown here for information.
    $settings->add(
        new admin_setting_heading(
            'mod_aianatomy/endpoints', get_string('lmslabs_endpoints', 'mod_aianatomy'),
            get_string(
                'lmslabs_endpoints_desc', 'mod_aianatomy', (object)[
                    'text' => s(\mod_aianatomy\local\ai\endpoints::text()),
                    'speech' => s(\mod_aianatomy\local\ai\endpoints::speech()),
                ]
            )
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'mod_aianatomy/lmslabs_useown',
            get_string('lmslabs_useown', 'mod_aianatomy'), get_string('lmslabs_useown_desc', 'mod_aianatomy'), 0
        )
    );
    $settings->add(
        new admin_setting_configtext(
            'mod_aianatomy/lmslabs_siteid',
            get_string('lmslabs_siteid', 'mod_aianatomy'), get_string('lmslabs_siteid_desc', 'mod_aianatomy'), '',
            PARAM_TEXT, 30
        )
    );
    $settings->add(
        new admin_setting_configpasswordunmask(
            'mod_aianatomy/lmslabs_apikey',
            get_string('lmslabs_apikey', 'mod_aianatomy'), get_string('lmslabs_apikey_desc', 'mod_aianatomy'), ''
        )
    );
    $settings->add(
        new admin_setting_configtext(
            'mod_aianatomy/lmslabs_timeout',
            get_string('lmslabs_timeout', 'mod_aianatomy'), '', 100, PARAM_INT, 6
        )
    );

    // Voiceover: LMS Labs text to speech (Chirp 3 HD voices), paid with LMS Labs AI credits.
    $settings->add(
        new admin_setting_heading(
            'mod_aianatomy/voiceheading', get_string('voicesettings', 'mod_aianatomy'),
            get_string('voicesettings_desc', 'mod_aianatomy')
        )
    );
    // Voice catalogue status (which of the eight voices LMS Labs offers for how many languages).
    $caps = \mod_aianatomy\local\ai\tts_lmslabs::capabilities();
    $settings->add(
        new admin_setting_heading(
            'mod_aianatomy/voicecatalogue', get_string('voicecatalogue', 'mod_aianatomy'),
            html_writer::div(
                s(
                    $caps === null ? get_string('voicecatalogue_unknown', 'mod_aianatomy')
                    : get_string('voicecatalogue_known', 'mod_aianatomy', count($caps))
                ), 'alert alert-info'
            )
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'mod_aianatomy/lmslabs_tts_studentgenerate',
            get_string('lmslabs_tts_studentgenerate', 'mod_aianatomy'),
            get_string('lmslabs_tts_studentgenerate_desc', 'mod_aianatomy'), 1
        )
    );

    $settings->add(
        new admin_setting_heading(
            'mod_aianatomy/defaultsheading', get_string('defaults', 'mod_aianatomy'),
            ''
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'mod_aianatomy/defaultsounds',
            get_string('defaultsounds', 'mod_aianatomy'), get_string('defaultsounds_desc', 'mod_aianatomy'), 1
        )
    );
}
