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
 * Privacy subsystem implementation for local_vektradiscuss.
 *
 * Moodle stores nothing, but personal data does leave the site: it is sent to
 * the Vektra assessment service, so this is not a null_provider. Export and
 * deletion requests are implemented together with the teacher and privacy
 * features; until then the plugin stays alpha and disabled by default, so no
 * data flows.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy provider: declares the external location receiving personal data.
 */
class provider implements \core_privacy\local\metadata\provider {
    /**
     * Describe the data sent to the Vektra assessment service.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('vektra_assess', [
            'userid' => 'privacy:metadata:vektra_assess:userid',
            'courseid' => 'privacy:metadata:vektra_assess:courseid',
            'cmid' => 'privacy:metadata:vektra_assess:cmid',
            'attempt' => 'privacy:metadata:vektra_assess:attempt',
            'submission' => 'privacy:metadata:vektra_assess:submission',
            'conversation' => 'privacy:metadata:vektra_assess:conversation',
            'transcript' => 'privacy:metadata:vektra_assess:transcript',
        ], 'privacy:metadata:vektra_assess');
        return $collection;
    }
}
