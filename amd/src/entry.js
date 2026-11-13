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
 * Student entry point on the results page: fetch the state, render the button, pre-session modal.
 *
 * Nothing is cached or stored: the state is fetched on every page view (ARCH-065).
 *
 * @module     local_vektradiscuss/entry
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import Modal from 'core/modal';
import {getString} from 'core/str';
import {post} from 'local_vektradiscuss/request';
import {open as openFrame} from 'local_vektradiscuss/frame';

/**
 * The generic unavailable context (state 7), used when the endpoint itself fails.
 *
 * @param {Number} cmid
 * @returns {Promise<Object>}
 */
const unavailableContext = async(cmid) => {
    const [heading, message, label] = await Promise.all([
        getString('student_heading', 'local_vektradiscuss'),
        getString('state_unavailable', 'local_vektradiscuss'),
        getString('button_start', 'local_vektradiscuss'),
    ]);
    return {
        visible: true, uniqid: 'local-vektradiscuss-' + cmid, state: 'unavailable', statenumber: 7,
        heading, message, enabled: false, canstart: false, canresume: false, buttonlabel: label,
        suggestcopy: false, hastranscripts: false, transcripts: [],
    };
};

/**
 * Announce a sentence once in the page's polite live region.
 *
 * @param {HTMLElement} region
 * @param {String} text
 */
const announce = (region, text) => {
    let live = region.querySelector('[data-region="local_vektradiscuss-live"]');
    if (!live) {
        live = document.createElement('div');
        live.className = 'sr-only visually-hidden';
        live.setAttribute('aria-live', 'polite');
        live.setAttribute('data-region', 'local_vektradiscuss-live');
        region.append(live);
    }
    live.textContent = text;
};

/**
 * Fetch the state and render the panel.
 *
 * After a discussion closed, only the status part is replaced: the iframe stays, with the UI's closing
 * screen and its transcript download (REQ-072). Moving or re-creating the iframe would reload it.
 *
 * @param {HTMLElement} region
 * @param {Object} config {cmid, attemptid, uiorigin, siteid}
 * @param {Boolean} keepFrame Keep the open iframe and replace only the status part.
 */
const refresh = async(region, config, keepFrame = false) => {
    let context;
    try {
        context = await post('state', {cmid: config.cmid, attemptid: config.attemptid});
        if (!context || typeof context.state !== 'string') {
            throw new Error('malformed');
        }
    } catch (e) {
        context = await unavailableContext(config.cmid);
    }
    const {html, js} = await Templates.renderForPromise('local_vektradiscuss/button', context);
    const status = region.querySelector('[data-region="local_vektradiscuss-status"]');
    const fresh = document.createElement('div');
    fresh.innerHTML = html;
    const freshStatus = fresh.querySelector('[data-region="local_vektradiscuss-status"]');
    const panel = region.querySelector('.local-vektradiscuss-panel');
    if (keepFrame && status && freshStatus && panel) {
        await Templates.replaceNodeContents(status, freshStatus.innerHTML, js);
        panel.dataset.state = context.state;
        panel.dataset.statenumber = String(context.statenumber);
    } else {
        await Templates.replaceNodeContents(region, html, js);
    }
    region.dataset.state = context.state;

    const frameRegion = () => region.querySelector('[data-region="local_vektradiscuss-frame"]');
    const open = async(mode, noticeversion = null) => {
        announce(region, await getString('preparing', 'local_vektradiscuss'));
        await openFrame(frameRegion(), {...config, noticeversion}, () => refresh(region, config, true), mode);
    };

    const resume = region.querySelector('[data-action="local_vektradiscuss-resume"]');
    if (resume) {
        resume.addEventListener('click', () => open('resume'));
    }
    const startButton = region.querySelector('[data-action="local_vektradiscuss-start"]');
    if (startButton && context.presession) {
        // The privacy notice version the screen shows travels with the Start mint: Vektra refuses the Start if
        // the notice changed since.
        const shown = context.presession.privacyversion;
        startButton.addEventListener('click', () => showPresession(context, () => open('start', shown || null)));
    }
};

/**
 * The pre-session screen. Cancel and Escape close it without any request.
 *
 * @param {Object} context The state context.
 * @param {Function} start Called on confirm.
 */
const showPresession = async(context, start) => {
    const modal = await Modal.create({
        title: context.presession.title,
        body: Templates.render('local_vektradiscuss/presession', context.presession),
        large: true,
        removeOnClose: true,
    });
    modal.show();
    const root = modal.getRoot()[0];
    root.addEventListener('click', (e) => {
        const action = e.target.closest('[data-action]');
        if (!action) {
            return;
        }
        if (action.dataset.action === 'local_vektradiscuss-cancel') {
            modal.hide();
        } else if (action.dataset.action === 'local_vektradiscuss-confirm') {
            modal.hide();
            start();
        }
    });
};

/**
 * Initialise the panel of one activity.
 *
 * @param {Object} config {cmid, attemptid, uiorigin, siteid}
 */
export const init = (config) => {
    const region = document.querySelector('[data-region="local_vektradiscuss"][data-cmid="' + Number(config.cmid) + '"]');
    if (!region) {
        return;
    }
    refresh(region, config);
};
