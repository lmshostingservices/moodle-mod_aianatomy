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
 * Teacher editor: choose structures, place labels on the 3D model, review AI teaching content and questions.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_aianatomy\local\manager;

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'aianatomy');
$instance = $DB->get_record('aianatomy', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aianatomy:manage', $context);

// Make sure every pack structure has a row (e.g. after a pack update).
manager::sync_structures($instance);

$PAGE->set_url('/mod/aianatomy/editor.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name) . ': ' . get_string('editanatomy', 'mod_aianatomy'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();

$data = manager::editor_data($instance, $context);
$config = [
    'cmid' => (int)$cm->id,
    'pack' => manager::pack_meta($instance),
    'canuseai' => has_capability('mod/aianatomy:useai', $context),
    'viewurl' => (new moodle_url('/mod/aianatomy/view.php', ['id' => $cm->id]))->out(false),
    'settingsurl' => (new moodle_url('/course/modedit.php', ['update' => $cm->id]))->out(false),
] + $data;

$PAGE->requires->js_call_amd('mod_aianatomy/editor', 'init', ['#aa-editor-' . $cm->id]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template(
    'mod_aianatomy/editor', [
        'uniqid' => 'aa-editor-' . $cm->id,
        'name' => format_string($instance->name, true, ['context' => $context]),
        'config' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    ]
);
echo $OUTPUT->footer();
