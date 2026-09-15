/* =============================================================
   WHERE IS TOBY? - Ermittlungsterminal (Hauptsteuerung)
   ============================================================= */

import {
    bootstrap, el, $, clear, api, post, toast, openOverlay, closeOverlay, isOverlayOpen,
    initOverlay, sound, formatTime, store, assetUrl, paragraphs,
} from './core.js';

import * as panelAkte from './panels/akte.js';
import * as panelPersonen from './panels/personen.js';
import * as panelBeweise from './panels/beweise.js';
import * as panelGeraete from './panels/geraete.js';
import * as panelWand from './panels/wand.js';
import * as panelKarte from './panels/karte.js';
import * as panelZeit from './panels/zeit.js';
import * as panelNotizen from './panels/notizen.js';
import * as panelBericht from './panels/bericht.js';

const PANELS = {
    akte: panelAkte,
    personen: panelPersonen,
    beweise: panelBeweise,
    geraete: panelGeraete,
    wand: panelWand,
    karte: panelKarte,
    zeit: panelZeit,
    notizen: panelNotizen,
    bericht: panelBericht,
};

class Game {
    constructor(config) {
        this.caseId = config.caseId;
        this.state = config.state || {};
        this.agent = config.agent || 'Agent';
        this.settings = Object.assign(
            { volume: 0.7, subtitles: true, effects: true, reduced_motion: false, jumpscares: true, font_scale: 1 },
            config.settings || {}
        );
        this.gameplay = config.gameplay || {};
        this.chatMode = config.chatMode || 'offline';
        this.isAdmin = Boolean(config.isAdmin);
        this.activePanel = store.get('panel:' + this.caseId, 'akte');
        this.root = document.getElementById('panel-root');
        this.sessionSeconds = 0;
        this.panelState = {};
        this.audioOn = store.get('audio', true);
    }

    /* ---------------- Zustand ---------------- */

    setState(state) {
        if (!state) return;
        const previousEvidence = new Set((this.state.progress?.evidence) || []);
        this.state = state;
        this.updateHud();
        (state.progress?.evidence || []).forEach((id) => {
            if (!previousEvidence.has(id)) this.markPanelDot('beweise');
        });
    }

    async refresh() {
        try {
            const data = await api(`/api/case/${this.caseId}/state`);
            this.setState(data.state);
            (data.state?.pending || []).forEach((message) => this.handleProactive(message));
            return data.state;
        } catch (error) {
            toast(error.message, { title: 'Verbindung', kind: 'bad' });
            return null;
        }
    }

    evidenceById(id) {
        return (this.state.evidence || []).find((item) => item.id === id) || null;
    }

    npcById(id) {
        return (this.state.npcs || []).find((item) => item.id === id) || null;
    }

    puzzleById(id) {
        return (this.state.puzzles || []).find((item) => item.id === id) || null;
    }

    hasFlag(flag) {
        return (this.state.progress?.flags || []).includes(flag);
    }

    /* ---------------- Oberflaeche ---------------- */

    updateHud() {
        const progress = this.state.progress || {};
        const evidenceCount = (progress.evidence || []).length;
        const total = this.state.evidence_total || 0;
        const setText = (id, value) => { const node = document.getElementById(id); if (node) node.textContent = value; };
        setText('stat-evidence', `${evidenceCount}/${total}`);
        setText('stat-progress', `${progress.percent ?? 0} %`);

        const hintNode = document.getElementById('hint-count');
        if (hintNode) {
            const left = progress.hints_left;
            hintNode.textContent = left === -1 ? '∞' : String(left ?? 0);
            document.getElementById('btn-hint')?.classList.toggle('is-empty', left === 0);
        }

        this.renderObjectives();
    }

    /**
     * Auftragsleiste: zeigt nur das aktuelle Kapitel und die offenen Punkte daraus.
     * Ohne diesen Wegweiser weiss beim ersten Durchgang niemand, wo er anfangen soll.
     */
    renderObjectives() {
        const box = document.getElementById('brief');
        const list = document.getElementById('brief-list');
        if (!box || !list) return;

        const data = this.state.objectives;
        if (!data) { box.hidden = true; return; }
        box.hidden = false;

        const chapter = document.getElementById('brief-chapter');
        if (chapter) {
            chapter.textContent = data.open.length
                ? `Kapitel ${data.chapter} von ${data.chapters} · ${data.title}`
                : 'Alle Aufgaben erledigt';
        }
        const count = document.getElementById('brief-count');
        if (count) count.textContent = `${data.done}/${data.total}`;

        clear(list);
        if (!data.open.length) {
            list.append(el('li', { class: 'brief__item brief__item--done' }, [
                el('span', { class: 'brief__title' }, ['Der Fall ist bereit zum Abschluss.']),
            ]));
            return;
        }

        data.open.forEach((objective) => {
            const children = [el('span', { class: 'brief__title' }, [objective.title])];
            if (objective.detail) {
                children.push(el('span', { class: 'brief__detail' }, [objective.detail]));
            }
            const item = el('li', { class: 'brief__item' }, [el('div', { class: 'brief__text' }, children)]);
            if (objective.panel && PANELS[objective.panel]) {
                const jump = el('button', { class: 'btn btn--small brief__jump' }, ['Oeffnen']);
                jump.addEventListener('click', () => this.showPanel(objective.panel));
                item.append(jump);
            }
            list.append(item);
        });
    }

