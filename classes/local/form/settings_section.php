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
 * The assessment discussion section of the quiz and assignment settings form.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local\form;

use local_vektradiscuss\local\config;
use local_vektradiscuss\local\config_reveal;
use local_vektradiscuss\local\http\assess_response;
use local_vektradiscuss\local\student_endpoint;
use local_vektradiscuss\local\teacher_api;

/**
 * Renders, checks and saves the discussion settings of an activity (REQ-077, REQ-091, BR-013).
 *
 * Vektra holds the configuration and validates it; the section is a client:
 * - load before write: the configuration is read when the form renders; without a successful read the
 *   section is frozen and the save sends nothing, so an outage while a teacher edits the activity title
 *   can never reset the discussion settings;
 * - only the changed fields are sent, with the version read on render (`expected_version`);
 * - solution, pasted rubric and recipients are write-only: Vektra never returns them, the form never
 *   shows them back and they leave the submitted data right after the save;
 * - course material search (FEAT-028) is off by default; turning it on needs the teacher's confirmation, ticked
 *   again on every change and never pre-ticked, and Vektra decides whether it is available.
 */
class settings_section {
    /** @var string Prefix of every element of the section. */
    public const PREFIX = 'vektradiscuss_';

    /** @var int Upper bound of the solution and the pasted rubric, in code points (BR-013). */
    public const MAX_TEXT = 20000;

    /** @var int Bounds of the time cap, in minutes (BR-013). */
    public const CAP_MIN = 5;

    /** @var int Upper bound of the time cap, in minutes. */
    public const CAP_MAX = 120;

    /** @var int Maximum number of transcript recipients. */
    public const MAX_RECIPIENTS = 3;

    /** @var string[] Write-only text fields: element suffix => Vektra field. */
    private const SECRETS = ['solution' => 'solution', 'rubric' => 'pasted_rubric'];

    /** @var string[] The course material search fields: saved in their own request (FEAT-028). */
    private const RAG_FIELDS = ['rag_enabled', 'rag_attested'];

    /** @var string[] Field error codes that carry a closed reason with its own sentence. */
    private const REASONED_CODES = ['rag_unavailable'];

    /**
     * Add the section to the form (coursemodule_standard_elements).
     *
     * @param \moodleform_mod $wrapper
     * @param \MoodleQuickForm $mform
     * @param teacher_api|null $api
     */
    public static function extend(\moodleform_mod $wrapper, \MoodleQuickForm $mform, ?teacher_api $api = null): void {
        global $USER, $PAGE;

        $type = (string) ($wrapper->get_current()->modulename ?? '');
        if (!in_array($type, teacher_api::ACTIVITY_TYPES, true)) {
            return;
        }
        $config = new config();
        if (!$config->is_enabled()) {
            return;
        }
        $cm = $wrapper->get_coursemodule();
        $course = $wrapper->get_course();
        $context = $cm ? \context_module::instance($cm->id) : \context_course::instance($course->id);
        if (!has_capability('local/vektradiscuss:configure', $context)) {
            return;
        }

        $mform->addElement('header', self::PREFIX . 'header', get_string('form_header', 'local_vektradiscuss'));
        if (student_endpoint::is_loginas()) {
            self::note($mform, 'form_loginas');
            return;
        }
        if (!$config->key_usable('teacher')) {
            self::note($mform, 'form_not_configured');
            return;
        }
        if (!$cm) {
            // A new activity: Vektra reads the close date and the review options from Moodle, so nothing can
            // be checked before the activity exists (REQ-077 defaults: everything off).
            self::note($mform, 'form_new_activity');
            return;
        }

        // On a submission the form is built again: it reuses the view it was rendered with, so a Vektra outage
        // between render and save cannot drop the teacher's changes, and no second GET is made.
        $view = self::posted_view();
        if ($view === null) {
            $api = $api ?? new teacher_api();
            // Retried once inside teacher_api when Vektra asks for it.
            $response = $api->get_config((int) $cm->id, (int) $course->id, $type, (int) $USER->id);
            // Vektra refreshes its LMS facts while the teacher edits, so the save finds them fresh.
            $api->refresh_gate((int) $cm->id, (int) $course->id, $type, (int) $USER->id);
            $view = self::view($response);
            if ($view === null) {
                self::note($mform, self::refusal($response, 'form_unreachable'));
                return;
            }
        }

        $canreveal = config_reveal::can_reveal(\context_module::instance($cm->id));
        self::add_fields($mform, $type, $view, $type === 'quiz' && self::review_hides_answers((int) $cm->instance), $canreveal);
        $PAGE->requires->js_call_amd('local_vektradiscuss/charcounter', 'init', [
            [self::PREFIX . 'solution', self::PREFIX . 'rubric'],
            self::MAX_TEXT,
        ]);
        if ($canreveal) {
            $PAGE->requires->js_call_amd('local_vektradiscuss/reveal', 'init', [(int) $cm->id]);
            $PAGE->requires->js_call_amd('local_vektradiscuss/secretedit', 'init', [(int) $cm->id]);
        }
        // A save Vektra asks to retry waits a few seconds on the server: say so under the button.
        $PAGE->requires->js_call_amd('local_vektradiscuss/pending', 'init', [
            '#id_' . self::PREFIX . 'header',
            get_string('teacher_checking', 'local_vektradiscuss'),
        ]);
    }

