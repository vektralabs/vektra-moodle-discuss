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
 * Student AJAX endpoint: advisory state (action=state) and pre-session token (action=mint).
 *
 * POST only. Login, course access, sesskey and capabilities are checked by
 * \local_vektradiscuss\local\student_endpoint before any request to Vektra.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

// phpcs:ignore moodle.Files.RequireLogin.Missing -- student_endpoint::handle() calls require_login() with the course and cm.
require(__DIR__ . '/../../config.php');

use local_vektradiscuss\local\state_view;
use local_vektradiscuss\local\student_endpoint;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new \moodle_exception('invalidrequest');
}

$action = required_param('action', PARAM_ALPHA);
$cmid = required_param('cmid', PARAM_INT);
$attemptid = optional_param('attemptid', 0, PARAM_INT);

$PAGE->set_url('/local/vektradiscuss/ajax.php');

$result = (new student_endpoint())->handle($action, $cmid, $attemptid);
if ($action === 'state') {
    $result = state_view::context($result, $cmid);
}

echo $OUTPUT->header();
header('Cache-Control: no-store');
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
