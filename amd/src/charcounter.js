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
 * Live character counter for the write-only texts of the discussion settings (BR-013).
 *
 * Counts as Vektra does: code points after NFC, without control characters other than newline and tab.
 * The count is shown under the field; a polite live region announces it only when it crosses 90% of the
 * limit and when it goes over the limit, not on every keystroke.
 *
 * @module     local_vektradiscuss/charcounter
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';

/** @type {RegExp} Control characters other than newline and tab. */
const CONTROL = /(?![\n\t])\p{Cc}/gu;

/**
 * Length of a text as Vektra counts it.
 *
 * @param {String} text
 * @returns {Number}
 */
export const length = (text) => [...text.normalize('NFC').replace(CONTROL, '')].length;

/**
 * Attach a counter to each textarea, by id.
 *
 * @param {String[]} names Element names; Moodle's ids are "id_" + name.
 * @param {Number} max
 */
export const init = (names, max) => {
    names.forEach((name) => {
        const field = document.getElementById('id_' + name);
        if (!field || field.dataset.vektraCounter) {
            return;
        }
        field.dataset.vektraCounter = '1';
        const counter = document.createElement('div');
        counter.id = field.id + '_counter';
        counter.className = 'form-text text-muted local-vektradiscuss-counter';
        const announcer = document.createElement('div');
        announcer.className = 'visually-hidden sr-only';
        announcer.setAttribute('aria-live', 'polite');
        field.insertAdjacentElement('afterend', counter);
        counter.insertAdjacentElement('afterend', announcer);
        const described = (field.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
        field.setAttribute('aria-describedby', described.concat(counter.id).join(' '));

        let level = 0;
        const update = async() => {
            const count = length(field.value);
            const over = count > max;
            const text = await getString(over ? 'charcounter_over' : 'charcounter_count', 'local_vektradiscuss', {count, max});
            counter.textContent = text;
            counter.classList.toggle('text-danger', over);
            counter.classList.toggle('text-muted', !over);
            const next = over ? 2 : (count >= max * 0.9 ? 1 : 0);
            if (next > level) {
                announcer.textContent = text;
            }
            level = next;
        };
        field.addEventListener('input', update);
        update();
    });
};