    markPanelDot(panelId, on = true) {
        const dot = document.querySelector(`[data-dot="${panelId}"]`);
        if (dot && panelId !== this.activePanel) dot.hidden = !on;
    }

    async showPanel(panelId, options = {}) {
        if (!PANELS[panelId]) return;
        this.activePanel = panelId;
        store.set('panel:' + this.caseId, panelId);

        document.querySelectorAll('.rail__item').forEach((button) => {
            button.classList.toggle('is-active', button.dataset.panel === panelId);
        });
        const dot = document.querySelector(`[data-dot="${panelId}"]`);
        if (dot) dot.hidden = true;

        clear(this.root);
        this.root.append(el('div', { class: 'stage__loading', text: 'Lade Bereich ...' }));
        try {
            const content = await PANELS[panelId].render(this, options);
            clear(this.root);
            this.root.append(content);
            this.root.scrollTop = 0;
        } catch (error) {
            clear(this.root);
            this.root.append(el('div', { class: 'alert alert--error', text: error.message || 'Bereich konnte nicht geladen werden.' }));
        }
        sound.play('ui_open', { gain: 0.35 });
        post(`/api/case/${this.caseId}/event`, { event: 'open_panel', subject: panelId })
            .then((data) => this.handleHorror(data.horror))
            .catch(() => {});
    }

    /* ---------------- Hinweise ---------------- */

    async requestHint() {
        try {
            const data = await post(`/api/case/${this.caseId}/hint`, {});
            if (!data.ok) {
                toast(data.message || 'Kein Hinweis verfuegbar.', { title: 'Hinweis', kind: 'bad' });
                return;
            }
            const levelLabel = ['Andeutung', 'Hinweis', 'Deutliche Hilfe'][data.level - 1] || 'Hinweis';
            openOverlay('Hinweis - ' + levelLabel, el('div', { class: 'stack' }, [
                el('p', { class: 'eyebrow', text: `Stufe ${data.level} von 3${data.title ? ' · ' + data.title : ''}` }),
                el('p', { class: 'lead', text: data.hint }),
                el('p', {
                    class: 'hint',
                    text: data.remaining === -1
                        ? 'Administratormodus: unbegrenzte Hinweise.'
                        : `Verbleibende Hinweise in diesem Fall: ${data.remaining}`,
                }),
            ]));
            const progress = this.state.progress || {};
            progress.hints_left = data.remaining;
            progress.hints_used = (progress.hints_used || 0) + 1;
            this.updateHud();
            sound.play('ui_alert', { gain: 0.3 });
        } catch (error) {
            toast(error.message, { title: 'Hinweis', kind: 'bad' });
        }
    }

    /* ---------------- Horror ---------------- */

    handleHorror(event) {
        if (!event) return;
        if (!this.settings.effects && event.payload.effect !== 'message') return;
        if (event.payload.jumpscare && !this.settings.jumpscares) return;

        const layer = document.getElementById('horror-layer');
        const payload = { ...(event.payload || {}) };
        // Platzhalter fuer den Agentennamen einsetzen (z. B. "{agent}")
        const personalise = (value) => String(value || '').replaceAll('{agent}', this.agent);
        payload.text = personalise(payload.text);
        payload.title = personalise(payload.title);
        const duration = Math.max(1200, Math.min(9000, payload.duration || 2600));

        const finish = () => {
            layer.className = 'horror-layer';
            clear(layer);
        };

        clear(layer);
        layer.className = 'horror-layer';

        const effect = payload.effect || 'flicker';
        if (effect === 'flicker' || effect === 'glitch' || effect === 'blackout') {
            layer.classList.add('is-' + effect);
        }
        if (payload.image) {
            layer.append(el('div', { class: 'horror-face', style: { backgroundImage: `url(${assetUrl(payload.image)})` } }));
        }
        if (payload.title || payload.text) {
            layer.append(el('div', { class: 'horror-msg' }, [
                payload.title ? el('small', { text: payload.title }) : null,
                el('div', { text: payload.text || '' }),
            ]));
        }
        if (payload.sound && this.settings.effects) {
            sound.play(payload.sound, { gain: payload.jumpscare ? 0.9 : 0.55 });
        }
        if (payload.sender) {
            toast(payload.text || '', { title: payload.sender, kind: 'bad', timeout: 8000 });
        }
        setTimeout(finish, duration);

        if (event.type === 'message' || event.type === 'npc') {
            this.markPanelDot('personen');
        }
        if (event.type === 'file') {
            this.markPanelDot('geraete');
        }
        this.refresh();
    }

