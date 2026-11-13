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
 * A grader downloads a student's transcript (REQ-076, BR-016), streamed from Vektra and never stored.
 *
 * Moodle checks login, course access, session key, capability and "log in as" before any request; the
 * transcript must belong to this activity (Vektra's sessions list), and Vektra checks that the actor grades
 * it. FAILED transcripts are downloadable too.
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
use local_vektradiscuss\local\transcript_proxy;

$cmid = required_param('cmid', PARAM_INT);
$tid = required_param('tid', PARAM_ALPHANUMEXT);
$page = optional_param('page', '', PARAM_RAW_TRIMMED);

[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_login($course, false, $cm);
require_sesskey();
$context = context_module::instance($cm->id);
require_capability('local/vektradiscuss:viewtranscripts', $context);

$PAGE->set_url(new moodle_url('/local/vektradiscuss/teacher_transcript.php', ['cmid' => $cm->id]));
$PAGE->set_context($context);
$back = new moodle_url('/local/vektradiscuss/teacher.php', ['cmid' => $cm->id]);

/**
 * End with a page that says why there is no file (the session lock is already released, so no redirect
 * message could be stored).
 *
 * @param string $identifier Language string of this plugin.
 * @param moodle_url $back The Discussions page.
 */
function local_vektradiscuss_teacher_transcript_refused(string $identifier, moodle_url $back): void {
    global $OUTPUT, $PAGE;
    $PAGE->set_title(get_string('teacher_heading', 'local_vektradiscuss'));
    header('Cache-Control: no-store');
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string($identifier, 'local_vektradiscuss'), 'warning', false);
    echo $OUTPUT->continue_button($back);
    echo $OUTPUT->footer();
    die();
}

// Release the session lock: the lookup and the transfer may take a while and must not block other tabs.
\core\session\manager::write_close();

$config = new config();
if (student_endpoint::is_loginas()) {
    local_vektradiscuss_teacher_transcript_refused('teacher_loginas', $back);
}
if (!$config->is_enabled() || !in_array($cm->modname, teacher_api::ACTIVITY_TYPES, true) || !transcript_proxy::valid_tid($tid)) {
    local_vektradiscuss_teacher_transcript_refused('transcript_unavailable', $back);
}
if (!$config->key_usable('teacher')) {
    local_vektradiscuss_teacher_transcript_refused('form_not_configured', $back);
}
$found = (new teacher_page($cm, $course))->find_transcript($tid, $page);
if ($found === null) {
    local_vektradiscuss_teacher_transcript_refused('transcript_unavailable', $back);
}

$proxy = new transcript_proxy($config);
[$result, $token] = $proxy->mint_grader((int) $USER->id, $tid);
if ($result === transcript_proxy::STALE) {
    // Vektra queued a fresh check of the teacher's role: one retry after it had time to land.
    sleep(2);
    [$result, $token] = $proxy->mint_grader((int) $USER->id, $tid);
}
if ($result === transcript_proxy::OK) {
    $result = $proxy->send($tid, $token, $found['n'], (string) $found['userid']);
}
if ($result === transcript_proxy::OK || $result === transcript_proxy::TRUNCATED) {
    die();
}
local_vektradiscuss_teacher_transcript_refused(
    match ($result) {
        transcript_proxy::NOT_READY => 'transcript_not_ready',
        transcript_proxy::STALE => 'teacher_check_stale',
        default => 'transcript_unavailable',
    },
    $back
);
