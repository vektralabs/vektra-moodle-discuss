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
 * Tests for the gate refresh and the one retry of the teacher calls.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

use local_vektradiscuss\local\http\assess_client;
use local_vektradiscuss\local\http\assess_response;
use local_vektradiscuss\tests\scripted_transport;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/scripted_transport.php');

/**
 * Tests for {@see teacher_api::refresh_gate()}, {@see teacher_api::retry_after()} and {@see teacher_api::retry_once()}.
 *
 * @covers \local_vektradiscuss\local\teacher_api
 */
final class teacher_api_test extends \advanced_testcase {
    /** @var scripted_transport Transport. */
    private scripted_transport $transport;

    /** @var int[] Waits before a retry, in seconds. */
    private array $slept = [];

    /**
     * The plugin is enabled with a teacher key.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_vektradiscuss');
        set_config('apiurl', 'https://vektra.example.test', 'block_vektra');
        set_config('apikey', 'test-admin-key-not-a-secret', 'block_vektra');
        set_config('mintkey', 'test-mint-key-not-a-secret', 'local_vektradiscuss');
        set_config('teacherkey', 'test-teacher-key-not-a-secret', 'local_vektradiscuss');
        $this->transport = new scripted_transport();
    }

    /**
     * The API under test, on the scripted transport, with a wait that only records its length.
     *
     * @return teacher_api
     */
    private function api(): teacher_api {
        return new teacher_api(new assess_client(null, $this->transport), function (int $seconds): void {
            $this->slept[] = $seconds;
        });
    }

    /**
     * An error response with the given details.
     *
     * @param int $status
     * @param string $code
     * @param array|null $details
     * @return assess_response
     */
    private static function refusal(int $status, string $code, ?array $details): assess_response {
        return new assess_response($status, null, $code, 'refused', $details);
    }

    /**
     * The refresh: POST to the activity's gate-refresh route with the teacher key, the strict teacher body and
     * a short timeout.
     */
    public function test_refresh_gate_request(): void {
        $this->transport->respond(202, ['cmid' => 42, 'queued' => true, 'refreshed_at' => null]);
        $this->api()->refresh_gate(42, 7, 'assign', 5);

        $this->assertCount(1, $this->transport->calls);
        $call = $this->transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://vektra.example.test/api/v1/assess/activities/42/gate-refresh', $call['url']);
        $this->assertSame(['actor_userid' => 5, 'courseid' => 7, 'activity_type' => 'assign'], json_decode($call['body'], true));
        $this->assertSame(teacher_api::REFRESH_TIMEOUT, $call['timeout']);
        $this->assertSame('Bearer test-teacher-key-not-a-secret', $this->transport->header_map(0)['authorization']);
    }

    /**
     * A failed refresh is ignored: no exception, no retry, nothing waited, nothing logged (a download may be
     * streaming).
     */
    public function test_refresh_gate_failure_is_ignored(): void {
        $this->transport->respond(0);
        $this->api()->refresh_gate(42, 7, 'quiz', 5);
        $this->transport->respond(403, ['error' => ['code' => 'ERR-ASSESS-007', 'message' => 'no',
            'details' => ['retry_after_s' => 3]]]);
        $this->api()->refresh_gate(42, 7, 'quiz', 5);
        $this->assertDebuggingNotCalled();
        $this->assertCount(2, $this->transport->calls);
        $this->assertSame([], $this->slept);
    }

