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
        if ($method === 'GET' && $path === '/api/v1/assess/state') {
            $cmid = preg_match('/^stub\.(\d+)\./', $bearer, $m) ? (int) $m[1] : 0;
            $fixture = json_decode((string) get_config(self::COMPONENT, self::STATE . $cmid), true);
            if (!is_array($fixture)) {
                return self::json(200, ['state' => 'not_enabled', 'reason' => 'not_enabled']);
            }
            return self::json((int) ($fixture['status'] ?? 200), $fixture['body'] ?? []);
        }
        return self::json(404, ['error' => ['code' => 'ERR-STUB-404', 'message' => 'not stubbed']]);
    }

    /**
     * Streaming is not stubbed yet (transcript plan): 404.
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
        $this->record('GET', (string) parse_url($url, PHP_URL_PATH), true, null);
        $onheaderline("HTTP/1.1 404 Not Found\r\n");
        $onheaderline("\r\n");
        return ['status' => 404, 'errno' => 0, 'error' => ''];
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
