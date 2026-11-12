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
 * Tests for the discussion settings section of the activity form.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local\form;

use local_vektradiscuss\local\http\assess_client;
use local_vektradiscuss\local\teacher_api;
use local_vektradiscuss\tests\scripted_transport;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../../fixtures/scripted_transport.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Tests for {@see settings_section} and {@see teacher_api}: load before write, only changed fields, the
 * write-only texts, Vektra's refusals and the opening fallback.
 *
 * @covers \local_vektradiscuss\local\form\settings_section
 * @covers \local_vektradiscuss\local\teacher_api
 */
final class settings_section_test extends \advanced_testcase {
    /** @var string Teacher key used by the tests. */
    private const TEACHERKEY = 'test-teacher-key-not-a-secret';

    /** @var string A canary that must never be shown back or kept. */
    private const CANARY = 'CANARY-solution-text-7f3a';

    /** @var scripted_transport Transport. */
    private scripted_transport $transport;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Quiz record (with cmid). */
    private \stdClass $quiz;

    /** @var \stdClass Editing teacher. */
    private \stdClass $teacher;

    /**
     * A course, a quiz and its editing teacher; the plugin is enabled and has a teacher key.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_vektradiscuss');
        set_config('apiurl', 'https://vektra.example.test', 'block_vektra');
        set_config('apikey', 'test-admin-key-not-a-secret', 'block_vektra');
        set_config('mintkey', 'test-mint-key-not-a-secret', 'local_vektradiscuss');
        set_config('teacherkey', self::TEACHERKEY, 'local_vektradiscuss');
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->quiz = $gen->create_module('quiz', ['course' => $this->course->id]);
        $this->teacher = $gen->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($this->teacher);
        $this->transport = new scripted_transport();
    }

    /**
     * The API under test, on the scripted transport.
     *
     * @return teacher_api
     */
    private function api(): teacher_api {
        return new teacher_api(new assess_client(null, $this->transport));
    }

    /**
     * Vektra's configuration view.
     *
     * @param array $overrides
     * @return array
     */
    private static function view(array $overrides = []): array {
        return $overrides + [
            'cmid' => 1, 'activity_type' => 'quiz', 'version' => 3, 'configured' => true, 'enabled' => false,
            'cap_minutes' => 30, 'rubric_source' => 'none', 'show_solution' => false, 'rag_enabled' => false,
            'available_until' => null, 'solution_state' => 'impostata', 'pasted_rubric_state' => 'non impostata',
            'transcript_recipients_state' => 'impostata', 'transcript_recipients_count' => 1,
            'opening' => ['at' => '2026-11-06T22:34:16+00:00', 'source' => 'activity_close', 'fallback_required' => false],
            'warnings' => ['available_until_not_after_opening' => false, 'review_options_hide_content' => false],
            'active_sessions' => 0, 'updated_at' => null,
        ];
    }

    /**
     * Build the section on a form of the quiz (or of a new quiz) and return the form.
     *
     * @param bool $existing
     * @return \MoodleQuickForm
     */
    private function extend(bool $existing = true): \MoodleQuickForm {
        $cm = $existing ? get_coursemodule_from_id('quiz', $this->quiz->cmid, 0, false, MUST_EXIST) : null;
        $wrapper = $this->createMock(\moodleform_mod::class);
        $wrapper->method('get_current')->willReturn((object) ['modulename' => 'quiz']);
        $wrapper->method('get_coursemodule')->willReturn($cm);
        $wrapper->method('get_course')->willReturn($this->course);
        $mform = new \MoodleQuickForm('modform', 'post', '#');
        settings_section::extend($wrapper, $mform, $this->api());
        return $mform;
    }

    /**
     * Submitted values of an unchanged form, as add_fields() set them for {@see view()}.
     *
     * @param array $overrides
     * @return array
     */
    private function submitted(array $overrides = []): array {
        $p = settings_section::PREFIX;
        return $overrides + [
            $p . 'version' => 3,
            $p . 'orig' => json_encode(['enabled' => false, 'cap_minutes' => 30, 'rubric_source' => 'none',
                'show_solution' => false, 'available_until' => null, 'rubric_set' => false, 'recipients_count' => 1]),
            $p . 'enabled' => 0, $p . 'cap' => '30', $p . 'rubricsource' => 'none', $p . 'showsolution' => 0,
            $p . 'availableuntil' => 0, $p . 'solution_replace' => 0, $p . 'solution' => '', $p . 'solution_remove' => 0,
            $p . 'rubric_replace' => 0, $p . 'rubric' => '', $p . 'recipients_replace' => 0, $p . 'recipients' => '',
        ];
    }

