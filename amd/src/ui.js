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
 * Small UI helpers shared by the player and the editor.
 *
 * @module     mod_aianatomy/ui
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';

export const REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
export const COARSE = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

/**
 * Loads language strings.
 *
 * @param {string[]} keys
 * @returns {Promise<object>} key => string
 */
export const loadStrings = async(keys) => {
    const values = await getStrings(keys.map((key) => ({key, component: 'mod_aianatomy'})));
    const S = {};
    keys.forEach((key, i) => {
        S[key] = values[i];
    });
    return S;
};

/**
 * {$a} / {$a->x} replacement.
 *
 * @param {string} str
 * @param {object|string|number} a
 * @returns {string}
 */
export const fmt = (str, a) => {
    if (typeof a === 'object' && a !== null) {
        return String(str).replace(/\{\$a->(\w+)\}/g, (m, k) => (a[k] ?? ''));
    }
    return String(str).replace(/\{\$a\}/g, a);
};

/**
 * Creates an element. Text is always set with textContent; 'formatted' is only used for strings
 * already formatted and escaped on the server.
 *
 * @param {string} tag
 * @param {string} cls
 * @param {object} attrs
 * @returns {HTMLElement}
 */
export const el = (tag, cls = '', attrs = {}) => {
    const node = document.createElement(tag);
    if (cls) {
        node.className = cls;
    }
    Object.entries(attrs).forEach(([k, v]) => {
        if (k === 'children') {
            node.append(...v.filter((c) => c !== null && c !== undefined && c !== ''));
        } else if (k === 'formatted') {
            node.innerHTML = v;
        } else if (k === 'text') {
            node.textContent = v;
        } else if (k === 'html') {
            // Static markup from this plugin only (icons).
            node.innerHTML = v;
        } else if (v !== null && v !== undefined && v !== false) {
            node.setAttribute(k, v === true ? '' : v);
        }
    });
    return node;
};

