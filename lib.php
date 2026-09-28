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
 * Library of interface functions for mod_aianatomy.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Grade method: highest attempt. */
define('AIANATOMY_GRADEHIGHEST', 1);
/** Grade method: average of attempts. */
define('AIANATOMY_GRADEAVERAGE', 2);
/** Grade method: first attempt. */
define('AIANATOMY_GRADEFIRST', 3);
/** Grade method: last attempt. */
define('AIANATOMY_GRADELAST', 4);

/**
 * Declares the features this module supports.
 *
 * @param string $feature FEATURE_xx constant
 * @return mixed
 */
function aianatomy_supports($feature) {
    if (defined('FEATURE_MOD_OTHERPURPOSE') && $feature === FEATURE_MOD_OTHERPURPOSE) {
        return MOD_PURPOSE_INTERACTIVECONTENT;
    }
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_OTHER;
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Normalises form data before saving.
 *
 * @param stdClass $data
 * @return stdClass
 */
function aianatomy_prepare_instance_data(stdClass $data): stdClass {
    foreach (['allowpractice', 'allowtest', 'allowstudy', 'sounds'] as $flag) {
        $data->$flag = empty($data->$flag) ? 0 : 1;
    }
    if (isset($data->studyfieldset) && is_array($data->studyfieldset)) {
        $data->studyfields = implode(',', array_keys(array_filter($data->studyfieldset)));
    }
    if (!isset($data->studyfields) || $data->studyfields === '') {
        $data->studyfields = implode(',', \mod_aianatomy\local\pack::DEFAULT_STUDYFIELDS);
    }
    if (empty($data->pack) || !\mod_aianatomy\local\pack::valid_id($data->pack)) {
        $data->pack = 'hand_right';
    }
    if (!in_array($data->level ?? '', \mod_aianatomy\local\pack::LEVELS, true)) {
        $data->level = 'diploma';
    }
    $data->identweight = max(0, min(100, (int)($data->identweight ?? 60)));
    $data->quizcount = max(0, min(50, (int)($data->quizcount ?? 10)));
    $data->explode = max(0, min(200, (int)($data->explode ?? 100)));
    $data->showcontext = max(0, min(2, (int)($data->showcontext ?? 1)));
    $data->studytip = isset($data->studytip) ? trim((string)$data->studytip) : '';
    if (empty($data->allowpractice) && empty($data->allowtest) && empty($data->allowstudy)) {
        $data->allowpractice = 1;
    }
    $data->completionfinish = empty($data->completionfinish) ? 0 : 1;
    if (!isset($data->grade)) {
        $data->grade = 100;
    }
    $data->timelimit = empty($data->timelimit) ? 0 : (int)$data->timelimit;
    // Language and voiceover.
    $data->language = \mod_aianatomy\local\language::normalise($data->language ?? current_language());
    $data->voice = empty($data->voice) ? 0 : 1;
    $data->voiceauto = empty($data->voiceauto) ? 0 : 1;
    if (!isset(\mod_aianatomy\local\voice::VOICES[$data->voicename ?? ''])) {
        $data->voicename = 'Kore';
    }
    if (isset($data->voiceplaceset) && is_array($data->voiceplaceset)) {
        $data->voiceplaces = implode(
            ',', array_intersect(
                \mod_aianatomy\local\voice::PLACES,
                array_keys(array_filter($data->voiceplaceset))
            )
        );
    }
    if (!isset($data->voiceplaces)) {
        $data->voiceplaces = implode(',', \mod_aianatomy\local\voice::PLACES);
    }
    return $data;
}

/**
 * Adds a new instance.
 *
 * @param stdClass $data
 * @param mod_aianatomy_mod_form|null $mform
 * @return int new instance id
 */
function aianatomy_add_instance($data, $mform = null) {
    global $DB;
    $data = aianatomy_prepare_instance_data($data);
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->id = $DB->insert_record('aianatomy', $data);
    \mod_aianatomy\local\manager::sync_structures($DB->get_record('aianatomy', ['id' => $data->id], '*', MUST_EXIST));
    aianatomy_grade_item_update($data);
    if (!empty($data->completionexpected)) {
        \core_completion\api::update_completion_date_event(
            $data->coursemodule,
            'aianatomy',
            $data->id,
            $data->completionexpected
        );
    }
    return $data->id;
}

/**
 * Updates an instance.
 *
 * @param stdClass $data
 * @param mod_aianatomy_mod_form|null $mform
 * @return bool
 */
function aianatomy_update_instance($data, $mform = null) {
    global $DB;
    $data = aianatomy_prepare_instance_data($data);
    $data->id = $data->instance;
    $data->timemodified = time();
    $old = $DB->get_record('aianatomy', ['id' => $data->id], 'id, pack', MUST_EXIST);
    if ($old->pack !== $data->pack) {
        // Group translations and voice clips belong to the old pack.
        $data->grouptext = null;
        \mod_aianatomy\local\voice::clear($old, context_module::instance($data->coursemodule));
    }
    $DB->update_record('aianatomy', $data);
    $instance = $DB->get_record('aianatomy', ['id' => $data->id], '*', MUST_EXIST);
    \mod_aianatomy\local\manager::sync_structures($instance);
    aianatomy_grade_item_update($instance);
    aianatomy_update_grades($instance, 0, false);
    \core_completion\api::update_completion_date_event(
        $data->coursemodule,
        'aianatomy',
        $data->id,
        $data->completionexpected ?? null
    );
    return true;
}

/**
 * Deletes an instance and all its data.
 *
 * @param int $id
 * @return bool
 */
function aianatomy_delete_instance($id) {
    global $DB;
    if (!$instance = $DB->get_record('aianatomy', ['id' => $id])) {
        return false;
    }
    \mod_aianatomy\local\manager::delete_user_data((int)$id);
    $DB->delete_records('aianatomy_question', ['aianatomyid' => $id]);
    $DB->delete_records('aianatomy_structure', ['aianatomyid' => $id]);
    // Voice clip files go with the module context; the index rows are removed here.
    $DB->delete_records('aianatomy_voice', ['aianatomyid' => $id]);
    $DB->delete_records('aianatomy_job', ['aianatomyid' => $id]);
    aianatomy_grade_item_delete($instance);
    $DB->delete_records('aianatomy', ['id' => $id]);
    return true;
}

/**
 * Adds completion rule data to the course module info cache.
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info|false
 */
function aianatomy_get_coursemodule_info($coursemodule) {
    global $DB;
    $fields = 'id, name, intro, introformat, completionfinish';
    if (!$instance = $DB->get_record('aianatomy', ['id' => $coursemodule->instance], $fields)) {
        return false;
    }
    $result = new cached_cm_info();
    $result->name = $instance->name;
    if ($coursemodule->showdescription) {
        $result->content = format_module_intro('aianatomy', $instance, $coursemodule->id, false);
    }
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionfinish'] = $instance->completionfinish;
    }
    return $result;
}

