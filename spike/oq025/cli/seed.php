<?php
// OQ-025 spike: seed fixtures for the WS readability probe. Works on Moodle 4.3 and 5.2.
// Idempotent: does nothing if course OQ025 exists. Writes a manifest (ids + canaries) to stdout.
// Every field the assess bot must NOT see carries a unique CANARY-* token.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
// Defined AFTER bootstrap so setup.php is not in Behat mode; only unlocks quiz_attempt::get_question_usage() for the generator.
define('BEHAT_TEST', true);
$CFG->noemailever = true;
// 4.3: lib/testing/classes/util.php; 5.x: autoloaded core\test\testing_util (class alias testing_util).
if (file_exists($CFG->libdir . '/testing/classes/util.php')) { require_once($CFG->libdir . '/testing/classes/util.php'); }
if (file_exists($CFG->libdir . '/testing/generator/lib.php')) { require_once($CFG->libdir . '/testing/generator/lib.php'); }
// Dev composer deps (PHPUnit) are needed by the question generator helpers; 5.x keeps vendor/ above public/.
foreach ([$CFG->dirroot . '/vendor/autoload.php', dirname($CFG->dirroot) . '/vendor/autoload.php'] as $al) {
    if (file_exists($al)) { require_once($al); break; }
}
set_exception_handler(function ($e) { fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' | ' . ($e->debuginfo ?? '') . "\n" . $e->getTraceAsString() . "\n"); exit(1); });
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->libdir . '/gradelib.php');

if ($DB->record_exists('course', ['shortname' => 'OQ025'])) {
    fwrite(STDERR, "already seeded\n");
    exit(0);
}
\core\session\manager::set_user(get_admin());
$gen = testing_util::get_data_generator();
$now = time();
$m = ['release' => $CFG->release];

// Course, users, groups.
$course = $gen->create_course(['shortname' => 'OQ025', 'fullname' => 'OQ-025 spike course']);
$m['courseid'] = (int)$course->id;
$u = [];
foreach (['s1', 's2', 's3', 's4'] as $n) {
    $u[$n] = $gen->create_user(['username' => "oq025_$n", 'firstname' => "Student", 'lastname' => strtoupper($n)]);
    $gen->enrol_user($u[$n]->id, $course->id, 'student');
}
$u['t1'] = $gen->create_user(['username' => 'oq025_t1', 'firstname' => 'Teacher', 'lastname' => 'Editing']);
$gen->enrol_user($u['t1']->id, $course->id, 'editingteacher');
$u['t2'] = $gen->create_user(['username' => 'oq025_t2', 'firstname' => 'Teacher', 'lastname' => 'Grader']);
$gen->enrol_user($u['t2']->id, $course->id, 'teacher');
foreach ($u as $k => $x) { $m['users'][$k] = (int)$x->id; }
$g1 = $gen->create_group(['courseid' => $course->id, 'name' => 'G1']);
$g2 = $gen->create_group(['courseid' => $course->id, 'name' => 'G2']);
$gen->create_group_member(['groupid' => $g1->id, 'userid' => $u['s1']->id]);
$gen->create_group_member(['groupid' => $g1->id, 'userid' => $u['s2']->id]);
$gen->create_group_member(['groupid' => $g2->id, 'userid' => $u['s3']->id]);
$m['groups'] = ['G1' => (int)$g1->id, 'G2' => (int)$g2->id];

// Questions (one category in the course; on 5.x the generator maps it to a qbank module).
$qgen = $gen->get_plugin_generator('core_question');
$cat = $qgen->create_question_category(['contextid' => context_course::instance($course->id)->id]);
$mk = function (string $type, ?string $which, string $tag) use ($qgen, $cat) {
    return $qgen->create_question($type, $which, [
        'category' => $cat->id,
        'name' => "Q-$tag",
        'questiontext' => ['text' => "VISIBLE-QTEXT-$tag: question body", 'format' => FORMAT_HTML],
        'generalfeedback' => ['text' => "CANARY-GENFB-$tag", 'format' => FORMAT_HTML],
    ]);
};
$q = [
    'tf'  => $mk('truefalse', null, 'TF'),
    'mc'  => $mk('multichoice', 'one_of_four', 'MC'),
    'sa'  => $mk('shortanswer', 'frogtoad', 'SA'),
    'num' => $mk('numerical', 'pi', 'NUM'),
    'es'  => $mk('essay', 'plain', 'ES'),
];
foreach ($q as $k => $x) { $m['questions'][$k] = (int)$x->id; }

