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
 * Lists all AI Anatomy activities in a course.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);
$coursecontext = context_course::instance($course->id);
require_capability('mod/aianatomy:view', $coursecontext);

$event = \mod_aianatomy\event\course_module_instance_list_viewed::create(
    [
    'context' => $coursecontext,
    ]
);
$event->add_record_snapshot('course', $course);
$event->trigger();

// Moodle 5.0+ provides a unified activity overview page.
if (class_exists('\core_courseformat\activityoverviewbase')) {
    \core_courseformat\activityoverviewbase::redirect_to_overview_page($course->id, 'aianatomy');
}

$PAGE->set_url('/mod/aianatomy/index.php', ['id' => $course->id]);
$PAGE->set_title(format_string($course->shortname) . ': ' . get_string('modulenameplural', 'mod_aianatomy'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_aianatomy'));

$instances = get_all_instances_in_course('aianatomy', $course);
if (!$instances) {
    notice(
        get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_aianatomy')),
        new moodle_url('/course/view.php', ['id' => $course->id])
    );
}
$table = new html_table();
$table->head = [get_string('name'), get_string('description')];
foreach ($instances as $instance) {
    $link = html_writer::link(
        new moodle_url('/mod/aianatomy/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name),
        $instance->visible ? [] : ['class' => 'dimmed']
    );
    $table->data[] = [$link, format_module_intro('aianatomy', $instance, $instance->coursemodule)];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
