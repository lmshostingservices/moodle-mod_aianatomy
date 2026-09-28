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
 * Label overlay for the 3D viewer: anchor dots on the anatomy, label boxes in screen space and
 * leader lines between them. Anchors are mesh-local, so lines follow the anatomy as it rotates;
 * label boxes keep their (normalised) screen position.
 *
 * @module     mod_aianatomy/overlay
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SVGNS = 'http://www.w3.org/2000/svg';

/**
 * Overlay.
 */
export default class Overlay {
    /**
     * Constructor.
     *
     * @param {HTMLElement} host positioned element on top of the canvas
     * @param {Viewer} viewer
     */
    constructor(host, viewer) {
        this.host = host;
        this.viewer = viewer;
        this.items = new Map();
        this.layer = document.createElement('div');
        this.layer.className = 'ax-overlay';
        this.svg = document.createElementNS(SVGNS, 'svg');
        this.svg.setAttribute('class', 'ax-lines');
        this.svg.setAttribute('aria-hidden', 'true');
        this.layer.appendChild(this.svg);
        host.appendChild(this.layer);
        this.onDragEnd = null;
        viewer.on('frame', () => this.update());
        viewer.on('resize', () => {
            this.layout(false);
            this.update();
        });
    }

    /**
     * Removes all items.
     */
    clear() {
        this.items.forEach((it) => {
            it.box.remove();
            it.dot.remove();
            it.line.remove();
        });
        this.items.clear();
    }

    /**
     * Sets the items.
     *
     * @param {object[]} list [{key, node, anchor, pos: {x,y}|null, box: HTMLElement, colour, number}]
     */
    set(list) {
        this.clear();
        list.forEach((spec) => {
            const dot = document.createElement('div');
            dot.className = 'ax-dot';
            if (spec.number) {
                dot.textContent = spec.number;
                dot.classList.add('has-number');
            }
            const line = document.createElementNS(SVGNS, 'path');
            line.setAttribute('class', 'ax-line');
            const box = spec.box;
            box.classList.add('ax-box');
            box.dataset.key = spec.key;
            dot.dataset.key = spec.key;
            if (spec.colour) {
                box.style.setProperty('--aa-c', spec.colour);
                dot.style.setProperty('--aa-c', spec.colour);
                line.style.stroke = spec.colour;
            }
            this.svg.appendChild(line);
            this.layer.append(dot, box);
            const item = {...spec, dot, line, box, pos: null, saved: spec.pos ? {...spec.pos} : null};
            this.items.set(spec.key, item);
            if (spec.draggable) {
                this.makeDraggable(item);
            }
        });
        this.layout(false);
        this.update();
    }

    /**
     * Returns an item.
     *
     * @param {string} key
     * @returns {object|undefined}
     */
    get(key) {
        return this.items.get(key);
    }

    /**
     * Automatic layout for boxes without a saved position: two columns (left/right of the anatomy),
     * each sorted top to bottom so leader lines do not cross.
     *
     * @param {boolean} all re-layout every item (ignores saved positions)
     */
    layout(all = false) {
        const w = this.host.clientWidth || 1;
        const h = this.host.clientHeight || 1;
        const pending = [];
        const narrow = w < 560;
        this.items.forEach((it) => {
            // Saved positions are designed on a desktop-sized view; small screens use the automatic layout.
            const useSaved = it.saved && !all && (!narrow || it.draggable);
            if (useSaved) {
                it.pos = {...it.saved};
                it.auto = false;
                return;
            }
            if (all) {
                it.saved = null;
            }
            if (all || !it.pos || it.auto || it.saved) {
                const p = this.viewer.project(it.node, it.anchor);
                pending.push({it, p: p || {x: w / 2, y: h / 2}});
            }
        });
        if (!pending.length) {
            return;
        }
        const cx = pending.reduce((s, e) => s + e.p.x, 0) / pending.length;
        const left = pending.filter((e) => e.p.x < cx).sort((a, b) => a.p.y - b.p.y);
        const right = pending.filter((e) => e.p.x >= cx).sort((a, b) => a.p.y - b.p.y);
        // Balance the columns.
        while (left.length > right.length + 1) {
            right.push(left.pop());
            right.sort((a, b) => a.p.y - b.p.y);
        }
        while (right.length > left.length + 1) {
            left.push(right.shift());
            left.sort((a, b) => a.p.y - b.p.y);
        }
        const rightEdge = 1 - Math.min(this.viewer.inset || 0, w * 0.6) / w;
        const place = (col, side) => {
            const n = col.length;
            col.forEach((e, i) => {
                const span = narrow ? 0.58 : 0.74;
                const y = n === 1 ? 0.45 : 0.16 + (span * i) / (n - 1);
                const x = narrow ? (side === 'l' ? 0.02 : rightEdge - 0.4) : (side === 'l' ? 0.03 : rightEdge - 0.23);
                e.it.pos = {x, y};
                e.it.auto = true;
            });
        };
        place(left, 'l');
        place(right, 'r');
        this.update();
    }

