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
 * Unit tests for the assess client with a scripted transport.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local\http;

use local_vektradiscuss\tests\scripted_transport;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/scripted_transport.php');

/**
 * Tests for {@see assess_client}.
 *
 * @covers \local_vektradiscuss\local\http\assess_client
 * @covers \local_vektradiscuss\local\http\assess_response
 * @covers \local_vektradiscuss\local\config
 */
final class assess_client_test extends \advanced_testcase {
    /** @var string Block admin key used by the tests. */
    private const ADMINKEY = 'test-admin-key-not-a-secret';
    /** @var string Mint key used by the tests. */
    private const MINTKEY = 'test-mint-key-not-a-secret';
    /** @var string Teacher key used by the tests. */
    private const TEACHERKEY = 'test-teacher-key-not-a-secret';

    /** @var scripted_transport Transport under test. */
    private scripted_transport $transport;

    /**
     * Configure the site: https API URL, three distinct keys.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_vektradiscuss');
        set_config('apiurl', 'https://vektra.example.test/', 'block_vektra');
        set_config('apikey', self::ADMINKEY, 'block_vektra');
        set_config('mintkey', self::MINTKEY, 'local_vektradiscuss');
        set_config('teacherkey', self::TEACHERKEY, 'local_vektradiscuss');
        $this->transport = new scripted_transport();
    }

    /**
     * Build the client under test.
     *
     * @return assess_client
     */
    private function client(): assess_client {
        return new assess_client(null, $this->transport);
    }

    /**
     * A 2xx response is decoded and carries the request id; the request is well formed.
     */
    public function test_success_json(): void {
        $this->transport->respond(200, ['report_id' => 'r1'], ['X-Request-ID' => 'req-9']);

        $response = $this->client()->request('POST', '/api/v1/assess/sites/s1/setup-check', ['a' => 'b c'], ['x' => 1]);

        $this->assertTrue($response->ok());
        $this->assertSame(200, $response->status);
        $this->assertSame(['report_id' => 'r1'], $response->json);
        $this->assertNull($response->errorcode);
        $this->assertSame('req-9', $response->requestid);

        $call = $this->transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://vektra.example.test/api/v1/assess/sites/s1/setup-check?a=b%20c', $call['url']);
        $this->assertSame('{"x":1}', $call['body']);
        $this->assertSame(5, $call['timeout']);
        $this->assertSame(3, $call['connecttimeout']);
        $headers = $this->transport->header_map();
        $this->assertSame('Bearer ' . self::MINTKEY, $headers['authorization']);
        $this->assertSame('application/json', $headers['content-type']);
    }

    /**
     * The key of the role selects the secret.
     */
    public function test_key_selected_by_role(): void {
        $this->transport->respond(200, [])->respond(200, []);

        $this->client()->request('GET', '/api/v1/assess/x', [], null, 'mint');
        $this->client()->request('GET', '/api/v1/assess/x', [], null, 'teacher');

        $this->assertSame('Bearer ' . self::MINTKEY, $this->transport->header_map(0)['authorization']);
        $this->assertSame('Bearer ' . self::TEACHERKEY, $this->transport->header_map(1)['authorization']);
    }

    /**
     * A per-call bearer replaces the key, whatever the role keys are.
     */
    public function test_bearer_option_replaces_key(): void {
        set_config('mintkey', '', 'local_vektradiscuss');
        $this->transport->respond(200, []);

        $response = $this->client()->request('POST', '/api/v1/assess/dl', [], null, 'mint', ['bearer' => 'dl-token-1']);

        $this->assertTrue($response->ok());
        $this->assertSame('Bearer dl-token-1', $this->transport->header_map()['authorization']);
    }

