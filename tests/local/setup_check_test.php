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
 * Tests for the setup check.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

use local_vektradiscuss\local\http\assess_client;
use local_vektradiscuss\tests\scripted_transport;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/scripted_transport.php');

/**
 * Tests for {@see setup_check}: start, the report by item name, failures named, key state without values.
 *
 * @covers \local_vektradiscuss\local\setup_check
 */
final class setup_check_test extends \advanced_testcase {
    /** @var string A report id. */
    private const REPORT = '59211260-ca06-479a-9759-a530210fdb89';

    /** @var scripted_transport Transport. */
    private scripted_transport $transport;

    /**
     * Enabled, with a site id and a teacher key.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_vektradiscuss');
        set_config('siteid', 'e3629a63-4ed5-474e-b9a5-68ef2880c330', 'local_vektradiscuss');
        set_config('apiurl', 'https://vektra.example.test', 'block_vektra');
        set_config('apikey', 'test-admin-key-not-a-secret', 'block_vektra');
        set_config('teacherkey', 'test-teacher-key', 'local_vektradiscuss');
        set_config('uiurl', 'https://ui.example.test', 'local_vektradiscuss');
        $this->transport = new scripted_transport();
    }

    /**
     * The check under test.
     *
     * @return setup_check
     */
    private function check(): setup_check {
        return new setup_check(null, new assess_client(null, $this->transport));
    }

    /**
     * Start: POST on the site's route with the teacher key; 429 and a down worker are named.
     */
    public function test_start(): void {
        $this->transport->respond(202, ['report_id' => self::REPORT]);
        $this->assertSame([self::REPORT, null], $this->check()->start());
        $this->assertSame('POST', $this->transport->calls[0]['method']);
        $this->assertStringEndsWith('/sites/e3629a63-4ed5-474e-b9a5-68ef2880c330/setup-check', $this->transport->calls[0]['url']);
        $this->assertSame('Bearer test-teacher-key', $this->transport->header_map(0)['authorization']);

        $this->transport->respond(429, ['error' => ['code' => 'ERR-ASSESS-002', 'message' => 'too soon']]);
        $this->assertSame([null, 'setupcheck_too_soon'], $this->check()->start());
        $this->transport->respond(503, ['error' => ['code' => 'ERR-ASSESS-001', 'message' => 'no worker']]);
        $this->assertSame([null, 'setupcheck_worker_down'], $this->check()->start());
        $this->assertDebuggingCalledCount(2);

        set_config('siteid', '', 'local_vektradiscuss');
        $this->assertSame([null, 'setupcheck_not_configured'], $this->check()->start());
        $this->assertCount(3, $this->transport->calls);
    }

    /**
     * The report: each item by name with its result; a MISSING item is named, an unknown item is shown as is.
     */
    public function test_report_names_the_failing_item(): void {
        $this->transport->respond(200, ['report_id' => self::REPORT, 'status' => 'done', 'lms_release' => '4.3.11+',
            'items' => [
                ['name' => 'token_works', 'result' => 'OK', 'reason_code' => null],
                ['name' => 'privacy_config', 'result' => 'MISSING', 'reason_code' => 'privacy_config_unset'],
                ['name' => 'future_item', 'result' => 'OK', 'reason_code' => null],
                ['name' => '<b>bad</b>', 'result' => 'OK'],
            ]]);
        $report = $this->check()->report(self::REPORT);
        $this->assertTrue($report['done']);
        $this->assertFalse($report['allok']);
        $this->assertCount(3, $report['items']);
        $this->assertSame(get_string('setupcheck_item_privacy_config', 'local_vektradiscuss'), $report['items'][1]['name']);
        $this->assertFalse($report['items'][1]['ok']);
        $this->assertSame('privacy_config_unset', $report['items'][1]['reason']);
        $this->assertSame('future_item', $report['items'][2]['name']);

        $this->transport->respond(200, ['report_id' => self::REPORT, 'status' => 'running', 'items' => []]);
        $this->assertTrue($this->check()->report(self::REPORT)['inprogress']);
        $this->assertNull($this->check()->report('not-a-uuid'));
    }

    /**
     * A transport failure gives no report at all (no item shown as OK).
     */
    public function test_failure_shows_no_item(): void {
        $this->transport->respond(0);
        $this->assertNull($this->check()->report(self::REPORT));
        $this->assertDebuggingCalled();
    }

    /**
     * Local part: which keys are configured, never their values.
     */
    public function test_local_never_shows_key_values(): void {
        $local = $this->check()->local();
        $this->assertTrue($local['uiorigin']);
        $states = array_column($local['keys'], 'state', 'name');
        $this->assertSame(
            get_string('setupcheck_key_ok', 'local_vektradiscuss'),
            $states[get_string('settings_teacherkey', 'local_vektradiscuss')]
        );
        $this->assertSame(
            get_string('setupcheck_key_missing', 'local_vektradiscuss'),
            $states[get_string('settings_mintkey', 'local_vektradiscuss')]
        );
        $this->assertStringNotContainsString('test-teacher-key', json_encode($local));
    }
}
