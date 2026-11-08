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
 * Tests for the state normalisation and its template context.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

/**
 * Tests for {@see state_dto} and {@see state_view}.
 *
 * @covers \local_vektradiscuss\local\state_dto
 * @covers \local_vektradiscuss\local\state_view
 */
final class state_dto_test extends \advanced_testcase {
    /**
     * Every Vektra render state maps to itself; starting is shown as active; anything else is unavailable.
     */
    public function test_state_mapping(): void {
        foreach (
            ['not_enabled', 'not_graded', 'not_open', 'eligible', 'active', 'closed', 'unavailable',
                'reset_granted', 'ended'] as $state
        ) {
            $this->assertSame($state, state_dto::from_vektra(['state' => $state, 'reason' => 'ok'])['state']);
        }
        $this->assertSame('active', state_dto::from_vektra(['state' => 'starting'])['state']);
        foreach (
            [null, 'x', [], ['state' => 3], ['state' => 'ELIGIBLE'], ['state' => 'loginas'],
                ['state' => 'not_selected'], ['state' => '<b>eligible</b>']] as $bad
        ) {
            $this->assertSame(['state' => 'unavailable', 'reason' => null], state_dto::from_vektra($bad));
        }
    }

    /**
     * Reason codes are kept only when code-shaped.
     */
    public function test_reason_is_a_code(): void {
        $dto = state_dto::from_vektra(['state' => 'not_open', 'reason' => 'close_not_passed']);
        $this->assertSame('close_not_passed', $dto['reason']);
        $this->assertNull(state_dto::from_vektra(['state' => 'not_open', 'reason' => 'Close <b>not</b> passed'])['reason']);
    }

    /**
     * The date is read only in state 3 and only when ISO-8601 with an offset.
     */
    public function test_opens_at(): void {
        $dto = state_dto::from_vektra(['state' => 'not_open', 'opens_at' => '2026-12-01T10:00:00+00:00']);
        $this->assertSame(gmmktime(10, 0, 0, 12, 1, 2026), $dto['opens_at']);
        $this->assertArrayNotHasKey('opens_at', state_dto::from_vektra(['state' => 'not_open', 'opens_at' => 'tomorrow']));
        // A calendar overflow would roll into March: refused, not normalised.
        $overflow = state_dto::from_vektra(['state' => 'not_open', 'opens_at' => '2026-02-30T10:00:00Z']);
        $this->assertArrayNotHasKey('opens_at', $overflow);
        $eligible = state_dto::from_vektra(['state' => 'eligible', 'opens_at' => '2026-12-01T10:00:00Z']);
        $this->assertArrayNotHasKey('opens_at', $eligible);
    }

    /**
     * The closing date (state 6) and the end date (state 11) are kept only in their own state and rendered.
     */
    public function test_closed_and_ended_dates(): void {
        $this->resetAfterTest();
        $iso = '2026-12-01T10:00:00Z';
        $closed = state_dto::from_vektra(['state' => 'closed', 'closed_at' => $iso, 'ended_at' => $iso]);
        $this->assertSame(gmmktime(10, 0, 0, 12, 1, 2026), $closed['closed_at']);
        $this->assertArrayNotHasKey('ended_at', $closed);
        $ended = state_dto::from_vektra(['state' => 'ended', 'ended_at' => $iso, 'closed_at' => $iso]);
        $this->assertSame(gmmktime(10, 0, 0, 12, 1, 2026), $ended['ended_at']);
        $this->assertArrayNotHasKey('closed_at', $ended);
        $this->assertArrayNotHasKey('closed_at', state_dto::from_vektra(['state' => 'closed', 'closed_at' => 'yesterday']));

        $date = userdate($ended['ended_at'], get_string('strftimedatetime', 'langconfig'));
        $this->assertSame(get_string('state_ended_date', 'local_vektradiscuss', $date), state_view::context($ended, 7)['message']);
        $this->assertSame(
            get_string('state_ended', 'local_vektradiscuss'),
            state_view::context(['state' => 'ended', 'reason' => null], 7)['message']
        );
    }

