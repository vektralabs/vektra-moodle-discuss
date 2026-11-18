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
 * The teacher's "Show" button of the settings form.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

use local_vektradiscuss\local\http\assess_response;

/**
 * The teacher's "Show" button of the settings form (OQ-034): reads one saved text back from Vektra.
 *
 * The text goes to the teacher's page only. It is never stored in Moodle: no database row, cache, log, event or
 * form value, and the replacement field is never prefilled with it. The caller has checked the login, the session
 * key and the access to the activity; this class checks who may read and turns Vektra's answer into the text or
 * a sentence for the teacher.
 */
class config_reveal {
    /** @var string[] The form's names of the fields => Vektra's names. */
    public const FIELDS = [
        'solution' => 'solution',
        'rubric' => 'pasted_rubric',
        'recipients' => 'transcript_recipients',
    ];

    /** @var config Plugin configuration. */
    private config $config;

    /** @var teacher_api API. */
    private teacher_api $api;

    /**
     * Constructor.
     *
     * @param teacher_api|null $api
     * @param config|null $config
     */
    public function __construct(?teacher_api $api = null, ?config $config = null) {
        $this->api = $api ?? new teacher_api();
        $this->config = $config ?? new config();
    }

    /**
     * Whether the user may read the saved texts of the activity: the same people who see the settings section and
     * can edit the activity.
     *
     * @param \context_module $context
     * @return bool
     */
    public static function can_reveal(\context_module $context): bool {
        return has_capability('local/vektradiscuss:configure', $context)
            && has_capability('moodle/course:manageactivities', $context);
    }

    /**
     * Read one saved text.
     *
     * @param \cm_info $cm
     * @param int $actorid
     * @param string $name A key of {@see self::FIELDS}.
     * @return array ['value' => string] or ['error' => string], a sentence for the teacher.
     */
    public function reveal(\cm_info $cm, int $actorid, string $name): array {
        $type = (string) $cm->modname;
        if (!isset(self::FIELDS[$name]) || !in_array($type, teacher_api::ACTIVITY_TYPES, true)) {
            return self::error('teacher_err_generic');
        }
        if (!self::can_reveal(\context_module::instance($cm->id))) {
            return self::error('nopermissions', 'error', get_string('form_reveal_show', 'local_vektradiscuss'));
        }
        if (student_endpoint::is_loginas()) {
            return self::error('form_loginas');
        }
        if (!$this->config->is_enabled() || !$this->config->key_usable('teacher')) {
            return self::error('form_not_configured');
        }
        $field = self::FIELDS[$name];
        // Retried once inside teacher_api when Vektra is refreshing the teacher's role.
        $response = $this->api->reveal_config((int) $cm->id, (int) $cm->course, $type, $actorid, $field);
        if (!$response->ok()) {
            return self::error(self::refusal($response));
        }
        $value = self::value($response, $field);
        return $value === null ? self::error('form_reveal_unavailable') : ['value' => $value];
    }

    /**
     * The text from a 200 answer, or null when it does not have the documented shape. The recipients come as a
     * list and are shown one per line.
     *
     * @param assess_response $response
     * @param string $field
     * @return string|null
     */
    private static function value(assess_response $response, string $field): ?string {
        $json = $response->json ?? [];
        if (($json['field'] ?? null) !== $field) {
            return null;
        }
        $value = $json['value'] ?? null;
        if ($field === 'transcript_recipients') {
            if (!is_array($value) || array_values($value) !== $value) {
                return null;
            }
            foreach ($value as $address) {
                if (!is_string($address)) {
                    return null;
                }
            }
            return implode("\n", $value);
        }
        return is_string($value) ? $value : null;
    }

    /**
     * The sentence for a refused or failed read.
     *
     * @param assess_response $response
     * @return string A language string identifier.
     */
    private static function refusal(assess_response $response): string {
        $role = teacher_api::role_refusal($response);
        if ($role !== null) {
            return $role === 'refused' ? 'form_not_an_editor' : 'form_check_stale';
        }
        if ($response->status === 404 && $response->errorcode !== null) {
            // Removed since the form opened (field_not_set).
            return 'form_reveal_notset';
        }
        // A 404 without Vektra's error envelope is a Vektra without the route: nothing to retry soon, but no
        // claim about the text either.
        if ($response->status === 0 || $response->status === 404 || $response->status === 429 || $response->status >= 500) {
            return 'form_reveal_unavailable';
        }
        return 'teacher_err_generic';
    }

    /**
     * An error answer.
     *
     * @param string $identifier
     * @param string $component
     * @param mixed $a
     * @return array
     */
    private static function error(string $identifier, string $component = 'local_vektradiscuss', $a = null): array {
        return ['error' => get_string($identifier, $component, $a)];
    }
}
