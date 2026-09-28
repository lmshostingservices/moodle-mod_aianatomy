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
 * Upgrade steps for mod_aianatomy.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs upgrade steps.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_aianatomy_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026100100) {
        // Activity language and voiceover.
        $table = new xmldb_table('aianatomy');
        $fields = [
            new xmldb_field('language', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'en', 'completionfinish'),
            new xmldb_field('grouptext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'language'),
            new xmldb_field('voice', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'grouptext'),
            new xmldb_field('voicename', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'Kore', 'voice'),
            new xmldb_field(
                'voiceplaces', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null,
                'cards,questions,feedback,prompts', 'voicename'
            ),
            new xmldb_field('voiceauto', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'voiceplaces'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('aianatomy_structure');
        foreach ([new xmldb_field('contentlang', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'en', 'contentstatus'),
                  new xmldb_field('draftlang', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '', 'draft')] as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('aianatomy_question');
        $field = new xmldb_field('lang', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'en', 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('aianatomy_voice');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('aianatomyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('hash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('locale', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('voicename', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
            $table->add_field('speed', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'normal');
            $table->add_field('textlength', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('credits', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
            $table->add_field('requestid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('aianatomyid', XMLDB_KEY_FOREIGN, ['aianatomyid'], 'aianatomy', ['id']);
            $table->add_index('clip', XMLDB_INDEX_UNIQUE, ['aianatomyid', 'hash']);
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026100100, 'aianatomy');
    }

    if ($oldversion < 2026100101) {
        // The LMS Labs endpoints are now built in; remove the old admin settings so they cannot override them.
        foreach (['lmslabs_endpoint', 'lmslabs_tts_endpoint', 'lmslabs_tts_voiceformat'] as $name) {
            unset_config($name, 'mod_aianatomy');
        }
        upgrade_mod_savepoint(true, 2026100101, 'aianatomy');
    }

    if ($oldversion < 2026100200) {
        // LMS Labs requests in progress (persisted Idempotency-Key and body).
        $table = new xmldb_table('aianatomy_job');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('aianatomyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('operation', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('target', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('idemkey', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
            $table->add_field('body', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('meta', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('retryafter', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('aianatomyid', XMLDB_KEY_FOREIGN, ['aianatomyid'], 'aianatomy', ['id']);
            $table->add_index('job', XMLDB_INDEX_UNIQUE, ['aianatomyid', 'operation', 'target']);
            $dbman->create_table($table);
        }
        // LMS Labs pins the model; a model override is no longer sent.
        unset_config('lmslabs_model', 'mod_aianatomy');
        upgrade_mod_savepoint(true, 2026100200, 'aianatomy');
    }
    return true;
}
