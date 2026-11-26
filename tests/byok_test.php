<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_aianatomy;

use mod_aianatomy\local\ai\generator;
use mod_aianatomy\local\ai\lmslabs;
use mod_aianatomy\local\byok\engine;
use mod_aianatomy\local\voice;

/**
 * Tests Bring your own AI: writing and voiceover on the site's own AI, with the service's checks, at no charge.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aianatomy\byok
 * @covers     \mod_aianatomy\local\byok\engine
 * @covers     \mod_aianatomy\local\ai\provider_lmslabs
 * @covers     \mod_aianatomy\local\ai\tts_lmslabs
 */
#[\PHPUnit\Framework\Attributes\CoversClass(byok::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(engine::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\ai\provider_lmslabs::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\ai\tts_lmslabs::class)]
final class byok_test extends \advanced_testcase {
    /** @var array Requests the mocked providers received: [url, body]. */
    private array $requests = [];

    /** @var array Tool answers the mocked Anthropic gives, in order. */
    private array $answers = [];

    /** @var string The structure the tests write about. */
    private const STRUCTURE = 'skeletal_hand_right_scaphoid';

    /**
     * Licence the site for Bring your own AI on a test key: Text on Anthropic, Voice on OpenAI, no LMS Labs credentials.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_aiconfig\byok\ai')) {
            $this->markTestSkipped('AI Grader Central Config with Bring your own AI is not installed.');
        }
        $this->resetAfterTest();
        $pair = sodium_crypto_sign_keypair();
        set_config('testpublickey', base64_encode(sodium_crypto_sign_publickey($pair)), 'local_aiconfig');
        $payload = json_encode(['v' => 1, 'id' => 'lic_t', 'site' => \local_aiconfig\byok\licence::site_host(),
            'plan' => 'pro', 'byok' => true, 'products' => ['*'], 'sub' => 'sub_t', 'exp' => time() + DAYSECS]);
        $b64 = fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        set_config('licencekey', $b64($payload) . '.' .
            $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair))), 'local_aiconfig');
        foreach (['text' => 'anthropic', 'voice' => 'openai'] as $service => $vendor) {
            set_config('src_' . $service, 'ownkey', 'local_aiconfig');
            set_config('vendor_' . $service, $vendor, 'local_aiconfig');
        }
        set_config('key_anthropic', 'sk-test', 'local_aiconfig');
        set_config('key_openai', 'sk-test', 'local_aiconfig');
        foreach (['siteid', 'apikey'] as $name) {
            set_config($name, '', 'local_aiconfig');
            set_config('lmslabs_' . $name, '', 'mod_aianatomy');
        }
        $this->requests = [];
        $this->answers = [];
        $GLOBALS['local_aiconfig_byok_mock'] = function ($url, $headers, $body, $vendor, $raw = false) {
            $this->requests[] = [$url, $body];
            if (strpos($url, '/audio/speech') !== false) {
                return 'MP3AUDIO';
            }
            $answer = array_shift($this->answers);
            if ($answer === null) {
                throw new \moodle_exception('byok_callfailed', 'local_aiconfig', '', (object) ['vendor' => $vendor,
                    'detail' => 'Overloaded']);
            }
            return json_encode(['content' => [['type' => 'tool_use', 'id' => 't', 'name' => $body['tools'][0]['name'],
                'input' => $answer]], 'stop_reason' => 'tool_use']);
        };
        // LMS Labs must never be called.
        lmslabs::$transport = function () {
            throw new \coding_exception('LMS Labs was called');
        };
    }

    /**
     * Clean up the mocks.
     */
    protected function tearDown(): void {
        unset($GLOBALS['local_aiconfig_byok_mock']);
        lmslabs::$transport = null;
        engine::$text = null;
        engine::$speech = null;
        parent::tearDown();
    }

    /**
     * An activity.
     *
     * @param array $settings
     * @return array [instance, context]
     */
    private function activity(array $settings = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('aianatomy', ['course' => $course->id] + $settings);
        return [$DB->get_record('aianatomy', ['id' => $module->id], '*', MUST_EXIST), \context_module::instance($module->cmid)];
    }

    /**
     * A card's content as the AI writes it.
     *
     * @return array
     */
    private function content(): array {
        return ['latin' => 'Os scaphoideum', 'pronunciation' => 'SKAF-oyd', 'origin' => 'Greek skaphe, a boat.',
            'location' => 'On the thumb side of the wrist.', 'description' => 'A boat-shaped carpal bone.',
            'function' => 'Links the two rows of carpal bones.', 'mnemonic' => 'Scaphoid sails by the thumb.',
            'clinical' => 'Often broken in a fall on an outstretched hand.', 'hint' => 'Find the boat by the thumb.'];
    }

