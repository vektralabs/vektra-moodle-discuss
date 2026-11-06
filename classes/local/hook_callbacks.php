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
 * Hook callbacks (Moodle 4.4+).
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

/**
 * Hook callbacks registered in db/hooks.php.
 */
final class hook_callbacks {
    /**
     * Add the student container before the footer.
     *
     * The parameter is untyped on purpose: the hook class does not exist on Moodle 4.3, where the
     * legacy callback in lib.php is used instead.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer($hook): void {
        $html = injector::inject();
        if ($html !== '') {
            $hook->add_html($html);
        }
    }
}
