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
 * Transcript download: single-use download token minted server-side, bytes streamed through.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

use local_vektradiscuss\local\http\assess_client;
use local_vektradiscuss\local\http\assess_response;

/**
 * Mints a transcript download token and forwards the transcript bytes as they arrive (REQ-088, BR-016).
 *
 * Nothing is stored: no file, no request directory, no session data. The response headers are the
 * plugin's own, sent only after Vektra answered 2xx and before the first byte; on any other answer
 * not a byte is forwarded. Reused by the teacher pages (P3) with the teacher key and actor_userid.
 */
final class transcript_proxy {
    /** @var string Transcript id: Vektra's session id (a UUID), checked loosely. */
    public const TID_PATTERN = '/^[A-Za-z0-9-]{1,64}\z/';

    /** @var string BR-016 file name: <course-shortname>_<activity-id>_<user-id>_s<N>_<yyyymmdd>.html. */
    public const FILENAME_PATTERN = '/^[A-Za-z0-9-]+_\d+_\d+_s\d+_\d{8}\.html\z/';

    /** @var string Result: bytes forwarded. */
    public const OK = 'ok';

    /** @var string Result: Vektra has not generated the transcript yet (404 ERR-ASSESS-017), try later. */
    public const NOT_READY = 'not_ready';

    /** @var string Result: any other failure, shown as the generic text. */
    public const FAILED = 'failed';

    /** @var string Result: the transfer broke after the first byte (the browser sees a short download). */
    public const TRUNCATED = 'truncated';

    /** @var string Vektra's role check of the grader was stale: a retry after a few seconds may pass. */
    public const STALE = 'stale';

    /** @var int Upper bound of a token's length. */
    private const MAX_TOKEN_LENGTH = 4096;

    /** @var config Configuration. */
    private config $config;

    /** @var assess_client Vektra client. */
    private assess_client $client;

    /**
     * Constructor.
     *
     * @param config|null $config
     * @param assess_client|null $client
     */
    public function __construct(?config $config = null, ?assess_client $client = null) {
        $this->config = $config ?? new config();
        $this->client = $client ?? new assess_client($this->config);
    }

    /**
     * Whether a transcript id has the expected shape.
     *
     * @param string $tid
     * @return bool
     */
    public static function valid_tid(string $tid): bool {
        return (bool) preg_match(self::TID_PATTERN, $tid);
    }

    /**
     * Mint a single-use download token for a student's own transcript (assess:mint key, sub = owner).
     *
     * @param int $userid The student, as the transcript owner.
     * @param string $tid
     * @param int $cmid The activity the request came through: Vektra refuses a transcript of another one.
     * @return array [result, token|null]: result OK with the token, NOT_READY or FAILED with null.
     */
    public function mint_own(int $userid, string $tid, int $cmid = 0): array {
        return $this->mint($tid, ['sub' => $userid] + ($cmid > 0 ? ['cmid' => $cmid] : []), 'mint');
    }

    /**
     * Mint a single-use download token for a grader (assess:teacher key, actor_userid = the teacher).
     *
     * Vektra checks that the actor grades the transcript's activity and, with the cmid, that the transcript
     * belongs to the activity whose capability Moodle checked (another one answers like an unknown transcript).
     *
     * @param int $actorid The teacher.
     * @param string $tid
     * @param int $cmid
     * @return array [result, token|null]: result OK with the token, NOT_READY or FAILED with null.
     */
    public function mint_grader(int $actorid, string $tid, int $cmid = 0): array {
        return $this->mint($tid, ['actor_userid' => $actorid] + ($cmid > 0 ? ['cmid' => $cmid] : []), 'teacher');
    }

    /**
     * POST download-token with a key role and its body.
     *
     * @param string $tid
     * @param array $body
     * @param string $keyrole
     * @return array [result, token|null]
     */
    private function mint(string $tid, array $body, string $keyrole): array {
        if (!self::valid_tid($tid)) {
            return [self::FAILED, null];
        }
        $response = $this->client->request(
            'POST',
            '/api/v1/assess/transcripts/' . $tid . '/download-token',
            [],
            $body,
            $keyrole
        );
        if (!$response->ok()) {
            self::log_failure('download-token', $response);
            if (teacher_api::role_refusal($response) === 'stale') {
                return [self::STALE, null];
            }
            return [$response->status === 404 && $response->errorcode === 'ERR-ASSESS-017' ? self::NOT_READY : self::FAILED, null];
        }
        $token = $response->json['token'] ?? null;
        if (
            !is_string($token) || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH
                || preg_match('/[^A-Za-z0-9._~+\/=-]/', $token)
        ) {
            debugging('Vektra assess download-token: malformed token response', DEBUG_DEVELOPER);
            return [self::FAILED, null];
        }
        return [self::OK, $token];
    }

