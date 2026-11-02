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
 * Tests for the admin settings of local_vektradiscuss.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\admin;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for {@see setting_assess_key} and {@see setting_uiurl}.
 *
 * @covers \local_vektradiscuss\admin\setting_assess_key
 * @covers \local_vektradiscuss\admin\setting_uiurl
 */
final class settings_test extends \advanced_testcase {
    /**
     * Each test starts with a block admin key configured.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('apikey', 'adminkey-value', 'block_vektra');
    }

    /**
     * Build a key setting.
     *
     * @param string $name Setting name.
     * @param string $otherrole Role of the other key.
     * @return setting_assess_key
     */
    private function key_setting(string $name, string $otherrole): setting_assess_key {
        return new setting_assess_key('local_vektradiscuss/' . $name, 'Key', 'Desc', $otherrole);
    }

    /**
     * The block admin key is refused and nothing is stored.
     */
    public function test_admin_key_refused(): void {
        $error = $this->key_setting('mintkey', 'teacher')->write_setting('adminkey-value');

        $this->assertSame(get_string('error_key_is_adminkey', 'local_vektradiscuss'), $error);
        $this->assertFalse(get_config('local_vektradiscuss', 'mintkey'));
    }

    /**
     * Surrounding whitespace does not hide the admin key.
     */
    public function test_admin_key_refused_with_whitespace(): void {
        $error = $this->key_setting('teacherkey', 'mint')->write_setting("  adminkey-value\n");
        $this->assertNotSame('', $error);
        $this->assertFalse(get_config('local_vektradiscuss', 'teacherkey'));
    }

    /**
     * The same key for both roles is refused (against the stored value of the other key).
     */
    public function test_duplicate_key_refused(): void {
        set_config('teacherkey', 'shared-value', 'local_vektradiscuss');

        $error = $this->key_setting('mintkey', 'teacher')->write_setting('shared-value');

        $this->assertSame(get_string('error_key_duplicate', 'local_vektradiscuss'), $error);
        $this->assertFalse(get_config('local_vektradiscuss', 'mintkey'));
    }

    /**
     * A duplicate is also caught against the other key submitted in the same form.
     */
    public function test_duplicate_key_refused_against_submitted_value(): void {
        set_config('teacherkey', 'old-teacher-value', 'local_vektradiscuss');
        $_POST['s_local_vektradiscuss_teacherkey'] = 'shared-value';

        $error = $this->key_setting('mintkey', 'teacher')->write_setting('shared-value');
        unset($_POST['s_local_vektradiscuss_teacherkey']);

        $this->assertSame(get_string('error_key_duplicate', 'local_vektradiscuss'), $error);
    }

    /**
     * A distinct key is stored; an empty value clears it.
     */
    public function test_valid_key_stored_and_cleared(): void {
        $setting = $this->key_setting('mintkey', 'teacher');

        $this->assertSame('', $setting->write_setting('mint-value'));
        $this->assertSame('mint-value', get_config('local_vektradiscuss', 'mintkey'));

        $this->assertSame('', $setting->write_setting(''));
        $this->assertSame('', get_config('local_vektradiscuss', 'mintkey'));
    }

    /**
     * Data provider: UI origins.
     *
     * @return array
     */
    public static function uiurl_provider(): array {
        return [
            'https origin' => ['https://ui.example.test', true, 'https://ui.example.test'],
            'normalised' => ['HTTPS://UI.Example.test:8443/', true, 'https://ui.example.test:8443'],
            'empty clears' => ['', true, ''],
            'http refused' => ['http://ui.example.test', false, null],
            'path refused' => ['https://ui.example.test/ui', false, null],
            'query refused' => ['https://ui.example.test?x=1', false, null],
            'credentials refused' => ['https://user:pw@ui.example.test', false, null],
            'no scheme refused' => ['ui.example.test', false, null],
            'javascript scheme refused' => ['javascript://x', false, null],
        ];
    }

    /**
     * UI origin validation.
     *
     * @dataProvider uiurl_provider
     * @param string $value
     * @param bool $accepted
     * @param string|null $stored
     */
    public function test_uiurl(string $value, bool $accepted, ?string $stored): void {
        global $CFG;
        $CFG->debugdeveloper = false;
        $setting = new setting_uiurl('local_vektradiscuss/uiurl', 'UI', 'Desc');

        $error = $setting->write_setting($value);

        if ($accepted) {
            $this->assertSame('', $error);
            $this->assertSame($stored, get_config('local_vektradiscuss', 'uiurl'));
        } else {
            $this->assertNotSame('', $error);
            $this->assertFalse(get_config('local_vektradiscuss', 'uiurl'));
        }
    }

    /**
     * Plain http is accepted only under developer debugging.
     */
    public function test_uiurl_http_allowed_in_developer_mode(): void {
        global $CFG;
        $CFG->debugdeveloper = true;
        $setting = new setting_uiurl('local_vektradiscuss/uiurl', 'UI', 'Desc');

        $this->assertSame('', $setting->write_setting('http://localhost:8081'));
        $this->assertSame('http://localhost:8081', get_config('local_vektradiscuss', 'uiurl'));
    }

    /**
     * The settings page exists in the admin tree with all the settings, off by default.
     */
    public function test_settings_page_in_admin_tree(): void {
        global $CFG;
        $this->setAdminUser();
        require_once($CFG->libdir . '/adminlib.php');

        $page = admin_get_root(true, true)->locate('local_vektradiscuss');

        $this->assertInstanceOf(\admin_settingpage::class, $page);
        foreach (['enabled', 'uiurl', 'siteid', 'mintkey', 'teacherkey'] as $name) {
            $this->assertTrue(isset($page->settings->{'local_vektradiscuss' . $name}), $name);
        }
        $this->assertFalse((new \local_vektradiscuss\local\config())->is_enabled());
    }
}
