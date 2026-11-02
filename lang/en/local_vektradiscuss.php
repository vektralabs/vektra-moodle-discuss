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

$string['error_key_duplicate'] = 'The mint key and the teacher key must be two different keys.';
$string['error_key_is_adminkey'] = 'This is the Vektra AI Assistant block admin key. Use a dedicated key issued for this plugin; an admin key must never be stored here.';
$string['error_uiurl_origin'] = 'Enter an origin only: scheme, host and optional port, without path, query or credentials.';
$string['error_uiurl_scheme'] = 'The discussion UI origin must use https.';
$string['pluginname'] = 'Vektra assessment discussion';
$string['privacy:metadata:vektra_assess'] = 'The plugin sends the data needed to run and record an assessment discussion to the Vektra assessment service, which stores it under its own retention rules.';
$string['privacy:metadata:vektra_assess:attempt'] = 'The quiz attempt, including answers, grades and feedback.';
$string['privacy:metadata:vektra_assess:cmid'] = 'The id of the activity (course module).';
$string['privacy:metadata:vektra_assess:conversation'] = 'The messages exchanged by the student with the discussion assistant.';
$string['privacy:metadata:vektra_assess:courseid'] = 'The id of the course of the activity.';
$string['privacy:metadata:vektra_assess:submission'] = 'The assignment submission, including grades, rubric and feedback.';
$string['privacy:metadata:vektra_assess:transcript'] = 'The transcript of the discussion delivered to the teacher.';
$string['privacy:metadata:vektra_assess:userid'] = 'The Moodle user id of the student or teacher.';
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
$string['settings_uiurl_desc'] = 'Optional. Origin (scheme, host and optional port, no path) that serves the discussion UI, ideally distinct from the Moodle origin. Empty means the origin of the Vektra public URL. Must use https.';
$string['vektradiscuss:configure'] = 'Configure the assessment discussion for an activity';
$string['vektradiscuss:manage'] = 'Manage assessment discussions (reset, terminate, extra time, opening date, resend)';
$string['vektradiscuss:setupcheck'] = 'Run the assessment discussion setup check';
$string['vektradiscuss:start'] = 'Start an assessment discussion on one\'s own attempt';
$string['vektradiscuss:viewowntranscript'] = 'View one\'s own assessment discussion transcript';
$string['vektradiscuss:viewtranscripts'] = 'View and download assessment discussion transcripts';
