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
 * Transport used by the assess client to talk HTTP.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local\http;

/**
 * Minimal HTTP transport. The default implementation wraps Moodle's \curl; tests script it.
 *
 * Implementations MUST NOT follow redirects and MUST verify TLS.
 */
interface transport {
    /**
     * Send one request and buffer the response.
     *
     * @param string $method GET, POST, PUT, PATCH or DELETE.
     * @param string $url Absolute URL.
     * @param string[] $headers Header lines ("Name: value").
     * @param string|null $body Raw request body.
     * @param int $timeout Total timeout in seconds.
     * @param int $connecttimeout Connect timeout in seconds.
     * @return array{status: int, headers: array, body: string, errno: int, error: string}
     *         status is 0 and errno/error set on transport failure; header names lower-case.
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        int $connecttimeout
    ): array;

    /**
     * Send a GET and hand header lines and body chunks to callbacks as they arrive.
     *
     * @param string $url Absolute URL.
     * @param string[] $headers Header lines.
     * @param callable $onheaderline fn(string $line): void, called per raw response header line.
     * @param callable $onchunk fn(string $chunk): bool, return false to abort the transfer.
     * @param int $timeout Total timeout in seconds.
     * @param int $connecttimeout Connect timeout in seconds.
     * @return array{status: int, errno: int, error: string}
     */
    public function stream(
        string $url,
        array $headers,
        callable $onheaderline,
        callable $onchunk,
        int $timeout,
        int $connecttimeout
    ): array;
}
