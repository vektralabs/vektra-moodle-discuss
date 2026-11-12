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
 * Tests for the transcript proxy.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

use local_vektradiscuss\local\http\assess_client;
use local_vektradiscuss\tests\scripted_transport;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/scripted_transport.php');

/**
 * Tests for {@see transcript_proxy}: the download-token mint, the forwarded bytes and headers, and that
 * nothing is forwarded or stored on a failure (REQ-088, BR-016).
 *
 * @covers \local_vektradiscuss\local\transcript_proxy
 */
final class transcript_proxy_test extends \advanced_testcase {
    /** @var string Transcript id (Vektra's session id). */
    private const TID = '0f8e7d6c-5b4a-4321-8fed-cba987654321';

    /** @var string A token-shaped download token. */
    private const TOKEN = 'dl.token-value_123';

    /** @var string Transcript bytes, with a multi-byte character to catch any re-encoding. */
    private const BODY = "<!DOCTYPE html>\n<html lang=\"it\"><body><h1>Trascrizione</h1><p>perché ∑ x²</p></body></html>\n";

    /** @var scripted_transport Transport. */
    private scripted_transport $transport;

    /**
     * The plugin enabled with a mint key.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_vektradiscuss');
        set_config('apiurl', 'https://vektra.example.test', 'block_vektra');
        set_config('apikey', 'test-admin-key-not-a-secret', 'block_vektra');
        set_config('mintkey', 'test-mint-key-not-a-secret', 'local_vektradiscuss');
        $this->transport = new scripted_transport();
    }

    /**
     * The proxy under test.
     *
     * @return transcript_proxy
     */
    private function proxy(): transcript_proxy {
        return new transcript_proxy(null, new assess_client(null, $this->transport));
    }

    /**
     * Stream with recording callbacks.
     *
     * @return array [result, header lines, body]
     */
    private function stream(): array {
        $headers = [];
        $body = '';
        $result = $this->proxy()->stream(
            self::TID,
            self::TOKEN,
            2,
            function (string $line) use (&$headers): void {
                $headers[] = $line;
            },
            function (string $chunk) use (&$body): void {
                $body .= $chunk;
            }
        );
        return [$result, $headers, $body];
    }

    /**
     * The mint goes to the transcript's download-token route with the mint key and the owner as sub.
     */
    public function test_mint_own(): void {
        $this->transport->respond(201, ['token' => self::TOKEN, 'expires_in' => 120]);
        $this->assertSame([transcript_proxy::OK, self::TOKEN], $this->proxy()->mint_own(42, self::TID));

        $call = $this->transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://vektra.example.test/api/v1/assess/transcripts/' . self::TID . '/download-token', $call['url']);
        $this->assertSame(['sub' => 42], json_decode($call['body'], true));
        $this->assertSame('Bearer test-mint-key-not-a-secret', $this->transport->header_map()['authorization']);
    }

    /**
     * Mint failures: not generated yet, another student's transcript, a malformed answer, a bad tid.
     */
    public function test_mint_failures(): void {
        $this->transport->respond(404, ['error' => ['code' => 'ERR-ASSESS-017', 'message' => 'not generated']]);
        $this->assertSame([transcript_proxy::NOT_READY, null], $this->proxy()->mint_own(42, self::TID));
        $this->assertDebuggingCalledCount(2);

        $this->transport->respond(403, ['error' => ['code' => 'ERR-AUTH-003', 'message' => 'not yours']]);
        $this->assertSame([transcript_proxy::FAILED, null], $this->proxy()->mint_own(42, self::TID));
        $this->assertDebuggingCalledCount(2);

        $this->transport->respond(201, ['token' => 'bad token with spaces', 'expires_in' => 120]);
        $this->assertSame([transcript_proxy::FAILED, null], $this->proxy()->mint_own(42, self::TID));
        $this->assertDebuggingCalled();

        $calls = count($this->transport->calls);
        $this->assertSame([transcript_proxy::FAILED, null], $this->proxy()->mint_own(42, '../state'));
        $this->assertCount($calls, $this->transport->calls);
    }

    /**
     * The bytes are forwarded unchanged (sha256-equal) under the plugin's own headers, with Vektra's name.
     */
    public function test_stream_forwards_bytes_and_headers(): void {
        $this->transport->respond(200, self::BODY, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="C1_42_7_s2_20261108.html"; filename*=UTF-8\'\'C1_42_7_s2_20261108.html',
            'Content-Length' => (string) strlen(self::BODY),
            'Set-Cookie' => 'vektra=1',
        ]);
        [$result, $headers, $body] = $this->stream();

