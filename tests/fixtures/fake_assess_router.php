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
 * Fake assess backend for the loopback HTTP test (router script of `php -S`).
 *
 * Every request is appended to the file named by FAKE_ASSESS_LOG as one JSON line.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Not a Moodle script: it runs standalone under the PHP built-in web server.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalNotNeeded

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$body = file_get_contents('php://input');
file_put_contents(getenv('FAKE_ASSESS_LOG'), json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'query' => $_SERVER['QUERY_STRING'] ?? '',
    'authorization' => $headers['authorization'] ?? null,
    'version' => $headers['x-vektra-plugin-version'] ?? null,
    'contenttype' => $headers['content-type'] ?? null,
    'body' => $body,
]) . "\n", FILE_APPEND);

header('Content-Type: application/json');
header('X-Request-ID: req-fake-1');

switch ($path) {
    case '/api/v1/assess/ok':
        echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'echo' => json_decode($body, true)]);
        break;
    case '/api/v1/assess/forbidden':
        http_response_code(403);
        echo json_encode(['error' => ['code' => 'ERR-AUTH-003', 'message' => 'scope not allowed']]);
        break;
    case '/api/v1/assess/redirect':
        http_response_code(302);
        header('Location: http://' . $_SERVER['HTTP_HOST'] . '/api/v1/assess/redirect-target');
        break;
    case '/api/v1/assess/redirect-target':
        echo json_encode(['reached' => true]);
        break;
    case '/api/v1/assess/stream':
        header('Content-Type: application/octet-stream');
        $total = (int) ($_GET['bytes'] ?? 1000);
        for ($sent = 0; $sent < $total; $sent += 100) {
            echo str_repeat('x', min(100, $total - $sent));
            flush();
        }
        break;
    case '/api/v1/assess/stream-forbidden':
        http_response_code(403);
        echo json_encode(['error' => ['code' => 'ERR-AUTH-003', 'message' => 'download token refused']]);
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => ['code' => 'ERR-ASSESS-003', 'message' => 'unknown']]);
}
