// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Voiceover player (LMS Labs text to speech, Chirp 3 HD voices).
 *
 * Plays signed items ({text, speed, sig}) that the server put in the page. Clip URLs come from the
 * mod_aianatomy_speak web service, which generates missing clips once and then serves stored audio.
 *
 * @module     mod_aianatomy/voice
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

const PREF = 'mod_aianatomy_voice_off';

/**
 * Reads the per-browser "voice off" choice.
 *
 * @returns {boolean}
 */
const storedOff = () => {
    try {
        return window.localStorage.getItem(PREF) === '1';
    } catch (e) {
        return false;
    }
};

export default class Voice {
    /**
     * @param {number} cmid
     * @param {Object} config {enabled, places, auto, phrases}
     * @param {Function} onError called with an error (shown once)
     */
    constructor(cmid, config, onError) {
        this.cmid = cmid;
        this.config = config || {enabled: false, places: [], auto: false, phrases: {}};
        this.onError = onError || (() => null);
        this.off = storedOff();
        this.cache = new Map();
        this.audio = null;
        this.queue = [];
        this.token = 0;
        this.failed = false;
        this.listeners = [];
    }

    /**
     * Voiceover is on for the activity.
     *
     * @returns {boolean}
     */
    get available() {
        return !!(this.config && this.config.enabled);
    }

    /**
     * A place is on (and the student has not turned voice off).
     *
     * @param {string} place cards|prompts|questions|feedback
     * @returns {boolean}
     */
    has(place) {
        return this.available && (this.config.places || []).includes(place);
    }

    /**
     * Automatic reading of prompts and questions.
     *
     * @returns {boolean}
     */
    get auto() {
        return this.available && !this.off && !!this.config.auto;
    }

    /**
     * A fixed phrase item.
     *
     * @param {string} key correct|incorrect|welldone
     * @returns {Object|null}
     */
    phrase(key) {
        return (this.config.phrases || {})[key] || null;
    }

    /**
     * Turns voice on or off for this browser.
     *
     * @param {boolean} off
     */
    setOff(off) {
        this.off = !!off;
        try {
            window.localStorage.setItem(PREF, this.off ? '1' : '0');
        } catch (e) {
            // Private mode: the choice lasts for this page only.
        }
        if (this.off) {
            this.stop();
        }
        this.listeners.forEach((fn) => fn());
    }

    /**
     * Registers a state listener (playing / stopped / on / off).
     *
     * @param {Function} fn
     */
    onChange(fn) {
        this.listeners.push(fn);
    }

    /**
     * Whether audio is playing.
     *
     * @returns {boolean}
     */
    get playing() {
        return !!(this.audio && !this.audio.paused);
    }

    /**
     * Clip URLs for an item.
     *
     * @param {Object} item
     * @returns {Promise<string[]>}
     */
    urls(item) {
        if (!this.cache.has(item.sig)) {
            const p = this.fetchClips(item);
            p.catch(() => this.cache.delete(item.sig));
            this.cache.set(item.sig, p);
        }
        return this.cache.get(item.sig);
    }

    /**
     * Asks the server for the clips of an item. While LMS Labs is still generating (202), the server keeps the
     * request's Idempotency-Key and this waits "retryafter" seconds and asks again (for up to about 3 minutes).
     *
     * @param {Object} item
     * @returns {Promise<string[]>}
     */
    async fetchClips(item) {
        const started = Date.now();
        for (;;) {
            const r = await Ajax.call([{methodname: 'mod_aianatomy_speak', args: {cmid: this.cmid, text: item.text,
                sig: item.sig, speed: item.speed || 'normal'}}])[0];
            if (!r.pending) {
                return r.clips;
            }
            this.preparing = true;
            this.listeners.forEach((fn) => fn());
            if (Date.now() - started > 180000) {
                throw new Error('pending');
            }
            await new Promise((resolve) => window.setTimeout(resolve, Math.max(1, r.retryafter || 5) * 1000));
        }
    }

    /**
     * Plays items in order, replacing anything playing.
     *
     * @param {Array|Object} items
     * @param {boolean} force play even when the student turned voice off (explicit Listen buttons)
     * @returns {Promise<void>}
     */
    async play(items, force = false) {
        const list = [].concat(items).filter((i) => i && i.sig);
        if (!this.available || !list.length || (this.off && !force)) {
            return;
        }
        this.stop();
        const token = ++this.token;
        this.listeners.forEach((fn) => fn());
        try {
            // Fetch all clip URLs first (in parallel), then play them back to back.
            const all = await Promise.all(list.map((i) => this.urls(i)));
            this.preparing = false;
            for (const clips of all) {
                for (const url of clips) {
                    if (token !== this.token) {
                        return;
                    }
                    await this.playUrl(url, token);
                }
            }
        } catch (err) {
            if (!this.failed) {
                this.failed = true;
                this.onError(err);
            }
        } finally {
            this.preparing = false;
            if (token === this.token) {
                this.audio = null;
                this.listeners.forEach((fn) => fn());
            }
        }
    }

    /**
     * Plays one clip.
     *
     * @param {string} url
     * @param {number} token
     * @returns {Promise<void>}
     */
    playUrl(url, token) {
        return new Promise((resolve) => {
            if (token !== this.token) {
                resolve();
                return;
            }
            const a = new Audio(url);
            this.audio = a;
            const done = () => {
                a.onended = a.onerror = a.onpause = null;
                resolve();
            };
            a.onended = done;
            a.onerror = done;
            a.onpause = done;
            a.onplaying = () => this.listeners.forEach((fn) => fn());
            a.play().catch(done);
        });
    }

    /**
     * Stops playback.
     */
    stop() {
        this.token++;
        if (this.audio) {
            const a = this.audio;
            this.audio = null;
            a.pause();
        }
        this.listeners.forEach((fn) => fn());
    }
}