    /**
     * The wait comes only from a valid hint, or from a stale role check without one; anything else is final.
     */
    public function test_retry_after(): void {
        $this->assertSame(3, teacher_api::retry_after(self::refusal(409, 'ERR-ASSESS-024', ['retry_after_s' => 3])));
        $this->assertSame(5, teacher_api::retry_after(self::refusal(400, 'ERR-ASSESS-022', ['retry_after_s' => 5])));
        // Generic: the field decides, not the code.
        $this->assertSame(1, teacher_api::retry_after(self::refusal(400, 'ERR-ASSESS-099', ['retry_after_s' => 1])));
        foreach ([0, 6, 600, '3', 3.0, null, true] as $invalid) {
            $this->assertNull(teacher_api::retry_after(self::refusal(409, 'ERR-ASSESS-024', ['retry_after_s' => $invalid])));
        }
        // A rate limit or a server error is never a refresh in progress.
        $this->assertNull(teacher_api::retry_after(self::refusal(429, 'ERR-ASSESS-030', ['retry_after_s' => 3])));
        $this->assertNull(teacher_api::retry_after(self::refusal(503, 'ERR-ASSESS-099', ['retry_after_s' => 3])));
        $this->assertNull(teacher_api::retry_after(self::refusal(409, 'ERR-ASSESS-024', ['reason' => 'gate_inputs_stale'])));
        $this->assertNull(teacher_api::retry_after(self::refusal(409, 'ERR-ASSESS-024', null)));
        $this->assertNull(teacher_api::retry_after(new assess_response(200, ['retry_after_s' => 3])));
        $this->assertNull(teacher_api::retry_after(assess_response::failure('transport', 'timeout')));
        // A Vektra without the hint: a stale role check keeps the legacy wait, a refused role is final.
        $stale = self::refusal(403, 'ERR-ASSESS-021', ['reason' => 'editor_check_stale']);
        $this->assertSame(teacher_api::LEGACY_RETRY_AFTER, teacher_api::retry_after($stale));
        $this->assertNull(teacher_api::retry_after(self::refusal(403, 'ERR-ASSESS-021', ['reason' => 'not_an_editor'])));
        // A stale role check with an unusable hint still gets the legacy wait.
        $badhint = self::refusal(403, 'ERR-ASSESS-021', ['reason' => 'editor_check_stale', 'retry_after_s' => 6]);
        $this->assertSame(teacher_api::LEGACY_RETRY_AFTER, teacher_api::retry_after($badhint));
        // An explicit hint wins, also over the legacy wait.
        $hinted = self::refusal(403, 'ERR-ASSESS-021', ['reason' => 'editor_check_stale', 'retry_after_s' => 4]);
        $this->assertSame(4, teacher_api::retry_after($hinted));
    }

    /**
     * One retry at most: the second answer is returned even when it carries the hint again.
     */
    public function test_retry_once(): void {
        $hinted = self::refusal(409, 'ERR-ASSESS-024', ['retry_after_s' => 3]);
        $answers = [$hinted, $hinted, new assess_response(200, [])];
        $calls = 0;
        $slept = [];
        $response = teacher_api::retry_once(function () use (&$answers, &$calls): assess_response {
            $calls++;
            return array_shift($answers);
        }, function (int $seconds) use (&$slept): void {
            $slept[] = $seconds;
        });
        $this->assertSame($hinted, $response);
        $this->assertSame(2, $calls);
        $this->assertSame([3], $slept);

        $calls = 0;
        $final = self::refusal(409, 'ERR-ASSESS-024', ['reason' => 'not_closed']);
        $response = teacher_api::retry_once(function () use ($final, &$calls): assess_response {
            $calls++;
            return $final;
        }, function (): void {
            $this->fail('A final refusal is not retried.');
        });
        $this->assertSame($final, $response);
        $this->assertSame(1, $calls);
    }

    /**
     * The configuration GET is retried with the same headers after the hinted wait.
     */
    public function test_get_config_retried_with_the_same_request(): void {
        $this->transport->respond(400, ['error' => ['code' => 'ERR-ASSESS-022', 'message' => 'stale',
            'details' => ['fields' => [['field' => 'enabled', 'code' => 'lms_facts_unreadable']], 'retry_after_s' => 3]]]);
        $this->transport->respond(200, ['version' => 1]);
        $response = $this->api()->get_config(42, 7, 'quiz', 5);
        $this->assertDebuggingCalled();
        $this->assertTrue($response->ok());
        $this->assertSame([3], $this->slept);
        $this->assertSame($this->transport->header_map(0), $this->transport->header_map(1));
        $this->assertSame(teacher_api::FORM_TIMEOUT, $this->transport->calls[1]['timeout']);
    }

    /**
     * The session list (Discussions page) is retried too when the teacher check was stale: the 403 with
     * Vektra's hint, then the same GET with the same cursor and headers.
     */
    public function test_list_sessions_retried_on_stale_grader_check(): void {
        $this->transport->respond(403, ['error' => ['code' => 'ERR-ASSESS-021', 'message' => 'stale',
            'details' => ['reason' => 'grader_check_stale', 'retry_after_s' => 3]]]);
        $this->transport->respond(200, ['students' => [], 'next_cursor' => null, 'terminations' => [], 'gate' => []]);
        $response = $this->api()->list_sessions(42, 7, 'quiz', 5, 'c1');
        $this->assertDebuggingCalled();
        $this->assertTrue($response->ok());
        $this->assertSame([3], $this->slept);
        $this->assertCount(2, $this->transport->calls);
        $this->assertSame($this->transport->header_map(0), $this->transport->header_map(1));
        $this->assertSame('c1', $this->transport->header_map(1)['x-assess-cursor']);
    }
}