    /**
     * Never show write-only text back (coursemodule_definition_after_data): on a redisplay after a
     * validation error the submitted solution and rubric are cleared.
     *
     * @param \moodleform_mod $wrapper
     * @param \MoodleQuickForm $mform
     */
    public static function after_data(\moodleform_mod $wrapper, \MoodleQuickForm $mform): void {
        foreach (array_keys(self::SECRETS) as $name) {
            if ($mform->elementExists(self::PREFIX . $name)) {
                $mform->getElement(self::PREFIX . $name)->setValue('');
            }
        }
        // The course material confirmation is ticked again on every page, even after a validation error.
        if ($mform->elementExists(self::PREFIX . 'ragattest')) {
            $mform->getElement(self::PREFIX . 'ragattest')->setValue(0);
        }
    }

    /**
     * Field-level checks mirroring BR-013 (coursemodule_validation). Advisory: Vektra decides on save.
     *
     * @param array $data Submitted data.
     * @return array Errors by element name.
     */
    public static function validate(array $data): array {
        if (!isset($data[self::PREFIX . 'version'])) {
            return [];
        }
        $errors = [];
        $p = self::PREFIX;

        $cap = trim((string) ($data[$p . 'cap'] ?? ''));
        if ($cap !== '' && (!ctype_digit($cap) || (int) $cap < self::CAP_MIN || (int) $cap > self::CAP_MAX)) {
            $errors[$p . 'cap'] = get_string(
                'form_err_cap',
                'local_vektradiscuss',
                ['min' => self::CAP_MIN, 'max' => self::CAP_MAX]
            );
        }

        foreach (array_keys(self::SECRETS) as $name) {
            if (!empty($data[$p . $name . '_replace'])) {
                $length = self::text_length((string) ($data[$p . $name] ?? ''));
                if ($length === 0) {
                    $errors[$p . $name] = get_string('form_err_text_empty', 'local_vektradiscuss');
                } else if ($length > self::MAX_TEXT) {
                    $errors[$p . $name] = get_string(
                        'form_err_text_long',
                        'local_vektradiscuss',
                        ['max' => self::MAX_TEXT, 'length' => $length]
                    );
                }
            }
        }
        $orig = self::orig($data);
        if (
            ($data[$p . 'rubricsource'] ?? 'none') === 'pasted' && empty($data[$p . 'rubric_replace'])
                && (!($orig['rubric_set'] ?? false) || !empty($data[$p . 'rubric_remove']))
        ) {
            $errors[$p . 'rubricsource'] = get_string('form_err_rubric_missing', 'local_vektradiscuss');
        }

        if (!empty($data[$p . 'recipients_replace'])) {
            $addresses = self::recipients((string) ($data[$p . 'recipients'] ?? ''));
            if ($addresses === null) {
                $errors[$p . 'recipients'] = get_string('form_err_recipients', 'local_vektradiscuss', self::MAX_RECIPIENTS);
            }
        } else if (!empty($data[$p . 'enabled']) && empty($orig['recipients_count'])) {
            $errors[$p . 'recipients'] = get_string('form_err_recipients_required', 'local_vektradiscuss');
        }

        if (self::rag_requested($data, $orig) && empty($orig['rag_enabled']) && empty($data[$p . 'ragattest'])) {
            $errors[$p . 'ragattest'] = get_string('form_err_rag_attest', 'local_vektradiscuss');
        }

        if (array_key_exists($p . 'openingat', $data) || array_key_exists($p . 'attest', $data)) {
            $date = (int) ($data[$p . 'openingat'] ?? 0);
            $attest = !empty($data[$p . 'attest']);
            if ($date > 0 && !$attest) {
                $errors[$p . 'attest'] = get_string('form_err_attest', 'local_vektradiscuss');
            } else if ($attest && $date <= 0) {
                $errors[$p . 'openingat'] = get_string('form_err_opening_date', 'local_vektradiscuss');
            }
        }
        return $errors;
    }

