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

use mod_aianatomy\local\ai\generator;
use mod_aianatomy\local\ai\lmslabs;
use mod_aianatomy\local\ai\tts_lmslabs;
use mod_aianatomy\local\language;
use mod_aianatomy\local\manager;
use mod_aianatomy\local\pack;
use mod_aianatomy\local\voice;

/**
 * Tests for voiceover (LMS Labs text to speech, no external calls) and activity languages.
 *
 * @package    mod_aianatomy
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aianatomy\local\voice
 * @covers     \mod_aianatomy\local\ai\tts_lmslabs
 * @covers     \mod_aianatomy\local\language
 */
final class voice_test extends \advanced_testcase {
    /**
     * Removes the transport seam after each test.
     */
    protected function tearDown(): void {
        lmslabs::$transport = null;
        parent::tearDown();
    }
    /**
     * Creates an activity with voiceover on and LMS Labs configured.
     *
     * @param array $settings
     * @return array [instance, context]
     */
    protected function setup_voice(array $settings = []): array {
        global $DB;
        set_config('lmslabs_siteid', 'site-1', 'mod_aianatomy');
        set_config('lmslabs_apikey', 'key-1', 'mod_aianatomy');
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('aianatomy', ['course' => $course->id, 'voice' => 1] + $settings);
        $instance = $DB->get_record('aianatomy', ['id' => $module->id], '*', MUST_EXIST);
        return [$instance, \context_module::instance($module->cmid)];
    }

