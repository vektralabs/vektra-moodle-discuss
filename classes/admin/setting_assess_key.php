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
 * Admin setting for a service key: refuses the block admin key and a key shared by two roles.
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
 * A password-unmask setting that validates the key on save.
 *
 * Vektra enforces the same rules (an admin key does not satisfy the non-inheriting assess:*
 * scopes), so this is the first, friendlier line of defence (ARCH-067, NFR-014).
 */
class setting_assess_key extends \admin_setting_configpasswordunmask {
    /** @var string The key role of the other key setting. */
    private string $otherrole;

    /**
     * Constructor.
     *
     * @param string $name Unique name (component/setting).
     * @param string $visiblename Display name.
     * @param string $description Description.
     * @param string $otherrole Key role of the other key, to refuse a duplicate.
     */
    public function __construct(string $name, string $visiblename, string $description, string $otherrole) {
        parent::__construct($name, $visiblename, $description, '');
        $this->otherrole = $otherrole;
    }

    /**
     * Validate and store the key.
     *
     * @param mixed $data Submitted value.
     * @return string Empty on success, else the error message.
     */
    public function write_setting($data) {
        $value = trim((string) $data);
        if ($value !== '') {
            $config = new config();
            if ($value === $config->block_admin_key()) {
                return get_string('error_key_is_adminkey', config::COMPONENT);
            }
            if ($value === $this->other_key($config)) {
                return get_string('error_key_duplicate', config::COMPONENT);
            }
        }
        return parent::write_setting($value);
    }

    /**
     * The value the other key setting holds: what is being saved in the same form, else the stored one.
     *
     * @param config $config
     * @return string
     */
    private function other_key(config $config): string {
        $field = 's_' . config::COMPONENT . '_' . config::KEY_ROLES[$this->otherrole];
        $submitted = optional_param($field, null, PARAM_RAW);
        if ($submitted !== null) {
            return trim($submitted);
        }
        return $config->key($this->otherrole);
    }
}