export const ICONS = {
    exit: '<path d="M15 18l-6-6 6-6"/>',
    sound: '<path d="M4 10v4h4l5 4V6L8 10z"/><path d="M16 9a4 4 0 010 6M18.5 6.5a8 8 0 010 11"/>',
    muted: '<path d="M4 10v4h4l5 4V6L8 10z"/><path d="M17 9l5 6M22 9l-5 6"/>',
    full: '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
    unfull: '<path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/>',
    hint: '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 00-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0012 3z"/>',
    eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    eyeoff: '<path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0112 5c6.5 0 10 7 10 7a17 17 0 01-3.2 4.1M6.6 6.6A17 17 0 002 12s3.5 7 10 7' +
        'a9.7 9.7 0 005.4-1.6"/><path d="M9.9 9.9a3 3 0 004.2 4.2"/>',
    reset: '<path d="M4 4v6h6"/><path d="M4.5 15a8 8 0 102-8.5L4 10"/>',
    next: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    prev: '<path d="M19 12H5M11 6l-6 6 6 6"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    cross: '<path d="M6 6l12 12M18 6L6 18"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    star: '<path d="M12 3l2.6 5.6 6.1.7-4.5 4.2 1.2 6L12 16.6 6.6 19.5l1.2-6L3.3 9.3l6.1-.7z"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    target: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
    repeat: '<path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 013-3h15M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 01-3 3H3"/>',
    hand: '<path d="M8 13V5.5a1.5 1.5 0 013 0V12M11 11.5v-2a1.5 1.5 0 013 0V12M14 10.5a1.5 1.5 0 013 0V15' +
        'a6 6 0 01-6 6h-.5A5.5 5.5 0 015 15.5L3.8 12.6a1.5 1.5 0 012.6-1.5L8 13"/>',
    flag: '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
    trophy: '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 01-10 0z"/><path d="M17 5h3v2a3 3 0 01-3 3M7 5H4v2a3 3 0 003 3"/>',
    bone: '<path d="M7.5 3.5a2.5 2.5 0 00-2.4 3.2 2.5 2.5 0 101.9 4.1l6.2 6.2a2.5 2.5 0 104.1 1.9 2.5 2.5 0 10' +
        '-.8-4.9L10.3 7.8A2.5 2.5 0 007.5 3.5z"/>',
    cube: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M12 12l8-4.5M12 12v9M12 12L4 7.5"/>',
    explode: '<path d="M12 3v5M12 16v5M3 12h5M16 12h5"/><path d="M9 6l3-3 3 3M9 18l3 3 3-3M6 9l-3 3 3 3M18 9l3 3-3 3"/>',
    labels: '<path d="M4 7h9l4 5-4 5H4z"/><circle cx="8" cy="12" r="1.2"/>',
    isolate: '<circle cx="12" cy="12" r="4"/><path d="M3 3l3 3M21 3l-3 3M3 21l3-3M21 21l-3-3"/>',
    layers: '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
    speak: '<path d="M4 10v4h4l5 4V6L8 10z"/><path d="M16 9a4 4 0 010 6"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18"/>',
    voice: '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0114 0"/><path d="M16.5 5.5a4 4 0 010 5M19.5 3a8 8 0 010 10"/>',
    voiceoff: '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0114 0"/><path d="M17 5l5 5M22 5l-5 5"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
    book: '<path d="M3 6.5C5.5 5 8.5 5 12 7c3.5-2 6.5-2 9-.5V19c-2.5-1.5-5.5-1.5-9 .5-3.5-2-6.5-2-9-.5z"/><path d="M12 7v12.5"/>',
    pin: '<path d="M12 21s-6-5.3-6-10a6 6 0 1112 0c0 4.7-6 10-6 10z"/><circle cx="12" cy="11" r="2"/>',
    cog: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 ' +
        '1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 ' +
        '00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 ' +
        '1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1' +
        'a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
    link: '<path d="M10 14a5 5 0 007 0l3-3a5 5 0 00-7-7l-1 1"/><path d="M14 10a5 5 0 00-7 0l-3 3a5 5 0 007 7l1-1"/>',
    brain: '<path d="M9 4a3 3 0 00-3 3 3 3 0 00-2 5 3 3 0 002 5 3 3 0 003 3V4zM15 4a3 3 0 013 3 3 3 0 012 5 3 3 0 01-2 5 3 3 0 ' +
        '01-3 3V4z"/>',
    stethoscope: '<path d="M6 3v6a4 4 0 008 0V3"/><path d="M10 13v3a4 4 0 008 0v-2"/><circle cx="18" cy="12" r="2"/>',
    compass: '<circle cx="12" cy="12" r="9"/><path d="M15.5 8.5l-2 5-5 2 2-5z"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17' +
        'M17 7l2.1-2.1"/>',
    root: '<path d="M4 20c4-1 6-4 6-9V4M10 11c2 0 4 2 4 5"/><path d="M14 4h6M17 4v6"/>',
    sparkle: '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 16l.8 2.2L22 19l-2.2.8L19 22' +
        'l-.8-2.2L16 19l2.2-.8z"/>',
    copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 012-2h10"/>',
    save: '<path d="M5 3h11l3 3v15H5z"/><path d="M8 3v6h8V3M8 21v-7h8v7"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    edit: '<path d="M4 20h4l10-10-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
    chevron: '<path d="M9 6l6 6-6 6"/>',
    home: '<path d="M3 11l9-7 9 7v9H3z"/><path d="M9 20v-6h6v6"/>',
};

/**
 * Inline SVG icon markup from the static icon set.
 *
 * @param {string} name
 * @returns {string}
 */
export const icon = (name) => `<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">${ICONS[name] || ''}</svg>`;

/**
 * Icon button.
 *
 * @param {string} name icon
 * @param {string} label accessible label
 * @param {string} cls extra classes
 * @returns {HTMLButtonElement}
 */
export const iconButton = (name, label, cls = '') => el('button', 'aa-iconbtn ' + cls, {
    type: 'button', 'aria-label': label, title: label, html: icon(name),
});

/**
 * Button with icon and text.
 *
 * @param {string} text
 * @param {string} iconname
 * @param {string} cls
 * @param {boolean} iconafter
 * @returns {HTMLButtonElement}
 */
export const button = (text, iconname, cls = 'aa-btn-ghost', iconafter = false) => {
    const b = el('button', 'aa-btn ' + cls, {type: 'button'});
    const span = el('span', '', {text});
    const ic = iconname ? el('span', 'aa-btnic', {html: icon(iconname)}) : null;
    if (iconafter) {
        b.append(span, ...(ic ? [ic] : []));
    } else {
        b.append(...(ic ? [ic] : []), span);
    }
    return b;
};

