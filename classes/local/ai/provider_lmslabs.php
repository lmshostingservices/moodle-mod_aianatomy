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
namespace mod_aianatomy\local\ai;

use mod_aianatomy\local\credentials;
use moodle_exception;

/**
 * LMS Labs text route for AI Anatomy (3 LMS Labs credits per successful operation).
 *
 *   POST https://lms-labs.com/api/moodle/ai-anatomy/text
 *   X-Site-ID, X-API-Key, X-LMS-Plugin, Idempotency-Key (persisted, see {@see jobs})
 *   {"messages": [system, user], "temperature": 0.3, "response_format": {"type": "json_object"},
 *    "metadata": {"plugin": "mod_aianatomy", "operation": "...", "siteid": "...", "contextid": 123},
 *    "source": {...}}   <- translation and grouptranslation only
 *   200: validated JSON in choices[0].message.content.
 * LMS Labs pins the model, so no model is sent. No user identity is sent.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_lmslabs implements provider {
    /** Operations the route accepts. */
    const OPERATIONS = ['content', 'questions', 'translation', 'grouptranslation'];

    /** Limits of the route: all messages together, and the whole JSON body. */
    const MAXMESSAGECHARS = 12000;
    /** Maximum JSON body size in bytes. */
    const MAXBODYBYTES = 32768;

    /**
     * Configured: a complete credential pair is available (the endpoint is built in).
     *
     * @return bool
     */
    public function is_ready(): bool {
        return endpoints::text() !== '' && credentials::source() !== 'missing';
    }

    /**
     * Provenance name.
     *
     * @return string
     */
    public function get_model_name(): string {
        return 'LMS Labs';
    }

    /**
     * Request body.
     *
     * @param string $operation
     * @param string $system
     * @param string $prompt
     * @param array|null $source
     * @param \context $context
     * @return string
     */
    public function body(string $operation, string $system, string $prompt, ?array $source, \context $context): string {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \coding_exception('Unknown LMS Labs operation ' . $operation);
        }
        if (\core_text::strlen($system) + \core_text::strlen($prompt) > self::MAXMESSAGECHARS) {
            throw new moodle_exception('aitoolarge', 'mod_aianatomy');
        }
        $creds = credentials::resolve();
        $body = [
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
            'response_format' => ['type' => 'json_object'],
            'metadata' => [
                'plugin' => 'mod_aianatomy',
                'operation' => $operation,
                'siteid' => $creds['siteid'],
                'contextid' => $context->id,
            ],
        ];
        if ($source !== null) {
            $body['source'] = $source;
        }
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAXBODYBYTES) {
            throw new moodle_exception('aitoolarge', 'mod_aianatomy');
        }
        return $json;
    }

    /**
     * Sends a job.
     *
     * @param \stdClass $job
     * @return array
     */
    public function request(\stdClass $job): array {
        [$status, $headers, $body] = lmslabs::send(
            'POST', endpoints::text(), $job->body, 'application/json',
            $job->idemkey
        );
        $o = lmslabs::outcome($status, $headers, $body, 'ai');
        if ($o['state'] !== 'done') {
            return $o;
        }
        $data = json_decode($body, true);
        $text = is_array($data) ? ($data['choices'][0]['message']['content'] ?? null) : null;
        if (!is_string($text) || trim($text) === '') {
            throw new moodle_exception('aiemptyreply', 'mod_aianatomy');
        }
        return ['state' => 'done', 'text' => $text,
            'credits' => \core_text::substr(lmslabs::header($headers, 'X-Credits-Charged'), 0, 20),
            'balance' => \core_text::substr(lmslabs::header($headers, 'X-Credits-Balance'), 0, 20)];
    }
}
