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
 * Activity view page: mode chooser, mastery overview and the 3D player.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_aianatomy\local\manager;

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'aianatomy');
$instance = $DB->get_record('aianatomy', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aianatomy:view', $context);
// The activity language switches the interface too (when its Moodle language pack is installed).
\mod_aianatomy\local\language::apply($instance);

aianatomy_view($instance, $course, $cm, $context);

$PAGE->set_url('/mod/aianatomy/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));

$canattempt = has_capability('mod/aianatomy:attempt', $context) && !isguestuser();
$canmanage = has_capability('mod/aianatomy:manage', $context);
$rows = manager::get_structures($instance->id);
$targetcount = count(array_filter($rows, fn($r) => $r->enabled));
$ready = $targetcount > 0;
$summary = manager::user_summary($instance, (int)$USER->id);
$passpercent = manager::pass_percent($instance);
$str = fn($k, $a = null) => get_string($k, 'mod_aianatomy', $a);

// What this user has done in each mode.
$done = ['practice' => null, 'test' => null];
if (!isguestuser()) {
    $best = $DB->get_records_sql(
        "SELECT mode, MAX(grade) AS best
           FROM {aianatomy_attempt}
          WHERE aianatomyid = :aid AND userid = :userid AND state = :state
       GROUP BY mode",
        ['aid' => $instance->id, 'userid' => $USER->id, 'state' => 'finished']
    );
    foreach (['practice', 'test'] as $m) {
        $done[$m] = isset($best[$m]) ? round((float)$best[$m]->best, 1) : null;
    }
}
$testpassed = $done['test'] !== null && (!$passpercent || $done['test'] >= $passpercent);

$compare = function (array $values) use ($str): array {
    $out = [];
    foreach ($values as $key => [$yes, $text]) {
        $out[] = ['label' => $str('cmp_' . $key), 'text' => $text, 'yes' => $yes];
    }
    return $out;
};
$modes = [];
if ($instance->allowstudy) {
    $modes[] = ['key' => 'study', 'enabled' => $ready, 'title' => $str('modestudy'), 'bestfor' => $str('bestfor_study'),
        'rows' => $compare(
            [
                'explore' => [true, $str('cmp_explore_study')],
                'feedback' => [true, $str('cmp_feedback_cards')],
                'hints' => [false, $str('cmp_notneeded')],
                'graded' => [false, $str('cmp_no')],
            ]
        ), 'done' => false];
}
if ($instance->allowpractice) {
    $modes[] = ['key' => 'practice', 'enabled' => $ready && $canattempt, 'title' => $str('modepractice'),
        'bestfor' => $str('bestfor_practice'),
        'rows' => $compare(
            [
                'explore' => [true, $str('cmp_explore_practice')],
                'feedback' => [true, $str('cmp_feedback_instant')],
                'hints' => [true, $str('cmp_hints_yes')],
                'graded' => [false, $str('cmp_no_unlimited')],
            ]
        ),
        'meta' => $done['practice'] !== null ? $str('bestfirsttry', $done['practice']) : '',
        'done' => $done['practice'] !== null,
        'status' => $done['practice'] !== null ? $str('status_practised') : null];
}
if ($instance->allowtest) {
    $meta = [];
    if ($instance->maxattempts) {
        $meta[] = $str('attemptsused', ['used' => $summary['attemptsused'], 'max' => $instance->maxattempts]);
    }
    if ($instance->timelimit) {
        $meta[] = $str('timelimitx', format_time($instance->timelimit));
    }
    if ($summary['best'] !== null) {
        $meta[] = $str('bestscore', $summary['best']);
    }
    $modes[] = ['key' => 'test', 'enabled' => $ready && $canattempt && $summary['attemptsleft'] !== 0,
        'title' => $str('modetest'), 'bestfor' => $str('bestfor_test'),
        'rows' => $compare(
            [
                'explore' => [true, $str('cmp_explore_test')],
                'feedback' => [false, $str('cmp_feedback_end')],
                'hints' => [false, $str('cmp_hints_no')],
                'graded' => [$instance->grade != 0, $instance->grade != 0 ? $str('cmp_graded_yes') : $str('cmp_no')],
            ]
        ),
        'meta' => implode(' · ', $meta),
        'nomore' => $summary['attemptsleft'] === 0 && !$testpassed,
        'done' => $testpassed,
        'status' => $testpassed ? $str($passpercent ? 'status_passed' : 'status_completed') : null,
        'warn' => ($done['test'] !== null && !$testpassed) ? $str('status_notpassed', $passpercent) : null];
}

// Mastery overview.
$mastery = ['mastered' => 0, 'learning' => 0, 'difficult' => 0, 'new' => 0];
$difficult = [];
if ($canattempt) {
    $pack = \mod_aianatomy\local\pack::get($instance->pack);
    foreach (manager::mastery($instance, (int)$USER->id) as $sid => $m) {
        $mastery[$m['state']]++;
        if ($m['state'] === 'difficult' && isset($pack['byid'][$sid])) {
            $difficult[] = format_string(
                manager::display_name($rows[$sid], $pack['byid'][$sid]), true,
                ['context' => $context]
            );
        }
    }
}

// Completion requirements, as shown on the start screen.
$completionrules = [];
$cminfo = get_fast_modinfo($course)->get_cm($cm->id);
if ($cminfo->completion == COMPLETION_TRACKING_AUTOMATIC && !isguestuser()) {
    $details = \core_completion\cm_completion_details::get_instance($cminfo, (int)$USER->id);
    foreach ($details->get_details() as $rule => $detail) {
        if ($rule !== 'completionview') {
            $completionrules[] = ['rule' => $rule, 'text' => $detail->description];
        }
    }
}

$config = [
    'cmid' => (int)$cm->id,
    'canattempt' => $canattempt,
    'passpercent' => $passpercent,
    'graded' => $instance->grade != 0,
    'maxattempts' => (int)$instance->maxattempts,
    'attemptsleft' => (int)$summary['attemptsleft'],
    'timelimit' => (int)$instance->timelimit,
    'timelimittext' => $instance->timelimit ? format_time($instance->timelimit) : '',
    'targetcount' => $targetcount,
    'quizcount' => (int)$instance->quizcount,
    'identweight' => (int)$instance->identweight,
    'completion' => $completionrules,
    'allowtest' => (int)$instance->allowtest,
    'name' => format_string($instance->name, true, ['context' => $context]),
    'sounds' => (int)$instance->sounds,
    'pack' => $ready ? manager::pack_meta($instance) : null,
    // Study data (names on the model) is only sent when Study mode is on.
    'study' => ($ready && $instance->allowstudy) ? manager::study_data($instance, $context) : null,
    'mastery' => $mastery,
    'voice' => $ready ? manager::voice_config($instance) : ['enabled' => false, 'places' => [], 'auto' => false,
        'phrases' => []],
    'locale' => \mod_aianatomy\local\language::locale($instance->language ?? 'en'),
];

$packinfo = \mod_aianatomy\local\pack::get($instance->pack);
$packname = \mod_aianatomy\local\pack::name($instance->pack);
foreach ($packinfo['groups'] as $g) {
    if ($g['id'] === $packinfo['root'] && !empty($instance->grouptext)) {
        $packname = manager::group_text($instance, $g)[0];
    }
}

$templatedata = [
    'uniqid' => 'aa-' . $cm->id,
    'config' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    'modes' => $modes,
    'ready' => $ready,
    'targetcount' => $targetcount,
    'packname' => format_string($packname, true, ['context' => $context]),
    'canmanage' => $canmanage,
    'editorurl' => (new moodle_url('/mod/aianatomy/editor.php', ['id' => $cm->id]))->out(false),
    'reporturl' => has_capability('mod/aianatomy:viewreports', $context)
        ? (new moodle_url('/mod/aianatomy/report.php', ['id' => $cm->id]))->out(false) : null,
    'guest' => !$canattempt,
    'showmastery' => $canattempt && $instance->allowpractice && ($mastery['mastered'] + $mastery['learning']
        + $mastery['difficult']) > 0,
    'mastery' => $mastery,
    'difficult' => implode(', ', $difficult),
    'hasdifficult' => (bool)$difficult,
    'canweak' => $canattempt && $instance->allowpractice && ($mastery['learning'] + $mastery['difficult']) > 0,
];

$PAGE->requires->js_call_amd('mod_aianatomy/player', 'init', ['#aa-' . $cm->id]);

echo $OUTPUT->header();
// Teachers: say plainly when the activity language is not English but the content still is.
$activitylang = \mod_aianatomy\local\language::normalise($instance->language ?? 'en');
if ($canmanage && !\mod_aianatomy\local\language::same($activitylang, 'en')) {
    $enabledrows = array_filter($rows, fn($r) => $r->enabled);
    $untranslated = array_filter(
        $enabledrows,
        fn($r) => !\mod_aianatomy\local\language::same((string)($r->contentlang ?? 'en'), $activitylang)
    );
    if ($untranslated) {
        $notice = $str('lang_teachernotice', ['n' => count($untranslated), 'total' => count($enabledrows),
            'lang' => \mod_aianatomy\local\language::native_name($activitylang)]);
        $link = html_writer::link(
            new moodle_url('/mod/aianatomy/editor.php', ['id' => $cm->id]),
            $str('editanatomy'),
            ['class' => 'btn btn-primary btn-sm ms-2']
        );
        echo $OUTPUT->notification($notice . ' ' . $link, \core\output\notification::NOTIFY_WARNING, false);
    }
}
echo $OUTPUT->render_from_template('mod_aianatomy/view', $templatedata);
echo $OUTPUT->footer();
