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
 * The teacher's "Discussions" page of a quiz or an assignment.
 *
 * Login, course access, capability, session key (on actions) and "log in as" are checked in Moodle before
 * any request to Vektra, which re-checks that the actor grades the activity.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_vektradiscuss\local\config;
use local_vektradiscuss\local\student_endpoint;
use local_vektradiscuss\local\teacher_api;
use local_vektradiscuss\local\teacher_page;

$cmid = required_param('cmid', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('local/vektradiscuss:viewtranscripts', $context);
if (!in_array($cm->modname, teacher_api::ACTIVITY_TYPES, true)) {
    throw new moodle_exception('invalidcoursemodule');
}

$url = new moodle_url('/local/vektradiscuss/teacher.php', ['cmid' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(format_string($cm->name) . ': ' . get_string('teacher_heading', 'local_vektradiscuss'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_secondary_active_tab('modulepage');

$config = new config();
$refusal = null;
if (student_endpoint::is_loginas()) {
    $refusal = 'teacher_loginas';
} else if (!$config->is_enabled()) {
    $refusal = 'teacher_disabled';
} else if (!$config->key_usable('teacher')) {
    $refusal = 'form_not_configured';
}

$page = new teacher_page($cm, $course);
$action = optional_param('action', '', PARAM_ALPHA);
if ($refusal === null && $action !== '') {
    require_sesskey();
    if (!data_submitted()) {
        throw new moodle_exception('invalidrequest');
    }
    [$ok, $message] = $page->act($action, [
        'sid' => optional_param('sid', '', PARAM_ALPHANUMEXT),
        'reason' => optional_param('reason', '', PARAM_TEXT),
        'includeprevious' => optional_param('includeprevious', 0, PARAM_BOOL),
        'expected' => optional_param('expected', -1, PARAM_INT),
        'alsodisable' => optional_param('alsodisable', 0, PARAM_BOOL),
        'userid' => optional_param('userid', 0, PARAM_INT),
        'unit' => optional_param('unit', 'minutes', PARAM_ALPHA),
        'value' => optional_param('value', '', PARAM_RAW_TRIMMED),
        'confirm' => optional_param('confirm', 0, PARAM_BOOL),
        'tid' => optional_param('tid', '', PARAM_ALPHANUMEXT),
        'page' => optional_param('page', '', PARAM_RAW_TRIMMED),
    ]);
    redirect($url, $message, null, $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('teacher_heading', 'local_vektradiscuss'));
if ($refusal !== null) {
    echo $OUTPUT->notification(get_string($refusal, 'local_vektradiscuss'), 'warning', false);
    echo $OUTPUT->footer();
    die();
}

$cursor = teacher_page::clean_cursor(optional_param('cursor', '', PARAM_RAW_TRIMMED));
$api = new teacher_api();
$response = $api->list_sessions((int) $cm->id, (int) $course->id, $cm->modname, (int) $USER->id, $cursor);
if (teacher_api::role_refusal($response) === 'stale') {
    // Vektra queued a fresh check of the teacher's role: one retry after it had time to land.
    sleep(2);
    $response = $api->list_sessions((int) $cm->id, (int) $course->id, $cm->modname, (int) $USER->id, $cursor);
}
$context = $page->context($response, $cursor, optional_param('q', '', PARAM_TEXT));
if ($context === null) {
    $identifier = match (teacher_api::role_refusal($response)) {
        'stale' => 'teacher_check_stale',
        'refused' => 'teacher_not_a_grader',
        default => 'teacher_unreachable',
    };
    echo $OUTPUT->notification(get_string($identifier, 'local_vektradiscuss'), 'warning', false);
} else {
    echo $OUTPUT->render_from_template('local_vektradiscuss/teacher_sessions', $context);
}
echo $OUTPUT->footer();