// Review option bits (mod_quiz display_options).
$DURING = 0x10000; $IMM = 0x01000; $OPEN = 0x00100; $CLOSED = 0x00010;
$all = $DURING | $IMM | $OPEN | $CLOSED;
$qzgen = $gen->get_plugin_generator('mod_quiz');
$mkquiz = function (string $name, array $extra) use ($gen, $course, $all) {
    $base = [
        'course' => $course->id, 'name' => $name, 'grade' => 10, 'sumgrades' => 0,
        'timelimit' => 1800, 'overduehandling' => 'graceperiod', 'graceperiod' => 600,
        'questionsperpage' => 0, 'preferredbehaviour' => 'deferredfeedback',
        'reviewattempt' => $all, 'reviewcorrectness' => $all, 'reviewmarks' => $all,
        'reviewspecificfeedback' => $all, 'reviewgeneralfeedback' => $all,
        'reviewrightanswer' => $all, 'reviewoverallfeedback' => $all,
    ];
    return $gen->create_module('quiz', array_merge($base, $extra));
};
// Quiz A: everything visible after close, fully auto-graded.
$qa = $mkquiz('Quiz A open review', []);
// Quiz B: right answer, feedback and correctness hidden; marks only after close; grade item hidden (unreleased).
$qb = $mkquiz('Quiz B hidden review', [
    'reviewcorrectness' => 0, 'reviewspecificfeedback' => 0, 'reviewgeneralfeedback' => 0,
    'reviewrightanswer' => 0, 'reviewoverallfeedback' => 0, 'reviewmarks' => $CLOSED,
]);
// Quiz C: essay, needs manual grading (BR-014).
$qc = $mkquiz('Quiz C essay', []);
// The generator rebuilds review* from form checkbox fields, so set them in the DB (verified: passing them is ignored).
foreach ([$qa, $qc] as $quiz) {
    $DB->update_record('quiz', (object)['id' => $quiz->id, 'reviewattempt' => $all, 'reviewcorrectness' => $all,
        'reviewmarks' => $all, 'reviewspecificfeedback' => $all, 'reviewgeneralfeedback' => $all, 'reviewrightanswer' => $all,
        'reviewoverallfeedback' => $all]);
}
$DB->update_record('quiz', (object)['id' => $qb->id, 'reviewattempt' => $all, 'reviewcorrectness' => 0, 'reviewmarks' => $CLOSED,
    'reviewspecificfeedback' => 0, 'reviewgeneralfeedback' => 0, 'reviewrightanswer' => 0, 'reviewoverallfeedback' => 0]);
foreach (['A' => $qa, 'B' => $qb] as $quiz) {
    foreach (['tf', 'mc', 'sa', 'num'] as $k) { quiz_add_quiz_question($q[$k]->id, $quiz, 0, 1); }
}
quiz_add_quiz_question($q['tf']->id, $qc, 0, 1);
quiz_add_quiz_question($q['es']->id, $qc, 0, 5);
foreach ([$qa, $qb, $qc] as $quiz) {
    \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
}
$m['quizzes'] = [];
foreach (['A' => $qa, 'B' => $qb, 'C' => $qc] as $k => $quiz) {
    $m['quizzes'][$k] = ['id' => (int)$quiz->id, 'cmid' => (int)$quiz->cmid];
}