    /**
     * The X-Vektra-Plugin-Version header is on every request, with the release from version.php.
     */
    public function test_plugin_version_header_on_every_request(): void {
        $this->transport->respond(200, [])->respond(403, [])->respond(0)->respond(200, []);

        $client = $this->client();
        $client->request('GET', '/api/v1/assess/a');
        $client->request('GET', '/api/v1/assess/b');
        $client->request('GET', '/api/v1/assess/c');
        $this->resetDebugging();
        $client->request('POST', '/api/v1/assess/d', [], ['k' => 'v'], 'teacher');

        $plugin = new \stdClass();
        require(__DIR__ . '/../../../version.php');
        $this->assertCount(4, $this->transport->calls);
        foreach (array_keys($this->transport->calls) as $i) {
            $this->assertSame($plugin->release, $this->transport->header_map($i)['x-vektra-plugin-version']);
        }
    }

    /**
     * Data provider: REQ-010 error statuses.
     *
     * @return array
     */
    public static function envelope_provider(): array {
        return [
            '403' => [403, 'ERR-AUTH-003', 'scope not allowed', null],
            '409' => [409, 'ERR-ASSESS-010', 'already started', ['session' => 'abc']],
            '422' => [422, 'ERR-VALIDATION-001', 'invalid', ['fields' => [['loc' => ['body', 'x'], 'msg' => 'required']]]],
            '429' => [429, 'ERR-ASSESS-002', 'slow down', ['retry_after' => 60]],
        ];
    }

    /**
     * The REQ-010 envelope yields error code, message and structured details.
     *
     * @dataProvider envelope_provider
     * @param int $status
     * @param string $code
     * @param string $message
     * @param array|null $details
     */
    public function test_error_envelope(int $status, string $code, string $message, ?array $details): void {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }
        $this->transport->respond($status, ['error' => $error], ['X-Request-ID' => 'req-err']);

        $response = $this->client()->request('GET', '/api/v1/assess/x');

