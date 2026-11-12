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
 * The site administrator's setup check (REQ-079, REQ-089).
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

use local_vektradiscuss\local\http\assess_client;
use local_vektradiscuss\local\http\assess_response;

/**
 * Starts Vektra's setup check for this site and reads its report: item names and results only, never more.
 * The local part says which keys are configured (never their values) and whether the UI origin is usable.
 */
class setup_check {
    /** @var string Component. */
    private const C = 'local_vektradiscuss';

    /** @var string[] Report statuses still in progress. */
    public const IN_PROGRESS = ['queued', 'running'];

    /** @var string[] Item results with their own label. */
    private const RESULTS = ['OK', 'MISSING', 'UNVERIFIABLE'];

    /** @var assess_client Client. */
    private assess_client $client;

    /** @var config Configuration. */
    private config $config;

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
     * Start a check.
     *
     * @return array [string|null report id, string|null error language string]
     */
    public function start(): array {
        $site = $this->config->site_id();
        if ($site === '' || !$this->config->key_usable('teacher')) {
            return [null, 'setupcheck_not_configured'];
        }
        $response = $this->client->request(
            'POST',
            '/api/v1/assess/sites/' . rawurlencode($site) . '/setup-check',
            [],
            null,
            'teacher'
        );
        $id = $response->ok() ? ($response->json['report_id'] ?? null) : null;
        if (is_string($id) && preg_match('/^[0-9a-f-]{36}$/', $id)) {
            return [$id, null];
        }
        return [null, self::error($response)];
    }

    /**
     * Read a report.
     *
     * @param string $reportid
     * @return array|null The template context, or null when it cannot be read.
     */
    public function report(string $reportid): ?array {
        $site = $this->config->site_id();
        if ($site === '' || !preg_match('/^[0-9a-f-]{36}$/', $reportid) || !$this->config->key_usable('teacher')) {
            return null;
        }
        $response = $this->client->request(
            'GET',
            '/api/v1/assess/sites/' . rawurlencode($site) . '/setup-check/' . $reportid,
            [],
            null,
            'teacher'
        );
        $json = $response->ok() ? $response->json : null;
        if (!is_array($json) || !is_string($json['status'] ?? null)) {
            return null;
        }
        $items = [];
        foreach (is_array($json['items'] ?? null) ? array_slice($json['items'], 0, 100) : [] as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || !preg_match('/^[a-z0-9_]{1,64}$/', $item['name'])) {
                continue;
            }
            $result = in_array($item['result'] ?? null, self::RESULTS, true) ? $item['result'] : 'UNVERIFIABLE';
            $reason = is_string($item['reason_code'] ?? null) && preg_match('/^[a-z0-9_]{1,64}$/', $item['reason_code'])
                ? $item['reason_code'] : null;
            $items[] = [
                'name' => self::item_label($item['name']),
                'code' => $item['name'],
                'result' => get_string('setupcheck_result_' . strtolower($result), self::C),
                'ok' => $result === 'OK',
                'reason' => $reason,
            ];
        }
        $status = in_array($json['status'], ['queued', 'running', 'done', 'failed'], true) ? $json['status'] : 'failed';
        $allok = $status === 'done' && $items && !in_array(false, array_column($items, 'ok'), true);
        return [
            'status' => get_string('setupcheck_status_' . $status, self::C),
            'inprogress' => in_array($status, self::IN_PROGRESS, true),
            'done' => $status === 'done',
            'allok' => $allok,
            'release' => is_string($json['lms_release'] ?? null) ? $json['lms_release'] : null,
            'items' => $items,
            'hasitems' => (bool) $items,
        ];
    }

    /**
     * The local checks: which keys are configured (never their values), and the UI origin.
     *
     * @return array
     */
    public function local(): array {
        $keys = [];
        foreach (config::KEY_ROLES as $role => $setting) {
            $keys[] = [
                'name' => get_string('settings_' . $setting, self::C),
                'ok' => $this->config->key_usable($role),
                'state' => get_string($this->config->key_usable($role) ? 'setupcheck_key_ok'
                    : ($this->config->key($role) === '' ? 'setupcheck_key_missing' : 'setupcheck_key_refused'), self::C),
            ];
        }
        $origin = injector::usable_ui_origin($this->config);
        return [
            'enabled' => $this->config->is_enabled(),
            'siteid' => $this->config->site_id() !== '',
            'keys' => $keys,
            'uiorigin' => $origin !== '',
            'uiorigintext' => $origin !== '' ? $origin : get_string('setupcheck_uiorigin_bad', self::C),
        ];
    }

    /**
     * A readable name for an item, or the item name itself when the plugin does not know it.
     *
     * @param string $name
     * @return string
     */
    private static function item_label(string $name): string {
        return get_string_manager()->string_exists('setupcheck_item_' . $name, self::C)
            ? get_string('setupcheck_item_' . $name, self::C) : $name;
    }

    /**
     * The language string of a failed start.
     *
     * @param assess_response $response
     * @return string
     */
    private static function error(assess_response $response): string {
        return match (true) {
            $response->status === 429 => 'setupcheck_too_soon',
            $response->errorcode === 'ERR-ASSESS-001' => 'setupcheck_worker_down',
            $response->status === 404 => 'setupcheck_unknown_site',
            in_array($response->status, [401, 403], true) => 'setupcheck_key_refused_by_vektra',
            default => 'setupcheck_unreachable',
        };
    }
}
