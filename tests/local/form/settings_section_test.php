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

    /** @var int[] Waits before a retry, in seconds. */
    private array $slept = [];

    /** @var int[] Activities whose gate refresh was asked for. */
    private array $refreshes = [];

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
        $sleep = function (int $seconds): void {
            $this->slept[] = $seconds;
        };
        $record = function (int $cmid): void {
            $this->refreshes[] = $cmid;
        };
        // The gate refresh is recorded, not sent: its request is tested in teacher_api_test, and the scripted
        // responses of these tests stay in the order of the configuration calls.
        return new class (new assess_client(null, $this->transport), $sleep, $record) extends teacher_api {
            /** @var callable Records a refresh. */
            private $record;

            /**
             * Constructor.
             *
             * @param assess_client $client
             * @param callable $sleep
             * @param callable $record
             */
            public function __construct(assess_client $client, callable $sleep, callable $record) {
                parent::__construct($client, $sleep);
                $this->record = $record;
            }

            /**
             * Record the refresh.
             *
             * @param int $cmid
             * @param int $courseid
             * @param string $type
             * @param int $actorid
             */
            public function refresh_gate(int $cmid, int $courseid, string $type, int $actorid): void {
                ($this->record)($cmid);
            }
        };
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
            $p . 'view' => json_encode(self::view()),
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
        // Course material search: a switch, off unless Vektra says it is on.
        $this->assertSame('advcheckbox', $mform->getElement($p . 'rag')->getType());
        $this->assertSame(0, $mform->_defaultValues[$p . 'rag']);
        // No opening fallback unless Vektra asks for it.
        $this->assertFalse($mform->elementExists($p . 'openingat'));
    }

    /**
     * The review warning follows the quiz's own review options; Vektra's flag (reduced quiz detail) gives the
     * note on Moodle's feedback instead.
     */
    public function test_review_warning_and_feedback_note(): void {
        global $DB;
        $p = settings_section::PREFIX;
        // The generator's quiz has no close date and shows the attempt later while open: no review warning, and no
        // note without the flag.
        $this->transport->respond(200, self::view());
        $mform = $this->extend();
        $this->assertFalse($mform->elementExists($p . 'reviewwarning'));
        $this->assertFalse($mform->elementExists($p . 'quizfeedback'));

        $this->transport->respond(200, self::view(['warnings' => ['available_until_not_after_opening' => false,
            'review_options_hide_content' => true]]));
        $mform = $this->extend();
        $this->assertFalse($mform->elementExists($p . 'reviewwarning'));
        $this->assertStringContainsString(
            get_string('form_note_quiz_feedback', 'local_vektradiscuss'),
            $mform->getElement($p . 'quizfeedback')->toHtml()
        );

        $DB->set_field('quiz', 'reviewattempt', 0, ['id' => $this->quiz->id]);
        $this->transport->respond(200, self::view());
        $mform = $this->extend();
        $this->assertStringContainsString(
            get_string('form_warning_review_options', 'local_vektradiscuss'),
            $mform->getElement($p . 'reviewwarning')->toHtml()
        );
        $this->assertFalse($mform->elementExists($p . 'quizfeedback'));
    }

    /**
     * Assignments never get the quiz-feedback note, whatever Vektra's flag says.
     */
    public function test_no_quiz_note_on_assignments(): void {
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('assign', $assign->cmid, 0, false, MUST_EXIST);
        $wrapper = $this->createMock(\moodleform_mod::class);
        $wrapper->method('get_current')->willReturn((object) ['modulename' => 'assign']);
        $wrapper->method('get_coursemodule')->willReturn($cm);
        $wrapper->method('get_course')->willReturn($this->course);
        $this->transport->respond(200, self::view(['activity_type' => 'assign', 'rubric_source' => 'none',
            'warnings' => ['available_until_not_after_opening' => false, 'review_options_hide_content' => true]]));
        $mform = new \MoodleQuickForm('modform', 'post', '#');
        settings_section::extend($wrapper, $mform, $this->api());
        $this->assertTrue($mform->elementExists(settings_section::PREFIX . 'cap'));
        $this->assertFalse($mform->elementExists(settings_section::PREFIX . 'quizfeedback'));
        $this->assertFalse($mform->elementExists(settings_section::PREFIX . 'reviewwarning'));
    }

    /**
     * The phase that counts: after the close when the quiz has one, later while open when it has none.
     */
    public function test_review_hides_answers_by_phase(): void {
        global $DB;
        $later = \mod_quiz\question\display_options::LATER_WHILE_OPEN;
        $closed = \mod_quiz\question\display_options::AFTER_CLOSE;
        $DB->update_record('quiz', (object) ['id' => $this->quiz->id, 'timeclose' => 0, 'reviewattempt' => $later]);
        $this->assertFalse(settings_section::review_hides_answers((int) $this->quiz->id));
        $DB->set_field('quiz', 'timeclose', time() - 60, ['id' => $this->quiz->id]);
        $this->assertTrue(settings_section::review_hides_answers((int) $this->quiz->id));
        $DB->set_field('quiz', 'reviewattempt', $closed, ['id' => $this->quiz->id]);
        $this->assertFalse(settings_section::review_hides_answers((int) $this->quiz->id));
        $this->assertFalse(settings_section::review_hides_answers(-1));
    }

    /**
     * REQ-077: a warning under "show the solution" while it is on, and after a save that turns it on.
     */
    public function test_show_solution_warning(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(200, self::view());
        $mform = $this->extend();
        $this->assertStringContainsString(
            get_string('form_showsolution_warning', 'local_vektradiscuss'),
            $mform->getElement($p . 'showsolutionwarning')->toHtml()
        );
        $hideifs = (fn() => $this->_hideifs)->call($mform);
        $this->assertContains($p . 'showsolutionwarning', $hideifs[$p . 'showsolution']['notchecked']['1']);

        $this->transport->respond(200, self::view(['version' => 4, 'show_solution' => true]));
        $this->save($this->submitted([$p . 'showsolution' => 1]));
        $messages = array_map(fn($m) => $m->get_message(), \core\notification::fetch());
        $this->assertSame([get_string('form_saved', 'local_vektradiscuss'),
            get_string('form_showsolution_warning', 'local_vektradiscuss')], $messages);

        // A save that leaves it on, or turns it off, says nothing more.
        $on = self::view(['show_solution' => true]);
        $this->transport->respond(200, self::view(['version' => 5, 'show_solution' => true]));
        $this->save($this->submitted([$p . 'view' => json_encode($on), $p . 'showsolution' => 1, $p . 'cap' => '45']));
        $messages = array_map(fn($m) => $m->get_message(), \core\notification::fetch());
        $this->assertSame([get_string('form_saved', 'local_vektradiscuss')], $messages);
        $this->transport->respond(200, self::view(['version' => 6]));
        $this->save($this->submitted([$p . 'view' => json_encode($on), $p . 'showsolution' => 0]));
        $messages = array_map(fn($m) => $m->get_message(), \core\notification::fetch());
        $this->assertSame([get_string('form_saved', 'local_vektradiscuss')], $messages);
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
        $this->transport->respond(200, self::view(['opening' => ['at' => null, 'source' => 'unreadable',
            'fallback_required' => true]]));
        $mform = $this->extend();
        $p = settings_section::PREFIX;
        $this->assertTrue($mform->elementExists($p . 'openingat'));
        $this->assertSame(0, $mform->_defaultValues[$p . 'attest']);

        // An unknown source is "no opening date", and no form without fallback_required.
        $this->transport->respond(200, self::view(['opening' => ['at' => '2026-11-06T22:34:16+00:00', 'source' => 'future_thing',
            'fallback_required' => false]]));
        $mform = $this->extend();
        $this->assertFalse($mform->elementExists($p . 'openingat'));
        $this->assertStringContainsString(
            get_string('form_opening_none', 'local_vektradiscuss'),
            $mform->getElement($p . 'opening')->toHtml()
        );
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
     * key is absent when it is not replaced; course material search is not sent when it did not change.
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
        $this->assertSame(
            ['a@example.test', 'b@example.test'],
            json_decode($this->transport->calls[0]['body'], true)['transcript_recipients']
        );
    }

    /**
     * Vektra's field errors become a notification naming each field; the text still leaves the module info.
     */
    public function test_field_errors_are_notified(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(400, ['error' => ['code' => 'ERR-ASSESS-022', 'message' => 'fields', 'details' => ['fields' => [
            ['field' => 'enabled', 'code' => 'quiz_without_close_date'],
            ['field' => 'transcript_recipients', 'code' => 'domain_not_allowed', 'index' => 0,
                'allowed_domains' => ['example.test']],
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
        // A Vektra that sends no hint gets the legacy wait.
        $this->assertSame([teacher_api::LEGACY_RETRY_AFTER], $this->slept);
        $this->assertSame(get_string('form_saved', 'local_vektradiscuss'), \core\notification::fetch()[0]->get_message());
    }

    /**
     * A save refused on LMS facts Vektra is refreshing (retry_after_s) waits as asked and is sent again once,
     * the same body; a second refusal is shown even when it carries the hint again.
     */
    public function test_save_refused_on_refreshing_facts_is_retried_once(): void {
        $p = settings_section::PREFIX;
        $refused = ['error' => ['code' => 'ERR-ASSESS-022', 'message' => 'invalid', 'details' => [
            'fields' => [['field' => 'enabled', 'code' => 'assignment_without_cutoff']], 'retry_after_s' => 3]]];
        $this->transport->respond(400, $refused, ['Retry-After' => '3']);
        $this->transport->respond(200, self::view(['version' => 4]));
        $this->save($this->submitted([$p . 'enabled' => 1]));
        $this->assertDebuggingCalled();
        $this->assertSame([3], $this->slept);
        $this->assertCount(2, $this->transport->calls);
        $this->assertSame($this->transport->calls[0]['body'], $this->transport->calls[1]['body']);
        $this->assertSame(get_string('form_saved', 'local_vektradiscuss'), \core\notification::fetch()[0]->get_message());

        $this->slept = [];
        $this->transport->respond(400, $refused);
        $this->transport->respond(400, $refused);
        $this->save($this->submitted([$p . 'enabled' => 1]));
        $this->assertDebuggingCalledCount(2);
        $this->assertSame([3], $this->slept);
        $this->assertCount(4, $this->transport->calls);
        $this->assertSame(0, $this->transport->remaining());
        $this->assertStringStartsWith(
            get_string('form_refused', 'local_vektradiscuss'),
            \core\notification::fetch()[0]->get_message()
        );
    }

    /**
     * Rendering the section asks Vektra to refresh the activity's gate first; a resubmission, which reuses the
     * rendered view, does not.
     */
    public function test_render_refreshes_the_gate(): void {
        $this->transport->respond(200, self::view());
        $this->extend();
        $this->assertSame([(int) $this->quiz->cmid], $this->refreshes);
        $this->assertCount(1, $this->transport->calls);

        $_POST[settings_section::PREFIX . 'view'] = json_encode(self::view());
        try {
            $this->extend();
        } finally {
            unset($_POST[settings_section::PREFIX . 'view']);
        }
        $this->assertSame([(int) $this->quiz->cmid], $this->refreshes);
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
     * A submission rebuilds the section from the view it was rendered with: no second GET, so an outage
     * between render and save cannot drop the changes.
     */
    public function test_submission_reuses_the_rendered_view(): void {
        $_POST[settings_section::PREFIX . 'view'] = json_encode(self::view(['version' => 7]));
        try {
            $mform = $this->extend();
        } finally {
            unset($_POST[settings_section::PREFIX . 'view']);
        }
        $this->assertSame([], $this->transport->calls);
        $this->assertSame(7, $mform->getElement(settings_section::PREFIX . 'version')->getValue());
    }

    /**
     * A stale role check on render is retried once; a permanent refusal is not, and says so.
     */
    public function test_render_retries_a_stale_check_once(): void {
        $this->transport->respond(403, ['error' => ['code' => 'ERR-ASSESS-021', 'message' => 'stale',
            'details' => ['reason' => 'editor_check_stale']]]);
        $this->transport->respond(200, self::view());
        $mform = $this->extend();
        $this->assertDebuggingCalled();
        $this->assertCount(2, $this->transport->calls);
        $this->assertTrue($mform->elementExists(settings_section::PREFIX . 'version'));

        $this->transport->respond(403, ['error' => ['code' => 'ERR-ASSESS-021', 'message' => 'no',
            'details' => ['reason' => 'not_an_editor']]]);
        $mform = $this->extend();
        $this->assertDebuggingCalled();
        $this->assertCount(3, $this->transport->calls);
        $this->assertStringContainsString(
            get_string('form_not_an_editor', 'local_vektradiscuss'),
            $mform->getElement(settings_section::PREFIX . 'note')->toHtml()
        );
    }

    /**
     * The recipients switch is never pre-ticked, so an unrelated save sends no recipients.
     */
    public function test_recipients_not_preticked(): void {
        $this->transport->respond(200, self::view(['transcript_recipients_state' => 'non impostata',
            'transcript_recipients_count' => 0]));
        $mform = $this->extend();
        $this->assertSame(0, $mform->_defaultValues[settings_section::PREFIX . 'recipients_replace']);
        $this->assertSame($this->teacher->email, $mform->_defaultValues[settings_section::PREFIX . 'recipients']);
    }

    /**
     * An end date stored with seconds is unchanged when the form posts the same minute.
     */
    public function test_available_until_compared_by_minute(): void {
        $p = settings_section::PREFIX;
        $view = self::view(['available_until' => '2026-12-01T10:30:45+00:00']);
        $values = $this->submitted([$p . 'view' => json_encode($view), $p . 'availableuntil' => strtotime('2026-12-01T10:30:00Z')]);
        $this->assertSame([], settings_section::changes($values, 'quiz'));
        $values[$p . 'availableuntil'] += 60;
        $this->assertSame(['available_until' => '2026-12-01T10:31:00Z'], settings_section::changes($values, 'quiz'));
    }

    /**
     * Duplicate recipients collapse case-insensitively, as in Vektra.
     */
    public function test_recipients_dedupe_case_insensitive(): void {
        $this->assertSame(
            ['Prof@uni.test', 'a@uni.test', 'b@uni.test'],
            settings_section::recipients('Prof@uni.test, prof@uni.test, a@uni.test, b@uni.test')
        );
    }

    /**
     * Configuration first, then the opening fallback (which needs a configured activity).
     */
    public function test_config_before_opening_fallback(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(200, self::view(['version' => 4, 'enabled' => true]));
        $this->transport->respond(200, ['opening_at' => '2026-11-07T10:00:00+00:00', 'previous' => null, 'version' => 5]);
        $this->save($this->submitted([$p . 'enabled' => 1, $p . 'openingat' => 1794045600, $p . 'attest' => 1]));
        $this->assertStringEndsWith('/config', $this->transport->calls[0]['url']);
        $this->assertStringEndsWith('/opening-fallback', $this->transport->calls[1]['url']);
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
        $errors = settings_section::validate($this->submitted([$p . 'rubricsource' => 'pasted']));
        $this->assertArrayHasKey($p . 'rubricsource', $errors);
        $errors = settings_section::validate($this->submitted([$p . 'recipients_replace' => 1,
            $p . 'recipients' => "a@x.test\nb@x.test\nc@x.test\nd@x.test"]));
        $this->assertArrayHasKey($p . 'recipients', $errors);
        $this->assertArrayHasKey($p . 'attest', settings_section::validate($this->submitted([$p . 'openingat' => 1794045600])));
    }

    /**
     * Course material search (FEAT-028): off by default, with an unticked confirmation shown when it is turned on.
     */
    public function test_rag_switch_renders_off_with_its_confirmation(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(200, self::view());
        $mform = $this->extend();
        $this->assertSame(0, $mform->_defaultValues[$p . 'rag']);
        $this->assertTrue($mform->elementExists($p . 'ragattest'));
        $this->assertSame(0, $mform->_defaultValues[$p . 'ragattest']);
        $this->assertFalse($mform->elementExists($p . 'ragattested'));
    }

    /**
     * Once on, the form shows when the teacher confirmed, and no new confirmation is asked.
     */
    public function test_rag_switch_renders_on_with_the_confirmation_date(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(200, self::view(['rag_enabled' => true, 'rag_attested_at' => '2026-11-16T10:00:00+00:00']));
        $mform = $this->extend();
        $this->assertSame(1, $mform->_defaultValues[$p . 'rag']);
        $this->assertFalse($mform->elementExists($p . 'ragattest'));
        $this->assertStringContainsString(
            get_string('form_rag_attested', 'local_vektradiscuss', userdate(strtotime('2026-11-16T10:00:00+00:00'))),
            $mform->getElement($p . 'ragattested')->toHtml()
        );
    }

    /**
     * Turning the search on sends the switch with the confirmation; turning it off sends the switch alone.
     */
    public function test_rag_switch_is_sent_with_the_confirmation(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(200, self::view(['version' => 4, 'rag_enabled' => true]));
        $this->save($this->submitted([$p . 'rag' => 1, $p . 'ragattest' => 1]));
        $body = json_decode($this->transport->calls[0]['body'], true);
        $this->assertTrue($body['rag_enabled']);
        $this->assertTrue($body['rag_attested']);

        $on = json_encode(self::view(['rag_enabled' => true, 'rag_attested_at' => '2026-11-16T10:00:00+00:00']));
        $this->transport->respond(200, self::view(['version' => 5]));
        $this->save($this->submitted([$p . 'view' => $on, $p . 'rag' => 0]));
        $body = json_decode($this->transport->calls[1]['body'], true);
        $this->assertFalse($body['rag_enabled']);
        $this->assertArrayNotHasKey('rag_attested', $body);
    }

    /**
     * Unchanged while on: nothing about the search is sent, and no confirmation is needed again.
     */
    public function test_rag_unchanged_while_on_sends_nothing(): void {
        $p = settings_section::PREFIX;
        $on = json_encode(self::view(['rag_enabled' => true, 'rag_attested_at' => '2026-11-16T10:00:00+00:00']));
        $values = $this->submitted([$p . 'view' => $on, $p . 'rag' => 1]);
        $this->assertSame([], settings_section::validate($values));
        $this->save($values);
        $this->assertSame([], $this->transport->calls);
    }

    /**
     * Turning the search on without the confirmation is refused locally, before Vektra.
     */
    public function test_rag_needs_the_confirmation(): void {
        $p = settings_section::PREFIX;
        $errors = settings_section::validate($this->submitted([$p . 'rag' => 1]));
        $this->assertSame(get_string('form_err_rag_attest', 'local_vektradiscuss'), $errors[$p . 'ragattest']);
        $this->assertSame([], settings_section::validate($this->submitted([$p . 'rag' => 1, $p . 'ragattest' => 1])));
    }

    /**
     * Vektra's refusals of the search: the confirmation, and unavailability with its reason.
     */
    public function test_rag_refusals_are_notified_with_their_reason(): void {
        $p = settings_section::PREFIX;
        $cases = [
            [['code' => 'rag_attestation_required'], 'form_code_rag_attestation_required'],
            [['code' => 'rag_unavailable', 'reason' => 'operator_flag_off'], 'form_code_rag_unavailable_operator_flag_off'],
            [['code' => 'rag_unavailable', 'reason' => 'eligibility_unreadable'],
                'form_code_rag_unavailable_eligibility_unreadable'],
            [['code' => 'rag_unavailable', 'reason' => 'something_new'], 'form_code_rag_unavailable'],
            [['code' => 'rag_unavailable', 'reason' => '<b>x</b>'], 'form_code_rag_unavailable'],
        ];
        $label = get_string('form_field_rag_enabled', 'local_vektradiscuss');
        foreach ($cases as [$item, $identifier]) {
            $this->transport->respond(400, ['error' => ['code' => 'ERR-ASSESS-022', 'message' => 'fields',
                'details' => ['fields' => [['field' => 'rag_enabled'] + $item]]]]);
            $this->save($this->submitted([$p . 'rag' => 1, $p . 'ragattest' => 1]));
            $this->assertDebuggingCalled();
            $messages = \core\notification::fetch();
            $this->assertCount(1, $messages);
            $this->assertStringContainsString(
                get_string($identifier, 'local_vektradiscuss', (object) ['field' => $label, 'range' => '', 'domains' => '']),
                $messages[0]->get_message()
            );
        }
    }

    /**
     * The search is saved in its own request after the other changes: a refusal of the search keeps them, and the
     * second request carries the version the first one returned.
     */
    public function test_rag_refusal_keeps_the_other_changes(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(200, self::view(['version' => 4, 'cap_minutes' => 60]));
        $this->transport->respond(400, ['error' => ['code' => 'ERR-ASSESS-022', 'message' => 'fields',
            'details' => ['fields' => [['field' => 'rag_enabled', 'code' => 'rag_unavailable', 'reason' => 'operator_flag_off']]]]]);
        $this->save($this->submitted([$p . 'cap' => '60', $p . 'rag' => 1, $p . 'ragattest' => 1]));
        $this->assertDebuggingCalled();

        $this->assertCount(2, $this->transport->calls);
        $first = json_decode($this->transport->calls[0]['body'], true);
        $this->assertSame(60, $first['cap_minutes']);
        $this->assertArrayNotHasKey('rag_enabled', $first);
        $second = json_decode($this->transport->calls[1]['body'], true);
        $this->assertSame(4, $second['expected_version']);
        $this->assertTrue($second['rag_enabled']);
        $this->assertTrue($second['rag_attested']);
        $this->assertArrayNotHasKey('cap_minutes', $second);

        $messages = array_map(fn($m) => $m->get_message(), \core\notification::fetch());
        $this->assertContains(get_string('form_saved', 'local_vektradiscuss'), $messages);
        $this->assertStringContainsString(
            get_string('form_code_rag_unavailable_operator_flag_off', 'local_vektradiscuss',
                (object) ['field' => get_string('form_field_rag_enabled', 'local_vektradiscuss'), 'range' => '', 'domains' => '']),
            implode(' ', $messages)
        );
    }

    /**
     * Without the version of the first save, the search is not sent with a guessed one.
     */
    public function test_rag_not_sent_without_a_version(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(200, ['enabled' => true]);
        $this->save($this->submitted([$p . 'cap' => '60', $p . 'rag' => 1, $p . 'ragattest' => 1]));
        $this->assertCount(1, $this->transport->calls);
        $messages = array_map(fn($m) => $m->get_message(), \core\notification::fetch());
        $this->assertContains(get_string('form_rag_not_saved', 'local_vektradiscuss'), $messages);
    }

    /**
     * Already on without a confirmation date: neither a confirmation nor a date is shown.
     */
    public function test_rag_on_without_a_date_asks_nothing(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(200, self::view(['rag_enabled' => true, 'rag_attested_at' => null]));
        $mform = $this->extend();
        $this->assertFalse($mform->elementExists($p . 'ragattest'));
        $this->assertFalse($mform->elementExists($p . 'ragattested'));
    }

    /**
     * After a validation error the confirmation comes back unticked.
     */
    public function test_rag_confirmation_is_reset_on_redisplay(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(200, self::view());
        $mform = $this->extend();
        $mform->getElement($p . 'ragattest')->setValue(1);
        $wrapper = $this->createMock(\moodleform_mod::class);
        settings_section::after_data($wrapper, $mform);
        $this->assertEmpty($mform->getElement($p . 'ragattest')->getValue());
    }

    /**
     * A reason is only looked up for the codes that carry one: other codes never borrow an unrelated sentence.
     */
    public function test_reason_only_for_reasoned_codes(): void {
        $p = settings_section::PREFIX;
        $this->transport->respond(400, ['error' => ['code' => 'ERR-ASSESS-022', 'message' => 'fields',
            'details' => ['fields' => [['field' => 'cap_minutes', 'code' => 'out', 'reason' => 'of_range']]]]]);
        $this->save($this->submitted([$p . 'cap' => '60']));
        $this->assertDebuggingCalled();
        $text = \core\notification::fetch()[0]->get_message();
        $this->assertStringContainsString(get_string('form_code_invalid', 'local_vektradiscuss',
            (object) ['field' => get_string('form_field_cap_minutes', 'local_vektradiscuss'), 'range' => '', 'domains' => '']), $text);
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
