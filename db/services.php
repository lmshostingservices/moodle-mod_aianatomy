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
 * External functions for mod_aianatomy.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_aianatomy_start_attempt' => [
        'classname' => \mod_aianatomy\external\start_attempt::class,
        'description' => 'Starts a practice or test attempt and returns the player data.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aianatomy:attempt',
    ],
    'mod_aianatomy_record_practice' => [
        'classname' => \mod_aianatomy\external\record_practice::class,
        'description' => 'Records practice results (drives mastery and reports).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aianatomy:attempt',
    ],
    'mod_aianatomy_submit_round' => [
        'classname' => \mod_aianatomy\external\submit_round::class,
        'description' => 'Submits and locks the labels placed in one test round.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aianatomy:attempt',
    ],
    'mod_aianatomy_submit_quiz' => [
        'classname' => \mod_aianatomy\external\submit_quiz::class,
        'description' => 'Submits the knowledge question answers of a test attempt.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aianatomy:attempt',
    ],
    'mod_aianatomy_finish_attempt' => [
        'classname' => \mod_aianatomy\external\finish_attempt::class,
        'description' => 'Finishes and grades an attempt.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aianatomy:attempt',
    ],
    'mod_aianatomy_save_structures' => [
        'classname' => \mod_aianatomy\external\save_structures::class,
        'description' => 'Saves which structures are used, label text, anchors and label positions.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aianatomy:manage',
    ],
    'mod_aianatomy_save_content' => [
        'classname' => \mod_aianatomy\external\save_content::class,
        'description' => 'Saves, approves, discards or resets the teaching content of a structure.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aianatomy:manage',
    ],
    'mod_aianatomy_generate' => [
        'classname' => \mod_aianatomy\external\generate::class,
        'description' => 'Generates draft teaching content or questions with LMS Labs AI for teacher review.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aianatomy:manage, mod/aianatomy:useai',
    ],
    'mod_aianatomy_save_question' => [
        'classname' => \mod_aianatomy\external\save_question::class,
        'description' => 'Creates, updates, approves or deletes a knowledge question.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aianatomy:manage',
    ],
    'mod_aianatomy_translate' => [
        'classname' => \mod_aianatomy\external\translate::class,
        'description' => 'Translates a structure (content, name, questions) or the group names into the activity ' .
            'language with LMS Labs AI, as drafts for teacher review.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aianatomy:manage, mod/aianatomy:useai',
    ],
    'mod_aianatomy_save_groups' => [
        'classname' => \mod_aianatomy\external\save_groups::class,
        'description' => 'Saves the activity\'s own group names and study tips.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aianatomy:manage',
    ],
    'mod_aianatomy_speak' => [
        'classname' => \mod_aianatomy\external\speak::class,
        'description' => 'Returns voiceover audio for text signed by the activity (LMS Labs text to speech).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aianatomy:view',
    ],
    'mod_aianatomy_voice_prepare' => [
        'classname' => \mod_aianatomy\external\voice_prepare::class,
        'description' => 'Reports and advances the preparation of an activity\'s voiceover (LMS Labs text to speech).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aianatomy:view',
    ],
    'mod_aianatomy_voice_clear' => [
        'classname' => \mod_aianatomy\external\voice_clear::class,
        'description' => 'Deletes the stored voiceover clips of an activity.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aianatomy:manage',
    ],
];
