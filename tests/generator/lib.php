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
 * Data generator for mod_aianatomy.
 *
 * @package    mod_aianatomy
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_aianatomy_generator extends testing_module_generator {
    /**
     * Creates an instance with sensible defaults (hand pack, carpal bones selected).
     *
     * @param array|stdClass $record
     * @param array|null $options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;
        $defaults = [
            'pack' => 'hand_right', 'level' => 'diploma', 'grade' => 100, 'grademethod' => 1, 'maxattempts' => 0,
            'allowstudy' => 1, 'allowpractice' => 1, 'allowtest' => 1, 'timelimit' => 0, 'identweight' => 60,
            'quizcount' => 5, 'sounds' => 1, 'explode' => 100, 'showcontext' => 1, 'studytip' => '',
            'completionfinish' => 0, 'language' => 'en', 'voice' => 0, 'voicename' => 'Kore',
            'voiceplaces' => 'cards,prompts,questions,feedback', 'voiceauto' => 0,
        ];
        foreach ($defaults as $key => $value) {
            if (!isset($record->$key)) {
                $record->$key = $value;
            }
        }
        return parent::create_instance($record, (array)$options);
    }
}
