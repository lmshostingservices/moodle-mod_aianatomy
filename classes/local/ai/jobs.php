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

use stdClass;

/**
 * LMS Labs requests in progress.
 *
 * A random Idempotency-Key and the exact request body are stored before the first network request.
 * Until the result is stored in Moodle, every poll, retry or recovery (also after a page reload or a
 * lost connection) re-sends the same key and the same body. A job is removed once its result is stored,
 * or when LMS Labs reports a terminal status; the next generation is then a new request with a new key.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class jobs {
    /** Jobs older than this are LMS Labs-expired (results are kept 24 hours) and are cleaned up. */
    const MAXAGE = 2 * DAYSECS;

    /**
     * An existing job.
     *
     * @param int $instanceid
     * @param string $operation
     * @param string $target
     * @return stdClass|null
     */
    public static function get(int $instanceid, string $operation, string $target): ?stdClass {
        global $DB;
        return $DB->get_record(
            'aianatomy_job', ['aianatomyid' => $instanceid, 'operation' => $operation,
            'target' => $target]
        ) ?: null;
    }

    /**
     * Returns the job in progress for this operation and target, or creates one with a new key.
     *
     * @param int $instanceid
     * @param string $operation
     * @param string $target
     * @param callable $build fn(): array [body JSON string, meta array|null], only called for a new job
     * @return stdClass
     */
    public static function start(int $instanceid, string $operation, string $target, callable $build): stdClass {
        global $DB;
        if ($job = self::get($instanceid, $operation, $target)) {
            return $job;
        }
        [$body, $meta] = $build();
        $job = (object)['aianatomyid' => $instanceid, 'operation' => $operation, 'target' => $target,
            'idemkey' => self::newkey(), 'body' => $body, 'meta' => $meta === null ? null : json_encode($meta),
            'retryafter' => 0, 'timecreated' => time(), 'timemodified' => time()];
        try {
            $job->id = $DB->insert_record('aianatomy_job', $job);
        } catch (\dml_exception $e) {
            // Someone else started the same request at the same moment: use theirs (same key and body).
            $job = self::get($instanceid, $operation, $target);
            if (!$job) {
                throw $e;
            }
        }
        return $job;
    }

    /**
     * A new Idempotency-Key: printable ASCII, no spaces, 1-128 characters.
     *
     * @return string
     */
    public static function newkey(): string {
        return 'aa-' . random_string(40);
    }

    /**
     * Records that the job is still pending.
     *
     * @param stdClass $job
     * @param int $retryafter seconds
     */
    public static function wait(stdClass $job, int $retryafter): void {
        global $DB;
        $DB->update_record(
            'aianatomy_job', (object)['id' => $job->id, 'retryafter' => time() + $retryafter,
            'timemodified' => time()]
        );
    }

    /**
     * Marks a job as terminally failed (409 or 410) without deleting it, so that automatic requests (a student
     * playing audio, automatic reading) never create a new billable key for it. Only an explicit teacher action
     * clears it (see {@see self::is_failed()}).
     *
     * @param stdClass $job
     * @param string $errorcode
     */
    public static function fail(stdClass $job, string $errorcode): void {
        global $DB;
        $meta = json_decode((string)$job->meta, true) ?: [];
        $meta['failed'] = $errorcode;
        $DB->update_record(
            'aianatomy_job', (object)['id' => $job->id, 'meta' => json_encode($meta),
            'timemodified' => time()]
        );
    }

    /**
     * The error code a job failed with (409/410), or null.
     *
     * @param stdClass $job
     * @return string|null
     */
    public static function is_failed(stdClass $job): ?string {
        $meta = json_decode((string)$job->meta, true);
        return is_array($meta) && !empty($meta['failed']) ? (string)$meta['failed'] : null;
    }

    /**
     * Number of speech clips that failed terminally and wait for an explicit teacher action.
     *
     * @param int $instanceid
     * @return int
     */
    public static function failed_speech(int $instanceid): int {
        global $DB;
        return $DB->count_records_select(
            'aianatomy_job', 'aianatomyid = :aid AND operation = :op AND ' .
            $DB->sql_like('meta', ':failed'), ['aid' => $instanceid, 'op' => 'speech', 'failed' => '%"failed"%']
        );
    }

    /**
     * Removes a job (result stored, or terminal status).
     *
     * @param stdClass $job
     */
    public static function finish(stdClass $job): void {
        global $DB;
        $DB->delete_records('aianatomy_job', ['id' => $job->id]);
    }

    /**
     * Text jobs in progress (for the editor to resume after a reload).
     *
     * @param int $instanceid
     * @return array [['operation' => string, 'target' => string]]
     */
    public static function pending_text(int $instanceid): array {
        global $DB;
        self::cleanup();
        $out = [];
        foreach ($DB->get_records_select(
            'aianatomy_job', 'aianatomyid = :aid AND operation <> :op',
            ['aid' => $instanceid, 'op' => 'speech'],
            'timecreated ASC', 'id, operation, target'
        ) as $j) {
            $out[] = ['operation' => $j->operation, 'target' => $j->target];
        }
        return $out;
    }

    /**
     * Deletes jobs LMS Labs no longer keeps (older than MAXAGE).
     */
    public static function cleanup(): void {
        global $DB;
        // Pending jobs past LMS Labs' retention are removed; failed speech markers stay until a teacher acts.
        $DB->delete_records_select(
            'aianatomy_job', 'timecreated < :cutoff AND (meta IS NULL OR ' .
            $DB->sql_like('meta', ':failed', true, true, true) . ')',
            ['cutoff' => time() - self::MAXAGE, 'failed' => '%"failed"%']
        );
    }
}