    /**
     * Clips are at most 200 code points and keep all the text.
     */
    public function test_chunks(): void {
        $text = str_repeat('The scaphoid links both carpal rows, and it is often fractured. ', 8) . str_repeat('x', 450);
        $chunks = voice::chunks($text);
        $this->assertGreaterThan(3, count($chunks));
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(200, \core_text::strlen($c));
        }
        $this->assertSame(str_replace(' ', '', $text), str_replace(' ', '', implode('', $chunks)));
        foreach (voice::chunks(str_repeat('手の骨は二十七個あります。', 30)) as $c) {
            $this->assertLessThanOrEqual(200, \core_text::strlen($c));
        }
    }

    /**
     * The endpoints are built in; config.php can force others.
     */
    public function test_endpoints(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->assertSame('https://lms-labs.com/api/moodle/ai-anatomy/speech/tts', \mod_aianatomy\local\ai\endpoints::speech());
        $this->assertSame('https://lms-labs.com/api/moodle/ai-anatomy/text', \mod_aianatomy\local\ai\endpoints::text());
        $CFG->forced_plugin_settings['mod_aianatomy']['lmslabs_tts_endpoint'] = 'https://staging.example/tts';
        $this->assertSame('https://staging.example/tts', \mod_aianatomy\local\ai\endpoints::speech());
        unset($CFG->forced_plugin_settings['mod_aianatomy']);
    }

    /**
     * Unsigned text is refused, so credits can only be spent on the activity's own content.
     */
    public function test_signature_required(): void {
        $this->resetAfterTest();
        [$instance, $context] = $this->setup_voice();
        lmslabs::$transport = fn() => $this->fail('No request may be sent for unsigned text');
        $this->expectException(\moodle_exception::class);
        voice::speak($instance, $context, 'Spend my credits', str_repeat('0', 64), 'normal', true);
    }

    /**
     * 202 is polled later with the same key and body; audio is stored once and replays are free.
     */
    public function test_pending_then_cached(): void {
        global $DB;
        $this->resetAfterTest();
        // Catalogue unknown: all eight voices offered.
        set_config('ttscapabilities', json_encode(['time' => time(), 'locales' => null]), 'mod_aianatomy');
        [$instance, $context] = $this->setup_voice(['language' => 'de', 'voicename' => 'Charon']);
        $requests = [];
        lmslabs::$transport = function (array $req) use (&$requests) {
            $requests[] = $req;
            return count($requests) === 1 ? [202, ['Retry-After' => ['5']], '{"code":"PENDING"}']
                : [200, ['Content-Type' => ['audio/mpeg'], 'X-Credits-Charged' => ['1'],
                    'X-Idempotent-Replay' => ['false']], 'ID3audio'];
        };
        $item = voice::item($instance, 'Das Kahnbein');
        $r = voice::speak($instance, $context, $item['text'], $item['sig'], 'normal', true);
        $this->assertTrue($r['pending']);
        $this->assertSame(5, $r['retryafter']);
        $this->assertSame([], $r['clips']);
        $this->assertSame(1, $DB->count_records('aianatomy_job'));
        $r = voice::speak($instance, $context, $item['text'], $item['sig'], 'normal', true);
        $this->assertFalse($r['pending']);
        $this->assertCount(1, $r['clips']);
        $this->assertSame(0, $DB->count_records('aianatomy_job'));
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0]['headers']['Idempotency-Key'], $requests[1]['headers']['Idempotency-Key']);
        $this->assertSame($requests[0]['body'], $requests[1]['body']);
        $this->assertSame('key-1', $requests[0]['headers']['X-API-Key']);
        $this->assertSame('site-1', $requests[0]['headers']['X-Site-ID']);
        $this->assertArrayNotHasKey('Authorization', $requests[0]['headers']);
        $body = json_decode($requests[0]['body'], true);
        $this->assertSame(['text', 'locale', 'voice', 'speed'], array_keys($body));
        $this->assertSame('de-DE', $body['locale']);
        $this->assertSame('Charon', $body['voice']);
        // Replay: no new request.
        $r = voice::speak($instance, $context, $item['text'], $item['sig'], 'normal', true);
        $this->assertSame(0, $r['generated']);
        $this->assertCount(2, $requests);
        $this->assertSame(1, voice::stats($instance)['clips']);
    }

    /**
     * Uncharged terminal statuses are reported, never retried, and discard the job.
     */
    public function test_terminal_statuses(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('ttscapabilities', json_encode(['time' => time(), 'locales' => null]), 'mod_aianatomy');
        [$instance, $context] = $this->setup_voice();
        $item = voice::item($instance, 'Scaphoid');
        // 409 and 410 are covered by test_failed_marker (they leave a marker instead of deleting the job).
        foreach ([401 => 'aiauth', 403 => 'voicenoentitlement', 402 => 'ainocredits', 404 => 'voicenotavailable',
                413 => 'lmslabsinvalid', 422 => 'lmslabsinvalid'] as $status => $code) {
            $calls = 0;
            lmslabs::$transport = function () use (&$calls, $status) {
                $calls++;
                return [$status, [], '{"code":"X"}'];
            };
            try {
                voice::speak($instance, $context, $item['text'], $item['sig'], 'normal', true);
                $this->fail('Expected an exception for ' . $status);
            } catch (\moodle_exception $e) {
                $this->assertSame($code, $e->errorcode);
            }
            $this->assertSame(1, $calls);
            $this->assertSame(0, $DB->count_records('aianatomy_job'));
        }
    }

    /**
     * After 409/410 a clip is marked failed: automatic plays send nothing; an explicit teacher request uses a new key.
     */
    public function test_failed_marker(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('ttscapabilities', json_encode(['time' => time(), 'locales' => null]), 'mod_aianatomy');
        [$instance, $context] = $this->setup_voice();
        $item = voice::item($instance, 'Lunate');
        $requests = [];
        lmslabs::$transport = function (array $req) use (&$requests) {
            $requests[] = $req;
            return count($requests) === 1 ? [410, [], '{"code":"RESULT_NOT_RETAINED"}']
                : [200, ['Content-Type' => ['audio/mpeg']], 'ID3audio'];
        };
        try {
            voice::speak($instance, $context, $item['text'], $item['sig'], 'normal', true);
            $this->fail('Expected 410');
        } catch (\moodle_exception $e) {
            $this->assertSame('lmslabsexpired', $e->errorcode);
        }
        try {
            voice::speak($instance, $context, $item['text'], $item['sig'], 'normal', true);
            $this->fail('Expected the stored failure');
        } catch (\moodle_exception $e) {
            $this->assertSame('lmslabsexpired', $e->errorcode);
        }
        $this->assertCount(1, $requests);
        $this->assertSame(1, \mod_aianatomy\local\ai\jobs::failed_speech((int)$instance->id));
        $r = voice::speak($instance, $context, $item['text'], $item['sig'], 'normal', true, true);
        $this->assertCount(1, $r['clips']);
        $this->assertCount(2, $requests);
        $this->assertNotSame($requests[0]['headers']['Idempotency-Key'], $requests[1]['headers']['Idempotency-Key']);
        $this->assertSame(0, $DB->count_records('aianatomy_job'));
    }

    /**
     * Text operations: metadata.operation, no model, structured source for translations, pending polling.
     */
    public function test_text_operations(): void {
        global $DB;
        $this->resetAfterTest();
        [$instance, $context] = $this->setup_voice(['language' => 'de']);
        $requests = [];
        lmslabs::$transport = function (array $req) use (&$requests) {
            $requests[] = $req;
            $body = json_decode($req['body'], true);
            if (count($requests) === 1) {
                return [202, ['Retry-After' => ['5']], ''];
            }
            if ($body['metadata']['operation'] === 'translation') {
                $reply = ['name' => 'Kahnbein', 'synonyms' => [],
                    'content' => array_map(fn($v) => 'DE ' . $v, $body['source']['content']),
                    'questions' => array_map(
                        fn($q) => ['ref' => $q['ref'], 'text' => 'DE ' . $q['text'],
                        'options' => $q['options'], 'explanation' => 'DE'], $body['source']['questions']
                    )];
            } else {
                $reply = ['latin' => 'Os', 'pronunciation' => '', 'origin' => '', 'location' => '', 'description' => 'D',
                    'function' => '', 'mnemonic' => '', 'clinical' => '', 'hint' => ''];
            }
            return [200, ['X-Credits-Charged' => ['3']],
                json_encode(['choices' => [['message' => ['content' => json_encode($reply)]]]])];
        };
        $r = generator::generate_content($instance, $context, 'skeletal_hand_right_scaphoid', 2);
        $this->assertTrue($r['pending']);
        $r = generator::generate_content($instance, $context, 'skeletal_hand_right_scaphoid', 2);
        $this->assertFalse($r['pending']);
        $this->assertSame('D', $r['draft']['description']);
        $this->assertSame($requests[0]['headers']['Idempotency-Key'], $requests[1]['headers']['Idempotency-Key']);
        $body = json_decode($requests[0]['body'], true);
        $this->assertSame('content', $body['metadata']['operation']);
        $this->assertArrayNotHasKey('model', $body);
        $this->assertArrayNotHasKey('source', $body);
        $this->assertSame(0, $DB->count_records('aianatomy_job'));

        $t = generator::translate_structure($instance, $context, 'skeletal_hand_right_scaphoid', 2);
        $last = end($requests);
        $body = json_decode($last['body'], true);
        $this->assertSame('translation', $body['metadata']['operation']);
        $this->assertArrayHasKey('source', $body);
        $this->assertNotSame($requests[0]['headers']['Idempotency-Key'], $last['headers']['Idempotency-Key']);
        $this->assertSame('Kahnbein', $t['name']);
        $this->assertCount(count($body['source']['questions']), $t['questions']);
    }

    /**
     * The voice catalogue parser accepts the common layouts and keeps only the eight offered voices.
     */
    public function test_capabilities_parse(): void {
        $a = tts_lmslabs::parse_capabilities(
            ['locales' => [['locale' => 'de-DE',
            'voices' => [['name' => 'de-DE-Chirp3-HD-Kore'], 'Puck', ['name' => 'de-DE-Chirp3-HD-Achernar']]]]]
        );
        $this->assertSame(['de-DE' => ['Kore', 'Puck']], $a);
        $b = tts_lmslabs::parse_capabilities(['voices' => [['languageCode' => 'fr-FR', 'name' => 'fr-FR-Chirp3-HD-Aoede']]]);
        $this->assertSame(['fr-FR' => ['Aoede']], $b);
        $c = tts_lmslabs::parse_capabilities(['en-GB' => ['Kore', 'Orus']]);
        $this->assertSame(['en-GB' => ['Kore', 'Orus']], $c);
    }

    /**
     * Test attempts never carry spoken feedback; practice does.
     */
    public function test_no_feedback_voice_in_test(): void {
        $this->resetAfterTest();
        [$instance, $context] = $this->setup_voice(['quizcount' => 3]);
        [$course, $cm] = get_course_and_cm_from_instance($instance->id, 'aianatomy');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $test = manager::start_attempt($instance, $cm, $course, $context, 'test', (int)$student->id);
        foreach ($test['questions'] as $q) {
            $this->assertArrayNotHasKey('voicefeedback', $q);
            $this->assertArrayNotHasKey('answer', $q);
        }
        $practice = manager::start_attempt($instance, $cm, $course, $context, 'practice', (int)$student->id);
        $this->assertArrayHasKey('voicefeedback', $practice['questions'][0]);
    }

    /**
     * Languages: normalisation, locales, and questions in the activity language.
     */
    public function test_languages(): void {
        $this->resetAfterTest();
        $this->assertSame('de', language::normalise('de_du'));
        $this->assertSame('en', language::normalise('xx'));
        $this->assertSame('cmn-CN', language::locale('zh_cn'));
        $this->assertTrue(language::same('en_us', 'en'));
        [$instance] = $this->setup_voice(['language' => 'de']);
        // Only English library questions exist: they are used until German ones are approved.
        $this->assertNotEmpty(manager::student_questions($instance));
        $q = manager::save_question(
            $instance, ['structureid' => 'skeletal_hand_right_scaphoid', 'kind' => 'function',
            'text' => 'Welcher Handwurzelknochen bricht am häufigsten?', 'options' => ['Kahnbein', 'Mondbein'],
            'answer' => 0, 'explanation' => '', 'status' => 'approved']
        );
        $this->assertSame('de', $q['lang']);
        $this->assertCount(1, manager::student_questions($instance));
        // German prompts ask for German output.
        $this->assertStringContainsString('German', generator::content_prompt($instance, 'skeletal_hand_right_scaphoid'));
    }

    /**
     * Every shipped pack is valid: nodes exist, relationships resolve, questions are well formed.
     */
    public function test_packs_valid(): void {
        foreach (array_keys(pack::list()) as $id) {
            $p = pack::get($id);
            $ids = array_column($p['structures'], 'id');
            $this->assertSame(count($ids), count(array_unique($ids)), $id);
            foreach ($p['structures'] as $s) {
                foreach ($s['relationships'] as $r) {
                    $this->assertContains($r['target'], $ids, $id . ' ' . $s['id']);
                }
                foreach ($s['questions'] as $q) {
                    $this->assertArrayHasKey($q['answer'], $q['options'], $id . ' ' . $s['id']);
                }
            }
            $this->assertFileExists(pack::model_path($id));
        }
    }
}
