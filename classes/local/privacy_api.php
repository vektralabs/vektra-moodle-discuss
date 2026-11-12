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
        if ($this->config->site_id() !== '') {
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
     * The activities (cmids) where a person has transcripts, without downloading or checking the files: for
     * finding contexts and verifying an erasure, which a damaged transcript must never block.
     *
     * @param int $userid
     * @return int[]
     * @throws \moodle_exception
     */
    public function subject_cmids(int $userid): array {
        $cmids = [];
        foreach ($this->pages('/api/v1/assess/privacy/subjects/' . $userid . '/transcripts') as $json) {
            foreach (is_array($json['items'] ?? null) ? $json['items'] : [] as $item) {
                if (is_array($item) && is_int($item['cmid'] ?? null)) {
                    $cmids[] = $item['cmid'];
                }
            }
        }
        return array_values(array_unique($cmids));
    }

    /**
     * Every transcript of a person, across all pages.
     *
     * @param int $userid
     * @return array[] {transcript_id, cmid, course_id, session_no, closed_at, filename, sha256, bytes}
     * @throws \moodle_exception
     */
    public function subject_transcripts(int $userid): array {
        $items = [];
        foreach ($this->pages('/api/v1/assess/privacy/subjects/' . $userid . '/transcripts') as $json) {
            foreach (is_array($json['items'] ?? null) ? $json['items'] : [] as $item) {
                $items[] = self::transcript($item);
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
            foreach (is_array($json['userids'] ?? null) ? $json['userids'] : [] as $id) {
                if (is_int($id) && $id > 0) {
                    $ids[] = $id;
                }
            }
        }
        return array_values(array_unique($ids));
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
     * A validated transcript item; the bytes are decoded and must match the reported sha256.
     *
     * @param mixed $item
     * @return array
     * @throws \moodle_exception
     */
    private static function transcript($item): array {
        if (
            !is_array($item) || !is_int($item['cmid'] ?? null) || !is_string($item['sha256'] ?? null)
                || !is_string($item['content_base64'] ?? null)
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
            'transcript_id' => is_string($item['transcript_id'] ?? null) ? $item['transcript_id'] : '',
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
        if (is_string($cursor) && preg_match('/^[\x21-\x7E]{1,512}$/', $cursor)) {
            return $cursor;
        }
        throw new \moodle_exception('privacy_export_failed', self::C);
    }
}
