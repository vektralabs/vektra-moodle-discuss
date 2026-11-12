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
 * HTTP client for the Vektra assess API (/api/v1/assess/*).
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local\http;

use local_vektradiscuss\local\config;

/**
 * Calls the assess endpoints with a narrow, per-role key.
 *
 * Contract (plan discuss-foundation, ARCH-067, NFR-014):
 * - only paths under {@see self::PATH_PREFIX}, never an arbitrary URL;
 * - never follows a redirect, always verifies TLS;
 * - refuses without any HTTP call when the key of the role is empty, is the block_vektra
 *   admin key or is the key of another role (error_code "not_configured");
 * - never throws on transport or HTTP errors (status 0 and error_code "transport"); it only
 *   throws coding_exception for programming errors (bad method, bad path);
 * - every request carries X-Vektra-Plugin-Version;
 * - secrets never reach debugging output ({@see self::redact()}).
 */
class assess_client {
    /** @var string Every request path must start with this prefix. */
    public const PATH_PREFIX = '/api/v1/assess/';

    /** @var string Request header carrying the plugin release (minimum plugin_version at mint). */
    public const VERSION_HEADER = 'X-Vektra-Plugin-Version';

    /** @var int Default total timeout in seconds. */
    public const DEFAULT_TIMEOUT = 5;

    /** @var int Maximum connect timeout in seconds. */
    public const MAX_CONNECT_TIMEOUT = 3;

    /** @var int Upper bound of a streamed body, in bytes (20 MB). */
    public const MAX_STREAM_BYTES = 20971520;

    /** @var int Bytes of an error body kept while streaming, to parse the error envelope. */
    private const ERROR_BODY_BYTES = 65536;

    /** @var string[] Allowed HTTP methods. */
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var config Configuration reader. */
    private config $config;

    /** @var transport HTTP transport. */
    private transport $transport;

    /**
     * Constructor.
     *
     * @param config|null $config Configuration reader (default: the site configuration).
     * @param transport|null $transport HTTP transport (default: \curl).
     */
    public function __construct(?config $config = null, ?transport $transport = null) {
        $this->config = $config ?? new config();
        $this->transport = $transport ?? self::default_transport();
    }

    /**
     * The network transport, or the in-site Vektra stub on a Behat site that turned it on.
     *
     * The stub is loaded only when BEHAT_SITE_RUNNING is defined (a Behat site, never production) and
     * its setting is on; it replaces an HTTP stub because the CI web server would have to call itself.
     *
     * @return transport
     */
    private static function default_transport(): transport {
        $stub = __DIR__ . '/../../../tests/fixtures/behat_stub_transport.php';
        if (defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING && file_exists($stub)) {
            require_once($stub);
            if (\local_vektradiscuss\tests\behat_stub_transport::active()) {
                return new \local_vektradiscuss\tests\behat_stub_transport();
            }
        }
        return new curl_transport();
    }

    /**
     * Call an assess endpoint and buffer the response.
     *
     * @param string $method GET, POST, PUT, PATCH or DELETE.
     * @param string $path Path starting with /api/v1/assess/.
     * @param array $query Query-string parameters.
     * @param array|null $body JSON body.
     * @param string $keyrole Key role (mint or teacher): selects the secret.
     * @param array $opts Options: timeout (seconds), bearer (a token replacing the key), headers (name => value,
     *     X-Assess-* names only, see {@see self::extra_headers()}).
     * @return assess_response
     * @throws \coding_exception For an invalid method, path or header, or a body on a GET.
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        string $keyrole = 'mint',
        array $opts = []
    ): assess_response {
        $method = strtoupper($method);
        if (!in_array($method, self::METHODS, true)) {
            throw new \coding_exception('Unsupported HTTP method: ' . $method);
        }
        $this->assert_path($path);
        // A programming error, so it is raised before prepare(): on a disabled or unconfigured site
        // prepare() returns a failure response, which would hide it until the site is enabled.
        if ($method === 'GET' && $body !== null) {
            throw new \coding_exception('A GET request cannot carry a body.');
        }
        $extra = self::extra_headers($opts['headers'] ?? []);

        $prepared = $this->prepare($path, $query, $keyrole, $opts);
        if ($prepared instanceof assess_response) {
            return $prepared;
        }
        [$url, $headers, $secret, $timeout, $connecttimeout] = $prepared;
        $headers = array_merge($headers, $extra);

        $payload = null;
        if ($body !== null) {
            try {
                $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                // Never send a silently emptied body (e.g. invalid UTF-8 in a student's text).
                return assess_response::failure('invalid_body', 'The request body cannot be encoded as JSON.');
            }
            $headers[] = 'Content-Type: application/json';
        }

        $result = $this->transport->send($method, $url, $headers, $payload, $timeout, $connecttimeout);
        $status = (int) $result['status'];

        if ($status === 0) {
            $message = self::redact('transport failure: ' . $result['error'], $secret);
            $this->log("Vektra assess {$method} {$path}: {$message}");
            return assess_response::failure('transport', $message);
        }

        return $this->build_response(
            $method,
            $path,
            $status,
            (string) $result['body'],
            (array) $result['headers'],
            $secret
        );
    }

    /**
     * Stream a response body (transcript and export downloads) without buffering it.
     *
     * Calls $onheaders(int $status, array $headers) once, after the status line and headers were
     * read and before the first body byte is passed to $onchunk(string $chunk), and only for a 2xx
     * response. Non-2xx bodies are never forwarded; they are parsed as the error envelope.
     * A body larger than MAX_STREAM_BYTES aborts the transfer (error_code "too_large").
     *
     * @param string $path Path starting with /api/v1/assess/.
     * @param string $bearer Single-use download token.
     * @param callable $onheaders Called with the status and the response headers.
     * @param callable $onchunk Called with each body chunk.
     * @param array $opts Options: timeout (seconds), maxbytes (may only lower the limit).
     * @return assess_response Without body JSON; carries the status, error fields and headers.
     * @throws \coding_exception For an invalid path.
     */
    public function stream(
        string $path,
        string $bearer,
        callable $onheaders,
        callable $onchunk,
        array $opts = []
    ): assess_response {
        $this->assert_path($path);
        $prepared = $this->prepare($path, [], 'mint', ['bearer' => $bearer] + $opts, false);
        if ($prepared instanceof assess_response) {
            return $prepared;
        }
        [$url, $headers, $secret, $timeout, $connecttimeout] = $prepared;

        $limit = min(self::MAX_STREAM_BYTES, max(1, (int) ($opts['maxbytes'] ?? self::MAX_STREAM_BYTES)));
        $state = (object) [
            'status' => 0, 'headers' => [], 'announced' => false, 'bytes' => 0,
            'errorbody' => '', 'toolarge' => false,
        ];

        $announce = function () use ($state, $onheaders): void {
            if (!$state->announced) {
                $state->announced = true;
                if ($state->status >= 200 && $state->status < 300) {
                    $onheaders($state->status, $state->headers);
                }
            }
        };

        $onheaderline = function (string $line) use ($state): void {
            $line = trim($line);
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                // A new status line (also after an interim 100): restart.
                $state->status = (int) $m[1];
                $state->headers = [];
            } else if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $state->headers[strtolower(trim($name))] = trim($value);
            }
        };

        $writer = function (string $chunk) use ($state, $announce, $onchunk, $limit): bool {
            $announce();
            if ($state->status < 200 || $state->status >= 300) {
                $room = self::ERROR_BODY_BYTES - strlen($state->errorbody);
                if ($room > 0) {
                    $state->errorbody .= substr($chunk, 0, $room);
                }
                return true;
            }
            $state->bytes += strlen($chunk);
            if ($state->bytes > $limit) {
                $state->toolarge = true;
                return false;
            }
            return $onchunk($chunk) !== false;
        };

        $result = $this->transport->stream($url, $headers, $onheaderline, $writer, $timeout, $connecttimeout);

        if ($state->toolarge) {
            return new assess_response(
                $state->status,
                null,
                'too_large',
                'response body exceeds the limit',
                null,
                null,
                $state->headers
            );
        }
        if ($result['errno'] !== 0 || $state->status === 0) {
            $message = self::redact('transport failure: ' . $result['error'], $secret);
            $this->log("Vektra assess GET {$path}: {$message}");
            return assess_response::failure('transport', $message);
        }
        $announce();

        if ($state->status >= 200 && $state->status < 300) {
            return new assess_response(
                $state->status,
                null,
                null,
                null,
                null,
                $state->headers['x-request-id'] ?? null,
                $state->headers
            );
        }
        return $this->build_response('GET', $path, $state->status, $state->errorbody, $state->headers, $secret);
    }

    /**
     * Log a failed call for developers (debugging, already redacted by the caller).
     *
     * Silent with the in-site Behat stub: there the failures are the scenario's own fixtures, and a Behat site
     * always displays debugging, which would fail the page that handles them.
     *
     * @param string $message
     */
    private function log(string $message): void {
        if (is_a($this->transport, 'local_vektradiscuss\\tests\\behat_stub_transport')) {
            return;
        }
        debugging($message, DEBUG_DEVELOPER);
    }

    /**
     * Header lines from an X-Assess-* map (the teacher routes' actor, course, activity type and cursor).
     *
     * Only X-Assess-* names, so a caller can never replace the Authorization or version header, and only
     * printable ASCII values without CR or LF, so no header can be injected.
     *
     * @param array $headers name => value
     * @return string[] Header lines.
     * @throws \coding_exception For a name or value outside these rules.
     */
    private static function extra_headers(array $headers): array {
        $lines = [];
        foreach ($headers as $name => $value) {
            $value = (string) $value;
            if (!is_string($name) || !preg_match('/^X-Assess-[A-Za-z-]{1,40}$/', $name)) {
                throw new \coding_exception('assess_client only sends X-Assess-* extra headers.');
            }
            if (!preg_match('/^[\x20-\x7E]{0,512}$/', $value)) {
                throw new \coding_exception('Invalid value for the header ' . $name . '.');
            }
            $lines[] = $name . ': ' . $value;
        }
        return $lines;
    }

    /**
     * Validate a path: assess prefix, no traversal, no scheme, no query or fragment.
     *
     * @param string $path
     * @throws \coding_exception
     */
    private function assert_path(string $path): void {
        $valid = str_starts_with($path, self::PATH_PREFIX)
            && preg_match('#^/api/v1/assess/[A-Za-z0-9._~%!$&\'()*+,;=:@/-]*$#', $path)
            && !preg_match('#(^|/)(\.|%2e){2}(/|$)#i', $path)
            // Encoded dots, slashes and backslashes: a server that decodes them would leave the prefix.
            && !preg_match('#%(2e|2f|5c)#i', $path)
            // Every percent sign must start a valid escape.
            && !preg_match('#%(?![0-9A-Fa-f]{2})#', $path)
            && !str_contains($path, '//');
        if (!$valid) {
            throw new \coding_exception('assess_client only calls ' . self::PATH_PREFIX . '* paths: ' . $path);
        }
    }

    /**
     * Resolve the URL, headers, secret and timeouts, or refuse.
     *
     * @param string $path
     * @param array $query
     * @param string $keyrole
     * @param array $opts
     * @param bool $accept Whether to ask for application/json (not for a streamed download).
     * @return array|assess_response [url, headers, secret, timeout, connecttimeout] or a refusal
     */
    private function prepare(string $path, array $query, string $keyrole, array $opts, bool $accept = true) {
        // Site kill switch: disabled means nothing is sent to Vektra, except privacy requests: the data stays in
        // Vektra after the plugin is turned off, and the site must still be able to export and erase it.
        if (!$this->config->is_enabled() && !str_starts_with($keyrole, 'privacy_')) {
            return assess_response::failure('disabled', 'The assessment discussion is disabled on this site.');
        }

        $bearer = $opts['bearer'] ?? null;
        if ($bearer !== null) {
            $secret = trim((string) $bearer);
            $usable = $secret !== '' && $secret !== $this->config->block_admin_key();
        } else {
            $usable = $this->config->key_usable($keyrole);
            $secret = $usable ? $this->config->key($keyrole) : '';
        }
        if (!$usable) {
            return assess_response::failure('not_configured', 'No usable assess key is configured for this call.');
        }

        $apiurl = $this->config->api_url();
        if ($apiurl === '') {
            return assess_response::failure('not_configured', 'The Vektra API URL is not configured.');
        }
        // Only an explicit dev override (explicit_config, smoke CLI) may relax this; $CFG->debugdeveloper
        // does not, because it is on in staging and sometimes in production.
        if (!str_starts_with(strtolower($apiurl), 'https://') && !$this->config->allow_insecure()) {
            return assess_response::failure('insecure_url', 'The Vektra API URL must use https.');
        }

        $url = $apiurl . $path;
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $timeout = max(1, (int) ($opts['timeout'] ?? self::DEFAULT_TIMEOUT));
        $headers = [
            'Authorization: Bearer ' . $secret,
            self::VERSION_HEADER . ': ' . $this->config->plugin_version(),
        ];
        if ($accept) {
            array_unshift($headers, 'Accept: application/json');
        }
        return [$url, $headers, $secret, $timeout, min($timeout, self::MAX_CONNECT_TIMEOUT)];
    }

    /**
     * Turn a status and body into a response, parsing the error envelope when not 2xx.
     *
     * @param string $method
     * @param string $path
     * @param int $status
     * @param string $body
     * @param array $headers
     * @param string $secret
     * @return assess_response
     */
    private function build_response(
        string $method,
        string $path,
        int $status,
        string $body,
        array $headers,
        string $secret
    ): assess_response {
        $json = json_decode($body, true);
        $json = is_array($json) ? $json : null;
        $requestid = isset($headers['x-request-id']) ? (string) $headers['x-request-id'] : null;

        if ($status >= 200 && $status < 300) {
            return new assess_response($status, $json, null, null, null, $requestid, $headers);
        }

        [$errorcode, $message, $details] = $this->parse_error_envelope($body, $status);
        if ($errorcode === null && $status >= 300 && $status < 400) {
            $errorcode = 'redirect_refused';
        }
        $this->log(
            self::redact("Vektra assess {$method} {$path} failed: HTTP {$status} - {$body}", $secret)
        );
        return new assess_response($status, $json, $errorcode, $message, $details, $requestid, $headers);
    }

    /**
     * Strip secrets from text destined for logs or on-screen diagnostics.
     *
     * Copied from block_vektra\vektra_client::redact() (vektralabs/vektra-moodle, release 0.6.2;
     * ADR-0001 decision 2) and extended: the known secret, any Authorization bearer value,
     * JWT-shaped strings, and, structurally, the value of every sensitive key (token, solution,
     * rubric and a few credential names) at any depth and of any type in an embedded JSON document.
     * Text that mentions a sensitive key but whose JSON cannot be parsed is replaced entirely. It
     * never returns null and never throws, whatever the input. Keep the two copies in step until the
     * backlog item to dedupe is done.
     *
     * @param string $text Raw text (e.g., "HTTP 403 - <body>").
     * @param string $secret A key or token known to the caller, removed verbatim.
     * @return string Text with secrets replaced by [REDACTED].
     */
    public static function redact(string $text, string $secret = ''): string {
        if ($secret !== '') {
            $text = str_replace($secret, '[REDACTED]', $text);
        }
        $text = self::safe_replace('/Bearer\s++\S++/', 'Bearer [REDACTED]', $text);
        $text = self::safe_replace('/eyJ[A-Za-z0-9_-]++\.[A-Za-z0-9_-]++\.[A-Za-z0-9_-]++/', '[REDACTED]', $text);

        // Structural redaction of an embedded JSON document (object or array), prefix kept.
        foreach (['{', '['] as $open) {
            $start = strpos($text, $open);
            if ($start === false) {
                continue;
            }
            $decoded = json_decode(substr($text, $start), true, 512);
            if (is_array($decoded)) {
                $encoded = json_encode(
                    self::redact_value($decoded),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                );
                return $encoded === false ? '[REDACTED: unencodable]' : substr($text, 0, $start) . $encoded;
            }
        }

        // Not parseable as JSON: if a sensitive key is mentioned, drop everything. A key can be written with
        // \uXXXX escapes (solution), so the check also runs on the unescaped text. preg_match() returns
        // false on a PCRE error, which counts as a match here: when in doubt, redact.
        $pattern = '/"(?:' . implode('|', self::SENSITIVE_KEYS) . ')"\s*+:/i';
        $unescaped = preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            fn(array $m): string => (string) mb_chr((int) hexdec($m[1]), 'UTF-8'),
            $text
        ) ?? $text;
        if (preg_match($pattern, $text) !== 0 || preg_match($pattern, $unescaped) !== 0) {
            return '[REDACTED: sensitive fields in unparseable text]';
        }
        return $text;
    }

    /** @var string[] Keys whose values are never logged, at any depth. */
    private const SENSITIVE_KEYS = [
        'token', 'access_token', 'refresh_token', 'id_token', 'solution', 'rubric', 'authorization',
        'apikey', 'api_key', 'secret', 'client_secret', 'password',
    ];

    /**
     * Replace the value of every sensitive key, at any depth and of any type.
     *
     * @param array $value Decoded JSON.
     * @param int $depth Current depth.
     * @return array
     */
    private static function redact_value(array $value, int $depth = 0): array {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $value[$key] = '[REDACTED]';
            } else if (is_array($item)) {
                $value[$key] = $depth >= 64 ? '[REDACTED]' : self::redact_value($item, $depth + 1);
            }
        }
        return $value;
    }

    /**
     * preg_replace that cannot return null: on a PCRE error (backtrack or JIT stack limit) the whole
     * text is replaced, so a secret can never survive a failed replacement.
     *
     * @param string $pattern
     * @param string $replacement
     * @param string $text
     * @return string
     */
    private static function safe_replace(string $pattern, string $replacement, string $text): string {
        $result = preg_replace($pattern, $replacement, $text);
        return $result ?? '[REDACTED: redaction failed]';
    }

    /**
     * Extract (code, message, details) from a Vektra error response body.
     *
     * Copied from block_vektra\vektra_client::parse_error_envelope() (vektralabs/vektra-moodle,
     * release 0.6.2; ADR-0001 decision 2) and extended to return the structured details.
     *
     * The platform returns the REQ-010 envelope at the document root:
     * `{"error": {"code": ..., "message": ..., "details": ...}}`. Older backends nested it under
     * `detail`; that shape is still accepted. FastAPI validation errors are
     * `{"detail": [{"msg": ..., "loc": [...]}]}` and a few plain handlers emit
     * `{"detail": "<string>"}`. Falls back to `HTTP <code>` when nothing parseable is found.
     *
     * @param string $response Raw response body.
     * @param int $httpcode HTTP status.
     * @return array{0: string|null, 1: string, 2: array|null} [error_code, message, details]
     */
    private function parse_error_envelope(string $response, int $httpcode): array {
        $message = "HTTP {$httpcode}";

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return [null, $message, null];
        }

        if (isset($data['error']) && is_array($data['error'])) {
            return $this->extract_error_object($data['error'], $message);
        }

        $detail = $data['detail'] ?? null;

        if (is_array($detail) && isset($detail['error']) && is_array($detail['error'])) {
            return $this->extract_error_object($detail['error'], $message);
        }

        if (is_string($detail) && $detail !== '') {
            return [null, $detail, null];
        }

        if (is_array($detail)) {
            $first = reset($detail);
            if (is_array($first) && !empty($first['msg'])) {
                return [null, (string) $first['msg'], $detail];
            }
            $encoded = json_encode($detail);
            if (is_string($encoded) && $encoded !== '[]' && $encoded !== '{}') {
                $message = $encoded;
            }
        }

        return [null, $message, null];
    }

    /**
     * Pull (code, message, details) out of a REQ-010 `error` object.
     *
     * Copied from block_vektra\vektra_client::extract_error_object() (see {@see self::redact()}).
     *
     * @param array $err The decoded `error` object.
     * @param string $fallback Message kept when `error.message` is absent.
     * @return array{0: string|null, 1: string, 2: array|null} [error_code, message, details]
     */
    private function extract_error_object(array $err, string $fallback): array {
        $errorcode = null;
        $message = $fallback;
        $details = null;
        // Stricter than !empty(): a literal '0' code or message is a real value.
        if (isset($err['code']) && is_string($err['code']) && $err['code'] !== '') {
            $errorcode = $err['code'];
        }
        if (isset($err['message']) && is_string($err['message']) && $err['message'] !== '') {
            $message = $err['message'];
        }
        if (isset($err['details']) && is_array($err['details'])) {
            $details = $err['details'];
        }
        return [$errorcode, $message, $details];
    }
}
