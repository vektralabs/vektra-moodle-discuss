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
 * Tests for the "Show" button's read of a saved text.
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
 * Tests for {@see config_reveal} and {@see teacher_api::reveal_config()}.
 *
 * @covers \local_vektradiscuss\local\config_reveal
 * @covers \local_vektradiscuss\local\teacher_api
 */
final class config_reveal_test extends \advanced_testcase {
    /** @var string A canary that must never be kept or logged. */
    private const CANARY = 'CANARY-revealed-text-9c1e';

    /** @var scripted_transport Transport. */
    private scripted_transport $transport;

    /** @var int[] Waits before a retry, in seconds. */
    private array $slept = [];

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \cm_info The assignment. */
    private \cm_info $cm;

    /** @var \stdClass Editing teacher. */
    private \stdClass $teacher;

    /**
     * A course, an assignment and its editing teacher; the plugin is enabled and has a teacher key.
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
        $assign = $gen->create_module('assign', ['course' => $this->course->id]);
        [, $this->cm] = get_course_and_cm_from_cmid($assign->cmid);
        $this->teacher = $gen->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($this->teacher);
        $this->transport = new scripted_transport();
    }

    /**
     * The class under test, on the scripted transport.
     *
     * @return config_reveal
     */
    private function reveal(): config_reveal {
        return new config_reveal(new teacher_api(new assess_client(null, $this->transport), function (int $seconds): void {
            $this->slept[] = $seconds;
        }));
    }

    /**
     * Vektra's error envelope.
     *
     * @param string $code
     * @param array $details
     * @return array
     */
    private static function envelope(string $code, array $details = []): array {
        return ['error' => ['code' => $code, 'message' => 'refused', 'details' => $details]];
    }

    /**
     * The read: POST to the activity's reveal route with the teacher key and the actor body; the text comes
     * back, and nothing is logged or turned into an event.
     */
    public function test_reveal_solution(): void {
        $sink = $this->redirectEvents();
        $this->transport->respond(200, ['field' => 'solution', 'value' => self::CANARY . "\n<b>a<b</b>"]);
        $result = $this->reveal()->reveal($this->cm, (int) $this->teacher->id, 'solution');

        $this->assertSame(['value' => self::CANARY . "\n<b>a<b</b>"], $result);
        $this->assertCount(1, $this->transport->calls);
        $call = $this->transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://vektra.example.test/api/v1/assess/activities/' . $this->cm->id . '/config/reveal', $call['url']);
        $this->assertSame([
            'actor_userid' => (int) $this->teacher->id,
            'courseid' => (int) $this->course->id,
            'activity_type' => 'assign',
            'field' => 'solution',
        ], json_decode($call['body'], true));
        $this->assertSame(teacher_api::FORM_TIMEOUT, $call['timeout']);
        $this->assertSame('Bearer test-teacher-key-not-a-secret', $this->transport->header_map(0)['authorization']);
        $this->assertDebuggingNotCalled();
        $this->assertSame(0, $sink->count());
        $sink->close();
    }

    /**
     * The form's names map to Vektra's; the recipients come as a list and are shown one per line.
     */
    public function test_reveal_rubric_and_recipients(): void {
        $this->transport->respond(200, ['field' => 'pasted_rubric', 'value' => 'R']);
        $this->transport->respond(200, ['field' => 'transcript_recipients', 'value' => ['a@example.test', 'b@example.test']]);
        $this->assertSame(['value' => 'R'], $this->reveal()->reveal($this->cm, (int) $this->teacher->id, 'rubric'));
        $this->assertSame(
            ['value' => "a@example.test\nb@example.test"],
            $this->reveal()->reveal($this->cm, (int) $this->teacher->id, 'recipients')
        );
        $this->assertSame('pasted_rubric', json_decode($this->transport->calls[0]['body'], true)['field']);
        $this->assertSame('transcript_recipients', json_decode($this->transport->calls[1]['body'], true)['field']);
    }

    /**
     * An answer without the documented shape is not shown.
     */
    public function test_malformed_answer_is_not_shown(): void {
        $unavailable = ['error' => get_string('form_reveal_unavailable', 'local_vektradiscuss')];
        $this->transport->respond(200, ['field' => 'pasted_rubric', 'value' => 'wrong field']);
        $this->transport->respond(200, ['field' => 'solution', 'value' => ['a']]);
        $this->transport->respond(200, ['field' => 'transcript_recipients', 'value' => ['a@example.test', 3]]);
        $this->transport->respond(200, ['field' => 'transcript_recipients', 'value' => ['x' => 'a@example.test']]);
        $actor = (int) $this->teacher->id;
        $this->assertSame($unavailable, $this->reveal()->reveal($this->cm, $actor, 'solution'));
        $this->assertSame($unavailable, $this->reveal()->reveal($this->cm, $actor, 'solution'));
        $this->assertSame($unavailable, $this->reveal()->reveal($this->cm, $actor, 'recipients'));
        $this->assertSame($unavailable, $this->reveal()->reveal($this->cm, $actor, 'recipients'));
    }

