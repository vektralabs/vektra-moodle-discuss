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
 * Loopback HTTP tests: the default \curl transport against a local fake assess backend.
 *
 * Moodle's \curl::mock_response() always answers HTTP 200, so redirects, error statuses and
 * streaming can only be proven over a real socket. A PHP built-in server on 127.0.0.1 plays
 * the backend (tests/fixtures/fake_assess_router.php); nothing leaves the machine.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local\http;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for {@see assess_client} with {@see curl_transport} over loopback.
 *
 * @covers \local_vektradiscuss\local\http\assess_client
 * @covers \local_vektradiscuss\local\http\curl_transport
 */
final class assess_client_http_test extends \advanced_testcase {
    /** @var resource|null The server process. */
    private $server = null;

    /** @var string Request log written by the fake backend. */
    private string $log;

    /** @var int Listening port. */
    private int $port;

    /**
     * Start the fake backend and point the plugin at it.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $this->log = tempnam(sys_get_temp_dir(), 'fakeassess');
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, __DIR__ . '/../../fixtures/fake_assess_router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['FAKE_ASSESS_LOG' => $this->log, 'PATH' => getenv('PATH')]
        );
        $up = false;
        for ($i = 0; $i < 50 && !$up; $i++) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.2);
            if ($socket) {
                fclose($socket);
                $up = true;
            } else {
                usleep(100000);
            }
        }
        $this->assertTrue($up, 'fake assess backend did not start');

        // Moodle's curl security helper blocks loopback and non-standard ports by default.
        $CFG->curlsecurityblockedhosts = '';
        $CFG->curlsecurityallowedport = (string) $this->port;
        $CFG->debugdeveloper = true;
        set_config('apiurl', 'http://127.0.0.1:' . $this->port, 'block_vektra');
        set_config('apikey', 'loopback-admin-key', 'block_vektra');
        set_config('mintkey', 'loopback-mint-key', 'local_vektradiscuss');
        set_config('teacherkey', 'loopback-teacher-key', 'local_vektradiscuss');
    }

    /**
     * Stop the fake backend.
     */
    protected function tearDown(): void {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if (isset($this->log) && file_exists($this->log)) {
            unlink($this->log);
        }
        parent::tearDown();
    }

    /**
     * The requests the fake backend saw.
     *
     * @return array[]
     */
    private function seen(): array {
        $lines = file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_map(fn($l) => json_decode($l, true), $lines);
    }

    /**
     * A JSON POST arrives with method, body, bearer and version header intact.
     */
    public function test_json_round_trip(): void {
        $response = (new assess_client())->request('POST', '/api/v1/assess/ok', ['q' => '1'], ['hello' => 'wörld']);

        $this->assertTrue($response->ok(), (string) $response->message);
        $this->assertSame('POST', $response->json['method']);
        $this->assertSame(['hello' => 'wörld'], $response->json['echo']);
        $this->assertSame('req-fake-1', $response->requestid);
        $seen = $this->seen();
        $this->assertCount(1, $seen);
        $this->assertSame('Bearer loopback-mint-key', $seen[0]['authorization']);
        $this->assertSame('0.1.0', $seen[0]['version']);
        $this->assertSame('application/json', $seen[0]['contenttype']);
        $this->assertSame('q=1', $seen[0]['query']);
    }

    /**
     * GET, PUT, PATCH and DELETE reach the server with the right verb.
     */
    public function test_verbs(): void {
        $client = new assess_client();
        foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            $response = $client->request($verb, '/api/v1/assess/ok', [], $verb === 'GET' ? null : ['v' => $verb], 'teacher');
            $this->assertTrue($response->ok(), $verb);
            $this->assertSame($verb, $response->json['method']);
        }
        $this->assertSame(['GET', 'PUT', 'PATCH', 'DELETE'], array_column($this->seen(), 'method'));
        $this->assertSame('Bearer loopback-teacher-key', $this->seen()[3]['authorization']);
    }

    /**
     * A 403 envelope over a real socket.
     */
    public function test_forbidden_envelope(): void {
        $response = (new assess_client())->request('GET', '/api/v1/assess/forbidden');
        $this->resetDebugging();

        $this->assertSame(403, $response->status);
        $this->assertSame('ERR-AUTH-003', $response->errorcode);
    }

    /**
     * A 302 to another path is not followed: status 302 and exactly one request reached the server.
     */
    public function test_redirect_not_followed_over_real_http(): void {
        $response = (new assess_client())->request('GET', '/api/v1/assess/redirect');
        $this->resetDebugging();

        $this->assertSame(302, $response->status);
        $this->assertSame('redirect_refused', $response->errorcode);
        $this->assertSame(['/api/v1/assess/redirect'], array_column($this->seen(), 'path'));
    }

    /**
     * A connection failure yields status 0 / "transport" without an exception.
     */
    public function test_transport_failure_over_real_http(): void {
        proc_terminate($this->server);
        proc_close($this->server);
        $this->server = null;

        $response = (new assess_client())->request('GET', '/api/v1/assess/ok', [], null, 'mint', ['timeout' => 2]);
        $this->resetDebugging();

        $this->assertSame(0, $response->status);
        $this->assertSame('transport', $response->errorcode);
    }

    /**
     * A streamed body is delivered in full, with the status announced first.
     */
    public function test_stream_over_real_http(): void {
        $events = [];
        $bytes = 0;

        $response = (new assess_client())->stream(
            '/api/v1/assess/stream',
            'dl-token',
            function (int $status) use (&$events): void {
                $events[] = $status;
            },
            function (string $chunk) use (&$bytes, &$events): void {
                $bytes += strlen($chunk);
                $events[] = 'chunk';
            }
        );

        $this->assertTrue($response->ok(), (string) $response->message);
        $this->assertSame(1000, $bytes);
        $this->assertSame(200, $events[0]);
        $this->assertSame('chunk', $events[1]);
    }

    /**
     * Over the byte limit the transfer aborts with "too_large".
     */
    public function test_stream_too_large_over_real_http(): void {
        $received = 0;
        $response = (new assess_client())->stream(
            '/api/v1/assess/stream',
            'dl-token',
            function (): void {
            },
            function (string $chunk) use (&$received): void {
                $received += strlen($chunk);
            },
            ['maxbytes' => 50]
        );

        $this->assertSame('too_large', $response->errorcode);
        $this->assertLessThanOrEqual(50, $received);
    }

    /**
     * A non-2xx stream is not forwarded to the callbacks.
     */
    public function test_stream_forbidden_over_real_http(): void {
        $called = false;
        $response = (new assess_client())->stream(
            '/api/v1/assess/stream-forbidden',
            'dl-token',
            function () use (&$called): void {
                $called = true;
            },
            function () use (&$called): void {
                $called = true;
            }
        );
        $this->resetDebugging();

        $this->assertFalse($called);
        $this->assertSame(403, $response->status);
        $this->assertSame('ERR-AUTH-003', $response->errorcode);
    }
}
