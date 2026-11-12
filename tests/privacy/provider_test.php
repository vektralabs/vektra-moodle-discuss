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
 * Tests for the privacy provider.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_vektradiscuss\local\http\assess_client;
use local_vektradiscuss\local\privacy_api;
use local_vektradiscuss\tests\scripted_transport;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/scripted_transport.php');

/**
 * Export writes Vektra's exact bytes after a hash check; deletion is scoped, retried while pending, verified,
 * and never reported as done when it was not (OQ-033).
 *
 * @covers \local_vektradiscuss\privacy\provider
 * @covers \local_vektradiscuss\local\privacy_api
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var string Transcript bytes. */
    private const BYTES = "<!DOCTYPE html>\n<p>Trascrizione, sessione 1: perché ∑</p>\n";

    /** @var scripted_transport Transport. */
    private scripted_transport $transport;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Quiz (with cmid). */
    private \stdClass $quiz;

    /** @var \stdClass Student. */
    private \stdClass $student;

    /**
     * Plugin enabled with export and erase keys; a quiz and a student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_vektradiscuss');
        set_config('siteid', 'site-1', 'local_vektradiscuss');
        set_config('apiurl', 'https://vektra.example.test', 'block_vektra');
        set_config('apikey', 'test-admin-key-not-a-secret', 'block_vektra');
        set_config('privacyexportkey', 'test-export-key', 'local_vektradiscuss');
        set_config('privacyerasekey', 'test-erase-key', 'local_vektradiscuss');
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->quiz = $gen->create_module('quiz', ['course' => $this->course->id]);
        $this->student = $gen->create_and_enrol($this->course, 'student');
        $this->transport = new scripted_transport();
        provider::$api = new privacy_api(null, new assess_client(null, $this->transport), 0);
    }

    /**
     * Reset the injected API.
     */
    public function tearDown(): void {
        provider::$api = null;
        parent::tearDown();
    }

    /**
     * A transcript item as Vektra exports it.
     *
     * @param int $cmid
     * @param string $bytes
     * @param string|null $sha256
     * @return array
     */
    private function item(int $cmid, string $bytes = self::BYTES, ?string $sha256 = null): array {
        return ['transcript_id' => 'aaaa-1', 'cmid' => $cmid, 'course_id' => (int) $this->course->id, 'session_no' => 1,
            'closed_at' => '2026-11-07T09:00:00+00:00',
            'filename' => 'C1_' . $cmid . '_' . $this->student->id . '_s1_20261107.html',
            'sha256' => $sha256 ?? hash('sha256', $bytes), 'content_base64' => base64_encode($bytes)];
    }

    /**
     * The approved context list of the student for the quiz.
     *
     * @return approved_contextlist
     */
    private function quiz_contextlist(): approved_contextlist {
        return new approved_contextlist(
            $this->student,
            'local_vektradiscuss',
            [\context_module::instance($this->quiz->cmid)->id]
        );
    }

    /**
     * Metadata: an external location, no Moodle table.
     */
    public function test_metadata(): void {
        $items = provider::get_metadata(new collection('local_vektradiscuss'))->get_collection();
        $this->assertCount(1, $items);
        $this->assertInstanceOf(\core_privacy\local\metadata\types\external_location::class, $items[0]);
    }

    /**
     * Contexts: the quiz, and the person's own context for an activity deleted from Moodle; read with the
     * export key across pages.
     */
    public function test_contexts_for_user(): void {
        $this->transport->respond(200, ['items' => [$this->item((int) $this->quiz->cmid)], 'next_cursor' => 'p2']);
        $this->transport->respond(200, ['items' => [$this->item(999999)], 'next_cursor' => null]);
        $ids = provider::get_contexts_for_userid((int) $this->student->id)->get_contextids();
        sort($ids);
        $expected = [\context_module::instance($this->quiz->cmid)->id, \context_user::instance($this->student->id)->id];
        sort($expected);
        $this->assertSame(array_map('intval', $expected), array_map('intval', $ids));
        $this->assertSame('Bearer test-export-key', $this->transport->header_map(0)['authorization']);
        $this->assertSame('p2', $this->transport->header_map(1)['x-assess-cursor']);
    }

    /**
     * Export: the exact bytes, as a file, with the metadata.
     */
    public function test_export_writes_the_exact_bytes(): void {
        $this->transport->respond(200, ['items' => [$this->item((int) $this->quiz->cmid)], 'next_cursor' => null]);
        provider::export_user_data($this->quiz_contextlist());
        $writer = writer::with_context(\context_module::instance($this->quiz->cmid));
        $subcontext = [get_string('privacy:path', 'local_vektradiscuss'),
            get_string('privacy:session', 'local_vektradiscuss', (object) ['cmid' => $this->quiz->cmid, 'n' => 1])];
        $this->assertTrue($writer->has_any_data());
        $files = $writer->get_custom_file($subcontext);
        $this->assertSame(hash('sha256', self::BYTES), hash('sha256', reset($files)));
    }

    /**
     * A transcript whose bytes do not match the reported hash is never exported.
     */
    public function test_export_refuses_a_hash_mismatch(): void {
        $this->transport->respond(200, ['items' => [$this->item((int) $this->quiz->cmid, self::BYTES, str_repeat('0', 64))],
            'next_cursor' => null]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('privacy_export_hash', 'local_vektradiscuss'));
        provider::export_user_data($this->quiz_contextlist());
    }

    /**
     * Deletion for a person in an activity: scoped to that activity, erase key, then verified.
     */
    public function test_delete_for_user_is_scoped_and_verified(): void {
        $this->transport->respond(200, ['items' => [$this->item((int) $this->quiz->cmid)], 'next_cursor' => null]);
        $this->transport->respond(200, ['outcome' => 'erased', 'counts' => ['sessions' => 1, 'transcripts' => 1]]);
        $this->transport->respond(200, ['cmid' => (int) $this->quiz->cmid, 'userids' => [], 'next_cursor' => null]);
        $this->transport->respond(200, ['items' => [], 'next_cursor' => null]);
        provider::delete_data_for_user($this->quiz_contextlist());
        $this->assertSame(0, $this->transport->remaining());
        $call = $this->transport->calls[1];
        $this->assertSame('DELETE', $call['method']);
        $this->assertStringEndsWith('/privacy/activities/' . $this->quiz->cmid . '/subjects', $call['url']);
        $this->assertSame(['userids' => [(int) $this->student->id]], json_decode($call['body'], true));
        $this->assertSame('Bearer test-erase-key', $this->transport->header_map(1)['authorization']);
    }

    /**
     * A verification that still finds a transcript of the activity throws.
     */
    public function test_delete_unverified_throws(): void {
        $this->transport->respond(200, ['items' => [$this->item((int) $this->quiz->cmid)], 'next_cursor' => null]);
        $this->transport->respond(200, ['outcome' => 'erased', 'counts' => []]);
        $this->transport->respond(200, ['cmid' => (int) $this->quiz->cmid, 'userids' => [(int) $this->student->id],
            'next_cursor' => null]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('privacy_erase_unverified', 'local_vektradiscuss'));
        provider::delete_data_for_user($this->quiz_contextlist());
    }

    /**
     * Pending is retried with the same request; then erased passes.
     */
    public function test_pending_is_retried(): void {
        $pending = ['error' => ['code' => 'ERR-ASSESS-027', 'message' => 'pending', 'details' => ['reason' => 'pending']]];
        $this->transport->respond(503, $pending)->respond(503, $pending);
        $this->transport->respond(200, ['outcome' => 'erased', 'counts' => []]);
        $this->transport->respond(200, ['userids' => [], 'next_cursor' => null]);
        provider::delete_data_for_all_users_in_context(\context_module::instance($this->quiz->cmid));
        $this->assertCount(4, $this->transport->calls);
        $this->assertNull($this->transport->calls[0]['body']);
        $this->assertDebuggingCalledCount(2);
    }

    /**
     * Pending after every attempt, failed, or retained: the request fails, never a silent success.
     */
    public function test_erase_failures_throw(): void {
        $pending = ['error' => ['code' => 'ERR-ASSESS-027', 'message' => 'pending', 'details' => ['reason' => 'pending']]];
        for ($i = 0; $i < privacy_api::ERASE_ATTEMPTS; $i++) {
            $this->transport->respond(503, $pending);
        }
        $this->transport->respond(200, ['outcome' => 'retained', 'legal_basis' => 'x', 'counts' => []]);
        $this->transport->respond(503, ['error' => ['code' => 'ERR-ASSESS-027', 'message' => 'failed',
            'details' => ['reason' => 'failed']]]);
        foreach (['privacy_erase_pending', 'privacy_erase_retained', 'privacy_erase_failed'] as $identifier) {
            try {
                provider::delete_data_for_all_users_in_context(\context_module::instance($this->quiz->cmid));
                $this->fail('Exception expected: ' . $identifier);
            } catch (\moodle_exception $e) {
                $this->assertSame($identifier, $e->errorcode);
            }
        }
        $this->assertSame(0, $this->transport->remaining());
        $this->assertDebuggingCalledCount(privacy_api::ERASE_ATTEMPTS + 1);
    }

    /**
     * Without the erase key the deletion throws before any request.
     */
    public function test_missing_erase_key_throws_without_delete(): void {
        set_config('privacyerasekey', '', 'local_vektradiscuss');
        $this->transport->respond(200, ['items' => [], 'next_cursor' => null]);
        try {
            provider::delete_data_for_user($this->quiz_contextlist());
            $this->fail('Exception expected');
        } catch (\moodle_exception $e) {
            $this->assertSame('privacy_key_missing', $e->errorcode);
        }
        $this->assertSame([], array_filter($this->transport->calls, fn(array $c): bool => $c['method'] === 'DELETE'));
    }

    /**
     * The user context approved and no existing activity left out: everything Vektra holds for the person is
     * erased (also the rows without a transcript), then verified.
     */
    public function test_user_context_erases_subject_wide(): void {
        $this->transport->respond(200, ['items' => [$this->item((int) $this->quiz->cmid), $this->item(999999)],
            'next_cursor' => null]);
        $this->transport->respond(200, ['outcome' => 'erased', 'counts' => []]);
        $this->transport->respond(200, ['cmid' => (int) $this->quiz->cmid, 'userids' => [], 'next_cursor' => null]);
        $this->transport->respond(200, ['items' => [], 'next_cursor' => null]);
        provider::delete_data_for_user(new approved_contextlist($this->student, 'local_vektradiscuss', [
            \context_module::instance($this->quiz->cmid)->id, \context_user::instance($this->student->id)->id]));
        $this->assertSame('DELETE', $this->transport->calls[1]['method']);
        $this->assertStringEndsWith('/privacy/subjects/' . $this->student->id, $this->transport->calls[1]['url']);
    }

    /**
     * The user context approved but an existing activity left out: only the deleted activities are erased
     * through the user context, never the activity that was not approved.
     */
    public function test_user_context_never_erases_an_unapproved_activity(): void {
        $this->transport->respond(200, ['items' => [$this->item((int) $this->quiz->cmid), $this->item(999999)],
            'next_cursor' => null]);
        $this->transport->respond(200, ['outcome' => 'erased', 'counts' => []]);
        $this->transport->respond(200, ['items' => [$this->item((int) $this->quiz->cmid)], 'next_cursor' => null]);
        provider::delete_data_for_user(new approved_contextlist($this->student, 'local_vektradiscuss', [
            \context_user::instance($this->student->id)->id]));
        $deletes = array_values(array_filter($this->transport->calls, fn(array $c): bool => $c['method'] === 'DELETE'));
        $this->assertCount(1, $deletes);
        $this->assertStringEndsWith('/privacy/activities/999999/subjects', $deletes[0]['url']);
    }

    /**
     * Privacy requests work with the plugin switched off: the data stays in Vektra.
     */
    public function test_works_with_the_kill_switch_off(): void {
        set_config('enabled', 0, 'local_vektradiscuss');
        $this->transport->respond(200, ['items' => [], 'next_cursor' => null]);
        provider::get_contexts_for_userid((int) $this->student->id);
        $this->assertCount(1, $this->transport->calls);
    }

    /**
     * A cursor the plugin cannot send back fails the read rather than ending the list early.
     */
    public function test_unreadable_cursor_throws(): void {
        $this->transport->respond(200, ['items' => [], 'next_cursor' => "bad\ncursor"]);
        $this->expectException(\moodle_exception::class);
        provider::get_contexts_for_userid((int) $this->student->id);
    }

    /**
     * A site that never set the plugin up holds nothing in Vektra: no contexts, no request.
     */
    public function test_unused_site_has_nothing(): void {
        foreach (['siteid', 'mintkey', 'teacherkey', 'privacyexportkey', 'privacyerasekey'] as $name) {
            set_config($name, '', 'local_vektradiscuss');
        }
        $this->assertSame([], provider::get_contexts_for_userid((int) $this->student->id)->get_contextids());
        $this->assertSame([], $this->transport->calls);
    }

    /**
     * Users in an activity, and deleting some of them (scoped userids, verified).
     */
    public function test_users_in_context_and_delete_for_users(): void {
        $context = \context_module::instance($this->quiz->cmid);
        $this->transport->respond(200, ['cmid' => (int) $this->quiz->cmid, 'userids' => [(int) $this->student->id],
            'next_cursor' => null]);
        $userlist = new userlist($context, 'local_vektradiscuss');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $this->student->id], array_map('intval', $userlist->get_userids()));

        $this->transport->respond(200, ['outcome' => 'erased', 'counts' => []]);
        $this->transport->respond(200, ['cmid' => (int) $this->quiz->cmid, 'userids' => [], 'next_cursor' => null]);
        provider::delete_data_for_users(new approved_userlist($context, 'local_vektradiscuss', [(int) $this->student->id]));
        $this->assertSame(['userids' => [(int) $this->student->id]], json_decode($this->transport->calls[1]['body'], true));
    }
}
