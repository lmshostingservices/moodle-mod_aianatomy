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
 * Teacher editor: choose structures, place label anchors and label boxes on the 3D model, review
 * AI-drafted teaching content and questions (AI drafts are never shown to students until approved).
 *
 * @module     mod_aianatomy/editor
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Viewer, {supported} from 'mod_aianatomy/viewer';
import Overlay from 'mod_aianatomy/overlay';
import {loadStrings, fmt, el, icon, iconButton, button} from 'mod_aianatomy/ui';

const STRING_KEYS = [
    'loading', 'nowebgl', 'tab_structures', 'tab_content', 'tab_questions', 'viewactivity', 'activitysettings',
    'ai_ready', 'ai_notready', 'ai_notallowed', 'selectall', 'selectnone', 'structuresselected', 'labeltext',
    'labeltext_help', 'setanchor', 'setanchor_help', 'anchorset', 'resetanchor', 'autoarrange', 'saved', 'saving',
    'savefailed', 'studytip', 'studytip_help', 'previewgroup', 'dragtolabel', 'status_library', 'status_draft',
    'status_approved', 'status_edited', 'aifill', 'aifillall', 'aigenerating', 'aigeneratingx', 'aidone',
    'approve', 'discarddraft', 'save', 'resetlibrary', 'confirmresetlibrary', 'draftbanner', 'aiwarning',
    'field_latin', 'field_pronunciation', 'field_origin', 'field_location', 'field_description', 'field_function',
    'field_mnemonic', 'field_clinical', 'field_hint', 'noquestions', 'addquestion', 'aiquestions', 'aiquestionsall',
    'question', 'options', 'correctoption', 'explanation', 'kind', 'kind_function', 'kind_location',
    'kind_relationship', 'kind_terminology', 'kind_clinical', 'edit', 'delete', 'confirmdeletequestion', 'cancel',
    'allstructures', 'onlyenabled', 'chooseastructure', 'nostructuresenabled', 'aiprovenance', 'librarynote',
    'confirm', 'close', 'fma', 'option',
    'tab_language', 'lang_intro', 'lang_notinstalled', 'lang_english', 'lang_progress', 'lang_translateall',
    'lang_translate', 'lang_translating', 'lang_translatingx', 'lang_translated', 'lang_needstranslation',
    'lang_groups', 'lang_groups_help', 'lang_translategroups', 'lang_grouplabel', 'lang_grouptip', 'lang_savegroups',
    'lang_draftname', 'lang_questionlang', 'lang_othersunused', 'voice_heading', 'voice_off_activity',
    'voice_notconfigured_editor', 'voice_summary', 'voice_stats', 'voice_generateall', 'voice_generating',
    'voice_generatingx', 'voice_generated', 'voice_balance', 'voice_clear', 'voice_confirmclear', 'voice_preview',
    'voice_credits_note', 'ai_pending', 'ai_stillpending', 'ai_resuming', 'aicost', 'voice_available',
    'voice_substituted', 'voice_failed',
];

let S = {};

/**
 * Editor application.
 */
class Editor {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root
     * @param {object} config
     */
    constructor(root, config) {
        this.root = root;
        this.config = config;
        this.structures = new Map(config.structures.map((s) => [s.id, s]));
        this.groups = new Map(config.pack.groups.map((g) => [g.id, g]));
        this.questions = config.questions;
        this.saveTimer = null;
        this.build();
    }

    /**
     * Calls a web service.
     *
     * @param {string} name
     * @param {object} args
     * @returns {Promise}
     */
    call(name, args) {
        return Ajax.call([{methodname: 'mod_aianatomy_' + name, args: {cmid: this.config.cmid, ...args}}])[0];
    }

    /**
     * Calls an LMS Labs generation service and, while LMS Labs is still working (202), calls it again after
     * "retryafter" seconds. The server re-sends the same Idempotency-Key and body, so this never charges twice.
     *
     * @param {string} name
     * @param {object} args
     * @param {Function|null} onwait called while waiting
     * @returns {Promise<object>}
     */
    async poll(name, args, onwait = null) {
        const started = Date.now();
        for (;;) {
            const res = await this.call(name, args);
            if (!res.pending) {
                return res;
            }
            if (onwait) {
                onwait();
            }
            if (Date.now() - started > 180000) {
                const err = new Error(S.ai_stillpending);
                err.stillpending = true;
                throw err;
            }
            await new Promise((resolve) => window.setTimeout(resolve, Math.max(1, res.retryafter || 5) * 1000));
        }
    }

    /**
     * Shows an error (a request that is still running at LMS Labs is a notice, not an error).
     *
     * @param {Error} e
     */
    fail(e) {
        if (e && e.stillpending) {
            Notification.addNotification({message: S.ai_stillpending, type: 'warning'});
        } else {
            Notification.exception(e);
        }
    }

    /**
     * Finishes LMS Labs requests that were still in progress when the page was left (same keys, no new charge).
     */
    async resumeJobs() {
        const jobs = this.config.jobs || [];
        if (!jobs.length || !this.aiReady()) {
            return;
        }
        Notification.addNotification({message: fmt(S.ai_resuming, jobs.length), type: 'info'});
        for (const j of jobs) {
            try {
                if (j.operation === 'content' || j.operation === 'questions') {
                    const res = await this.poll('generate', {what: j.operation, structureids: [j.target]});
                    res.content.forEach((d) => this.applyDraft(d));
                    res.questions.forEach((q) => this.questions.push(q));
                } else if (j.operation === 'translation') {
                    await this.translateOne(j.target);
                } else if (j.operation === 'grouptranslation') {
                    await this.translateGroups();
                }
            } catch (e) {
                this.fail(e);
            }
        }
        this.config.jobs = [];
        this.showTab(this.tab);
    }

