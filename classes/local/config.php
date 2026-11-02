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
 * Typed access to the plugin and block_vektra configuration.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

/**
 * The single place that reads plugin settings and the block_vektra connection settings.
 */
class config {
    /** @var string Moodle component of this plugin. */
    public const COMPONENT = 'local_vektradiscuss';

    /** @var string Moodle component of the plugin whose connection settings are reused. */
    public const BLOCK = 'block_vektra';

    /** @var string[] Key role => setting name. Later plans add the privacy roles. */
    public const KEY_ROLES = [
        'mint' => 'mintkey',
        'teacher' => 'teacherkey',
    ];

    /**
     * Whether the site kill switch is on.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return !empty(get_config(self::COMPONENT, 'enabled'));
    }

    /**
     * The Vektra API base URL (shared with the block), without trailing slash.
     *
     * @return string Empty when the block is not configured.
     */
    public function api_url(): string {
        return rtrim(trim((string) get_config(self::BLOCK, 'apiurl')), '/');
    }

    /**
     * The Vektra public URL (shared with the block), falling back to the API URL like the block does.
     *
     * @return string
     */
    public function public_url(): string {
        $public = rtrim(trim((string) get_config(self::BLOCK, 'publicurl')), '/');
        return $public !== '' ? $public : $this->api_url();
    }

    /**
     * Origin (scheme://host[:port]) that serves the discussion UI.
     *
     * The plugin setting wins; when empty the origin of the block's public URL is used.
     *
     * @return string Empty when nothing is configured.
     */
    public function ui_origin(): string {
        $own = trim((string) get_config(self::COMPONENT, 'uiurl'));
        $source = $own !== '' ? $own : $this->public_url();
        return self::origin_of($source);
    }

    /**
     * The site identifier issued by the Vektra operator.
     *
     * @return string
     */
    public function site_id(): string {
        return trim((string) get_config(self::COMPONENT, 'siteid'));
    }

    /**
     * The secret of a key role.
     *
     * @param string $role A key of {@see self::KEY_ROLES}.
     * @return string Empty when not set.
     * @throws \coding_exception For an unknown role.
     */
    public function key(string $role): string {
        if (!isset(self::KEY_ROLES[$role])) {
            throw new \coding_exception('Unknown assess key role: ' . $role);
        }
        return trim((string) get_config(self::COMPONENT, self::KEY_ROLES[$role]));
    }

    /**
     * The block_vektra admin key. Read only to refuse it, never to send it.
     *
     * @return string
     */
    public function block_admin_key(): string {
        return trim((string) get_config(self::BLOCK, 'apikey'));
    }

    /**
     * Whether a key of a role may be used: set, not the block admin key, not another role's key.
     *
     * @param string $role A key of {@see self::KEY_ROLES}.
     * @return bool
     */
    public function key_usable(string $role): bool {
        $key = $this->key($role);
        if ($key === '' || $key === $this->block_admin_key()) {
            return false;
        }
        foreach (array_keys(self::KEY_ROLES) as $other) {
            if ($other !== $role && $this->key($other) === $key) {
                return false;
            }
        }
        return true;
    }

    /**
     * The plugin release string, sent as X-Vektra-Plugin-Version.
     *
     * @return string
     */
    public function plugin_version(): string {
        static $release = null;
        if ($release === null) {
            $plugin = new \stdClass();
            require(__DIR__ . '/../../version.php');
            $release = (string) $plugin->release;
        }
        return $release;
    }

    /**
     * The origin of a URL.
     *
     * @param string $url
     * @return string scheme://host[:port], or empty when the URL has no scheme and host.
     */
    public static function origin_of(string $url): string {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }
}