    /**
     * Vektra's refusals become sentences: removed meanwhile, unavailable (retryable), role refused, anything else.
     */
    public function test_refusals(): void {
        $s = fn(string $id): array => ['error' => get_string($id, 'local_vektradiscuss')];
        $actor = (int) $this->teacher->id;
        $cases = [
            [404, self::envelope('ERR-ASSESS-030', ['reason' => 'field_not_set', 'field' => 'solution']), 'form_reveal_notset'],
            [404, ['detail' => 'Not Found'], 'form_reveal_unavailable'],
            [503, self::envelope('ERR-ASSESS-031', ['reason' => 'reveal_unavailable', 'retry_after_s' => 2]), 'form_reveal_unavailable'],
            [0, '', 'form_reveal_unavailable'],
            [403, self::envelope('ERR-ASSESS-021', ['reason' => 'not_an_editor']), 'form_not_an_editor'],
            [400, self::envelope('ERR-ASSESS-014', ['reason' => 'field']), 'teacher_err_generic'],
        ];
        foreach ($cases as [$status, $body, $expected]) {
            $this->transport->respond($status, $body);
            $this->assertSame($s($expected), $this->reveal()->reveal($this->cm, $actor, 'solution'), (string) $status);
            $this->resetDebugging();
        }
        $this->assertSame([], $this->slept);
    }

    /**
     * A stale role check is retried once after Vektra's wait; a second stale answer asks the teacher to retry.
     */
    public function test_stale_role_check_is_retried_once(): void {
        $stale = self::envelope('ERR-ASSESS-021', ['reason' => 'editor_check_stale', 'retry_after_s' => 3]);
        $this->transport->respond(403, $stale);
        $this->transport->respond(200, ['field' => 'solution', 'value' => 'S']);
        $this->assertSame(['value' => 'S'], $this->reveal()->reveal($this->cm, (int) $this->teacher->id, 'solution'));
        $this->assertSame([3], $this->slept);
        $this->resetDebugging();

        $this->transport->respond(403, $stale);
        $this->transport->respond(403, $stale);
        $this->assertSame(
            ['error' => get_string('form_check_stale', 'local_vektradiscuss')],
            $this->reveal()->reveal($this->cm, (int) $this->teacher->id, 'solution')
        );
        $this->resetDebugging();
    }

    /**
     * Refused in Moodle before any request: an unknown field, a user who cannot edit the activity, "log in as",
     * a disabled plugin.
     */
    public function test_refused_without_call(): void {
        $actor = (int) $this->teacher->id;
        $this->assertArrayHasKey('error', $this->reveal()->reveal($this->cm, $actor, 'secret'));

        // A non-editing teacher sees the activity but cannot edit it.
        $grader = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($grader);
        $this->assertFalse(config_reveal::can_reveal(\context_module::instance($this->cm->id)));
        $this->assertArrayHasKey('error', $this->reveal()->reveal($this->cm, (int) $grader->id, 'solution'));

        // An editing teacher without the right to edit activities.
        $role = (int) $this->getDataGenerator()->create_role();
        $this->getDataGenerator()->role_assign($role, $this->teacher->id, \context_module::instance($this->cm->id)->id);
        assign_capability('moodle/course:manageactivities', CAP_PROHIBIT, $role, \context_module::instance($this->cm->id));
        $this->setUser($this->teacher);
        $this->assertFalse(config_reveal::can_reveal(\context_module::instance($this->cm->id)));
        unassign_capability('moodle/course:manageactivities', $role, \context_module::instance($this->cm->id));
        $this->assertTrue(config_reveal::can_reveal(\context_module::instance($this->cm->id)));

        $this->setAdminUser();
        \core\session\manager::loginas($this->teacher->id, \context_course::instance($this->course->id));
        $this->assertSame(
            ['error' => get_string('form_loginas', 'local_vektradiscuss')],
            $this->reveal()->reveal($this->cm, $actor, 'solution')
        );

        $this->setUser($this->teacher);
        set_config('enabled', 0, 'local_vektradiscuss');
        $this->assertSame(
            ['error' => get_string('form_not_configured', 'local_vektradiscuss')],
            $this->reveal()->reveal($this->cm, $actor, 'solution')
        );
        $this->assertSame([], $this->transport->calls);
    }

    /**
     * The API refuses a field outside the three readable ones (a programming error).
     */
    public function test_api_refuses_other_fields(): void {
        $this->expectException(\coding_exception::class);
        (new teacher_api(new assess_client(null, $this->transport)))->reveal_config(1, 1, 'quiz', 1, 'show_solution');
    }
}