    /**
     * Save a submission of the quiz form.
     *
     * @param array $values
     * @return \stdClass The module info returned by the save.
     */
    private function save(array $values): \stdClass {
        $data = (object) ($values + ['modulename' => 'quiz', 'coursemodule' => (int) $this->quiz->cmid]);
        return settings_section::save($data, $this->course, $this->api());
    }

    /**
     * Render: GET config with the actor, course and type headers and the teacher key; fields filled from it,
     * write-only fields empty, version and shown values kept in hidden fields.
     */
    public function test_render_reads_the_config(): void {
        $this->transport->respond(200, self::view(['enabled' => true, 'cap_minutes' => 45]));
        $mform = $this->extend();

        $call = $this->transport->calls[0];
        $this->assertSame('GET', $call['method']);
        $this->assertSame('https://vektra.example.test/api/v1/assess/activities/' . $this->quiz->cmid . '/config', $call['url']);
        $map = $this->transport->header_map(0);
        $this->assertSame('Bearer ' . self::TEACHERKEY, $map['authorization']);
        $this->assertSame((string) $this->teacher->id, $map['x-assess-actor']);
        $this->assertSame((string) $this->course->id, $map['x-assess-course']);
        $this->assertSame('quiz', $map['x-assess-activity-type']);

        $p = settings_section::PREFIX;
        $this->assertSame(3, $mform->getElement($p . 'version')->getValue());
        $this->assertSame(1, $mform->_defaultValues[$p . 'enabled']);
        $this->assertSame('45', $mform->_defaultValues[$p . 'cap']);
        $this->assertTrue($mform->elementExists($p . 'solution'));
        $this->assertTrue($mform->elementExists($p . 'solution_remove'));
        $this->assertFalse($mform->elementExists($p . 'rubric_remove'));
        $this->assertArrayNotHasKey($p . 'solution', $mform->_defaultValues);
        // RAG is only text: there is no element that could send it.
        $this->assertSame('static', $mform->getElement($p . 'rag')->getType());
        // No opening fallback unless Vektra asks for it.
        $this->assertFalse($mform->elementExists($p . 'openingat'));
    }

    /**
     * Outage on render: a notice, no version field, so the save sends nothing (dormant-capability guard).
     */
    public function test_unreachable_on_render_freezes_and_save_sends_nothing(): void {
        $this->transport->respond(0);
        $mform = $this->extend();
        $p = settings_section::PREFIX;
        $this->assertFalse($mform->elementExists($p . 'version'));
        $this->assertTrue($mform->elementExists($p . 'note'));
        $this->assertDebuggingCalled();

        // The same submission without the version (what the frozen form posts): no request at all.
        $values = $this->submitted([$p . 'enabled' => 1]);
        unset($values[$p . 'version']);
        $this->save($values);
        $this->assertCount(1, $this->transport->calls);
    }

    /**
     * A new activity: a notice, no call (nothing can be checked before the activity exists).
     */
    public function test_new_activity_makes_no_call(): void {
        $mform = $this->extend(false);
        $this->assertSame([], $this->transport->calls);
        $this->assertTrue($mform->elementExists(settings_section::PREFIX . 'note'));
        $this->assertFalse($mform->elementExists(settings_section::PREFIX . 'enabled'));
    }

    /**
     * Without the configure capability, no section and no call.
     */
    public function test_without_capability_nothing(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $mform = $this->extend();
        $this->assertFalse($mform->elementExists(settings_section::PREFIX . 'header'));
        $this->assertSame([], $this->transport->calls);
    }

