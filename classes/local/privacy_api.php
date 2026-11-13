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
 * Vektra's privacy endpoints, for the Moodle privacy API provider.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

use local_vektradiscuss\local\http\assess_client;
use local_vektradiscuss\local\http\assess_response;

/**
 * Export and erasure of a person's discussion data held by Vektra (REQ-088, REQ-093, OQ-033).
 *
 * Every failure throws a moodle_exception: the Moodle privacy tool then records the request as failed and an
 * administrator sees it. Nothing here ever reports success it could not confirm.
 */
class privacy_api {
    /** @var string Component. */
    private const C = 'local_vektradiscuss';

    /** @var int Upper bound of pages read for one subject or activity. */
    private const MAX_PAGES = 1000;

    /** @var int Erasure attempts while Vektra answers "pending" (the same request is idempotent). */
    public const ERASE_ATTEMPTS = 5;

    /** @var int Seconds between two erasure attempts. */
    public const ERASE_WAIT = 3;

    /** @var int Timeout of a read, in seconds (a page carries up to 10 transcripts). */
    public const READ_TIMEOUT = 30;

    /** @var int Timeout of an erasure, in seconds: Vektra holds it up to its erasure wait (at most 30 s). */
    public const ERASE_TIMEOUT = 35;

    /** @var int Largest userids list of one activity erasure. */
    public const MAX_USERIDS = 1000;

    /** @var string[] Why a session's turns are not in the activities export (vektra-stack #215). */
    public const TURNS_UNAVAILABLE = ['transcript_pending', 'transcript_failed', 'transcript_purged', 'transcript_missing'];

    /** @var string[] Technical record counts of an activity. */
    private const TECHNICAL = ['assess_start_attempts', 'assess_token_jti', 'assess_gate_inputs', 'assess_render_state',
        'assess_render_checks'];

    /** @var assess_client Client. */
    private assess_client $client;

    /** @var config Configuration. */
    private config $config;

    /** @var int Seconds slept between erasure attempts (0 in tests). */
    private int $wait;

    /**
     * Constructor.
     *
     * @param config|null $config
     * @param assess_client|null $client
     * @param int $wait Seconds between erasure attempts.
     */
    public function __construct(?config $config = null, ?assess_client $client = null, int $wait = self::ERASE_WAIT) {
        $this->config = $config ?? new config();
        $this->client = $client ?? new assess_client($this->config);
        $this->wait = $wait;
    }

