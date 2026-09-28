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
 * Backup structure for mod_aianatomy.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backup structure step.
 */
class backup_aianatomy_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $aianatomy = new backup_nested_element(
            'aianatomy', ['id'], [
                'name', 'intro', 'introformat', 'pack', 'level', 'studyfields', 'explode', 'showcontext', 'studytip',
                'grade', 'grademethod', 'maxattempts', 'allowstudy', 'allowpractice', 'allowtest', 'timelimit',
                'identweight', 'quizcount', 'sounds', 'completionfinish', 'language', 'grouptext', 'voice', 'voicename',
                'voiceplaces', 'voiceauto', 'timecreated', 'timemodified',
            ]
        );
        $structures = new backup_nested_element('structures');
        $structure = new backup_nested_element(
            'structure', ['id'], [
                'structureid', 'enabled', 'sortorder', 'label', 'anchor', 'labelpos', 'content', 'contentstatus',
                'contentlang', 'draft', 'draftlang', 'aigenerated', 'aimodel', 'timegenerated', 'approvedby',
                'timeapproved', 'timemodified',
            ]
        );
        $questions = new backup_nested_element('questions');
        $question = new backup_nested_element(
            'question', ['id'], [
                'structureid', 'sortorder', 'kind', 'questiontext', 'options', 'answer', 'explanation', 'status',
                'lang', 'aigenerated', 'timemodified',
            ]
        );
        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element(
            'attempt', ['id'], [
                'userid', 'attempt', 'mode', 'state', 'focus', 'tokenmap', 'timestart', 'timefinish', 'identcorrect',
                'identtotal', 'quizcorrect', 'quiztotal', 'grade', 'duration',
            ]
        );
        $responses = new backup_nested_element('responses');
        $response = new backup_nested_element(
            'response', ['id'], [
                'kind', 'structureid', 'questionid', 'answer', 'correct', 'tries', 'roundno', 'timecreated',
            ]
        );
        // Voiceover clips (teaching audio, no user data): kept so a restored or duplicated activity needs no new credits.
        $clips = new backup_nested_element('voiceclips');
        $clip = new backup_nested_element(
            'voiceclip', ['id'], [
                'hash', 'locale', 'voicename', 'speed', 'textlength', 'credits', 'requestid', 'timecreated',
            ]
        );
        $masteries = new backup_nested_element('masteries');
        $mastery = new backup_nested_element(
            'mastery', ['id'], [
                'userid', 'structureid', 'correct', 'wrong', 'streak', 'timemodified',
            ]
        );

        $aianatomy->add_child($structures);
        $structures->add_child($structure);
        $aianatomy->add_child($questions);
        $questions->add_child($question);
        $aianatomy->add_child($attempts);
        $attempts->add_child($attempt);
        $attempt->add_child($responses);
        $responses->add_child($response);
        $aianatomy->add_child($clips);
        $clips->add_child($clip);
        $aianatomy->add_child($masteries);
        $masteries->add_child($mastery);

        $aianatomy->set_source_table('aianatomy', ['id' => backup::VAR_ACTIVITYID]);
        $structure->set_source_table('aianatomy_structure', ['aianatomyid' => backup::VAR_PARENTID], 'sortorder ASC');
        $question->set_source_table('aianatomy_question', ['aianatomyid' => backup::VAR_PARENTID], 'id ASC');
        $clip->set_source_table('aianatomy_voice', ['aianatomyid' => backup::VAR_PARENTID], 'id ASC');
        if ($userinfo) {
            $attempt->set_source_table('aianatomy_attempt', ['aianatomyid' => backup::VAR_PARENTID], 'id ASC');
            $response->set_source_table('aianatomy_response', ['attemptid' => backup::VAR_PARENTID], 'id ASC');
            $mastery->set_source_table('aianatomy_mastery', ['aianatomyid' => backup::VAR_PARENTID], 'id ASC');
        }
        $attempt->annotate_ids('user', 'userid');
        $mastery->annotate_ids('user', 'userid');
        $structure->annotate_ids('user', 'approvedby');

        $aianatomy->annotate_files('mod_aianatomy', 'intro', null);
        $aianatomy->annotate_files('mod_aianatomy', 'voice', null);

        return $this->prepare_activity_structure($aianatomy);
    }
}