    /**
     * Send the changes to Vektra (coursemodule_edit_post_actions), then drop the write-only values.
     *
     * Vektra's field errors cannot become form errors here (Moodle has saved the activity already): they
     * are shown as notifications naming each field, and Vektra's configuration stays as it was.
     *
     * @param \stdClass $data The saved module info.
     * @param \stdClass $course
     * @param teacher_api|null $api
     * @return \stdClass The module info, without the write-only values.
     */
    public static function save(\stdClass $data, \stdClass $course, ?teacher_api $api = null): \stdClass {
        global $USER;
        try {
            $p = self::PREFIX;
            $type = (string) ($data->modulename ?? '');
            $cmid = (int) ($data->coursemodule ?? 0);
            if (
                !isset($data->{$p . 'version'}) || $cmid <= 0 || !in_array($type, teacher_api::ACTIVITY_TYPES, true)
                    || !(new config())->is_enabled() || student_endpoint::is_loginas()
                    || !has_capability('local/vektradiscuss:configure', \context_module::instance($cmid))
            ) {
                return $data;
            }
            $api = $api ?? new teacher_api();
            $values = (array) $data;

            // The configuration first: the opening fallback needs a configured activity and is checked against
            // the end date saved here; it does not use the configuration's version.
            $fields = self::changes($values, $type);
            // Course material search goes in its own request, after the rest: Vektra may refuse it (operator flag,
            // eligibility unreadable, confirmation) and that refusal must not drop the other changes.
            $rag = array_intersect_key($fields, array_flip(self::RAG_FIELDS));
            $fields = array_diff_key($fields, $rag);
            $version = (int) $values[$p . 'version'];
            if ($fields) {
                // Retried once inside teacher_api when Vektra asks for it: otherwise the teacher's changes would be
                // lost with the submission.
                $response = $api->put_config($cmid, (int) $course->id, $type, (int) $USER->id, $version, $fields);
                if (!$response->ok()) {
                    // The opening fallback is checked against the configuration just saved: none was saved.
                    self::notify_failure($response);
                    return $data;
                }
                \core\notification::success(get_string('form_saved', 'local_vektradiscuss'));
                if (($fields['show_solution'] ?? false) === true) {
                    \core\notification::warning(get_string('form_showsolution_warning', 'local_vektradiscuss'));
                }
                $version = is_int($response->json['version'] ?? null) ? $response->json['version'] : -1;
            }
            if ($rag && $version >= 0) {
                $response = $api->put_config($cmid, (int) $course->id, $type, (int) $USER->id, $version, $rag);
                if ($response->ok()) {
                    \core\notification::success(get_string(
                        $rag['rag_enabled'] ? 'form_rag_saved_on' : 'form_rag_saved_off',
                        'local_vektradiscuss'
                    ));
                } else {
                    // Only the search failed: the other changes, if any, were saved in the first request.
                    self::notify_failure($response, true);
                }
            } else if ($rag) {
                \core\notification::error(get_string('form_rag_not_saved', 'local_vektradiscuss'));
            }
            if (!empty($values[$p . 'attest']) && (int) ($values[$p . 'openingat'] ?? 0) > 0) {
                $openingat = (int) $values[$p . 'openingat'];
                $opening = $api->put_opening_fallback($cmid, (int) $course->id, $type, (int) $USER->id, $openingat);
                if ($opening->ok()) {
                    \core\notification::success(get_string('form_opening_saved', 'local_vektradiscuss'));
                } else {
                    self::notify_failure($opening);
                }
            }
            return $data;
        } finally {
            foreach (['solution', 'rubric', 'recipients'] as $name) {
                unset($data->{self::PREFIX . $name});
            }
        }
    }

    /**
     * Whether the quiz's saved review options hide the student's answers when the discussion can happen: after
     * the close, or later while the quiz is open when it has no close date. Saved values: a change made in the
     * same form shows on the next load, and user or group overrides are left to Vektra's opening check.
     *
     * @param int $quizid
     * @return bool
     */
    public static function review_hides_answers(int $quizid): bool {
        global $DB;
        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        if (!$quiz) {
            return false;
        }
        $when = (int) $quiz->timeclose > 0
            ? \mod_quiz\question\display_options::AFTER_CLOSE
            : \mod_quiz\question\display_options::LATER_WHILE_OPEN;
        return !\mod_quiz\question\display_options::make_from_quiz($quiz, $when)->attempt;
    }