// Attempts. Responses per slot (simulated post data).
$right = [1 => 'True', 2 => 'One', 3 => 'frog', 4 => '3.14'];
$wrong = [1 => 'False', 2 => 'Two', 3 => 'toad', 4 => '42'];
$attempt = function ($quiz, $user, array $resp) use ($qzgen) {
    // The generator starts the attempt as the session user, so impersonate the student.
    \core\session\manager::set_user($user);
    $att = $qzgen->create_attempt($quiz->id, $user->id);
    $qzgen->submit_responses($att->id, $resp, false, true);
    \core\session\manager::set_user(get_admin());
    return (int)$att->id;
};
foreach (['A' => $qa, 'B' => $qb] as $k => $quiz) {
    $m['attempts'][$k]['s1'] = $attempt($quiz, $u['s1'], $right);
    $m['attempts'][$k]['s2'] = $attempt($quiz, $u['s2'], $wrong);
    $m['attempts'][$k]['s3'] = $attempt($quiz, $u['s3'], $right);
}
// s1 makes a second attempt on A (BR-012 attempt selection).
$m['attempts']['A']['s1_second'] = $attempt($qa, $u['s1'], $wrong);
$essay = [1 => 'True', 2 => 'VISIBLE-ESSAY-ANSWER student text'];
$m['attempts']['C']['s1'] = $attempt($qc, $u['s1'], $essay);
$m['attempts']['C']['s2'] = $attempt($qc, $u['s2'], $essay);
// Manually grade s1's essay only; s2 stays "requires grading".
$ao = \mod_quiz\quiz_attempt::create($m['attempts']['C']['s1']);
$transaction = $DB->start_delegated_transaction();
$ao->get_question_usage()->manual_grade(2, 'CANARY-ESSAYCOMMENT', 4, FORMAT_HTML);
question_engine::save_questions_usage_by_activity($ao->get_question_usage());
$DB->set_field('quiz_attempts', 'sumgrades', $ao->get_question_usage()->get_total_mark(), ['id' => $ao->get_attemptid()]);
$transaction->allow_commit();
\mod_quiz\quiz_settings::create($qc->id)->get_grade_calculator()->recompute_final_grade($u['s1']->id);

// Close all quizzes (attempts were made while open).
foreach ([$qa, $qb, $qc] as $quiz) {
    $DB->set_field('quiz', 'timeclose', $now - 3600, ['id' => $quiz->id]);
    $DB->set_field('quiz', 'timeopen', $now - 7 * 86400, ['id' => $quiz->id]);
}
// Hide Quiz B grade item (unreleased grade, BR-010).
$gi = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $qb->id, 'courseid' => $course->id]);
$gi->set_hidden(1);

// Overrides on Quiz A: user override for s2 (later close, longer limit), group override for G2.
$qzgen->create_override(['quiz' => $qa->id, 'userid' => $u['s2']->id, 'timeclose' => $now + 7 * 86400, 'timelimit' => 3600]);
$qzgen->create_override(['quiz' => $qa->id, 'groupid' => $g2->id, 'timeclose' => $now + 2 * 86400]);
$m['overrides'] = ['quizA_user_s2_timeclose' => $now + 7 * 86400, 'quizA_user_s2_timelimit' => 3600, 'quizA_group_G2_timeclose' => $now + 2 * 86400];

// Assignments.
$asgen = $gen->get_plugin_generator('mod_assign');
$mkassign = function (string $name, array $extra) use ($gen, $course, $now) {
    return $gen->create_module('assign', array_merge([
        'course' => $course->id, 'name' => $name, 'grade' => 100,
        'allowsubmissionsfromdate' => $now - 10 * 86400, 'duedate' => $now - 2 * 86400,
        'cutoffdate' => $now - 86400, 'gradingduedate' => $now + 86400,
        'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 1,
        'assignsubmission_file_maxfiles' => 5, 'assignsubmission_file_maxsizebytes' => 1048576,
        'assignfeedback_comments_enabled' => 1, 'submissiondrafts' => 0,
    ], $extra));
};
$ax = $mkassign('Assign X rubric released', []);
$ay = $mkassign('Assign Y marking workflow unreleased', ['markingworkflow' => 1]);
$m['assigns'] = ['X' => ['id' => (int)$ax->id, 'cmid' => (int)$ax->cmid], 'Y' => ['id' => (int)$ay->id, 'cmid' => (int)$ay->cmid]];

