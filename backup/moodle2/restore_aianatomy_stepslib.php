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
 * Restore structure for mod_aianatomy.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore structure step.
 */
class restore_aianatomy_activity_structure_step extends restore_activity_structure_step {
    /**
     * Defines the paths.
     *
     * @return array
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');
        $paths = [];
        $paths[] = new restore_path_element('aianatomy', '/activity/aianatomy');
        $paths[] = new restore_path_element('aianatomy_structure', '/activity/aianatomy/structures/structure');
        $paths[] = new restore_path_element('aianatomy_question', '/activity/aianatomy/questions/question');
        $paths[] = new restore_path_element('aianatomy_voice', '/activity/aianatomy/voiceclips/voiceclip');
        if ($userinfo) {
            $paths[] = new restore_path_element('aianatomy_attempt', '/activity/aianatomy/attempts/attempt');
            $paths[] = new restore_path_element('aianatomy_response', '/activity/aianatomy/attempts/attempt/responses/response');
            $paths[] = new restore_path_element('aianatomy_mastery', '/activity/aianatomy/masteries/mastery');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restores the instance.
     *
     * @param array $data
     */
    protected function process_aianatomy($data) {
        global $DB;
        $data = (object)$data;
        $data->course = $this->get_courseid();
        $data->timemodified = time();
        $newid = $DB->insert_record('aianatomy', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Restores a structure row.
     *
     * @param array $data
     */
    protected function process_aianatomy_structure($data) {
        global $DB;
        $data = (object)$data;
        $data->aianatomyid = $this->get_new_parentid('aianatomy');
        $data->approvedby = $data->approvedby ? (int)$this->get_mappingid('user', $data->approvedby, 0) : 0;
        $DB->insert_record('aianatomy_structure', $data);
    }

    /**
     * Restores a question.
     *
     * @param array $data
     */
    protected function process_aianatomy_question($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->aianatomyid = $this->get_new_parentid('aianatomy');
        $newid = $DB->insert_record('aianatomy_question', $data);
        $this->set_mapping('aianatomy_question', $oldid, $newid);
    }

    /**
     * Restores a voiceover clip index row (the audio file is restored with the file area).
     *
     * @param array $data
     */
    protected function process_aianatomy_voice($data) {
        global $DB;
        $data = (object)$data;
        $data->aianatomyid = $this->get_new_parentid('aianatomy');
        if (!$DB->record_exists('aianatomy_voice', ['aianatomyid' => $data->aianatomyid, 'hash' => $data->hash])) {
            $DB->insert_record('aianatomy_voice', $data);
        }
    }

    /**
     * Restores an attempt (question ids inside the token map are remapped).
     *
     * @param array $data
     */
    protected function process_aianatomy_attempt($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->aianatomyid = $this->get_new_parentid('aianatomy');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!empty($data->tokenmap)) {
            $map = json_decode($data->tokenmap, true);
            if (is_array($map)) {
                foreach ($map['questions'] ?? [] as $token => $info) {
                    $map['questions'][$token]['id'] = (int)$this->get_mappingid('aianatomy_question', $info['id']);
                }
                $data->tokenmap = json_encode($map);
            }
        }
        $newid = $DB->insert_record('aianatomy_attempt', $data);
        $this->set_mapping('aianatomy_attempt', $oldid, $newid);
    }

    /**
     * Restores a response.
     *
     * @param array $data
     */
    protected function process_aianatomy_response($data) {
        global $DB;
        $data = (object)$data;
        $data->attemptid = $this->get_new_parentid('aianatomy_attempt');
        $data->questionid = $data->questionid ? (int)$this->get_mappingid('aianatomy_question', $data->questionid) : 0;
        $DB->insert_record('aianatomy_response', $data);
    }

    /**
     * Restores a mastery row.
     *
     * @param array $data
     */
    protected function process_aianatomy_mastery($data) {
        global $DB;
        $data = (object)$data;
        $data->aianatomyid = $this->get_new_parentid('aianatomy');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if ($data->userid) {
            $DB->insert_record('aianatomy_mastery', $data);
        }
    }

    /**
     * Restores files after the structure.
     */
    protected function after_execute() {
        $this->add_related_files('mod_aianatomy', 'intro', null);
        $this->add_related_files('mod_aianatomy', 'voice', null);
    }
}