    /**
     * The fields that differ from what the form showed, in Vektra's names.
     *
     * @param array $values Submitted values.
     * @param string $type quiz or assign
     * @return array
     */
    public static function changes(array $values, string $type): array {
        $p = self::PREFIX;
        $orig = self::orig($values);
        $fields = [];

        $enabled = !empty($values[$p . 'enabled']);
        if ($enabled !== (bool) ($orig['enabled'] ?? false)) {
            $fields['enabled'] = $enabled;
        }
        $cap = trim((string) ($values[$p . 'cap'] ?? ''));
        $cap = $cap === '' ? null : (int) $cap;
        if ($cap !== ($orig['cap_minutes'] ?? null)) {
            $fields['cap_minutes'] = $cap;
        }
        $source = (string) ($values[$p . 'rubricsource'] ?? 'none');
        if (in_array($source, self::rubric_sources($type), true) && $source !== ($orig['rubric_source'] ?? 'none')) {
            $fields['rubric_source'] = $source;
        }
        $show = !empty($values[$p . 'showsolution']);
        if ($show !== (bool) ($orig['show_solution'] ?? false)) {
            $fields['show_solution'] = $show;
        }
        // Course material search: sent only when it changes; turning it on carries the teacher's confirmation.
        $rag = self::rag_requested($values, $orig);
        if ($rag !== (bool) ($orig['rag_enabled'] ?? false)) {
            $fields['rag_enabled'] = $rag;
            if ($rag) {
                $fields['rag_attested'] = !empty($values[$p . 'ragattest']);
            }
        }
        // The date selector has no seconds: compare whole minutes, or a stored value with seconds would always
        // look changed.
        $until = (int) ($values[$p . 'availableuntil'] ?? 0);
        $until = $until > 0 ? $until : null;
        $origuntil = is_int($orig['available_until'] ?? null) ? $orig['available_until'] : null;
        if (($until === null ? null : intdiv($until, 60)) !== ($origuntil === null ? null : intdiv($origuntil, 60))) {
            $fields['available_until'] = $until === null ? null : gmdate('Y-m-d\TH:i:s\Z', $until);
        }

        foreach (self::SECRETS as $name => $field) {
            if (!empty($values[$p . $name . '_replace'])) {
                $fields[$field] = (string) ($values[$p . $name] ?? '');
            } else if (!empty($values[$p . $name . '_remove'])) {
                $fields[$field] = null;
            }
        }
        if (!empty($values[$p . 'recipients_replace'])) {
            $addresses = self::recipients((string) ($values[$p . 'recipients'] ?? ''));
            if ($addresses !== null) {
                $fields['transcript_recipients'] = $addresses;
            }
        }
        return $fields;
    }

    /**
     * Whether the submitted form asks for course material search. For an activity Vektra has no configuration for,
     * the switch starts on but counts only when the discussion is turned on in the same save: otherwise saving the
     * activity for another reason would ask for the teacher's confirmation, or turn the search on unasked.
     *
     * @param array $values Submitted values.
     * @param array $orig {@see self::orig()}
     * @return bool
     */
    private static function rag_requested(array $values, array $orig): bool {
        $p = self::PREFIX;
        if (!($orig['configured'] ?? true) && empty($values[$p . 'enabled'])) {
            return (bool) ($orig['rag_enabled'] ?? false);
        }
        return !empty($values[$p . 'rag']);
    }

    /**
     * Addresses from the recipients field: one per line or comma separated, 1 to 3, each a valid email.
     *
     * @param string $text
     * @return string[]|null Null when the list is empty, too long or has an invalid address.
     */
    public static function recipients(string $text): ?array {
        // Duplicates collapse case-insensitively, as in Vektra.
        $addresses = [];
        foreach (array_filter(array_map('trim', preg_split('/[\s,;]+/', $text) ?: [])) as $address) {
            $addresses[\core_text::strtolower($address)] ??= $address;
        }
        $addresses = array_values($addresses);
        if (!$addresses || count($addresses) > self::MAX_RECIPIENTS) {
            return null;
        }
        foreach ($addresses as $address) {
            if (!validate_email($address)) {
                return null;
            }
        }
        return $addresses;
    }

    /**
     * Length as Vektra counts it: code points after NFC, without control characters other than newline and
     * tab (BR-013).
     *
     * @param string $text
     * @return int
     */
    public static function text_length(string $text): int {
        if (class_exists('\Normalizer')) {
            $normalised = \Normalizer::normalize($text, \Normalizer::FORM_C);
            $text = $normalised === false ? $text : $normalised;
        }
        $text = (string) preg_replace('/(?![\n\t])\p{Cc}/u', '', $text);
        return \core_text::strlen($text);
    }

    /**
     * The validated configuration view, or null when it cannot be used.
     *
     * @param assess_response $response
     * @return array|null
     */
    private static function view(assess_response $response): ?array {
        $json = $response->ok() ? $response->json : null;
        if (!is_array($json) || !is_int($json['version'] ?? null) || $json['version'] < 0) {
            return null;
        }
        return $json;
    }

