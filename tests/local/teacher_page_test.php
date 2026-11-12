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
 * Tests for the teacher's "Discussions" page.
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
 * Tests for {@see teacher_page} and the teacher operations of {@see teacher_api}: exact request bodies,
 * Moodle refusals before any call, Vektra's refusals as messages, and the list rendered from user ids.
 *
 * @covers \local_vektradiscuss\local\teacher_page
 * @covers \local_vektradiscuss\local\teacher_api
 */
final class teacher_page_test extends \advanced_testcase {
    /** @var string A session id. */
    private const SID = '0f8e7d6c-5b4a-4321-8fed-cba987654321';

    /** @var scripted_transport Transport. */
    private scripted_transport $transport;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \cm_info Quiz. */
    private \cm_info $cm;

    /** @var \stdClass Editing teacher. */
    private \stdClass $teacher;

    /** @var \stdClass Student. */
    private \stdClass $student;

    /**
     * A course with a quiz, an editing teacher and a student; the plugin is enabled with a teacher key.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_vektradiscuss');
        set_config('apiurl', 'https://vektra.example.test', 'block_vektra');
        set_config('apikey', 'test-admin-key-not-a-secret', 'block_vektra');
        set_config('mintkey', 'test-mint-key-not-a-secret', 'local_vektradiscuss');
        set_config('teacherkey', 'test-teacher-key-not-a-secret', 'local_vektradiscuss');
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $quiz = $gen->create_module('quiz', ['course' => $this->course->id]);
        $this->cm = get_fast_modinfo($this->course)->get_cm($quiz->cmid);
        $this->teacher = $gen->create_and_enrol($this->course, 'editingteacher', ['firstname' => 'Tina', 'lastname' => 'Teacher']);
        $this->student = $gen->create_and_enrol($this->course, 'student', ['firstname' => 'Sam', 'lastname' => 'Student']);
        $this->setUser($this->teacher);
        $this->transport = new scripted_transport();
    }

    /**
     * The page under test.
     *
     * @return teacher_page
     */
    private function page(): teacher_page {
        return new teacher_page($this->cm, $this->course, new teacher_api(new assess_client(null, $this->transport)));
    }

    /**
     * The body of a recorded call.
     *
     * @param int $i
     * @return array
     */
    private function body(int $i = 0): array {
        return json_decode((string) $this->transport->calls[$i]['body'], true);
    }

    /**
     * Reset: the exact body (actor, course, type, reason, continuity) on the latest session's route.
     */
    public function test_reset_body(): void {
        $this->transport->respond(201, ['reset_id' => 'r1', 'student_userid' => (int) $this->student->id,
            'session_no_next' => 2, 'include_previous_turns' => false]);
        [$ok] = $this->page()->act('reset', ['sid' => self::SID, 'reason' => 'Connection lost', 'includeprevious' => 0]);
        $this->assertTrue($ok);
        $this->assertStringEndsWith(
            '/activities/' . $this->cm->id . '/sessions/' . self::SID . '/reset',
            $this->transport->calls[0]['url']
        );
        $this->assertSame([
            'actor_userid' => (int) $this->teacher->id,
            'courseid' => (int) $this->course->id,
            'activity_type' => 'quiz',
            'reason' => 'Connection lost',
            'include_previous_turns' => false,
        ], $this->body());
    }

    /**
     * Terminate: the confirmed count and the disable choice; a changed count is reported, nothing else sent.
     */
    public function test_terminate_body_and_count_changed(): void {
        $this->transport->respond(200, ['termination_id' => 't1', 'closed' => 2, 'rolled_back' => 0, 'disabled' => true]);
        [$ok, $message] = $this->page()->act('terminate', ['reason' => 'Exam over', 'expected' => 2, 'alsodisable' => 1]);
        $this->assertTrue($ok);
        $this->assertSame(get_string('teacher_terminate_done', 'local_vektradiscuss', 2), $message);
        $this->assertSame([
            'actor_userid' => (int) $this->teacher->id,
            'courseid' => (int) $this->course->id,
            'activity_type' => 'quiz',
            'reason' => 'Exam over',
            'expected_active_count' => 2,
            'also_disable' => true,
        ], $this->body());

        $this->transport->respond(409, ['error' => ['code' => 'ERR-ASSESS-025', 'message' => 'changed',
            'details' => ['current' => 3]]]);
        [$ok, $message] = $this->page()->act('terminate', ['reason' => 'Exam over', 'expected' => 2, 'alsodisable' => 0]);
        $this->assertDebuggingCalled();
        $this->assertFalse($ok);
        $this->assertSame(get_string('teacher_terminate_count_changed', 'local_vektradiscuss', 3), $message);
    }