        $this->assertSame(transcript_proxy::OK, $result);
        $this->assertSame(hash('sha256', self::BODY), hash('sha256', $body));
        $this->assertSame([
            'Content-Type: text/html; charset=utf-8',
            'Content-Disposition: attachment; filename="C1_42_7_s2_20261108.html"',
            'X-Content-Type-Options: nosniff',
            "Content-Security-Policy: sandbox; default-src 'none'",
            'Cache-Control: no-store',
            'Referrer-Policy: no-referrer',
            'Content-Length: ' . strlen(self::BODY),
        ], $headers);

        $call = $this->transport->calls[0];
        $this->assertSame('https://vektra.example.test/api/v1/assess/transcripts/' . self::TID, $call['url']);
        $this->assertSame('Bearer ' . self::TOKEN, $this->transport->header_map()['authorization']);
    }

    /**
     * A body over the size limit is cut off: the headers and the first bytes went out, so the result is
     * TRUNCATED, never OK, although the status stays 200.
     */
    public function test_oversized_body_is_truncated_not_ok(): void {
        $this->transport->respond(200, self::BODY, ['Content-Type' => 'text/html; charset=utf-8']);
        $headers = [];
        $body = '';
        $result = $this->proxy()->stream(
            self::TID,
            self::TOKEN,
            2,
            function (string $line) use (&$headers): void {
                $headers[] = $line;
            },
            function (string $chunk) use (&$body): void {
                $body .= $chunk;
            },
            8
        );
        $this->assertSame(transcript_proxy::TRUNCATED, $result);
        $this->assertNotEmpty($headers);
        $this->assertLessThanOrEqual(8, strlen($body));
        $this->assertDebuggingCalled();
    }

    /**
     * A file name outside the BR-016 pattern falls back to transcript_s<N>.html.
     */
    public function test_filename_fallback(): void {
        foreach (
            [
                'attachment; filename="../evil.html"',
                'attachment; filename="C1_42_7_s2_20261108.html.exe"',
                'attachment; filename="a b_1_2_s1_20261108.html"',
                'inline',
                '',
            ] as $disposition
        ) {
            $this->assertNull(transcript_proxy::filename($disposition), $disposition);
            $this->assertContains(
                'Content-Disposition: attachment; filename="transcript_s3.html"',
                transcript_proxy::headers(['content-disposition' => $disposition], 3)
            );
        }
        $name = 'EA-1_9_7_s1_20261108.html';
        $this->assertSame($name, transcript_proxy::filename('attachment; filename="' . $name . '"'));
    }

    /**
     * The length is forwarded only when it describes the bytes as sent.
     */
    public function test_content_length(): void {
        $this->assertContains('Content-Length: 10', transcript_proxy::headers(['content-length' => '10'], 1));
        $gzip = ['content-length' => '10', 'content-encoding' => 'gzip'];
        $this->assertNotContains('Content-Length: 10', transcript_proxy::headers($gzip, 1));
        $this->assertSame(6, count(transcript_proxy::headers(['content-length' => '10; x'], 1)));
    }

    /**
     * Any non-200: no header and no byte forwarded; a transcript not generated yet is told apart.
     */
    public function test_failure_forwards_nothing(): void {
        foreach (
            [
                [404, 'ERR-ASSESS-017', transcript_proxy::NOT_READY],
                [401, 'ERR-ASSESS-018', transcript_proxy::FAILED],
                [403, 'ERR-AUTH-003', transcript_proxy::FAILED],
                [500, 'ERR-ASSESS-020', transcript_proxy::FAILED],
            ] as [$status, $code, $expected]
        ) {
            $this->transport->respond($status, ['error' => ['code' => $code, 'message' => self::BODY]]);
            [$result, $headers, $body] = $this->stream();
            $this->assertSame($expected, $result, $code);
            $this->assertSame([], $headers, $code);
            $this->assertSame('', $body, $code);
            $this->assertDebuggingCalledCount(2);
        }

        $this->transport->respond(0);
        [$result, $headers, $body] = $this->stream();
        $this->assertSame(transcript_proxy::FAILED, $result);
        $this->assertSame([[], ''], [$headers, $body]);
        $this->assertDebuggingCalledCount(2);
    }

    /**
     * Nothing is stored: no File API, no request directory, no session (read from the sources).
     */
    public function test_stores_nothing(): void {
        foreach (['/../../classes/local/transcript_proxy.php', '/../../transcript.php'] as $file) {
            $source = file_get_contents(__DIR__ . $file);
            foreach (['file_storage', 'get_file_storage', 'pluginfile', 'make_request_directory', '$SESSION'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $file . ': ' . $forbidden);
            }
        }
    }
}