// Rubric on X: criterion descriptions + level definitions carry canaries; hide descriptions from students.
$rgen = $gen->get_plugin_generator('gradingform_rubric');
$xctx = context_module::instance($ax->cmid);
$controller = $rgen->create_instance($xctx, 'mod_assign', 'submissions', 'Rubric X', 'CANARY-RUBRICDESC', [
    'CANARY-CRIT-Clarity' => ['CANARY-LVL-poor' => 0, 'CANARY-LVL-ok' => 5, 'CANARY-LVL-good' => 10],
    'CANARY-CRIT-Method'  => ['CANARY-LVL-none' => 0, 'CANARY-LVL-some' => 5, 'CANARY-LVL-full' => 10],
]);
$def = $controller->get_definition();
$opts = json_decode($def->options ?? '{}', true) ?: [];
$opts['showdescriptionstudent'] = 0;
$opts['showdescriptionteacher'] = 1;
$DB->set_field('grading_definitions', 'options', json_encode($opts), ['id' => $def->id]);
$m['rubric_definitionid'] = (int)$def->id;

// Submissions with a file; relative path from dirroot to a temp file.
$tmp = $CFG->tempdir . '/oq025_submission.txt';
file_put_contents($tmp, "VISIBLE-FILE-CONTENT student submission\n");
$rel = str_repeat('../', substr_count(trim($CFG->dirroot, '/'), '/') + 1) . ltrim($tmp, '/');
foreach (['X' => $ax, 'Y' => $ay] as $k => $as) {
    foreach (['s1', 's2'] as $s) {
        $asgen->create_submission([
            'userid' => $u[$s]->id, 'cmid' => $as->cmid, 'status' => 'submitted',
            'onlinetext' => "VISIBLE-ONLINETEXT-$k-$s", 'file' => $rel,
        ]);
    }
}
// Extension for s2 on X.
$asgen->create_extension(['userid' => $u['s2']->id, 'cmid' => $ax->cmid, 'extensionduedate' => $now + 3 * 86400]);
$m['extensions'] = ['assignX_s2' => $now + 3 * 86400];

// Grade X for s1 with the rubric + feedback comment (released).
[$c1, $cmx] = get_course_and_cm_from_cmid($ax->cmid, 'assign');
$assignx = new assign($xctx, $cmx, $c1);
$gradex = $assignx->get_user_grade($u['s1']->id, true);
$data = new stdClass();
$data->attemptnumber = -1;
$data->sendstudentnotifications = 0;
$data->advancedgrading = $rgen->get_submitted_form_data($controller, (int)$gradex->id, [
    'CANARY-CRIT-Clarity' => ['score' => 10, 'remark' => 'VISIBLE-REMARK-clarity'],
    'CANARY-CRIT-Method'  => ['score' => 5, 'remark' => 'VISIBLE-REMARK-method'],
]);
$data->assignfeedbackcomments_editor = ['text' => 'VISIBLE-FEEDBACK-X-s1', 'format' => FORMAT_HTML];
$assignx->save_grade($u['s1']->id, $data);

// Grade Y for s1 but leave it in marking (unreleased): feedback is a canary.
$yctx = context_module::instance($ay->cmid);
[$c2, $cmy] = get_course_and_cm_from_cmid($ay->cmid, 'assign');
$assigny = new assign($yctx, $cmy, $c2);
$datay = new stdClass();
$datay->attemptnumber = -1;
$datay->sendstudentnotifications = 0;
$datay->grade = 73;
$datay->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_INMARKING;
$datay->assignfeedbackcomments_editor = ['text' => 'CANARY-UNRELEASED-FEEDBACK-Y', 'format' => FORMAT_HTML];
$assigny->save_grade($u['s1']->id, $datay);

echo json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
