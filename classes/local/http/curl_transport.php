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
 * Default transport: Moodle's \curl wrapper (keeps the curl security helper in force).
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local\http;

/**
 * Transport on top of \curl: no redirects, TLS verification on.
 */
class curl_transport implements transport {
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
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        int $connecttimeout
    ): array {
        if ($method === 'GET' && $body !== null) {
            // Moodle's curl would silently drop it.
            throw new \coding_exception('A GET request cannot carry a body.');
        }
        $curl = $this->new_curl($headers, $timeout, $connecttimeout);

        if ($method === 'GET') {
            $response = $curl->get($url);
        } else {
            // POST with a raw string body and a custom verb covers POST, PUT, PATCH and DELETE.
            $options = $method === 'POST' ? [] : ['CURLOPT_CUSTOMREQUEST' => $method];
            $response = $curl->post($url, $body ?? '', $options);
        }

        $info = $curl->get_info();
        $errno = (int) $curl->get_errno();
        $status = $errno === 0 ? (int) ($info['http_code'] ?? 0) : 0;

        $responseheaders = [];
        foreach ((array) $curl->getResponse() as $name => $value) {
            $responseheaders[strtolower((string) $name)] = is_array($value) ? end($value) : (string) $value;
        }

        return [
            'status' => $status,
            'headers' => $responseheaders,
            'body' => $status > 0 && is_string($response) ? $response : '',
            'errno' => $errno,
            'error' => $status > 0 ? '' : (is_string($response) && $response !== '' ? $response : 'transport failure'),
        ];
    }

    /**
     * Send a GET and hand header lines and body chunks to callbacks as they arrive.
     *
     * @param string $url Absolute URL.
     * @param string[] $headers Header lines.
     * @param callable $onheaderline Called with each raw response header line.
     * @param callable $onchunk Called with each body chunk; return false to abort.
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
    ): array {
        $curl = $this->new_curl($headers, $timeout, $connecttimeout);
        $curl->setopt([
            'CURLOPT_HEADERFUNCTION' => function ($handle, string $line) use ($onheaderline): int {
                $onheaderline($line);
                return strlen($line);
            },
            'CURLOPT_WRITEFUNCTION' => function ($handle, string $chunk) use ($onchunk): int {
                return $onchunk($chunk) === false ? -1 : strlen($chunk);
            },
        ]);

        $response = $curl->get($url);
        $info = $curl->get_info();
        $errno = (int) $curl->get_errno();

        return [
            'status' => (int) ($info['http_code'] ?? 0),
            'errno' => $errno,
            'error' => $errno !== 0 ? (is_string($response) ? $response : 'transport failure') : '',
        ];
    }

    /**
     * Build a \curl handle with the transport defaults.
     *
     * @param string[] $headers Header lines.
     * @param int $timeout Total timeout.
     * @param int $connecttimeout Connect timeout.
     * @return \curl
     */
    protected function new_curl(array $headers, int $timeout, int $connecttimeout): \curl {
        global $CFG;
        // \curl lives in filelib.php, which a web page loads but a CLI script or a task may not.
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt([
            // A redirect could carry the bearer to another host.
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_MAXREDIRS' => 0,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => $connecttimeout,
        ]);
        $curl->setHeader($headers);
        return $curl;
    }
}
