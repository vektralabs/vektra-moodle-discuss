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

/**
 * In-site Vektra assess stub for Behat (discuss-student plan, D1).
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\tests;

use local_vektradiscuss\local\http\transport;

/**
 * A transport that answers like Vektra from scenario fixtures and records every request.
 *
 * Loaded by assess_client only on a Behat site (BEHAT_SITE_RUNNING) with the behatstub setting on.
 * Fixtures and the request log are config_plugins rows, reset by Behat between scenarios. The log keeps
 * the method, the path, whether an Authorization header was present and the body; never a secret.
 */
class behat_stub_transport implements transport {
    /** @var string Component of the config rows. */
    public const COMPONENT = 'local_vektradiscuss';

    /** @var string Config name of the request log (JSON list). */
    public const LOG = 'behatstublog';

    /** @var string Config name prefix of the state fixture of an activity (suffix: cmid). */
    public const STATE = 'behatstubstate_';

    /** @var string Config name prefix of a transcript fixture (suffix: tid): {owner, filename, body}. */
    public const TRANSCRIPT = 'behatstubtranscript_';

    /** @var string Config name prefix of a teacher-route fixture (suffix: method_route_cmid): {status, body}. */
    public const ROUTE = 'behatstubroute_';

    /** @var string Download token prefix; the tid travels in it, as the binding does in Vektra. */
    private const DOWNLOAD_PREFIX = 'stubdl.';

    /**
     * Whether the stub replaces the network on this site.
     *
     * @return bool
     */
    public static function active(): bool {
        return defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING && !empty(get_config(self::COMPONENT, 'behatstub'));
    }

    /**
     * The recorded requests.
     *
     * @return array[]
     */
    public static function log(): array {
        global $DB;
        // Straight from the table: the Behat process may hold a cached copy from before the browser's requests.
        $value = $DB->get_field('config_plugins', 'value', ['plugin' => self::COMPONENT, 'name' => self::LOG]);
        $log = json_decode((string) $value, true);
        return is_array($log) ? $log : [];
    }

    /**
     * Answer one request.
     *
     * @param string $method
     * @param string $url
     * @param string[] $headers
     * @param string|null $body
     * @param int $timeout
     * @param int $connecttimeout
     * @return array{status: int, headers: array, body: string, errno: int, error: string}
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        int $connecttimeout
    ): array {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $bearer = '';
        foreach ($headers as $line) {
            if (stripos($line, 'Authorization: Bearer ') === 0) {
                $bearer = substr($line, strlen('Authorization: Bearer '));
            }
        }
        $this->record($method, $path, $bearer !== '', $body);

        if ($method === 'POST' && $path === '/api/v1/assess/tokens') {
            $request = json_decode((string) $body, true);
            $cmid = (int) ($request['cmid'] ?? 0);
            // The cmid travels in the token, as in Vektra; the random part makes every token distinct.
            $token = 'stub.' . $cmid . '.' . bin2hex(random_bytes(12));
            return self::json(201, ['token' => $token, 'expires_in' => 120, 'jti' => bin2hex(random_bytes(8))]);
        }
        if ($method === 'POST' && preg_match('#^/api/v1/assess/transcripts/([A-Za-z0-9-]+)/download-token$#', $path, $m)) {
            $fixture = self::transcript($m[1]);
            if ($fixture === null) {
                return self::json(404, ['error' => ['code' => 'ERR-ASSESS-017', 'message' => 'not generated']]);
            }
            $request = json_decode((string) $body, true);
            if ((int) ($request['sub'] ?? 0) !== (int) ($fixture['owner'] ?? -1)) {
                return self::json(403, ['error' => ['code' => 'ERR-AUTH-003', 'message' => 'not the owner']]);
            }
            return self::json(201, ['token' => self::DOWNLOAD_PREFIX . $m[1] . '.' . bin2hex(random_bytes(12)),
                'expires_in' => 120]);
        }
        if ($method === 'GET' && $path === '/api/v1/assess/state') {
            $cmid = preg_match('/^stub\.(\d+)\./', $bearer, $m) ? (int) $m[1] : 0;
            $fixture = json_decode((string) get_config(self::COMPONENT, self::STATE . $cmid), true);
            if (!is_array($fixture)) {
                return self::json(200, ['state' => 'not_enabled', 'reason' => 'not_enabled']);
            }
            return self::json((int) ($fixture['status'] ?? 200), $fixture['body'] ?? []);
        }
        $route = self::teacher_route($path);
        if ($route !== null) {
            [$name, $cmid] = $route;
            $fixture = json_decode(
                (string) get_config(self::COMPONENT, self::ROUTE . strtolower($method) . '_' . $name . '_' . $cmid),
                true
            );
            if (is_array($fixture)) {
                return self::json((int) ($fixture['status'] ?? 200), $fixture['body'] ?? []);
            }
            return self::json(200, self::teacher_default($method, $name, $cmid, $body));
        }
        return self::json(404, ['error' => ['code' => 'ERR-STUB-404', 'message' => 'not stubbed']]);
    }

    /**
     * The teacher route of a path: [name, cmid], or null.
     *
     * @param string $path
     * @return array|null
     */
    public static function teacher_route(string $path): ?array {
        $routes = [
            'config' => '#^/api/v1/assess/activities/(\d+)/config$#',
            'opening-fallback' => '#^/api/v1/assess/activities/(\d+)/opening-fallback$#',
            'sessions' => '#^/api/v1/assess/activities/(\d+)/sessions$#',
            'reset' => '#^/api/v1/assess/activities/(\d+)/sessions/[0-9a-f-]+/reset$#',
            'terminate' => '#^/api/v1/assess/activities/(\d+)/terminate$#',
            'extra-time' => '#^/api/v1/assess/activities/(\d+)/students/\d+/extra-time$#',
        ];
        foreach ($routes as $name => $pattern) {
            if (preg_match($pattern, $path, $m)) {
                return [$name, (int) $m[1]];
            }
        }
        return null;
    }