        $this->assertFalse($response->ok());
        $this->assertSame($status, $response->status);
        $this->assertSame($code, $response->errorcode);
        $this->assertSame($message, $response->message);
        $this->assertSame($details, $response->details);
        $this->assertSame('req-err', $response->requestid);
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }

    /**
     * The legacy envelope nested under "detail" is tolerated; so are FastAPI validation errors.
     */
    public function test_legacy_and_validation_envelopes(): void {
        $this->transport
            ->respond(409, ['detail' => ['error' => ['code' => 'ERR-OLD-001', 'message' => 'legacy']]])
            ->respond(422, ['detail' => [['msg' => 'field required', 'loc' => ['body', 'x']]]])
            ->respond(500, 'not json at all');

        $legacy = $this->client()->request('GET', '/api/v1/assess/x');
        $validation = $this->client()->request('GET', '/api/v1/assess/x');
        $garbage = $this->client()->request('GET', '/api/v1/assess/x');
        $this->resetDebugging();

        $this->assertSame('ERR-OLD-001', $legacy->errorcode);
        $this->assertSame('legacy', $legacy->message);
        $this->assertNull($validation->errorcode);
        $this->assertSame('field required', $validation->message);
        $this->assertNull($garbage->errorcode);
        $this->assertSame('HTTP 500', $garbage->message);
        $this->assertSame(500, $garbage->status);
    }

    /**
     * Transport failure: status 0, error_code "transport", no exception.
     */
    public function test_transport_failure_does_not_throw(): void {
        $this->transport->respond(0);

        $response = $this->client()->request('GET', '/api/v1/assess/x');

        $this->assertSame(0, $response->status);
        $this->assertFalse($response->ok());
        $this->assertSame('transport', $response->errorcode);
        $this->resetDebugging();
    }

    /**
     * Data provider: paths that are not assess paths.
     *
     * @return array
     */
    public static function bad_path_provider(): array {
        return [
            'other api' => ['/api/v1/admin/keys'],
            'absolute url' => ['https://evil.example.test/api/v1/assess/x'],
            'no leading slash' => ['api/v1/assess/x'],
            'prefix lookalike' => ['/api/v1/assessment/x'],
            'traversal' => ['/api/v1/assess/../admin/keys'],
            'encoded traversal' => ['/api/v1/assess/%2e%2e/admin'],
            'query smuggled in path' => ['/api/v1/assess/x?token=1'],
            'newline' => ["/api/v1/assess/x\r\nX-Evil: 1"],
            'double slash' => ['/api/v1/assess//x'],
            'encoded traversal 1' => ['/api/v1/assess/..%2f..%2fadmin'],
            'encoded traversal 2' => ['/api/v1/assess/%2e%2e%2fadmin'],
            'encoded traversal upper' => ['/api/v1/assess/..%2Fadmin'],
            'encoded traversal inner' => ['/api/v1/assess/a%2f..%2f..%2fadmin'],
            'encoded dot upper' => ['/api/v1/assess/%2E%2E/admin'],
            'encoded slash alone' => ['/api/v1/assess/a%2Fb'],
            'encoded backslash' => ['/api/v1/assess/a%5Cb'],
            'encoded backslash lower' => ['/api/v1/assess/..%5c..%5cadmin'],
            'bare percent' => ['/api/v1/assess/50%'],
            'broken escape' => ['/api/v1/assess/a%zzb'],
        ];
    }

    /**
     * Data provider: paths built with rawurlencode() that must keep working.
     *
     * @return array
     */
    public static function valid_path_provider(): array {
        return [
            'uuid' => ['/api/v1/assess/sessions/' . rawurlencode('3f2b8c1e-6a4d-4e0b-9d57-0c1f2a3b4c5d') . '/token'],
            'int id' => ['/api/v1/assess/sites/' . rawurlencode('42') . '/setup-check'],
            'space in id' => ['/api/v1/assess/sites/' . rawurlencode('a b') . '/x'],
            'encoded colon and at' => ['/api/v1/assess/x/' . rawurlencode('a:b@c') . '/y'],
            'plain' => ['/api/v1/assess/transcripts/t1/download'],
        ];
    }

    /**
     * Legitimate encoded identifiers (no separators, no dots) are accepted.
     *
     * @dataProvider valid_path_provider
     * @param string $path
     */
    public function test_valid_paths_accepted(string $path): void {
        $this->transport->respond(200, []);

        $this->assertTrue($this->client()->request('GET', $path)->ok());
    }

    /**
     * A path outside /api/v1/assess/ is a programming error: refused before any request.
     *
     * @dataProvider bad_path_provider
     * @param string $path
     */
    public function test_non_assess_path_rejected(string $path): void {
        $this->transport->respond(200, []);

        try {
            $this->client()->request('GET', $path);
            $this->fail('coding_exception expected');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('/api/v1/assess/', $e->getMessage());
        }
        $this->assertCount(0, $this->transport->calls);
        $this->assertSame(1, $this->transport->remaining());
    }

    /**
     * An unsupported HTTP method is a programming error.
     */
    public function test_bad_method_rejected(): void {
        $this->expectException(\coding_exception::class);
        $this->client()->request('TRACE', '/api/v1/assess/x');
    }

    /**
     * Data provider: key configurations that must not produce any HTTP call.
     *
     * @return array
     */
    public static function unusable_key_provider(): array {
        return [
            'empty mint key' => ['mintkey', ''],
            'mint key is the block admin key' => ['mintkey', self::ADMINKEY],
            'mint key equals the teacher key' => ['mintkey', self::TEACHERKEY],
        ];
    }

    /**
     * Empty, admin-valued or duplicated key: 0 HTTP calls (the queued response stays unconsumed).
     *
     * @dataProvider unusable_key_provider
     * @param string $setting
     * @param string $value
     */
    public function test_unusable_key_makes_no_call(string $setting, string $value): void {
        set_config($setting, $value, 'local_vektradiscuss');
        $this->transport->respond(200, ['should' => 'not be consumed']);

        $response = $this->client()->request('POST', '/api/v1/assess/x', [], ['a' => 1], 'mint');

        $this->assertSame(0, $response->status);
        $this->assertSame('not_configured', $response->errorcode);
        $this->assertCount(0, $this->transport->calls);
        $this->assertSame(1, $this->transport->remaining());
    }

    /**
     * Neither the block admin key nor an empty value can be passed as a bearer either.
     */
    public function test_admin_key_refused_as_bearer(): void {
        $this->transport->respond(200, []);

        $admin = $this->client()->request('GET', '/api/v1/assess/x', [], null, 'mint', ['bearer' => self::ADMINKEY]);
        $empty = $this->client()->request('GET', '/api/v1/assess/x', [], null, 'mint', ['bearer' => ' ']);

        $this->assertSame('not_configured', $admin->errorcode);
        $this->assertSame('not_configured', $empty->errorcode);
        $this->assertSame(1, $this->transport->remaining());
    }

    /**
     * No API URL, or a non-https API URL outside developer debugging: no call.
     */
    public function test_api_url_required_and_https(): void {
        global $CFG;
        $this->transport->respond(200, []);

        set_config('apiurl', '', 'block_vektra');
        $none = $this->client()->request('GET', '/api/v1/assess/x');
        $this->assertSame('not_configured', $none->errorcode);

        set_config('apiurl', 'http://vektra.example.test', 'block_vektra');
        // Developer debugging, common in staging, must NOT relax the https rule.
        $CFG->debugdeveloper = true;
        $insecure = $this->client()->request('GET', '/api/v1/assess/x');
        $this->assertSame('insecure_url', $insecure->errorcode);
        $this->assertCount(0, $this->transport->calls);
        $this->assertSame(1, $this->transport->remaining());

        // Only an explicit, code-level override does.
        $explicit = new \local_vektradiscuss\local\explicit_config(
            'http://vektra.example.test',
            ['mint' => self::MINTKEY],
            '',
            true,
            true
        );
        $this->assertTrue((new assess_client($explicit, $this->transport))->request('GET', '/api/v1/assess/x')->ok());
        $notinsecure = new \local_vektradiscuss\local\explicit_config(
            'http://vektra.example.test',
            ['mint' => self::MINTKEY]
        );
        $this->transport->respond(200, []);
        $refused = (new assess_client($notinsecure, $this->transport))->request('GET', '/api/v1/assess/x');
        $this->assertSame('insecure_url', $refused->errorcode);
    }

    /**
     * The kill switch: disabled means 0 HTTP calls, for request() and stream().
     */
    public function test_disabled_site_sends_nothing(): void {
        set_config('enabled', 0, 'local_vektradiscuss');
        $this->transport->respond(200, [])->respond(200, 'x');

        $request = $this->client()->request('GET', '/api/v1/assess/x');
        $stream = $this->client()->stream('/api/v1/assess/x', 'tok', function (): void {
        }, function (): void {
        });

        $this->assertSame('disabled', $request->errorcode);
        $this->assertSame('disabled', $stream->errorcode);
        $this->assertCount(0, $this->transport->calls);
        $this->assertSame(2, $this->transport->remaining());

        set_config('enabled', 1, 'local_vektradiscuss');
        $this->assertTrue($this->client()->request('GET', '/api/v1/assess/x')->ok());
    }

    /**
     * A body that cannot be JSON-encoded (invalid UTF-8) is refused, not sent as an empty body.
     */
    public function test_invalid_utf8_body_is_refused(): void {
        $this->transport->respond(200, []);

        $response = $this->client()->request('POST', '/api/v1/assess/x', [], ['text' => "bad \xB1\x31 utf8"]);

        $this->assertSame('invalid_body', $response->errorcode);
        $this->assertSame(0, $response->status);
        $this->assertCount(0, $this->transport->calls);
        $this->assertSame(1, $this->transport->remaining());
    }

    /**
     * A body on a GET is a programming error: refused, no Content-Type, no call.
     */
    public function test_get_with_body_rejected(): void {
        $this->transport->respond(200, []);

        try {
            $this->client()->request('GET', '/api/v1/assess/x', [], ['a' => 1]);
            $this->fail('coding_exception expected');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('GET', $e->getMessage());
        }
        $this->assertCount(0, $this->transport->calls);
    }

    /**
     * The default transport refuses a GET body instead of silently dropping it.
     */
    public function test_curl_transport_rejects_get_body(): void {
        $this->expectException(\coding_exception::class);
        (new curl_transport())->send('GET', 'https://vektra.example.test/api/v1/assess/x', [], '{"a":1}', 5, 3);
    }

    /**
     * A streamed download does not ask for JSON.
     */
    public function test_stream_sends_no_accept_header(): void {
        $this->transport->respond(200, 'abc')->respond(200, []);

        $this->client()->stream('/api/v1/assess/x', 'tok', function (): void {
        }, function (): void {
        });
        $this->client()->request('GET', '/api/v1/assess/x');

        $this->assertArrayNotHasKey('accept', $this->transport->header_map(0));
        $this->assertSame('application/json', $this->transport->header_map(1)['accept']);
    }

    /**
     * Per-call timeout is honoured and the connect timeout is capped at 3 s.
     */
    public function test_timeout_override(): void {
        $this->transport->respond(200, [])->respond(200, []);

        $this->client()->request('GET', '/api/v1/assess/x', [], null, 'mint', ['timeout' => 2]);
        $this->client()->request('GET', '/api/v1/assess/x', [], null, 'mint', ['timeout' => 30]);

        $this->assertSame([2, 2], [$this->transport->calls[0]['timeout'], $this->transport->calls[0]['connecttimeout']]);
        $this->assertSame([30, 3], [$this->transport->calls[1]['timeout'], $this->transport->calls[1]['connecttimeout']]);
    }

    /**
     * A 302 is returned as is; the scripted transport proves no second request is made.
     */
    public function test_redirect_is_not_followed(): void {
        $this->transport->respond(302, '', ['Location' => 'https://evil.example.test/steal'])
            ->respond(200, ['followed' => true]);

        $response = $this->client()->request('GET', '/api/v1/assess/x');
        $this->resetDebugging();

        $this->assertSame(302, $response->status);
        $this->assertFalse($response->ok());
        $this->assertSame('redirect_refused', $response->errorcode);
        $this->assertCount(1, $this->transport->calls);
        $this->assertSame(1, $this->transport->remaining());
    }

    /**
     * The default transport is configured without redirects and with TLS verification.
     */
    public function test_default_transport_options(): void {
        $transport = new class extends curl_transport {
            /** @var \curl|null Handle built by the last call. */
            public ?\curl $built = null;

            /**
             * Capture the handle.
             *
             * @param array $headers
             * @param int $timeout
             * @param int $connecttimeout
             * @return \curl
             */
            protected function new_curl(array $headers, int $timeout, int $connecttimeout): \curl {
                $this->built = parent::new_curl($headers, $timeout, $connecttimeout);
                return $this->built;
            }
        };
        \curl::mock_response('{}');
        $transport->send('GET', 'https://vektra.example.test/api/v1/assess/x', [], null, 5, 3);

        $options = (new \ReflectionProperty(\curl::class, 'options'))->getValue($transport->built);
        $this->assertEquals(0, $options['CURLOPT_FOLLOWLOCATION']);
        $this->assertEquals(0, $options['CURLOPT_MAXREDIRS']);
        $this->assertEquals(1, $options['CURLOPT_SSL_VERIFYPEER']);
        $this->assertEquals(2, $options['CURLOPT_SSL_VERIFYHOST']);
    }

    /**
     * The Authorization header, the key and sensitive fields never appear in debugging output.
     */
    public function test_secrets_absent_from_debugging(): void {
        $leaky = json_encode([
            'error' => ['code' => 'ERR-X-001', 'message' => 'bad'],
            'echo' => 'Authorization: Bearer ' . self::MINTKEY,
            'key' => self::MINTKEY,
            'token' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl',
            'solution' => 'the answer is 42',
            'rubric' => ['criteria' => [['desc' => 'secret criterion', 'levels' => [1, 2]]]],
            'transcript' => ['safe' => 'visible'],
        ]);
        $this->transport->respond(403, $leaky)->respond(0);

        $this->client()->request('GET', '/api/v1/assess/x');
        $messages = $this->getDebuggingMessages();
        $this->assertCount(1, $messages);
        $text = $messages[0]->message;
        $this->assertStringContainsString('ERR-X-001', $text);
        $this->assertStringContainsString('[REDACTED]', $text);
        foreach ([self::MINTKEY, 'eyJhbGci', 'the answer is 42', 'secret criterion'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);

        $this->client()->request('GET', '/api/v1/assess/x');
        $messages = $this->getDebuggingMessages();
        $this->assertCount(1, $messages);
        $this->assertStringNotContainsString(self::MINTKEY, $messages[0]->message);
        $this->resetDebugging();
    }

    /**
     * Redaction of free text: secret, bearer, JWT.
     */
    public function test_redact_text(): void {
        $out = assess_client::redact('k=abc123 Bearer abc123x eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2ln ok', 'abc123');

        $this->assertStringNotContainsString('abc123', $out);
        $this->assertStringNotContainsString('eyJhbGci', $out);
        $this->assertStringEndsWith(' ok', $out);
    }

    /**
     * Redaction is structural: any depth, any value type, other keys kept.
     */
    public function test_redact_structural(): void {
        $body = json_encode([
            'keep' => 'yes',
            'solution' => 42,
            'token' => 123456,
            'flag' => ['solution' => true, 'Rubric' => null, 'ok' => 1],
            'rubric' => ['a' => ['b' => ['c' => ['d' => ['deep secret criterion']]]]],
            'nested' => ['l1' => ['l2' => ['l3' => ['l4' => ['Token' => 'deep-token-value']]]]],
            'list' => [['solution' => 'in a list'], ['x' => 'y']],
        ]);

        $out = assess_client::redact('Vektra assess GET /x failed: HTTP 403 - ' . $body);

        $this->assertStringStartsWith('Vektra assess GET /x failed: HTTP 403 - {', $out);
        foreach (['deep secret criterion', 'deep-token-value', '123456', '"solution":42', 'in a list'] as $secret) {
            $this->assertStringNotContainsString($secret, $out);
        }
        $this->assertStringContainsString('"keep":"yes"', $out);
        $this->assertStringContainsString('"ok":1', $out);
        $this->assertStringContainsString('"x":"y"', $out);
    }

    /**
     * Unparseable text that mentions a sensitive key is dropped entirely.
     */
    public function test_redact_unparseable_with_sensitive_key(): void {
        $out = assess_client::redact('oops "solution" : 42 {broken');

        $this->assertStringNotContainsString('42', $out);
        $this->assertStringContainsString('REDACTED', $out);
    }

    /**
     * A sensitive key written with \uXXXX escapes in unparseable text is still caught.
     */
    public function test_redact_unparseable_with_escaped_sensitive_key(): void {
        foreach (['{"solution": "leaked-answer"', '{"access_token": "leaked-answer"'] as $text) {
            $out = assess_client::redact('HTTP 400 - ' . $text);

            $this->assertStringNotContainsString('leaked-answer', $out);
            $this->assertStringContainsString('REDACTED', $out);
        }
        // Unparseable text without a sensitive key is kept as is.
        $this->assertSame('HTTP 500 - {"message": "boom"', assess_client::redact('HTTP 500 - {"message": "boom"'));
    }

    /**
     * OAuth-style credential keys are redacted at any depth, in parseable and unparseable text.
     */
    public function test_redact_credential_key_family(): void {
        foreach (['access_token', 'refresh_token', 'id_token', 'client_secret'] as $key) {
            $parsed = assess_client::redact('HTTP 400 - ' . json_encode(['a' => [$key => 'leaked-value'], 'keep' => 'yes']));
            $this->assertStringNotContainsString('leaked-value', $parsed, $key);
            $this->assertStringContainsString('"keep":"yes"', $parsed);

            $broken = assess_client::redact('HTTP 400 - {"' . $key . '": "leaked-value"');
            $this->assertStringNotContainsString('leaked-value', $broken, $key);
        }
    }

    /**
     * A tens-of-KB value full of escapes neither returns null nor throws (PCRE limits), and is redacted.
     */
    public function test_redact_huge_value_never_fails(): void {
        $huge = str_repeat('a\\"b ', 40000);
        $json = '{"solution":"' . $huge . '","keep":"yes"}';
        $this->assertGreaterThan(200000, strlen($json));

        $out = assess_client::redact($json, 'a');
        $this->assertIsString($out);
        $this->assertStringNotContainsString('b b', $out);

        $unparsed = assess_client::redact('"rubric":"' . str_repeat('x\\"', 100000) . '  trailing garbage {');
        $this->assertIsString($unparsed);
        $this->assertStringNotContainsString('xx', $unparsed);

        // And through the client: a 500 with a huge body still yields a response, not an exception.
        $this->transport->respond(500, $json);
        $response = $this->client()->request('GET', '/api/v1/assess/x');
        $this->assertSame(500, $response->status);
        $this->resetDebugging();
    }

    /**
     * Debugging output redacts deep rubrics and scalar sensitive values.
     */
    public function test_debugging_redacts_deep_and_scalar_values(): void {
        $leaky = json_encode([
            'error' => ['code' => 'ERR-X-001', 'message' => 'bad'],
            'solution' => 42,
            'token' => 987654321,
            'rubric' => ['a' => ['b' => ['c' => ['d' => 'depth-four-criterion']]]],
        ]);
        $this->transport->respond(403, $leaky);

        $this->client()->request('GET', '/api/v1/assess/x');
        $messages = $this->getDebuggingMessages();

        $this->assertCount(1, $messages);
        foreach (['depth-four-criterion', '987654321', '"solution":42'] as $secret) {
            $this->assertStringNotContainsString($secret, $messages[0]->message);
        }
        $this->resetDebugging();
    }

    /**
     * Streaming: headers are announced once, before the first chunk; chunks arrive in order.
     */
    public function test_stream_success(): void {
        $this->transport->respond(200, 'abcdefghij', ['Content-Type' => 'text/csv', 'X-Request-ID' => 'req-s']);
        $events = [];

        $response = $this->client()->stream(
            '/api/v1/assess/transcripts/t1/download',
            'dl-token',
            function (int $status, array $headers) use (&$events): void {
                $events[] = ['headers', $status, $headers['content-type']];
            },
            function (string $chunk) use (&$events): void {
                $events[] = ['chunk', $chunk];
            }
        );

        $this->assertTrue($response->ok());
        $this->assertSame('req-s', $response->requestid);
        $this->assertSame(['headers', 200, 'text/csv'], $events[0]);
        $this->assertSame('abcdefghij', implode('', array_map(fn($e) => $e[1], array_slice($events, 1))));
        $this->assertSame('Bearer dl-token', $this->transport->header_map()['authorization']);
        $this->assertArrayHasKey('x-vektra-plugin-version', $this->transport->header_map());
    }

    /**
     * Streaming a non-2xx: no callback is invoked; the error envelope is parsed.
     */
    public function test_stream_error_not_forwarded(): void {
        $this->transport->respond(403, ['error' => ['code' => 'ERR-AUTH-003', 'message' => 'no']]);
        $called = false;

        $response = $this->client()->stream(
            '/api/v1/assess/transcripts/t1/download',
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

    /**
     * Streaming more than the limit aborts with "too_large" and stops forwarding.
     */
    public function test_stream_too_large(): void {
        $this->transport->respond(200, str_repeat('z', 100));
        $received = 0;

        $response = $this->client()->stream(
            '/api/v1/assess/transcripts/t1/download',
            'dl-token',
            function (): void {
            },
            function (string $chunk) use (&$received): void {
                $received += strlen($chunk);
            },
            ['maxbytes' => 10]
        );

        $this->assertSame('too_large', $response->errorcode);
        $this->assertLessThanOrEqual(10, $received);
    }

    /**
     * The stream limit can only be lowered, never raised above 20 MB.
     */
    public function test_stream_limit_constant(): void {
        $this->assertSame(20 * 1024 * 1024, assess_client::MAX_STREAM_BYTES);
    }

    /**
     * Streaming with an unusable bearer makes no call.
     */
    public function test_stream_requires_bearer(): void {
        $this->transport->respond(200, 'x');

        $response = $this->client()->stream('/api/v1/assess/x', self::ADMINKEY, function (): void {
        }, function (): void {
        });

        $this->assertSame('not_configured', $response->errorcode);
        $this->assertSame(1, $this->transport->remaining());
    }
}
