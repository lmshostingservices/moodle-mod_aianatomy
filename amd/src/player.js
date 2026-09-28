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
 * AI Anatomy player: Study (drill-down explorer), Practice (label it, find it, quiz with hints) and
 * Test (label it and quiz, marked on the server) on a verified 3D anatomy model.
 *
 * Labels can be dragged (mouse, pen, touch) or placed with tap-tap / keyboard (WCAG 2.5.7).
 *
 * @module     mod_aianatomy/player
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import * as Sound from 'mod_aianatomy/sound';
import Viewer, {supported} from 'mod_aianatomy/viewer';
import Overlay from 'mod_aianatomy/overlay';
import Voice from 'mod_aianatomy/voice';
import {loadStrings, fmt, el, icon, iconButton, button, clock, shuffle, confetti, burst, speak, COARSE,
    REDUCED} from 'mod_aianatomy/ui';

const STRING_KEYS = [
    'exit', 'mute', 'unmute', 'fullscreen', 'exitfullscreen', 'loading', 'nowebgl', 'modestudy', 'modepractice',
    'modetest', 'resetview', 'home', 'explode', 'labels', 'isolate', 'showincontext', 'showall', 'hide', 'close',
    'pronounce', 'field_latin', 'field_pronunciation', 'field_origin', 'field_location', 'field_description',
    'field_function', 'field_mnemonic', 'field_clinical', 'field_hint', 'field_relationships', 'nottested',
    'rel_articulates_with', 'rel_adjacent_to', 'rel_continuous_with', 'rel_supplies', 'rel_drains_to', 'rel_works_with',
    'rel_opposes', 'rel_attaches_to', 'rel_part_of', 'rel_contains', 'rel_connects',
    'voiceon', 'voiceoff', 'listen', 'stoplistening', 'listenquestion', 'listenprompt', 'voiceerror_short',
    'searchstructures', 'nomatches', 'studytip', 'clicktoexplore', 'clicktostudy', 'views', 'roundof', 'labelit',
    'findit', 'quiz', 'hint', 'showanswers', 'reset', 'submitround', 'next', 'previous', 'finish', 'labeltray',
    'instructions_drag', 'instructions_tap', 'correctfeedback', 'wrongfeedback', 'placedfeedback', 'selectedfeedback',
    'slotempty', 'slotfilled', 'returntotray', 'allplaced', 'roundcomplete', 'findprompt', 'findwrong', 'findcorrect',
    'showme', 'skip', 'questionof', 'submitanswers', 'correctanswer', 'incorrectanswer', 'explanation',
    'confirmsubmitround', 'confirmsubmitquiz', 'confirmexit', 'confirm', 'cancel', 'timeup', 'time', 'timeleft',
    'score', 'resultstitle', 'reviewanswers', 'tryagain', 'backtomenu', 'identification', 'questions', 'passed',
    'notpassed', 'passmark', 'excellent', 'greatjob', 'goodeffort', 'keeppractising', 'yourlabel', 'nolabel',
    'mastered', 'learning', 'difficult', 'notyet', 'practiseweak', 'difficultlist', 'intro_title_study',
    'intro_title_practice', 'intro_title_test', 'intro_sub_study', 'intro_sub_practice', 'intro_sub_test',
    'intro_pass', 'intro_nopass', 'intro_attempts', 'intro_unlimited', 'intro_time', 'intro_notime',
    'intro_content', 'intro_graded', 'intro_feedback_practice', 'intro_feedback_test', 'intro_practice_nograde',
    'intro_study_nopressure', 'intro_study_next', 'intro_requirements', 'intro_expect', 'intro_howto',
    'intro_howto_study', 'intro_howto_practice', 'intro_howto_test', 'intro_go', 'intro_back', 'intro_weights',
    'intro_weak', 'firsttryscore', 'attribution', 'hintfor', 'hintpick', 'nexttask', 'yourchoice', 'close',
];

let S = {};

/**
 * The player application.
 */
