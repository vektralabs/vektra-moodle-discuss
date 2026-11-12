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
$string['charcounter_count'] = '{$a->count} of {$a->max} characters';
$string['charcounter_over'] = '{$a->count} of {$a->max} characters: too long';
$string['error_key_duplicate'] = 'The mint key and the teacher key must be two different keys.';
$string['error_key_is_adminkey'] = 'This is the Vektra AI Assistant block admin key. Use a dedicated key issued for this plugin; an admin key must never be stored here.';
$string['error_uiurl_origin'] = 'Enter an origin only: scheme, host and optional port, without path, query or credentials.';
$string['error_uiurl_scheme'] = 'The discussion UI origin must use https.';
$string['form_active_sessions'] = '{$a} discussion(s) in progress. Changes apply to new discussions; running ones keep their settings.';
$string['form_attest'] = 'I confirm that no student has time or an extension beyond this date. I understand that later extensions or overrides will not be detected.';
$string['form_availableuntil'] = 'Discussion available until';
$string['form_availableuntil_help'] = 'After this date no new discussion can start. Leave it disabled for no end date. It must be after the opening date, grace period included.';
$string['form_cap'] = 'Time limit (minutes)';
$string['form_cap_help'] = 'Length of one discussion, from 5 to 120 minutes. Required to enable the discussion.';
$string['form_check_stale'] = 'Vektra is checking your role in this activity. Try again in a few seconds.';
$string['form_code_activity_not_configured'] = '{$a->field}: the discussion of this activity is not configured yet.';
$string['form_code_assignment_without_cutoff'] = '{$a->field}: the assignment needs a cut-off date before the discussion can be enabled.';
$string['form_code_before_close'] = '{$a->field}: the date must not be before the activity\'s close date.';
$string['form_code_close_unreadable'] = '{$a->field}: Vektra cannot read the activity\'s close date right now; try again later.';
$string['form_code_domain_not_allowed'] = '{$a->field}: an address is outside the allowed domains ({$a->domains}).';
$string['form_code_empty'] = '{$a->field}: the text is empty.';
$string['form_code_fallback_not_needed'] = '{$a->field}: an opening date is not needed for this activity.';
$string['form_code_invalid'] = '{$a->field}: the value was refused.';
$string['form_code_invalid_address'] = '{$a->field}: an address is not valid.';
$string['form_code_lms_facts_unreadable'] = '{$a->field}: Vektra cannot read the activity\'s settings from Moodle right now; try again later.';
$string['form_code_lms_rubric_missing'] = '{$a->field}: the assignment has no Moodle rubric.';
$string['form_code_marking_guide'] = '{$a->field}: assignments graded with a marking guide are not supported.';
$string['form_code_no_close_date'] = '{$a->field}: the activity has no close date.';
$string['form_code_not_after_opening'] = '{$a->field}: the end date must be after the opening date, grace period included.';
$string['form_code_not_before_end'] = '{$a->field}: the opening date must be before the discussion\'s end date.';
$string['form_code_not_for_activity_type'] = '{$a->field}: not available for this type of activity.';
$string['form_code_null_not_allowed'] = '{$a->field}: a value is required.';
$string['form_code_only_later'] = '{$a->field}: after the first discussion the opening date can only move later.';
$string['form_code_opening_unknown'] = '{$a->field}: the opening date is not known yet (close date missing or unreadable).';
$string['form_code_out_of_range'] = '{$a->field}: out of range ({$a->range}).';
$string['form_code_privacy_unset'] = '{$a->field}: the site\'s privacy settings are not configured in Vektra; contact the site administrator.';
$string['form_code_quiz_without_close_date'] = '{$a->field}: the quiz needs a close date before the discussion can be enabled.';
$string['form_code_rag_unavailable'] = '{$a->field}: course material search is not available in this version.';
$string['form_code_required'] = '{$a->field}: required.';
$string['form_code_setup_check_red'] = '{$a->field}: the site\'s setup check is not green; contact the site administrator.';
$string['form_code_team_submission'] = '{$a->field}: group submissions are not supported.';
$string['form_code_timezone_required'] = '{$a->field}: the date needs a time zone.';
$string['form_code_too_long'] = '{$a->field}: too long ({$a->range}).';
$string['form_code_too_many'] = '{$a->field}: too many entries ({$a->range}).';
$string['form_enabled'] = 'Enable the discussion with the assistant';
$string['form_enabled_help'] = 'Students who completed the activity can discuss their attempt with the assistant, and the transcript is sent to the recipients. Vektra refuses to enable it when a requirement is missing (close date or cut-off, time limit, recipients, privacy settings, setup check); the reason is shown after saving.';
$string['form_err_attest'] = 'Tick the confirmation to set the opening date.';
$string['form_err_cap'] = 'Enter a whole number of minutes from {$a->min} to {$a->max}, or leave it empty.';
$string['form_err_conflict'] = 'The discussion settings were changed by someone else in the meantime. Nothing was saved: open the settings again and repeat the change.';
$string['form_err_opening_date'] = 'Choose the opening date to confirm.';
$string['form_err_recipients'] = 'Enter from 1 to {$a} valid email addresses, one per line.';
$string['form_err_recipients_required'] = 'Add at least one transcript recipient to enable the discussion.';
$string['form_err_rubric_missing'] = 'Paste the rubric, or choose another rubric source.';
$string['form_err_text_empty'] = 'The text is empty.';
$string['form_err_text_long'] = '{$a->length} characters: the limit is {$a->max}.';
$string['form_field_attestation'] = 'Opening date confirmation';
$string['form_field_available_until'] = 'Discussion available until';
$string['form_field_cap_minutes'] = 'Time limit';
$string['form_field_enabled'] = 'Enable the discussion';
$string['form_field_opening_at'] = 'Opening date';
$string['form_field_pasted_rubric'] = 'Rubric';
$string['form_field_rag_enabled'] = 'Course material search';
$string['form_field_rubric_source'] = 'Rubric source';
$string['form_field_show_solution'] = 'Show the solution';
$string['form_field_solution'] = 'Solution';
$string['form_field_transcript_recipients'] = 'Transcript recipients';
$string['form_header'] = 'Discussion with the assistant';
$string['form_loginas'] = 'The discussion settings are not available while logged in as another user.';
$string['form_new_activity'] = 'Save the activity, then open its settings again to configure and enable the discussion with the assistant.';
$string['form_not_an_editor'] = 'Vektra does not count you among the editors of this activity, so its discussion settings cannot be changed from your account.';
$string['form_not_configured'] = 'The discussion with the assistant is not configured on this site (teacher key missing). Contact the site administrator.';
$string['form_opening'] = 'Opening date';
$string['form_opening_activity_close'] = '{$a} (from the activity\'s close date)';
$string['form_opening_attested'] = '{$a} (confirmed by a teacher)';
$string['form_opening_needed'] = 'Vektra cannot read every closing date of this activity (overrides or grace period). Set the date from which discussions can start, and confirm it.';
$string['form_opening_none'] = 'Not known yet';
$string['form_opening_saved'] = 'The opening date was saved.';
$string['form_openingat'] = 'Discussions open from';
$string['form_rag'] = 'Course material search';
$string['form_rag_locked'] = 'Off. Not available in this version.';
$string['form_recipients'] = 'Transcript recipients';
$string['form_recipients_add'] = 'Set the recipients';
$string['form_recipients_list'] = 'Recipient addresses';
$string['form_recipients_list_help'] = 'From 1 to 3 email addresses, one per line, that receive each transcript. The addresses are stored in Vektra and never shown again here. The site allows only some domains: an address outside them is refused when you save.';
$string['form_recipients_replace'] = 'Replace the recipients';
$string['form_recipients_set'] = '{$a} set (not shown)';
$string['form_recipients_unset'] = 'None set';
$string['form_refused'] = 'The discussion settings were not saved:';
$string['form_rubric'] = 'Rubric';
$string['form_rubric_text'] = 'Rubric text';
$string['form_rubric_text_help'] = 'The rubric the assistant uses, as plain text, up to 20,000 characters. It is stored in Vektra and never shown again here.';
$string['form_rubricsource'] = 'Rubric source';
$string['form_rubricsource_help'] = 'Where the assistant takes the rubric from: none, a rubric pasted here, or (assignments only) the assignment\'s Moodle rubric.';
$string['form_rubricsource_lms'] = 'The assignment\'s Moodle rubric';
$string['form_rubricsource_none'] = 'None';
$string['form_rubricsource_pasted'] = 'Pasted here';
$string['form_saved'] = 'The discussion settings were saved.';
$string['form_secret_add'] = 'Enter it';
$string['form_secret_remove'] = 'Remove it';
$string['form_secret_replace'] = 'Replace it';
$string['form_secret_set'] = 'Set (not shown)';
$string['form_secret_unset'] = 'Not set';
$string['form_showsolution'] = 'Let the assistant show the solution';
$string['form_showsolution_help'] = 'Off by default: the assistant uses the solution to guide the discussion but does not reveal it. On: it may show it to the student.';
$string['form_solution'] = 'Solution';
$string['form_solution_text'] = 'Solution text';
$string['form_solution_text_help'] = 'The expected solution, as plain text, up to 20,000 characters. It is stored in Vektra, readable only by the assistant, and never shown again here.';
$string['form_unreachable'] = 'Vektra cannot be reached: the discussion settings cannot be changed now. The rest of the activity can be saved as usual.';
$string['form_unreachable_save'] = 'Vektra could not be reached: the discussion settings were not saved. The rest of the activity was saved.';
$string['form_warning_review_options'] = 'The quiz review options hide the attempt\'s content from students: the discussion needs the student to see their answers.';
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
$string['settings_teacherkey_desc'] = 'Key used for the teacher pages, the teachers\' transcript downloads and the setup check. Students download their own transcripts with the mint key.';
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
$string['transcript_link'] = 'Transcript of session {$a} (download)';
$string['transcript_not_ready'] = 'The transcript is not ready yet. Try again in a minute.';
$string['transcript_pending'] = 'Transcript of session {$a}: not available at the moment.';
$string['transcript_purged'] = 'Transcript of session {$a}: no longer available (retention period expired).';
$string['transcript_unavailable'] = 'The transcript cannot be downloaded right now. Try again later.';
$string['transcripts_heading'] = 'Your transcripts';
$string['vektradiscuss:configure'] = 'Configure the assessment discussion for an activity';
$string['vektradiscuss:manage'] = 'Manage assessment discussions (reset, terminate, extra time, opening date, resend)';
$string['vektradiscuss:setupcheck'] = 'Run the assessment discussion setup check';
$string['vektradiscuss:start'] = 'Start an assessment discussion on one\'s own attempt';
$string['vektradiscuss:viewowntranscript'] = 'View one\'s own assessment discussion transcript';
$string['vektradiscuss:viewtranscripts'] = 'View and download assessment discussion transcripts';