    /**
     * The section's elements, filled from Vektra's view.
     *
     * @param \MoodleQuickForm $mform
     * @param string $type
     * @param array $view
     * @param bool $reviewhidden The quiz's saved review options hide the student's answers after the attempt.
     * @param bool $canreveal The user may read the saved texts back ({@see config_reveal::can_reveal()}).
     */
    private static function add_fields(
        \MoodleQuickForm $mform,
        string $type,
        array $view,
        bool $reviewhidden,
        bool $canreveal = false
    ): void {
        global $USER;
        $p = self::PREFIX;
        $s = fn(string $id, $a = null): string => get_string($id, 'local_vektradiscuss', $a);

        $cap = is_int($view['cap_minutes'] ?? null) ? $view['cap_minutes'] : null;
        $source = in_array($view['rubric_source'] ?? null, self::rubric_sources($type), true) ? $view['rubric_source'] : 'none';
        $orig = self::orig_from_view($view);
        $mform->addElement('hidden', $p . 'version', (int) $view['version']);
        $mform->setType($p . 'version', PARAM_INT);
        // The view the form was rendered with: the save compares against it, and a resubmission rebuilds the
        // form from it. Vektra stays authoritative: an edited value only changes what the plugin sends.
        $mform->addElement('hidden', $p . 'view', json_encode(self::keep($view)));
        $mform->setType($p . 'view', PARAM_RAW);

        $active = is_int($view['active_sessions'] ?? null) ? $view['active_sessions'] : 0;
        if ($active > 0) {
            $mform->addElement('static', $p . 'active', '', $s('form_active_sessions', $active));
        }
        if ($reviewhidden) {
            $mform->addElement('static', $p . 'reviewwarning', '', $s('form_warning_review_options'));
        }
        // Vektra's flag means the quiz detail is reduced (ARCH-070): Moodle's per-question feedback does not
        // reach the assistant, whatever the review options say.
        if ($type === 'quiz' && ($view['warnings']['review_options_hide_content'] ?? false) === true) {
            $mform->addElement('static', $p . 'quizfeedback', '', $s('form_note_quiz_feedback'));
        }

        $mform->addElement('advcheckbox', $p . 'enabled', $s('form_enabled'));
        $mform->setDefault($p . 'enabled', $orig['enabled'] ? 1 : 0);
        $mform->addHelpButton($p . 'enabled', 'form_enabled', 'local_vektradiscuss');

        $mform->addElement('text', $p . 'cap', $s('form_cap'), ['size' => 4]);
        $mform->setType($p . 'cap', PARAM_RAW_TRIMMED);
        $mform->setDefault($p . 'cap', $cap === null ? '' : (string) $cap);
        $mform->addHelpButton($p . 'cap', 'form_cap', 'local_vektradiscuss');

        $mform->addElement('date_time_selector', $p . 'availableuntil', $s('form_availableuntil'), ['optional' => true]);
        $mform->setDefault($p . 'availableuntil', $orig['available_until'] ?? 0);
        $mform->addHelpButton($p . 'availableuntil', 'form_availableuntil', 'local_vektradiscuss');

        self::add_opening($mform, $view);

        $options = [];
        foreach (self::rubric_sources($type) as $option) {
            $options[$option] = $s('form_rubricsource_' . $option);
        }
        $mform->addElement('select', $p . 'rubricsource', $s('form_rubricsource'), $options);
        $mform->setDefault($p . 'rubricsource', $source);
        $mform->addHelpButton($p . 'rubricsource', 'form_rubricsource', 'local_vektradiscuss');

        self::add_secret($mform, 'rubric', self::is_set($view['pasted_rubric_state'] ?? null), $canreveal);
        $mform->hideIf($p . 'rubric_replace', $p . 'rubricsource', 'neq', 'pasted');
        $mform->hideIf($p . 'rubric_state', $p . 'rubricsource', 'neq', 'pasted');

        self::add_secret($mform, 'solution', self::is_set($view['solution_state'] ?? null), $canreveal);

        $mform->addElement('advcheckbox', $p . 'showsolution', $s('form_showsolution'));
        $mform->setDefault($p . 'showsolution', $orig['show_solution'] ? 1 : 0);
        $mform->addHelpButton($p . 'showsolution', 'form_showsolution', 'local_vektradiscuss');
        // REQ-077: a warning while the option is on.
        $mform->addElement('static', $p . 'showsolutionwarning', '', $s('form_showsolution_warning'));
        $mform->hideIf($p . 'showsolutionwarning', $p . 'showsolution', 'notchecked');

        self::add_rag($mform, $view);

        // Recipients: write-only. When none is set, the field starts with the teacher's own address.
        $count = $orig['recipients_count'];
        $mform->addElement('static', $p . 'recipients_state', $s('form_recipients'), $count > 0
            ? self::revealable('recipients', $s('form_recipients_set', $count), $canreveal) : s($s('form_recipients_unset')));
        $replacelabel = $s($count > 0 ? 'form_recipients_replace' : 'form_recipients_add');
        $mform->addElement('advcheckbox', $p . 'recipients_replace', '', $replacelabel);
        // Never pre-ticked: an unrelated save of the activity must not send recipients.
        $mform->setDefault($p . 'recipients_replace', 0);
        $mform->addElement('textarea', $p . 'recipients', $s('form_recipients_list'), ['rows' => 3, 'cols' => 50]);
        $mform->setType($p . 'recipients', PARAM_RAW_TRIMMED);
        $mform->setDefault($p . 'recipients', $count > 0 ? '' : (string) $USER->email);
        $mform->addHelpButton($p . 'recipients', 'form_recipients_list', 'local_vektradiscuss');
        $mform->hideIf($p . 'recipients', $p . 'recipients_replace', 'notchecked');
    }

