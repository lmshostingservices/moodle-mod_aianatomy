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
 * HTTP client and status handling shared by the LMS Labs text and speech routes.
 *
 * - Credentials (Central Config first, complete pairs only) go in X-Site-ID and X-API-Key headers only.
 *   They are never logged, stored with a job or returned to the browser.
 * - Every generation carries an Idempotency-Key that was persisted (with the exact body) before the first
 *   request, see {@see jobs}. Polling and recovery re-send the same key and the same body.
 * - Nothing is ever retried with another credential pair.
 *
 * Status handling (from the LMS Labs AI Anatomy contract):
 *   200 done (first result or replay). 202 and 429 pending: poll again after Retry-After.
 *   502/503/504 and network errors: recoverable, poll again with the same key.
 *   401 credentials, 403 no AI Anatomy entitlement, 402 no credits, 404 route not available,
 *   409 key/body mismatch, 410 failed or expired, 413/422 invalid request: terminal.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lmslabs {
    /** Default seconds to wait before polling again. */
    const RETRY_DEFAULT = 5;

    /** @var callable|null test seam: fn(array $request): array [status, headers, body] (status 0 = network error) */
    public static $transport = null;

    /**
     * Request headers with the site's credentials.
     *
     * @param string $accept
     * @param string|null $idempotencykey
     * @return array
     */
    protected static function headers(string $accept, ?string $idempotencykey): array {
        $creds = credentials::resolve();
        $h = [
            'Accept' => $accept,
            'X-Site-ID' => $creds['siteid'],
            'X-API-Key' => $creds['apikey'],
            'X-LMS-Plugin' => 'mod_aianatomy',
        ];
        if ($idempotencykey !== null) {
            $h['Content-Type'] = 'application/json';
            $h['Idempotency-Key'] = $idempotencykey;
        }
        return $h;
    }

    /**
     * Sends a request.
     *
     * @param string $method GET or POST
     * @param string $url
     * @param string|null $body
     * @param string $accept
     * @param string|null $idempotencykey
     * @param int|null $timeout seconds (default: the admin timeout, at least 30)
     * @return array [status (0 = network error), headers, body]
     */
    public static function send(string $method, string $url, ?string $body, string $accept,
            ?string $idempotencykey, ?int $timeout = null): array {
        $request = ['method' => $method, 'url' => $url, 'headers' => self::headers($accept, $idempotencykey),
            'body' => $body];
        if (self::$transport) {
            return (self::$transport)($request);
        }
        // The server bounds provider work at 80 s and settles within 85 s.
        $timeout = $timeout ?? max(30, (int)(get_config('mod_aianatomy', 'lmslabs_timeout') ?: 100));
        try {
            $client = new \core\http_client();
            $options = ['headers' => $request['headers'], 'timeout' => $timeout, 'connect_timeout' => 15,
                'http_errors' => false];
            if ($body !== null) {
                $options['body'] = $body;
            }
            $response = $client->request($method, $url, $options);
        } catch (\Throwable $e) {
            // Network failure or timeout: recoverable with the same key. The message never contains the key.
            debugging('LMS Labs request failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [0, [], ''];
        }
        return [(int)$response->getStatusCode(), $response->getHeaders(), (string)$response->getBody()];
    }

    /**
     * A response header (case-insensitive).
     *
     * @param array $headers
     * @param string $name
     * @return string
     */
    public static function header(array $headers, string $name): string {
        foreach ($headers as $k => $v) {
            if (strcasecmp((string)$k, $name) === 0) {
                return trim(is_array($v) ? (string)reset($v) : (string)$v);
            }
        }
        return '';
    }

    /**
     * Seconds to wait before polling again (Retry-After, 1-120).
     *
     * @param array $headers
     * @return int
     */
    public static function retry_after(array $headers): int {
        $v = self::header($headers, 'Retry-After');
        return is_numeric($v) ? max(1, min(120, (int)$v)) : self::RETRY_DEFAULT;
    }

    /**
     * Classifies a response.
     *
     * @param int $status
     * @param array $headers
     * @param string $body
     * @param string $service 'ai' (text) or 'voice'
     * @return array ['state' => 'done'] or ['state' => 'pending', 'retryafter' => int]
     * @throws moodle_exception for terminal results (the caller discards its job)
     */
    public static function outcome(int $status, array $headers, string $body, string $service): array {
        if ($status === 200) {
            return ['state' => 'done'];
        }
        if (in_array($status, [202, 429], true)) {
            return ['state' => 'pending', 'retryafter' => self::retry_after($headers)];
        }
        if ($status === 0 || in_array($status, [502, 503, 504], true)) {
            // Provider, storage or deadline failure, or no answer: recover with the same key.
            return ['state' => 'pending', 'retryafter' => self::retry_after($headers)];
        }
        $data = json_decode($body, true);
        $code = is_array($data) ? ($data['code'] ?? $data['error']['code'] ?? (is_string($data['error'] ?? null)
            ? $data['error'] : '')) : '';
        $message = is_array($data) ? ($data['message'] ?? $data['error']['message'] ?? '') : '';
        $detail = trim(
            'HTTP ' . $status . ' ' . (is_string($code) ? $code : '') . ' ' .
            (is_string($message) ? \core_text::substr($message, 0, 200) : '')
        );
        switch ($status) {
            case 401:
                throw new moodle_exception('aiauth', 'mod_aianatomy');
            case 403:
                throw new moodle_exception($service === 'voice' ? 'voicenoentitlement' : 'ainoentitlement', 'mod_aianatomy');
            case 402:
                throw new moodle_exception('ainocredits', 'mod_aianatomy');
            case 404:
                throw new moodle_exception($service === 'voice' ? 'voicenotavailable' : 'ainotavailable', 'mod_aianatomy');
            case 409:
                throw new moodle_exception('lmslabsconflict', 'mod_aianatomy', '', $detail);
            case 410:
                throw new moodle_exception('lmslabsexpired', 'mod_aianatomy');
            case 413:
            case 422:
                throw new moodle_exception('lmslabsinvalid', 'mod_aianatomy', '', $detail);
            default:
                throw new moodle_exception($service === 'voice' ? 'voiceerror' : 'aierror', 'mod_aianatomy', '', $detail);
        }
    }
}
