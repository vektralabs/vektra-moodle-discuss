<?php
// Lists external functions matching the patterns relevant to the OQ-025 spike.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
$pat = '/^(mod_quiz_|mod_assign_|core_grading_|gradereport_user_|core_grades_|core_enrol_get_enrolled|core_group_get_(course_user|activity)|core_course_get_(contents|course_module|courses_by)|core_availability|report_log|tool_log|core_webservice_get_site_info|core_user_get_users_by_field|core_files_get)/';
$out = [];
foreach ($DB->get_records('external_functions', null, 'name', 'name, capabilities') as $f) {
    if (preg_match($pat, $f->name)) { $out[$f->name] = $f->capabilities; }
}
echo json_encode(['release' => $CFG->release, 'functions' => $out], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";