    /**
     * The full schema agreed with vektra-stack (plan 9/10 fields) is kept, typed and bounded.
     */
    public function test_full_schema(): void {
        $pre = [
            'duration_min' => 30,
            'recipients' => [['role' => 'editingteacher', 'name' => 'Mario Rossi'], ['role' => 'x', 'name' => "bad\nname"]],
            'course_material_used' => false,
            'limits' => ['max_message_chars' => 2000, 'messages_per_min' => '4'],
            'privacy' => ['notice_url' => 'https://vektra.example.test/privacy', 'notice_version' => '2026-11'],
            'previous_sessions' => 1,
            'files' => [['name' => 'relazione.pdf', 'predicted' => 'readable'], ['name' => 'x.doc', 'predicted' => 'maybe']],
            'free_text' => 'never rendered',
        ];
        $dto = state_dto::from_vektra(['state' => 'eligible', 'reason' => 'ok', 'session_no_next' => 2, 'pre_session' => $pre]);

        $this->assertSame(2, $dto['session_no_next']);
        $this->assertSame([
            'duration_min' => 30,
            'course_material_used' => false,
            'previous_sessions' => 1,
            'limits' => ['max_message_chars' => 2000],
            'recipients' => [['role' => 'editingteacher', 'name' => 'Mario Rossi']],
            'privacy' => ['notice_url' => 'https://vektra.example.test/privacy', 'notice_version' => '2026-11'],
            'files' => [['name' => 'relazione.pdf', 'predicted' => 'readable']],
        ], $dto['pre_session']);

        $this->assertArrayNotHasKey('pre_session', state_dto::from_vektra(['state' => 'closed', 'pre_session' => $pre]));
        $http = $pre;
        $http['privacy']['notice_url'] = 'http://vektra.example.test/privacy';
        $insecure = state_dto::from_vektra(['state' => 'eligible', 'pre_session' => $http]);
        $this->assertArrayNotHasKey('privacy', $insecure['pre_session']);

        $active = state_dto::from_vektra(['state' => 'starting', 'active' => ['session_no' => 1, 'minutes_left' => 37,
            'deadline_at' => '2026-12-01T10:00:00Z']]);
        $this->assertSame(['session_no' => 1, 'minutes_left' => 37], $active['active']);

        $closed = state_dto::from_vektra(['state' => 'closed', 'transcripts' => [
            ['tid' => 't-1', 'n' => 1, 'available' => true, 'purged' => false],
            ['tid' => '../x', 'n' => 2],
        ]]);
        $this->assertSame([['tid' => 't-1', 'n' => 1, 'available' => true, 'purged' => false]], $closed['transcripts']);

        // Any state carries the links of earlier sessions (plan 10 contract), e.g. a granted reset.
        $reset = state_dto::from_vektra(['state' => 'eligible', 'transcripts' => [
            ['tid' => '0f8e7d6c-5b4a-4321-8fed-cba987654321', 'n' => 1, 'available' => false, 'purged' => false],
        ]]);
        $this->assertSame(1, $reset['transcripts'][0]['n']);
        $this->assertFalse($reset['transcripts'][0]['available']);
    }

    /**
     * Transcript links: a sesskey-protected download link when available, a sentence otherwise (REQ-088).
     */
    public function test_transcript_links(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $ctx = state_view::context(state_dto::from_vektra(['state' => 'closed', 'transcripts' => [
            ['tid' => 't-1', 'n' => 1, 'available' => true, 'purged' => false],
            ['tid' => 't-2', 'n' => 2, 'available' => false, 'purged' => false],
            ['tid' => 't-3', 'n' => 3, 'available' => false, 'purged' => true],
        ]]), 7);
        $this->assertTrue($ctx['hastranscripts']);
        [$one, $two, $three] = $ctx['transcripts'];
        $url = new \moodle_url($one['url']);
        $this->assertStringEndsWith('/local/vektradiscuss/transcript.php', $url->get_path(false));
        $this->assertSame(['cmid' => '7', 'tid' => 't-1', 'n' => '1', 'sesskey' => sesskey()], $url->params());
        $this->assertSame(get_string('transcript_link', 'local_vektradiscuss', 1), $one['text']);
        $this->assertNull($two['url']);
        $this->assertSame(get_string('transcript_pending', 'local_vektradiscuss', 2), $two['text']);
        $this->assertNull($three['url']);
        $this->assertSame(get_string('transcript_purged', 'local_vektradiscuss', 3), $three['text']);

        $this->assertFalse(state_view::context(['state' => 'closed', 'reason' => null], 7)['hastranscripts']);
    }

    /**
     * The template context: texts from the language pack, enabled only for start and resume, the
     * pre-session screen with the fixed statements (REQ-068, REQ-093(a)).
     */
    public function test_context(): void {
        $this->resetAfterTest();
        $eligible = state_view::context(state_dto::from_vektra(['state' => 'eligible', 'pre_session' => [
            'duration_min' => 30, 'recipients' => [['role' => 'editingteacher', 'name' => 'Mario Rossi']],
        ]]), 7);
        $this->assertTrue($eligible['enabled']);
        $this->assertTrue($eligible['canstart']);
        $texts = array_column($eligible['presession']['items'], 'text');
        $this->assertContains(get_string('pre_enrolment', 'local_vektradiscuss'), $texts);
        $this->assertContains(get_string('pre_duration', 'local_vektradiscuss', 30), $texts);
        $this->assertSame('Mario Rossi', $eligible['presession']['recipients'][0]['name']);

        $closed = state_view::context(['state' => 'closed', 'reason' => 'already_discussed'], 7);
        $this->assertFalse($closed['enabled']);
        $this->assertTrue($closed['suggestcopy']);

        $notselected = state_view::context(['state' => 'not_selected', 'reason' => null, 'selected_attempt_no' => 2], 7);
        $this->assertSame(get_string('state_not_selected', 'local_vektradiscuss', 2), $notselected['message']);
        $this->assertSame(9, $notselected['statenumber']);

        $this->assertFalse(state_view::context(['state' => 'not_enabled', 'reason' => null], 7)['visible']);
        $this->assertSame(
            get_string('button_start_session', 'local_vektradiscuss', 3),
            state_view::context(['state' => 'reset_granted', 'reason' => null, 'session_no_next' => 3], 7)['buttonlabel']
        );
    }
}
