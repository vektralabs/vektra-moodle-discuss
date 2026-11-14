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
 * "Checking…" under a teacher form's button while the server waits for Vektra.
 *
 * When Vektra refuses on LMS facts it is still refreshing, the server waits a few seconds and sends the same
 * request once more. A normal answer comes before the notice shows; a retry shows it until the page changes.
 *
 * @module     local_vektradiscuss/pending
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {Number} Wait before the notice, in milliseconds. */
const DELAY = 1500;

/** @type {String} Class of the notice. */
const NOTICE = 'local-vektradiscuss-pending';

/**
 * Show the notice after the button that sent the form.
 *
 * @param {HTMLFormElement} form
 * @param {HTMLElement|null} submitter
 * @param {String} text
 */
const show = (form, submitter, text) => {
    if (form.querySelector('.' + NOTICE)) {
        return;
    }
    const notice = document.createElement('div');
    notice.className = NOTICE + ' alert alert-info mt-2 mb-0 py-1 px-2 small';
    notice.setAttribute('role', 'status');
    notice.textContent = text;
    if (submitter && form.contains(submitter)) {
        submitter.insertAdjacentElement('afterend', notice);
    } else {
        form.appendChild(notice);
    }
};

/**
 * Watch the forms holding the matched elements.
 *
 * @param {String} selector A form, or an element inside one.
 * @param {String} text The notice, already translated.
 */
export const init = (selector, text) => {
    document.querySelectorAll(selector).forEach((element) => {
        const form = element.closest('form');
        if (!form || form.dataset.vektraPending) {
            return;
        }
        form.dataset.vektraPending = '1';
        form.addEventListener('submit', (event) => {
            window.setTimeout(() => {
                // Checked late: a listener added after this one (form validation) may have stopped the submission.
                if (!event.defaultPrevented) {
                    show(form, event.submitter || null, text);
                }
            }, DELAY);
        });
    });
    // Coming back to the page from the history must not keep an old notice.
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('.' + NOTICE).forEach((notice) => notice.remove());
    });
};
