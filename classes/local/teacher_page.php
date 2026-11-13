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
 * The teacher's "Discussions" page of an activity: the sessions list and the teacher actions.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

use local_vektradiscuss\local\http\assess_response;

/**
 * Builds the sessions list (REQ-076) and carries out reset (REQ-087), terminate (REQ-081) and extra time
 * (REQ-078).
 *
 * Moodle checks login, course access, the capability (view for the list, manage for the actions), the
 * session key and "log in as" before any request; Vektra re-checks that the actor grades the activity.
 * Names are resolved in Moodle from user ids: Vektra never sends a name or an address. Nothing is stored.
 */
class teacher_page {
    /** @var string Component. */
    private const C = 'local_vektradiscuss';

    /** @var string[] Actions of the page. */
    public const ACTIONS = ['reset', 'terminate', 'extratime', 'resend'];

    /** @var int Students offered at once for extra time before their first discussion. */
    public const OTHER_LIMIT = 200;

    /** @var int Pages of the sessions list searched for a transcript (50 students each). */
    private const MAX_PAGES = 40;

    /** @var int Upper bound of a reason, in characters (Vektra: 1-1000 code points). */
    public const MAX_REASON = 1000;

    /** @var string Shape of a list cursor. */
    private const CURSOR = '/^[A-Za-z0-9._~+\/=:-]{1,256}\z/';

    /** @var string[] Session states with their own label. */
    private const STATES = ['STARTING', 'ACTIVE', 'OUTAGE', 'CLOSED'];

    /** @var string[] Close reasons with their own label. */
    private const CLOSE_REASONS = ['finished', 'timed_out', 'service_failure', 'budget_exhausted', 'terminated_by_teacher',
        'access_revoked'];

    /** @var string[] Delivery statuses with their own label. */
    private const DELIVERY = ['PENDING', 'RETRYING', 'SENT', 'FAILED', 'BOUNCED', 'REPLACED', 'ABANDONED'];

    /** @var teacher_api API. */
    private teacher_api $api;

    /** @var \cm_info Activity. */
    private \cm_info $cm;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var string Cursor of the list page being rendered (links carry it, so lookups start there). */
    private string $cursor = '';

    /**
     * Constructor.
     *
     * @param \cm_info $cm
     * @param \stdClass $course
     * @param teacher_api|null $api
     */
    public function __construct(\cm_info $cm, \stdClass $course, ?teacher_api $api = null) {
        $this->cm = $cm;
        $this->course = $course;
        $this->api = $api ?? new teacher_api();
    }

    /**
     * A valid cursor from the request, or ''.
     *
     * @param string $cursor
     * @return string
     */
    public static function clean_cursor(string $cursor): string {
        return preg_match(self::CURSOR, $cursor) ? $cursor : '';
    }

    /**
     * The next page's cursor from a list page: Vektra sends the last user id of the page (a JSON number).
     *
     * @param array $json
     * @return string
     */
    public static function next_cursor(array $json): string {
        $cursor = $json['next_cursor'] ?? null;
        if (is_int($cursor) && $cursor > 0) {
            return (string) $cursor;
        }
        return is_string($cursor) ? self::clean_cursor($cursor) : '';
    }

    /**
     * Carry out one action. The caller has checked the session key; this checks the capability.
     *
     * @param string $action reset, terminate or extratime
     * @param array $params Cleaned request parameters.
     * @return array [bool success, string message]
     */
    public function act(string $action, array $params): array {
        global $USER;
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \invalid_parameter_exception('Unknown action.');
        }
        require_capability('local/vektradiscuss:manage', \context_module::instance($this->cm->id));
        if (student_endpoint::is_loginas()) {
            return [false, get_string('teacher_loginas', self::C)];
        }
        $args = [(int) $this->cm->id, (int) $this->course->id, $this->cm->modname, (int) $USER->id];

