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
 * Tests for the student endpoint.
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
 * Tests for {@see student_endpoint}: Moodle refusals before any Vektra call, the mint body, the
 * failure mapping and the login-as fast path.
 *
 * "0 Vektra calls" is asserted on the scripted transport: an empty call log and an untouched queue.
 *
 * @covers \local_vektradiscuss\local\student_endpoint
 * @covers \local_vektradiscuss\local\state_dto
 * @covers \local_vektradiscuss\local\transcript_proxy
 */
final class student_endpoint_test extends \advanced_testcase {
    /** @var string Mint key used by the tests. */
    private const MINTKEY = 'test-mint-key-not-a-secret';

    /** @var string A transcript id. */
    private const TID = '0f8e7d6c-5b4a-4321-8fed-cba987654321';

    /** @var string A token-shaped value returned by the scripted mint. */
    private const TOKEN = 'pre.token-value_123';

    /** @var scripted_transport Transport. */
    private scripted_transport $transport;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Quiz module record (with cmid). */
    private \stdClass $quiz;

    /** @var \stdClass Student. */
    private \stdClass $student;

    /**
     * A course with a quiz and an enrolled student; the plugin is enabled and configured.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_vektradiscuss');
        set_config('apiurl', 'https://vektra.example.test', 'block_vektra');
        set_config('apikey', 'test-admin-key-not-a-secret', 'block_vektra');
        set_config('mintkey', self::MINTKEY, 'local_vektradiscuss');
        set_config('uiurl', 'https://ui.example.test', 'local_vektradiscuss');

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->quiz = $gen->create_module('quiz', ['course' => $this->course->id]);
        $this->student = $gen->create_and_enrol($this->course, 'student');
        $this->transport = new scripted_transport();
    }

    /**
     * The endpoint under test.
     *
     * @return student_endpoint
     */
    private function endpoint(): student_endpoint {
        return new student_endpoint(null, new assess_client(null, $this->transport));
    }

    /**
     * Call the endpoint on a fresh page, as a request would (require_login() sets the page context once).
     *
     * @param string $action
     * @param int $cmid
     * @param int $attemptid
     * @param string $tid
     * @return array
     */
    private function handle(string $action, int $cmid, int $attemptid = 0, string $tid = ''): array {
        global $PAGE;
        $PAGE = new \moodle_page();
        return $this->endpoint()->handle($action, $cmid, $attemptid, $tid);
    }

    /**
     * Log in as the student with a valid sesskey in the request.
     */
    private function as_student(): void {
        $this->setUser($this->student);
        $_POST['sesskey'] = sesskey();
    }

    /**
     * Queue a successful mint.
     */
    private function respond_mint(): void {
        $this->transport->respond(201, ['token' => self::TOKEN, 'expires_in' => 120, 'jti' => 'j1']);
    }

    /**
     * Queue the ownership check of a download: a mint, then /state listing these transcripts.
     *
     * @param string[] $tids
     */
    private function respond_transcripts(array $tids): void {
        $this->respond_mint();
        $this->transport->respond(200, ['state' => 'closed', 'reason' => null, 'transcripts' => array_map(
            fn(string $tid, int $i): array => ['tid' => $tid, 'n' => $i + 1, 'available' => true, 'purged' => false],
            $tids,
            array_keys($tids)
        )]);
    }

    /**
     * Assert that nothing reached Vektra.
     */
    private function assert_no_vektra_call(): void {
        $this->assertSame([], $this->transport->calls);
    }

    /**
     * State: mint, then GET /state with the token as bearer; only the normalised state is returned.
     */
    public function test_state_mints_then_reads_state_with_the_token(): void {
        $this->as_student();
        $this->respond_mint();
        $this->transport->respond(200, ['state' => 'eligible', 'reason' => 'ok', 'session_id' => null]);

        $result = $this->handle('state', (int) $this->quiz->cmid);

        $this->assertSame('eligible', $result['state']);
        $this->assertCount(2, $this->transport->calls);
        $this->assertSame('POST', $this->transport->calls[0]['method']);
        $this->assertSame('https://vektra.example.test/api/v1/assess/tokens', $this->transport->calls[0]['url']);
        $this->assertSame('Bearer ' . self::MINTKEY, $this->transport->header_map(0)['authorization']);
        $this->assertSame('GET', $this->transport->calls[1]['method']);
        $this->assertSame('https://vektra.example.test/api/v1/assess/state', $this->transport->calls[1]['url']);
        $this->assertSame('Bearer ' . self::TOKEN, $this->transport->header_map(1)['authorization']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($result));
    }

