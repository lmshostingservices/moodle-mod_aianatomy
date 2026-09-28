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
 * Serves the 3D model (GLB) of an activity's anatomy pack to users who can view the activity.
 *
 * The URL carries the pack version, so browsers may keep the file for a long time.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'aianatomy');
$instance = $DB->get_record('aianatomy', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aianatomy:view', $context);

if (!\mod_aianatomy\local\pack::valid_id($instance->pack)) {
    throw new moodle_exception('invalidpack', 'mod_aianatomy');
}
$path = \mod_aianatomy\local\pack::model_path($instance->pack);
if (!is_readable($path)) {
    send_file_not_found();
}
\core\session\manager::write_close();
send_file($path, $instance->pack . '.glb', YEARSECS, 0, false, false, 'model/gltf-binary');
