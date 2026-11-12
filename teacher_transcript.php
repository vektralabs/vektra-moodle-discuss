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

[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_login($course, false, $cm);
require_sesskey();
$context = context_module::instance($cm->id);
require_capability('local/vektradiscuss:viewtranscripts', $context);

$PAGE->set_url(new moodle_url('/local/vektradiscuss/teacher_transcript.php', ['cmid' => $cm->id]));
$PAGE->set_context($context);
$back = new moodle_url('/local/vektradiscuss/teacher.php', ['cmid' => $cm->id]);

$config = new config();
if (
    student_endpoint::is_loginas() || !$config->is_enabled() || !in_array($cm->modname, teacher_api::ACTIVITY_TYPES, true)
        || !transcript_proxy::valid_tid($tid)
) {
    redirect($back, get_string('transcript_unavailable', 'local_vektradiscuss'), null, \core\output\notification::NOTIFY_ERROR);
}
$n = (new teacher_page($cm, $course))->session_of_transcript($tid);
if ($n === null) {
    redirect($back, get_string('transcript_unavailable', 'local_vektradiscuss'), null, \core\output\notification::NOTIFY_ERROR);
}

$proxy = new transcript_proxy($config);
[$result, $token] = $proxy->mint_grader((int) $USER->id, $tid);
if ($result === transcript_proxy::OK) {
    $result = $proxy->send($tid, $token, $n);
}
if ($result === transcript_proxy::OK || $result === transcript_proxy::TRUNCATED) {
    die();
}
redirect(
    $back,
    get_string($result === transcript_proxy::NOT_READY ? 'transcript_not_ready' : 'transcript_unavailable', 'local_vektradiscuss'),
    null,
    \core\output\notification::NOTIFY_ERROR
);