    /**
     * Whether the plugin was ever set up on this site: a site that never configured it holds nothing in Vektra.
     *
     * @return bool
     */
    public function in_use(): bool {
        // A durable marker, set on the first successful call to Vektra: clearing the settings later does not
        // mean that Vektra holds nothing.
        if (!empty(get_config(config::COMPONENT, 'everused')) || $this->config->site_id() !== '') {
            return true;
        }
        foreach (array_keys(config::KEY_ROLES) as $role) {
            if ($this->config->key($role) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Every activity (cmid) where Vektra holds a row of the person that an erasure would remove: sessions,
     * new sessions granted, extra time, retention tombstones, technical records (vektra-stack #215). For
     * finding contexts and verifying an erasure: no transcript is downloaded, and the activities are not
     * validated beyond their id.
     *
     * @param int $userid
     * @return int[]
     * @throws \moodle_exception
     */
    public function subject_cmids(int $userid): array {
        $cmids = [];
        foreach ($this->pages(self::activities_path($userid)) as $json) {
            foreach (self::list_of($json, 'items') as $item) {
                $cmids[] = self::cmid_of($item);
            }
        }
        return array_values(array_unique($cmids));
    }

    /**
     * Every activity of a person with its data, validated (vektra-stack #215).
     *
     * @param int $userid
     * @param callable|null $keep fn(int $cmid): bool, the activities to return (null: all).
     * @return array[] {cmid, course_id, sessions[], resets[], extra_seconds, extra_granted_at, purged_sessions,
     *     technical_records}; a session is {session_no, state, close_reason, created_at, deadline_at, closed_at,
     *     transcript_id, turns (array|null), turns_unavailable (string|null)}.
     * @throws \moodle_exception
     */
    public function subject_activities(int $userid, ?callable $keep = null): array {
        $activities = [];
        foreach ($this->pages(self::activities_path($userid)) as $json) {
            foreach (self::list_of($json, 'items') as $item) {
                $cmid = self::cmid_of($item);
                if (array_key_exists($cmid, $activities)) {
                    // One entry per activity: a repeated one would overwrite the first in the export.
                    throw new \moodle_exception('privacy_export_failed', self::C);
                }
                if ($keep === null || $keep($cmid)) {
                    $activities[$cmid] = self::activity($item);
                } else {
                    $activities[$cmid] = null;
                }
            }
        }
        return array_values(array_filter($activities));
    }

    /**
     * Every transcript of a person, across all pages.
     *
     * @param int $userid
     * @param callable|null $keep fn(int $cmid): bool, the activities to export (null: all).
     * @param array|null $damaged When given, a transcript whose bytes do not match its hash is collected here
     *     ({transcript_id, cmid}) instead of failing the read, so that the caller can export everything else.
     * @return array[] {transcript_id, cmid, course_id, session_no, closed_at, filename, sha256, bytes}
     * @throws \moodle_exception
     */
    public function subject_transcripts(int $userid, ?callable $keep = null, ?array &$damaged = null): array {
        $items = [];
        foreach ($this->pages('/api/v1/assess/privacy/subjects/' . $userid . '/transcripts') as $json) {
            foreach (self::list_of($json, 'items') as $item) {
                // Only the selected activities' files are decoded and hash-checked: a damaged file of another
                // activity must not block an export it is not part of. A malformed entry fails the read.
                $cmid = self::cmid_of($item);
                if ($keep !== null && !$keep($cmid)) {
                    continue;
                }
                try {
                    $items[] = self::transcript($item);
                } catch (\moodle_exception $e) {
                    if ($damaged === null || $e->errorcode !== 'privacy_export_hash') {
                        throw $e;
                    }
                    $damaged[] = ['transcript_id' => $item['transcript_id'], 'cmid' => $cmid];
                }
            }
        }
        return $items;
    }

    /**
     * Every person with data in an activity, across all pages.
     *
     * @param int $cmid
     * @return int[]
     * @throws \moodle_exception
     */
    public function activity_subjects(int $cmid): array {
        $ids = [];
        foreach ($this->pages('/api/v1/assess/privacy/activities/' . $cmid . '/subjects') as $json) {
            foreach (self::list_of($json, 'userids') as $id) {
                if (!is_int($id) || $id <= 0) {
                    throw new \moodle_exception('privacy_export_failed', self::C);
                }
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * The path of a person's activities.
     *
     * @param int $userid
     * @return string
     */
    private static function activities_path(int $userid): string {
        return '/api/v1/assess/privacy/subjects/' . $userid . '/activities';
    }

    /**
     * The activity id of a list entry: a malformed one fails the read, it never silently drops the entry.
     *
     * @param mixed $item
     * @return int
     * @throws \moodle_exception
     */
    private static function cmid_of($item): int {
        if (!is_array($item) || !is_int($item['cmid'] ?? null) || $item['cmid'] <= 0) {
            throw new \moodle_exception('privacy_export_failed', self::C);
        }
        return $item['cmid'];
    }

    /**
     * A validated activity entry.
     *
     * @param mixed $item
     * @return array
     * @throws \moodle_exception
     */
    private static function activity($item): array {
        $cmid = self::cmid_of($item);
        $sessions = [];
        foreach (self::list_of($item, 'sessions') as $session) {
            $sessions[] = self::session($session);
        }
        $resets = [];
        foreach (self::list_of($item, 'resets') as $reset) {
            if (!is_array($reset) || !is_int($reset['after_session_no'] ?? null)) {
                throw new \moodle_exception('privacy_export_failed', self::C);
            }
            $resets[] = [
                'after_session_no' => $reset['after_session_no'],
                'granted_at' => self::text($reset['granted_at'] ?? null),
                'consumed_at' => self::text($reset['consumed_at'] ?? null),
            ];
        }
        $technical = $item['technical_records'] ?? null;
        if (!is_array($technical)) {
            throw new \moodle_exception('privacy_export_failed', self::C);
        }
        $counts = [];
        foreach (self::TECHNICAL as $name) {
            if (!is_int($technical[$name] ?? null) || $technical[$name] < 0) {
                throw new \moodle_exception('privacy_export_failed', self::C);
            }
            $counts[$name] = $technical[$name];
        }
        $extra = $item['extra_seconds'] ?? null;
        $purged = $item['purged_sessions'] ?? null;
        $course = $item['course_id'] ?? null;
        if (
            ($extra !== null && (!is_int($extra) || $extra < 0)) || !is_int($purged) || $purged < 0
                || ($course !== null && (!is_int($course) || $course <= 0))
        ) {
            throw new \moodle_exception('privacy_export_failed', self::C);
        }
        return [
            'cmid' => $cmid,
            'course_id' => $course,
            'sessions' => $sessions,
            'resets' => $resets,
            'extra_seconds' => $extra,
            'extra_granted_at' => self::text($item['extra_granted_at'] ?? null),
            'purged_sessions' => $purged,
            'technical_records' => $counts,
        ];
    }

    /**
     * A validated session: with a transcript id, with its turns, or with the reason its turns are missing.
     *
     * @param mixed $session
     * @return array
     * @throws \moodle_exception
     */
    private static function session($session): array {
        if (
            !is_array($session) || !is_int($session['session_no'] ?? null) || $session['session_no'] <= 0
                || !is_string($session['state'] ?? null)
        ) {
            throw new \moodle_exception('privacy_export_failed', self::C);
        }
        $tid = $session['transcript_id'] ?? null;
        $turns = $session['turns'] ?? null;
        $unavailable = $session['turns_unavailable'] ?? null;
        if ($tid !== null && !(is_string($tid) && transcript_proxy::valid_tid($tid))) {
            throw new \moodle_exception('privacy_export_failed', self::C);
        }
        if ($tid === null && $turns === null && !in_array($unavailable, self::TURNS_UNAVAILABLE, true)) {
            // Neither the transcript, nor the turns, nor a known reason: the content would silently be missing.
            throw new \moodle_exception('privacy_export_failed', self::C);
        }
        $list = null;
        if ($turns !== null) {
            $list = [];
            foreach (self::list_of($session, 'turns') as $turn) {
                if (
                    !is_array($turn) || !is_int($turn['turn_no'] ?? null) || !is_string($turn['role'] ?? null)
                        || !is_string($turn['kind'] ?? null) || !is_string($turn['text'] ?? null)
                ) {
                    throw new \moodle_exception('privacy_export_failed', self::C);
                }
                $list[] = ['turn_no' => $turn['turn_no'], 'role' => $turn['role'], 'kind' => $turn['kind'],
                    'text' => $turn['text']];
            }
        }
        return [
            'session_no' => $session['session_no'],
            'state' => $session['state'],
            'close_reason' => self::text($session['close_reason'] ?? null),
            'created_at' => self::text($session['created_at'] ?? null),
            'deadline_at' => self::text($session['deadline_at'] ?? null),
            'closed_at' => self::text($session['closed_at'] ?? null),
            'transcript_id' => $tid,
            'turns' => $list,
            'turns_unavailable' => $tid === null && $list === null ? $unavailable : null,
        ];
    }

    /**
     * A string field, or null.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function text($value): ?string {
        return is_string($value) ? $value : null;
    }

    /**
     * Every page of a paged privacy read (export key).
     *
     * @param string $path
     * @return array[] The pages' JSON.
     * @throws \moodle_exception
     */
    private function pages(string $path): array {
        $this->require_key('privacy_export');
        $pages = [];
        $cursor = '';
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $opts = ['timeout' => self::READ_TIMEOUT];
            if ($cursor !== '') {
                $opts['headers'] = ['X-Assess-Cursor' => $cursor];
            }
            $json = $this->ok($this->client->request('GET', $path, [], null, 'privacy_export', $opts), 'privacy_export_failed');
            $pages[] = $json;
            $cursor = self::cursor($json['next_cursor'] ?? null);
            if ($cursor === '') {
                return $pages;
            }
        }
        throw new \moodle_exception('privacy_export_failed', self::C);
    }

    /**
     * Throw when the erase key is not usable: checked before reading anything for an erasure.
     *
     * @throws \moodle_exception
     */
    public function require_erase_key(): void {
        $this->require_key('privacy_erase');
    }

    /**
     * Erase everything Vektra holds for a person on this site.
     *
     * @param int $userid
     * @throws \moodle_exception
     */
    public function erase_subject(int $userid): void {
        $this->erase('/api/v1/assess/privacy/subjects/' . $userid, null);
    }

    /**
     * Erase an activity's data: of the given people, or of everyone when $userids is null.
     *
     * @param int $cmid
     * @param int[]|null $userids
     * @throws \moodle_exception
     */
    public function erase_activity(int $cmid, ?array $userids): void {
        $path = '/api/v1/assess/privacy/activities/' . $cmid . '/subjects';
        if ($userids === null) {
            $this->erase($path, null);
            return;
        }
        $userids = array_values(array_unique(array_map('intval', $userids)));
        foreach (array_chunk($userids, self::MAX_USERIDS) as $chunk) {
            $this->erase($path, ['userids' => $chunk]);
        }
    }

    /**
     * DELETE, retrying while Vektra reports the erasure as pending; anything but "erased" throws.
     *
     * @param string $path
     * @param array|null $body
     * @throws \moodle_exception
     */
    private function erase(string $path, ?array $body): void {
        $this->require_key('privacy_erase');
        for ($attempt = 1; $attempt <= self::ERASE_ATTEMPTS; $attempt++) {
            $response = $this->client->request('DELETE', $path, [], $body, 'privacy_erase', ['timeout' => self::ERASE_TIMEOUT]);
            $pending = $response->status === 503 && $response->errorcode === 'ERR-ASSESS-027'
                && ($response->details['reason'] ?? null) === 'pending';
            if (!$pending) {
                break;
            }
            if ($attempt < self::ERASE_ATTEMPTS && $this->wait > 0) {
                sleep($this->wait);
            }
        }
        if ($pending) {
            throw new \moodle_exception('privacy_erase_pending', self::C);
        }
        $json = $this->ok($response, 'privacy_erase_failed');
        if (($json['outcome'] ?? null) === 'retained') {
            // Exam-evidence retention (OQ-020/OQ-033): Vektra kept the data on purpose; never a silent success.
            throw new \moodle_exception('privacy_erase_retained', self::C);
        }
        if (($json['outcome'] ?? null) !== 'erased') {
            throw new \moodle_exception('privacy_erase_failed', self::C);
        }
    }

    /**
     * The JSON of a 2xx response, or the exception.
     *
     * @param assess_response $response
     * @param string $identifier
     * @return array
     * @throws \moodle_exception
     */
    private function ok(assess_response $response, string $identifier): array {
        if (!$response->ok() || !is_array($response->json)) {
            throw new \moodle_exception(
                $identifier,
                self::C,
                '',
                null,
                'HTTP ' . $response->status . ' ' . (string) $response->errorcode
            );
        }
        return $response->json;
    }

    /**
     * Throw when the key of a role is not usable.
     *
     * @param string $role
     * @throws \moodle_exception
     */
    private function require_key(string $role): void {
        if (!$this->config->key_usable($role)) {
            $setting = get_string('settings_' . str_replace('_', '', $role) . 'key', self::C);
            throw new \moodle_exception('privacy_key_missing', self::C, '', $setting);
        }
    }

    /**
     * The list a page must carry: a missing or malformed list never counts as "no data".
     *
     * @param array $json
     * @param string $key
     * @return array
     * @throws \moodle_exception
     */
    private static function list_of(array $json, string $key): array {
        if (!isset($json[$key]) || !is_array($json[$key]) || array_values($json[$key]) !== $json[$key]) {
            throw new \moodle_exception('privacy_export_failed', self::C);
        }
        return $json[$key];
    }

    /**
     * A validated transcript item; the bytes are decoded and must match the reported sha256.
     *
     * @param mixed $item
     * @return array
     * @throws \moodle_exception
     */
    private static function transcript($item): array {
        if (
            !is_array($item) || !is_int($item['cmid'] ?? null) || !is_string($item['sha256'] ?? null)
                || !is_string($item['content_base64'] ?? null) || !is_string($item['transcript_id'] ?? null)
                || !transcript_proxy::valid_tid($item['transcript_id'])
        ) {
            throw new \moodle_exception('privacy_export_failed', self::C);
        }
        $bytes = base64_decode($item['content_base64'], true);
        if ($bytes === false || !hash_equals(strtolower($item['sha256']), hash('sha256', $bytes))) {
            // Never export bytes that are not the stored transcript.
            throw new \moodle_exception('privacy_export_hash', self::C);
        }
        $n = is_int($item['session_no'] ?? null) && $item['session_no'] > 0 ? $item['session_no'] : 1;
        $filename = is_string($item['filename'] ?? null) && preg_match(transcript_proxy::FILENAME_PATTERN, $item['filename'])
            ? $item['filename'] : 'transcript_' . $item['cmid'] . '_s' . $n . '.html';
        return [
            'transcript_id' => $item['transcript_id'],
            'cmid' => $item['cmid'],
            'course_id' => is_int($item['course_id'] ?? null) ? $item['course_id'] : 0,
            'session_no' => $n,
            'closed_at' => is_string($item['closed_at'] ?? null) ? $item['closed_at'] : null,
            'filename' => $filename,
            'sha256' => strtolower($item['sha256']),
            'bytes' => $bytes,
        ];
    }

    /**
     * The next page's cursor ('' on the last page). A cursor the plugin cannot send back throws: reading it as
     * the last page would export, or verify, only part of the data.
     *
     * @param mixed $cursor
     * @return string
     * @throws \moodle_exception
     */
    private static function cursor($cursor): string {
        if ($cursor === null) {
            return '';
        }
        if (is_int($cursor) && $cursor > 0) {
            return (string) $cursor;
        }
        if (is_string($cursor) && preg_match('/^[\x21-\x7E]{1,512}\z/', $cursor)) {
            return $cursor;
        }
        throw new \moodle_exception('privacy_export_failed', self::C);
    }
}
