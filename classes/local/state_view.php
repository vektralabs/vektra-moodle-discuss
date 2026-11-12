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
 * Template context of the student container.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

/**
 * Turns a normalised state ({@see state_dto}) into the context of templates/button.mustache.
 *
 * Every visible sentence comes from this plugin's language pack, keyed by state and reason; from
 * Vektra only numbers, a date, recipient names, file names and the privacy notice URL are shown,
 * and Mustache escapes them.
 */
final class state_view {
    /** @var string Language component. */
    private const C = 'local_vektradiscuss';

    /** @var string[] Reasons with their own sentence, per state. Other reasons use the state's sentence. */
    private const REASON_TEXTS = [
        'not_open' => ['attempt_in_progress', 'no_close_date', 'close_unreadable'],
    ];

    /**
     * Build the context.
     *
     * @param array $dto Normalised state.
     * @param int $cmid Course module id (for unique element ids).
     * @return array
     */
    public static function context(array $dto, int $cmid): array {
        $state = $dto['state'];
        $number = state_dto::STATES[$state] ?? state_dto::STATES[state_dto::UNAVAILABLE];
        $ctx = [
            'state' => $state,
            'statenumber' => $number,
            'uniqid' => 'local-vektradiscuss-' . $cmid,
            // Not enabled renders nothing, unless the student has transcripts of earlier sessions to download:
            // then only those are shown (REQ-068 state 1 has no button).
            'visible' => $state !== 'not_enabled' || !empty($dto['transcripts']),
            'showstatus' => $state !== 'not_enabled',
            'heading' => get_string('student_heading', self::C),
            'canstart' => in_array($state, ['eligible', 'reset_granted'], true),
            'canresume' => $state === 'active',
            'message' => self::message($dto),
            'presession' => null,
            'hastranscripts' => !empty($dto['transcripts']),
            'transcriptsheading' => get_string('transcripts_heading', self::C),
            'transcripts' => self::transcripts($dto['transcripts'] ?? [], $cmid),
            'suggestcopy' => $state === 'closed',
        ];
        $ctx['enabled'] = $ctx['canstart'] || $ctx['canresume'];
        $ctx['buttonlabel'] = self::button_label($dto);
        if ($ctx['canstart']) {
            $ctx['presession'] = self::presession($dto);
        }
        return $ctx;
    }

    /**
     * The status sentence of a state.
     *
     * @param array $dto
     * @return string
     */
    private static function message(array $dto): string {
        $state = $dto['state'];
        $reason = $dto['reason'] ?? null;
        if ($reason !== null && in_array($reason, self::REASON_TEXTS[$state] ?? [], true)) {
            return get_string('state_' . $state . '_' . $reason, self::C);
        }
        switch ($state) {
            case 'not_open':
                if (isset($dto['opens_at'])) {
                    return get_string(
                        'state_not_open_date',
                        self::C,
                        userdate($dto['opens_at'], get_string('strftimedatetime', 'langconfig'))
                    );
                }
                return get_string('state_not_open', self::C);
            case 'active':
                if (isset($dto['active']['minutes_left'])) {
                    return get_string('state_active_minutes', self::C, $dto['active']['minutes_left']);
                }
                return get_string('state_active', self::C);
            case 'not_selected':
                return get_string('state_not_selected', self::C, $dto['selected_attempt_no']);
            case 'eligible':
            case 'reset_granted':
            case 'closed':
            case 'ended':
                $field = $state === 'closed' ? 'closed_at' : 'ended_at';
                if (isset($dto[$field])) {
                    return get_string(
                        'state_' . $state . '_date',
                        self::C,
                        userdate($dto[$field], get_string('strftimedatetime', 'langconfig'))
                    );
                }
                return get_string('state_' . $state, self::C);
            case 'not_graded':
            case 'loginas':
            case 'not_enabled':
                return get_string('state_' . $state, self::C);
            default:
                return get_string('state_unavailable', self::C);
        }
    }

    /**
     * The button label.
     *
     * @param array $dto
     * @return string
     */
    private static function button_label(array $dto): string {
        if ($dto['state'] === 'active') {
            return get_string('button_resume', self::C);
        }
        $sessionno = $dto['session_no_next'] ?? null;
        if ($dto['state'] === 'reset_granted' || $sessionno !== null) {
            return get_string('button_start_session', self::C, $sessionno ?? 2);
        }
        return get_string('button_start', self::C);
    }