/**
 * Creates or updates the grade item.
 *
 * @param stdClass $instance
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int GRADE_UPDATE_OK etc.
 */
function aianatomy_grade_item_update($instance, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $params = ['itemname' => $instance->name];
    if (isset($instance->cmidnumber)) {
        $params['idnumber'] = $instance->cmidnumber;
    }
    if ($instance->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = $instance->grade;
        $params['grademin'] = 0;
    } else if ($instance->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid'] = -$instance->grade;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }
    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }
    return grade_update('mod/aianatomy', $instance->course, 'mod', 'aianatomy', $instance->id, 0, $grades, $params);
}

/**
 * Deletes the grade item.
 *
 * @param stdClass $instance
 * @return int
 */
function aianatomy_grade_item_delete($instance) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    return grade_update(
        'mod/aianatomy',
        $instance->course,
        'mod',
        'aianatomy',
        $instance->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * Returns user grades computed from finished test attempts.
 *
 * @param stdClass $instance
 * @param int $userid 0 for all users
 * @return array userid => stdClass(userid, rawgrade, dategraded)
 */
function aianatomy_get_user_grades($instance, $userid = 0) {
    global $DB;
    $params = ['aid' => $instance->id, 'mode' => 'test', 'state' => 'finished'];
    $where = 'aianatomyid = :aid AND mode = :mode AND state = :state';
    if ($userid) {
        $where .= ' AND userid = :userid';
        $params['userid'] = $userid;
    }
    $attempts = $DB->get_records_select(
        'aianatomy_attempt',
        $where,
        $params,
        'userid, attempt',
        'id, userid, attempt, grade, timefinish'
    );
    $byuser = [];
    foreach ($attempts as $attempt) {
        $byuser[$attempt->userid][] = $attempt;
    }
    $grades = [];
    foreach ($byuser as $uid => $list) {
        $percent = aianatomy_calculate_percent($list, (int)$instance->grademethod);
        $last = end($list);
        $grade = new stdClass();
        $grade->userid = $uid;
        $grade->rawgrade = $instance->grade > 0 ? round($percent * $instance->grade / 100, 5) : null;
        $grade->dategraded = $last->timefinish;
        $grade->datesubmitted = $last->timefinish;
        $grades[$uid] = $grade;
    }
    return $grades;
}

/**
 * Applies the grading method to a list of attempts (ordered by attempt number).
 *
 * @param array $attempts
 * @param int $method
 * @return float percentage
 */
function aianatomy_calculate_percent(array $attempts, int $method): float {
    $attempts = array_values($attempts);
    if (!$attempts) {
        return 0.0;
    }
    switch ($method) {
        case AIANATOMY_GRADEAVERAGE:
            $sum = 0;
            foreach ($attempts as $a) {
                $sum += (float)$a->grade;
            }
            return $sum / count($attempts);
        case AIANATOMY_GRADEFIRST:
            return (float)$attempts[0]->grade;
        case AIANATOMY_GRADELAST:
            return (float)$attempts[count($attempts) - 1]->grade;
        default:
            $max = 0.0;
            foreach ($attempts as $a) {
                $max = max($max, (float)$a->grade);
            }
            return $max;
    }
}

/**
 * Pushes grades to the gradebook.
 *
 * @param stdClass $instance
 * @param int $userid
 * @param bool $nullifnone
 */
function aianatomy_update_grades($instance, $userid = 0, $nullifnone = true) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    if ($instance->grade == 0) {
        aianatomy_grade_item_update($instance);
        return;
    }
    if ($grades = aianatomy_get_user_grades($instance, $userid)) {
        aianatomy_grade_item_update($instance, $grades);
    } else if ($userid && $nullifnone) {
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        aianatomy_grade_item_update($instance, $grade);
    } else {
        aianatomy_grade_item_update($instance);
    }
}

