<?php
// OQ-025 spike: add an assign user override (later due date) for s3 on Assign X. Prints the override duedate.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
define('BEHAT_TEST', true);
foreach ([$CFG->dirroot . '/vendor/autoload.php', dirname($CFG->dirroot) . '/vendor/autoload.php'] as $al) { if (file_exists($al)) { require_once($al); break; } }
if (file_exists($CFG->libdir . '/testing/classes/util.php')) { require_once($CFG->libdir . '/testing/classes/util.php'); }
if (file_exists($CFG->libdir . '/testing/generator/lib.php')) { require_once($CFG->libdir . '/testing/generator/lib.php'); }
require_once($CFG->dirroot . '/mod/assign/locallib.php');
\core\session\manager::set_user(get_admin());
$gen = testing_util::get_data_generator();
$ax = $DB->get_record('assign', ['name' => 'Assign X rubric released'], '*', MUST_EXIST);
$s3 = $DB->get_record('user', ['username' => 'oq025_s3', 'deleted' => 0], '*', MUST_EXIST);
if (!$DB->record_exists('assign_overrides', ['assignid' => $ax->id, 'userid' => $s3->id])) {
    $gen->get_plugin_generator('mod_assign')->create_override(['assignid' => $ax->id, 'userid' => $s3->id,
        'duedate' => time() + 5 * 86400, 'cutoffdate' => time() + 6 * 86400]);
}
echo json_encode($DB->get_record('assign_overrides', ['assignid' => $ax->id, 'userid' => $s3->id])), "\n";
