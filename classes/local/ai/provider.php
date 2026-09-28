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

/**
 * An AI text provider (LMS Labs). Requests are jobs with a persisted Idempotency-Key, see {@see jobs}.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface provider {
    /**
     * Whether the provider is configured and usable.
     *
     * @return bool
     */
    public function is_ready(): bool;

    /**
     * Name of the service, stored with generated content for provenance.
     *
     * @return string
     */
    public function get_model_name(): string;

    /**
     * Builds the exact request body for an operation.
     *
     * @param string $operation content, questions, translation or grouptranslation
     * @param string $system system instructions
     * @param string $prompt user prompt
     * @param array|null $source structured source (translation and grouptranslation)
     * @param \context $context where the request comes from (no user identity is sent)
     * @return string JSON
     */
    public function body(string $operation, string $system, string $prompt, ?array $source, \context $context): string;

    /**
     * Sends (or re-sends, with the same key and body) a job.
     *
     * @param \stdClass $job
     * @return array ['state' => 'done', 'text' => string, 'credits' => string, 'balance' => string]
     *               or ['state' => 'pending', 'retryafter' => int]
     * @throws \moodle_exception on a terminal status
     */
    public function request(\stdClass $job): array;
}