    /**
     * Opening fallback: shown only when Vektra says fallback_required, the attestation never pre-ticked.
     */
    public function test_opening_fallback_shown_only_when_required(): void {
        $this->transport->respond(200, self::view(['opening' => ['at' => null, 'source' => 'unreadable', 'fallback_required' => true]]));
        $mform = $this->extend();
        $p = settings_section::PREFIX;
        $this->assertTrue($mform->elementExists($p . 'openingat'));
        $this->assertSame(0, $mform->_defaultValues[$p . 'attest']);

        // An unknown source is "no opening date", and no form without fallback_required.
        $this->transport->respond(200, self::view(['opening' => ['at' => '2026-11-06T22:34:16+00:00', 'source' => 'future_thing',
            'fallback_required' => false]]));
        $mform = $this->extend();
        $this->assertFalse($mform->elementExists($p . 'openingat'));
        $this->assertStringContainsString(get_string('form_opening_none', 'local_vektradiscuss'),
            $mform->getElement($p . 'opening')->toHtml());
    }

    /**
     * Unchanged form: no PUT.
     */
    public function test_unchanged_sends_nothing(): void {
        $this->save($this->submitted());
        $this->assertSame([], $this->transport->calls);
    }

    /**
     * A change: PUT with the actor, course, type, expected version and only the changed fields; the solution
     * key is absent when it is not replaced; RAG is never sent.
     */
    public function test_put_body_has_only_the_changes(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(200, self::view(['version' => 4, 'enabled' => true]));
        $this->save($this->submitted([$p . 'enabled' => 1, $p . 'cap' => '60']));

        $call = $this->transport->calls[0];
        $this->assertSame('PUT', $call['method']);
        $this->assertSame([
            'actor_userid' => (int) $this->teacher->id,
            'courseid' => (int) $this->course->id,
            'activity_type' => 'quiz',
            'expected_version' => 3,
            'enabled' => true,
            'cap_minutes' => 60,
        ], json_decode($call['body'], true));
        $this->assertStringNotContainsString('rag_enabled', $call['body']);
        $this->assertStringNotContainsString('solution', $call['body']);
    }

    /**
     * Write-only texts: replace sends the text, remove sends null; the text leaves the module info afterwards.
     */
    public function test_solution_replace_and_remove(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(200, self::view());
        $out = $this->save($this->submitted([$p . 'solution_replace' => 1, $p . 'solution' => self::CANARY]));
        $this->assertSame(self::CANARY, json_decode($this->transport->calls[0]['body'], true)['solution']);
        $this->assertFalse(property_exists($out, $p . 'solution'));
        $this->assertStringNotContainsString(self::CANARY, json_encode($out));

        $this->transport->respond(200, self::view());
        $this->save($this->submitted([$p . 'solution_remove' => 1]));
        $body = json_decode($this->transport->calls[1]['body'], true);
        $this->assertArrayHasKey('solution', $body);
        $this->assertNull($body['solution']);
    }

    /**
     * Recipients: sent as a list only when replaced.
     */
    public function test_recipients_replace(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(200, self::view());
        $this->save($this->submitted([$p . 'recipients_replace' => 1, $p . 'recipients' => "a@example.test\nb@example.test"]));
        $this->assertSame(['a@example.test', 'b@example.test'],
            json_decode($this->transport->calls[0]['body'], true)['transcript_recipients']);
    }

    /**
     * Vektra's field errors become a notification naming each field; the text still leaves the module info.
     */
    public function test_field_errors_are_notified(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(400, ['error' => ['code' => 'ERR-ASSESS-022', 'message' => 'fields', 'details' => ['fields' => [
            ['field' => 'enabled', 'code' => 'quiz_without_close_date'],
            ['field' => 'transcript_recipients', 'code' => 'domain_not_allowed', 'index' => 0, 'allowed_domains' => ['example.test']],
        ]]]]);
        $out = $this->save($this->submitted([$p . 'enabled' => 1, $p . 'solution_replace' => 1, $p . 'solution' => self::CANARY]));
        $this->assertDebuggingCalled();
        $this->assertFalse(property_exists($out, $p . 'solution'));

        $messages = \core\notification::fetch();
        $this->assertCount(1, $messages);
        $text = $messages[0]->get_message();
        $this->assertStringContainsString(get_string('form_field_enabled', 'local_vektradiscuss'), $text);
        $this->assertStringContainsString('example.test', $text);
        $this->assertStringNotContainsString(self::CANARY, $text);
    }