    /**
     * Extra time: minutes or percent, never both; the confirmation travels; Vektra's field codes become text.
     */
    public function test_extra_time(): void {
        $this->transport->respond(200, ['student_userid' => (int) $this->student->id, 'extra_seconds' => 900,
            'duration_s' => 2700]);
        [$ok] = $this->page()->act('extratime', ['userid' => $this->student->id, 'unit' => 'percent', 'value' => '50',
            'confirm' => 0]);
        $this->assertTrue($ok);
        $this->assertStringEndsWith('/students/' . $this->student->id . '/extra-time', $this->transport->calls[0]['url']);
        $this->assertSame('PUT', $this->transport->calls[0]['method']);
        $body = $this->body();
        $this->assertNull($body['minutes']);
        $this->assertSame(50, $body['percent']);
        $this->assertFalse($body['confirm_over_100']);

        $this->transport->respond(400, ['error' => ['code' => 'ERR-ASSESS-022', 'message' => 'fields',
            'details' => ['fields' => [['field' => 'extra_time', 'code' => 'total_too_long']]]]]);
        [$ok, $message] = $this->page()->act('extratime', ['userid' => $this->student->id, 'unit' => 'minutes', 'value' => '300']);
        $this->assertDebuggingCalled();
        $this->assertFalse($ok);
        $this->assertSame(get_string('teacher_code_total_too_long', 'local_vektradiscuss'), $message);
    }

    /**
     * A missing reason, a bad value or a missing capability: refused before any request.
     */
    public function test_refusals_without_call(): void {
        $page = $this->page();
        $this->assertFalse($page->act('reset', ['sid' => self::SID, 'reason' => '  '])[0]);
        $this->assertFalse($page->act('terminate', ['reason' => '', 'expected' => 1])[0]);
        $this->assertFalse($page->act('reset', ['sid' => 'not-a-uuid', 'reason' => 'x'])[0]);
        $this->assertFalse($page->act('extratime', ['userid' => $this->student->id, 'value' => '-5'])[0]);
        $this->assertFalse($page->act('reset', ['sid' => self::SID, 'reason' => str_repeat('a', 1001)])[0]);
        $this->assertSame([], $this->transport->calls);

        $nonediting = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($nonediting);
        try {
            $page->act('terminate', ['reason' => 'x', 'expected' => 1]);
            $this->fail('A teacher without manage must be refused.');
        } catch (\required_capability_exception $e) {
            $this->assertSame([], $this->transport->calls);
        }
    }

    /**
     * Reset refusals (409 ERR-ASSESS-024) are explained by their reason.
     */
    public function test_reset_refusal_reasons(): void {
        $this->transport->respond(409, ['error' => ['code' => 'ERR-ASSESS-024', 'message' => 'no',
            'details' => ['reason' => 'gate_inputs_stale']]]);
        [, $message] = $this->page()->act('reset', ['sid' => self::SID, 'reason' => 'x']);
        $this->assertDebuggingCalled();
        $this->assertSame(get_string('teacher_reset_gate_inputs_stale', 'local_vektradiscuss'), $message);
    }

