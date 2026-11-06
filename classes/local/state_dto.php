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
 * Normalisation of the advisory state returned by Vektra GET /api/v1/assess/state.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

/**
 * Maps the Vektra state document to a closed set of render states (REQ-068) and plain fields.
 *
 * Anything unknown, malformed or out of range degrades to {@see self::UNAVAILABLE}: a backend addition
 * never reaches the page as free text. Only data the plugin renders through its own strings survives:
 * numbers, booleans, allowlisted codes, a date, recipient names and the privacy notice URL.
 */
final class state_dto {
    /** @var string State 7: temporarily unavailable, generic text. */
    public const UNAVAILABLE = 'unavailable';

    /** @var string State 8: the session is a "log in as" session (local, never asked to Vektra). */
    public const LOGINAS = 'loginas';

    /** @var string State 9: the viewed attempt is not the one selected for the discussion (local). */
    public const NOT_SELECTED = 'not_selected';

    /** @var int[] Render state => REQ-068 number. */
    public const STATES = [
        'not_enabled' => 1,
        'not_graded' => 2,
        'not_open' => 3,
        'eligible' => 4,
        'active' => 5,
        'closed' => 6,
        self::UNAVAILABLE => 7,
        self::LOGINAS => 8,
        self::NOT_SELECTED => 9,
        'reset_granted' => 10,
        'ended' => 11,
    ];

    /** @var string[] Vektra state => render state, for the values that are not render states themselves. */
    private const ALIASES = [
        // An open session still being committed: shown as state 5 (resume); the iframe shows the preparation.
        'starting' => 'active',
    ];

    /** @var string[] States in which Vektra may send the pre-session block. */
    private const PRESESSION_STATES = ['eligible', 'reset_granted'];

    /** @var string[] States in which Vektra may send transcript links. */
    private const TRANSCRIPT_STATES = ['closed', 'reset_granted', 'ended'];

    /** @var string[] Predicted readability of a submitted file. */
    private const FILE_PREDICTIONS = ['readable', 'partial', 'unreadable'];

    /** @var int Upper bound of every count and number read from Vektra. */
    private const MAX_INT = 100000;

    /**
     * A bare state with no fields.
     *
     * @param string $state One of {@see self::STATES}.
     * @return array
     */
    public static function bare(string $state): array {
        if (!isset(self::STATES[$state])) {
            $state = self::UNAVAILABLE;
        }
        return ['state' => $state, 'reason' => null];
    }

    /**
     * Normalise the decoded body of GET /state.
     *
     * @param mixed $json Decoded JSON (anything: it is validated here).
     * @return array Keys: state, reason, and optionally opens_at (int timestamp), session_no_next,
     *         active, selected_attempt_no, pre_session, transcripts.
     */
    public static function from_vektra($json): array {
        if (!is_array($json) || !isset($json['state']) || !is_string($json['state'])) {
            return self::bare(self::UNAVAILABLE);
        }
        $state = self::ALIASES[$json['state']] ?? $json['state'];
        // The two local states are never accepted from the backend.
        if (!isset(self::STATES[$state]) || $state === self::LOGINAS || $state === self::NOT_SELECTED) {
            return self::bare(self::UNAVAILABLE);
        }

        $dto = ['state' => $state, 'reason' => self::code($json['reason'] ?? null)];

        if ($state === 'not_open') {
            $opens = self::timestamp($json['opens_at'] ?? null);
            if ($opens !== null) {
                $dto['opens_at'] = $opens;
            }
        }
        $next = self::count($json['session_no_next'] ?? null, 2);
        if ($next !== null) {
            $dto['session_no_next'] = $next;
        }
        if ($state === 'active' && is_array($json['active'] ?? null)) {
            $active = [];
            foreach (['session_no' => 1, 'minutes_left' => 0] as $key => $min) {
                $value = self::count($json['active'][$key] ?? null, $min);
                if ($value !== null) {
                    $active[$key] = $value;
                }
            }
            if ($active) {
                $dto['active'] = $active;
            }
        }
        if (is_array($json['selected_attempt'] ?? null)) {
            $no = self::count($json['selected_attempt']['attempt_no'] ?? null, 1);
            if ($no !== null) {
                $dto['selected_attempt_no'] = $no;
            }
        }
        if (in_array($state, self::PRESESSION_STATES, true) && is_array($json['pre_session'] ?? null)) {
            $dto['pre_session'] = self::pre_session($json['pre_session']);
        }
        if (in_array($state, self::TRANSCRIPT_STATES, true) && is_array($json['transcripts'] ?? null)) {
            $transcripts = self::transcripts($json['transcripts']);
            if ($transcripts) {
                $dto['transcripts'] = $transcripts;
            }
        }
        return $dto;
    }