    /**
     * Builds the shell.
     */
    build() {
        const root = this.root;
        root.replaceChildren();
        const head = el('div', 'ax-ed-head');
        const tabs = el('div', 'aa-tabs ax-ed-tabs', {role: 'tablist'});
        this.tabButtons = {};
        [['structures', 'tab_structures', 'pin'], ['content', 'tab_content', 'book'], ['questions', 'tab_questions', 'brain'],
            ['language', 'tab_language', 'globe']]
            .forEach(([key, str, ic], i) => {
                const b = el('button', '', {type: 'button', role: 'tab', 'aria-selected': 'false',
                    html: icon(ic)});
                b.appendChild(el('span', '', {text: `${i + 1}. ${S[str]}`}));
                b.addEventListener('click', () => this.showTab(key));
                this.tabButtons[key] = b;
                tabs.appendChild(b);
            });
        const links = el('div', 'ax-ed-links');
        const view = el('a', 'aa-btn aa-btn-ghost aa-btn-sm', {href: this.config.viewurl, html: icon('eye')});
        view.appendChild(el('span', '', {text: S.viewactivity}));
        const settings = el('a', 'aa-btn aa-btn-ghost aa-btn-sm', {href: this.config.settingsurl, html: icon('cog')});
        settings.appendChild(el('span', '', {text: S.activitysettings}));
        this.saveState = el('span', 'aa-savestate', {'aria-live': 'polite'});
        links.append(this.saveState, view, settings);
        head.append(tabs, links);
        root.appendChild(head);

        const ai = this.config.ai;
        let aitext = ai.ready ? fmt(S.ai_ready, ai.name) : S.ai_notready;
        if (ai.ready && !this.config.canuseai) {
            aitext = S.ai_notallowed;
        }
        root.appendChild(el('div', 'ax-ed-ai ' + (ai.ready && this.config.canuseai ? 'is-ready' : 'is-off'), {
            children: [el('span', 'ax-ed-ai-ic', {html: icon('sparkle')}), el('span', '', {
                text: aitext + (ai.ready && this.config.canuseai ? ' ' + S.aicost : '')})],
        }));
        this.panels = {
            structures: el('section', 'ax-ed-panel', {role: 'tabpanel'}),
            content: el('section', 'ax-ed-panel', {role: 'tabpanel'}),
            questions: el('section', 'ax-ed-panel', {role: 'tabpanel'}),
            language: el('section', 'ax-ed-panel', {role: 'tabpanel'}),
        };
        Object.values(this.panels).forEach((p) => {
            p.hidden = true;
            root.appendChild(p);
        });
        this.buildStructuresTab();
        this.showTab('structures');
        this.resumeJobs();
    }

    /**
     * AI actions available?
     *
     * @returns {boolean}
     */
    aiReady() {
        return this.config.ai.ready && this.config.canuseai;
    }

    /**
     * Switches tab.
     *
     * @param {string} key
     */
    showTab(key) {
        this.tab = key;
        Object.entries(this.panels).forEach(([k, p]) => {
            p.hidden = k !== key;
            this.tabButtons[k].setAttribute('aria-selected', k === key ? 'true' : 'false');
            this.tabButtons[k].classList.toggle('is-on', k === key);
        });
        if (key === 'content') {
            this.buildContentTab();
        } else if (key === 'questions') {
            this.buildQuestionsTab();
        } else if (key === 'language') {
            this.buildLanguageTab();
        } else if (this.viewer) {
            this.viewer.resize();
        }
    }

    /**
     * Shows save status.
     *
     * @param {string} state saving|saved|failed
     */
    setSaveState(state) {
        this.saveState.textContent = {saving: S.saving, saved: S.saved, failed: S.savefailed}[state] || '';
        this.saveState.className = 'aa-savestate is-' + state;
    }

    /* ------------------------------------------------------------------ */
    /* Structures and labels                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Tab 1.
     */
    buildStructuresTab() {
        const panel = this.panels.structures;
        const side = el('div', 'ax-ed-side');
        const main = el('div', 'ax-ed-main');
        panel.appendChild(el('div', 'ax-ed-grid', {children: [side, main]}));

        this.countEl = el('p', 'ax-ed-count');
        side.appendChild(this.countEl);
        const tree = el('div', 'ax-ed-tree');
        this.checks = new Map();
        this.rowEls = new Map();
        this.config.pack.groups.filter((g) => g.top).forEach((g) => {
            const members = this.config.structures.filter((s) => s.top === g.id);
            if (!members.length) {
                return;
            }
            const box = el('div', 'ax-ed-group');
            const gcheck = el('input', '', {type: 'checkbox', id: 'ax-g-' + g.id});
            const glabel = el('label', 'ax-ed-grouplabel', {for: 'ax-g-' + g.id, text: g.label});
            const preview = iconButton('eye', fmt(S.previewgroup, g.label), 'aa-btn-sm ax-ed-preview');
            preview.addEventListener('click', () => this.previewGroup(g.id));
            box.appendChild(el('div', 'ax-ed-grouphead', {children: [gcheck, glabel, preview]}));
            const syncGroup = () => {
                const on = members.filter((s) => s.enabled).length;
                gcheck.checked = on === members.length;
                gcheck.indeterminate = on > 0 && on < members.length;
            };
            gcheck.addEventListener('change', () => {
                members.forEach((s) => {
                    s.enabled = gcheck.checked;
                    this.checks.get(s.id).checked = gcheck.checked;
                });
                syncGroup();
                this.structuresChanged(members.map((s) => s.id));
            });
            members.forEach((s) => {
                const row = el('div', 'ax-ed-row');
                const cb = el('input', '', {type: 'checkbox', id: 'ax-s-' + s.id});
                cb.checked = s.enabled;
                cb.addEventListener('change', () => {
                    s.enabled = cb.checked;
                    syncGroup();
                    this.structuresChanged([s.id]);
                });
                const name = el('button', 'ax-ed-name', {type: 'button', text: s.label || s.packname});
                name.addEventListener('click', () => this.selectForLabel(s.id));
                row.append(cb, el('label', 'visually-hidden sr-only', {for: 'ax-s-' + s.id, text: s.packname}), name);
                this.checks.set(s.id, cb);
                this.rowEls.set(s.id, row);
                box.appendChild(row);
            });
            syncGroup();
            tree.appendChild(box);
        });
        side.appendChild(tree);

        // Study tip.
        const tipwrap = el('div', 'ax-ed-tipwrap');
        tipwrap.appendChild(el('label', 'ax-ed-label', {for: 'ax-studytip', text: S.studytip}));
        const tip = el('textarea', 'aa-textarea', {id: 'ax-studytip', rows: '3'});
        tip.value = this.config.studytip || '';
        tip.addEventListener('input', () => {
            this.studytip = tip.value;
            this.queueSave([]);
        });
        tipwrap.appendChild(tip);
        tipwrap.appendChild(el('p', 'ax-ed-help', {text: S.studytip_help}));
        side.appendChild(tipwrap);

        // Viewer and the selected structure's label settings.
        this.view = el('div', 'ax-view ax-ed-view');
        this.inspector = el('div', 'ax-ed-inspector');
        main.append(this.view, this.inspector);
        this.updateCount();
        this.initViewer();
    }

