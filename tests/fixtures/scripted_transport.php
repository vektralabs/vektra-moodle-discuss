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
 * Scripted transport for unit tests: returns queued responses and records every call.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\tests;

use local_vektradiscuss\local\http\transport;

/**
 * A transport that never touches the network.
 */
class scripted_transport implements transport {
    /** @var array[] Queued results for send(). */
    private array $queue = [];

    /** @var array[] Recorded calls: [method, url, headers, body, timeout, connecttimeout]. */
    public array $calls = [];

    /**
     * Queue a response.
     *
     * @param int $status HTTP status (0 = transport failure).
     * @param mixed $body Array (JSON-encoded) or raw string.
     * @param array $headers Response headers.
     * @return self
     */
    public function respond(int $status, $body = '', array $headers = []): self {
        $this->queue[] = [
            'status' => $status,
            'headers' => array_change_key_case($headers, CASE_LOWER),
            'body' => is_array($body) ? json_encode($body) : (string) $body,
            'errno' => $status === 0 ? 28 : 0,
            'error' => $status === 0 ? 'Operation timed out' : '',
        ];
        return $this;
    }

    /**
     * Number of queued responses not yet consumed.
     *
     * @return int
     */
    public function remaining(): int {
        return count($this->queue);
    }

    /**
     * Header lines of a recorded call, as a name => value map.
     *
     * @param int $call Call index.
     * @return array
     */
    public function header_map(int $call = 0): array {
        $map = [];
        foreach ($this->calls[$call]['headers'] as $line) {
            [$name, $value] = explode(':', $line, 2);
            $map[strtolower($name)] = trim($value);
        }
        return $map;
    }

    /**
     * {@inheritDoc}
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        int $connecttimeout
    ): array {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body,
            'timeout' => $timeout, 'connecttimeout' => $connecttimeout];
        if (!$this->queue) {
            throw new \coding_exception('scripted_transport: no queued response for ' . $method . ' ' . $url);
        }
        return array_shift($this->queue);
    }

    /**
     * {@inheritDoc}
     */
    public function stream(
        string $url,
        array $headers,
        callable $onheaderline,
        callable $onchunk,
        int $timeout,
        int $connecttimeout
    ): array {
        $this->calls[] = ['method' => 'GET', 'url' => $url, 'headers' => $headers, 'body' => null,
            'timeout' => $timeout, 'connecttimeout' => $connecttimeout];
        if (!$this->queue) {
            throw new \coding_exception('scripted_transport: no queued response for GET ' . $url);
        }
        $response = array_shift($this->queue);
        if ($response['status'] === 0) {
            return ['status' => 0, 'errno' => $response['errno'], 'error' => $response['error']];
        }
        $onheaderline("HTTP/1.1 {$response['status']} X\r\n");
        foreach ($response['headers'] as $name => $value) {
            $onheaderline("{$name}: {$value}\r\n");
        }
        $onheaderline("\r\n");
        // Deliver the body in 4-byte chunks to exercise the callbacks.
        foreach (str_split($response['body'], 4) as $chunk) {
            if ($chunk !== '' && $onchunk($chunk) === false) {
                return ['status' => $response['status'], 'errno' => 23, 'error' => 'write aborted'];
            }
        }
        return ['status' => $response['status'], 'errno' => 0, 'error' => ''];
    }
}
