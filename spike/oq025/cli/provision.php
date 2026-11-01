<?php
// OQ-025 spike: dedicated assess service account (REQ-079), idempotent.
// Usage: php provision.php [--caps=cap1,cap2,...]  (override the role capability set, for ablation runs)
// Prints {"token": ..., "userid": ..., "functions": [...], "caps": [...]} as JSON.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/webservice/lib.php');
[$opts] = cli_get_params(['caps' => '']);
\core\session\manager::set_user(get_admin());
$sys = context_system::instance();

set_config('enablewebservices', 1);
$protocols = array_filter(explode(',', (string)get_config('core', 'webserviceprotocols')));
if (!in_array('rest', $protocols)) { $protocols[] = 'rest'; set_config('webserviceprotocols', implode(',', $protocols)); }

// User: interactive login disabled, not an admin. auth=nologin is REFUSED by the WS layer
// (wsaccessusernologin, verified on 4.3 and 5.2), so use auth_webservice: token access only.
$auths = array_filter(explode(',', (string)get_config('core', 'auth')));
if (!in_array('webservice', $auths)) { $auths[] = 'webservice'; set_config('auth', implode(',', $auths)); \core\session\manager::gc(); }
$user = $DB->get_record('user', ['username' => 'vektra_assess_svc', 'deleted' => 0]);
if (!$user) {
    $id = user_create_user((object)[
        'username' => 'vektra_assess_svc', 'auth' => 'webservice', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
        'firstname' => 'Vektra', 'lastname' => 'Assess service', 'email' => 'vektra-assess-svc@example.invalid',
        'password' => random_string(32),
    ], false, false);
    $user = $DB->get_record('user', ['id' => $id]);
}
if ($user->auth !== 'webservice') { $DB->set_field('user', 'auth', 'webservice', ['id' => $user->id]); $user->auth = 'webservice'; }

// Read-only role at system level. Default capability set; --caps replaces it for ablation.
$defaultcaps = [
    'webservice/rest:use',
    'moodle/course:view', 'moodle/course:viewparticipants', 'moodle/course:viewhiddenactivities',
    'moodle/course:viewhiddencourses', 'moodle/site:accessallgroups', 'moodle/user:viewdetails',
    'moodle/grade:viewall', 'moodle/grade:viewhidden', 'moodle/grade:managegradingforms',
    'gradereport/user:view', 'moodle/grade:view',
    'mod/quiz:view', 'mod/quiz:viewreports', 'mod/quiz:reviewmyattempts',
    'mod/assign:view', 'mod/assign:viewgrades', 'mod/assign:grade', 'moodle/role:review',
];
if (get_capability_info('mod/quiz:viewoverrides')) { $defaultcaps[] = 'mod/quiz:viewoverrides'; }
if (get_capability_info('mod/assign:viewoverrides')) { $defaultcaps[] = 'mod/assign:viewoverrides'; }
$caps = $opts['caps'] !== '' ? array_map('trim', explode(',', $opts['caps'])) : $defaultcaps;
$roleid = $DB->get_field('role', 'id', ['shortname' => 'vektra_assess_reader']);
if (!$roleid) {
    $roleid = create_role('Vektra assess reader', 'vektra_assess_reader', 'OQ-025 spike: read-only WS role', '');
    set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
}
// Reset to exactly $caps (ablation runs remove one cap at a time).
$DB->delete_records('role_capabilities', ['roleid' => $roleid, 'contextid' => $sys->id]);
foreach ($caps as $c) {
    if (get_capability_info($c)) { assign_capability($c, CAP_ALLOW, $roleid, $sys->id, true); }
}
if (!user_has_role_assignment($user->id, $roleid, $sys->id)) { role_assign($roleid, $user->id, $sys->id); }
accesslib_clear_all_caches(true);

// External service with read functions only.
$functions = [
    'core_webservice_get_site_info',
    'core_course_get_courses_by_field', 'core_course_get_contents', 'core_course_get_course_module',
    'core_enrol_get_enrolled_users', 'core_enrol_get_enrolled_users_with_capability', 'core_enrol_get_users_courses',
    'core_group_get_course_user_groups', 'core_user_get_users_by_field',
    'mod_quiz_get_quizzes_by_courses', 'mod_quiz_get_user_attempts', 'mod_quiz_get_attempt_review',
    'mod_quiz_get_combined_review_options', 'mod_quiz_get_user_best_grade', 'mod_quiz_get_overrides',
    'mod_quiz_get_user_quiz_attempts', 'mod_quiz_get_quiz_feedback_for_grade',
    'mod_assign_get_assignments', 'mod_assign_get_submissions', 'mod_assign_get_submission_status',
    'mod_assign_get_grades', 'mod_assign_get_user_flags', 'mod_assign_get_participant',
    'core_grading_get_definitions', 'core_grading_get_gradingform_instances',
    'gradereport_user_get_grade_items',
];
$functions = array_values(array_filter($functions, fn($f) => $DB->record_exists('external_functions', ['name' => $f])));
$service = $DB->get_record('external_services', ['shortname' => 'vektra_assess']);
if (!$service) {
    $sid = $DB->insert_record('external_services', (object)[
        'name' => 'Vektra assess (spike)', 'shortname' => 'vektra_assess', 'enabled' => 1, 'restrictedusers' => 1,
        'requiredcapability' => '', 'downloadfiles' => 1, 'uploadfiles' => 0, 'timecreated' => time(),
    ]);
    $service = $DB->get_record('external_services', ['id' => $sid]);
}
$DB->delete_records('external_services_functions', ['externalserviceid' => $service->id]);
foreach ($functions as $f) {
    $DB->insert_record('external_services_functions', (object)['externalserviceid' => $service->id, 'functionname' => $f]);
}
if (!$DB->record_exists('external_services_users', ['externalserviceid' => $service->id, 'userid' => $user->id])) {
    $DB->insert_record('external_services_users', (object)['externalserviceid' => $service->id, 'userid' => $user->id, 'timecreated' => time()]);
}
$tok = $DB->get_record('external_tokens', ['externalserviceid' => $service->id, 'userid' => $user->id, 'tokentype' => EXTERNAL_TOKEN_PERMANENT]);
if (!$tok) {
    $token = md5(random_string(64));
    $DB->insert_record('external_tokens', (object)[
        'token' => $token, 'privatetoken' => random_string(64), 'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        'userid' => $user->id, 'externalserviceid' => $service->id, 'contextid' => $sys->id,
        'creatorid' => get_admin()->id, 'timecreated' => time(), 'validuntil' => 0,
    ]);
} else { $token = $tok->token; }
echo json_encode(['token' => $token, 'userid' => (int)$user->id, 'auth' => $user->auth, 'functions' => $functions,
    'caps' => $caps], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