    /**
     * Course material search (FEAT-028): a switch, and the teacher's confirmation that appears when it is turned on.
     * The switch starts on for an activity Vektra has no configuration for, and is then shown only while the
     * discussion is being turned on: an unrelated save of the activity sends nothing ({@see self::rag_requested()}).
     * The confirmation is never pre-ticked; once saved, the form shows when it was given.
     *
     * @param \MoodleQuickForm $mform
     * @param array $view
     */
    private static function add_rag(\MoodleQuickForm $mform, array $view): void {
        $p = self::PREFIX;
        $s = fn(string $id, $a = null): string => get_string($id, 'local_vektradiscuss', $a);
        $orig = self::orig_from_view($view);
        $enabled = $orig['rag_enabled'];
        // Vektra says when the installation cannot offer the search (operator flag): no switch to turn it on,
        // but a search already on can still be turned off. An absent field means available.
        if (($view['rag_available'] ?? true) === false && !$enabled) {
            $reason = $view['rag_unavailable_reason'] ?? null;
            $identifier = is_string($reason) && preg_match('/^[a-z_]{1,40}\z/', $reason)
                && get_string_manager()->string_exists('form_rag_unavailable_' . $reason, 'local_vektradiscuss')
                ? 'form_rag_unavailable_' . $reason : 'form_rag_unavailable';
            $mform->addElement('static', $p . 'rag', $s('form_rag'), $s($identifier));
            return;
        }
        $mform->addElement('advcheckbox', $p . 'rag', $s('form_rag'), $s('form_rag_label'));
        $mform->setDefault($p . 'rag', $enabled || !$orig['configured'] ? 1 : 0);
        $mform->addHelpButton($p . 'rag', 'form_rag', 'local_vektradiscuss');
        if (!$orig['configured']) {
            $mform->hideIf($p . 'rag', $p . 'enabled', 'notchecked');
            $mform->hideIf($p . 'ragattest', $p . 'enabled', 'notchecked');
        }
        if ($enabled) {
            // Already on: no new confirmation is asked; the date is shown when Vektra gives one.
            $at = is_string($view['rag_attested_at'] ?? null) ? strtotime($view['rag_attested_at']) : false;
            if ($at !== false) {
                $mform->addElement('static', $p . 'ragattested', '', $s('form_rag_attested', userdate($at)));
                $mform->hideIf($p . 'ragattested', $p . 'rag', 'notchecked');
            }
            return;
        }
        $mform->addElement('advcheckbox', $p . 'ragattest', '', $s('form_rag_attest'));
        $mform->setDefault($p . 'ragattest', 0);
        $mform->hideIf($p . 'ragattest', $p . 'rag', 'notchecked');
    }

    /**
     * The opening date the discussion counts from, and the teacher's fallback when Vektra needs one (REQ-091).
     *
     * @param \MoodleQuickForm $mform
     * @param array $view
     */
    private static function add_opening(\MoodleQuickForm $mform, array $view): void {
        $p = self::PREFIX;
        $opening = is_array($view['opening'] ?? null) ? $view['opening'] : [];
        $at = is_string($opening['at'] ?? null) ? strtotime($opening['at']) : false;
        $source = in_array($opening['source'] ?? null, ['activity_close', 'attested'], true) && $at !== false
            ? $opening['source'] : 'none';
        $text = $source === 'none'
            ? get_string('form_opening_none', 'local_vektradiscuss')
            : get_string('form_opening_' . $source, 'local_vektradiscuss', userdate($at));
        $mform->addElement('static', $p . 'opening', get_string('form_opening', 'local_vektradiscuss'), $text);

        if (($opening['fallback_required'] ?? false) !== true) {
            return;
        }
        $mform->addElement('static', $p . 'openingneeded', '', get_string('form_opening_needed', 'local_vektradiscuss'));
        $mform->addElement(
            'date_time_selector',
            $p . 'openingat',
            get_string('form_openingat', 'local_vektradiscuss'),
            ['optional' => true]
        );
        // The attestation is ticked again on every edit, never pre-ticked.
        $mform->addElement('advcheckbox', $p . 'attest', '', get_string('form_attest', 'local_vektradiscuss'));
        $mform->setDefault($p . 'attest', 0);
        $mform->hideIf($p . 'attest', $p . 'openingat[enabled]', 'notchecked');
    }