    handleProactive(message) {
        if (!message) return;
        toast(message.text, { title: 'Neue Nachricht von ' + message.name, kind: 'evidence', timeout: 9000 });
        this.markPanelDot('personen');
        sound.play('ui_alert', { gain: 0.3 });
    }

    /* ---------------- Einstellungen ---------------- */

    applySettings() {
        document.documentElement.style.fontSize = `${Math.round(16 * (this.settings.font_scale || 1))}px`;
        document.body.dataset.reducedMotion = this.settings.reduced_motion ? '1' : '0';
        sound.setVolume(this.settings.volume);
        sound.setEnabled(this.audioOn && this.settings.volume > 0);
        document.querySelector('.grain').style.display = this.settings.effects ? '' : 'none';
        document.querySelector('.crt').style.display = this.settings.effects ? '' : 'none';
    }

    openSettings() {
        const row = (labelText, control, help = '') => el('label', { class: 'stack', style: { gap: '.3rem' } }, [
            el('span', { text: labelText }),
            control,
            help ? el('span', { class: 'hint', text: help }) : null,
        ]);

        const volume = el('input', {
            type: 'range', min: '0', max: '1', step: '.05', value: String(this.settings.volume),
            oninput: (event) => { this.settings.volume = Number(event.target.value); this.applySettings(); },
        });
        const check = (key, label) => el('label', { class: 'check' }, [
            el('input', {
                type: 'checkbox', checked: Boolean(this.settings[key]),
                onchange: (event) => { this.settings[key] = event.target.checked; this.applySettings(); },
            }),
            el('span', { text: label }),
        ]);
        const fontScale = el('input', {
            type: 'range', min: '.85', max: '1.4', step: '.05', value: String(this.settings.font_scale || 1),
            oninput: (event) => { this.settings.font_scale = Number(event.target.value); this.applySettings(); },
        });

        const body = el('div', { class: 'stack' }, [
            row('Lautstaerke', volume, 'Wirkt auf Raumtoene, Sprachnachrichten und Effekte.'),
            check('subtitles', 'Untertitel und Transkripte anzeigen'),
            check('effects', 'Bildeffekte, Filmkorn und Horror-Einblendungen'),
            check('jumpscares', 'Schreckmomente zulassen (Lautstaerkeschutz bleibt aktiv)'),
            check('reduced_motion', 'Bewegung reduzieren'),
            row('Schriftgroesse', fontScale),
            el('div', { class: 'toolbar' }, [
                el('button', {
                    class: 'btn btn--primary',
                    text: 'Speichern',
                    onclick: async () => {
                        try {
                            await post('/api/settings', { settings: this.settings });
                            toast('Einstellungen gespeichert.', { kind: 'good' });
                            closeOverlay();
                        } catch (error) {
                            toast(error.message, { kind: 'bad' });
                        }
                    },
                }),
                el('button', { class: 'btn', text: 'Fall neu starten', onclick: () => this.confirmReset() }),
            ]),
            el('p', { class: 'hint', text: 'Tastatur: 1-9 Bereiche, H Hinweis, N Notiz, Esc schliesst Fenster.' }),
        ]);
        openOverlay('Einstellungen', body);
    }

    confirmReset() {
        const body = el('div', { class: 'stack' }, [
            el('p', { text: 'Der gesamte Fortschritt in diesem Fall wird geloescht: Beweise, Gespraeche, Wand, Notizen und Bericht.' }),
            el('div', { class: 'toolbar' }, [
                el('button', {
                    class: 'btn btn--danger', text: 'Ja, Fall zuruecksetzen',
                    onclick: async () => {
                        try {
                            const data = await post(`/api/case/${this.caseId}/reset`, {});
                            this.setState(data.state);
                            closeOverlay();
                            this.showPanel('akte');
                            toast('Fall zurueckgesetzt.', { kind: 'good' });
                        } catch (error) {
                            toast(error.message, { kind: 'bad' });
                        }
                    },
                }),
                el('button', { class: 'btn', text: 'Abbrechen', onclick: () => closeOverlay() }),
            ]),
        ]);
        openOverlay('Fall zuruecksetzen', body);
    }