/**
 * Formats seconds as m:ss.
 *
 * @param {number} secs
 * @returns {string}
 */
export const clock = (secs) => {
    secs = Math.max(0, Math.round(secs));
    return `${Math.floor(secs / 60)}:${String(secs % 60).padStart(2, '0')}`;
};

/**
 * Shuffles an array copy.
 *
 * @param {Array} list
 * @returns {Array}
 */
export const shuffle = (list) => {
    const a = list.slice();
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
};

/**
 * Confetti burst.
 *
 * @param {HTMLElement} host
 * @param {number} amount
 */
export const confetti = (host, amount = 140) => {
    if (REDUCED) {
        return;
    }
    const canvas = el('canvas', 'aa-confetti', {'aria-hidden': 'true'});
    host.appendChild(canvas);
    const rect = host.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    const c = canvas.getContext('2d');
    c.scale(dpr, dpr);
    const colors = ['#6366F1', '#0EA5E9', '#10B981', '#F59E0B', '#EF4444', '#EC4899', '#8B5CF6'];
    const parts = Array.from({length: amount}, () => ({
        x: rect.width / 2 + (Math.random() - 0.5) * rect.width * 0.3,
        y: rect.height * 0.35,
        vx: (Math.random() - 0.5) * 14,
        vy: -Math.random() * 13 - 4,
        r: Math.random() * 6 + 4,
        a: Math.random() * Math.PI,
        va: (Math.random() - 0.5) * 0.3,
        color: colors[Math.floor(Math.random() * colors.length)],
        shape: Math.random() > 0.5,
    }));
    const start = performance.now();
    const frame = (now) => {
        const t = now - start;
        c.clearRect(0, 0, rect.width, rect.height);
        parts.forEach((p) => {
            p.vy += 0.35;
            p.vx *= 0.985;
            p.x += p.vx;
            p.y += p.vy;
            p.a += p.va;
            c.save();
            c.globalAlpha = Math.max(0, 1 - t / 2600);
            c.translate(p.x, p.y);
            c.rotate(p.a);
            c.fillStyle = p.color;
            if (p.shape) {
                c.fillRect(-p.r / 2, -p.r / 4, p.r, p.r / 2);
            } else {
                c.beginPath();
                c.arc(0, 0, p.r / 2.6, 0, Math.PI * 2);
                c.fill();
            }
            c.restore();
        });
        if (t < 2600) {
            requestAnimationFrame(frame);
        } else {
            canvas.remove();
        }
    };
    requestAnimationFrame(frame);
};

/**
 * Small particle burst around a point.
 *
 * @param {HTMLElement} host positioned container
 * @param {number} x
 * @param {number} y
 * @param {string} color
 */
export const burst = (host, x, y, color) => {
    if (REDUCED) {
        return;
    }
    for (let i = 0; i < 12; i++) {
        const p = el('span', 'aa-particle');
        const angle = (Math.PI * 2 * i) / 12 + Math.random() * 0.4;
        const dist = 26 + Math.random() * 22;
        p.style.left = `${x}px`;
        p.style.top = `${y}px`;
        p.style.background = i % 3 === 0 ? '#FACC15' : color;
        host.appendChild(p);
        p.animate([
            {transform: 'translate(-50%, -50%) scale(1)', opacity: 1},
            {transform: `translate(calc(-50% + ${Math.cos(angle) * dist}px), calc(-50% + ${Math.sin(angle) * dist}px))
                scale(0.2)`, opacity: 0},
        ], {duration: 620 + Math.random() * 200, easing: 'cubic-bezier(.2,.8,.3,1)'}).finished
            .then(() => p.remove()).catch(() => p.remove());
    }
};

/**
 * Speaks text with the browser's speech synthesis (no audio files or external calls).
 *
 * @param {string} text
 * @returns {boolean} whether speech is available
 */
export const speak = (text) => {
    if (!('speechSynthesis' in window)) {
        return false;
    }
    try {
        window.speechSynthesis.cancel();
        const u = new SpeechSynthesisUtterance(text);
        u.rate = 0.85;
        u.lang = document.documentElement.lang || 'en';
        window.speechSynthesis.speak(u);
        return true;
    } catch (e) {
        return false;
    }
};
