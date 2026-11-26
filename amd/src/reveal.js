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
 * The "Show" buttons of the discussion settings (OQ-034): read a saved text from Vektra on demand.
 *
 * The text is put in a read-only area next to the button as plain text (textContent), never in a form field:
 * a save never sends it, and "Hide" removes it from the page. Nothing is kept in the browser.
 *
 * @module     local_vektradiscuss/reveal
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Config from 'core/config';
import {getStrings} from 'core/str';

/**
 * Ask the plugin for one saved text.
 *
 * @param {Number} cmid
 * @param {String} field solution, rubric or recipients
 * @returns {Promise<Object>} {value} or {error}
 */
export const read = async(cmid, field) => {
    const body = new URLSearchParams({cmid: String(cmid), field, sesskey: Config.sesskey});
    const response = await fetch(Config.wwwroot + '/local/vektradiscuss/reveal.php', {
        method: 'POST',
        body,
        credentials: 'same-origin',
        cache: 'no-store',
    });
    if (!response.ok) {
        throw new Error('HTTP ' + response.status);
    }
    const data = await response.json();
    if (!data || typeof data !== 'object' || (typeof data.value !== 'string' && typeof data.error !== 'string')) {
        throw new Error('refused');
    }
    return data;
};

/**
 * Empty and hide the area of a button.
 *
 * @param {HTMLButtonElement} button
 * @param {HTMLElement} area
 * @param {String} showlabel
 */
const conceal = (button, area, showlabel) => {
    area.replaceChildren();
    area.hidden = true;
    button.textContent = showlabel;
    button.setAttribute('aria-expanded', 'false');
};

/**
 * Wire the buttons of the form.
 *
 * @param {Number} cmid
 */
export const init = async(cmid) => {
    const buttons = document.querySelectorAll('button[data-vektradiscuss-reveal]');
    if (!buttons.length) {
        return;
    }
    const [show, hide, loading, unavailable] = await getStrings([
        {key: 'form_reveal_show', component: 'local_vektradiscuss'},
        {key: 'form_reveal_hide', component: 'local_vektradiscuss'},
        {key: 'form_reveal_loading', component: 'local_vektradiscuss'},
        {key: 'form_reveal_unavailable', component: 'local_vektradiscuss'},
    ]);
    buttons.forEach((button) => {
        const area = document.getElementById(button.getAttribute('aria-controls'));
        if (!area) {
            return;
        }
        button.addEventListener('click', async(e) => {
            e.preventDefault();
            if (button.getAttribute('aria-expanded') === 'true') {
                conceal(button, area, show);
                return;
            }
            button.disabled = true;
            area.hidden = false;
            area.replaceChildren(document.createTextNode(loading));
            let data;
            try {
                data = await read(cmid, button.dataset.vektradiscussReveal);
            } catch (err) {
                data = {error: unavailable};
            }
            button.disabled = false;
            if (area.hidden) {
                // Concealed while the read was pending (pagehide): the text must not land on the page.
                return;
            }
            if (typeof data.value === 'string') {
                const text = document.createElement('pre');
                text.className = 'border rounded p-2 mb-0 bg-light';
                text.style.whiteSpace = 'pre-wrap';
                text.style.maxHeight = '20em';
                text.style.overflow = 'auto';
                text.tabIndex = 0;
                text.textContent = data.value;
                area.replaceChildren(text);
                button.textContent = hide;
                button.setAttribute('aria-expanded', 'true');
                return;
            }
            const message = document.createElement('div');
            message.className = 'alert alert-warning mb-0';
            message.setAttribute('role', 'alert');
            message.textContent = data.error;
            area.replaceChildren(message);
        });
    });
    // A text read before a save must not linger when the browser restores the page from its cache.
    window.addEventListener('pagehide', () => {
        buttons.forEach((button) => {
            const area = document.getElementById(button.getAttribute('aria-controls'));
            if (area) {
                conceal(button, area, show);
            }
        });
    });
};
