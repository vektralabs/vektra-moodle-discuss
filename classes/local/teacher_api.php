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
 * Teacher calls to the Vektra assess API.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

use local_vektradiscuss\local\http\assess_client;
use local_vektradiscuss\local\http\assess_response;

/**
 * The activity configuration and the opening fallback, with the teacher key (assess:teacher).
 *
 * Every call names the acting teacher, the course and the activity type: Vektra re-checks that the
 * actor edits this activity (ERR-ASSESS-021) and uses the course and type to queue a fresh check when
 * its facts are stale. Nothing is cached: each call reads or writes Vektra's current state.
 */
class teacher_api {
    /** @var string[] The activity types of the assessment discussion. */
    public const ACTIVITY_TYPES = ['quiz', 'assign'];

    /** @var int Total timeout of a call made while a form renders or saves, in seconds. */
    public const FORM_TIMEOUT = 4;

    /** @var string Version of the attestation text the opening-date form shows (audited by Vektra). */
    public const ATTESTATION_TEXT_VERSION = 'discuss-opening-1';

    /** @var assess_client Client. */
    private assess_client $client;

    /**
     * Constructor.
     *
     * @param assess_client|null $client
     */
    public function __construct(?assess_client $client = null) {
        $this->client = $client ?? new assess_client();
    }

    /**
     * GET the activity configuration (no secret text, no address).
     *
     * @param int $cmid
     * @param int $courseid
     * @param string $type quiz or assign
     * @param int $actorid The teacher's Moodle user id.
     * @return assess_response
     */
    public function get_config(int $cmid, int $courseid, string $type, int $actorid): assess_response {
        self::assert_type($type);
        return $this->client->request('GET', self::config_path($cmid), [], null, 'teacher', [
            'timeout' => self::FORM_TIMEOUT,
            'headers' => [
                'X-Assess-Actor' => (string) $actorid,
                'X-Assess-Course' => (string) $courseid,
                'X-Assess-Activity-Type' => $type,
            ],
        ]);
    }

    /**
     * PUT the changed fields of the activity configuration.
     *
     * @param int $cmid
     * @param int $courseid
     * @param string $type quiz or assign
     * @param int $actorid
     * @param int $expectedversion The version read when the form was rendered (0: never saved).
     * @param array $fields Only the changed fields; absent means unchanged.
     * @return assess_response
     */
    public function put_config(int $cmid, int $courseid, string $type, int $actorid, int $expectedversion, array $fields): assess_response {
        self::assert_type($type);
        $body = [
            'actor_userid' => $actorid,
            'courseid' => $courseid,
            'activity_type' => $type,
            'expected_version' => $expectedversion,
        ] + $fields;
        return $this->client->request('PUT', self::config_path($cmid), [], $body, 'teacher', ['timeout' => self::FORM_TIMEOUT]);
    }

    /**
     * PUT the teacher's opening date (REQ-091), with the attestation the form showed.
     *
     * @param int $cmid
     * @param int $courseid
     * @param string $type quiz or assign
     * @param int $actorid
     * @param int $openingat Unix time.
     * @return assess_response
     */
    public function put_opening_fallback(int $cmid, int $courseid, string $type, int $actorid, int $openingat): assess_response {
        self::assert_type($type);
        $body = [
            'actor_userid' => $actorid,
            'courseid' => $courseid,
            'activity_type' => $type,
            'opening_at' => gmdate('Y-m-d\TH:i:s\Z', $openingat),
            'attestation' => true,
            'attestation_text_version' => self::ATTESTATION_TEXT_VERSION,
        ];
        return $this->client->request(
            'PUT',
            '/api/v1/assess/activities/' . $cmid . '/opening-fallback',
            [],
            $body,
            'teacher',
            ['timeout' => self::FORM_TIMEOUT]
        );
    }

    /**
     * The configuration route of an activity.
     *
     * @param int $cmid
     * @return string
     */
    private static function config_path(int $cmid): string {
        if ($cmid <= 0) {
            throw new \coding_exception('A course module id is required.');
        }
        return '/api/v1/assess/activities/' . $cmid . '/config';
    }

    /**
     * Refuse a type outside quiz and assign (a programming error).
     *
     * @param string $type
     */
    private static function assert_type(string $type): void {
        if (!in_array($type, self::ACTIVITY_TYPES, true)) {
            throw new \coding_exception('Unsupported activity type: ' . $type);
        }
    }
}
