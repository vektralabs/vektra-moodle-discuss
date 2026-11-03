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
 * Tests for the plugin configuration reader.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

/**
 * Tests for {@see config}.
 *
 * @covers \local_vektradiscuss\local\config
 * @covers \local_vektradiscuss\local\explicit_config
 */
final class config_test extends \advanced_testcase {
    /**
     * Each test starts from an unconfigured site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * An unconfigured site: disabled, nothing set, no usable key.
     */
    public function test_defaults(): void {
        $config = new config();

        $this->assertFalse($config->is_enabled());
        $this->assertSame('', $config->api_url());
        $this->assertSame('', $config->public_url());
        $this->assertSame('', $config->ui_origin());
        $this->assertSame('', $config->site_id());
        $this->assertSame('', $config->key('mint'));
        $this->assertFalse($config->key_usable('mint'));
        $this->assertFalse($config->key_usable('teacher'));
    }

    /**
     * The kill switch follows the setting.
     */
    public function test_is_enabled(): void {
        set_config('enabled', 1, 'local_vektradiscuss');
        $this->assertTrue((new config())->is_enabled());
        set_config('enabled', 0, 'local_vektradiscuss');
        $this->assertFalse((new config())->is_enabled());
    }

    /**
     * The API URL comes from the block, trimmed, without trailing slash; the public URL falls back to it.
     */
    public function test_urls_from_block(): void {
        set_config('apiurl', ' https://vektra.example.test/ ', 'block_vektra');
        $config = new config();
        $this->assertSame('https://vektra.example.test', $config->api_url());
        $this->assertSame('https://vektra.example.test', $config->public_url());

        set_config('publicurl', 'https://public.example.test/some/path/', 'block_vektra');
        $this->assertSame('https://public.example.test/some/path', $config->public_url());
    }

    /**
     * The UI origin: own setting wins; otherwise the origin of the block public URL.
     */
    public function test_ui_origin_fallback(): void {
        set_config('publicurl', 'https://Public.Example.test:8443/some/path', 'block_vektra');
        $config = new config();
        $this->assertSame('https://public.example.test:8443', $config->ui_origin());

        set_config('uiurl', 'https://ui.example.test', 'local_vektradiscuss');
        $this->assertSame('https://ui.example.test', $config->ui_origin());
    }

    /**
     * Origin extraction.
     */
    public function test_origin_of(): void {
        $this->assertSame('https://a.test', config::origin_of('https://a.test/x?y=1'));
        $this->assertSame('http://a.test:81', config::origin_of('HTTP://A.test:81'));
        $this->assertSame('', config::origin_of('a.test'));
        $this->assertSame('', config::origin_of('/path'));
        $this->assertSame('', config::origin_of(''));
    }

    /**
     * Key refusals: empty, block admin key, other role's key; a distinct key is usable.
     */
    public function test_key_usable(): void {
        set_config('apikey', 'adminkey-value', 'block_vektra');
        $config = new config();

        set_config('mintkey', 'adminkey-value', 'local_vektradiscuss');
        $this->assertFalse($config->key_usable('mint'));

        set_config('mintkey', 'mint-value', 'local_vektradiscuss');
        $this->assertTrue($config->key_usable('mint'));
        $this->assertFalse($config->key_usable('teacher'));

        set_config('teacherkey', 'mint-value', 'local_vektradiscuss');
        $this->assertFalse($config->key_usable('mint'));
        $this->assertFalse($config->key_usable('teacher'));

        set_config('teacherkey', 'teacher-value', 'local_vektradiscuss');
        $this->assertTrue($config->key_usable('mint'));
        $this->assertTrue($config->key_usable('teacher'));
    }

    /**
     * An unconfigured block admin key does not make an empty key usable (empty equals empty).
     */
    public function test_empty_key_never_usable_even_if_admin_key_empty(): void {
        $this->assertSame('', (new config())->block_admin_key());
        $this->assertFalse((new config())->key_usable('mint'));
    }

    /**
     * An unknown role is a programming error.
     */
    public function test_unknown_role(): void {
        $this->expectException(\coding_exception::class);
        (new config())->key('privacy_export');
    }

    /**
     * The plugin version is the release of version.php.
     */
    public function test_plugin_version(): void {
        $plugin = new \stdClass();
        require(__DIR__ . '/../../version.php');
        $this->assertSame($plugin->release, (new config())->plugin_version());
    }

    /**
     * The explicit configuration ignores the site settings and honours the same key rules.
     */
    public function test_explicit_config(): void {
        set_config('apiurl', 'https://site.example.test', 'block_vektra');
        set_config('mintkey', 'site-mint', 'local_vektradiscuss');

        $config = new explicit_config('https://cli.example.test/', ['mint' => 'cli-mint', 'teacher' => 'cli-teacher'], 'cli-admin');

        $this->assertSame('https://cli.example.test', $config->api_url());
        $this->assertSame('cli-mint', $config->key('mint'));
        $this->assertTrue($config->key_usable('teacher'));
        $this->assertSame('cli-admin', $config->block_admin_key());

        $same = new explicit_config('https://cli.example.test', ['mint' => 'cli-admin', 'teacher' => 't'], 'cli-admin');
        $this->assertFalse($same->key_usable('mint'));
        $this->assertFalse((new explicit_config('https://cli.example.test', []))->key_usable('mint'));
    }

    /**
     * An unknown role is a programming error, as in the site configuration (not a silent empty key).
     */
    public function test_explicit_config_unknown_role_throws(): void {
        $config = new explicit_config('https://cli.example.test', ['mint' => 'cli-mint', 'admin' => 'x']);

        $this->expectException(\coding_exception::class);
        $config->key('admin');
    }

    /**
     * The UI origin is https only: an http value (saved earlier, or inherited from the block public URL)
     * yields an empty origin, whatever $CFG->debugdeveloper says; only the explicit override allows it.
     */
    public function test_ui_origin_requires_https(): void {
        global $CFG;
        $CFG->debugdeveloper = true;

        set_config('uiurl', 'http://ui.example.test', 'local_vektradiscuss');
        $this->assertSame('', (new config())->ui_origin());

        set_config('uiurl', '', 'local_vektradiscuss');
        set_config('publicurl', 'http://public.example.test:8080/x', 'block_vektra');
        $this->assertSame('', (new config())->ui_origin());

        set_config('uiurl', 'https://ui.example.test', 'local_vektradiscuss');
        $this->assertSame('https://ui.example.test', (new config())->ui_origin());

        $explicit = new explicit_config('http://api.example.test', [], '', true, true);
        $this->assertTrue($explicit->allow_insecure());
        $this->assertFalse((new config())->allow_insecure());
    }

    /**
     * The explicit configuration carries the kill switch.
     */
    public function test_explicit_config_kill_switch(): void {
        $this->assertFalse((new explicit_config('https://a.test', [], '', false))->is_enabled());
        $this->assertTrue((new explicit_config('https://a.test', []))->is_enabled());
    }
}
