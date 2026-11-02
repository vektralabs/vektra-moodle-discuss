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
 * Admin setting for the discussion UI origin.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\admin;

use local_vektradiscuss\local\config;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * An optional origin: https, host and optional port, nothing else (ARCH-076).
 */
class setting_uiurl extends \admin_setting_configtext {
    /**
     * Constructor.
     *
     * @param string $name Unique name (component/setting).
     * @param string $visiblename Display name.
     * @param string $description Description.
     */
    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, '', PARAM_RAW_TRIMMED);
    }

    /**
     * Validate and store the origin (normalised: lower-case scheme and host, no trailing slash).
     *
     * @param mixed $data Submitted value.
     * @return string Empty on success, else the error message.
     */
    public function write_setting($data) {
        global $CFG;

        $value = trim((string) $data);
        if ($value === '') {
            return parent::write_setting('');
        }

        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return get_string('error_uiurl_origin', config::COMPONENT);
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && !empty($CFG->debugdeveloper))) {
            return get_string('error_uiurl_scheme', config::COMPONENT);
        }
        $path = $parts['path'] ?? '';
        if (($path !== '' && $path !== '/') || isset($parts['query']) || isset($parts['fragment'])
                || isset($parts['user']) || isset($parts['pass'])) {
            return get_string('error_uiurl_origin', config::COMPONENT);
        }
        return parent::write_setting(config::origin_of($value));
    }
}