/**
 * Adds plugin pages to the activity's secondary navigation.
 *
 * @param settings_navigation $settingsnav
 * @param navigation_node $node
 */
function aianatomy_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $node) {
    $cm = $settingsnav->get_page()->cm;
    if (!$cm) {
        return;
    }
    $context = context_module::instance($cm->id);
    if (has_capability('mod/aianatomy:manage', $context)) {
        $node->add(
            get_string('editanatomy', 'mod_aianatomy'),
            new moodle_url('/mod/aianatomy/editor.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'aianatomy_editor',
            new pix_icon('i/edit', '')
        );
    }
    if (has_capability('mod/aianatomy:viewreports', $context)) {
        $node->add(
            get_string('reports', 'mod_aianatomy'),
            new moodle_url('/mod/aianatomy/report.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'aianatomy_reports',
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Marks the activity viewed and triggers the event.
 *
 * @param stdClass $instance
 * @param stdClass $course
 * @param stdClass|cm_info $cm
 * @param context_module $context
 */
function aianatomy_view($instance, $course, $cm, $context) {
    $event = \mod_aianatomy\event\course_module_viewed::create(
        [
        'objectid' => $instance->id,
        'context' => $context,
        ]
    );
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('aianatomy', $instance);
    $event->trigger();
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Adds reset elements to the course reset form.
 *
 * @param MoodleQuickForm $mform
 */
function aianatomy_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'aianatomyheader', get_string('modulenameplural', 'mod_aianatomy'));
    $mform->addElement('advcheckbox', 'reset_aianatomy_attempts', get_string('resetattempts', 'mod_aianatomy'));
}

/**
 * Reset form defaults.
 *
 * @param stdClass $course
 * @return array
 */
function aianatomy_reset_course_form_defaults($course) {
    return ['reset_aianatomy_attempts' => 1];
}

/**
 * Removes user data when a course is reset.
 *
 * @param stdClass $data
 * @return array status
 */
function aianatomy_reset_userdata($data) {
    global $DB;
    $status = [];
    if (!empty($data->reset_aianatomy_attempts)) {
        $instances = $DB->get_records('aianatomy', ['course' => $data->courseid]);
        foreach ($instances as $instance) {
            \mod_aianatomy\local\manager::delete_user_data((int)$instance->id);
            if (empty($data->reset_gradebook_grades)) {
                aianatomy_grade_item_update($instance, 'reset');
            }
        }
        $status[] = [
            'component' => get_string('modulenameplural', 'mod_aianatomy'),
            'item' => get_string('resetattempts', 'mod_aianatomy'),
            'error' => false,
        ];
    }
    return $status;
}

/**
 * Resets gradebook grades for all instances in a course.
 *
 * @param int $courseid
 * @param string $type
 */
function aianatomy_reset_gradebook($courseid, $type = '') {
    global $DB;
    $instances = $DB->get_records('aianatomy', ['course' => $courseid]);
    foreach ($instances as $instance) {
        aianatomy_grade_item_update($instance, 'reset');
    }
}

/**
 * Serves voiceover clips (activity file area "voice").
 *
 * @param stdClass $course
 * @param cm_info|stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false if the file is not found
 */
function aianatomy_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE || $filearea !== 'voice') {
        return false;
    }
    require_login($course, true, $cm);
    require_capability('mod/aianatomy:view', $context);
    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    if ($itemid !== 0 || !preg_match('/^[0-9a-f]{64}\.mp3$/', (string)$filename)) {
        return false;
    }
    $file = get_file_storage()->get_file($context->id, 'mod_aianatomy', 'voice', 0, '/', $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    // Clips never change (the name is a hash of locale, voice, speed and text), so they can be cached.
    send_stored_file($file, YEARSECS, 0, false, $options);
}