    /**
     * Version conflict: a notification asking to reload; nothing else sent.
     */
    public function test_conflict_is_notified(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(409, ['error' => ['code' => 'ERR-ASSESS-023', 'message' => 'conflict',
            'details' => ['current_version' => 4]]]);
        $this->save($this->submitted([$p . 'enabled' => 1]));
        $this->assertDebuggingCalled();
        $this->assertCount(1, $this->transport->calls);
        $this->assertSame(get_string('form_err_conflict', 'local_vektradiscuss'), \core\notification::fetch()[0]->get_message());
    }

    /**
     * Stale editor check: one retry, then success.
     */
    public function test_stale_editor_check_is_retried_once(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(403, ['error' => ['code' => 'ERR-ASSESS-021', 'message' => 'stale',
            'details' => ['reason' => 'editor_check_stale']]]);
        $this->transport->respond(200, self::view(['version' => 4]));
        $this->save($this->submitted([$p . 'enabled' => 1]));
        $this->assertDebuggingCalled();
        $this->assertCount(2, $this->transport->calls);
        $this->assertSame(get_string('form_saved', 'local_vektradiscuss'), \core\notification::fetch()[0]->get_message());
    }

    /**
     * The opening fallback goes to its own route, with the attestation, before the configuration.
     */
    public function test_opening_fallback_is_put_with_the_attestation(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(200, ['opening_at' => '2026-11-07T10:00:00+00:00', 'previous' => null, 'version' => 4]);
        $this->save($this->submitted([$p . 'openingat' => 1794045600, $p . 'attest' => 1]));

        $call = $this->transport->calls[0];
        $this->assertStringEndsWith('/activities/' . $this->quiz->cmid . '/opening-fallback', $call['url']);
        $this->assertSame([
            'actor_userid' => (int) $this->teacher->id,
            'courseid' => (int) $this->course->id,
            'activity_type' => 'quiz',
            'opening_at' => '2026-11-07T10:00:00Z',
            'attestation' => true,
            'attestation_text_version' => teacher_api::ATTESTATION_TEXT_VERSION,
        ], json_decode($call['body'], true));
        $this->assertCount(1, $this->transport->calls);
    }

    /**
     * Local checks mirror BR-013 and name the range.
     */
    public function test_validation_mirror(): void {
        $p = settings_section::PREFIX;
        $this->assertSame([], settings_section::validate($this->submitted()));
        foreach (['4', '121', 'abc', '30.5'] as $cap) {
            $this->assertArrayHasKey($p . 'cap', settings_section::validate($this->submitted([$p . 'cap' => $cap])));
        }
        foreach (['5', '120', ''] as $cap) {
            $this->assertArrayNotHasKey($p . 'cap', settings_section::validate($this->submitted([$p . 'cap' => $cap])));
        }
        $ok = str_repeat('x', 20000);
        $this->assertSame([], settings_section::validate($this->submitted([$p . 'solution_replace' => 1, $p . 'solution' => $ok])));
        $errors = settings_section::validate($this->submitted([$p . 'solution_replace' => 1, $p . 'solution' => $ok . 'y']));
        $this->assertArrayHasKey($p . 'solution', $errors);
        $this->assertArrayHasKey($p . 'solution', settings_section::validate($this->submitted([$p . 'solution_replace' => 1])));
        $this->assertArrayHasKey($p . 'rubricsource', settings_section::validate($this->submitted([$p . 'rubricsource' => 'pasted'])));
        $errors = settings_section::validate($this->submitted([$p . 'recipients_replace' => 1, $p . 'recipients' => "a@x.test\nb@x.test\nc@x.test\nd@x.test"]));
        $this->assertArrayHasKey($p . 'recipients', $errors);
        $this->assertArrayHasKey($p . 'attest', settings_section::validate($this->submitted([$p . 'openingat' => 1794045600])));
    }

    /**
     * Length as Vektra counts it: NFC code points, control characters other than newline and tab removed.
     */
    public function test_text_length(): void {
        $this->assertSame(3, settings_section::text_length("e\u{301}\n\t"));
        $this->assertSame(2, settings_section::text_length("a\x07\x00b"));
        $this->assertSame(1, settings_section::text_length('∑'));
    }
}
