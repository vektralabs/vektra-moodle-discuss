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
 * POST to the plugin's AJAX endpoint (ajax.php) with the session key.
 *
 * @module     local_vektradiscuss/request
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Config from 'core/config';

/**
 * Call an action. Rejects on a non-2xx status or a Moodle error document.
 *
 * @param {String} action state or mint
 * @param {Object} params cmid, attemptid
 * @returns {Promise<Object>}
 */
export const post = async(action, params) => {
    const body = new URLSearchParams({action, sesskey: Config.sesskey});
    Object.entries(params).forEach(([key, value]) => body.append(key, String(value)));
    const response = await fetch(Config.wwwroot + '/local/vektradiscuss/ajax.php', {
        method: 'POST',
        body,
        credentials: 'same-origin',
        cache: 'no-store',
    });
    if (!response.ok) {
        throw new Error('HTTP ' + response.status);
    }
    const data = await response.json();
    if (!data || typeof data !== 'object' || data.error) {
        throw new Error('refused');
    }
    return data;
};