    /**
     * Creates the 3D viewer.
     */
    async initViewer() {
        if (!supported()) {
            this.view.appendChild(el('div', 'ax-nowebgl', {text: S.nowebgl}));
            return;
        }
        const pack = this.config.pack;
        const loading = el('div', 'ax-loading', {children: [el('span', 'ax-spinner'), el('span', '', {text: S.loading})]});
        this.view.appendChild(loading);
        this.viewer = new Viewer(this.view, {modelUrl: pack.model, presets: pack.presets, defaultPreset: pack.defaultpreset});
        this.overlay = new Overlay(this.view, this.viewer);
        this.overlay.onDragEnd = (it) => {
            const s = this.structures.get(it.key);
            s.labelpos = {x: it.pos.x, y: it.pos.y};
            this.queueSave([s.id]);
        };
        try {
            await this.viewer.load();
        } catch (e) {
            loading.remove();
            this.view.appendChild(el('div', 'ax-nowebgl', {text: S.nowebgl}));
            return;
        }
        loading.remove();
        this.byNode = new Map(this.config.structures.map((s) => [s.node, s]));
        this.viewer.on('select', (name, hit) => this.onPick(name, hit));
        const bar = el('div', 'ax-toolbar');
        const presets = el('div', 'ax-presets');
        pack.presets.forEach((p) => {
            const b = el('button', 'ax-preset', {type: 'button', text: p.label.replace(/\s*\(.*\)$/, ''), title: p.label});
            b.addEventListener('click', () => {
                this.preset = p.id;
                if (this.group) {
                    this.previewGroup(this.group);
                }
            });
            presets.appendChild(b);
        });
        const arrange = button(S.autoarrange, 'labels', 'aa-btn-ghost aa-btn-sm');
        arrange.addEventListener('click', () => this.autoArrange());
        bar.append(presets, arrange);
        this.view.appendChild(bar);
        this.hintEl = el('div', 'ax-status is-info is-on', {text: S.dragtolabel});
        this.view.appendChild(this.hintEl);
        const first = this.config.pack.groups.find((g) => g.top && this.config.structures.some((s) => s.top === g.id &&
            s.enabled)) || this.config.pack.groups.find((g) => g.top);
        this.previewGroup(first.id);
    }

    /**
     * Shows a group the way students see it in Practice and Test (focused and exploded).
     *
     * @param {string} gid
     */
    async previewGroup(gid) {
        if (!this.viewer) {
            return;
        }
        this.group = gid;
        const pack = this.config.pack;
        const nodes = this.config.structures.filter((s) => s.top === gid).map((s) => s.node);
        const v = this.viewer;
        v.clearStates();
        v.isolate(nodes, {0: 0, 1: 0.14, 2: 1}[pack.showcontext] ?? 0.14);
        v.setPickable(nodes);
        const preset = this.preset || pack.defaultpreset;
        v.explode(nodes, pack.explode, true, v.presetDir(preset), pack.explodedirs || {});
        await v.frame(nodes, {preset, padding: 1.12 + 0.3 * pack.explode});
        this.refreshOverlay();
        this.root.querySelectorAll('.ax-ed-group').forEach((b) => b.classList.remove('is-preview'));
        const g = this.root.querySelector('#ax-g-' + gid);
        if (g) {
            g.closest('.ax-ed-group').classList.add('is-preview');
        }
    }