        if ($action === 'reset' || $action === 'terminate') {
            $reason = trim((string) ($params['reason'] ?? ''));
            if ($reason === '') {
                return [false, get_string('teacher_err_reason_required', self::C)];
            }
            if (\core_text::strlen($reason) > self::MAX_REASON) {
                return [false, get_string('teacher_err_reason_long', self::C, self::MAX_REASON)];
            }
        }
        switch ($action) {
            case 'resend':
                $tid = (string) ($params['tid'] ?? '');
                if ($this->find_transcript($tid, (string) ($params['page'] ?? '')) === null) {
                    return [false, get_string('teacher_err_generic', self::C)];
                }
                $response = $this->api->resend($tid, (int) $USER->id);
                if ($response->status === 429) {
                    return [false, get_string('teacher_resend_too_soon', self::C)];
                }
                return $this->outcome($response, 'teacher_resend_done');
            case 'reset':
                $sid = (string) ($params['sid'] ?? '');
                if (!preg_match('/^[0-9a-f-]{36}\z/', $sid)) {
                    return [false, get_string('teacher_err_generic', self::C)];
                }
                $response = $this->api->reset(...[...$args, $sid, $reason, !empty($params['includeprevious'])]);
                return $this->outcome($response, 'teacher_reset_done');
            case 'terminate':
                $expected = (int) ($params['expected'] ?? -1);
                if ($expected < 0) {
                    return [false, get_string('teacher_err_generic', self::C)];
                }
                $response = $this->api->terminate(...[...$args, $reason, $expected, !empty($params['alsodisable'])]);
                if ($response->ok()) {
                    $closed = (int) ($response->json['closed'] ?? 0);
                    return [true, get_string('teacher_terminate_done', self::C, $closed)];
                }
                return $this->outcome($response, '');
            default:
                $student = (int) ($params['userid'] ?? 0);
                $value = trim((string) ($params['value'] ?? ''));
                $unit = ($params['unit'] ?? '') === 'percent' ? 'percent' : 'minutes';
                if ($student <= 0 || !ctype_digit($value)) {
                    return [false, get_string('teacher_err_extratime_value', self::C)];
                }
                $minutes = $unit === 'minutes' ? (int) $value : null;
                $percent = $unit === 'percent' ? (int) $value : null;
                $response = $this->api->extra_time(...[...$args, $student, $minutes, $percent, !empty($params['confirm'])]);
                return $this->outcome($response, 'teacher_extratime_done');
        }
    }

    /**
     * Where a transcript of this activity is: its session number and student, read from Vektra's sessions
     * list, or null.
     *
     * Vektra checks that the actor grades the transcript's activity; this ties the transcript to the activity
     * whose capability Moodle checked, so a teacher prohibited on another activity cannot reach its
     * transcripts through this one. The links carry the list page of their row, so the search starts there.
     *
     * @param string $tid
     * @param string $hint The cursor of the list page the link came from ('' = first page).
     * @return array|null {n, userid}
     */
    public function find_transcript(string $tid, string $hint = ''): ?array {
        if (!transcript_proxy::valid_tid($tid)) {
            return null;
        }
        $hint = self::clean_cursor($hint);
        $found = $hint !== '' ? $this->search_transcript($tid, $hint, 1) : null;
        return $found ?? $this->search_transcript($tid, '', self::MAX_PAGES);
    }

    /**
     * Search the sessions list for a transcript, from a cursor, over at most $pages pages.
     *
     * @param string $tid
     * @param string $cursor
     * @param int $pages
     * @return array|null {n, userid}
     */
    private function search_transcript(string $tid, string $cursor, int $pages): ?array {
        global $USER;
        for ($page = 0; $page < $pages; $page++) {
            $args = [(int) $this->cm->id, (int) $this->course->id, $this->cm->modname, (int) $USER->id, $cursor];
            $response = $this->api->list_sessions(...$args);
            if (teacher_api::role_refusal($response) === 'stale') {
                // Vektra queued a fresh check of the teacher's role: one retry after it had time to land.
                sleep(2);
                $response = $this->api->list_sessions(...$args);
            }
            $students = $response->ok() && is_array($response->json['students'] ?? null) ? $response->json['students'] : null;
            if ($students === null) {
                return null;
            }
            foreach ($students as $student) {
                foreach (is_array($student['sessions'] ?? null) ? $student['sessions'] : [] as $session) {
                    if (is_array($session) && ($session['transcript_id'] ?? null) === $tid) {
                        return ['n' => max(1, (int) ($session['session_no'] ?? 1)), 'userid' => (int) ($student['userid'] ?? 0)];
                    }
                }
            }
            $cursor = self::next_cursor($response->json);
            if ($cursor === '') {
                return null;
            }
        }
        return null;
    }

    /**
     * The template context of the list, or null when Vektra did not answer with one.
     *
     * @param assess_response $response
     * @param string $cursor The cursor of this page.
     * @param string $search Filter of the students offered for extra time before their first discussion.
     * @return array|null
     */
    public function context(assess_response $response, string $cursor = '', string $search = ''): ?array {
        $this->cursor = self::clean_cursor($cursor);
        $json = $response->ok() ? $response->json : null;
        if (!is_array($json) || !is_array($json['students'] ?? null)) {
            return null;
        }
        $modcontext = \context_module::instance($this->cm->id);
        $canmanage = has_capability('local/vektradiscuss:manage', $modcontext) && !student_endpoint::is_loginas();
        $gate = is_array($json['gate'] ?? null) ? $json['gate'] : [];
        $active = is_int($gate['active_sessions'] ?? null) ? $gate['active_sessions'] : 0;

        $students = array_values(array_filter(array_slice($json['students'], 0, 200), 'is_array'));
        $ids = [];
        foreach ($students as $student) {
            $ids[] = (int) ($student['userid'] ?? 0);
            foreach (($student['resets'] ?? []) as $reset) {
                $ids[] = (int) ($reset['granted_by'] ?? 0);
            }
        }
        $terminations = is_array($json['terminations'] ?? null) ? array_slice($json['terminations'], 0, 50) : [];
        foreach ($terminations as $termination) {
            $ids[] = (int) ($termination['by'] ?? 0);
        }
        $holder = $this->override_holder($gate);
        if ($holder) {
            $ids[] = $holder;
        }
        $names = self::names($ids);

        $listed = array_map(fn(array $s): int => (int) ($s['userid'] ?? 0), $students);
        $search = \core_text::substr(trim($search), 0, 100);
        [$others, $truncated] = $canmanage ? $this->other_students($listed, $search) : [[], false];
        $url = new \moodle_url('/local/vektradiscuss/teacher.php', ['cmid' => $this->cm->id]);
        $next = self::next_cursor($json);
        return [
            'cmid' => (int) $this->cm->id,
            'actionurl' => $url->out(false),
            'sesskey' => sesskey(),
            'canmanage' => $canmanage,
            'gate' => [
                'opening' => self::opening_text($gate['opening'] ?? null),
                'fallbackreason' => is_string($gate['fallback_reason'] ?? null) ? $gate['fallback_reason'] : null,
                'fallbackmissing' => ($gate['fallback_date_missing'] ?? false) === true,
                'settingsurl' => (new \moodle_url('/course/modedit.php', ['update' => $this->cm->id]))->out(false),
                'activesessions' => $active,
                'overrideholder' => $holder ? ($names[$holder] ?? get_string('teacher_user_unknown', self::C, $holder)) : null,
            ],
            'canterminate' => $canmanage && $active > 0,
            'expectedactive' => $active,
            'terminations' => array_map(fn(array $t): array => [
                'at' => self::date($t['at'] ?? null),
                'by' => $names[(int) ($t['by'] ?? 0)] ?? get_string('teacher_user_unknown', self::C, (int) ($t['by'] ?? 0)),
                'reason' => self::reason($t),
                'closed' => (int) ($t['closed'] ?? 0),
                'rolledback' => (int) ($t['rolled_back'] ?? 0),
                'disabled' => ($t['also_disable'] ?? false) === true,
            ], array_values(array_filter($terminations, 'is_array'))),
            'hasterminations' => (bool) $terminations,
            'students' => array_map(fn(array $s): array => $this->student($s, $names, $canmanage), $students),
            'hasstudents' => (bool) $students,
            'otherstudents' => $others,
            'hasotherstudents' => (bool) $others,
            // The search shows when the list is cut, or when a search is on.
            'othersearch' => $canmanage && ($truncated || $search !== '') ? ['value' => $search, 'truncated' => $truncated] : null,
            'firstpageurl' => $cursor !== '' ? $url->out(false) : null,
            'nexturl' => $next !== '' ? (new \moodle_url($url, ['cursor' => $next]))->out(false) : null,
        ];
    }

    /**
     * One student's row.
     *
     * @param array $s
     * @param array $names
     * @param bool $canmanage
     * @return array
     */
    private function student(array $s, array $names, bool $canmanage): array {
        $userid = (int) ($s['userid'] ?? 0);
        $sessions = array_values(array_filter(is_array($s['sessions'] ?? null) ? $s['sessions'] : [], 'is_array'));
        $resets = array_values(array_filter(is_array($s['resets'] ?? null) ? $s['resets'] : [], 'is_array'));
        $latest = $sessions ? $sessions[count($sessions) - 1] : null;
        $resetafter = array_map(fn(array $r): int => (int) ($r['after_session_no'] ?? 0), $resets);
        $canreset = $canmanage && $latest !== null && ($latest['state'] ?? '') === 'CLOSED'
            && !in_array((int) ($latest['session_no'] ?? 0), $resetafter, true)
            && preg_match('/^[0-9a-f-]{36}\z/', (string) ($latest['session_id'] ?? ''));
        $extra = is_int($s['extra_seconds'] ?? null) ? $s['extra_seconds'] : 0;
        return [
            'userid' => $userid,
            'name' => $names[$userid] ?? get_string('teacher_user_unknown', self::C, $userid),
            'sessions' => array_map(fn(array $session): array => [
                'n' => (int) ($session['session_no'] ?? 0),
                'state' => self::label('teacher_state_', $session['state'] ?? null, self::STATES),
                'closereason' => ($session['close_reason'] ?? null) !== null
                    ? self::label('teacher_close_', $session['close_reason'], self::CLOSE_REASONS) : null,
                'closedat' => self::date($session['closed_at'] ?? null),
                'deliveries' => self::deliveries($session['deliveries'] ?? []),
                'hasdeliveries' => !empty($session['deliveries']),
                'tid' => $tid = $this->transcript_id($session),
                'downloadurl' => $this->download_url($tid),
                // Vektra resends only a transcript that was delivered at least once (a new round of its deliveries).
                'canresend' => $canmanage && $tid !== null && !empty($session['deliveries']),
                'page' => $this->cursor,
            ], $sessions),
            'resets' => array_map(fn(array $r): array => [
                'after' => (int) ($r['after_session_no'] ?? 0),
                'reason' => self::reason($r),
                'by' => $names[(int) ($r['granted_by'] ?? 0)]
                    ?? get_string('teacher_user_unknown', self::C, (int) ($r['granted_by'] ?? 0)),
                'at' => self::date($r['granted_at'] ?? null),
            ], $resets),
            'hasresets' => (bool) $resets,
            'extra' => $extra > 0 ? get_string('teacher_extratime_current', self::C, (int) ceil($extra / 60)) : null,
            'canreset' => (bool) $canreset,
            'resetsid' => $canreset ? (string) $latest['session_id'] : null,
            'resetafter' => $canreset ? (int) ($latest['session_no'] ?? 0) : null,
            'continuitydefault' => $canreset && ($latest['close_reason'] ?? null) === 'service_failure',
            // Vektra accepts extra time only between sessions: before the first one, or after a granted reset
            // whose session has not started (REQ-078).
            'canextratime' => $canmanage && ($latest === null
                || (($latest['state'] ?? '') === 'CLOSED' && in_array((int) ($latest['session_no'] ?? 0), $resetafter, true))),
        ];
    }

    /**
     * Students of the activity who are not in the list (no session yet), for extra time before their first
     * discussion (REQ-078 accommodations): at most OTHER_LIMIT of them, filtered by name or email when the
     * teacher searches, so a large course is reached through the search rather than cut off.
     *
     * @param int[] $listed
     * @param string $search
     * @return array [students [{userid, name}], whether more match than are shown]
     */
    private function other_students(array $listed, string $search): array {
        global $DB;
        [$esql, $params] = get_enrolled_sql(\context_module::instance($this->cm->id), 'local/vektradiscuss:start', 0, true);
        $where = '';
        if ($search !== '') {
            $q = '%' . $DB->sql_like_escape($search) . '%';
            $where = ' AND (' . $DB->sql_like($DB->sql_fullname('u.firstname', 'u.lastname'), ':q1', false, false)
                . ' OR ' . $DB->sql_like('u.email', ':q2', false, false) . ')';
            $params += ['q1' => $q, 'q2' => $q];
        }
        if ($listed) {
            [$notin, $nparams] = $DB->get_in_or_equal($listed, SQL_PARAMS_NAMED, 'listed', false);
            $where .= ' AND u.id ' . $notin;
            $params += $nparams;
        }
        $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $users = $DB->get_records_sql(
            "SELECT u.id, $fields FROM {user} u JOIN ($esql) e ON e.id = u.id
              WHERE u.deleted = 0 $where ORDER BY u.lastname, u.firstname, u.id",
            $params,
            0,
            self::OTHER_LIMIT + 1
        );
        $out = [];
        foreach (array_slice($users, 0, self::OTHER_LIMIT) as $user) {
            $out[] = ['userid' => (int) $user->id, 'name' => fullname($user)];
        }
        return [$out, count($users) > self::OTHER_LIMIT];
    }

    /**
     * The grader download link of a transcript, or null; it carries the list page of its row.
     *
     * @param string|null $tid
     * @return string|null
     */
    private function download_url(?string $tid): ?string {
        if ($tid === null) {
            return null;
        }
        $params = ['cmid' => $this->cm->id, 'tid' => $tid, 'sesskey' => sesskey()];
        if ($this->cursor !== '') {
            $params['page'] = $this->cursor;
        }
        return (new \moodle_url('/local/vektradiscuss/teacher_transcript.php', $params))->out(false);
    }

    /**
     * The transcript id of a closed session, when Vektra generated one.
     *
     * @param array $session
     * @return string|null
     */
    private function transcript_id(array $session): ?string {
        $tid = $session['transcript_id'] ?? null;
        return is_string($tid) && transcript_proxy::valid_tid($tid) ? $tid : null;
    }

    /**
     * Translate an outcome into a success flag and a message.
     *
     * @param assess_response $response
     * @param string $done Language string of the success message.
     * @return array [bool, string]
     */
    private function outcome(assess_response $response, string $done): array {
        if ($response->ok()) {
            return [true, get_string($done, self::C)];
        }
        $details = is_array($response->details) ? $response->details : [];
        switch ($response->errorcode) {
            case 'ERR-ASSESS-024':
                $reason = is_string($details['reason'] ?? null) ? $details['reason'] : '';
                $known = ['not_closed', 'not_latest', 'already_reset', 'purged', 'gate_inputs_stale', 'gate', 'end_date_passed',
                    'not_enabled'];
                $identifier = in_array($reason, $known, true) ? 'teacher_reset_' . $reason : 'teacher_err_generic';
                return [false, get_string($identifier, self::C)];
            case 'ERR-ASSESS-025':
                return [false, get_string('teacher_terminate_count_changed', self::C, (int) ($details['current'] ?? 0))];
            case 'ERR-ASSESS-022':
                $codes = [];
                foreach (is_array($details['fields'] ?? null) ? $details['fields'] : [] as $item) {
                    $code = is_array($item) && is_string($item['code'] ?? null) ? $item['code'] : '';
                    if (preg_match('/^[a-z_0-9]{1,40}\z/', $code)) {
                        $codes[] = get_string_manager()->string_exists('teacher_code_' . $code, self::C)
                            ? get_string('teacher_code_' . $code, self::C) : get_string('teacher_err_generic', self::C);
                    }
                }
                return [false, $codes ? implode(' ', array_unique($codes)) : get_string('teacher_err_generic', self::C)];
            case 'ERR-ASSESS-021':
                $refusal = teacher_api::role_refusal($response);
                return [false, get_string($refusal === 'refused' ? 'teacher_not_a_grader' : 'teacher_check_stale', self::C)];
            case 'ERR-ASSESS-008':
                return [false, get_string('teacher_session_gone', self::C)];
            default:
                return [false, get_string('teacher_err_generic', self::C)];
        }
    }

    /**
     * The override holder's user id, only for a viewer who may manage overrides of this activity.
     *
     * @param array $gate
     * @return int
     */
    private function override_holder(array $gate): int {
        $holder = is_int($gate['override_holder'] ?? null) ? $gate['override_holder'] : 0;
        $cap = $this->cm->modname === 'quiz' ? 'mod/quiz:manageoverrides' : 'mod/assign:manageoverrides';
        return $holder > 0 && has_capability($cap, \context_module::instance($this->cm->id)) ? $holder : 0;
    }

    /**
     * Full names by user id, for the users that still exist.
     *
     * @param int[] $ids
     * @return string[]
     */
    private static function names(array $ids): array {
        global $DB;
        $ids = array_values(array_unique(array_filter($ids, fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return [];
        }
        $fields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
        $users = $DB->get_records_list('user', 'id', $ids, '', 'id, ' . $fields);
        $names = [];
        foreach ($users as $user) {
            $names[(int) $user->id] = fullname($user);
        }
        return $names;
    }

    /**
     * A reason, or the sentence saying it cannot be read.
     *
     * @param array $item
     * @return string
     */
    private static function reason(array $item): string {
        if (($item['reason_state'] ?? null) === 'purged') {
            return get_string('teacher_reason_purged', self::C);
        }
        if (($item['reason_state'] ?? null) === 'unreadable' || !is_string($item['reason'] ?? null)) {
            return get_string('teacher_reason_unreadable', self::C);
        }
        return \core_text::substr($item['reason'], 0, self::MAX_REASON);
    }

    /**
     * Deliveries of a session, as one line per round and kind.
     *
     * @param mixed $deliveries
     * @return string[]
     */
    private static function deliveries($deliveries): array {
        $out = [];
        foreach (is_array($deliveries) ? array_slice($deliveries, 0, 20) : [] as $d) {
            if (!is_array($d)) {
                continue;
            }
            $out[] = get_string('teacher_delivery', self::C, (object) [
                'round' => (int) ($d['round'] ?? 1),
                'status' => self::label('teacher_delivery_', $d['status'] ?? null, self::DELIVERY),
                'at' => self::date($d['sent_at'] ?? null) ?? '-',
            ]);
        }
        return $out;
    }

    /**
     * The opening date sentence (an unknown source is "no opening date").
     *
     * @param mixed $opening
     * @return string
     */
    private static function opening_text($opening): string {
        $opening = is_array($opening) ? $opening : [];
        $at = is_string($opening['at'] ?? null) ? strtotime($opening['at']) : false;
        $source = $opening['source'] ?? null;
        if ($at === false || !in_array($source, ['activity_close', 'attested'], true)) {
            return get_string('form_opening_none', self::C);
        }
        return get_string('form_opening_' . $source, self::C, userdate($at));
    }

    /**
     * A label for an enumerated value, or the escaped value itself when it is new.
     *
     * @param string $prefix
     * @param mixed $value
     * @param string[] $known
     * @return string
     */
    private static function label(string $prefix, $value, array $known): string {
        if (!is_string($value) || $value === '') {
            return '-';
        }
        // Raw: the template escapes it (escaping here too would show "&amp;").
        return in_array($value, $known, true) ? get_string($prefix . strtolower($value), self::C) : $value;
    }

    /**
     * A date from an ISO 8601 string, or null.
     *
     * @param mixed $iso
     * @return string|null
     */
    private static function date($iso): ?string {
        $time = is_string($iso) ? strtotime($iso) : false;
        return $time === false ? null : userdate($time);
    }
}
