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

namespace mod_aianatomy\event;

/**
 * AI draft content generated (or pasted) for teacher review.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_generated extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventcontentgenerated', 'mod_aianatomy');
    }

    /**
     * Event description.
     *
     * @return string
     */
    public function get_description() {
        $what = s($this->other['what'] ?? '');
        $sid = s($this->other['structureid'] ?? '');
        $model = s($this->other['model'] ?? '');
        return "The user with id '$this->userid' generated AI draft $what for '$sid' using '$model' " .
            "in the AI Anatomy activity with course module id '$this->contextinstanceid'.";
    }

    /**
     * Event URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/aianatomy/editor.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Other mapping for restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
