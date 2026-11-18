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
 * Teacher AJAX endpoint of the settings form's "Show" button: one saved text (solution, pasted rubric or
 * recipients) read from Vektra.
 *
 * POST only, with the session key, for a user who can edit the activity
 * ({@see \local_vektradiscuss\local\config_reveal::can_reveal()}). The answer is never cached and the text is
 * never stored, logged or turned into an event.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use local_vektradiscuss\local\config_reveal;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new \moodle_exception('invalidrequest');
}

$cmid = required_param('cmid', PARAM_INT);
$field = required_param('field', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_login($course, false, $cm, false, true);
require_sesskey();
$PAGE->set_url('/local/vektradiscuss/reveal.php');

// A developer site displays the debugging of a refused call: keep it out of the JSON answer.
ob_start();
$result = (new config_reveal())->reveal($cm, (int) $USER->id, $field);
ob_end_clean();

echo $OUTPUT->header();
header('Cache-Control: no-store');
header('Pragma: no-cache');
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