    /**
     * Library content is written on the site's own AI with the service's instruction and schema, at no charge.
     */
    public function test_content(): void {
        global $DB;
        [$instance, $context] = $this->activity();
        $this->assertSame(['ready' => true, 'name' => get_string('byok_name', 'mod_aianatomy')], generator::status());
        $this->answers = [$this->content()];
        $result = generator::generate_content($instance, $context, self::STRUCTURE, 2);

        $this->assertFalse($result['pending']);
        $this->assertSame('0', $result['credits']);
        $this->assertSame('A boat-shaped carpal bone.', $result['draft']['description']);
        $this->assertSame(0, $DB->count_records('aianatomy_job'));
        $this->assertCount(1, $this->requests);
        [$url, $sent] = $this->requests[0];
        $this->assertStringEndsWith('/v1/messages', $url);
        $start = generator::system_prompt() . "\n\nOperation: content. Return usable plain text";
        $this->assertStringStartsWith($start, $sent['system']);
        $this->assertStringEndsWith('Structured source is authoritative: null', $sent['system']);
        $this->assertStringContainsString('Os scaphoideum', $sent['messages'][0]['content'][0]['text']);
        $this->assertEquals(0.3, $sent['temperature']);
        $this->assertSame(4096, $sent['max_tokens']);
        $this->assertSame(engine::FIELDS, $sent['tools'][0]['input_schema']['required']);
    }

    /**
     * An answer the service would refuse is refused here too: nothing is stored and the next click starts afresh.
     */
    public function test_refused(): void {
        global $DB;
        [$instance, $context] = $this->activity();
        $this->answers = [['description' => '   '] + $this->content()];
        try {
            generator::generate_content($instance, $context, self::STRUCTURE, 2);
            $this->fail('No exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('aierror', $e->errorcode);
            $this->assertStringContainsString('EMPTY_CONTENT', $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('aianatomy_job'));
        $where = ['aianatomyid' => $instance->id, 'structureid' => self::STRUCTURE];
        $this->assertSame('', (string)$DB->get_field('aianatomy_structure', 'draft', $where));
    }

    /**
     * Voiceover clips are made on the site's own voice: the closest OpenAI voice, MP3, at the service's speed.
     */
    public function test_voice(): void {
        set_config('ttscapabilities', json_encode(['time' => time(), 'locales' => null]), 'mod_aianatomy');
        [$instance, $context] = $this->activity(['voice' => 1, 'language' => 'de', 'voicename' => 'Charon']);
        $this->assertTrue(voice::enabled($instance));
        $item = voice::item($instance, 'Das Kahnbein', 'slow');
        $r = voice::speak($instance, $context, $item['text'], $item['sig'], 'slow', true);
        $this->assertFalse($r['pending']);
        $this->assertCount(1, $r['clips']);
        [$url, $sent] = $this->requests[0];
        $this->assertStringEndsWith('/audio/speech', $url);
        $this->assertSame('Das Kahnbein', $sent['input']);
        $this->assertSame('echo', $sent['voice']);
        $this->assertSame('mp3', $sent['response_format']);
        $this->assertEquals(0.8, $sent['speed']);
    }

    /**
     * The service's checks of a translation: the same fields and questions, in the same order.
     */
    public function test_translation_checks(): void {
        $source = ['name' => 'Scaphoid', 'synonyms' => ['Navicular'], 'content' => ['description' => 'A boat.', 'hint' => ''],
            'questions' => [['ref' => 1, 'text' => 'Where?', 'options' => ['A', 'B', 'C', 'D'], 'explanation' => '']]];
        $body = json_encode(['messages' => [['role' => 'system', 'content' => 'Translate.'], ['role' => 'user', 'content' => 'de']],
            'metadata' => ['plugin' => 'mod_aianatomy', 'operation' => 'translation'], 'source' => $source]);
        $good = ['name' => 'Kahnbein', 'synonyms' => ['Os naviculare'], 'content' => ['description' => 'Ein Boot.', 'hint' => ''],
            'questions' => [['ref' => 1, 'text' => 'Wo?', 'options' => ['A', 'B', 'C', 'D'], 'explanation' => '']]];
        $bad = $good;
        $bad['content']['hint'] = 'Neu';
        foreach ([[$good, 200], [$bad, 502]] as [$answer, $status]) {
            engine::$text = function ($system, $user, $max, $t, $schema) use ($answer) {
                $this->assertSame(['description', 'hint'], $schema['schema']['properties']['content']['required']);
                return json_encode($answer);
            };
            $this->assertSame($status, engine::text($body)[0]);
        }
    }
}