    /**
     * A write-only text: its state, an "edit" switch revealing the field, and "remove" when set.
     *
     * When the text is set and the user may read it back, the switch fills the field with the saved text read from
     * Vektra (local_vektradiscuss/secretedit): the teacher edits it in place, and the save sends the field as the new
     * text. Otherwise the field starts empty and its text replaces the saved one.
     *
     * @param \MoodleQuickForm $mform
     * @param string $name solution or rubric
     * @param bool $set
     * @param bool $canreveal Add the "Show" button and the read-back of "edit" when set.
     */
    private static function add_secret(\MoodleQuickForm $mform, string $name, bool $set, bool $canreveal = false): void {
        $p = self::PREFIX . $name;
        $s = fn(string $id, $a = null): string => get_string($id, 'local_vektradiscuss', $a);
        $prefix = 'form_' . $name . '_';
        $state = $set ? self::revealable($name, $s($prefix . 'set'), $canreveal) : s($s($prefix . 'unset'));
        $mform->addElement('static', $p . '_state', $s('form_' . $name), $state);
        $mform->addElement('advcheckbox', $p . '_replace', '', $s($prefix . ($set ? 'edit' : 'add')));
        $attributes = ['rows' => 8, 'cols' => 60, 'autocomplete' => 'off', 'spellcheck' => 'false'];
        if ($set && $canreveal) {
            $attributes['data-vektradiscuss-prefill'] = $name;
        }
        $mform->addElement('textarea', $p, $s('form_' . $name . '_text'), $attributes);
        // PARAM_RAW: the text is plain text for Vektra (PARAM_TEXT would strip "a<b" from a formula).
        $mform->setType($p, PARAM_RAW);
        $mform->addHelpButton($p, 'form_' . $name . '_text', 'local_vektradiscuss');
        $mform->hideIf($p, $p . '_replace', 'notchecked');
        if ($set) {
            $mform->addElement('advcheckbox', $p . '_remove', '', $s($prefix . 'remove'));
            $mform->hideIf($p . '_remove', $p . '_replace', 'checked');
        }
    }

    /**
     * The state of a saved text with its "Show" button (OQ-034), for the people who can edit the activity. The
     * button reads the text from Vektra on click (local_vektradiscuss/reveal): it is shown in a read-only area
     * outside the form's fields and never put in the form, so a save never sends it back.
     *
     * @param string $name A key of {@see config_reveal::FIELDS}.
     * @param string $state The state sentence.
     * @param bool $canreveal
     * @return string HTML
     */
    private static function revealable(string $name, string $state, bool $canreveal): string {
        if (!$canreveal) {
            return s($state);
        }
        $out = 'vektradiscuss-reveal-' . $name;
        $button = \html_writer::tag('button', s(get_string('form_reveal_show', 'local_vektradiscuss')), [
            'type' => 'button',
            'class' => 'btn btn-link p-0 ms-2 ml-2 align-baseline',
            'data-vektradiscuss-reveal' => $name,
            'aria-controls' => $out,
            'aria-expanded' => 'false',
        ]);
        $area = \html_writer::div('', 'mt-2', ['id' => $out, 'hidden' => 'hidden', 'aria-live' => 'polite']);
        return \html_writer::span(s($state)) . $button . $area;
    }

    /**
     * A notice instead of the fields.
     *
     * @param \MoodleQuickForm $mform
     * @param string $identifier
     */
    private static function note(\MoodleQuickForm $mform, string $identifier): void {
        $mform->addElement('static', self::PREFIX . 'note', '', get_string($identifier, 'local_vektradiscuss'));
    }

    /**
     * Tell the teacher why the settings were not saved.
     *
     * @param assess_response $response
     * @param bool $ragonly The failed request carried only course material search: name only the search (a refusal
     *        leaves it unchanged; any other failure leaves its outcome unknown, so the teacher reopens the settings).
     */
    private static function notify_failure(assess_response $response, bool $ragonly = false): void {
        if ($response->errorcode === 'ERR-ASSESS-022' && is_array($response->details['fields'] ?? null)) {
            $lines = [];
            foreach (array_slice($response->details['fields'], 0, 20) as $item) {
                $lines[] = self::field_error(is_array($item) ? $item : []);
            }
            $intro = get_string($ragonly ? 'form_rag_refused' : 'form_refused', 'local_vektradiscuss');
            \core\notification::error($intro . ' ' . implode(' ', $lines));
            return;
        }
        if ($response->errorcode === 'ERR-ASSESS-023') {
            $identifier = $ragonly ? 'form_rag_conflict' : 'form_err_conflict';
        } else {
            $identifier = self::refusal($response, $ragonly ? 'form_rag_unreachable' : 'form_unreachable_save');
        }
        \core\notification::error(get_string($identifier, 'local_vektradiscuss'));
    }