    /**
     * Repositions dots, boxes and lines.
     */
    update() {
        const w = this.host.clientWidth;
        const h = this.host.clientHeight;
        this.svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
        this.items.forEach((it) => {
            const p = this.viewer.project(it.node, it.anchor);
            if (!p || !p.visible || it.hidden) {
                it.dot.hidden = true;
                it.box.hidden = !!it.hidden;
                it.line.setAttribute('d', '');
                return;
            }
            it.dot.hidden = false;
            it.box.hidden = false;
            it.dot.style.transform = `translate(${p.x}px, ${p.y}px)`;
            const pos = it.pos || {x: 0.02, y: 0.02};
            const bw = it.box.offsetWidth;
            const bh = it.box.offsetHeight;
            const bx = Math.max(4, Math.min(w - bw - 4, pos.x * w));
            const by = Math.max(4, Math.min(h - bh - 4, pos.y * h - bh / 2));
            it.box.style.transform = `translate(${bx}px, ${by}px)`;
            // Leader line from the nearest box edge to the anchor dot.
            const sx = p.x < bx ? bx : (p.x > bx + bw ? bx + bw : bx + bw / 2);
            const sy = by + bh / 2;
            const mx = (sx + p.x) / 2;
            it.line.setAttribute('d', `M${sx},${sy} C${mx},${sy} ${mx},${p.y} ${p.x},${p.y}`);
        });
    }

    /**
     * Returns the item whose box or dot is under a point.
     *
     * @param {number} x client x
     * @param {number} y client y
     * @param {number} slack extra px around targets
     * @returns {object|null}
     */
    hitTest(x, y, slack = 14) {
        let best = null;
        let bestD = Infinity;
        this.items.forEach((it) => {
            if (it.hidden || it.box.hidden) {
                return;
            }
            [it.box, it.dot].forEach((node) => {
                const r = node.getBoundingClientRect();
                const dx = Math.max(r.left - x, 0, x - r.right);
                const dy = Math.max(r.top - y, 0, y - r.bottom);
                const d = Math.hypot(dx, dy);
                if (d <= slack && d < bestD) {
                    best = it;
                    bestD = d;
                }
            });
        });
        return best;
    }

    /**
     * Lets a teacher drag a label box to a new screen position.
     *
     * @param {object} it
     */
    makeDraggable(it) {
        it.box.addEventListener('pointerdown', (e) => {
            if (e.button !== 0) {
                return;
            }
            e.preventDefault();
            const start = {x: e.clientX, y: e.clientY};
            const rect = this.host.getBoundingClientRect();
            const origin = {...(it.pos || {x: 0, y: 0})};
            it.box.setPointerCapture(e.pointerId);
            it.box.classList.add('is-dragging');
            const move = (ev) => {
                it.pos = {
                    x: Math.max(0, Math.min(0.95, origin.x + (ev.clientX - start.x) / rect.width)),
                    y: Math.max(0.02, Math.min(0.98, origin.y + (ev.clientY - start.y) / rect.height)),
                };
                it.auto = false;
                this.update();
            };
            const up = () => {
                it.box.removeEventListener('pointermove', move);
                it.box.removeEventListener('pointerup', up);
                it.box.classList.remove('is-dragging');
                it.saved = {...it.pos};
                if (this.onDragEnd) {
                    this.onDragEnd(it);
                }
            };
            it.box.addEventListener('pointermove', move);
            it.box.addEventListener('pointerup', up);
        });
        it.box.addEventListener('keydown', (e) => {
            const step = e.shiftKey ? 0.05 : 0.01;
            const d = {ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step]}[e.key];
            if (!d) {
                return;
            }
            e.preventDefault();
            const p = it.pos || {x: 0, y: 0};
            it.pos = {x: Math.max(0, Math.min(0.95, p.x + d[0])), y: Math.max(0.02, Math.min(0.98, p.y + d[1]))};
            it.auto = false;
            it.saved = {...it.pos};
            this.update();
            if (this.onDragEnd) {
                this.onDragEnd(it);
            }
        });
    }

    /**
     * Destroys the overlay.
     */
    destroy() {
        this.clear();
        this.layer.remove();
    }
}
