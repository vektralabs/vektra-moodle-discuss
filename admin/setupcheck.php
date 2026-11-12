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
 * The site administrator's setup check page (REQ-079).
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_vektradiscuss\local\setup_check;

admin_externalpage_setup('local_vektradiscuss_setupcheck');
require_capability('local/vektradiscuss:setupcheck', context_system::instance());

$url = new moodle_url('/local/vektradiscuss/admin/setupcheck.php');
$check = new setup_check();

if (optional_param('run', 0, PARAM_BOOL) && data_submitted()) {
    require_sesskey();
    [$reportid, $error] = $check->start();
    if ($reportid !== null) {
        redirect(new moodle_url($url, ['report' => $reportid]));
    }
    redirect($url, get_string($error, 'local_vektradiscuss'), null, \core\output\notification::NOTIFY_ERROR);
}

$reportid = optional_param('report', '', PARAM_ALPHANUMEXT);
$report = $reportid !== '' ? $check->report($reportid) : null;
if ($report !== null && $report['inprogress']) {
    // The worker runs the check in the background: look again shortly.
    $PAGE->set_periodic_refresh_delay(3);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('setupcheck_heading', 'local_vektradiscuss'));
if ($reportid !== '' && $report === null) {
    echo $OUTPUT->notification(get_string('setupcheck_report_unreadable', 'local_vektradiscuss'), 'warning', false);
}
echo $OUTPUT->render_from_template('local_vektradiscuss/setupcheck', [
    'actionurl' => $url->out(false),
    'sesskey' => sesskey(),
    'local' => $check->local(),
    'report' => $report,
]);
echo $OUTPUT->footer();
