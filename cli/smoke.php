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
 * Live smoke of the Vektra assess API from the Moodle side (CLI, dev and staging only).
 *
 * Calls GET /health/assess, an assess:mint endpoint with the mint key and with the block admin key
 * (which MUST be refused: the admin scope does not satisfy the non-inheriting assess:* scopes,
 * ARCH-067, NFR-014), and the setup-check endpoint with the teacher key. Prints the raw HTTP
 * status of every call. Exit code: 0 all expectations met, 1 an expectation failed, 2 BLOCKED
 * (an endpoint is not available on that backend, so the 403/2xx pair cannot be recorded).
 *
 * Keys come from the command line, never from the database, and are never printed. The admin key
 * is sent ONLY by the deliberate negative probe of this script; assess_client itself refuses it.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

use local_vektradiscuss\local\explicit_config;
use local_vektradiscuss\local\http\assess_client;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'apiurl' => '',
    'mintkey' => '',
    'teacherkey' => '',
    'adminkey' => '',
    'site' => '',
    'mintpath' => '',
    'allow-local' => false,
], ['h' => 'help']);

if ($unrecognised || $options['help'] || $options['apiurl'] === '' || $options['mintkey'] === '') {
    cli_writeln(<<<HELP
Usage: php local/vektradiscuss/cli/smoke.php --apiurl=URL --mintkey=KEY [options]

  --apiurl=URL       Vektra API base URL (dev: http allowed).
  --mintkey=KEY      Key with the assess:mint scope.
  --teacherkey=KEY   Key with the assess:teacher scope (enables the setup-check call).
  --adminkey=KEY     The block admin key, for the negative probe (must be refused with 403).
  --site=ID          Site identifier for the setup-check path.
  --mintpath=PATH    An assess:mint endpoint under /api/v1/assess/ to probe (none exists before the
                     session-lifecycle plan: without it the pair is reported BLOCKED).
  --allow-local      Let Moodle's curl reach loopback and private addresses on any port (dev only).
HELP);
    exit($options['help'] ? 0 : 1);
}

if ($options['allow-local']) {
    // Dev only: the curl security helper blocks loopback and non-standard ports by default.
    $CFG->curlsecurityblockedhosts = '';
    $CFG->curlsecurityallowedport = '';
    $CFG->debugdeveloper = true;
}

$cliconfig = new explicit_config(
    $options['apiurl'],
    ['mint' => $options['mintkey'], 'teacher' => $options['teacherkey']],
    $options['adminkey']
);

$client = new assess_client($cliconfig);
$rows = [];
$failed = false;
$blocked = false;

// Record a result row: name, raw HTTP status, note and verdict (PASS, FAIL or BLOCKED).
$record = function (string $name, int $status, string $note, string $verdict) use (&$rows, &$failed, &$blocked): void {
    $rows[] = [$name, $status, $verdict, $note];
    $failed = $failed || $verdict === 'FAIL';
    $blocked = $blocked || $verdict === 'BLOCKED';
};

// Raw GET with a bearer, bypassing assess_client (outside its path prefix, or the deliberate negative probe).
// Returns [HTTP status, Vektra error code or empty].
$rawget = function (string $url, string $bearer): array {
    $curl = new \curl();
    $curl->setopt(['CURLOPT_FOLLOWLOCATION' => 0, 'CURLOPT_TIMEOUT' => 5, 'CURLOPT_CONNECTTIMEOUT' => 3]);
    $curl->setHeader(['Accept: application/json'] + ($bearer !== '' ? [1 => 'Authorization: Bearer ' . $bearer] : []));
    $body = $curl->get($url);
    $data = json_decode((string) $body, true);
    return [(int) ($curl->get_info()['http_code'] ?? 0), (string) ($data['error']['code'] ?? '')];
};

// 1. Readiness of the assess feature.
[$status, $code] = $rawget($cliconfig->api_url() . '/health/assess', $options['mintkey']);
$record('GET /health/assess (mint key)', $status, $code, $status === 200 ? 'PASS' : ($status === 0 ? 'FAIL' : 'BLOCKED'));

// 2. The non-inheriting scope proof: mint key accepted, admin key refused.
$mintpath = trim($options['mintpath']);
if ($mintpath === '') {
    $record('mint key on an assess:mint endpoint', 0, 'no --mintpath: no such endpoint on this backend', 'BLOCKED');
    $record('block admin key on an assess:mint endpoint', 0, 'no --mintpath', 'BLOCKED');
} else {
    $response = $client->request('GET', $mintpath, [], null, 'mint');
    $verdict = $response->ok() ? 'PASS' : ($response->status === 404 ? 'BLOCKED' : 'FAIL');
    $record('mint key on ' . $mintpath, $response->status, (string) $response->errorcode, $verdict);

    if ($options['adminkey'] === '') {
        $record('block admin key on ' . $mintpath, 0, 'no --adminkey', 'BLOCKED');
    } else {
        [$status, $code] = $rawget($cliconfig->api_url() . $mintpath, $options['adminkey']);
        $refused = $status === 403 && str_starts_with($code, 'ERR-AUTH-');
        $record('block admin key on ' . $mintpath, $status, $code, $refused ? 'PASS' : ($status === 404 ? 'BLOCKED' : 'FAIL'));
    }
}

// 3. The setup check with the teacher key.
if ($options['teacherkey'] === '' || $options['site'] === '') {
    $record('POST setup-check (teacher key)', 0, 'needs --teacherkey and --site', 'BLOCKED');
} else {
    $path = '/api/v1/assess/sites/' . rawurlencode($options['site']) . '/setup-check';
    $response = $client->request('POST', $path, [], null, 'teacher');
    $verdict = $response->status === 202 ? 'PASS' : ($response->status === 404 ? 'BLOCKED' : 'FAIL');
    $record('POST setup-check (teacher key)', $response->status, (string) $response->errorcode, $verdict);
}

// The client logs failures through debugging(); the table below is the report.
foreach ($rows as [$name, $status, $verdict, $note]) {
    cli_writeln(sprintf('%-7s HTTP %-3d  %s%s', $verdict, $status, $name, $note !== '' ? '  [' . $note . ']' : ''));
}
cli_writeln($failed ? 'RESULT: FAILED' : ($blocked ? 'RESULT: BLOCKED (not everything could be exercised)' : 'RESULT: OK'));
exit($failed ? 1 : ($blocked ? 2 : 0));