    /**
     * The list: GET with the actor headers and the cursor; names resolved in Moodle; reset offered after the
     * latest closed session only; an unreadable reason rendered as such; the override holder hidden from a
     * viewer who cannot manage overrides.
     */
    public function test_list_context(): void {
        $list = ['students' => [[
            'userid' => (int) $this->student->id,
            'sessions' => [
                ['session_id' => self::SID, 'session_no' => 1, 'state' => 'CLOSED', 'close_reason' => 'service_failure',
                    'committed_at' => null, 'closed_at' => '2026-11-07T09:00:00+00:00', 'transcript_id' => 't1',
                    'deliveries' => [['round' => 1, 'recipient_kind' => 'recipient', 'status' => 'SENT', 'attempts' => 1,
                        'smtp_code' => 250, 'error_class' => null, 'sent_at' => '2026-11-07T09:01:00+00:00']]],
            ],
            'resets' => [['reset_id' => 'r0', 'after_session_no' => 0, 'reason' => null, 'reason_state' => 'unreadable',
                'include_previous_turns' => false, 'granted_by' => (int) $this->teacher->id, 'granted_at' => null,
                'student_notice' => 'studente non avvisato via email']],
            'reset_count' => 1, 'extra_seconds' => 600,
        ]], 'next_cursor' => 'c2', 'terminations' => [],
            'gate' => ['opening' => ['at' => null, 'source' => 'unreadable', 'fallback_required' => true],
                'fallback_reason' => 'Vektra non può verificare proroghe/override per questa attività',
                'fallback_date_missing' => true, 'active_sessions' => 0, 'override_holder' => (int) $this->student->id]];
        $this->transport->respond(200, $list);
        $api = new teacher_api(new assess_client(null, $this->transport));
        $response = $api->list_sessions((int) $this->cm->id, (int) $this->course->id, 'quiz', (int) $this->teacher->id, 'c1');
        $this->assertSame('c1', $this->transport->header_map(0)['x-assess-cursor']);
        $this->assertSame((string) $this->teacher->id, $this->transport->header_map(0)['x-assess-actor']);

        $ctx = $this->page()->context($response, 'c1');
        $student = $ctx['students'][0];
        $this->assertSame('Sam Student', $student['name']);
        $this->assertTrue($student['canreset']);
        $this->assertSame(self::SID, $student['resetsid']);
        $this->assertTrue($student['continuitydefault']);
        $this->assertSame(get_string('teacher_reason_unreadable', 'local_vektradiscuss'), $student['resets'][0]['reason']);
        $this->assertSame('Tina Teacher', $student['resets'][0]['by']);
        $this->assertSame(get_string('teacher_state_closed', 'local_vektradiscuss'), $student['sessions'][0]['state']);
        $this->assertTrue($ctx['gate']['fallbackmissing']);
        $this->assertSame(get_string('form_opening_none', 'local_vektradiscuss'), $ctx['gate']['opening']);
        $this->assertFalse($ctx['canterminate']);
        $this->assertStringContainsString('cursor=c2', $ctx['nexturl']);
        // The editing teacher manages overrides: the holder's name is shown.
        $this->assertSame('Sam Student', $ctx['gate']['overrideholder']);

        // The template renders the context.
        $html = $GLOBALS['OUTPUT']->render_from_template('local_vektradiscuss/teacher_sessions', $ctx);
        $this->assertStringContainsString('Sam Student', $html);
        $this->assertStringContainsString('name="sid" value="' . self::SID . '"', $html);

        // A non-editing teacher: no actions, no override holder.
        $nonediting = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($nonediting);
        $this->transport->respond(200, $list);
        $response = $api->list_sessions((int) $this->cm->id, (int) $this->course->id, 'quiz', (int) $nonediting->id);
        $ctx = $this->page()->context($response);
        $this->assertFalse($ctx['canmanage']);
        $this->assertFalse($ctx['students'][0]['canreset']);
        $this->assertNull($ctx['gate']['overrideholder']);
    }

    /**
     * A failed list gives no context; a cursor outside the expected shape is dropped.
     */
    public function test_failed_list_and_cursor(): void {
        $this->transport->respond(0);
        $api = new teacher_api(new assess_client(null, $this->transport));
        $this->assertNull($this->page()->context($api->list_sessions((int) $this->cm->id, (int) $this->course->id, 'quiz', 1)));
        $this->assertDebuggingCalled();
        $this->assertSame('', teacher_page::clean_cursor("c1\r\nX-Evil: 1"));
        $this->assertSame('abc_1-2=', teacher_page::clean_cursor('abc_1-2='));
    }
}
