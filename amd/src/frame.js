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
 * postMessage contract version 1 (Vektra API reference, "Assess UI"): every message is
 * {v: 1, type: "vektra-assess-<name>", ...}. A message is acted on only when it comes from the UI origin,
 * from this iframe's window, with v === 1 and a known type; anything else is ignored silently.
 *
 * The UI page (/api/v1/assess/ui?site=<site id>) announces itself with vektra-assess-ready. Each ready
 * message triggers a fresh server-side mint, and the token is posted to the UI origin only (never "*"),
 * with the mode (start or resume); no session id is sent, the UI reads it from /state. The token is kept
 * in no variable that outlives the call, no URL, no DOM attribute and no storage.
 *
 * After vektra-assess-closed the iframe stays, showing the UI's closing screen, and the listener keeps
 * answering vektra-assess-download-token-request: a single-use download token for the student's own
 * transcript, minted server-side on each request and posted to the UI origin only. On any failure nothing
 * is posted and the UI shows its own generic text after its timeout.
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

/** @var {Number} postMessage contract version. */
const VERSION = 1;

/** @var {RegExp} Transcript id (the session id). */
const TID = /^[A-Za-z0-9-]{1,64}$/;

/** @var {WeakMap} Container => removal of its message listener, for a closed discussion replaced by a new one. */
const listeners = new WeakMap();

/**
 * Open the discussion in a container.
 *
 * @param {HTMLElement} container
 * @param {Object} config {cmid, uiorigin, siteid}
 * @param {Function} onClosed Called when the UI reports the session closed; may return a promise (the panel refresh).
 * @param {String} mode "start" (after the pre-session screen) or "resume" (an open session).
 */
export const open = async(container, config, onClosed, mode) => {
    // One discussion per panel: start and resume stay clickable, so a second click must not replace the live
    // iframe (and leave its listener behind). A closed discussion may be replaced by a new one (a granted reset).
    if (container.dataset.frameState && container.dataset.frameState !== 'closed') {
        return;
    }
    const previous = listeners.get(container);
    if (previous) {
        previous();
        listeners.delete(container);
    }
    container.dataset.frameState = 'opening';
    const origin = String(config.uiorigin);
    const tokenmode = mode === 'resume' ? 'resume' : 'start';
    const src = origin + '/api/v1/assess/ui?site=' + encodeURIComponent(String(config.siteid));
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
        if (!data || typeof data !== 'object' || data.v !== VERSION || typeof data.type !== 'string') {
            return;
        }
        switch (data.type) {
            case 'vektra-assess-ready':
                // Once the session closed, the frame keeps its closing screen: a reload of that page must not
                // mint a new pre-session token.
                if (container.dataset.frameState === 'closing' || container.dataset.frameState === 'closed') {
                    break;
                }
                await handToken(iframe, origin, config, tokenmode);
                break;
            case 'vektra-assess-resize': {
                const height = Number(data.height);
                if (Number.isFinite(height)) {
                    iframe.style.height = Math.min(MAX_HEIGHT, Math.max(MIN_HEIGHT, Math.round(height))) + 'px';
                }
                break;
            }
            case 'vektra-assess-download-token-request':
                if (typeof data.tid === 'string' && TID.test(data.tid)) {
                    await handDownloadToken(iframe, origin, config, data.tid);
                }
                break;
            case 'vektra-assess-closed':
                // The iframe stays with its closing screen; only the status part of the panel is refreshed.
                // The guard stays set while it refreshes, so a click cannot open a second iframe meanwhile.
                if (container.dataset.frameState === 'closing' || container.dataset.frameState === 'closed') {
                    break;
                }
                container.dataset.frameState = 'closing';
                try {
                    await onClosed();
                } catch (e) {
                    // The panel keeps its previous status.
                } finally {
                    container.dataset.frameState = 'closed';
                }
                break;
            default:
                // Anything else is ignored.
        }
    };
    window.addEventListener('message', onMessage);
    listeners.set(container, () => window.removeEventListener('message', onMessage));
    container.dataset.frameState = 'open';
};

/**
 * Mint a pre-session token server-side and post it to the iframe.
 *
 * @param {HTMLIFrameElement} iframe
 * @param {String} origin
 * @param {Object} config
 * @param {String} mode "start" or "resume".
 */
const handToken = async(iframe, origin, config, mode) => {
    let minted;
    try {
        minted = await post('mint', {cmid: config.cmid});
    } catch (e) {
        minted = null;
    }
    const target = iframe.contentWindow;
    if (!target) {
        // The panel refreshed while the mint was pending, so the iframe is no longer in the page.
        return;
    }
    if (!minted || minted.state !== 'ready' || minted.uiorigin !== origin || typeof minted.token !== 'string') {
        target.postMessage({v: VERSION, type: 'vektra-assess-unavailable'}, origin);
        return;
    }
    target.postMessage({v: VERSION, type: 'vektra-assess-token', token: minted.token, mode}, origin);
};

/**
 * Mint a transcript download token server-side and post it to the iframe that asked for it.
 *
 * @param {HTMLIFrameElement} iframe
 * @param {String} origin
 * @param {Object} config
 * @param {String} tid
 */
const handDownloadToken = async(iframe, origin, config, tid) => {
    let minted;
    try {
        minted = await post('download', {cmid: config.cmid, tid});
    } catch (e) {
        minted = null;
    }
    const target = iframe.contentWindow;
    if (!target || !minted || minted.state !== 'ready' || minted.tid !== tid || typeof minted.token !== 'string') {
        // No answer: the UI times out and shows its generic text.
        return;
    }
    target.postMessage({v: VERSION, type: 'vektra-assess-download-token', tid, token: minted.token}, origin);
};
