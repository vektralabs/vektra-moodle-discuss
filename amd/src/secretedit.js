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
 * Edit a saved solution or grading criteria in place: ticking "Edit" fills the field with the text read from Vektra.
 * Only an explicit tick reads it: a page redisplayed with "Edit" already ticked keeps the field empty.
 *
 * The text is read on demand (the same request as "Show") and lives only in the field of this page: Moodle never
 * stores it, and the save sends the field to Vektra as the new text. Unticking "Edit", or leaving the page, empties
 * the field. When the read fails the field stays empty and editable, with a message: the teacher can retry or write
 * the full replacement.
 *
 * @module     local_vektradiscuss/secretedit
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';
import {getStrings} from 'core/str';
import {read} from 'local_vektradiscuss/reveal';

const PREFIX = 'id_vektradiscuss_';

/**
 * Empty a field and its message.
 *
 * @param {HTMLTextAreaElement} field
 * @param {HTMLElement} message
 */
const empty = (field, message) => {
    field.value = '';
    field.readOnly = false;
    field.removeAttribute('aria-busy');
    field.placeholder = '';
    message.replaceChildren();
    message.hidden = true;
    field.dispatchEvent(new Event('input', {bubbles: true}));
};

/**
 * Wire the fields that can be filled from Vektra.
 *
 * @param {Number} cmid
 */
export const init = async(cmid) => {
    const fields = document.querySelectorAll('textarea[data-vektradiscuss-prefill]');
    if (!fields.length) {
        return;
    }
    const [loading, unavailable] = await getStrings([
        {key: 'form_reveal_loading', component: 'local_vektradiscuss'},
        {key: 'form_edit_unavailable', component: 'local_vektradiscuss'},
    ]);
    const wired = [];
    fields.forEach((field) => {
        const name = field.dataset.vektradiscussPrefill;
        const toggle = document.getElementById(PREFIX + name + '_replace');
        if (!toggle) {
            return;
        }
        const message = document.createElement('div');
        message.className = 'alert alert-warning mt-2 mb-0';
        message.setAttribute('role', 'alert');
        message.hidden = true;
        field.insertAdjacentElement('afterend', message);
        // Each read gets a number: an answer that arrives after "Edit" was unticked, or ticked again, or after the
        // page was hidden, is dropped.
        let request = 0;
        wired.push([field, message, () => request++]);

        const fill = async() => {
            if (field.value !== '') {
                // The teacher's own text (or one already read) is never overwritten.
                return;
            }
            const mine = ++request;
            const pending = new Pending('local_vektradiscuss/secretedit:' + name);
            field.readOnly = true;
            field.setAttribute('aria-busy', 'true');
            field.placeholder = loading;
            message.hidden = true;
            let data;
            try {
                data = await read(cmid, name);
            } catch (err) {
                data = {error: unavailable};
            }
            pending.resolve();
            if (mine !== request || !toggle.checked) {
                return;
            }
            field.readOnly = false;
            field.removeAttribute('aria-busy');
            field.placeholder = '';
            if (typeof data.value === 'string') {
                field.value = data.value;
                field.dispatchEvent(new Event('input', {bubbles: true}));
                return;
            }
            message.textContent = data.error === undefined ? unavailable : data.error;
            message.hidden = false;
        };

        toggle.addEventListener('change', () => {
            request++;
            if (toggle.checked) {
                fill();
            } else {
                empty(field, message);
            }
        });
        // Redisplayed after a validation error with "Edit" still ticked, the field was emptied by the server and stays
        // empty: reloading the saved text would silently replace the teacher's lost edit. The empty-text check then
        // asks for the text again; unticking and ticking "Edit" reads it again.
    });
    // A text read for editing must not linger when the browser restores the page from its cache.
    window.addEventListener('pagehide', () => {
        wired.forEach(([field, message, forget]) => {
            forget();
            empty(field, message);
        });
    });
};