    /**
     * The mint body carries the positive login-as assertion and the plugin version, always (B3).
     */
    public function test_mint_body_is_exact(): void {
        $this->as_student();
        $this->respond_mint();

        $result = $this->handle('mint', (int) $this->quiz->cmid);

        $body = json_decode($this->transport->calls[0]['body'], true);
        $this->assertSame([
            'sub' => (int) $this->student->id,
            'cmid' => (int) $this->quiz->cmid,
            'courseid' => (int) $this->course->id,
            'loginas' => false,
            'realuser_id' => null,
            'plugin_version' => (new config())->plugin_version(),
        ], $body);
        // Present, not just null-by-absence.
        $this->assertStringContainsString('"realuser_id":null', $this->transport->calls[0]['body']);
        $this->assertSame((new config())->plugin_version(), $this->transport->header_map(0)['x-vektra-plugin-version']);
        $this->assertSame(['state' => 'ready', 'token' => self::TOKEN, 'expiresin' => 120,
            'uiorigin' => 'https://ui.example.test'], $result);
    }

    /**
     * A 403 on mint (plugin too old, login-as refused, key not bound) is the generic unavailable state.
     */
    public function test_mint_refused_is_unavailable(): void {
        $this->as_student();
        $this->transport->respond(
            403,
            ['error' => ['code' => 'ERR-ASSESS-016', 'message' => 'plugin too old']],
            ['X-Request-ID' => 'req-1']
        );

        $result = $this->handle('mint', (int) $this->quiz->cmid);
        $this->assertDebuggingCalledCount(2);

        $this->assertSame(['state' => 'unavailable', 'reason' => null], $result);
        $this->assertCount(1, $this->transport->calls);
    }

    /**
     * Failure mapping (B5): every non-2xx, transport failure, malformed body or unknown state on /state
     * gives the generic state and no error code, request id or message.
     *
     * @dataProvider state_failures
     * @param int $status
     * @param mixed $body
     * @param int $debugging Expected debugging() calls.
     */
    public function test_state_failures_are_unavailable(int $status, $body, int $debugging): void {
        $this->as_student();
        $this->respond_mint();
        $this->transport->respond($status, $body, ['X-Request-ID' => 'req-42']);

        $result = $this->handle('state', (int) $this->quiz->cmid);
        if ($debugging) {
            $this->assertDebuggingCalledCount($debugging);
        }

        $this->assertSame('unavailable', $result['state']);
        $encoded = json_encode($result);
        $this->assertStringNotContainsString('ERR-', $encoded);
        $this->assertStringNotContainsString('req-42', $encoded);
        $this->assertStringNotContainsString('boom', $encoded);
    }

    /**
     * Failure cases of /state.
     *
     * @return array
     */
    public static function state_failures(): array {
        $envelope = ['error' => ['code' => 'ERR-ASSESS-012', 'message' => 'boom']];
        return [
            '500' => [500, $envelope, 2],
            '502' => [502, 'boom', 2],
            '429' => [429, $envelope, 2],
            'timeout' => [0, '', 2],
            'malformed json' => [200, '{boom', 0],
            'unknown state' => [200, ['state' => 'boom', 'reason' => 'x'], 0],
            'local state from vektra' => [200, ['state' => 'loginas'], 0],
        ];
    }

    /**
     * Login-as (B2, REQ-084): the local state, no Vektra request, no token.
     */
    public function test_loginas_makes_no_call_and_mints_nothing(): void {
        $this->setAdminUser();
        \core\session\manager::loginas($this->student->id, \context_course::instance($this->course->id));
        $_POST['sesskey'] = sesskey();

        foreach (['state', 'mint', 'download'] as $action) {
            $result = $this->handle($action, (int) $this->quiz->cmid, 0, self::TID);
            $this->assertSame(['state' => 'loginas', 'reason' => null], $result);
        }
        $this->assert_no_vektra_call();
        $this->assertSame(0, $this->transport->remaining());
    }

