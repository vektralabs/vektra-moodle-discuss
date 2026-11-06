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
 * Tests for the results-page injection.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

/**
 * Tests for {@see injector}. The injector never calls Vektra: it has no client at all.
 *
 * @covers \local_vektradiscuss\local\injector
 * @covers \local_vektradiscuss\local\hook_callbacks
 */
final class injector_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Student. */
    private \stdClass $student;

    /**
     * Enabled, configured site with a course and a student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_vektradiscuss');
        set_config('uiurl', 'https://ui.example.test', 'local_vektradiscuss');
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($this->student);
    }

    /**
     * A page of a module with a page type.
     *
     * @param string $modname
     * @param string $pagetype
     * @return \moodle_page
     */
    private function page(string $modname, string $pagetype): \moodle_page {
        $module = $this->getDataGenerator()->create_module($modname, ['course' => $this->course->id]);
        [$course, $cm] = get_course_and_cm_from_cmid($module->cmid);
        $page = new \moodle_page();
        $page->set_cm($cm, $course);
        $page->set_pagetype($pagetype);
        $page->set_url('/mod/' . $modname . '/view.php', ['id' => $cm->id]);
        return $page;
    }

    /**
     * The three target pages get the container with the cmid.
     */
    public function test_target_pages(): void {
        foreach (['mod-quiz-review' => 'quiz', 'mod-quiz-view' => 'quiz', 'mod-assign-view' => 'assign'] as $type => $mod) {
            $page = $this->page($mod, $type);
            $html = injector::inject($page);
            $this->assertStringContainsString('data-region="local_vektradiscuss"', $html, $type);
            $this->assertStringContainsString('data-cmid="' . $page->cm->id . '"', $html, $type);
        }
    }

    /**
     * Any other page, module mismatch, kill switch off, no capability or no usable origin: nothing.
     */
    public function test_nothing_rendered(): void {
        global $CFG;
        $this->assertSame('', injector::inject($this->page('quiz', 'mod-quiz-attempt')));
        $this->assertSame('', injector::inject($this->page('page', 'mod-assign-view')));
        $this->assertSame('', injector::inject($this->page('forum', 'mod-forum-view')));

        set_config('enabled', 0, 'local_vektradiscuss');
        $this->assertSame('', injector::inject($this->page('quiz', 'mod-quiz-view')));
        set_config('enabled', 1, 'local_vektradiscuss');

        set_config('uiurl', 'http://ui.example.test', 'local_vektradiscuss');
        $this->assertSame('', injector::inject($this->page('quiz', 'mod-quiz-view')));

        // The UI origin equal to the Moodle origin is refused (the iframe sandbox needs allow-same-origin).
        $CFG->wwwroot = 'https://moodle.example.test';
        set_config('uiurl', 'https://moodle.example.test', 'local_vektradiscuss');
        $this->assertSame('', injector::inject($this->page('quiz', 'mod-quiz-view')));
        $this->assertSame('', injector::usable_ui_origin(new config()));
        // The same origin written with its default port is still the same origin.
        set_config('uiurl', 'https://moodle.example.test:443', 'local_vektradiscuss');
        $this->assertSame('', injector::usable_ui_origin(new config()));
    }

    /**
     * Without both capabilities nothing is rendered.
     */
    public function test_no_capability(): void {
        $page = $this->page('quiz', 'mod-quiz-view');
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_module::instance($page->cm->id);
        assign_capability('local/vektradiscuss:start', CAP_PROHIBIT, $roleid, $context->id);
        assign_capability('local/vektradiscuss:viewowntranscript', CAP_PROHIBIT, $roleid, $context->id);
        role_assign($roleid, $this->student->id, $context->id);
        $this->assertSame('', injector::inject($page));
    }

    /**
     * db/hooks.php uses the "class::method" string form, the only one the Moodle 4.3 hook manager accepts,
     * and it points to an existing static method.
     */
    public function test_hook_definition_is_43_compatible(): void {
        $callbacks = [];
        require(__DIR__ . '/../../db/hooks.php');
        $this->assertCount(1, $callbacks);
        $this->assertSame('core\\hook\\output\\before_footer_html_generation', ltrim($callbacks[0]['hook'], '\\'));
        $this->assertIsString($callbacks[0]['callback']);
        [$class, $method] = explode('::', $callbacks[0]['callback']);
        $this->assertTrue(is_callable([$class, $method]));
    }
}
