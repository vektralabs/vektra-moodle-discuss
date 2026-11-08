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
 * A student's own transcript, streamed from Vektra as a download (REQ-088, BR-016).
 *
 * Moodle checks login, course access, sesskey, capability and "log in as" before any request to Vektra;
 * Vektra checks ownership on the download-token mint and again on the download. Nothing is stored.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_vektradiscuss\local\config;
use local_vektradiscuss\local\student_endpoint;
use local_vektradiscuss\local\transcript_proxy;

$cmid = required_param('cmid', PARAM_INT);
$tid = required_param('tid', PARAM_ALPHANUMEXT);
$n = optional_param('n', 1, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_login($course, false, $cm);
require_sesskey();
$context = context_module::instance($cm->id);
require_capability('local/vektradiscuss:viewowntranscript', $context);

$PAGE->set_url(new moodle_url('/local/vektradiscuss/transcript.php', ['cmid' => $cm->id]));
$PAGE->set_context($context);
$back = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);

/**
 * End with a page that says why there is no file: an expected outcome, not an error, so no exception.
 *
 * @param string $identifier Language string of this plugin.
 * @param moodle_url $back The activity page.
 */
function local_vektradiscuss_transcript_refused(string $identifier, moodle_url $back): void {
    global $OUTPUT, $PAGE;
    $PAGE->set_title(get_string('transcripts_heading', 'local_vektradiscuss'));
    $PAGE->set_heading(get_string('transcripts_heading', 'local_vektradiscuss'));
    header('Cache-Control: no-store');
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string($identifier, 'local_vektradiscuss'), 'warning', false);
    echo $OUTPUT->continue_button($back);
    echo $OUTPUT->footer();
    die();
}

// REQ-084: a "log in as" session never reaches Vektra.
if (student_endpoint::is_loginas()) {
    local_vektradiscuss_transcript_refused('state_loginas', $back);
}
$config = new config();
if (!$config->is_enabled() || !in_array($cm->modname, student_endpoint::MODULES, true) || !transcript_proxy::valid_tid($tid)) {
    local_vektradiscuss_transcript_refused('transcript_unavailable', $back);
}

// Release the session lock: the transfer may take a while and must not block the student's other tabs.
\core\session\manager::write_close();

$proxy = new transcript_proxy($config);
[$result, $token] = $proxy->mint_own((int) $USER->id, $tid);
if ($result === transcript_proxy::OK) {
    $result = $proxy->stream(
        $tid,
        $token,
        $n,
        function (string $line): void {
            header($line);
        },
        function (string $chunk): void {
            echo $chunk;
            flush();
        }
    );
}
if ($result === transcript_proxy::OK || $result === transcript_proxy::TRUNCATED) {
    die();
}
local_vektradiscuss_transcript_refused(
    $result === transcript_proxy::NOT_READY ? 'transcript_not_ready' : 'transcript_unavailable',
    $back
);
