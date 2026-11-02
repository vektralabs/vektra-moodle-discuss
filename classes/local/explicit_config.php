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
 * Configuration with explicit values instead of the site settings (CLI smoke).
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

/**
 * A {@see config} whose API URL, keys and block admin key come from the constructor.
 */
class explicit_config extends config {
    /** @var string API URL. */
    private string $apiurl;

    /** @var string[] Key role => secret. */
    private array $keys;

    /** @var string Block admin key, for refusal only. */
    private string $adminkey;

    /**
     * Constructor.
     *
     * @param string $apiurl API base URL.
     * @param string[] $keys Key role => secret.
     * @param string $adminkey The block admin key (never sent by the client).
     */
    public function __construct(string $apiurl, array $keys, string $adminkey = '') {
        $this->apiurl = rtrim(trim($apiurl), '/');
        $this->keys = $keys;
        $this->adminkey = trim($adminkey);
    }

    /**
     * API URL.
     *
     * @return string
     */
    public function api_url(): string {
        return $this->apiurl;
    }

    /**
     * Key of a role.
     *
     * @param string $role
     * @return string
     */
    public function key(string $role): string {
        return trim($this->keys[$role] ?? '');
    }

    /**
     * Block admin key.
     *
     * @return string
     */
    public function block_admin_key(): string {
        return $this->adminkey;
    }
}
