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

namespace mod_aianatomy\task;

use mod_aianatomy\local\voice;

/**
 * Prepares an activity's voiceover in the background (cron), so students do not wait for it.
 *
 * Queued when an activity is created or saved and when its teaching texts change. Runs for up to ten minutes, then
 * queues itself again while clips are still missing or LMS Labs is still generating. Stops when everything is
 * ready or the site's LMS Labs setup prevents generation (the teacher sees why in the editor).
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prepare_voice extends \core\task\adhoc_task {
    /** Seconds of generation per run. */
    const BUDGET = 600;

    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_preparevoice', 'mod_aianatomy');
    }

    /**
     * Runs the task.
     */
    public function execute() {
        global $DB;
        $data = $this->get_custom_data();
        $instance = $DB->get_record('aianatomy', ['id' => (int)($data->instanceid ?? 0)]);
        if (!$instance) {
            return;
        }
        $cm = get_coursemodule_from_instance('aianatomy', $instance->id, $instance->course, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $context = \context_module::instance($cm->id);
        \core_php_time_limit::raise(self::BUDGET + 120);
        $r = voice::prepare($instance, $context, self::BUDGET);
        mtrace(
            'AI Anatomy voiceover ' . $instance->id . ': ' . $r['state'] . ' ' . $r['ready'] . '/' . $r['total']
            . ($r['error'] !== '' ? ' (' . $r['error'] . ')' : '')
        );
        if ($r['state'] === 'preparing') {
            // More to do (or LMS Labs is still generating): run again shortly with the same persisted jobs.
            // Ready, incomplete (the rest failed for good) and failed (site setup) all stop here.
            $next = new self();
            $next->set_custom_data(['instanceid' => (int)$instance->id]);
            $next->set_next_run_time(time() + max(30, (int)$r['retryafter']));
            \core\task\manager::queue_adhoc_task($next, true);
        }
    }
}
