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
 * Tests for the capability definitions.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss;

/**
 * Tests for db/access.php.
 *
 * @coversNothing
 */
final class capabilities_test extends \advanced_testcase {
    /**
     * The capabilities as installed in the database.
     *
     * @return array name => record
     */
    private function installed(): array {
        global $DB;
        $fields = 'name, captype, contextlevel, riskbitmask';
        return $DB->get_records('capabilities', ['component' => 'local_vektradiscuss'], '', $fields);
    }

    /**
     * The six capabilities exist with the expected types and context levels.
     */
    public function test_capabilities_installed(): void {
        $this->resetAfterTest();
        $caps = $this->installed();

        $this->assertEqualsCanonicalizing([
            'local/vektradiscuss:start', 'local/vektradiscuss:viewowntranscript', 'local/vektradiscuss:viewtranscripts',
            'local/vektradiscuss:manage', 'local/vektradiscuss:configure', 'local/vektradiscuss:setupcheck',
        ], array_keys($caps));
        $this->assertEquals(CONTEXT_SYSTEM, $caps['local/vektradiscuss:setupcheck']->contextlevel);
        $this->assertEquals(CONTEXT_MODULE, $caps['local/vektradiscuss:viewtranscripts']->contextlevel);
    }

    /**
     * Capabilities that expose personal data or site configuration carry a risk bitmask.
     */
    public function test_risk_bitmasks(): void {
        $this->resetAfterTest();
        $caps = $this->installed();

        $this->assertEquals(RISK_PERSONAL, $caps['local/vektradiscuss:viewtranscripts']->riskbitmask);
        $this->assertEquals(RISK_PERSONAL, $caps['local/vektradiscuss:manage']->riskbitmask);
        $this->assertEquals(RISK_CONFIG, $caps['local/vektradiscuss:setupcheck']->riskbitmask);
        $this->assertEquals(0, $caps['local/vektradiscuss:start']->riskbitmask);
    }
}