    /* ---------------- Start ---------------- */

    async boot() {
        initOverlay();
        this.applySettings();
        this.updateHud();

        document.querySelectorAll('.rail__item').forEach((button) => {
            button.addEventListener('click', () => this.showPanel(button.dataset.panel));
        });
        document.getElementById('btn-hint')?.addEventListener('click', () => this.requestHint());

        const briefToggle = document.getElementById('brief-toggle');
        briefToggle?.addEventListener('click', () => {
            const box = document.getElementById('brief');
            const collapsed = box?.classList.toggle('is-collapsed') ?? false;
            briefToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            store.set('brief-collapsed', collapsed);
        });
        if (store.get('brief-collapsed', false) === true) {
            document.getElementById('brief')?.classList.add('is-collapsed');
            briefToggle?.setAttribute('aria-expanded', 'false');
        }
        document.getElementById('btn-settings')?.addEventListener('click', () => this.openSettings());
        document.getElementById('btn-audio')?.addEventListener('click', () => {
            this.audioOn = !this.audioOn;
            store.set('audio', this.audioOn);
            this.applySettings();
            if (this.audioOn) sound.startAmbience(); else sound.stopAmbience();
            toast(this.audioOn ? 'Ton eingeschaltet.' : 'Ton ausgeschaltet.');
        });

        document.addEventListener('keydown', (event) => {
            if (event.target.matches('input, textarea, select, [contenteditable]')) return;
            if (event.key === 'Escape') { closeOverlay(); return; }
            if (isOverlayOpen()) return;
            const panelKeys = ['akte', 'personen', 'beweise', 'geraete', 'wand', 'karte', 'zeit', 'notizen', 'bericht'];
            const index = Number(event.key) - 1;
            if (index >= 0 && index < panelKeys.length) { this.showPanel(panelKeys[index]); return; }
            if (event.key.toLowerCase() === 'h') this.requestHint();
            if (event.key.toLowerCase() === 'n') this.showPanel('notizen', { compose: true });
        });

        // Ton erst nach der ersten Nutzerinteraktion starten (Browser-Vorgabe)
        const startAudio = () => {
            if (this.audioOn) sound.startAmbience();
            document.removeEventListener('pointerdown', startAudio);
            document.removeEventListener('keydown', startAudio);
        };
        document.addEventListener('pointerdown', startAudio);
        document.addEventListener('keydown', startAudio);

        // Altersfreigabe
        const gate = document.getElementById('age-gate');
        if (gate) {
            document.getElementById('age-accept')?.addEventListener('click', async () => {
                try { await post('/api/age-confirm', {}); } catch { /* egal */ }
                gate.remove();
                this.maybeShowIntro();
            });
        } else {
            this.maybeShowIntro();
        }

        // Spielzeit melden
        const beat = Math.max(5, Number(this.gameplay.autosave || 20));
        setInterval(async () => {
            if (document.hidden) return;
            this.sessionSeconds += beat;
            const node = document.getElementById('stat-time');
            if (node) node.textContent = formatTime((this.state.progress?.playtime || 0) + this.sessionSeconds);
            try {
                const data = await post(`/api/case/${this.caseId}/heartbeat`, { seconds: beat });
                (data.pending || []).forEach((message) => this.handleProactive(message));
            } catch { /* offline, spaeter erneut */ }
        }, beat * 1000);

        const timeNode = document.getElementById('stat-time');
        if (timeNode) timeNode.textContent = formatTime(this.state.progress?.playtime || 0);

        await this.showPanel(this.activePanel);
    }

    maybeShowIntro() {
        const intro = document.getElementById('intro');
        const introData = this.state.case?.intro || {};
        if (!intro || this.state.progress?.intro_done || !introData.lines) {
            if (intro) intro.remove();
            return;
        }
        intro.hidden = false;
        document.getElementById('intro-title').textContent = introData.title || 'Einsatz';
        const body = document.getElementById('intro-body');
        clear(body);
        body.append(el('div', { class: 'typed' }, [(introData.lines || []).join('\n\n')]));
        document.getElementById('intro-start')?.addEventListener('click', async () => {
            intro.hidden = true;
            try {
                const data = await post(`/api/case/${this.caseId}/start`, {});
                this.setState(data.state);
            } catch { /* Fortschritt bleibt bestehen */ }
            this.showPanel('akte');
        });
    }
}

const game = new Game(bootstrap);
window.witGame = game;
game.boot();

export default game;