    /**
     * What Vektra answers on a teacher route when the scenario fixed nothing.
     *
     * @param string $method
     * @param string $name
     * @param int $cmid
     * @param string|null $body
     * @return array
     */
    private static function teacher_default(string $method, string $name, int $cmid, ?string $body): array {
        $view = ['cmid' => $cmid, 'activity_type' => 'quiz', 'version' => 1, 'configured' => true, 'enabled' => false,
            'cap_minutes' => 30, 'rubric_source' => 'none', 'show_solution' => false, 'rag_enabled' => false,
            'available_until' => null, 'solution_state' => 'impostata', 'pasted_rubric_state' => 'non impostata',
            'transcript_recipients_state' => 'impostata', 'transcript_recipients_count' => 1,
            'opening' => ['at' => '2026-01-10T10:00:00+00:00', 'source' => 'activity_close', 'fallback_required' => false],
            'warnings' => ['available_until_not_after_opening' => false, 'review_options_hide_content' => false],
            'active_sessions' => 0, 'updated_at' => null];
        if ($name === 'config') {
            return $method === 'PUT' ? ['version' => 2] + $view : $view;
        }
        if ($name === 'sessions') {
            return ['students' => [], 'next_cursor' => null, 'terminations' => [],
                'gate' => ['opening' => $view['opening'], 'fallback_reason' => null, 'fallback_date_missing' => false,
                    'active_sessions' => 0]];
        }
        return [];
    }

    /**
     * Stream a transcript fixture to a matching download token, like GET /transcripts/{tid}; 401 otherwise.
     *
     * @param string $url
     * @param string[] $headers
     * @param callable $onheaderline
     * @param callable $onchunk
     * @param int $timeout
     * @param int $connecttimeout
     * @return array{status: int, errno: int, error: string}
     */
    public function stream(
        string $url,
        array $headers,
        callable $onheaderline,
        callable $onchunk,
        int $timeout,
        int $connecttimeout
    ): array {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $bearer = '';
        foreach ($headers as $line) {
            if (stripos($line, 'Authorization: Bearer ') === 0) {
                $bearer = substr($line, strlen('Authorization: Bearer '));
            }
        }
        $this->record('GET', $path, $bearer !== '', null);
        $fixture = null;
        if (
            preg_match('#^/api/v1/assess/transcripts/([A-Za-z0-9-]+)$#', $path, $m)
                && str_starts_with($bearer, self::DOWNLOAD_PREFIX . $m[1] . '.')
        ) {
            $fixture = self::transcript($m[1]);
        }
        if ($fixture === null) {
            $onheaderline("HTTP/1.1 401 Unauthorized\r\n");
            $onheaderline("content-type: application/json\r\n");
            $onheaderline("\r\n");
            $onchunk(json_encode(['error' => ['code' => 'ERR-ASSESS-018', 'message' => 'invalid download token']]));
            return ['status' => 401, 'errno' => 0, 'error' => ''];
        }
        $bytes = (string) ($fixture['body'] ?? '');
        $onheaderline("HTTP/1.1 200 OK\r\n");
        $onheaderline("content-type: text/html; charset=utf-8\r\n");
        $onheaderline('content-disposition: attachment; filename="' . ($fixture['filename'] ?? '') . "\"\r\n");
        $onheaderline('content-length: ' . strlen($bytes) . "\r\n");
        $onheaderline("\r\n");
        foreach (str_split($bytes, 1024) as $chunk) {
            if ($chunk !== '' && $onchunk($chunk) === false) {
                return ['status' => 200, 'errno' => 23, 'error' => 'write aborted'];
            }
        }
        return ['status' => 200, 'errno' => 0, 'error' => ''];
    }

    /**
     * A transcript fixture, read straight from the table (the browser's requests may run in another process).
     *
     * @param string $tid
     * @return array|null
     */
    private static function transcript(string $tid): ?array {
        global $DB;
        $value = $DB->get_field('config_plugins', 'value', ['plugin' => self::COMPONENT, 'name' => self::TRANSCRIPT . $tid]);
        $fixture = json_decode((string) $value, true);
        return is_array($fixture) ? $fixture : null;
    }

    /**
     * Append a request to the log.
     *
     * @param string $method
     * @param string $path
     * @param bool $auth
     * @param string|null $body
     */
    private function record(string $method, string $path, bool $auth, ?string $body): void {
        $log = self::log();
        $log[] = ['method' => $method, 'path' => $path, 'auth' => $auth, 'body' => $body];
        set_config(self::LOG, json_encode($log), self::COMPONENT);
    }

    /**
     * A JSON response.
     *
     * @param int $status
     * @param mixed $body
     * @return array
     */
    private static function json(int $status, $body): array {
        return ['status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($body),
            'errno' => 0, 'error' => ''];
    }
}
