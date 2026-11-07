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
 * Handler of the student AJAX endpoint (ajax.php): advisory state and server-side mint.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

use local_vektradiscuss\local\http\assess_client;
use local_vektradiscuss\local\http\assess_response;

/**
 * The student endpoint, kept out of ajax.php so that every refusal path is unit-tested.
 *
 * Moodle decides who may ask (login, course access, sesskey, capability, login-as); Vektra decides
 * everything else on every request (ARCH-065). Nothing is cached and nothing from Vektra's error
 * responses reaches the browser: any failure is the generic "unavailable" state.
 */
final class student_endpoint {
    /** @var string[] Actions. */
    public const ACTIONS = ['state', 'mint'];

    /** @var string[] Activity modules the discussion supports. */
    public const MODULES = ['quiz', 'assign'];

    /** @var string Mint route. */
    private const PATH_TOKENS = '/api/v1/assess/tokens';

    /** @var string State route (the cmid travels in the token). */
    private const PATH_STATE = '/api/v1/assess/state';

    /** @var int Upper bound of a token's length, as a sanity check of the mint response. */
    private const MAX_TOKEN_LENGTH = 4096;

    /** @var config Configuration. */
    private config $config;

    /** @var assess_client Vektra client. */
    private assess_client $client;

    /**
     * Constructor.
     *
     * @param config|null $config
     * @param assess_client|null $client
     */
    public function __construct(?config $config = null, ?assess_client $client = null) {
        $this->config = $config ?? new config();
        $this->client = $client ?? new assess_client($this->config);
    }

    /**
     * Handle one request.
     *
     * Moodle refusals are thrown (require_login, sesskey, capability), before any Vektra call.
     *
     * @param string $action state or mint.
     * @param int $cmid Course module id.
     * @param int $attemptid Viewed quiz attempt id (review page only), used for the state 9 pointer.
     * @return array The response document.
     * @throws \moodle_exception On a Moodle refusal.
     */
    public function handle(string $action, int $cmid, int $attemptid = 0): array {
        global $USER;

        if (!in_array($action, self::ACTIONS, true)) {
            throw new \invalid_parameter_exception('Unknown action.');
        }
        [$course, $cm] = get_course_and_cm_from_cmid($cmid);
        require_login($course, false, $cm, false, true);
        require_sesskey();
        $context = \context_module::instance($cm->id);
        if ($action === 'mint') {
            require_capability('local/vektradiscuss:start', $context);
        } else if (!has_any_capability(['local/vektradiscuss:start', 'local/vektradiscuss:viewowntranscript'], $context)) {
            throw new \required_capability_exception($context, 'local/vektradiscuss:start', 'nopermissions', '');
        }

        // Fast path of REQ-084: no Vektra call at all. The positive assertion in the mint body is the
        // authoritative one.
        if (self::is_loginas()) {
            return state_dto::bare(state_dto::LOGINAS);
        }
        if (!$this->config->is_enabled() || !in_array($cm->modname, self::MODULES, true)) {
            return state_dto::bare(state_dto::UNAVAILABLE);
        }

        if ($action === 'mint') {
            $uiorigin = injector::usable_ui_origin($this->config);
            if ($uiorigin === '') {
                return state_dto::bare(state_dto::UNAVAILABLE);
            }
            $minted = $this->mint((int) $USER->id, (int) $cm->id, (int) $course->id);
            if ($minted === null) {
                return state_dto::bare(state_dto::UNAVAILABLE);
            }
            return ['state' => 'ready', 'token' => $minted['token'], 'expiresin' => $minted['expiresin'],
                'uiorigin' => $uiorigin];
        }

        $minted = $this->mint((int) $USER->id, (int) $cm->id, (int) $course->id);
        if ($minted === null) {
            return state_dto::bare(state_dto::UNAVAILABLE);
        }
        $response = $this->client->request('GET', self::PATH_STATE, [], null, 'mint', ['bearer' => $minted['token']]);
        if (!$response->ok()) {
            self::log_failure('state', $response);
            return state_dto::bare(state_dto::UNAVAILABLE);
        }
        $dto = state_dto::from_vektra($response->json);
        return self::point_to_selected($dto, $cm, $attemptid, (int) $USER->id);
    }

    /**
     * Whether the current session is a "log in as" session.
     *
     * @return bool
     */
    public static function is_loginas(): bool {
        global $USER;
        return \core\session\manager::is_loggedinas() || !empty($USER->realuser);
    }

    /**
     * Mint a pre-session token (POST /tokens with the assess:mint key).
     *
     * The login-as fields are always sent, explicitly: Vektra refuses their absence (ARCH-067). The
     * Moodle ids are hints that Vektra re-resolves.
     *
     * @param int $userid
     * @param int $cmid
     * @param int $courseid
     * @return array|null [token, expiresin], or null on any failure.
     */
    private function mint(int $userid, int $cmid, int $courseid): ?array {
        $body = [
            'sub' => $userid,
            'cmid' => $cmid,
            'courseid' => $courseid,
            'loginas' => false,
            'realuser_id' => null,
            'plugin_version' => $this->config->plugin_version(),
        ];
        $response = $this->client->request('POST', self::PATH_TOKENS, [], $body, 'mint');
        if (!$response->ok()) {
            self::log_failure('mint', $response);
            return null;
        }
        $token = $response->json['token'] ?? null;
        $expiresin = $response->json['expires_in'] ?? null;
        if (
            !is_string($token) || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH
                || preg_match('/[^A-Za-z0-9._~+\/=-]/', $token) || !is_int($expiresin) || $expiresin <= 0
        ) {
            debugging('Vektra assess mint: malformed token response', DEBUG_DEVELOPER);
            return null;
        }
        return ['token' => $token, 'expiresin' => $expiresin];
    }

    /**
     * State 9: on a quiz review page, point to the selected attempt when the viewed one is not it.
     *
     * Vektra selects the attempt (BR-012); the comparison here is for display only.
     *
     * @param array $dto
     * @param \cm_info $cm
     * @param int $attemptid
     * @param int $userid
     * @return array
     */
    private static function point_to_selected(array $dto, \cm_info $cm, int $attemptid, int $userid): array {
        global $DB;
        if (
            $attemptid <= 0 || $cm->modname !== 'quiz' || !isset($dto['selected_attempt_no'])
                || !in_array($dto['state'], ['eligible', 'reset_granted', 'active', 'closed'], true)
        ) {
            return $dto;
        }
        $viewed = $DB->get_field('quiz_attempts', 'attempt', ['id' => $attemptid, 'quiz' => $cm->instance, 'userid' => $userid]);
        if ($viewed === false || (int) $viewed === $dto['selected_attempt_no']) {
            return $dto;
        }
        return ['state' => state_dto::NOT_SELECTED, 'reason' => null, 'selected_attempt_no' => $dto['selected_attempt_no']];
    }

    /**
     * Developer log of a failed call: the action, status and request id only.
     *
     * @param string $action
     * @param assess_response $response
     */
    private static function log_failure(string $action, assess_response $response): void {
        debugging(
            "Vektra assess {$action} failed: HTTP {$response->status}" .
                ($response->requestid !== null ? ' request ' . clean_param($response->requestid, PARAM_ALPHANUMEXT) : ''),
            DEBUG_DEVELOPER
        );
    }
}