class Player {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root
     * @param {object} config
     */
    constructor(root, config) {
        this.root = root;
        this.config = config;
        this.home = root.querySelector('[data-region="home"]');
        this.host = root.querySelector('[data-region="player"]');
        this.live = root.querySelector('[data-region="live"]');
        this.viewer = null;
        Sound.setAllowed(!!config.sounds);
        this.voice = new Voice(config.cmid, config.voice, () => {
            if (this.status) {
                this.setStatus(S.voiceerror_short, 'bad');
            }
        });
        this.voice.onChange(() => this.syncVoice());
        root.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action="start"]');
            if (btn && !btn.disabled && this.home.contains(btn)) {
                this.showIntro(btn.dataset.mode, btn.dataset.focus || 'all').catch(Notification.exception);
            }
        });
        document.addEventListener('fullscreenchange', () => this.syncFullscreen());
        document.addEventListener('webkitfullscreenchange', () => this.syncFullscreen());
        document.addEventListener('keydown', (e) => this.onKey(e));
        window.addEventListener('resize', () => {
            this.fitCard();
        });
    }

    /**
     * Announces a message to assistive technologies.
     *
     * @param {string} msg
     */
    say(msg) {
        this.live.textContent = '';
        window.setTimeout(() => {
            this.live.textContent = msg;
        }, 30);
    }

    /* ------------------------------------------------------------------ */
    /* Intro                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Shows the start screen of a mode.
     *
     * @param {string} mode study|practice|test
     * @param {string} focus all|weak
     */
    async showIntro(mode, focus = 'all') {
        Sound.play('select');
        const c = this.config;
        const items = [];
        const li = (ic, text, key = false) => ({icon: icon(ic), text, key});
        const rules = c.completion || [];
        const content = fmt(S.intro_content, {structures: c.targetcount, questions: c.quizcount});
        if (mode === 'test') {
            items.push(c.passpercent > 0 ? li('target', fmt(S.intro_pass, c.passpercent), true) : li('target', S.intro_nopass));
            items.push(li('repeat', c.maxattempts ? fmt(S.intro_attempts, {left: c.attemptsleft, max: c.maxattempts}) :
                S.intro_unlimited));
            items.push(li('clock', c.timelimit ? fmt(S.intro_time, c.timelimittext) : S.intro_notime));
            items.push(li('bone', content));
            if (c.quizcount > 0) {
                items.push(li('star', fmt(S.intro_weights, {ident: c.identweight, quiz: 100 - c.identweight})));
            }
            if (c.graded) {
                items.push(li('trophy', S.intro_graded));
            }
            items.push(li('info', S.intro_feedback_test));
            rules.filter((r) => r.rule !== 'completionpassgrade' || !c.passpercent)
                .forEach((r) => items.push(li('flag', r.text)));
        } else if (mode === 'practice') {
            if (focus === 'weak') {
                items.push(li('target', S.intro_weak, true));
            }
            items.push(li('info', S.intro_feedback_practice));
            items.push(li('bone', content));
            items.push(li('star', S.intro_practice_nograde));
        } else {
            items.push(li('cube', S.intro_study_nopressure));
            if (c.allowtest || rules.length) {
                items.push(li('flag', S.intro_study_next));
            }
        }
        const context = {
            mode,
            badgeicon: icon({study: 'book', practice: 'star', test: 'check'}[mode]),
            modename: S['mode' + mode],
            title: S['intro_title_' + mode],
            sub: S['intro_sub_' + mode],
            heading: mode === 'test' ? S.intro_requirements : S.intro_expect,
            items,
            howtoheading: S.intro_howto,
            study: mode === 'study',
            handicon: icon('hand'),
            howto: S['intro_howto_' + mode],
            back: {label: S.intro_back, icon: icon('prev')},
            go: {label: S.intro_go, icon: icon('next')},
        };
        const seq = (this.introSeq = (this.introSeq || 0) + 1);
        const {html, js} = await Templates.renderForPromise('mod_aianatomy/player_intro', context);
        if (seq !== this.introSeq) {
            return;
        }
        this.home.hidden = true;
        this.host.hidden = false;
        this.teardown();
        this.host.replaceChildren();
        const shell = el('div', `aa-shell aa-intro-shell aa-mode-${mode}`);
        Templates.appendNodeContents(shell, html, js);
        shell.querySelector('[data-action="back"]').addEventListener('click', () => this.exit());
        const go = shell.querySelector('[data-action="go"]');
        go.addEventListener('click', () => {
            go.disabled = true;
            this.start(mode, focus).catch(Notification.exception);
        });
        this.host.appendChild(shell);
        this.shell = shell;
        go.focus({preventScroll: true});
        this.scrollToShell();
    }

    /**
     * Starts a mode.
     *
     * @param {string} mode
     * @param {string} focus
     */
    async start(mode, focus) {
        Sound.play('select');
        this.mode = mode;
        let data = null;
        if (mode !== 'study') {
            try {
                data = await Ajax.call([{methodname: 'mod_aianatomy_start_attempt',
                    args: {cmid: this.config.cmid, mode, focus}}])[0];
            } catch (err) {
                this.exit();
                Notification.exception(err);
                return;
            }
        }
        this.data = data;
        this.attemptid = data ? data.attemptid : 0;
        await this.buildShell();
        if (!this.viewer) {
            return;
        }
        if (mode === 'study') {
            this.startStudy();
        } else {
            this.results = {label: {}, find: {}, quiz: {}};
            this.unsent = [];
            this.firstTry = 0;
            this.totalItems = data.rounds.reduce((n, r) => n + r.pins.length * (mode === 'practice' ? 2 : 1), 0) +
                data.questions.length;
            this.startedAt = Date.now();
            this.startTimer();
            this.updateScore();
            this.runRound(0);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Shell and scene                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Builds the player shell and loads the 3D model.
     */
    async buildShell() {
        const mode = this.mode;
        this.teardown();
        this.host.replaceChildren();
        const shell = el('div', `aa-shell ax-shell aa-mode-${mode}`, {tabindex: '-1'});
        this.shell = shell;

        // Top bar.
        const exitBtn = iconButton('exit', S.exit);
        exitBtn.addEventListener('click', () => this.requestExit());
        this.titleEl = el('span', 'aa-slidename', {text: this.config.name});
        this.counterEl = el('span', 'aa-counter');
        const titlewrap = el('div', 'aa-titlewrap', {children: [
            el('span', `aa-modetag aa-modetag-${mode}`, {text: S['mode' + mode]}),
            el('div', 'aa-slidetitle', {children: [this.titleEl, this.counterEl]}),
        ]});
        const tools = el('div', 'aa-tools');
        if (mode !== 'study') {
            this.scoreEl = el('div', 'aa-score', {'aria-live': 'off'});
            this.timerEl = el('div', 'aa-timer', {html: icon('clock')});
            this.timerEl.appendChild(el('span', '', {text: '0:00'}));
            tools.append(this.scoreEl, this.timerEl);
        }
        this.muteBtn = iconButton('sound', S.mute);
        this.muteBtn.addEventListener('click', () => {
            Sound.toggleMute();
            this.syncMute();
        });
        this.fullBtn = iconButton('full', S.fullscreen);
        this.fullBtn.addEventListener('click', () => this.toggleFullscreen());
        if (this.config.sounds) {
            tools.append(this.muteBtn);
        }
        this.voiceBtn = null;
        if (this.voice.available) {
            this.voiceBtn = iconButton('voice', S.voiceoff);
            this.voiceBtn.classList.add('ax-voicebtn');
            this.voiceBtn.addEventListener('click', () => this.voice.setOff(!this.voice.off));
            tools.append(this.voiceBtn);
        }
        tools.append(this.fullBtn);
        shell.appendChild(el('div', 'aa-topbar', {children: [exitBtn, titlewrap, tools]}));

        // Stage: side panel + 3D view.
        this.side = el('div', 'ax-side');
        this.view = el('div', 'ax-view');
        this.stage = el('div', 'ax-stage', {children: [this.side, this.view]});
        shell.appendChild(this.stage);
        this.crumbs = el('nav', 'ax-crumbs', {'aria-label': S.views});
        this.toolbar = el('div', 'ax-toolbar');
        this.status = el('div', 'ax-status', {'aria-live': 'polite'});
        this.view.append(this.crumbs, this.toolbar, this.status);

        // Actions.
        this.actions = el('div', 'aa-actions');
        shell.appendChild(this.actions);
        const pack = this.config.pack;
        if (pack.attribution) {
            shell.appendChild(el('p', 'ax-attribution', {text: fmt(S.attribution, {source: pack.attribution,
                license: pack.license})}));
        }
        this.host.appendChild(shell);
        this.syncMute();
        this.syncVoice();
        this.scrollToShell();

        if (!supported()) {
            this.view.appendChild(el('div', 'ax-nowebgl', {text: S.nowebgl}));
            this.viewer = null;
            return;
        }
        const loading = el('div', 'ax-loading', {children: [el('span', 'ax-spinner'), el('span', '', {text: S.loading})]});
        this.view.appendChild(loading);
        this.viewer = new Viewer(this.view, {modelUrl: pack.model, presets: pack.presets, defaultPreset: pack.defaultpreset});
        this.overlay = new Overlay(this.view, this.viewer);
        try {
            await this.viewer.load();
        } catch (e) {
            loading.remove();
            this.view.appendChild(el('div', 'ax-nowebgl', {text: S.nowebgl}));
            this.viewer.dispose();
            this.viewer = null;
            return;
        }
        loading.remove();
        this.viewer.on('select', (name, hit, ev) => this.onSelect(name, hit, ev));
        this.viewer.on('hover', (name) => this.onHover(name));
        this.buildToolbar();
    }

    /**
     * Toolbar over the 3D view: home, camera presets, explode, labels.
     */
    buildToolbar() {
        const pack = this.config.pack;
        const bar = this.toolbar;
        bar.replaceChildren();
        const reset = iconButton('home', S.resetview, 'ax-tool');
        reset.addEventListener('click', () => this.resetView());
        bar.appendChild(reset);
        const presets = el('div', 'ax-presets', {role: 'group', 'aria-label': S.views});
        pack.presets.forEach((p) => {
            const b = el('button', 'ax-preset', {type: 'button', text: p.label.replace(/\s*\(.*\)$/, ''), title: p.label});
            b.addEventListener('click', () => {
                this.currentPreset = p.id;
                this.viewer.preset(p.id, this.focusNodes || this.viewer.names());
                Sound.play('select');
            });
            presets.appendChild(b);
        });
        bar.appendChild(presets);
        const explode = el('label', 'ax-explode', {title: S.explode});
        explode.appendChild(el('span', 'ax-explode-ic', {html: icon('explode')}));
        explode.appendChild(el('span', 'visually-hidden sr-only', {text: S.explode}));
        this.explodeInput = el('input', '', {type: 'range', min: '0', max: '150', step: '5',
            value: String(Math.round(pack.explode * 100)), 'aria-label': S.explode});
        this.explodeInput.addEventListener('input', () => {
            this.explodeAmount = Number(this.explodeInput.value) / 100;
            if (this.focusNodes) {
                this.viewer.explode(this.focusNodes, this.explodeAmount, false, this.viewer.viewDir(),
                    pack.explodedirs || {});
            }
        });
        explode.appendChild(this.explodeInput);
        bar.appendChild(explode);
        if (this.mode === 'study') {
            this.labelsBtn = iconButton('labels', S.labels, 'ax-tool');
            this.labelsBtn.setAttribute('aria-pressed', 'false');
            this.labelsBtn.addEventListener('click', () => this.toggleLabels());
            bar.appendChild(this.labelsBtn);
        }
        this.explodeAmount = pack.explode;
    }

    /**
     * Shows a set of structures as the focus of the scene, with the rest shown per the teacher's
     * context setting, exploded and framed.
     *
     * @param {string[]} nodes
     * @param {boolean} animate
     * @returns {Promise}
     */
    focusScene(nodes, animate = true) {
        const v = this.viewer;
        const pack = this.config.pack;
        this.focusNodes = nodes;
        const others = {0: 0, 1: 0.14, 2: 1}[pack.showcontext] ?? 0.14;
        v.isolate(nodes, others);
        v.setPickable(nodes);
        const preset = this.currentPreset || pack.defaultpreset;
        const amount = this.explodeAmount ?? pack.explode;
        v.explode(nodes, amount, animate, v.presetDir(preset), pack.explodedirs || {});
        return v.frame(nodes, {preset, animate, padding: 1.12 + 0.3 * amount});
    }

    /**
     * Whole-model view.
     *
     * @param {boolean} animate
     * @returns {Promise}
     */
    wholeScene(animate = true) {
        const v = this.viewer;
        this.focusNodes = null;
        v.showAll();
        v.setPickable(null);
        v.assemble(animate);
        const nodes = this.rootFrame && this.rootFrame.length ? this.rootFrame : v.names();
        return v.frame(nodes, {preset: this.currentPreset || this.config.pack.defaultpreset, animate, padding: 1.1});
    }

    /**
     * Home button.
     */
    resetView() {
        Sound.play('whoosh');
        if (this.mode === 'study') {
            this.closeCard();
            this.drill(null);
        } else if (this.focusNodes) {
            this.currentPreset = null;
            this.focusScene(this.focusNodes);
        }
    }

    /**
     * Breadcrumb.
     *
     * @param {Array} parts [{label, action}]
     */
    setCrumbs(parts) {
        this.crumbs.replaceChildren();
        parts.forEach((p, i) => {
            if (i) {
                this.crumbs.appendChild(el('span', 'ax-crumb-sep', {html: icon('chevron')}));
            }
            if (p.action && i < parts.length - 1) {
                const b = el('button', 'ax-crumb', {type: 'button', text: p.label});
                b.addEventListener('click', p.action);
                this.crumbs.appendChild(b);
            } else {
                this.crumbs.appendChild(el('span', 'ax-crumb is-current', {text: p.label, 'aria-current': 'location'}));
            }
        });
    }

    /**
     * Sets the status line inside the 3D view.
     *
     * @param {string} text
     * @param {string} tone info|good|bad|hint
     */
    setStatus(text, tone = 'info') {
        this.status.textContent = text || '';
        this.status.className = 'ax-status is-' + tone + (text ? ' is-on' : '');
        if (text) {
            this.say(text);
        }
    }

    /**
     * Practice: after a correct label, shows a card with the structure's key facts (and Listen when voiceover is
     * on). It stays until the student closes it or labels the next structure.
     *
     * @param {string} pin
     * @param {string} colour
     */
    showInfo(pin, colour) {
        const info = this.infos && this.infos.get(pin);
        this.hideInfo();
        if (!info || !info.facts || !info.facts.length) {
            return;
        }
        const card = el('div', 'ax-info', {role: 'dialog', 'aria-label': info.name});
        card.style.setProperty('--c', colour || 'var(--aa-primary)');
        const head = el('div', 'ax-info-head');
        const title = el('div', 'ax-info-title');
        title.appendChild(el('span', 'ax-info-check', {html: icon('check')}));
        title.appendChild(el('strong', '', {formatted: info.name}));
        if (info.latin) {
            title.appendChild(el('em', 'ax-latin', {formatted: info.latin}));
        }
        head.appendChild(title);
        if (info.voice && this.voice.has('cards')) {
            const listen = iconButton('speak', S.listen, 'ax-info-listen');
            listen.addEventListener('click', () => this.voice.play(info.voice, true));
            head.appendChild(listen);
        }
        const close = iconButton('cross', S.close, 'ax-info-close');
        close.addEventListener('click', () => this.hideInfo());
        head.appendChild(close);
        card.appendChild(head);
        const list = el('dl', 'ax-info-facts');
        info.facts.forEach((f) => {
            list.appendChild(el('dt', '', {formatted: f.label}));
            list.appendChild(el('dd', '', {formatted: f.text}));
        });
        card.appendChild(list);
        this.view.appendChild(card);
        this.infoEl = card;
        window.requestAnimationFrame(() => card.classList.add('is-on'));
        if (info.voice && this.voice.auto) {
            this.voice.play(info.voice);
        }
    }

    /**
     * Removes the practice info card.
     */
    hideInfo() {
        if (this.infoEl) {
            this.infoEl.remove();
            this.infoEl = null;
        }
    }

    /**
     * Pointer selected a structure in the 3D view.
     *
     * @param {string|null} name
     * @param {object|null} hit
     */
    onSelect(name, hit) {
        if (this.mode === 'study') {
            this.studySelect(name);
        } else if (this.phase === 'find') {
            this.findSelect(name, hit);
        }
    }

    /**
     * Pointer hovered a structure.
     *
     * @param {string|null} name
     */
    onHover(name) {
        if (this.mode !== 'study' || !this.viewer) {
            return;
        }
        const v = this.viewer;
        if (this.hoverGroup) {
            this.hoverGroup.forEach((n) => {
                if (n !== this.selectedNode) {
                    v.setState(n, 'default');
                }
            });
            this.hoverGroup = null;
        }
        if (!name) {
            return;
        }
        let nodes = [name];
        if (!this.level) {
            const s = this.byNode.get(name);
            nodes = s ? this.groupNodes(s.top) : [name];
        }
        nodes = nodes.filter((n) => n !== this.selectedNode);
        v.setState(nodes, 'hover');
        this.hoverGroup = nodes;
    }

    /* ------------------------------------------------------------------ */
    /* Study                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Starts Study mode.
     */
    startStudy() {
        const study = this.config.study;
        this.byNode = new Map(study.structures.map((s) => [s.node, s]));
        this.byId = new Map(study.structures.map((s) => [s.id, s]));
        this.groups = new Map(this.config.pack.groups.map((g) => [g.id, g]));
        this.labelsOn = false;
        // Frame the regions being taught (e.g. the hand, not the whole forearm).
        const tops = new Set(this.config.pack.rootframe && this.config.pack.rootframe.length ? this.config.pack.rootframe :
            study.structures.filter((s) => s.enabled).map((s) => s.top));
        this.rootFrame = study.structures.filter((s) => tops.has(s.top)).map((s) => s.node);
        this.buildStudySide();
        this.actions.replaceChildren();
        this.drill(null, false);
    }

    /**
     * Nodes of a top-level group.
     *
     * @param {string} top
     * @returns {string[]}
     */
    groupNodes(top) {
        return this.config.study.structures.filter((s) => s.top === top).map((s) => s.node);
    }

    /**
     * Side panel for Study: search and grouped structure list.
     */
    buildStudySide() {
        const side = this.side;
        side.replaceChildren();
        const search = el('div', 'ax-search', {html: icon('search')});
        const input = el('input', 'aa-input', {type: 'search', placeholder: S.searchstructures, 'aria-label': S.searchstructures});
        search.appendChild(input);
        side.appendChild(search);
        this.tipEl = el('div', 'ax-tip');
        this.tipEl.hidden = true;
        const list = el('div', 'ax-list', {role: 'list'});
        this.listItems = new Map();
        this.config.pack.groups.filter((g) => g.top).forEach((g) => {
            const members = this.config.study.structures.filter((s) => s.top === g.id);
            if (!members.length) {
                return;
            }
            const head = el('button', 'ax-list-group', {type: 'button', 'data-group': g.id,
                children: [el('span', 'ax-list-ic', {html: icon('layers')}), el('span', '', {text: g.label})]});
            head.addEventListener('click', () => {
                this.closeCard();
                this.drill(g.id);
            });
            const box = el('div', 'ax-list-box', {role: 'listitem'});
            box.appendChild(head);
            members.forEach((s) => {
                const item = el('button', 'ax-list-item' + (s.enabled ? '' : ' is-context'), {type: 'button',
                    'data-id': s.id});
                item.appendChild(el('span', 'ax-swatch'));
                item.lastChild.style.background = s.colour;
                // Names are format_string() output from the server.
                item.appendChild(el('span', 'ax-list-name', {formatted: s.name}));
                if (!s.enabled) {
                    item.appendChild(el('span', 'ax-nottested', {text: S.nottested}));
                }
                item.addEventListener('click', () => this.selectStructure(s.id));
                this.listItems.set(s.id, item);
                box.appendChild(item);
            });
            list.appendChild(box);
        });
        const empty = el('p', 'ax-empty', {text: S.nomatches});
        empty.hidden = true;
        side.append(this.tipEl, list, empty);
        input.addEventListener('input', () => {
            const q = input.value.trim().toLowerCase();
            let any = false;
            this.config.study.structures.forEach((s) => {
                const item = this.listItems.get(s.id);
                if (!item) {
                    return;
                }
                const hay = [s.name, s.latinsearch, ...(s.synonyms || []), s.content.latin || ''].join(' ').toLowerCase();
                const show = !q || hay.includes(q);
                item.hidden = !show;
                any = any || show;
            });
            list.querySelectorAll('.ax-list-box').forEach((b) => {
                b.hidden = !!q && !b.querySelector('.ax-list-item:not([hidden])');
            });
            empty.hidden = any;
        });
    }

    /**
     * Drill down into a top-level group (null = whole model).
     *
     * @param {string|null} top
     * @param {boolean} animate
     * @returns {Promise}
     */
    drill(top, animate = true) {
        const v = this.viewer;
        this.level = top;
        this.selectedNode = null;
        this.hoverGroup = null;
        v.clearStates();
        const rootLabel = this.groups.get(this.config.pack.root)?.label || this.config.name;
        const crumbs = [{label: rootLabel, action: () => {
            this.closeCard();
            this.drill(null);
        }}];
        let promise;
        if (!top) {
            promise = this.wholeScene(animate);
            this.showTip(this.config.study.studytip ? this.config.study.studytip : '', true);
            this.counterEl.textContent = S.clicktoexplore;
        } else {
            const g = this.groups.get(top);
            crumbs.push({label: g.label, action: () => {
                this.closeCard();
                this.drill(top);
            }});
            if (animate) {
                Sound.play('whoosh');
            }
            promise = this.focusScene(this.groupNodes(top), animate);
            this.showTip(g.tip || '');
            this.counterEl.textContent = S.clicktostudy;
        }
        this.setCrumbs(crumbs);
        this.listItems.forEach((item, id) => {
            item.classList.toggle('is-ingroup', !!top && this.byId.get(id).top === top);
            item.classList.remove('is-selected');
        });
        this.side.querySelectorAll('.ax-list-group').forEach((h) => h.classList.toggle('is-on', h.dataset.group === top));
        return promise.then(() => this.refreshLabels());
    }

    /**
     * Shows the tip panel.
     *
     * @param {string} text
     * @param {boolean} formatted text is server-formatted HTML
     */
    showTip(text, formatted = false) {
        this.tipEl.replaceChildren();
        if (!text) {
            this.tipEl.hidden = true;
            return;
        }
        this.tipEl.hidden = false;
        this.tipEl.appendChild(el('span', 'ax-tip-ic', {html: icon('brain')}));
        this.tipEl.appendChild(el('div', 'ax-tip-body', formatted ? {formatted: text} : {text}));
    }

    /**
     * Click in Study mode.
     *
     * @param {string|null} name
     */
    studySelect(name) {
        if (!name) {
            this.closeCard();
            if (this.selectedNode) {
                this.viewer.setState(this.selectedNode, 'default');
                this.selectedNode = null;
            }
            return;
        }
        const s = this.byNode.get(name);
        if (!s) {
            return;
        }
        if (!this.level || s.top !== this.level) {
            this.drill(s.top);
            return;
        }
        this.selectStructure(s.id);
    }

    /**
     * Selects a structure and opens its card.
     *
     * @param {string} id
     */
    async selectStructure(id) {
        const s = this.byId.get(id);
        if (!s) {
            return;
        }
        if (this.level !== s.top) {
            await this.drill(s.top);
        }
        const v = this.viewer;
        if (this.selectedNode) {
            v.setState(this.selectedNode, 'default');
        }
        this.restoreGroupScene();
        this.selectedNode = s.node;
        v.setState(s.node, 'selected');
        this.listItems.forEach((item, key) => item.classList.toggle('is-selected', key === id));
        const item = this.listItems.get(id);
        if (item) {
            item.scrollIntoView({block: 'nearest'});
        }
        const g = this.groups.get(s.top);
        const rootLabel = this.groups.get(this.config.pack.root)?.label || this.config.name;
        this.setCrumbs([
            {label: rootLabel, action: () => {
                this.closeCard();
                this.drill(null);
            }},
            {label: g.label, action: () => {
                this.closeCard();
                this.drill(s.top);
            }},
            {label: this.plain(s.name)},
        ]);
        this.openCard(s);
    }

    /**
     * Restores the current group's scene (after isolate / hide).
     */
    restoreGroupScene() {
        if (!this.level || !this.isolated) {
            return;
        }
        this.isolated = false;
        const others = {0: 0, 1: 0.14, 2: 1}[this.config.pack.showcontext] ?? 0.14;
        this.viewer.isolate(this.focusNodes, others);
    }

    /**
     * Plain text of server-formatted HTML.
     *
     * @param {string} html
     * @returns {string}
     */
    plain(html) {
        const d = document.createElement('div');
        d.innerHTML = html;
        return d.textContent;
    }

    /**
     * Opens the Study card for a structure.
     *
     * @param {object} s
     */
    openCard(s) {
        this.closeCard(true);
        const card = el('section', 'ax-card', {role: 'dialog', 'aria-modal': 'false', tabindex: '-1'});
        card.style.setProperty('--c', s.colour);
        card.appendChild(el('span', 'aa-card-accent'));
        const close = iconButton('cross', S.close, 'aa-card-close');
        close.addEventListener('click', () => this.studySelect(null));
        card.appendChild(close);
        const head = el('header', 'ax-card-head');
        head.appendChild(el('span', 'aa-card-eyebrow', {html: icon('bone')}));
        head.firstChild.appendChild(el('span', '', {text: this.groups.get(s.group)?.label || ''}));
        const title = el('h3', 'aa-card-title', {formatted: s.name});
        head.appendChild(title);
        card.setAttribute('aria-label', this.plain(s.name));
        const c = s.content || {};
        if (c.latin || c.pronunciation) {
            const sub = el('div', 'ax-card-sub');
            if (c.latin) {
                sub.appendChild(el('em', 'ax-latin', {formatted: c.latin}));
            }
            if (c.pronunciation) {
                sub.appendChild(el('span', 'ax-pron', {formatted: c.pronunciation}));
            }
            const nameitem = this.voice.has('cards') && s.voice ? s.voice.name : null;
            if (nameitem || 'speechSynthesis' in window) {
                const sp = iconButton('speak', S.pronounce, 'ax-speak');
                sp.addEventListener('click', () => (nameitem ? this.voice.play(nameitem, true) : speak(this.plain(s.name))));
                sub.appendChild(sp);
            }
            head.appendChild(sub);
        }
        card.appendChild(head);
        const body = el('div', 'ax-card-body');
        if (!s.enabled) {
            head.appendChild(el('span', 'ax-nottested', {text: S.nottested}));
        }
        const fieldicons = {origin: 'root', location: 'compass', description: 'info', function: 'gear', mnemonic: 'brain',
            clinical: 'stethoscope'};
        Object.entries(fieldicons).forEach(([f, ic]) => {
            if (!c[f]) {
                return;
            }
            const row = el('div', 'ax-field ax-field-' + f);
            row.appendChild(el('span', 'ax-field-ic', {html: icon(ic)}));
            const txt = el('div', 'ax-field-txt');
            txt.appendChild(el('span', 'ax-field-label', {text: S['field_' + f]}));
            txt.appendChild(el('p', '', {formatted: c[f]}));
            row.appendChild(txt);
            body.appendChild(row);
        });
        if (s.related && s.related.length) {
            // One row per relationship type ("Joins with", "Supplies", "Drains into"...), in pack order.
            const types = [];
            s.related.forEach((r) => {
                if (!types.includes(r.type)) {
                    types.push(r.type);
                }
            });
            types.forEach((type) => {
                const row = el('div', 'ax-field ax-field-related');
                row.appendChild(el('span', 'ax-field-ic', {html: icon('link')}));
                const txt = el('div', 'ax-field-txt');
                txt.appendChild(el('span', 'ax-field-label', {text: S['rel_' + type] || S.field_relationships}));
                const chips = el('div', 'ax-related');
                s.related.filter((r) => r.type === type).forEach((r) => {
                    const b = el('button', 'ax-relchip', {type: 'button', formatted: r.name});
                    b.addEventListener('click', () => this.selectStructure(r.id));
                    b.addEventListener('mouseenter', () => {
                        const t = this.byId.get(r.id);
                        if (t && t.node !== this.selectedNode) {
                            this.viewer.setState(t.node, 'hover');
                        }
                    });
                    b.addEventListener('mouseleave', () => {
                        const t = this.byId.get(r.id);
                        if (t && t.node !== this.selectedNode) {
                            this.viewer.setState(t.node, 'default');
                        }
                    });
                    chips.appendChild(b);
                });
                txt.appendChild(chips);
                row.appendChild(txt);
                body.appendChild(row);
            });
        }
        card.appendChild(body);

        const acts = el('div', 'ax-card-actions');
        const iso = button(S.isolate, 'isolate', 'aa-btn-ghost aa-btn-sm');
        iso.addEventListener('click', () => {
            this.isolated = true;
            this.viewer.isolate([s.node], 0.06);
            this.viewer.frame([s.node], {padding: 1.8});
        });
        const ctx = button(S.showincontext, 'eye', 'aa-btn-ghost aa-btn-sm');
        ctx.addEventListener('click', () => {
            this.isolated = true;
            const near = (s.related || []).map((r) => this.byId.get(r.id)?.node).filter(Boolean);
            this.viewer.context([s.node], near, 0.45, 0.08);
            this.viewer.frame([s.node, ...near], {padding: 1.3});
        });
        const all = button(S.showall, 'layers', 'aa-btn-ghost aa-btn-sm');
        all.addEventListener('click', () => {
            this.isolated = true;
            this.restoreGroupScene();
            this.viewer.frame(this.focusNodes, {padding: 1.12 + 0.3 * this.explodeAmount});
        });
        acts.append(iso, ctx, all);
        if (this.voice.has('cards') && s.voice && s.voice.card) {
            // Listen reads the card aloud (a second press stops it).
            const listen = button(S.listen, 'voice', 'aa-btn-ghost aa-btn-sm ax-listen');
            listen.addEventListener('click', () => {
                if (this.voice.playing && this.cardListening === s.id) {
                    this.voice.stop();
                    return;
                }
                this.cardListening = s.id;
                this.voice.play(s.voice.card, true);
            });
            this.listenBtn = listen;
            acts.prepend(listen);
        }
        card.appendChild(acts);

        this.view.appendChild(card);
        this.card = card;
        this.fitCard();
        requestAnimationFrame(() => card.classList.add('is-open'));
        Sound.play('card');
        this.say(this.plain(s.name));
    }

    /**
     * Card as a side panel (the model shifts left to stay visible) or, on narrow screens, a bottom sheet.
     */
    fitCard() {
        if (!this.card || !this.view || !this.viewer) {
            return;
        }
        const narrow = this.view.clientWidth < 640;
        this.card.classList.toggle('is-sheet', narrow);
        const inset = narrow ? 0 : this.card.offsetWidth + 12;
        const changed = inset !== (this.viewer.inset || 0);
        this.viewer.setRightInset(inset);
        if (changed && this.focusNodes) {
            this.viewer.frame(this.focusNodes, {padding: 1.12 + 0.3 * (this.explodeAmount ?? 1)})
                .then(() => this.overlay && this.overlay.layout(false));
        }
    }

    /**
     * Closes the Study card.
     *
     * @param {boolean} instant
     */
    closeCard(instant = false) {
        if (!this.card) {
            return;
        }
        const card = this.card;
        this.card = null;
        this.listenBtn = null;
        if (this.cardListening) {
            this.cardListening = null;
            this.voice.stop();
        }
        if (this.viewer) {
            this.viewer.setRightInset(0);
        }
        if (instant || REDUCED) {
            card.remove();
            return;
        }
        card.classList.add('is-closing');
        window.setTimeout(() => card.remove(), 220);
    }

    /**
     * Labels on/off in Study.
     */
    toggleLabels() {
        this.labelsOn = !this.labelsOn;
        this.labelsBtn.setAttribute('aria-pressed', this.labelsOn ? 'true' : 'false');
        this.labelsBtn.classList.toggle('is-on', this.labelsOn);
        Sound.play('select');
        this.refreshLabels();
    }

    /**
     * Shows labels for the enabled structures of the current group.
     */
    refreshLabels() {
        if (this.mode !== 'study' || !this.overlay) {
            return;
        }
        const shown = this.config.study.structures.filter((s) => s.top === this.level);
        this.viewer.setTints(Object.fromEntries(shown.map((s) => [s.node, s.colour])));
        if (!this.labelsOn || !this.level) {
            this.overlay.clear();
            return;
        }
        const items = shown.map((s) => {
            const box = el('button', 'ax-label', {type: 'button', formatted: s.name});
            box.addEventListener('click', () => this.selectStructure(s.id));
            return {key: s.id, node: s.node, anchor: s.anchor, pos: s.labelpos, box, colour: s.colour};
        });
        this.overlay.set(items);
    }

    /* ------------------------------------------------------------------ */
    /* Practice and Test: rounds                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Runs a round (one top-level group): Label it, then (practice) Find it.
     *
     * @param {number} index
     */
    async runRound(index) {
        const rounds = this.data.rounds;
        if (index >= rounds.length) {
            this.runQuiz();
            return;
        }
        this.roundIndex = index;
        const round = rounds[index];
        this.round = round;
        this.titleEl.textContent = round.title;
        this.counterEl.textContent = fmt(S.roundof, {n: index + 1, total: rounds.length}) + ' · ' + S.labelit;
        this.setCrumbs([{label: round.title}, {label: S.labelit}]);
        this.viewer.clearStates();
        this.closeCard(true);
        this.currentPreset = null;
        Sound.play(index ? 'slide' : 'whoosh');
        await this.focusScene(round.nodes, true);
        this.setupLabels(round);
    }

    /**
     * Label it: chips in the tray, numbered slots on the 3D model.
     *
     * @param {object} round
     */
    setupLabels(round) {
        this.phase = 'label';
        this.selectedChip = null;
        this.slots = new Map();
        this.chips = new Map();
        this.tries = {};
        this.hinted = new Set();
        this.answers = new Map((round.answers || []).map((a) => [a.pin, a.label]));
        this.hints = new Map((round.hints || []).map((h) => [h.pin, h.text]));
        this.infos = new Map((round.infos || []).map((i) => [i.pin, i]));
        this.hideInfo();
        this.viewer.setPickable([]);

        // Tray.
        const side = this.side;
        side.replaceChildren();
        side.appendChild(el('p', 'ax-side-title', {text: S.labeltray}));
        side.appendChild(el('p', 'ax-side-help', {text: COARSE ? S.instructions_tap : S.instructions_drag}));
        const tray = el('div', 'aa-tray ax-tray', {role: 'group', 'aria-label': S.labeltray});
        this.tray = tray;
        round.labels.forEach((l) => {
            const chip = el('button', 'aa-chip', {type: 'button', 'data-token': l.token, formatted: l.text});
            chip.addEventListener('pointerdown', (e) => this.chipPointerDown(e, chip));
            chip.addEventListener('click', (e) => {
                if (this.suppressClick) {
                    e.preventDefault();
                    return;
                }
                this.toggleSelect(chip);
            });
            this.chips.set(l.token, {el: chip, text: this.plain(l.text), token: l.token, placed: null});
            tray.appendChild(chip);
        });
        side.appendChild(tray);
        if (round.tip) {
            this.tipEl = el('div', 'ax-tip');
            side.appendChild(this.tipEl);
            this.showTip(round.tip, true);
        }

        // Slots.
        const items = round.pins.map((p) => {
            const slot = el('button', 'aa-slot-zone ax-slot', {type: 'button', 'aria-label': fmt(S.slotempty, p.number)});
            slot.appendChild(el('span', 'aa-slot-num', {text: String(p.number)}));
            const body = el('span', 'aa-slot-body');
            slot.appendChild(body);
            slot.addEventListener('click', () => this.onSlotActivate(p.token));
            this.slots.set(p.token, {pin: p, el: slot, body, chip: null, done: false});
            this.tries[p.token] = 0;
            return {key: p.token, node: p.node, anchor: p.anchor, pos: p.labelpos, box: slot, colour: p.colour,
                number: p.number};
        });
        this.overlay.set(items);
        this.viewer.setTints(Object.fromEntries(round.pins.map((p) => [p.node, p.colour])));

        // Actions.
        this.actions.replaceChildren();
        const left = el('div', 'aa-actions-left');
        const right = el('div', 'aa-actions-right');
        if (this.mode === 'practice') {
            const hint = button(S.hint, 'hint');
            hint.addEventListener('click', () => this.labelHint());
            const show = button(S.showanswers, 'eye');
            show.addEventListener('click', () => this.showAnswers());
            left.append(hint, show);
            this.nextBtn = button(S.nexttask, 'next', 'aa-btn-primary', true);
            this.nextBtn.disabled = true;
            this.nextBtn.addEventListener('click', () => this.startFind());
            right.appendChild(this.nextBtn);
        } else {
            const reset = button(S.reset, 'reset');
            reset.addEventListener('click', () => this.resetLabels());
            left.appendChild(reset);
            this.nextBtn = button(S.submitround, 'check', 'aa-btn-primary', true);
            this.nextBtn.addEventListener('click', () => this.submitRound());
            right.appendChild(this.nextBtn);
        }
        this.actions.append(left, right);
        this.setStatus('');
    }

    /**
     * Selects / deselects a chip (tap-tap and keyboard).
     *
     * @param {HTMLElement} chip
     */
    toggleSelect(chip) {
        if (chip.disabled) {
            return;
        }
        const token = chip.dataset.token;
        if (this.selectedChip === token) {
            this.clearSelection();
            return;
        }
        this.clearSelection();
        this.selectedChip = token;
        chip.classList.add('is-selected');
        this.overlay.layer.classList.add('is-targeting');
        Sound.play('pickup');
        this.say(fmt(S.selectedfeedback, this.chips.get(token).text));
    }

    /**
     * Clears the selected chip.
     */
    clearSelection() {
        if (this.selectedChip) {
            const c = this.chips.get(this.selectedChip);
            if (c) {
                c.el.classList.remove('is-selected');
            }
        }
        this.selectedChip = null;
        if (this.overlay) {
            this.overlay.layer.classList.remove('is-targeting');
        }
    }

    /**
     * A slot was clicked / activated.
     *
     * @param {string} pin
     */
    onSlotActivate(pin) {
        const slot = this.slots.get(pin);
        if (!slot || slot.done) {
            return;
        }
        if (this.selectedChip) {
            const token = this.selectedChip;
            this.clearSelection();
            this.place(token, pin);
            return;
        }
        if (slot.chip && this.mode === 'test') {
            this.unplace(slot.chip);
            Sound.play('back');
            this.say(S.returntotray);
            return;
        }
        if (this.mode === 'practice' && this.hints.get(pin)) {
            this.setStatus(fmt(S.hintfor, {n: slot.pin.number, hint: this.hints.get(pin)}), 'hint');
        }
    }

    /**
     * Pointer down on a chip: starts a drag once the pointer moves.
     *
     * @param {PointerEvent} e
     * @param {HTMLElement} chip
     */
    chipPointerDown(e, chip) {
        if (chip.disabled || e.button > 0) {
            return;
        }
        const start = {x: e.clientX, y: e.clientY};
        let ghost = null;
        let over = null;
        const token = chip.dataset.token;
        const move = (ev) => {
            if (!ghost) {
                if (Math.hypot(ev.clientX - start.x, ev.clientY - start.y) < 6) {
                    return;
                }
                this.clearSelection();
                ghost = chip.cloneNode(true);
                ghost.classList.add('aa-ghost');
                ghost.removeAttribute('data-token');
                ghost.style.width = chip.offsetWidth + 'px';
                document.body.appendChild(ghost);
                chip.classList.add('is-lifted');
                this.overlay.layer.classList.add('is-dragging');
                document.body.classList.add('aa-noselect');
                Sound.play('pickup');
            }
            ev.preventDefault();
            ghost.style.transform = `translate(${ev.clientX - ghost.offsetWidth / 2}px, ${ev.clientY - 22}px) rotate(-3deg)`;
            const hit = this.overlay.hitTest(ev.clientX, ev.clientY, 18);
            const next = hit && !this.slots.get(hit.key).done ? hit.key : null;
            if (next !== over) {
                if (over) {
                    this.slots.get(over).el.classList.remove('is-over');
                }
                over = next;
                if (over) {
                    this.slots.get(over).el.classList.add('is-over');
                    Sound.play('zone');
                }
            }
        };
        const up = () => {
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', up);
            document.removeEventListener('pointercancel', up);
            if (!ghost) {
                return;
            }
            this.suppressClick = true;
            window.setTimeout(() => {
                this.suppressClick = false;
            }, 0);
            ghost.remove();
            chip.classList.remove('is-lifted');
            this.overlay.layer.classList.remove('is-dragging');
            document.body.classList.remove('aa-noselect');
            if (over) {
                this.slots.get(over).el.classList.remove('is-over');
                this.place(token, over);
            } else {
                Sound.play('back');
            }
        };
        document.addEventListener('pointermove', move, {passive: false});
        document.addEventListener('pointerup', up);
        document.addEventListener('pointercancel', up);
    }

    /**
     * Places a chip on a slot.
     *
     * @param {string} token
     * @param {string} pin
     */
    place(token, pin) {
        const chip = this.chips.get(token);
        const slot = this.slots.get(pin);
        if (!chip || !slot || slot.done) {
            return;
        }
        if (this.mode === 'practice') {
            this.tries[pin]++;
            if (this.answers.get(pin) === token) {
                this.attach(chip, slot);
                slot.done = true;
                slot.el.classList.add('is-correct');
                chip.el.disabled = true;
                this.viewer.setState(slot.pin.node, 'correct');
                const r = slot.el.getBoundingClientRect();
                const v = this.view.getBoundingClientRect();
                burst(this.view, r.left - v.left + 12, r.top - v.top + r.height / 2, slot.pin.colour);
                Sound.play('correct');
                const first = this.tries[pin] === 1 && !this.hinted.has(pin);
                this.results.label[pin] = {correct: 1, tries: first ? 1 : Math.max(2, this.tries[pin])};
                if (first) {
                    this.firstTry++;
                }
                this.updateScore();
                this.setStatus(fmt(S.correctfeedback, chip.text), 'good');
                this.showInfo(pin, slot.pin.colour);
                this.checkLabelsDone();
            } else {
                slot.el.classList.add('is-wrong');
                window.setTimeout(() => slot.el.classList.remove('is-wrong'), 900);
                this.viewer.setState(slot.pin.node, 'incorrect');
                window.setTimeout(() => {
                    if (!slot.done) {
                        this.viewer.setState(slot.pin.node, 'default');
                    }
                }, 700);
                Sound.play('wrong');
                let msg = fmt(S.wrongfeedback, chip.text);
                if (this.tries[pin] >= 2 && this.hints.get(pin)) {
                    msg += ' ' + fmt(S.hintfor, {n: slot.pin.number, hint: this.hints.get(pin)});
                    this.hinted.add(pin);
                }
                this.setStatus(msg, 'bad');
            }
            return;
        }
        // Test: free placement, swap if occupied.
        if (slot.chip) {
            this.unplace(slot.chip);
        }
        if (chip.placed) {
            const prev = this.slots.get(chip.placed);
            prev.chip = null;
            prev.body.replaceChildren();
            prev.el.classList.remove('is-filled');
        }
        this.attach(chip, slot);
        Sound.play('drop');
        this.say(fmt(S.placedfeedback, {label: chip.text, n: slot.pin.number}));
        this.updateScore();
    }

    /**
     * Shows a chip inside a slot.
     *
     * @param {object} chip
     * @param {object} slot
     */
    attach(chip, slot) {
        slot.chip = chip.token;
        chip.placed = slot.pin.token;
        const shown = el('span', 'aa-chip aa-chip-static', {text: chip.text});
        slot.body.replaceChildren(shown);
        slot.el.classList.add('is-filled');
        slot.el.setAttribute('aria-label', fmt(S.slotfilled, {n: slot.pin.number, label: chip.text}));
        chip.el.hidden = true;
        this.tray.classList.toggle('is-empty', Array.from(this.chips.values()).every((c) => c.el.hidden));
        this.overlay.update();
    }

    /**
     * Returns a chip to the tray (test).
     *
     * @param {string} token
     */
    unplace(token) {
        const chip = this.chips.get(token);
        if (!chip || !chip.placed) {
            return;
        }
        const slot = this.slots.get(chip.placed);
        slot.chip = null;
        slot.body.replaceChildren();
        slot.el.classList.remove('is-filled');
        slot.el.setAttribute('aria-label', fmt(S.slotempty, slot.pin.number));
        chip.placed = null;
        chip.el.hidden = false;
        this.tray.classList.remove('is-empty');
        this.overlay.update();
        this.updateScore();
    }

    /**
     * Test: removes every placement of the round.
     */
    resetLabels() {
        this.chips.forEach((c) => this.unplace(c.token));
        Sound.play('back');
    }

    /**
     * Practice hint: the selected chip's structure hint, otherwise the next unsolved slot is highlighted.
     */
    labelHint() {
        Sound.play('hint');
        let pin = null;
        if (this.selectedChip) {
            for (const [p, l] of this.answers) {
                if (l === this.selectedChip) {
                    pin = p;
                }
            }
            if (pin) {
                this.hinted.add(pin);
                this.setStatus(fmt(S.hintpick, {label: this.chips.get(this.selectedChip).text,
                    hint: this.hints.get(pin) || ''}), 'hint');
                return;
            }
        }
        const slot = Array.from(this.slots.values()).find((s) => !s.done);
        if (!slot) {
            return;
        }
        this.hinted.add(slot.pin.token);
        slot.el.classList.add('aa-hinted');
        window.setTimeout(() => slot.el.classList.remove('aa-hinted'), 2000);
        this.viewer.setState(slot.pin.node, 'target');
        window.setTimeout(() => {
            if (!slot.done) {
                this.viewer.setState(slot.pin.node, 'default');
            }
        }, 1600);
        this.setStatus(fmt(S.hintfor, {n: slot.pin.number, hint: this.hints.get(slot.pin.token) || ''}), 'hint');
    }

    /**
     * Practice: reveals the remaining answers (counted as not correct first time).
     */
    showAnswers() {
        Sound.play('hint');
        this.slots.forEach((slot, pin) => {
            if (slot.done) {
                return;
            }
            const token = this.answers.get(pin);
            const chip = this.chips.get(token);
            if (chip) {
                this.attach(chip, slot);
                chip.el.disabled = true;
            }
            slot.done = true;
            slot.el.classList.add('is-revealed');
            this.viewer.setState(slot.pin.node, 'target');
            this.results.label[pin] = {correct: 0, tries: Math.max(2, this.tries[pin] + 1)};
        });
        this.checkLabelsDone();
    }

    /**
     * Practice: all slots solved?
     */
    checkLabelsDone() {
        const done = Array.from(this.slots.values()).every((s) => s.done);
        if (!done) {
            return;
        }
        this.nextBtn.disabled = false;
        this.nextBtn.focus({preventScroll: true});
        Sound.play('slide');
        this.setStatus(S.roundcomplete, 'good');
        this.record('label', this.round.pins.map((p) => p.token));
    }

    /**
     * Sends practice results to the server (mastery and reports).
     *
     * @param {string} kind
     * @param {string[]} tokens
     */
    record(kind, tokens) {
        const items = tokens.filter((t) => this.results[kind][t]).map((t) => ({
            kind, token: t, correct: this.results[kind][t].correct, tries: this.results[kind][t].tries,
        }));
        if (!items.length || this.mode !== 'practice') {
            return;
        }
        this.unsent = (this.unsent || []).concat(items);
        this.flushPractice();
    }

    /**
     * Sends practice results not yet saved. A failed save (for example while the session is briefly busy) is
     * retried quietly and the answers are kept, so a fast student never loses one; the server ignores repeats.
     *
     * @returns {Promise}
     */
    flushPractice() {
        if (this.flushing) {
            return this.flushing;
        }
        if (!this.unsent || !this.unsent.length) {
            return Promise.resolve();
        }
        const items = this.unsent.splice(0);
        const attemptid = this.attemptid;
        this.flushing = Ajax.call([{methodname: 'mod_aianatomy_record_practice', args: {attemptid, items}}])[0]
            .then(() => {
                this.flushing = null;
                this.saveFailures = 0;
                return this.flushPractice();
            })
            .catch((e) => {
                this.flushing = null;
                if (attemptid !== this.attemptid) {
                    return null;
                }
                this.unsent = items.concat(this.unsent);
                this.saveFailures = (this.saveFailures || 0) + 1;
                if (this.saveFailures > 4) {
                    this.saveFailures = 0;
                    Notification.exception(e);
                    return null;
                }
                return new Promise((resolve) => window.setTimeout(resolve, 1500 * this.saveFailures))
                    .then(() => this.flushPractice());
            });
        return this.flushing;
    }

    /**
     * Test: submits and locks the current round.
     *
     * @param {boolean} force skip confirmation (time up)
     * @returns {Promise}
     */
    async submitRound(force = false) {
        const empty = Array.from(this.slots.values()).some((s) => !s.chip);
        if (!force && empty && !(await this.confirm(S.confirmsubmitround))) {
            return;
        }
        this.nextBtn.disabled = true;
        const placements = Array.from(this.slots.values()).map((s) => ({pin: s.pin.token, label: s.chip || ''}));
        try {
            await Ajax.call([{methodname: 'mod_aianatomy_submit_round', args: {attemptid: this.attemptid,
                round: this.roundIndex, placements}}])[0];
        } catch (e) {
            if (!force) {
                this.nextBtn.disabled = false;
                Notification.exception(e);
                return;
            }
        }
        this.slots.forEach((s) => {
            s.done = true;
            s.el.disabled = true;
        });
        this.chips.forEach((c) => {
            c.el.disabled = true;
        });
        this.submittedPins = (this.submittedPins || 0) + this.slots.size;
        if (force) {
            return;
        }
        Sound.play('slide');
        this.runRound(this.roundIndex + 1);
    }

    /* ------------------------------------------------------------------ */
    /* Find it (practice)                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Starts "Find it": click the named structure on the model.
     */
    startFind() {
        this.hideInfo();
        this.phase = 'find';
        this.overlay.clear();
        this.viewer.clearStates();
        this.viewer.setPickable(this.round.nodes);
        this.names = new Map((this.data.names || []).map((n) => [n.node, n.name]));
        this.findQueue = shuffle(this.round.pins);
        this.findTries = {};
        this.counterEl.textContent = fmt(S.roundof, {n: this.roundIndex + 1, total: this.data.rounds.length}) + ' · ' +
            S.findit;
        this.setCrumbs([{label: this.round.title}, {label: S.findit}]);
        this.side.replaceChildren(el('p', 'ax-side-title', {text: S.findit}));
        this.findList = el('ol', 'ax-findlist');
        this.side.appendChild(this.findList);
        Sound.play('whoosh');

        this.actions.replaceChildren();
        const left = el('div', 'aa-actions-left');
        const hint = button(S.hint, 'hint');
        hint.addEventListener('click', () => this.findHint());
        const show = button(S.showme, 'eye');
        show.addEventListener('click', () => this.findReveal());
        left.append(hint, show);
        const right = el('div', 'aa-actions-right');
        this.nextBtn = button(S.next, 'next', 'aa-btn-primary', true);
        this.nextBtn.disabled = true;
        this.nextBtn.addEventListener('click', () => this.runRound(this.roundIndex + 1));
        right.appendChild(this.nextBtn);
        this.actions.append(left, right);
        this.nextFind();
    }

    /**
     * Next "Find it" target.
     */
    nextFind() {
        this.findTarget = this.findQueue.shift() || null;
        if (!this.findTarget) {
            this.phase = 'done';
            this.setStatus(S.roundcomplete, 'good');
            Sound.play('slide');
            this.record('find', this.round.pins.map((p) => p.token));
            this.nextBtn.disabled = false;
            this.nextBtn.focus({preventScroll: true});
            return;
        }
        this.findTries[this.findTarget.token] = 0;
        const name = this.labelFor(this.findTarget.token);
        this.setStatus(fmt(S.findprompt, name), 'prompt');
        const item = this.voice.has('prompts') ? this.findTarget.voice : null;
        if (item) {
            const again = iconButton('voice', S.listenprompt, 'ax-status-voice');
            again.addEventListener('click', () => this.voice.play(item, true));
            this.status.appendChild(again);
            if (this.voice.auto) {
                this.voice.play(item);
            }
        }
    }

    /**
     * Name for a pin (practice has the answers).
     *
     * @param {string} pin
     * @returns {string}
     */
    labelFor(pin) {
        const label = this.answers.get(pin);
        const l = this.round.labels.find((x) => x.token === label);
        return l ? this.plain(l.text) : '';
    }

    /**
     * Click during "Find it".
     *
     * @param {string|null} name
     * @param {object|null} hit
     */
    findSelect(name, hit) {
        const t = this.findTarget;
        if (!t || !name) {
            return;
        }
        const token = t.token;
        this.findTries[token]++;
        const v = this.viewer;
        if (name === t.node) {
            v.setState(name, 'correct');
            Sound.play('correct');
            if (hit) {
                const p = v.project(name, null);
                burst(this.view, p.x, p.y, '#12b76a');
            }
            const first = this.findTries[token] === 1 && !this.findHinted;
            this.results.find[token] = {correct: 1, tries: first ? 1 : Math.max(2, this.findTries[token])};
            if (first) {
                this.firstTry++;
            }
            this.findHinted = false;
            this.updateScore();
            const label = this.labelFor(token);
            this.findList.appendChild(el('li', first ? 'is-good' : 'is-ok', {text: label}));
            this.setStatus(fmt(S.findcorrect, label), 'good');
            window.setTimeout(() => this.nextFind(), REDUCED ? 200 : 900);
            return;
        }
        if (v.nodes.get(name)?.state === 'correct') {
            return;
        }
        v.setState(name, 'incorrect');
        window.setTimeout(() => {
            if (v.nodes.get(name)?.state === 'incorrect') {
                v.setState(name, 'default');
            }
        }, 800);
        Sound.play('wrong');
        let msg = fmt(S.findwrong, {clicked: this.names.get(name) || '?', target: this.labelFor(token)});
        if (this.findTries[token] >= 2) {
            msg += ' ' + (this.hints.get(token) || '');
            this.findHinted = true;
        }
        this.setStatus(msg, 'bad');
    }

    /**
     * "Find it" hint.
     */
    findHint() {
        if (!this.findTarget) {
            return;
        }
        Sound.play('hint');
        this.findHinted = true;
        this.setStatus(fmt(S.findprompt, this.labelFor(this.findTarget.token)) + ' ' +
            (this.hints.get(this.findTarget.token) || ''), 'hint');
    }

    /**
     * "Find it": show me.
     */
    findReveal() {
        const t = this.findTarget;
        if (!t) {
            return;
        }
        Sound.play('hint');
        this.viewer.setState(t.node, 'target');
        this.results.find[t.token] = {correct: 0, tries: Math.max(2, this.findTries[t.token] + 1)};
        this.findList.appendChild(el('li', 'is-missed', {text: this.labelFor(t.token)}));
        this.findTarget = null;
        this.findHinted = false;
        window.setTimeout(() => {
            this.viewer.setState(t.node, 'default');
            this.nextFind();
        }, REDUCED ? 600 : 1600);
    }

    /* ------------------------------------------------------------------ */
    /* Quiz                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Knowledge questions.
     */
    runQuiz() {
        const qs = this.data.questions;
        if (!qs.length) {
            this.finish();
            return;
        }
        this.phase = 'quiz';
        this.quizIndex = 0;
        this.quizAnswers = {};
        this.overlay.clear();
        this.viewer.clearStates();
        this.viewer.setPickable([]);
        this.titleEl.textContent = S.quiz;
        this.setCrumbs([{label: S.quiz}]);
        this.setStatus('');
        this.side.replaceChildren(el('p', 'ax-side-title', {text: S.quiz}));
        this.quizNav = el('ol', 'ax-quiznav');
        qs.forEach((q, i) => {
            const b = el('button', 'ax-qdot', {type: 'button', text: String(i + 1),
                'aria-label': fmt(S.questionof, {n: i + 1, total: qs.length})});
            b.addEventListener('click', () => {
                if (this.mode === 'test' || this.quizAnswers[qs[i].token] !== undefined || i <= this.quizMax) {
                    this.showQuestion(i);
                }
            });
            this.quizNav.appendChild(el('li', '', {children: [b]}));
        });
        this.side.appendChild(this.quizNav);
        this.quizMax = 0;
        this.quizPanel = el('div', 'ax-quiz');
        this.view.appendChild(this.quizPanel);
        this.view.classList.add('is-quiz');
        this.wholeScene(true);
        Sound.play('whoosh');
        this.showQuestion(0);
    }

    /**
     * Renders one question.
     *
     * @param {number} i
     */
    showQuestion(i) {
        const qs = this.data.questions;
        const q = qs[i];
        this.quizIndex = i;
        this.quizMax = Math.max(this.quizMax, i);
        this.counterEl.textContent = fmt(S.questionof, {n: i + 1, total: qs.length});
        this.quizNav.querySelectorAll('.ax-qdot').forEach((b, k) => {
            b.classList.toggle('is-current', k === i);
            b.classList.toggle('is-answered', qs[k] && this.quizAnswers[qs[k].token] !== undefined);
        });
        const panel = this.quizPanel;
        panel.replaceChildren();
        const card = el('div', 'ax-qcard' + (this.lastQuestion !== i ? ' is-new' : ''));
        this.lastQuestion = i;
        card.appendChild(el('p', 'ax-qnum', {text: fmt(S.questionof, {n: i + 1, total: qs.length})}));
        const qtext = el('h3', 'ax-qtext', {formatted: q.text, id: 'ax-q-' + q.token});
        card.appendChild(qtext);
        const qvoice = this.voice.has('questions') && q.voice ? [q.voice, ...(q.voiceoptions || [])] : null;
        const isnew = this.voicedQuestion !== q.token;
        if (qvoice) {
            const lq = button(S.listenquestion, 'voice', 'aa-btn-ghost aa-btn-sm ax-qlisten');
            lq.addEventListener('click', () => this.voice.play(qvoice, true));
            card.appendChild(lq);
        }
        const opts = el('div', 'ax-options', {role: 'radiogroup', 'aria-labelledby': 'ax-q-' + q.token});
        const answered = this.quizAnswers[q.token];
        const locked = this.mode === 'practice' && answered !== undefined;
        q.options.forEach((o, k) => {
            const b = el('button', 'ax-option', {type: 'button', role: 'radio',
                'aria-checked': answered === k ? 'true' : 'false'});
            b.appendChild(el('span', 'ax-option-key', {text: String.fromCharCode(65 + k)}));
            b.appendChild(el('span', 'ax-option-text', {formatted: o}));
            if (answered === k) {
                b.classList.add('is-chosen');
            }
            if (locked) {
                b.disabled = true;
                if (k === q.answer) {
                    b.classList.add('is-correct');
                } else if (k === answered) {
                    b.classList.add('is-incorrect');
                }
            }
            b.addEventListener('click', () => this.answerQuestion(q, k));
            opts.appendChild(b);
        });
        card.appendChild(opts);
        this.feedbackEl = el('div', 'ax-qfeedback', {'aria-live': 'polite'});
        card.appendChild(this.feedbackEl);
        if (locked) {
            this.showQuizFeedback(q, answered);
        }
        panel.appendChild(card);

        this.actions.replaceChildren();
        const left = el('div', 'aa-actions-left');
        const right = el('div', 'aa-actions-right');
        if (i > 0) {
            const prev = button(S.previous, 'prev');
            prev.addEventListener('click', () => this.showQuestion(i - 1));
            left.appendChild(prev);
        }
        const last = i === qs.length - 1;
        const next = button(last ? (this.mode === 'test' ? S.submitanswers : S.finish) : S.next, last ? 'check' : 'next',
            'aa-btn-primary', true);
        next.disabled = this.mode === 'practice' && answered === undefined;
        next.addEventListener('click', () => (last ? this.submitQuiz() : this.showQuestion(i + 1)));
        this.quizNext = next;
        right.appendChild(next);
        this.actions.append(left, right);
        Sound.play('card');
        opts.querySelector('.ax-option')?.focus({preventScroll: true});
        if (isnew) {
            this.voicedQuestion = q.token;
            if (qvoice && this.voice.auto && answered === undefined) {
                this.voice.play(qvoice);
            } else {
                this.voice.stop();
            }
        }
    }

    /**
     * Chooses an option.
     *
     * @param {object} q
     * @param {number} k
     */
    answerQuestion(q, k) {
        if (this.mode === 'practice') {
            if (this.quizAnswers[q.token] !== undefined) {
                return;
            }
            this.quizAnswers[q.token] = k;
            const correct = k === q.answer;
            this.results.quiz[q.token] = {correct: correct ? 1 : 0, tries: correct ? 1 : 2};
            if (correct) {
                this.firstTry++;
            }
            this.updateScore();
            Sound.play(correct ? 'correct' : 'wrong');
            this.record('quiz', [q.token]);
            this.showQuestion(this.quizIndex);
            if (this.voice.has('feedback')) {
                // Spoken feedback follows the student's own action, so it plays without "read automatically".
                this.voice.play([this.voice.phrase(correct ? 'correct' : 'incorrect'), q.voicefeedback]);
            }
            return;
        }
        this.quizAnswers[q.token] = k;
        Sound.play('drop');
        this.showQuestion(this.quizIndex);
    }

    /**
     * Practice feedback for a question.
     *
     * @param {object} q
     * @param {number} k
     */
    showQuizFeedback(q, k) {
        const correct = k === q.answer;
        this.feedbackEl.className = 'ax-qfeedback ' + (correct ? 'is-good' : 'is-bad');
        this.feedbackEl.appendChild(el('strong', '', {text: correct ? S.correctanswer : S.incorrectanswer}));
        if (q.explanation) {
            this.feedbackEl.appendChild(el('p', '', {formatted: q.explanation}));
        }
    }

    /**
     * Finishes the quiz.
     *
     * @param {boolean} force time up
     */
    async submitQuiz(force = false) {
        const qs = this.data.questions;
        if (this.mode === 'test') {
            const missing = qs.some((q) => this.quizAnswers[q.token] === undefined);
            if (!force && missing && !(await this.confirm(S.confirmsubmitquiz))) {
                return;
            }
            const answers = qs.map((q) => ({token: q.token, option: this.quizAnswers[q.token] ?? -1}));
            try {
                await Ajax.call([{methodname: 'mod_aianatomy_submit_quiz', args: {attemptid: this.attemptid, answers}}])[0];
            } catch (e) {
                if (!force) {
                    Notification.exception(e);
                    return;
                }
            }
            this.quizSubmitted = true;
        }
        if (!force) {
            this.finish();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Results                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Updates the score pill.
     */
    updateScore() {
        if (!this.scoreEl) {
            return;
        }
        this.scoreEl.replaceChildren();
        this.scoreEl.insertAdjacentHTML('afterbegin', icon('star'));
        if (this.mode === 'practice') {
            this.scoreEl.append(el('strong', '', {text: String(this.firstTry)}),
                el('span', 'aa-score-total', {text: '/ ' + this.totalItems}));
            this.scoreEl.title = S.firsttryscore;
        } else {
            const placed = this.slots ? Array.from(this.slots.values()).filter((s) => s.chip).length : 0;
            const total = this.slots ? this.slots.size : 0;
            this.scoreEl.append(el('strong', '', {text: String(placed)}), el('span', 'aa-score-total', {text: '/ ' + total}));
        }
    }

    /**
     * Finishes the attempt and shows the results.
     */
    async finish() {
        window.clearInterval(this.timerHandle);
        this.phase = 'results';
        let res;
        try {
            if (this.mode === 'practice') {
                await this.flushPractice();
            }
            res = await Ajax.call([{methodname: 'mod_aianatomy_finish_attempt', args: {attemptid: this.attemptid}}])[0];
        } catch (e) {
            Notification.exception(e);
            return;
        }
        this.showSummary(res);
    }

    /**
     * Results screen.
     *
     * @param {object} res
     */
    async showSummary(res) {
        const pct = res.grade;
        const test = res.mode === 'test';
        let title = S.keeppractising;
        if (pct >= 90) {
            title = S.excellent;
        } else if (pct >= 75) {
            title = S.greatjob;
        } else if (pct >= 50) {
            title = S.goodeffort;
        }
        const stats = [
            {icon: icon('bone'), label: S.identification, value: `${res.identcorrect} / ${res.identtotal}`},
        ];
        if (res.quiztotal) {
            stats.push({icon: icon('brain'), label: S.questions, value: `${res.quizcorrect} / ${res.quiztotal}`});
        }
        stats.push({icon: icon('clock'), label: S.time, value: clock(res.duration)});
        const verdict = test && res.passpercent ? {pass: res.passed, icon: icon(res.passed ? 'check' : 'info'),
            text: res.passed ? S.passed : fmt(S.notpassed, res.passpercent)} : false;
        const {html, js} = await Templates.renderForPromise('mod_aianatomy/player_summary', {
            ring: 326.73, verdict, title, sub: test ? '' : S.firsttryscore, stats, leaderboard: false,
            review: {label: S.reviewanswers, icon: icon('eye')},
            again: {label: S.tryagain, icon: icon('repeat')},
            againdisabled: test && res.attemptsleft === 0,
            back: {label: S.backtomenu, icon: icon('next')},
        });
        this.teardown();
        this.host.replaceChildren();
        const shell = el('div', `aa-shell aa-summary-shell aa-mode-${res.mode}`);
        Templates.appendNodeContents(shell, html, js);
        this.shell = shell;
        this.host.appendChild(shell);

        // Mastery.
        const m = res.mastery;
        const mastery = el('div', 'ax-mastery', {children: [
            el('span', 'ax-mchip is-mastered', {text: `${S.mastered}: ${m.mastered}`}),
            el('span', 'ax-mchip is-learning', {text: `${S.learning}: ${m.learning}`}),
            el('span', 'ax-mchip is-difficult', {text: `${S.difficult}: ${m.difficult}`}),
            el('span', 'ax-mchip is-new', {text: `${S.notyet}: ${m.new}`}),
        ]});
        const summary = shell.querySelector('.aa-summary');
        summary.insertBefore(mastery, summary.querySelector('.aa-summary-actions'));
        if (res.difficult.length) {
            summary.insertBefore(el('p', 'ax-difficult', {text: fmt(S.difficultlist, res.difficult.join(', '))}),
                summary.querySelector('.aa-summary-actions'));
        }

        // Review.
        const review = el('div', 'ax-review');
        review.hidden = true;
        if (res.review.length) {
            review.appendChild(el('h4', '', {text: S.identification}));
            const list = el('ul', 'ax-review-list');
            res.review.forEach((r) => {
                const li = el('li', r.correct ? 'is-good' : 'is-bad', {html: icon(r.correct ? 'check' : 'cross')});
                li.appendChild(el('strong', '', {formatted: r.name}));
                if (!r.correct) {
                    li.appendChild(el('span', '', {text: ' — ' + fmt(S.yourlabel, r.given ? this.plain(r.given) : S.nolabel)}));
                }
                list.appendChild(li);
            });
            review.appendChild(list);
        }
        if (res.quizreview.length) {
            review.appendChild(el('h4', '', {text: S.questions}));
            const list = el('ol', 'ax-review-list ax-review-quiz');
            res.quizreview.forEach((q) => {
                const li = el('li', q.correct ? 'is-good' : 'is-bad');
                li.appendChild(el('p', 'ax-review-q', {formatted: q.text}));
                if (!q.correct) {
                    li.appendChild(el('p', '', {text: fmt(S.yourchoice, q.given ? this.plain(q.given) : S.nolabel)}));
                }
                li.appendChild(el('p', 'ax-review-a', {text: S.correctanswer + ': ' + this.plain(q.answer)}));
                if (q.explanation) {
                    li.appendChild(el('p', 'ax-review-exp', {formatted: q.explanation}));
                }
                list.appendChild(li);
            });
            review.appendChild(list);
        }
        summary.appendChild(review);
        const reviewBtn = shell.querySelector('[data-action="review"]');
        if (!res.review.length && !res.quizreview.length) {
            reviewBtn.hidden = true;
        }
        reviewBtn.addEventListener('click', () => {
            review.hidden = !review.hidden;
            if (!review.hidden) {
                review.scrollIntoView({behavior: REDUCED ? 'auto' : 'smooth', block: 'start'});
            }
        });
        shell.querySelector('[data-action="again"]').addEventListener('click', () => this.showIntro(res.mode));
        shell.querySelector('[data-action="back"]').addEventListener('click', () => this.exit(true));

        // Ring animation.
        const ring = shell.querySelector('.aa-ring-fg');
        const count = shell.querySelector('[data-count]');
        const good = !test || !res.passpercent || res.passed;
        ring.style.stroke = good ? (pct >= 75 ? '#12b76a' : '#f79009') : '#f04438';
        requestAnimationFrame(() => {
            ring.style.transition = REDUCED ? 'none' : 'stroke-dashoffset 1.4s cubic-bezier(.22,1,.36,1)';
            ring.style.strokeDashoffset = String(326.73 * (1 - pct / 100));
        });
        const t0 = performance.now();
        const step = (now) => {
            const t = Math.min(1, (now - t0) / (REDUCED ? 1 : 1400));
            count.textContent = String(Math.round(pct * (1 - Math.pow(1 - t, 3))));
            if (t < 1) {
                requestAnimationFrame(step);
            }
        };
        requestAnimationFrame(step);
        if (good && pct >= 75) {
            Sound.play('finish');
            confetti(shell);
        } else {
            Sound.play(good ? 'slide' : 'fail');
        }
        this.scrollToShell();
    }

    /* ------------------------------------------------------------------ */
    /* Timer, dialogs, fullscreen, exit                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Starts the timer.
     */
    startTimer() {
        window.clearInterval(this.timerHandle);
        const limit = this.data.timelimit || 0;
        const span = this.timerEl.querySelector('span');
        const tick = () => {
            const elapsed = (Date.now() - this.startedAt) / 1000;
            if (limit) {
                const left = limit - elapsed;
                span.textContent = clock(left);
                this.timerEl.classList.toggle('is-warning', left <= 30);
                this.timerEl.classList.toggle('is-danger', left <= 10);
                this.timerEl.title = S.timeleft;
                if (left <= 10 && left > 0) {
                    Sound.play('tick');
                }
                if (left <= 0) {
                    window.clearInterval(this.timerHandle);
                    this.timeUp();
                }
            } else {
                span.textContent = clock(elapsed);
                this.timerEl.title = S.time;
            }
        };
        tick();
        this.timerHandle = window.setInterval(tick, 1000);
    }

    /**
     * Time limit reached: submit what we have and finish.
     */
    async timeUp() {
        this.say(S.timeup);
        this.setStatus(S.timeup, 'bad');
        if (this.phase === 'label' && this.slots) {
            await this.submitRound(true);
        }
        if (this.phase === 'quiz' && !this.quizSubmitted) {
            await this.submitQuiz(true);
        }
        this.finish();
    }

    /**
     * In-player confirm dialog.
     *
     * @param {string} message
     * @returns {Promise<boolean>}
     */
    confirm(message) {
        return new Promise((resolve) => {
            const overlay = el('div', 'aa-overlay');
            const box = el('div', 'aa-dialog', {role: 'alertdialog', 'aria-modal': 'true'});
            box.appendChild(el('p', '', {text: message}));
            const row = el('div', 'aa-dialog-actions');
            const no = el('button', 'aa-btn aa-btn-ghost', {type: 'button', text: S.cancel});
            const yes = el('button', 'aa-btn aa-btn-primary', {type: 'button', text: S.confirm});
            row.append(no, yes);
            box.appendChild(row);
            overlay.appendChild(box);
            this.shell.appendChild(overlay);
            yes.focus();
            const done = (v) => {
                overlay.remove();
                resolve(v);
            };
            no.addEventListener('click', () => done(false));
            yes.addEventListener('click', () => done(true));
            overlay.addEventListener('click', (e) => e.target === overlay && done(false));
        });
    }

    /**
     * Exit button: confirm when a test is in progress.
     */
    async requestExit() {
        if (this.mode === 'test' && this.phase !== 'results' && !(await this.confirm(S.confirmexit))) {
            return;
        }
        this.exit();
    }

    /**
     * Updates the voiceover toggle.
     */
    syncVoice() {
        if (this.voiceBtn) {
            const off = this.voice.off;
            this.voiceBtn.innerHTML = icon(off ? 'voiceoff' : 'voice');
            this.voiceBtn.setAttribute('aria-label', off ? S.voiceon : S.voiceoff);
            this.voiceBtn.setAttribute('title', off ? S.voiceon : S.voiceoff);
            this.voiceBtn.setAttribute('aria-pressed', off ? 'false' : 'true');
            this.voiceBtn.classList.toggle('is-playing', this.voice.playing || !!this.voice.preparing);
        }
        if (this.listenBtn) {
            const on = this.voice.playing && !!this.cardListening;
            this.listenBtn.classList.toggle('is-playing', on);
            this.listenBtn.querySelector('span:not(.aa-btnic)').textContent = on ? S.stoplistening : S.listen;
        }
    }

    /**
     * Updates the mute button.
     */
    syncMute() {
        const muted = Sound.isMuted();
        this.muteBtn.innerHTML = icon(muted ? 'muted' : 'sound');
        this.muteBtn.setAttribute('aria-label', muted ? S.unmute : S.mute);
        this.muteBtn.setAttribute('title', muted ? S.unmute : S.mute);
        this.muteBtn.setAttribute('aria-pressed', muted ? 'true' : 'false');
    }

    /**
     * Toggles fullscreen (native, with CSS fallback).
     */
    toggleFullscreen() {
        const fsEl = document.fullscreenElement || document.webkitFullscreenElement;
        if (fsEl || this.shell.classList.contains('is-pseudo-full')) {
            if (fsEl) {
                (document.exitFullscreen || document.webkitExitFullscreen).call(document);
            }
            this.shell.classList.remove('is-pseudo-full');
            document.documentElement.classList.remove('aa-lock-scroll');
            this.syncFullscreen();
            return;
        }
        const req = this.shell.requestFullscreen || this.shell.webkitRequestFullscreen;
        if (req) {
            Promise.resolve(req.call(this.shell)).catch(() => this.pseudoFull());
        } else {
            this.pseudoFull();
        }
    }

    /**
     * CSS fullscreen fallback.
     */
    pseudoFull() {
        this.shell.classList.add('is-pseudo-full');
        document.documentElement.classList.add('aa-lock-scroll');
        this.syncFullscreen();
    }

    /**
     * Updates the fullscreen button.
     */
    syncFullscreen() {
        if (!this.fullBtn || !this.shell) {
            return;
        }
        const on = !!(document.fullscreenElement || document.webkitFullscreenElement) ||
            this.shell.classList.contains('is-pseudo-full');
        this.shell.classList.toggle('is-full', on);
        this.fullBtn.innerHTML = icon(on ? 'unfull' : 'full');
        this.fullBtn.setAttribute('aria-label', on ? S.exitfullscreen : S.fullscreen);
        this.fullBtn.setAttribute('title', on ? S.exitfullscreen : S.fullscreen);
        window.setTimeout(() => {
            if (this.viewer) {
                this.viewer.resize();
            }
            if (this.overlay) {
                this.overlay.layout(false);
            }
        }, 150);
    }

    /**
     * Keyboard shortcuts.
     *
     * @param {KeyboardEvent} e
     */
    onKey(e) {
        if (!this.shell || this.host.hidden || !this.viewer) {
            return;
        }
        if (e.key === 'Escape') {
            if (this.selectedChip) {
                this.clearSelection();
            } else if (this.card) {
                this.studySelect(null);
            }
        }
    }

    /**
     * Scrolls the player into view.
     */
    scrollToShell() {
        const top = this.host.getBoundingClientRect().top;
        if (top < 0 || top > window.innerHeight * 0.4) {
            this.host.scrollIntoView({behavior: REDUCED ? 'auto' : 'smooth', block: 'start'});
        }
    }

    /**
     * Frees the 3D viewer.
     */
    teardown() {
        window.clearInterval(this.timerHandle);
        if (this.voice) {
            this.voice.stop();
        }
        if (this.overlay) {
            this.overlay.destroy();
            this.overlay = null;
        }
        if (this.viewer) {
            this.viewer.dispose();
            this.viewer = null;
        }
        this.card = null;
        this.focusNodes = null;
        this.phase = null;
        this.quizSubmitted = false;
    }

    /**
     * Back to the mode chooser.
     *
     * @param {boolean} reload reload the page (updated scores)
     */
    exit(reload = false) {
        if (document.fullscreenElement || document.webkitFullscreenElement) {
            (document.exitFullscreen || document.webkitExitFullscreen).call(document);
        }
        document.documentElement.classList.remove('aa-lock-scroll');
        this.teardown();
        if (reload) {
            window.location.reload();
            return;
        }
        this.host.replaceChildren();
        this.host.hidden = true;
        this.home.hidden = false;
        this.shell = null;
        this.home.scrollIntoView({behavior: REDUCED ? 'auto' : 'smooth', block: 'start'});
    }
}

/**
 * Initialises the player.
 *
 * @param {string} selector
 */
export const init = async(selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    S = await loadStrings(STRING_KEYS);
    const config = JSON.parse(root.dataset.config);
    root.aaPlayer = new Player(root, config);
};