    /**
     * IDOR (B4): a student of course A asking about the activity of course B is refused by Moodle.
     */
    public function test_other_course_activity_is_refused_without_call(): void {
        $other = $this->getDataGenerator()->create_course();
        $foreign = $this->getDataGenerator()->create_module('quiz', ['course' => $other->id]);
        $this->as_student();

        try {
            $this->handle('state', (int) $foreign->cmid);
            $this->fail('A foreign activity must be refused.');
        } catch (\require_login_exception $e) {
            $this->assert_no_vektra_call();
        }
    }

    /**
     * Without the start capability, mint is refused; without both capabilities, state too.
     */
    public function test_missing_capability_is_refused_without_call(): void {
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_module::instance($this->quiz->cmid);
        assign_capability('local/vektradiscuss:start', CAP_PROHIBIT, $roleid, $context->id);
        assign_capability('local/vektradiscuss:viewowntranscript', CAP_PROHIBIT, $roleid, $context->id);
        role_assign($roleid, $this->student->id, $context->id);
        $this->as_student();

        foreach (['state', 'mint', 'download'] as $action) {
            try {
                $this->handle($action, (int) $this->quiz->cmid, 0, self::TID);
                $this->fail('Refusal expected for ' . $action);
            } catch (\required_capability_exception $e) {
                $this->assert_no_vektra_call();
            }
        }
    }

    /**
     * Download (the iframe's closing screen): a download token for the student's own transcript, minted with
     * the mint key and the student as sub, handed back with its tid; a refusal is the generic unavailable.
     */
    public function test_download_mints_for_the_owner(): void {
        $this->as_student();
        $this->respond_transcripts([self::TID]);
        $this->transport->respond(201, ['token' => self::TOKEN, 'expires_in' => 120]);
        $this->assertSame(
            ['state' => 'ready', 'tid' => self::TID, 'token' => self::TOKEN],
            $this->handle('download', (int) $this->quiz->cmid, 0, self::TID)
        );
        // The ownership check (mint, /state of this activity), then the download token.
        $this->assertStringEndsWith('/api/v1/assess/tokens', $this->transport->calls[0]['url']);
        $this->assertStringEndsWith('/api/v1/assess/state', $this->transport->calls[1]['url']);
        $path = '/api/v1/assess/transcripts/' . self::TID . '/download-token';
        $this->assertStringEndsWith($path, $this->transport->calls[2]['url']);
        $this->assertSame(['sub' => (int) $this->student->id], json_decode($this->transport->calls[2]['body'], true));

        $this->respond_transcripts([self::TID]);
        $this->transport->respond(403, ['error' => ['code' => 'ERR-AUTH-003', 'message' => 'not yours']]);
        $refused = $this->handle('download', (int) $this->quiz->cmid, 0, self::TID);
        $this->assertSame(['state' => 'unavailable', 'reason' => null], $refused);
        $this->assertDebuggingCalledCount(2);

        // A malformed tid never reaches Vektra.
        $calls = count($this->transport->calls);
        $this->assertSame(['state' => 'unavailable', 'reason' => null], $this->handle('download', (int) $this->quiz->cmid, 0, ''));
        $this->assertCount($calls, $this->transport->calls);
    }

    /**
     * Download needs only viewowntranscript: a student who lost the start capability keeps their copy.
     */
    public function test_download_needs_only_viewowntranscript(): void {
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_module::instance($this->quiz->cmid);
        assign_capability('local/vektradiscuss:start', CAP_PROHIBIT, $roleid, $context->id);
        role_assign($roleid, $this->student->id, $context->id);
        $this->as_student();

        $this->respond_transcripts([self::TID]);
        $this->transport->respond(201, ['token' => self::TOKEN, 'expires_in' => 120]);
        $this->assertSame('ready', $this->handle('download', (int) $this->quiz->cmid, 0, self::TID)['state']);
    }

