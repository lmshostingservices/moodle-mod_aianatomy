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

namespace mod_aianatomy;

use mod_aianatomy\local\manager;
use mod_aianatomy\local\pack;

/**
 * Tests for structures, attempts, grading, mastery and questions.
 *
 * @package    mod_aianatomy
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aianatomy\local\manager
 */
final class manager_test extends \advanced_testcase {
    /**
     * Creates a course, a student and an activity.
     *
     * @param array $settings
     * @return array [instance, cm, course, context, student]
     */
    protected function setup_activity(array $settings = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('aianatomy', ['course' => $course->id] + $settings);
        [$course, $cm] = get_course_and_cm_from_instance($module->id, 'aianatomy');
        $instance = $DB->get_record('aianatomy', ['id' => $module->id], '*', MUST_EXIST);
        // Assess only the carpal bones so rounds and counts are predictable.
        $DB->set_field('aianatomy_structure', 'enabled', 0, ['aianatomyid' => $instance->id]);
        $DB->set_field_select(
            'aianatomy_structure', 'enabled', 1,
            "aianatomyid = :aid AND structureid IN ('skeletal_hand_right_scaphoid', 'skeletal_hand_right_lunate',
            'skeletal_hand_right_triquetrum', 'skeletal_hand_right_pisiform', 'skeletal_hand_right_trapezium',
            'skeletal_hand_right_trapezoid', 'skeletal_hand_right_capitate', 'skeletal_hand_right_hamate')",
            ['aid' => $instance->id]
        );
        return [$instance, $cm, $course, \context_module::instance($cm->id), $student];
    }

    /**
     * A new activity gets every pack structure, the default group enabled and the library questions.
     */
    public function test_sync_structures(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('aianatomy', ['course' => $course->id]);
        $instance = $DB->get_record('aianatomy', ['id' => $instance->id]);
        $pack = pack::get('hand_right');
        $this->assertSame(
            count($pack['structures']), $DB->count_records(
                'aianatomy_structure',
                ['aianatomyid' => $instance->id]
            )
        );
        // The whole hand (27 bones) is assessed by default; radius and ulna are context only.
        $this->assertSame(27, $DB->count_records('aianatomy_structure', ['aianatomyid' => $instance->id, 'enabled' => 1]));
        $this->assertGreaterThan(
            10, $DB->count_records(
                'aianatomy_question', ['aianatomyid' => $instance->id,
                'status' => 'library']
            )
        );
        // Running it again changes nothing.
        manager::sync_structures($instance);
        $this->assertSame(
            count($pack['structures']), $DB->count_records(
                'aianatomy_structure',
                ['aianatomyid' => $instance->id]
            )
        );
    }

    /**
     * Test attempts hide the answer key, are marked on the server and graded with the weighting.
     */
    public function test_test_attempt_grading(): void {
        global $DB;
        $this->resetAfterTest();
        [$instance, $cm, $course, $context, $student] = $this->setup_activity(['maxattempts' => 1, 'quizcount' => 5]);

        $data = manager::start_attempt($instance, $cm, $course, $context, 'test', $student->id);
        $this->assertCount(1, $data['rounds']);
        $round = $data['rounds'][0];
        $this->assertCount(8, $round['pins']);
        $this->assertEmpty($round['answers']);
        $this->assertEmpty($data['names']);
        $this->assertCount(5, $data['questions']);
        $this->assertArrayNotHasKey('answer', $data['questions'][0]);

        $attempt = $DB->get_record('aianatomy_attempt', ['id' => $data['attemptid']]);
        $map = json_decode($attempt->tokenmap, true);
        $labelfor = array_flip($map['labels']);
        $placements = [];
        foreach ($round['pins'] as $i => $pin) {
            if ($i < 6) {
                $placements[] = ['pin' => $pin['token'], 'label' => $labelfor[$map['pins'][$pin['token']]]];
            }
        }
        $this->assertSame(6, manager::submit_round($instance, $attempt, 0, $placements));
        $answers = [];
        foreach ($data['questions'] as $i => $q) {
            $info = $map['questions'][$q['token']];
            $correct = (int)$DB->get_field('aianatomy_question', 'answer', ['id' => $info['id']]);
            $shown = array_search($correct, $info['order'], true);
            $answers[] = ['token' => $q['token'], 'option' => $i < 4 ? $shown : ($shown + 1) % count($q['options'])];
        }
        manager::submit_quiz($instance, $DB->get_record('aianatomy_attempt', ['id' => $attempt->id]), $answers);
        $result = manager::finish_attempt(
            $instance, $cm, $course, $context,
            $DB->get_record('aianatomy_attempt', ['id' => $attempt->id])
        );

        $this->assertSame(6, $result['identcorrect']);
        $this->assertSame(8, $result['identtotal']);
        $this->assertSame(4, $result['quizcorrect']);
        // 60% identification (75%) + 40% questions (80%) = 77%.
        $this->assertEqualsWithDelta(77.0, $result['grade'], 0.01);

        $grades = grade_get_grades($course->id, 'mod', 'aianatomy', $instance->id, $student->id);
        $this->assertEqualsWithDelta(77.0, (float)$grades->items[0]->grades[$student->id]->grade, 0.01);

        // Attempt limit reached.
        $this->expectException(\moodle_exception::class);
        manager::start_attempt($instance, $cm, $course, $context, 'test', $student->id);
    }

    /**
     * A round cannot be submitted twice.
     */
    public function test_round_locked(): void {
        global $DB;
        $this->resetAfterTest();
        [$instance, $cm, $course, $context, $student] = $this->setup_activity();
        $data = manager::start_attempt($instance, $cm, $course, $context, 'test', $student->id);
        $attempt = $DB->get_record('aianatomy_attempt', ['id' => $data['attemptid']]);
        manager::submit_round($instance, $attempt, 0, []);
        $this->expectException(\moodle_exception::class);
        manager::submit_round($instance, $DB->get_record('aianatomy_attempt', ['id' => $attempt->id]), 0, []);
    }

    /**
     * Practice includes answers; results update mastery and weak practice focuses on weak structures.
     */
    public function test_practice_and_mastery(): void {
        global $DB;
        $this->resetAfterTest();
        [$instance, $cm, $course, $context, $student] = $this->setup_activity();
        $data = manager::start_attempt($instance, $cm, $course, $context, 'practice', $student->id);
        $round = $data['rounds'][0];
        $this->assertCount(8, $round['answers']);
        $this->assertNotEmpty($data['names']);
        $attempt = $DB->get_record('aianatomy_attempt', ['id' => $data['attemptid']]);
        $items = [];
        foreach ($round['pins'] as $i => $pin) {
            // Two structures wrong first time, the rest right twice (label + find).
            $items[] = ['kind' => 'label', 'token' => $pin['token'], 'correct' => 1, 'tries' => $i < 2 ? 3 : 1];
            $items[] = ['kind' => 'find', 'token' => $pin['token'], 'correct' => 1, 'tries' => $i < 2 ? 2 : 1];
        }
        $this->assertSame(16, manager::record_practice($instance, $attempt, $items));
        // Duplicates are ignored.
        $this->assertSame(
            0, manager::record_practice(
                $instance, $DB->get_record(
                    'aianatomy_attempt',
                    ['id' => $attempt->id]
                ), $items
            )
        );
        $mastery = manager::mastery($instance, $student->id);
        $states = array_count_values(array_column($mastery, 'state'));
        $this->assertSame(6, $states['mastered']);
        $this->assertSame(2, $states['difficult']);

        $weak = manager::start_attempt($instance, $cm, $course, $context, 'practice', $student->id, 'weak');
        $this->assertSame('weak', $weak['focus']);
        $this->assertCount(2, $weak['rounds'][0]['pins']);
    }

    /**
     * Enabling more groups adds rounds; label overrides, anchors and label positions are used.
     */
    public function test_save_structures(): void {
        $this->resetAfterTest();
        [$instance, $cm, $course, $context, $student] = $this->setup_activity();
        $items = [];
        for ($d = 1; $d <= 5; $d++) {
            $items[] = ['structureid' => 'skeletal_hand_right_metacarpal_' . $d, 'enabled' => 1,
                'label' => $d === 1 ? 'Thumb metacarpal' : '', 'anchor' => $d === 1 ? '1.5,-2,3' : '',
                'labelpos' => $d === 1 ? '0.1,0.2' : ''];
        }
        $this->assertSame(5, manager::save_structures($instance, $items));
        $data = manager::start_attempt($instance, $cm, $course, $context, 'test', $student->id);
        $this->assertCount(2, $data['rounds']);
        $texts = array_column($data['rounds'][1]['labels'], 'text');
        $this->assertContains('Thumb metacarpal', $texts);
        $withanchor = array_filter($data['rounds'][1]['pins'], fn($p) => isset($p['anchor']));
        $this->assertCount(1, $withanchor);
        $pin = reset($withanchor);
        $this->assertSame([1.5, -2.0, 3.0], $pin['anchor']);
        $this->assertSame(['x' => 0.1, 'y' => 0.2], $pin['labelpos']);
    }

    /**
     * Content review: drafts stay hidden until approved; reset restores the library content.
     */
    public function test_content_workflow(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$instance, , , $context] = $this->setup_activity(['allowstudy' => 1]);
        $sid = 'skeletal_hand_right_scaphoid';
        manager::store_draft($instance, $sid, ['latin' => 'Draft latin', 'function' => 'Draft <b>function</b>'], 'Test AI');
        $study = manager::study_data($instance, $context);
        $scaphoid = array_values(array_filter($study['structures'], fn($s) => $s['id'] === $sid))[0];
        $this->assertSame('Os scaphoideum', $scaphoid['content']['latin']);

        $result = manager::save_content(
            $instance, $sid, 'approve', ['latin' => 'Draft latin',
            'function' => 'Draft <b>function</b>'], (int)$USER->id
        );
        $this->assertSame('approved', $result['status']);
        $this->assertTrue($result['aigenerated']);
        $this->assertNull($result['draft']);
        $this->assertSame('Draft function', $result['content']['function']);

        $result = manager::save_content($instance, $sid, 'reset', [], (int)$USER->id);
        $this->assertSame('library', $result['status']);
        $this->assertSame('Os scaphoideum', $result['content']['latin']);
        $this->assertSame(
            0, (int)$DB->get_field(
                'aianatomy_structure', 'approvedby',
                ['aianatomyid' => $instance->id, 'structureid' => $sid]
            )
        );
    }

    /**
     * Questions: empty options are dropped and the correct answer still points at the right option.
     */
    public function test_save_question(): void {
        $this->resetAfterTest();
        [$instance] = $this->setup_activity();
        $q = manager::save_question(
            $instance, ['structureid' => 'skeletal_hand_right_lunate', 'kind' => 'location',
            'text' => 'Where?', 'options' => ['A', '', 'C', 'D'], 'answer' => 2, 'explanation' => '', 'status' => 'approved']
        );
        $this->assertSame(['A', 'C', 'D'], $q['options']);
        $this->assertSame(1, $q['answer']);
        $this->expectException(\moodle_exception::class);
        manager::save_question(
            $instance, ['structureid' => 'skeletal_hand_right_lunate', 'text' => 'Bad',
            'options' => ['A', 'B'], 'answer' => 1 + 5, 'status' => 'approved']
        );
    }
}
