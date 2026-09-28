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
 * 3D anatomy viewer.
 *
 * The viewer only knows how to render, pick, highlight, fade, hide, isolate, explode and frame
 * anatomical objects identified by their node name. It holds no anatomy names, no quiz rules and
 * no educational text: all of that comes from data supplied by the caller.
 *
 * @module     mod_aianatomy/viewer
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as THREE from 'mod_aianatomy/three';

const REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Visual states. Colour is never the only cue: states also change emissive glow and opacity. */
const STATES = {
    default: {color: 0xeee3cc, emissive: 0x000000, ei: 0},
    hover: {color: 0xf6ecd6, emissive: 0x6366f1, ei: 0.18},
    selected: {color: 0xc7c4ff, emissive: 0x4f46e5, ei: 0.45},
    target: {color: 0xfde68a, emissive: 0xf59e0b, ei: 0.35},
    correct: {color: 0xa7f3d0, emissive: 0x12b76a, ei: 0.45},
    incorrect: {color: 0xfecaca, emissive: 0xf04438, ei: 0.5},
    muted: {color: 0xd9d4ca, emissive: 0x000000, ei: 0},
};

/**
 * Checks for WebGL support.
 *
 * @returns {boolean}
 */
export const supported = () => {
    try {
        const c = document.createElement('canvas');
        return !!(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
    } catch (e) {
        return false;
    }
};

const easeInOut = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

/**
 * The viewer.
 */
export default class Viewer {
    /**
     * Constructor.
     *
     * @param {HTMLElement} container
     * @param {object} opts {modelUrl, presets: [{id, dir, up}], defaultPreset, pickHidden}
     */
    constructor(container, opts) {
        this.container = container;
        this.opts = opts;
        this.handlers = {};
        this.nodes = new Map();
        this.tweens = [];
        this.dirty = true;
        this.hoverName = null;
        this.pickable = null;
        this.explodeState = {names: [], amount: 0};

        const renderer = new THREE.WebGLRenderer({antialias: true, alpha: true, preserveDrawingBuffer: false});
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.outputColorSpace = THREE.SRGBColorSpace;
        renderer.toneMapping = THREE.NoToneMapping;
        renderer.domElement.className = 'aa-canvas';
        renderer.domElement.setAttribute('aria-hidden', 'true');
        container.appendChild(renderer.domElement);
        this.renderer = renderer;

        this.scene = new THREE.Scene();
        this.camera = new THREE.PerspectiveCamera(30, 1, 1, 5000);
        this.scene.add(new THREE.HemisphereLight(0xffffff, 0xb9b4c8, 1.35));
        const key = new THREE.DirectionalLight(0xffffff, 1.6);
        key.position.set(0.6, 1, 1.2);
        this.camera.add(key);
        const rim = new THREE.DirectionalLight(0xdfe7ff, 0.6);
        rim.position.set(-1, -0.4, -0.8);
        this.camera.add(rim);
        this.scene.add(this.camera);

        this.controls = new THREE.OrbitControls(this.camera, renderer.domElement);
        this.controls.enableDamping = !REDUCED;
        this.controls.dampingFactor = 0.12;
        this.controls.rotateSpeed = 0.8;
        this.controls.zoomSpeed = 0.9;
        this.controls.screenSpacePanning = true;
        this.controls.addEventListener('change', () => {
            this.dirty = true;
            this.emit('camera');
        });

        this.raycaster = new THREE.Raycaster();
        this.pointer = new THREE.Vector2();
        this.bindPointer();

        this.resizeObserver = new ResizeObserver(() => this.resize());
        this.resizeObserver.observe(container);
        this.resize();

        this.running = true;
        const loop = () => {
            if (!this.running) {
                return;
            }
            this.frameHandle = window.requestAnimationFrame(loop);
            this.tick();
        };
        loop();
    }

    /**
     * Registers an event handler: select, hover, frame, camera, ready.
     *
     * @param {string} name
     * @param {function} fn
     * @returns {Viewer}
     */
    on(name, fn) {
        (this.handlers[name] = this.handlers[name] || []).push(fn);
        return this;
    }

    /**
     * Emits an event.
     *
     * @param {string} name
     * @param {...*} args
     */
    emit(name, ...args) {
        (this.handlers[name] || []).forEach((fn) => fn(...args));
    }

    /**
     * Loads the model. Each child node of the model root is one anatomical structure.
     *
     * @returns {Promise}
     */
    load() {
        return new Promise((resolve, reject) => {
            const loader = new THREE.GLTFLoader();
            loader.load(this.opts.modelUrl, (gltf) => {
                this.root = gltf.scene;
                this.scene.add(this.root);
                this.root.traverse((obj) => {
                    if (!obj.isMesh) {
                        return;
                    }
                    const node = obj.parent && obj.parent.isMesh === false && obj.name === '' ? obj.parent : obj;
                    const name = node.name || obj.name;
                    // Tissue packs give each structure its own colour; bone packs use the neutral bone colour.
                    const src = obj.material;
                    const base = src && src.name === 'tissue' && src.color ? src.color.clone() : null;
                    obj.material = new THREE.MeshStandardMaterial({
                        color: STATES.default.color, roughness: base ? 0.55 : 0.62, metalness: 0, transparent: false,
                    });
                    if (base) {
                        obj.material.color.copy(base);
                    }
                    if (src && src.dispose) {
                        src.dispose();
                    }
                    obj.userData.aaname = name;
                    this.nodes.set(name, {
                        name, object: node, mesh: obj, home: node.position.clone(), base: base,
                        state: 'default', opacity: 1, visible: true,
                    });
                });
                this.scene.updateMatrixWorld(true);
                this.fitAll(false);
                this.dirty = true;
                this.emit('ready');
                resolve();
            }, undefined, reject);
        });
    }

    /**
     * Node names in the model.
     *
     * @returns {string[]}
     */
    names() {
        return Array.from(this.nodes.keys());
    }

    /**
     * Resizes the renderer to its container.
     */
    resize() {
        const w = Math.max(1, this.container.clientWidth);
        const h = Math.max(1, this.container.clientHeight);
        this.renderer.setSize(w, h, false);
        this.camera.aspect = w / h;
        this.applyInset();
        this.dirty = true;
        this.emit('resize');
    }

    /**
     * Keeps the anatomy centred in the part of the view not covered by a side panel (e.g. the Study card).
     *
     * @param {number} px width covered on the right
     */
    setRightInset(px) {
        this.inset = Math.max(0, px || 0);
        this.applyInset();
        this.dirty = true;
        this.emit('camera');
    }

    /**
     * Applies the right inset as a camera view offset.
     */
    applyInset() {
        const w = Math.max(1, this.container.clientWidth);
        const h = Math.max(1, this.container.clientHeight);
        const inset = Math.min(this.inset || 0, w * 0.6);
        if (inset > 0) {
            this.camera.setViewOffset(w + inset, h, inset, 0, w, h);
        } else {
            this.camera.clearViewOffset();
        }
        this.camera.updateProjectionMatrix();
    }

    /**
     * Animation frame: runs tweens, updates controls, renders when needed and notifies overlays.
     */
    tick() {
        const now = performance.now();
        if (this.tweens.length) {
            this.tweens = this.tweens.filter((tw) => {
                const t = Math.min(1, (now - tw.start) / tw.duration);
                tw.step(easeInOut(t));
                if (t >= 1 && tw.done) {
                    tw.done();
                }
                return t < 1;
            });
            this.dirty = true;
        }
        if (this.controls.update()) {
            this.dirty = true;
        }
        if (this.dirty) {
            this.dirty = false;
            this.renderer.render(this.scene, this.camera);
            this.emit('frame');
        }
    }

    /**
     * Adds a tween.
     *
     * @param {number} duration ms
     * @param {function} step receives eased 0..1
     * @returns {Promise}
     */
    tween(duration, step) {
        return new Promise((resolve) => {
            if (REDUCED || duration <= 0) {
                step(1);
                this.dirty = true;
                resolve();
                return;
            }
            this.tweens.push({start: performance.now(), duration, step, done: resolve});
        });
    }

    /**
     * Pointer handling: click (not drag) selects, mouse move hovers.
     */
    bindPointer() {
        const el = this.renderer.domElement;
        let down = null;
        el.addEventListener('pointerdown', (e) => {
            down = {x: e.clientX, y: e.clientY, t: performance.now()};
        });
        el.addEventListener('pointerup', (e) => {
            if (!down) {
                return;
            }
            const moved = Math.hypot(e.clientX - down.x, e.clientY - down.y);
            down = null;
            if (moved > 6) {
                return;
            }
            const hit = this.pick(e.clientX, e.clientY);
            this.emit('select', hit ? hit.name : null, hit, e);
        });
        let pending = null;
        el.addEventListener('pointermove', (e) => {
            if (e.pointerType !== 'mouse' || down) {
                return;
            }
            pending = e;
            if (this.hoverQueued) {
                return;
            }
            this.hoverQueued = true;
            window.requestAnimationFrame(() => {
                this.hoverQueued = false;
                const hit = this.pick(pending.clientX, pending.clientY);
                const name = hit ? hit.name : null;
                if (name !== this.hoverName) {
                    this.hoverName = name;
                    el.style.cursor = name ? 'pointer' : '';
                    this.emit('hover', name);
                }
            });
        });
        el.addEventListener('pointerleave', () => {
            if (this.hoverName) {
                this.hoverName = null;
                el.style.cursor = '';
                this.emit('hover', null);
            }
        });
    }

    /**
     * Limits which nodes can be picked (null = all visible nodes).
     *
     * @param {string[]|null} names
     */
    setPickable(names) {
        this.pickable = names ? new Set(names) : null;
    }

    /**
     * Returns the structure under a screen point.
     *
     * @param {number} clientX
     * @param {number} clientY
     * @returns {object|null} {name, point (node-local [x,y,z]), world}
     */
    pick(clientX, clientY) {
        const rect = this.renderer.domElement.getBoundingClientRect();
        this.pointer.set(((clientX - rect.left) / rect.width) * 2 - 1, -((clientY - rect.top) / rect.height) * 2 + 1);
        this.raycaster.setFromCamera(this.pointer, this.camera);
        const meshes = [];
        this.nodes.forEach((n) => {
            if (n.visible && n.opacity > 0.05 && (!this.pickable || this.pickable.has(n.name))) {
                meshes.push(n.mesh);
            }
        });
        const hits = this.raycaster.intersectObjects(meshes, false);
        if (!hits.length) {
            return null;
        }
        const hit = hits[0];
        const name = hit.object.userData.aaname;
        const node = this.nodes.get(name);
        const local = node.object.worldToLocal(hit.point.clone());
        return {name, point: [local.x, local.y, local.z], world: hit.point.clone()};
    }

    /**
     * Sets the visual state of nodes.
     *
     * @param {string|string[]} names
     * @param {string} state
     */
    setState(names, state) {
        [].concat(names).forEach((name) => {
            const n = this.nodes.get(name);
            if (!n) {
                return;
            }
            n.state = state;
            const s = STATES[state] || STATES.default;
            const own = n.tint || n.base;
            if (n.tint && state === 'correct') {
                // Labelled correctly: keep its own colour (so structures stay distinguishable) with a green glow.
                n.mesh.material.color.copy(n.tint);
            } else if (own && (state === 'default' || state === 'hover' || state === 'muted')) {
                // Keep the structure's own colour (its number colour, or tissue colour); hover lightens it,
                // muted greys it.
                n.mesh.material.color.copy(own);
                if (state === 'hover') {
                    n.mesh.material.color.lerp(new THREE.Color(0xffffff), 0.18);
                } else if (state === 'muted') {
                    n.mesh.material.color.lerp(new THREE.Color(0xbbbbbb), 0.55);
                }
            } else {
                n.mesh.material.color.setHex(s.color);
            }
            n.mesh.material.emissive.setHex(s.emissive);
            n.mesh.material.emissiveIntensity = n.tint && state === 'correct' ? 0.15 : s.ei;
        });
        this.dirty = true;
    }

    /**
     * Colours structures to match their numbered label, as a lighter shade of the label colour, so neighbouring
     * structures are easy to tell apart. Structures not listed go back to their tissue or bone colour.
     *
     * @param {Object} colours node name => CSS hex colour
     */
    setTints(colours) {
        const map = colours || {};
        this.nodes.forEach((n, name) => {
            const hex = map[name];
            if (hex) {
                const c = new THREE.Color(hex);
                const hsl = {};
                c.getHSL(hsl, THREE.SRGBColorSpace);
                // Lighter than the label so the 3D shading still reads; darker labels (13+) stay darker here too.
                c.setHSL(hsl.h, Math.min(hsl.s, 0.6), Math.min(0.7, hsl.l * 0.9 + 0.3), THREE.SRGBColorSpace);
                n.tint = c;
            } else {
                n.tint = null;
            }
            this.setState(name, n.state);
        });
    }

    /**
     * Resets all nodes to the default state.
     */
    clearStates() {
        this.setState(this.names(), 'default');
    }

    /**
     * Sets opacity (and visibility) of nodes.
     *
     * @param {string|string[]} names
     * @param {number} opacity 0 hides
     */
    setOpacity(names, opacity) {
        [].concat(names).forEach((name) => {
            const n = this.nodes.get(name);
            if (!n) {
                return;
            }
            n.opacity = opacity;
            n.visible = opacity > 0.001;
            n.object.visible = n.visible;
            const m = n.mesh.material;
            m.transparent = opacity < 0.999;
            m.opacity = opacity;
            m.depthWrite = opacity >= 0.6;
            m.needsUpdate = true;
        });
        this.dirty = true;
    }

    /**
     * Shows the given nodes fully and the others faded (or hidden when others = 0).
     *
     * @param {string[]} names
     * @param {number} others opacity for all other nodes
     */
    isolate(names, others = 0.12) {
        const keep = new Set(names);
        this.nodes.forEach((n) => this.setOpacity(n.name, keep.has(n.name) ? 1 : others));
    }

    /**
     * Context view: focus fully visible, neighbours partly, the rest faint.
     *
     * @param {string[]} focus
     * @param {string[]} near
     * @param {number} nearOpacity
     * @param {number} farOpacity
     */
    context(focus, near, nearOpacity = 0.35, farOpacity = 0.12) {
        const f = new Set(focus);
        const nn = new Set(near);
        this.nodes.forEach((n) => {
            let o = farOpacity;
            if (f.has(n.name)) {
                o = 1;
            } else if (nn.has(n.name)) {
                o = nearOpacity;
            }
            this.setOpacity(n.name, o);
        });
    }

    /**
     * Shows every node at full opacity.
     */
    showAll() {
        this.setOpacity(this.names(), 1);
    }

    /**
     * Centre of a set of nodes' home positions.
     *
     * @param {string[]} names
     * @returns {THREE.Vector3}
     */
    homeCentre(names) {
        const c = new THREE.Vector3();
        let k = 0;
        names.forEach((name) => {
            const n = this.nodes.get(name);
            if (n) {
                c.add(n.home);
                k++;
            }
        });
        return k ? c.multiplyScalar(1 / k) : c;
    }

    /**
     * Explodes a group of nodes outwards from the group centre. Other nodes return home.
     *
     * The direction for each structure is (structure centre - group centre); spacing scales with the
     * group size so small groups (carpals) and large groups (phalanges) both separate clearly.
     *
     * @param {string[]} names
     * @param {number} amount 0 = anatomical position, 1 = standard, 1.5 = wide
     * @param {boolean} animate
     * @param {number[]|null} viewDir optional viewing direction: separation is mostly kept across the screen
     *     so structures that lie one behind the other (e.g. pisiform on triquetrum) still separate visibly
     * @param {object} overrides node name => [x,y,z] direction set by the pack author for structures that
     *     would otherwise separate badly
     * @returns {Promise}
     */
    explode(names, amount, animate = true, viewDir = null, overrides = {}) {
        const group = names.filter((n) => this.nodes.has(n));
        const centre = this.homeCentre(group);
        let spread = 0;
        group.forEach((name) => {
            spread = Math.max(spread, this.nodes.get(name).home.distanceTo(centre));
        });
        const dist = Math.max(spread, 8) * 0.55;
        const inGroup = new Set(group);
        const targets = new Map();
        this.nodes.forEach((n) => {
            let to = n.home.clone();
            if (inGroup.has(n.name) && amount > 0) {
                const dir = overrides[n.name] ? new THREE.Vector3(...overrides[n.name]) : n.home.clone().sub(centre);
                if (viewDir && !overrides[n.name]) {
                    const v = new THREE.Vector3(...viewDir).normalize();
                    dir.sub(v.multiplyScalar(dir.dot(v) * 0.8));
                }
                if (dir.lengthSq() < 1e-6) {
                    dir.set(0, 1, 0);
                }
                dir.normalize();
                const own = n.home.distanceTo(centre) / Math.max(spread, 1e-3);
                const scale = overrides[n.name] ? 1.5 : 0.45 + 0.55 * own;
                to = n.home.clone().add(dir.multiplyScalar(dist * amount * scale));
            }
            targets.set(n.name, {from: n.object.position.clone(), to});
        });
        this.explodeState = {names: group, amount};
        return this.tween(animate ? 650 : 0, (t) => {
            targets.forEach((tg, name) => {
                this.nodes.get(name).object.position.lerpVectors(tg.from, tg.to, t);
            });
            this.scene.updateMatrixWorld(true);
        });
    }

    /**
     * Returns every node to its anatomical position.
     *
     * @param {boolean} animate
     * @returns {Promise}
     */
    assemble(animate = true) {
        return this.explode([], 0, animate);
    }

    /**
     * Bounding sphere of nodes at their current (or target) positions.
     *
     * @param {string[]} names
     * @returns {THREE.Sphere}
     */
    bounds(names) {
        const box = new THREE.Box3();
        names.forEach((name) => {
            const n = this.nodes.get(name);
            if (n) {
                box.expandByObject(n.object);
            }
        });
        const sphere = new THREE.Sphere();
        if (box.isEmpty()) {
            sphere.radius = 50;
            return sphere;
        }
        box.getBoundingSphere(sphere);
        return sphere;
    }

    /**
     * Moves the camera to frame nodes, optionally from a preset direction.
     *
     * @param {string[]} names
     * @param {object} opts {preset, animate, padding}
     * @returns {Promise}
     */
    frame(names, opts = {}) {
        const sphere = this.bounds(names.length ? names : this.names());
        const fov = THREE.MathUtils.degToRad(this.camera.fov);
        const w = Math.max(1, this.container.clientWidth);
        const h = Math.max(1, this.container.clientHeight);
        const aspect = Math.max(0.2, (w - Math.min(this.inset || 0, w * 0.6)) / h);
        const fit = Math.min(fov, 2 * Math.atan(Math.tan(fov / 2) * aspect));
        const distance = (sphere.radius * (opts.padding || 1.18)) / Math.sin(fit / 2);
        let dir;
        let up = this.camera.up.clone();
        const preset = opts.preset && (this.opts.presets || []).find((p) => p.id === opts.preset);
        if (preset) {
            dir = new THREE.Vector3(...preset.dir).normalize();
            up = new THREE.Vector3(...(preset.up || [0, 1, 0]));
        } else {
            dir = this.camera.position.clone().sub(this.controls.target).normalize();
        }
        const fromPos = this.camera.position.clone();
        const fromTarget = this.controls.target.clone();
        const fromUp = this.camera.up.clone();
        const toPos = sphere.center.clone().add(dir.multiplyScalar(distance));
        const toTarget = sphere.center.clone();
        this.camera.near = Math.max(0.5, distance / 100);
        this.camera.far = distance * 20;
        this.camera.updateProjectionMatrix();
        return this.tween(opts.animate === false ? 0 : 700, (t) => {
            this.camera.position.lerpVectors(fromPos, toPos, t);
            this.controls.target.lerpVectors(fromTarget, toTarget, t);
            this.camera.up.lerpVectors(fromUp, up, t).normalize();
            this.camera.lookAt(this.controls.target);
            this.emit('camera');
        });
    }

    /**
     * Current viewing direction (from target to camera), for view-aware explode.
     *
     * @returns {number[]}
     */
    viewDir() {
        const d = this.camera.position.clone().sub(this.controls.target).normalize();
        return [d.x, d.y, d.z];
    }

    /**
     * Direction of a camera preset.
     *
     * @param {string} id
     * @returns {number[]|null}
     */
    presetDir(id) {
        const p = (this.opts.presets || []).find((x) => x.id === id);
        return p ? p.dir : null;
    }

    /**
     * Frames the whole model.
     *
     * @param {boolean} animate
     * @returns {Promise}
     */
    fitAll(animate = true) {
        return this.frame(this.names(), {preset: this.opts.defaultPreset, animate});
    }

    /**
     * Applies a camera preset to the current framing.
     *
     * @param {string} id
     * @param {string[]} names nodes to frame
     * @returns {Promise}
     */
    preset(id, names) {
        return this.frame(names || this.names(), {preset: id});
    }

    /**
     * Projects a node-local point to container pixels.
     *
     * @param {string} name
     * @param {number[]} local [x,y,z]; defaults to the node origin (structure centroid)
     * @returns {object|null} {x, y, behind, facing}
     */
    project(name, local) {
        const n = this.nodes.get(name);
        if (!n) {
            return null;
        }
        const v = new THREE.Vector3(...(local || [0, 0, 0]));
        n.object.localToWorld(v);
        const cam = v.clone().project(this.camera);
        const w = this.container.clientWidth;
        const h = this.container.clientHeight;
        return {
            x: (cam.x + 1) / 2 * w,
            y: (1 - cam.y) / 2 * h,
            behind: cam.z > 1,
            visible: n.visible,
        };
    }

    /**
     * Renders a PNG snapshot (used for thumbnails in the editor).
     *
     * @returns {string} data URL
     */
    snapshot() {
        this.renderer.render(this.scene, this.camera);
        return this.renderer.domElement.toDataURL('image/png');
    }

    /**
     * Stops rendering and frees GPU resources.
     */
    dispose() {
        this.running = false;
        window.cancelAnimationFrame(this.frameHandle);
        this.resizeObserver.disconnect();
        this.controls.dispose();
        this.nodes.forEach((n) => {
            n.mesh.geometry.dispose();
            n.mesh.material.dispose();
        });
        this.renderer.dispose();
        this.renderer.domElement.remove();
    }
}