    /**
     * Label boxes for enabled structures of the previewed group.
     */
    refreshOverlay() {
        if (!this.overlay) {
            return;
        }
        let i = 0;
        const items = this.config.structures.filter((s) => s.top === this.group && s.enabled).map((s) => {
            const box = el('div', 'ax-label ax-ed-labelbox', {tabindex: '0', text: s.label || s.packname});
            box.addEventListener('dblclick', () => this.selectForLabel(s.id));
            box.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    this.selectForLabel(s.id);
                }
            });
            return {key: s.id, node: s.node, anchor: s.anchor, pos: s.labelpos, box, draggable: true,
                colour: ['#4f46e5', '#0369a1', '#047857', '#b45309', '#b91c1c', '#be185d', '#7c3aed', '#0f766e'][i++ % 8]};
        });
        this.overlay.set(items);
        // Keep the automatic positions until the teacher moves a box (stored only then).
        if (this.selected) {
            this.highlightSelected();
        }
    }

    /**
     * Re-lays out all boxes in the group and clears stored positions.
     */
    autoArrange() {
        const changed = [];
        this.config.structures.filter((s) => s.top === this.group).forEach((s) => {
            if (s.labelpos) {
                s.labelpos = null;
                changed.push(s.id);
            }
        });
        this.overlay.layout(true);
        if (changed.length) {
            this.queueSave(changed);
        }
    }

    /**
     * Selects a structure for label editing.
     *
     * @param {string} id
     */
    selectForLabel(id) {
        const s = this.structures.get(id);
        this.selected = id;
        if (s.top !== this.group) {
            this.previewGroup(s.top);
        }
        this.rowEls.forEach((r, key) => r.classList.toggle('is-selected', key === id));
        this.highlightSelected();
        const ins = this.inspector;
        ins.replaceChildren();
        const title = el('h4', '', {text: s.packname});
        const meta = el('p', 'ax-ed-meta');
        if (s.latin) {
            meta.appendChild(el('em', '', {text: s.latin}));
        }
        if (s.fma) {
            meta.appendChild(el('span', 'ax-ed-fma', {text: fmt(S.fma, s.fma)}));
        }
        const labelInput = el('input', 'aa-input', {type: 'text', id: 'ax-labeltext', value: s.label || '',
            placeholder: s.packname, maxlength: '255'});
        labelInput.addEventListener('input', () => {
            s.label = labelInput.value;
            const nameBtn = this.rowEls.get(id).querySelector('.ax-ed-name');
            nameBtn.textContent = s.label || s.packname;
            const it = this.overlay.get(id);
            if (it) {
                it.box.textContent = s.label || s.packname;
                this.overlay.update();
            }
            this.queueSave([id]);
        });
        const anchorBtn = button(S.setanchor, 'target', 'aa-btn-ghost aa-btn-sm');
        anchorBtn.addEventListener('click', () => {
            this.anchorMode = !this.anchorMode;
            anchorBtn.classList.toggle('is-on', this.anchorMode);
            this.hintEl.textContent = this.anchorMode ? fmt(S.setanchor_help, s.packname) : S.dragtolabel;
        });
        const resetAnchor = button(S.resetanchor, 'reset', 'aa-btn-ghost aa-btn-sm');
        resetAnchor.addEventListener('click', () => {
            s.anchor = null;
            this.refreshOverlay();
            this.queueSave([id]);
        });
        ins.append(title, meta,
            el('label', 'ax-ed-label', {for: 'ax-labeltext', text: S.labeltext}), labelInput,
            el('p', 'ax-ed-help', {text: S.labeltext_help}),
            el('div', 'ax-ed-btnrow', {children: [anchorBtn, resetAnchor]}));
        this.anchorMode = false;
    }

    /**
     * Highlights the selected structure.
     */
    highlightSelected() {
        if (!this.viewer) {
            return;
        }
        this.viewer.clearStates();
        const s = this.structures.get(this.selected);
        if (s) {
            this.viewer.setState(s.node, 'selected');
        }
        this.overlay.items.forEach((it) => it.box.classList.toggle('is-selected', it.key === this.selected));
    }

    /**
     * Click on the model: set anchor (anchor mode) or select the structure.
     *
     * @param {string|null} name
     * @param {object|null} hit
     */
    onPick(name, hit) {
        if (!name) {
            return;
        }
        const s = this.byNode.get(name);
        if (!s) {
            return;
        }
        if (this.anchorMode && this.selected) {
            const target = this.structures.get(this.selected);
            if (target.node === name && hit) {
                target.anchor = hit.point.map((v) => Math.round(v * 1000) / 1000);
                this.anchorMode = false;
                this.inspector.querySelectorAll('.is-on').forEach((b) => b.classList.remove('is-on'));
                this.hintEl.textContent = S.anchorset;
                this.refreshOverlay();
                this.queueSave([target.id]);
                return;
            }
        }
        this.selectForLabel(s.id);
    }

    /**
     * Enabled-structures counter.
     */
    updateCount() {
        const n = this.config.structures.filter((s) => s.enabled).length;
        this.countEl.textContent = fmt(S.structuresselected, {n, total: this.config.structures.length});
    }

    /**
     * Structure selection changed.
     *
     * @param {string[]} ids
     */
    structuresChanged(ids) {
        this.updateCount();
        this.refreshOverlay();
        this.queueSave(ids);
    }

    /**
     * Debounced autosave of structure settings.
     *
     * @param {string[]} ids
     */
    queueSave(ids) {
        this.dirtyIds = this.dirtyIds || new Set();
        ids.forEach((id) => this.dirtyIds.add(id));
        this.setSaveState('saving');
        window.clearTimeout(this.saveTimer);
        this.saveTimer = window.setTimeout(() => this.flushSave(), 700);
    }

    /**
     * Saves pending structure changes.
     */
    async flushSave() {
        const ids = Array.from(this.dirtyIds || []);
        this.dirtyIds = new Set();
        const structures = ids.map((id) => {
            const s = this.structures.get(id);
            return {
                structureid: id,
                enabled: s.enabled ? 1 : 0,
                label: s.label || '',
                anchor: s.anchor ? s.anchor.join(',') : '',
                labelpos: s.labelpos ? `${s.labelpos.x},${s.labelpos.y}` : '',
            };
        });
        const args = {structures};
        if (this.studytip !== undefined) {
            args.studytip = this.studytip;
        }
        try {
            await this.call('save_structures', args);
            this.setSaveState('saved');
        } catch (e) {
            this.setSaveState('failed');
            Notification.exception(e);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Teaching content                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Status badge.
     *
     * @param {object} s
     * @returns {HTMLElement}
     */
    badge(s) {
        const status = s.draft ? 'draft' : s.status;
        const b = el('span', 'ax-badges', {children: [el('span', 'ax-badge is-' + status, {text: S['status_' + status]})]});
        if (this.needsTranslation(s)) {
            b.appendChild(el('span', 'ax-badge is-lang', {text: (s.contentlang || 'en').toUpperCase(),
                title: S.lang_needstranslation}));
        }
        return b;
    }

    /**
     * Content is in another language than the activity (and no translated draft is waiting).
     *
     * @param {object} s
     * @returns {boolean}
     */
    needsTranslation(s) {
        const lang = this.config.language;
        const base = (c) => (c || 'en').split('_')[0];
        return base(s.contentlang) !== base(lang.code) && !(s.draft && base(s.draftlang) === base(lang.code));
    }

    /**
     * Tab 2.
     */
    buildContentTab() {
        const panel = this.panels.content;
        panel.replaceChildren();
        const enabled = this.config.structures.filter((s) => s.enabled);
        if (!enabled.length) {
            panel.appendChild(el('p', 'aa-note', {text: S.nostructuresenabled}));
            return;
        }
        // Bulk tools.
        const tools = el('div', 'ax-ed-tools');
        if (this.aiReady()) {
            const all = button(S.aifillall, 'sparkle', 'aa-btn-primary aa-btn-sm');
            all.addEventListener('click', () => this.generateAll('content', enabled.map((s) => s.id)));
            tools.appendChild(all);
            const todo = enabled.filter((s) => this.needsTranslation(s));
            if (todo.length) {
                const tr = button(fmt(S.lang_translateall, {n: todo.length, lang: this.config.language.name}), 'globe',
                    'aa-btn-ghost aa-btn-sm');
                tr.addEventListener('click', () => this.translateAll(todo.map((s) => s.id)));
                tools.appendChild(tr);
            }
        }
        panel.appendChild(tools);
        panel.appendChild(el('p', 'ax-ed-warn', {html: icon('info')}));
        panel.lastChild.appendChild(el('span', '', {text: S.aiwarning}));

        const side = el('div', 'ax-ed-side ax-ed-list');
        this.contentItems = new Map();
        enabled.forEach((s) => {
            const b = el('button', 'ax-ed-item', {type: 'button'});
            b.append(el('span', 'ax-ed-item-name', {text: s.label || s.packname}), this.badge(s));
            b.addEventListener('click', () => this.editContent(s.id));
            this.contentItems.set(s.id, b);
            side.appendChild(b);
        });
        this.contentForm = el('div', 'ax-ed-main ax-ed-form');
        this.contentForm.appendChild(el('p', 'aa-note', {text: S.chooseastructure}));
        panel.appendChild(el('div', 'ax-ed-grid', {children: [side, this.contentForm]}));
        this.editContent(this.contentSelected && this.structures.get(this.contentSelected)?.enabled
            ? this.contentSelected : enabled[0].id);
    }

    /**
     * Stores a returned draft locally.
     *
     * @param {object} d
     */
    applyDraft(d) {
        const s = this.structures.get(d.structureid);
        if (!s) {
            return;
        }
        const draft = {};
        this.config.fields.forEach((f) => {
            draft[f] = d[f] || '';
        });
        s.draft = draft;
    }

    /**
     * Content form for one structure.
     *
     * @param {string} id
     */
    editContent(id) {
        const s = this.structures.get(id);
        this.contentSelected = id;
        this.contentItems.forEach((b, key) => b.classList.toggle('is-selected', key === id));
        const form = this.contentForm;
        form.replaceChildren();
        const head = el('div', 'ax-ed-formhead');
        head.append(el('h3', '', {text: s.label || s.packname}), this.badge(s));
        form.appendChild(head);
        if (s.latin) {
            form.appendChild(el('p', 'ax-ed-meta', {children: [el('em', '', {text: s.latin}),
                el('span', 'ax-ed-fma', {text: fmt(S.fma, s.fma)})]}));
        }
        const source = s.draft || s.content;
        if (s.draft) {
            form.appendChild(el('div', 'ax-ed-draftbanner', {html: icon('sparkle')}));
            form.lastChild.appendChild(el('span', '', {text: S.draftbanner}));
            if (s.draftname) {
                form.appendChild(el('p', 'ax-ed-draftname', {text: fmt(S.lang_draftname, s.draftname)}));
            }
        } else if (s.status === 'library') {
            form.appendChild(el('p', 'ax-ed-help', {text: S.librarynote}));
        } else if (s.aigenerated && s.aimodel) {
            form.appendChild(el('p', 'ax-ed-help', {text: fmt(S.aiprovenance, s.aimodel)}));
        }
        const inputs = {};
        this.config.fields.forEach((f) => {
            const row = el('div', 'ax-ed-field' + (s.draft && s.draft[f] !== (s.content[f] || '') ? ' is-changed' : ''));
            row.appendChild(el('label', 'ax-ed-label', {for: 'ax-f-' + f, text: S['field_' + f]}));
            const long = !['latin', 'pronunciation'].includes(f);
            const input = el(long ? 'textarea' : 'input', long ? 'aa-textarea' : 'aa-input', {id: 'ax-f-' + f});
            if (long) {
                input.rows = 2;
            } else {
                input.type = 'text';
            }
            input.value = source[f] || '';
            inputs[f] = input;
            row.appendChild(input);
            form.appendChild(row);
        });
        const values = () => {
            const out = {};
            Object.entries(inputs).forEach(([f, i]) => {
                out[f] = i.value;
            });
            return out;
        };
        const acts = el('div', 'ax-ed-btnrow ax-ed-formacts');
        const run = async(action, btn) => {
            btn.disabled = true;
            try {
                const res = await this.call('save_content', {structureid: id, action, content: values()});
                s.content = res.content;
                s.status = res.status;
                s.aigenerated = res.aigenerated;
                s.draft = res.draft || null;
                if (!s.draft) {
                    s.draftname = '';
                    s.draftlang = '';
                }
                if (res.label !== undefined) {
                    s.label = res.label;
                }
                if (res.contentlang) {
                    s.contentlang = res.contentlang;
                }
                this.setSaveState('saved');
                this.buildContentTab();
            } catch (e) {
                btn.disabled = false;
                Notification.exception(e);
            }
        };
        if (s.draft) {
            const approve = button(S.approve, 'check', 'aa-btn-primary');
            approve.addEventListener('click', () => run('approve', approve));
            const discard = button(S.discarddraft, 'trash', 'aa-btn-ghost');
            discard.addEventListener('click', () => run('discard', discard));
            acts.append(approve, discard);
        } else {
            const save = button(S.save, 'save', 'aa-btn-primary');
            save.addEventListener('click', () => run('save', save));
            acts.appendChild(save);
            if (s.status !== 'library') {
                const reset = button(S.resetlibrary, 'reset', 'aa-btn-ghost');
                reset.addEventListener('click', async() => {
                    if (await this.confirm(S.confirmresetlibrary)) {
                        run('reset', reset);
                    }
                });
                acts.appendChild(reset);
            }
        }
        if (this.aiReady()) {
            const ai = button(S.aifill, 'sparkle', 'aa-btn-ghost');
            ai.addEventListener('click', async() => {
                ai.disabled = true;
                ai.lastChild.textContent = S.aigenerating;
                try {
                    const res = await this.poll('generate', {what: 'content', structureids: [id]}, () => {
                        ai.lastChild.textContent = S.ai_pending;
                    });
                    res.content.forEach((d) => this.applyDraft(d));
                    this.buildContentTab();
                } catch (e) {
                    ai.disabled = false;
                    ai.lastChild.textContent = S.aifill;
                    this.fail(e);
                }
            });
            acts.appendChild(ai);
            if (this.needsTranslation(s)) {
                const tr = button(fmt(S.lang_translate, this.config.language.name), 'globe', 'aa-btn-ghost');
                tr.addEventListener('click', async() => {
                    tr.disabled = true;
                    tr.lastChild.textContent = S.lang_translating;
                    try {
                        await this.translateOne(id);
                        this.buildContentTab();
                    } catch (e) {
                        tr.disabled = false;
                        tr.lastChild.textContent = fmt(S.lang_translate, this.config.language.name);
                        Notification.exception(e);
                    }
                });
                acts.appendChild(tr);
            }
        }
        form.appendChild(acts);
    }

    /**
     * Translates one structure (content draft, name and draft questions).
     *
     * @param {string} id
     */
    async translateOne(id) {
        const res = await this.poll('translate', {what: 'structure', structureid: id});
        const s = this.structures.get(id);
        if (res.draft) {
            this.applyDraft({structureid: id, ...res.draft});
            s.draftname = res.name || '';
            s.draftlang = this.config.language.code;
        }
        res.questions.forEach((q) => this.questions.push(q));
    }

    /**
     * Translates several structures (and the group names), one request at a time.
     *
     * @param {string[]} ids
     */
    async translateAll(ids) {
        const panel = this.panels[this.tab];
        const progress = el('div', 'ax-ed-progress', {role: 'status'});
        const bar = el('div', 'aa-bar', {children: [el('span')]});
        const label = el('span', '', {text: S.lang_translating});
        progress.append(label, bar);
        panel.prepend(progress);
        panel.querySelectorAll('button').forEach((b) => {
            b.disabled = true;
        });
        let done = 0;
        let failed = 0;
        const total = ids.length + (this.config.grouptranslated ? 0 : 1);
        if (!this.config.grouptranslated) {
            label.textContent = S.lang_translategroups;
            try {
                await this.translateGroups();
            } catch (e) {
                failed++;
                Notification.exception(e);
            }
            done++;
        }
        for (const id of ids) {
            if (failed >= 3) {
                break;
            }
            const s = this.structures.get(id);
            label.textContent = fmt(S.lang_translatingx, {n: done + 1, total, name: s.label || s.packname});
            try {
                await this.translateOne(id);
            } catch (e) {
                failed++;
                if (failed === 1) {
                    Notification.exception(e);
                }
            }
            done++;
            bar.firstChild.style.width = `${Math.round(done / total * 100)}%`;
        }
        Notification.addNotification({message: fmt(S.lang_translated, {n: done - failed, total}), type: 'info'});
        this.showTab(this.tab);
    }

    /**
     * Translates the group names and tips.
     */
    async translateGroups() {
        const res = await this.poll('translate', {what: 'groups'});
        const map = new Map(res.groups.map((g) => [g.id, g]));
        this.config.groups.forEach((g) => {
            const t = map.get(g.id);
            if (t) {
                g.label = t.label || g.packlabel;
                g.tip = t.tip || g.packtip;
            }
        });
        this.config.grouptranslated = true;
    }

    /* ------------------------------------------------------------------ */
    /* Language and voice                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Tab 4.
     */
    buildLanguageTab() {
        const panel = this.panels.language;
        panel.replaceChildren();
        const lang = this.config.language;
        const box = el('div', 'ax-ed-box');
        box.appendChild(el('h3', '', {text: S.tab_language}));
        box.appendChild(el('p', '', {text: fmt(S.lang_intro, lang.name)}));
        if (!lang.installed && lang.code !== 'en') {
            box.appendChild(el('p', 'ax-ed-warn', {text: fmt(S.lang_notinstalled, lang.name)}));
        }
        const enabled = this.config.structures.filter((s) => s.enabled);
        if (lang.english) {
            box.appendChild(el('p', 'ax-ed-help', {text: S.lang_english}));
        } else {
            const todo = enabled.filter((s) => this.needsTranslation(s));
            const base = lang.code.split('_')[0];
            const qin = this.questions.filter((q) => (q.lang || 'en').split('_')[0] === base).length;
            box.appendChild(el('p', '', {text: fmt(S.lang_progress, {done: enabled.length - todo.length,
                total: enabled.length, questions: qin, lang: lang.name})}));
            if (this.aiReady()) {
                const row = el('div', 'ax-ed-btnrow');
                if (todo.length) {
                    const all = button(fmt(S.lang_translateall, {n: todo.length, lang: lang.name}), 'globe',
                        'aa-btn-primary');
                    all.addEventListener('click', () => this.translateAll(todo.map((s) => s.id)));
                    row.appendChild(all);
                }
                const gr = button(S.lang_translategroups, 'globe', 'aa-btn-ghost');
                gr.addEventListener('click', async() => {
                    gr.disabled = true;
                    try {
                        await this.translateGroups();
                        this.buildLanguageTab();
                    } catch (e) {
                        gr.disabled = false;
                        Notification.exception(e);
                    }
                });
                row.appendChild(gr);
                box.appendChild(row);
            }
        }
        panel.appendChild(box);

        // Group names and tips (the activity's own text; empty = the pack's English text).
        const gbox = el('div', 'ax-ed-box');
        gbox.appendChild(el('h3', '', {text: S.lang_groups}));
        gbox.appendChild(el('p', 'ax-ed-help', {text: S.lang_groups_help}));
        const inputs = [];
        this.config.groups.forEach((g) => {
            const row = el('div', 'ax-ed-grouprow');
            const uid = 'g-' + g.id;
            const label = el('input', 'aa-input', {type: 'text', id: uid, placeholder: g.packlabel,
                'aria-label': fmt(S.lang_grouplabel, g.packlabel)});
            label.value = g.label !== g.packlabel ? g.label : '';
            const tip = el('textarea', 'aa-textarea', {rows: '2', placeholder: g.packtip || '',
                'aria-label': fmt(S.lang_grouptip, g.packlabel)});
            tip.value = g.tip !== g.packtip ? g.tip : '';
            if (g.id.startsWith('_preset_')) {
                tip.hidden = true;
            }
            row.append(el('label', 'ax-ed-label', {for: uid, text: g.packlabel}), label, tip);
            inputs.push({g, label, tip});
            gbox.appendChild(row);
        });
        const save = button(S.lang_savegroups, 'save', 'aa-btn-primary aa-btn-sm');
        save.addEventListener('click', async() => {
            save.disabled = true;
            try {
                await this.call('save_groups', {groups: inputs.map(({g, label, tip}) => ({id: g.id,
                    label: label.value.trim(), tip: tip.value.trim()}))});
                inputs.forEach(({g, label, tip}) => {
                    g.label = label.value.trim() || g.packlabel;
                    g.tip = tip.value.trim() || g.packtip;
                });
                this.setSaveState('saved');
            } catch (e) {
                Notification.exception(e);
            }
            save.disabled = false;
        });
        gbox.appendChild(el('div', 'ax-ed-btnrow', {children: [save]}));
        panel.appendChild(gbox);

        // Voiceover.
        const v = this.config.voice;
        const vbox = el('div', 'ax-ed-box');
        vbox.appendChild(el('h3', '', {text: S.voice_heading}));
        if (!v.configured) {
            vbox.appendChild(el('p', 'ax-ed-warn', {text: S.voice_notconfigured_editor}));
        } else if (!v.activity) {
            vbox.appendChild(el('p', 'ax-ed-help', {text: S.voice_off_activity}));
        } else {
            vbox.appendChild(el('p', '', {text: fmt(S.voice_summary, {voice: v.effective, locale: v.locale,
                n: v.items.length})}));
            vbox.appendChild(el('p', 'ax-ed-help', {text: fmt(S.voice_available, {locale: v.locale,
                voices: v.available.join(', ')})}));
            if (v.effective !== v.voicename) {
                vbox.appendChild(el('p', 'ax-ed-warn', {text: fmt(S.voice_substituted, {chosen: v.voicename,
                    used: v.effective})}));
            }
            const stats = el('p', 'ax-ed-help', {text: fmt(S.voice_stats, v.stats)});
            vbox.appendChild(stats);
            vbox.appendChild(el('p', 'ax-ed-help', {text: S.voice_credits_note}));
            if (v.failed) {
                vbox.appendChild(el('p', 'ax-ed-warn', {text: fmt(S.voice_failed, v.failed)}));
            }
            const row = el('div', 'ax-ed-btnrow');
            if (v.items.length) {
                const preview = button(S.voice_preview, 'voice', 'aa-btn-ghost');
                preview.addEventListener('click', async() => {
                    preview.disabled = true;
                    try {
                        const r = await this.poll('speak', {text: v.items[0].text, sig: v.items[0].sig,
                            speed: v.items[0].speed});
                        for (const url of r.clips) {
                            await new Promise((resolve) => {
                                const a = new Audio(url);
                                a.onended = a.onerror = resolve;
                                a.play().catch(resolve);
                            });
                        }
                    } catch (e) {
                        Notification.exception(e);
                    }
                    preview.disabled = false;
                });
                const gen = button(fmt(S.voice_generateall, v.items.length), 'sparkle', 'aa-btn-primary');
                gen.addEventListener('click', () => this.generateVoice(vbox));
                row.append(gen, preview);
            }
            const clear = button(S.voice_clear, 'trash', 'aa-btn-ghost');
            clear.addEventListener('click', async() => {
                if (!(await this.confirm(S.voice_confirmclear))) {
                    return;
                }
                try {
                    v.stats = await this.call('voice_clear', {});
                    this.buildLanguageTab();
                } catch (e) {
                    Notification.exception(e);
                }
            });
            row.appendChild(clear);
            vbox.appendChild(row);
        }
        panel.appendChild(vbox);
    }

    /**
     * Generates every voiceover clip now (one text per request), so students never wait.
     *
     * @param {HTMLElement} box
     */
    async generateVoice(box) {
        const v = this.config.voice;
        const progress = el('div', 'ax-ed-progress', {role: 'status'});
        const bar = el('div', 'aa-bar', {children: [el('span')]});
        const label = el('span', '', {text: S.voice_generating});
        progress.append(label, bar);
        box.prepend(progress);
        box.querySelectorAll('button').forEach((b) => {
            b.disabled = true;
        });
        let done = 0;
        let made = 0;
        let failed = 0;
        let balance = '';
        let credits = 0;
        for (const item of v.items) {
            label.textContent = fmt(S.voice_generatingx, {n: done + 1, total: v.items.length});
            try {
                const r = await this.poll('speak', {text: item.text, sig: item.sig, speed: item.speed, prefetch: true});
                made += r.generated;
                credits += r.credits || 0;
                balance = r.balance || balance;
            } catch (e) {
                failed++;
                if (failed === 1) {
                    Notification.exception(e);
                }
                if (failed >= 3) {
                    break;
                }
            }
            done++;
            bar.firstChild.style.width = `${Math.round(done / v.items.length * 100)}%`;
        }
        let msg = fmt(S.voice_generated, {n: made, total: v.items.length});
        if (balance) {
            msg += ' ' + fmt(S.voice_balance, balance);
        }
        Notification.addNotification({message: msg, type: 'info'});
        v.stats.clips += made;
        v.stats.credits = Math.round((v.stats.credits + credits) * 100) / 100;
        this.buildLanguageTab();
    }

    /**
     * Generates AI drafts for several structures, one request at a time.
     *
     * @param {string} what content|questions
     * @param {string[]} ids
     */
    async generateAll(what, ids) {
        const panel = this.panels[this.tab];
        const progress = el('div', 'ax-ed-progress', {role: 'status'});
        const bar = el('div', 'aa-bar', {children: [el('span')]});
        const label = el('span', '', {text: S.aigenerating});
        progress.append(label, bar);
        panel.prepend(progress);
        panel.querySelectorAll('button').forEach((b) => {
            b.disabled = true;
        });
        let done = 0;
        let failed = 0;
        for (const id of ids) {
            const s = this.structures.get(id);
            label.textContent = fmt(S.aigeneratingx, {n: done + 1, total: ids.length, name: s.label || s.packname});
            try {
                const res = await this.poll('generate', {what, structureids: [id]});
                res.content.forEach((d) => this.applyDraft(d));
                res.questions.forEach((q) => this.questions.push(q));
            } catch (e) {
                failed++;
                if (failed === 1) {
                    this.fail(e);
                }
                if (failed >= 3) {
                    break;
                }
            }
            done++;
            bar.firstChild.style.width = `${Math.round(done / ids.length * 100)}%`;
        }
        Notification.addNotification({message: fmt(S.aidone, {n: done - failed, total: ids.length}), type: 'info'});
        if (what === 'content') {
            this.buildContentTab();
        } else {
            this.buildQuestionsTab();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Questions                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Tab 3.
     */
    buildQuestionsTab() {
        const panel = this.panels.questions;
        panel.replaceChildren();
        const enabled = this.config.structures.filter((s) => s.enabled);
        if (!enabled.length) {
            panel.appendChild(el('p', 'aa-note', {text: S.nostructuresenabled}));
            return;
        }
        const tools = el('div', 'ax-ed-tools');
        const filter = el('select', 'custom-select form-select', {'aria-label': S.chooseastructure});
        filter.appendChild(el('option', '', {value: '', text: S.allstructures}));
        enabled.forEach((s) => filter.appendChild(el('option', '', {value: s.id, text: s.label || s.packname})));
        filter.value = this.qfilter || '';
        filter.addEventListener('change', () => {
            this.qfilter = filter.value;
            this.buildQuestionsTab();
        });
        tools.appendChild(filter);
        const add = button(S.addquestion, 'plus', 'aa-btn-ghost aa-btn-sm');
        add.addEventListener('click', () => this.editQuestion(null, list));
        tools.appendChild(add);
        if (this.aiReady()) {
            const gen = button(this.qfilter ? S.aiquestions : S.aiquestionsall, 'sparkle', 'aa-btn-primary aa-btn-sm');
            gen.addEventListener('click', () => this.generateAll('questions', this.qfilter ? [this.qfilter] :
                enabled.map((s) => s.id)));
            tools.appendChild(gen);
        }
        panel.appendChild(tools);
        panel.appendChild(el('p', 'ax-ed-warn', {html: icon('info')}));
        panel.lastChild.appendChild(el('span', '', {text: S.aiwarning}));

        const enabledIds = new Set(enabled.map((s) => s.id));
        const qs = this.questions.filter((q) => enabledIds.has(q.structureid) && (!this.qfilter ||
            q.structureid === this.qfilter));
        const list = el('div', 'ax-ed-qlist');
        if (!qs.length) {
            list.appendChild(el('p', 'aa-note', {text: S.noquestions}));
        }
        qs.forEach((q) => list.appendChild(this.questionCard(q, list)));
        panel.appendChild(list);
    }

    /**
     * One question card.
     *
     * @param {object} q
     * @param {HTMLElement} list
     * @returns {HTMLElement}
     */
    questionCard(q, list) {
        const s = this.structures.get(q.structureid);
        const card = el('div', 'ax-ed-q is-' + q.status);
        const head = el('div', 'ax-ed-qhead');
        head.append(el('span', 'ax-ed-qstruct', {text: s ? (s.label || s.packname) : q.structureid}),
            el('span', 'ax-ed-qkind', {text: S['kind_' + q.kind] || q.kind}),
            el('span', 'ax-badge is-' + q.status, {text: S['status_' + q.status]}));
        const base = (c) => (c || 'en').split('_')[0];
        if (base(q.lang) !== base(this.config.language.code)) {
            head.appendChild(el('span', 'ax-badge is-lang', {text: (q.lang || 'en').toUpperCase(),
                title: S.lang_othersunused}));
        }
        card.appendChild(head);
        card.appendChild(el('p', 'ax-ed-qtext', {text: q.text}));
        const ol = el('ol', 'ax-ed-qopts');
        q.options.forEach((o, i) => ol.appendChild(el('li', i === q.answer ? 'is-answer' : '', {text: o})));
        card.appendChild(ol);
        if (q.explanation) {
            card.appendChild(el('p', 'ax-ed-qexp', {text: q.explanation}));
        }
        const acts = el('div', 'ax-ed-btnrow');
        if (q.status === 'draft') {
            const approve = button(S.approve, 'check', 'aa-btn-primary aa-btn-sm');
            approve.addEventListener('click', () => this.saveQuestion({...q, status: 'approved'}));
            acts.appendChild(approve);
        }
        const edit = button(S.edit, 'edit', 'aa-btn-ghost aa-btn-sm');
        edit.addEventListener('click', () => this.editQuestion(q, list, card));
        const del = button(S.delete, 'trash', 'aa-btn-danger aa-btn-sm');
        del.addEventListener('click', async() => {
            if (!(await this.confirm(S.confirmdeletequestion))) {
                return;
            }
            try {
                await this.call('save_question', {action: 'delete', question: {id: q.id}});
                this.questions = this.questions.filter((x) => x.id !== q.id);
                this.buildQuestionsTab();
            } catch (e) {
                Notification.exception(e);
            }
        });
        acts.append(edit, del);
        card.appendChild(acts);
        return card;
    }

    /**
     * Question form (new or edit).
     *
     * @param {object|null} q
     * @param {HTMLElement} list
     * @param {HTMLElement|null} card
     */
    editQuestion(q, list, card = null) {
        const enabled = this.config.structures.filter((s) => s.enabled);
        const data = q ? {...q, options: q.options.slice()} : {id: 0, structureid: this.qfilter || enabled[0].id,
            kind: 'function', text: '', options: ['', '', '', ''], answer: 0, explanation: '', status: 'approved'};
        const form = el('div', 'ax-ed-q is-editing');
        const uid = 'q' + Math.random().toString(36).slice(2, 8);
        const struct = el('select', 'custom-select form-select', {id: uid + 's'});
        enabled.forEach((s) => struct.appendChild(el('option', '', {value: s.id, text: s.label || s.packname})));
        struct.value = data.structureid;
        const kind = el('select', 'custom-select form-select', {id: uid + 'k'});
        ['function', 'location', 'relationship', 'terminology', 'clinical'].forEach((k) =>
            kind.appendChild(el('option', '', {value: k, text: S['kind_' + k]})));
        kind.value = data.kind;
        const text = el('textarea', 'aa-textarea', {id: uid + 't', rows: '2'});
        text.value = data.text;
        form.append(el('div', 'ax-ed-btnrow', {children: [struct, kind]}),
            el('label', 'ax-ed-label', {for: uid + 't', text: S.question}), text,
            el('p', 'ax-ed-label', {text: S.options}));
        const optInputs = data.options.map((o, i) => {
            const row = el('div', 'ax-ed-optrow');
            const radio = el('input', '', {type: 'radio', name: uid + 'a', id: `${uid}r${i}`, 'aria-label': S.correctoption});
            radio.checked = i === data.answer;
            const input = el('input', 'aa-input', {type: 'text', value: o, 'aria-label': fmt(S.option, i + 1)});
            row.append(radio, input);
            form.appendChild(row);
            return {radio, input};
        });
        const exp = el('textarea', 'aa-textarea', {id: uid + 'e', rows: '2'});
        exp.value = data.explanation;
        form.append(el('label', 'ax-ed-label', {for: uid + 'e', text: S.explanation}), exp);
        const acts = el('div', 'ax-ed-btnrow');
        const save = button(S.save, 'save', 'aa-btn-primary aa-btn-sm');
        save.addEventListener('click', () => this.saveQuestion({
            id: data.id, structureid: struct.value, kind: kind.value, text: text.value,
            options: optInputs.map((o) => o.input.value), answer: Math.max(0, optInputs.findIndex((o) => o.radio.checked)),
            explanation: exp.value, status: data.status === 'draft' ? 'approved' : data.status,
        }));
        const cancel = button(S.cancel, 'cross', 'aa-btn-ghost aa-btn-sm');
        cancel.addEventListener('click', () => this.buildQuestionsTab());
        acts.append(save, cancel);
        form.appendChild(acts);
        if (card) {
            card.replaceWith(form);
        } else {
            list.prepend(form);
        }
        text.focus();
    }

    /**
     * Saves a question.
     *
     * @param {object} q
     */
    async saveQuestion(q) {
        try {
            const res = await this.call('save_question', {action: 'save', question: {
                id: q.id || 0, structureid: q.structureid, kind: q.kind, text: q.text, options: q.options,
                answer: q.answer, explanation: q.explanation || '', status: q.status,
            }});
            const saved = res.question;
            const idx = this.questions.findIndex((x) => x.id === saved.id);
            if (idx >= 0) {
                this.questions[idx] = saved;
            } else {
                this.questions.push(saved);
            }
            this.setSaveState('saved');
            this.buildQuestionsTab();
        } catch (e) {
            Notification.exception(e);
        }
    }

    /**
     * Confirm dialog.
     *
     * @param {string} message
     * @returns {Promise<boolean>}
     */
    confirm(message) {
        return new Promise((resolve) => {
            const overlay = el('div', 'aa-overlay ax-ed-overlay');
            const box = el('div', 'aa-dialog', {role: 'alertdialog', 'aria-modal': 'true'});
            box.appendChild(el('p', '', {text: message}));
            const no = el('button', 'aa-btn aa-btn-ghost', {type: 'button', text: S.cancel});
            const yes = el('button', 'aa-btn aa-btn-primary', {type: 'button', text: S.confirm});
            box.appendChild(el('div', 'aa-dialog-actions', {children: [no, yes]}));
            overlay.appendChild(box);
            this.root.appendChild(overlay);
            yes.focus();
            const done = (v) => {
                overlay.remove();
                resolve(v);
            };
            no.addEventListener('click', () => done(false));
            yes.addEventListener('click', () => done(true));
        });
    }
}

/**
 * Initialises the editor.
 *
 * @param {string} selector
 */
export const init = async(selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    S = await loadStrings(STRING_KEYS);
    root.aaEditor = new Editor(root, JSON.parse(root.dataset.config));
};