    /**
     * Stream a transcript with a download token.
     *
     * @param string $tid
     * @param string $token Single-use download token.
     * @param int $n Session number, for the fallback file name.
     * @param callable $header fn(string $line): sends one response header.
     * @param callable $emit fn(string $chunk): sends body bytes.
     * @param int $maxbytes Body size limit (0: the client's limit); it can only lower that limit.
     * @param string $label Added to the fallback file name ('' for none).
     * @return string OK, NOT_READY, FAILED or TRUNCATED.
     */
    public function stream(
        string $tid,
        string $token,
        int $n,
        callable $header,
        callable $emit,
        int $maxbytes = 0,
        string $label = ''
    ): string {
        if (!self::valid_tid($tid)) {
            return self::FAILED;
        }
        $started = false;
        $response = $this->client->stream(
            '/api/v1/assess/transcripts/' . $tid,
            $token,
            function (int $status, array $headers) use (&$started, $header, $n, $label): void {
                foreach (self::headers($headers, $n, $label) as $line) {
                    $header($line);
                }
                $started = true;
            },
            function (string $chunk) use ($emit): bool {
                $emit($chunk);
                return true;
            },
            $maxbytes > 0 ? ['maxbytes' => $maxbytes] : []
        );
        // An aborted oversized body keeps the 200 status: it is a truncation, not a success.
        if ($response->ok() && $response->errorcode === null) {
            return self::OK;
        }
        self::log_failure('transcript', $response);
        if ($started) {
            return self::TRUNCATED;
        }
        return $response->status === 404 && $response->errorcode === 'ERR-ASSESS-017' ? self::NOT_READY : self::FAILED;
    }

    /**
     * Stream a transcript to the browser.
     *
     * Once the transfer starts (a refusal page rendered afterwards still needs them otherwise), Moodle's output
     * buffers and zlib compression are turned off, so the body goes out
     * as it arrives with Vektra's Content-Length, and no debugging notice is written into the file (it still
     * reaches the log). Inline because disable_output_buffering() is deprecated since Moodle 4.5.
     *
     * @param string $tid
     * @param string $token
     * @param int $n
     * @param string $label Added to the fallback file name (a grader download: the student's user id).
     * @return string OK, NOT_READY, FAILED or TRUNCATED.
     */
    public function send(string $tid, string $token, int $n, string $label = ''): string {
        $started = false;
        return $this->stream(
            $tid,
            $token,
            $n,
            function (string $line) use (&$started): void {
                global $CFG;
                if (!$started) {
                    $started = true;
                    if (ini_get_bool('zlib.output_compression')) {
                        ini_set('zlib.output_compression', 'Off');
                    }
                    while (ob_get_level() > 0 && ob_end_clean()) {
                        continue;
                    }
                    $CFG->debugdisplay = 0;
                }
                header($line);
            },
            function (string $chunk): void {
                echo $chunk;
                flush();
            },
            0,
            $label
        );
    }

    /**
     * The response headers the plugin sends: its own, never Vektra's, except a validated name and length.
     *
     * @param array $vektra Vektra's response headers, lower-case names.
     * @param int $n Session number, for the fallback file name.
     * @param string $label Added to the fallback file name ('' for none).
     * @return string[]
     */
    public static function headers(array $vektra, int $n, string $label = ''): array {
        $name = self::filename((string) ($vektra['content-disposition'] ?? ''));
        if ($name === null) {
            // The label (a grader download: the student's user id) keeps files of different students apart.
            $name = 'transcript_' . ($label !== '' ? $label . '_' : '') . 's' . max(1, $n) . '.html';
        }
        $lines = [
            'Content-Type: text/html; charset=utf-8',
            'Content-Disposition: attachment; filename="' . $name . '"',
            'X-Content-Type-Options: nosniff',
            "Content-Security-Policy: sandbox; default-src 'none'",
            'Cache-Control: no-store',
            'Referrer-Policy: no-referrer',
        ];
        $length = (string) ($vektra['content-length'] ?? '');
        $encoding = strtolower(trim((string) ($vektra['content-encoding'] ?? 'identity')));
        if (preg_match('/^\d{1,9}\z/', $length) && ($encoding === '' || $encoding === 'identity')) {
            // Lets the browser tell a broken transfer from a complete one.
            $lines[] = 'Content-Length: ' . $length;
        }
        return $lines;
    }

    /**
     * The file name from Vektra's Content-Disposition, only when it matches the BR-016 pattern.
     *
     * @param string $disposition
     * @return string|null
     */
    public static function filename(string $disposition): ?string {
        if (!preg_match('/(?:^|;)\s*filename="([^"]*)"/i', $disposition, $m)) {
            return null;
        }
        return preg_match(self::FILENAME_PATTERN, $m[1]) ? $m[1] : null;
    }

    /**
     * Developer log of a failed call: the step, status and request id only.
     *
     * @param string $step
     * @param assess_response $response
     */
    private static function log_failure(string $step, assess_response $response): void {
        debugging(
            "Vektra assess {$step} failed: HTTP {$response->status}" .
                ($response->requestid !== null ? ' request ' . clean_param($response->requestid, PARAM_ALPHANUMEXT) : ''),
            DEBUG_DEVELOPER
        );
    }
}
