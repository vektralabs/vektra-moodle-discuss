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
 * English language strings for local_vektradiscuss.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['button_resume'] = 'Resume';
$string['button_start'] = 'Discuss this assessment';
$string['button_start_session'] = 'Discuss this assessment (session #{$a})';
$string['error_key_duplicate'] = 'The mint key and the teacher key must be two different keys.';
$string['error_key_is_adminkey'] = 'This is the Vektra AI Assistant block admin key. Use a dedicated key issued for this plugin; an admin key must never be stored here.';
$string['error_uiurl_origin'] = 'Enter an origin only: scheme, host and optional port, without path, query or credentials.';
$string['error_uiurl_scheme'] = 'The discussion UI origin must use https.';
$string['iframe_title'] = 'Assessment discussion';
$string['pluginname'] = 'Vektra assessment discussion';
$string['pre_ai_disclosure'] = 'You will talk with an AI assistant, not with a person. Its answers may contain errors.';
$string['pre_cancel'] = 'Not now';
$string['pre_duration'] = 'Duration: {$a} minutes. If this does not match what you were told, contact your teacher before starting.';
$string['pre_enrolment'] = 'Access to the discussion and to its transcript in Moodle depends on your enrolment in this course: download a copy before the end of the course.';
$string['pre_file_partial'] = 'partly readable';
$string['pre_file_readable'] = 'readable';
$string['pre_file_unreadable'] = 'cannot be read';
$string['pre_files_heading'] = 'Your submitted files';
$string['pre_files_note'] = 'If an important file cannot be read, you can discuss it at the oral exam.';
$string['pre_limits'] = 'Limits: at most {$a->chars} characters per message, {$a->rate} messages per minute.';
$string['pre_material_notused'] = 'Course material: not used.';
$string['pre_material_used'] = 'Course material: used.';
$string['pre_no_grade_change'] = 'The discussion does not change your grade.';
$string['pre_one_session'] = 'You have one session: once started it cannot be repeated.';
$string['pre_previous_sessions'] = 'Previous sessions on this assessment: {$a}.';
$string['pre_privacy_link'] = 'Privacy notice';
$string['pre_privacy_summary'] = 'The conversation is processed by the Vektra assessment service and kept under its retention rules.';
$string['pre_privacy_version'] = 'version {$a}';
$string['pre_recipients'] = 'The transcript will be sent to:';
$string['pre_start'] = 'Start';
$string['pre_title'] = 'Before you start';
$string['pre_transcript_copy'] = 'The transcript is delivered to your teacher, and you receive an identical copy.';
$string['preparing'] = 'Preparing the discussion…';
$string['privacy:metadata:vektra_assess'] = 'The plugin sends the data needed to run and record an assessment discussion to the Vektra assessment service, which stores it under its own retention rules.';
$string['privacy:metadata:vektra_assess:attempt'] = 'The quiz attempt, including answers, grades and feedback.';
$string['privacy:metadata:vektra_assess:cmid'] = 'The id of the activity (course module).';
$string['privacy:metadata:vektra_assess:conversation'] = 'The messages exchanged by the student with the discussion assistant.';
$string['privacy:metadata:vektra_assess:courseid'] = 'The id of the course of the activity.';
$string['privacy:metadata:vektra_assess:submission'] = 'The assignment submission, including grades, rubric and feedback.';
$string['privacy:metadata:vektra_assess:transcript'] = 'The transcript of the discussion delivered to the teacher.';
$string['privacy:metadata:vektra_assess:userid'] = 'The Moodle user id of the student or teacher.';
$string['role_editingteacher'] = 'Teacher';
$string['role_manager'] = 'Manager';
$string['role_teacher'] = 'Non-editing teacher';
$string['settings_apiurl_shared'] = 'Vektra API URL (from the block)';
$string['settings_connection'] = 'Connection';
$string['settings_connection_desc'] = 'The Vektra API address is shared with the Vektra AI Assistant block; change it in the block settings.';
$string['settings_enabled'] = 'Enable assessment discussions';
$string['settings_enabled_desc'] = 'Site kill switch. While disabled the plugin renders nothing and makes no request to Vektra. Leave disabled until the keys below are set and the setup check passes.';
$string['settings_keys'] = 'Service keys';
$string['settings_keys_desc'] = 'Two separate, narrow keys issued by the Vektra operator. Never use the Vektra AI Assistant block admin key here, and never the same key for both roles.';
$string['settings_mintkey'] = 'Mint key (assess:mint)';
$string['settings_mintkey_desc'] = 'Key used to mint and start student discussions.';
$string['settings_publicurl_shared'] = 'Vektra public URL (from the block)';
$string['settings_shared_link'] = '{$a->value} (<a href="{$a->url}">change in the block settings</a>)';
$string['settings_shared_notset'] = 'Not set';
$string['settings_siteid'] = 'Site identifier';
$string['settings_siteid_desc'] = 'The identifier of this Moodle site, issued by the Vektra operator together with the keys.';
$string['settings_teacherkey'] = 'Teacher key (assess:teacher)';
$string['settings_teacherkey_desc'] = 'Key used for teacher pages, transcript downloads and the setup check.';
$string['settings_uiurl'] = 'Discussion UI origin';
$string['settings_uiurl_desc'] = 'Required for the student entry point. Origin (scheme, host and optional port, no path) that serves the discussion UI, as given by the Vektra operator: its own host, distinct from the Moodle origin and from the Vektra API host. Must use https. Empty means no entry point is shown.';
$string['state_active'] = 'A discussion is in progress.';
$string['state_active_minutes'] = 'A discussion is in progress: {$a} minutes left.';
$string['state_closed'] = 'The discussion of this assessment has ended.';
$string['state_closed_copy'] = 'We recommend that you download a copy of the transcript.';
$string['state_closed_date'] = 'The discussion of this assessment ended on {$a}.';
$string['state_eligible'] = 'You can discuss this assessment with the assistant.';
$string['state_ended'] = 'The discussion is no longer available.';
$string['state_ended_date'] = 'The discussion is no longer available since {$a}.';
$string['state_loginas'] = 'Not available while logged in as another user.';
$string['state_not_enabled'] = 'The assessment discussion is not enabled for this activity.';
$string['state_not_graded'] = 'Available when the grade is published.';
$string['state_not_open'] = 'Available after the activity has definitively closed.';
$string['state_not_open_attempt_in_progress'] = 'Available when your attempt is finished and the activity has definitively closed.';
$string['state_not_open_close_unreadable'] = 'Not available yet: the closing date of the activity cannot be confirmed. Contact your teacher.';
$string['state_not_open_date'] = 'Available from {$a}.';
$string['state_not_open_no_close_date'] = 'Available after the activity has definitively closed.';
$string['state_not_selected'] = 'The discussion concerns attempt #{$a}. Open that attempt to start it.';
$string['state_reset_granted'] = 'Your teacher has granted a new discussion session.';
$string['state_unavailable'] = 'The discussion is temporarily unavailable. Try again later.';
$string['student_heading'] = 'Assessment discussion';
$string['vektradiscuss:configure'] = 'Configure the assessment discussion for an activity';
$string['vektradiscuss:manage'] = 'Manage assessment discussions (reset, terminate, extra time, opening date, resend)';
$string['vektradiscuss:setupcheck'] = 'Run the assessment discussion setup check';
$string['vektradiscuss:start'] = 'Start an assessment discussion on one\'s own attempt';
$string['vektradiscuss:viewowntranscript'] = 'View one\'s own assessment discussion transcript';
$string['vektradiscuss:viewtranscripts'] = 'View and download assessment discussion transcripts';