    /**
     * One of Vektra's field errors as a sentence naming the field and the rule.
     *
     * @param array $item {field, code, reason?, range?, allowed_domains?, index?}
     * @return string
     */
    private static function field_error(array $item): string {
        $field = is_string($item['field'] ?? null) ? $item['field'] : '';
        $code = is_string($item['code'] ?? null) && preg_match('/^[a-z_]{1,40}\z/', $item['code']) ? $item['code'] : 'invalid';
        $sm = get_string_manager();
        $label = $sm->string_exists('form_field_' . $field, 'local_vektradiscuss')
            ? get_string('form_field_' . $field, 'local_vektradiscuss') : s($field);
        $a = (object) [
            'field' => $label,
            'range' => is_string($item['range'] ?? null) ? s($item['range']) : '',
            'domains' => is_array($item['allowed_domains'] ?? null)
                ? s(implode(', ', array_filter($item['allowed_domains'], 'is_string'))) : '',
        ];
        // A refusal with a closed reason has its own sentence when there is one (only for the codes that carry one).
        $reason = in_array($code, self::REASONED_CODES, true) && is_string($item['reason'] ?? null)
            && preg_match('/^[a-z_]{1,40}\z/', $item['reason']) ? $item['reason'] : '';
        if ($reason !== '' && $sm->string_exists('form_code_' . $code . '_' . $reason, 'local_vektradiscuss')) {
            $identifier = 'form_code_' . $code . '_' . $reason;
        } else {
            $identifier = $sm->string_exists('form_code_' . $code, 'local_vektradiscuss')
                ? 'form_code_' . $code : 'form_code_invalid';
        }
        return get_string($identifier, 'local_vektradiscuss', $a);
    }

    /**
     * The rubric sources of an activity type.
     *
     * @param string $type
     * @return string[]
     */
    private static function rubric_sources(string $type): array {
        return $type === 'assign' ? ['none', 'pasted', 'lms'] : ['none', 'pasted'];
    }

    /**
     * Whether a write-only field is set, from Vektra's state word.
     *
     * @param mixed $state
     * @return bool
     */
    private static function is_set($state): bool {
        return $state === 'impostata';
    }

    /**
     * The values the form showed, from the hidden view.
     *
     * @param array $values
     * @return array
     */
    private static function orig(array $values): array {
        $view = json_decode((string) ($values[self::PREFIX . 'view'] ?? ''), true);
        return is_array($view) ? self::orig_from_view($view) : [];
    }

    /**
     * The comparable values of a view.
     *
     * @param array $view
     * @return array
     */
    private static function orig_from_view(array $view): array {
        $until = is_string($view['available_until'] ?? null) ? strtotime($view['available_until']) : false;
        return [
            // An absent flag counts as configured: the search then never starts on by itself.
            'configured' => ($view['configured'] ?? true) !== false,
            'enabled' => ($view['enabled'] ?? false) === true,
            'cap_minutes' => is_int($view['cap_minutes'] ?? null) ? $view['cap_minutes'] : null,
            'rubric_source' => is_string($view['rubric_source'] ?? null) ? $view['rubric_source'] : 'none',
            'show_solution' => ($view['show_solution'] ?? false) === true,
            'rag_enabled' => ($view['rag_enabled'] ?? false) === true,
            'available_until' => $until !== false ? $until : null,
            'rubric_set' => self::is_set($view['pasted_rubric_state'] ?? null),
            'recipients_count' => is_int($view['transcript_recipients_count'] ?? null) ? $view['transcript_recipients_count'] : 0,
        ];
    }

    /**
     * The fields of a view the form needs (nothing else is carried in the page).
     *
     * @param array $view
     * @return array
     */
    private static function keep(array $view): array {
        return array_intersect_key($view, array_flip(['version', 'configured', 'enabled', 'cap_minutes', 'rubric_source',
            'show_solution', 'available_until', 'solution_state', 'pasted_rubric_state', 'transcript_recipients_count',
            'opening', 'warnings', 'active_sessions', 'rag_enabled', 'rag_attested_at', 'rag_available',
            'rag_unavailable_reason']));
    }

    /**
     * The view posted with the form, on a submission.
     *
     * @return array|null
     */
    private static function posted_view(): ?array {
        $raw = optional_param(self::PREFIX . 'view', '', PARAM_RAW);
        $view = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($view) && is_int($view['version'] ?? null) && $view['version'] >= 0 ? $view : null;
    }

    /**
     * The language string for a refusal: role check stale or failed, or the given default.
     *
     * @param assess_response $response
     * @param string $default
     * @return string
     */
    private static function refusal(assess_response $response, string $default): string {
        $refusal = teacher_api::role_refusal($response);
        if ($refusal === null) {
            return $default;
        }
        return $refusal === 'refused' ? 'form_not_an_editor' : 'form_check_stale';
    }
}
