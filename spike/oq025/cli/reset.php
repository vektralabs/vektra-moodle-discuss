<?php
// OQ-025 spike: remove the seeded course and oq025_* users so seed.php can run again.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/user/lib.php');
\core\session\manager::set_user(get_admin());
if ($c = $DB->get_record('course', ['shortname' => 'OQ025'])) { delete_course($c, false); echo "course deleted\n"; }
foreach ($DB->get_records_select('user', "username LIKE 'oq025_%' AND deleted = 0") as $x) { user_delete_user($x); }
echo "users deleted\n";
