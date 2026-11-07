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
 * The Vektra-origin discussion iframe and its postMessage handshake (ARCH-076).
 *
 * The iframe announces itself with {type: "vektra-assess-ready"}; only a message from the UI origin
 * AND from this iframe's window is accepted. Each ready message triggers a fresh server-side mint,
 * and the token is posted to the UI origin only (never "*"). The token is kept in no variable that
 * outlives the call, no URL, no DOM attribute and no storage.
 *
 * Message names follow the discuss-student plan; the final contract comes with the vektra-stack
 * discussion-ui plan.
 *
 * @module     local_vektradiscuss/frame
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import {getString} from 'core/str';
import {post} from 'local_vektradiscuss/request';

/** @var {Number} Minimum and maximum iframe height accepted from a resize message, in pixels. */
const MIN_HEIGHT = 240;
const MAX_HEIGHT = 4000;

/**
 * Open the discussion in a container.
 *
 * @param {HTMLElement} container
 * @param {Object} config {cmid, uiorigin}
 * @param {Function} onClosed Called when the UI reports the session closed; may return a promise (the panel refresh).
 */
export const open = async(container, config, onClosed) => {
    // One discussion per panel: start and resume stay clickable, so a second click must not replace the live
    // iframe (and leave its listener behind).
    if (container.dataset.frameState) {
        return;
    }
    container.dataset.frameState = 'opening';
    const origin = String(config.uiorigin);
    const src = origin + '/api/v1/assess/ui?cmid=' + encodeURIComponent(Number(config.cmid));
    let iframe;
    try {
        const {html} = await Templates.renderForPromise('local_vektradiscuss/iframe', {
            src,
            title: await getString('iframe_title', 'local_vektradiscuss'),
        });
        Templates.replaceNodeContents(container, html, '');
        iframe = container.querySelector('iframe');
    } catch (e) {
        iframe = null;
    }
    if (!iframe) {
        // Leave the panel usable: a later click may try again.
        delete container.dataset.frameState;
        return;
    }

    const onMessage = async(event) => {
        if (event.origin !== origin || event.source !== iframe.contentWindow) {
            return;
        }
        const data = event.data;
        if (!data || typeof data !== 'object' || typeof data.type !== 'string') {
            return;
        }
        switch (data.type) {
            case 'vektra-assess-ready':
                await handToken(iframe, origin, config);
                break;
            case 'vektra-assess-resize': {
                const height = Number(data.height);
                if (Number.isFinite(height)) {
                    iframe.style.height = Math.min(MAX_HEIGHT, Math.max(MIN_HEIGHT, Math.round(height))) + 'px';
                }
                break;
            }
            case 'vektra-assess-closed':
                // The guard stays set while the panel refreshes, so a click on the old panel cannot open
                // a second iframe that the refresh would then remove; it is released whatever the outcome.
                window.removeEventListener('message', onMessage);
                container.dataset.frameState = 'closing';
                try {
                    await onClosed();
                } catch (e) {
                    // The panel keeps its previous content; the guard is released below.
                } finally {
                    delete container.dataset.frameState;
                }
                break;
            default:
                // Anything else is ignored.
        }
    };
    window.addEventListener('message', onMessage);
    container.dataset.frameState = 'open';
};

/**
 * Mint a pre-session token server-side and post it to the iframe.
 *
 * @param {HTMLIFrameElement} iframe
 * @param {String} origin
 * @param {Object} config
 */
const handToken = async(iframe, origin, config) => {
    let minted;
    try {
        minted = await post('mint', {cmid: config.cmid});
    } catch (e) {
        minted = null;
    }
    if (!minted || minted.state !== 'ready' || minted.uiorigin !== origin || typeof minted.token !== 'string') {
        iframe.contentWindow.postMessage({type: 'vektra-assess-unavailable'}, origin);
        return;
    }
    iframe.contentWindow.postMessage({type: 'vektra-assess-token', token: minted.token}, origin);
};