    /**
     * The pre-session screen (REQ-068): fixed statements plus the data Vektra sent.
     *
     * @param array $dto
     * @return array
     */
    private static function presession(array $dto): array {
        $pre = $dto['pre_session'] ?? [];
        $items = [];
        if (isset($pre['duration_min'])) {
            $items[] = ['text' => get_string('pre_duration', self::C, $pre['duration_min'])];
        }
        $items[] = ['text' => get_string('pre_one_session', self::C)];
        $items[] = ['text' => get_string('pre_no_grade_change', self::C)];
        $items[] = ['text' => get_string('pre_transcript_copy', self::C)];
        if (isset($pre['course_material_used'])) {
            $items[] = ['text' => get_string($pre['course_material_used'] ? 'pre_material_used' : 'pre_material_notused', self::C)];
        }
        $items[] = ['text' => get_string('pre_ai_disclosure', self::C)];
        if (isset($pre['limits']['max_message_chars']) || isset($pre['limits']['messages_per_min'])) {
            $items[] = ['text' => get_string('pre_limits', self::C, (object) [
                'chars' => $pre['limits']['max_message_chars'] ?? '-',
                'rate' => $pre['limits']['messages_per_min'] ?? '-',
            ])];
        }
        $items[] = ['text' => get_string('pre_enrolment', self::C)];
        if (($pre['previous_sessions'] ?? 0) > 0) {
            $items[] = ['text' => get_string('pre_previous_sessions', self::C, $pre['previous_sessions'])];
        }

        $recipients = [];
        foreach ($pre['recipients'] ?? [] as $recipient) {
            $recipients[] = ['role' => self::role_name($recipient['role']), 'name' => $recipient['name']];
        }
        $files = [];
        foreach ($pre['files'] ?? [] as $file) {
            $files[] = ['name' => $file['name'], 'status' => get_string('pre_file_' . $file['predicted'], self::C)];
        }

        return [
            'title' => get_string('pre_title', self::C),
            'items' => $items,
            'hasrecipients' => !empty($recipients),
            'recipients' => $recipients,
            'hasfiles' => !empty($files),
            'files' => $files,
            'privacyurl' => $pre['privacy']['notice_url'] ?? null,
            'privacyversion' => $pre['privacy']['notice_version'] ?? null,
            // Vektra's summary already ends with the mailbox notice; without a summary, the plugin's sentence and
            // the mailbox notice (a development site without privacy settings).
            'privacysummary' => $pre['privacy']['summary'] ?? get_string('pre_privacy_summary', self::C),
            'privacymailbox' => isset($pre['privacy']['summary']) ? null : ($pre['privacy']['mailbox_notice'] ?? null),
            // Vektra's summary already states the legal basis and the retention: the plugin adds them only when
            // there is no summary.
            'privacylegalbasis' => !isset($pre['privacy']['summary']) && isset($pre['privacy']['legal_basis_label'])
                ? get_string('pre_privacy_legal_basis', self::C, $pre['privacy']['legal_basis_label']) : null,
            'privacyretention' => !isset($pre['privacy']['summary']) && isset($pre['privacy']['retention_days'])
                ? get_string('pre_privacy_retention', self::C, $pre['privacy']['retention_days']) : null,
            'startlabel' => get_string('pre_start', self::C),
            'cancellabel' => get_string('pre_cancel', self::C),
            'preparing' => get_string('preparing', self::C),
        ];
    }

    /**
     * Transcript links: a download link when the transcript is available, a status sentence otherwise.
     *
     * The link carries the session key: transcript.php checks it, so a third-party page cannot make the
     * browser fetch a transcript.
     *
     * @param array[] $transcripts From the DTO.
     * @param int $cmid
     * @return array[]
     */
    private static function transcripts(array $transcripts, int $cmid): array {
        $out = [];
        foreach ($transcripts as $t) {
            $item = ['n' => $t['n'], 'url' => null, 'text' => null];
            if ($t['purged']) {
                $item['text'] = get_string('transcript_purged', self::C, $t['n']);
            } else if ($t['available']) {
                $item['url'] = (new \moodle_url('/local/vektradiscuss/transcript.php', [
                    'cmid' => $cmid, 'tid' => $t['tid'], 'n' => $t['n'], 'sesskey' => sesskey(),
                ]))->out(false);
                $item['text'] = get_string('transcript_link', self::C, $t['n']);
            } else {
                $item['text'] = get_string('transcript_pending', self::C, $t['n']);
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * The localised name of a recipient role, by archetype short name; the code itself otherwise.
     *
     * @param string $role
     * @return string
     */
    private static function role_name(string $role): string {
        $known = ['editingteacher', 'teacher', 'manager'];
        return in_array($role, $known, true) ? get_string('role_' . $role, self::C) : $role;
    }
}