    /**
     * The pre-session block, keeping only typed, bounded values.
     *
     * @param array $raw
     * @return array
     */
    private static function pre_session(array $raw): array {
        $pre = [];
        $duration = self::count($raw['duration_min'] ?? null, 1);
        if ($duration !== null) {
            $pre['duration_min'] = $duration;
        }
        if (is_bool($raw['course_material_used'] ?? null)) {
            $pre['course_material_used'] = $raw['course_material_used'];
        }
        $previous = self::count($raw['previous_sessions'] ?? null, 0);
        if ($previous !== null) {
            $pre['previous_sessions'] = $previous;
        }
        if (is_array($raw['limits'] ?? null)) {
            foreach (['max_message_chars', 'messages_per_min'] as $key) {
                $value = self::count($raw['limits'][$key] ?? null, 1);
                if ($value !== null) {
                    $pre['limits'][$key] = $value;
                }
            }
        }
        if (is_array($raw['recipients'] ?? null)) {
            foreach (array_slice($raw['recipients'], 0, 50) as $recipient) {
                $role = self::code($recipient['role'] ?? null);
                $name = self::text($recipient['name'] ?? null, 200);
                if ($role !== null && $name !== null) {
                    $pre['recipients'][] = ['role' => $role, 'name' => $name];
                }
            }
        }
        if (is_array($raw['privacy'] ?? null)) {
            $url = self::https_url($raw['privacy']['notice_url'] ?? null);
            if ($url !== null) {
                $pre['privacy']['notice_url'] = $url;
                $version = self::text($raw['privacy']['notice_version'] ?? null, 40);
                if ($version !== null) {
                    $pre['privacy']['notice_version'] = $version;
                }
            }
        }
        if (is_array($raw['files'] ?? null)) {
            foreach (array_slice($raw['files'], 0, 100) as $file) {
                $name = self::text($file['name'] ?? null, 255);
                $predicted = $file['predicted'] ?? null;
                if ($name !== null && in_array($predicted, self::FILE_PREDICTIONS, true)) {
                    $pre['files'][] = ['name' => $name, 'predicted' => $predicted];
                }
            }
        }
        return $pre;
    }

    /**
     * Transcript links: opaque ids and session numbers.
     *
     * @param array $raw
     * @return array[]
     */
    private static function transcripts(array $raw): array {
        $out = [];
        foreach (array_slice($raw, 0, 50) as $item) {
            $tid = $item['tid'] ?? null;
            $n = self::count($item['n'] ?? null, 1);
            if (!is_string($tid) || !preg_match('/^[A-Za-z0-9-]{1,64}$/', $tid) || $n === null) {
                continue;
            }
            $out[] = [
                'tid' => $tid,
                'n' => $n,
                'available' => ($item['available'] ?? false) === true,
                'purged' => ($item['purged'] ?? false) === true,
            ];
        }
        return $out;
    }

    /**
     * A lower-case code, or null.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function code($value): ?string {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $value) ? $value : null;
    }

    /**
     * A bounded integer (JSON numbers only, no numeric strings), or null.
     *
     * @param mixed $value
     * @param int $min
     * @return int|null
     */
    private static function count($value, int $min): ?int {
        return is_int($value) && $value >= $min && $value <= self::MAX_INT ? $value : null;
    }

    /**
     * Single-line text without control characters, trimmed and bounded, or null.
     *
     * @param mixed $value
     * @param int $max Maximum length in characters.
     * @return string|null
     */
    private static function text($value, int $max): ?string {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            return null;
        }
        return $value;
    }

    /**
     * An https URL, or null.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function https_url($value): ?string {
        $value = self::text($value, 2000);
        if ($value === null || !str_starts_with(strtolower($value), 'https://')) {
            return null;
        }
        return clean_param($value, PARAM_URL) === $value ? $value : null;
    }

    /**
     * An ISO-8601 date-time with an explicit offset, as a Unix timestamp, or null.
     *
     * @param mixed $value
     * @return int|null
     */
    private static function timestamp($value): ?int {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }
}
