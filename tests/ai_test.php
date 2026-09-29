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
use mod_aianatomy\local\credentials;

/**
 * Tests for the AI authoring assistant (no external calls are made).
 *
 * @package    mod_aianatomy
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aianatomy\local\ai\generator
 * @covers     \mod_aianatomy\local\credentials
 */
final class ai_test extends \advanced_testcase {
    /**
     * JSON is found inside code fences and surrounding text.
     */
    public function test_parse_json(): void {
        $this->assertSame(['a' => 1], generator::parse_json("Sure!\n```json\n{\"a\": 1}\n```\nHope this helps"));
        $this->assertSame(['a' => 2], generator::parse_json('Answer: {"a": 2} done'));
        $this->expectException(\moodle_exception::class);
        generator::parse_json('no json here');
    }

    /**
     * Prompts carry the verified anatomy facts from the pack.
     */
    public function test_prompt_uses_pack_facts(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('aianatomy', ['course' => $course->id, 'level' => 'medical']);
        $prompt = generator::content_prompt($instance, 'skeletal_hand_right_scaphoid');
        $this->assertStringContainsString('Os scaphoideum', $prompt);
        $this->assertStringContainsString('FMA24435', $prompt);
        $this->assertStringContainsString('articulates with Lunate', $prompt);
        // One writing style for every learner (no learner levels).
        $this->assertStringContainsString('never like a medical textbook', $prompt);
        $this->assertStringNotContainsString('medical students:', $prompt);
    }

    /**
     * Credential resolution: central first, complete pairs only, never mixed.
     */
    public function test_credentials(): void {
        $this->resetAfterTest();
        $central = fn(string $siteid, string $apikey) => fn() => ['siteid' => $siteid, 'apikey' => $apikey];

        // Central only.
        $c = credentials::resolve($central(' site-1 ', 'key-1'));
        $this->assertSame(['siteid' => 'site-1', 'apikey' => 'key-1', 'source' => 'central'], $c);

        // Local only (central complete pair missing / not installed).
        set_config('lmslabs_siteid', 'site-2', 'mod_aianatomy');
        set_config('lmslabs_apikey', 'key-2', 'mod_aianatomy');
        $this->assertSame('local', credentials::resolve($central('', ''))['source']);

        // Both configured: central wins by default.
        $this->assertSame('key-1', credentials::resolve($central('site-1', 'key-1'))['apikey']);

        // Both configured and the explicit override is on: local wins.
        set_config('lmslabs_useown', 1, 'mod_aianatomy');
        $this->assertSame('key-2', credentials::resolve($central('site-1', 'key-1'))['apikey']);
        set_config('lmslabs_useown', 0, 'mod_aianatomy');

        // Incomplete central: never mixed with local values; the complete local pair is used.
        $c = credentials::resolve($central('site-1', ''));
        $this->assertSame(['siteid' => 'site-2', 'apikey' => 'key-2', 'source' => 'local'], $c);

        // Incomplete local and incomplete central: error, no call.
        set_config('lmslabs_apikey', '', 'mod_aianatomy');
        try {
            credentials::resolve($central('', 'key-1'));
            $this->fail('Incomplete credentials must not resolve');
        } catch (\moodle_exception $e) {
            $this->assertSame('lmslabscredentialsmissing', $e->errorcode);
        }

        // Central Config not installed (no seam): local incomplete, so missing.
        if (!credentials::central_installed()) {
            $this->assertSame('missing', credentials::source());
        }
    }
}
