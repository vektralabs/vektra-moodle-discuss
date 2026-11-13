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
 * Legacy callbacks.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the student container before the footer (Moodle 4.3).
 *
 * On 4.4+ core does not call this function: db/hooks.php registers the replacing hook.
 *
 * @return string
 */
function local_vektradiscuss_before_footer(): string {
    return \local_vektradiscuss\local\injector::inject();
}

/**
 * Add the assessment discussion section to the quiz and assignment settings form.
 *
 * @param moodleform_mod $formwrapper
 * @param MoodleQuickForm $mform
 */
function local_vektradiscuss_coursemodule_standard_elements($formwrapper, $mform): void {
    \local_vektradiscuss\local\form\settings_section::extend($formwrapper, $mform);
}

/**
 * Clear the write-only texts when the form is shown again.
 *
 * @param moodleform_mod $formwrapper
 * @param MoodleQuickForm $mform
 */
function local_vektradiscuss_coursemodule_definition_after_data($formwrapper, $mform): void {
    \local_vektradiscuss\local\form\settings_section::after_data($formwrapper, $mform);
}

/**
 * Check the section's fields (a mirror of Vektra's rules, for field-level messages).
 *
 * @param moodleform_mod $formwrapper
 * @param array $data
 * @return array Errors by element name.
 */
function local_vektradiscuss_coursemodule_validation($formwrapper, $data): array {
    return \local_vektradiscuss\local\form\settings_section::validate((array) $data);
}

/**
 * Send the changed discussion settings to Vektra once the activity is saved.
 *
 * @param stdClass $data
 * @param stdClass $course
 * @return stdClass
 */
function local_vektradiscuss_coursemodule_edit_post_actions($data, $course) {
    return \local_vektradiscuss\local\form\settings_section::save($data, $course);
}

/**
 * Add the "Discussions" page to the settings of a quiz or an assignment, for teachers who see transcripts.
 *
 * @param settings_navigation $settingsnav
 * @param context $context
 */
function local_vektradiscuss_extend_settings_navigation($settingsnav, $context): void {
    global $PAGE;
    if (!$context instanceof context_module || !get_config('local_vektradiscuss', 'enabled')) {
        return;
    }
    $cm = $PAGE->cm;
    if (
        !$cm || (int) $cm->id !== (int) $context->instanceid
            || !in_array($cm->modname, \local_vektradiscuss\local\teacher_api::ACTIVITY_TYPES, true)
            || !has_capability('local/vektradiscuss:viewtranscripts', $context)
    ) {
        return;
    }
    $modulenode = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    if (!$modulenode) {
        return;
    }
    $modulenode->add(
        get_string('teacher_heading', 'local_vektradiscuss'),
        new moodle_url('/local/vektradiscuss/teacher.php', ['cmid' => $cm->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_vektradiscuss_teacher',
        new pix_icon('i/report', '')
    );
}