    /**
     * A transcript of another activity is refused on this one: no download token is minted, so a student
     * prohibited from viewing transcripts on activity A cannot fetch A's transcript through activity B.
     */
    public function test_download_refuses_a_transcript_of_another_activity(): void {
        $this->as_student();
        $this->respond_transcripts(['a1b2c3d4-0000-4000-8000-000000000001']);

        $refused = $this->handle('download', (int) $this->quiz->cmid, 0, self::TID);
        $this->assertSame(['state' => 'unavailable', 'reason' => null], $refused);
        $this->assertCount(2, $this->transport->calls);
        foreach ($this->transport->calls as $call) {
            $this->assertStringNotContainsString('/download-token', $call['url']);
        }
        $this->assertSame(0, $this->transport->remaining());

        // A failed ownership check refuses too, and mints no download token either.
        $this->respond_mint();
        $this->transport->respond(503, ['error' => ['code' => 'ERR-ASSESS-001', 'message' => 'down']]);
        $refused = $this->handle('download', (int) $this->quiz->cmid, 0, self::TID);
        $this->assertSame(['state' => 'unavailable', 'reason' => null], $refused);
        $this->assertCount(4, $this->transport->calls);
        $this->assertDebuggingCalledCount(2);
    }

    /**
     * A missing or wrong sesskey is refused before any call.
     */
    public function test_missing_sesskey_is_refused_without_call(): void {
        $this->setUser($this->student);
        $_POST['sesskey'] = 'wrong';

        $this->expectException(\moodle_exception::class);
        try {
            $this->handle('mint', (int) $this->quiz->cmid);
        } finally {
            $this->assert_no_vektra_call();
        }
    }

    /**
     * An unknown action is refused before anything else.
     */
    public function test_unknown_action(): void {
        $this->as_student();
        $this->expectException(\invalid_parameter_exception::class);
        $this->handle('start', (int) $this->quiz->cmid);
    }

    /**
     * Kill switch off, an unsupported module, or no usable UI origin for mint: unavailable, no call.
     */
    public function test_disabled_unsupported_or_no_origin_makes_no_call(): void {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->as_student();
        $this->assertSame('unavailable', $this->handle('state', (int) $page->cmid)['state']);

        // An http origin is never usable (config::ui_origin() returns empty).
        set_config('uiurl', 'http://ui.example.test', 'local_vektradiscuss');
        $this->assertSame('unavailable', $this->handle('mint', (int) $this->quiz->cmid)['state']);

        set_config('enabled', 0, 'local_vektradiscuss');
        $this->assertSame('unavailable', $this->handle('state', (int) $this->quiz->cmid)['state']);
        $this->assert_no_vektra_call();
    }

    /**
     * A malformed mint response is never handed to the browser.
     */
    public function test_malformed_mint_response(): void {
        $this->as_student();
        $this->transport->respond(201, ['token' => "bad token\n", 'expires_in' => 120]);

        $result = $this->handle('mint', (int) $this->quiz->cmid);
        $this->assertDebuggingCalled();
        $this->assertSame('unavailable', $result['state']);
    }

    /**
     * State 9: the viewed attempt is not the selected one; the selected one is unaffected.
     */
    public function test_not_selected_attempt_pointer(): void {
        global $DB;
        $this->as_student();
        $base = ['quiz' => $this->quiz->id, 'userid' => $this->student->id, 'layout' => '', 'state' => 'finished',
            'timestart' => 1, 'timefinish' => 2, 'timemodified' => 2, 'currentpage' => 0, 'sumgrades' => 1, 'preview' => 0];
        $first = $DB->insert_record('quiz_attempts', $base + ['attempt' => 1, 'uniqueid' => 9001]);
        $second = $DB->insert_record('quiz_attempts', $base + ['attempt' => 2, 'uniqueid' => 9002]);
        $vektra = ['state' => 'eligible', 'reason' => 'ok', 'selected_attempt' => ['attempt_no' => 2]];

        $this->respond_mint();
        $this->transport->respond(200, $vektra);
        $result = $this->handle('state', (int) $this->quiz->cmid, $first);
        $this->assertSame(['state' => 'not_selected', 'reason' => null, 'selected_attempt_no' => 2], $result);

        $this->respond_mint();
        $this->transport->respond(200, $vektra);
        $this->assertSame('eligible', $this->handle('state', (int) $this->quiz->cmid, $second)['state']);

        // The transcripts of earlier sessions stay on the pointer.
        $transcripts = [['tid' => self::TID, 'n' => 1, 'available' => true, 'purged' => false]];
        $this->respond_mint();
        $this->transport->respond(200, $vektra + ['transcripts' => $transcripts]);
        $result = $this->handle('state', (int) $this->quiz->cmid, $first);
        $this->assertSame('not_selected', $result['state']);
        $this->assertSame($transcripts, $result['transcripts']);
    }
}
