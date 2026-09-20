/**
 * Angriffsablauf: Ziel wählen → Mission wählen → Truppe zusammenstellen →
 * kurze interaktive 2D-Mission → Auswertung durch den Server.
 */

import * as api from '../core/api.js';
import { sfx } from '../core/audio.js';
import { haptics } from '../core/haptics.js';
import { compact, duration, full } from '../core/num.js';
import { applyResult, state } from '../core/state.js';
import { alpha, ellipse, roundRect, shade } from '../render/draw.js';
import { costList, emptyNote, escapeHtml, listItem, pill, statGrid } from '../ui/parts.js';
import { closeSheet, openSheet, sheetBody } from '../ui/sheet.js';
import { toast, toastError } from '../ui/toast.js';
import { FIELD_HEIGHT, FIELD_WIDTH, LANES, MissionSim, OBJECTIVE_X } from './mission.js';

let sim = null;
let raf = null;
let attackId = null;
let replayMode = false;

// ===================================================================
// Vorbereitung
// ===================================================================

export async function openAttackPlanner() {
    openSheet({ key: 'attack', title: 'Angriff planen', body: '<p class="sk-muted">Suche passende Gegner …</p>' });

    try {
        const response = await api.get('attack_targets');
        const body = sheetBody();
        if (!body) { return; }

        body.innerHTML = `
            <p class="sk-muted">Gegner mit ähnlicher Stärke. Neulinge und frisch Angegriffene
                sind geschützt, Allianzmitglieder ebenso.</p>
            <ul class="sk-list">${response.targets.map((target) => listItem({
                action: true,
                attrs: `data-target="${target.id}"`,
                title: escapeHtml(target.name),
                sub: `Stufe ${target.level} · ${compact(target.score)} Punkte · ${target.islands} Inseln`,
                right: pill(target.army + ' Truppen', 'muted')
            })).join('') || emptyNote('Gerade ist kein passender Gegner verfügbar. Versuch es später erneut.')}</ul>`;

        body.querySelectorAll('[data-target]').forEach((element) => {
            element.addEventListener('click', () => chooseMission(element.dataset.target,
                element.querySelector('.sk-list__title').textContent));
        });
    } catch (error) { toastError(error); }
}

function chooseMission(targetId, targetName) {
    const missions = state.statics.missions;

    openSheet({
        key: 'mission',
        title: 'Missionsziel',
        subtitle: 'Angriff auf ' + escapeHtml(targetName),
        body: `<ul class="sk-list">${Object.keys(missions).map((key) => listItem({
            action: true,
            attrs: `data-mission="${key}"`,
            title: escapeHtml(missions[key].name),
            sub: escapeHtml(missions[key].desc),
            right: pill(Math.round(missions[key].loot * 100) + ' % Beute', 'gold')
        })).join('')}</ul>`,
        onMount(body) {
            body.querySelectorAll('[data-mission]').forEach((element) => {
                element.addEventListener('click', () => chooseSquad(targetId, targetName, element.dataset.mission));
            });
        }
    });
}

function chooseSquad(targetId, targetName, missionKey) {
    const units = state.statics.units;
    const owned = state.world.units || {};
    const available = Object.keys(owned).filter((key) => owned[key] > 0);

    if (available.length === 0) {
        openSheet({
            key: 'squad',
            title: 'Keine Truppen',
            body: `<div class="sk-alert sk-alert--warn"><span>Du hast noch keine Einheiten.
                Baue eine Kaserne und bilde Truppen aus.</span></div>`
        });
        return;
    }

    openSheet({
        key: 'squad',
        title: 'Truppe zusammenstellen',
        subtitle: escapeHtml(state.statics.missions[missionKey].name) + ' · ' + escapeHtml(targetName),
        body: `
            <p class="sk-muted">Höchstens 20 Einheiten. Plünderer tragen viel,
                Pioniere brechen Mauern, Späher sind schnell.</p>
            <ul class="sk-list">${available.map((key) => `
                <li class="sk-list__item">
                    <div class="sk-grow">
                        <div class="sk-list__title">${escapeHtml(units[key].name)}</div>
                        <div class="sk-list__sub">${compact(units[key].hp)} LP · ${compact(units[key].damage)} Schaden
                            · trägt ${compact(units[key].carry)}</div>
                    </div>
                    <div class="sk-list__right">
                        <input type="number" min="0" max="${owned[key]}" value="0" data-unit="${key}"
                            inputmode="numeric" style="width:78px;min-height:44px;text-align:center;border-radius:10px;
                            border:1.5px solid rgba(16,26,46,.12)">
                        <div class="sk-list__sub">von ${owned[key]}</div>
                    </div>
                </li>`).join('')}</ul>
            <button class="sk-btn sk-btn--block sk-btn--danger" data-action="start">Angriff starten</button>`,
        onMount(body) {
            body.querySelector('[data-action="start"]').addEventListener('click', async () => {
                const squad = {};
                let total = 0;
                body.querySelectorAll('[data-unit]').forEach((input) => {
                    const count = Math.max(0, Math.min(Number(input.value) || 0, Number(input.max)));
                    if (count > 0) { squad[input.dataset.unit] = count; total += count; }
                });
                if (total === 0) { toast('Wähle mindestens eine Einheit.', 'bad'); return; }
                if (total > 20) { toast('Höchstens 20 Einheiten je Angriff.', 'bad'); return; }

                try {
                    const response = await api.post('attack_start', {
                        target: targetId,
                        mission: missionKey,
                        squad: JSON.stringify(squad)
                    });
                    closeSheet();
                    startBattle(response);
                } catch (error) { toastError(error); }
            });
        }
    });
}

// ===================================================================
// Die Mission
// ===================================================================

function startBattle(response) {
    attackId = response.attack;
    replayMode = false;

    const setup = response.setup;
    setup.bonus = 1.15;
    sim = new MissionSim(setup, response.units, state.statics.abilities);

    showBattleScreen(response.mission ? response.mission.name : 'Mission');
    sfx.battle();
    haptics.medium();
    loop();
}

export function startReplay(report) {
    if (!report.replay) { return; }
    attackId = null;
    replayMode = true;

    const setup = report.replay.setup;
    setup.bonus = 1.15;
    const units = (report.replay.units || []).map((unit) => ({
        type: unit.type, hp: unit.maxHp, maxHp: unit.maxHp,
        damage: 10, speed: 1.6, carry: 20, struct: 1
    }));

    sim = new MissionSim(setup, units, state.statics.abilities);
    sim.replayActions = (report.replay.actions || []).slice();

    showBattleScreen('Wiederholung: ' + report.mission_name);
    loop();
}

function showBattleScreen(title) {
    const screen = document.getElementById('sk-battle');
    screen.classList.add('is-open');

    const abilities = state.statics.abilities || {};
    const host = document.getElementById('sk-battle-abilities');
    host.innerHTML = Object.keys(abilities).map((key) => `
        <button class="sk-ability" data-ability="${key}" title="${escapeHtml(abilities[key].desc || '')}">
            <span>${escapeHtml(abilities[key].name)}</span>
            <span class="sk-ability__cd" data-cd="${key}" style="height:0"></span>
        </button>`).join('');

    host.querySelectorAll('[data-ability]').forEach((button) => {
        button.addEventListener('click', () => {
            if (replayMode || !sim) { return; }
            if (sim.act('ability', button.dataset.ability)) {
                sfx.hit();
                haptics.light();
            }
        });
    });

    document.getElementById('sk-battle-quit').onclick = () => endBattle(true);

    const canvas = document.getElementById('sk-battle-canvas');
    canvas.onpointerdown = (event) => {
        if (replayMode || !sim) { return; }
        const rect = canvas.getBoundingClientRect();
        const y = (event.clientY - rect.top) / rect.height * FIELD_HEIGHT;
        let lane = 0;
        let best = Infinity;
        LANES.forEach((laneY, index) => {
            const distance = Math.abs(laneY - y);
            if (distance < best) { best = distance; lane = index; }
        });
        if (sim.act('lane', lane)) { sfx.tap(); haptics.light(); }
    };

    document.getElementById('sk-battle-timer').textContent = title;
}

function loop() {
    const canvas = document.getElementById('sk-battle-canvas');
    const ctx = canvas.getContext('2d');
    let last = performance.now();
    let accumulator = 0;

    const frame = (time) => {
        const dt = Math.min(0.1, (time - last) / 1000);
        last = time;
        accumulator += dt;

        while (accumulator >= 0.1 && sim && !sim.finished) {
            accumulator -= 0.1;

            // Wiederholung: aufgezeichnete Entscheidungen einspielen
            if (replayMode && sim.replayActions) {
                while (sim.replayActions.length && Number(sim.replayActions[0].t) <= sim.tick) {
                    const action = sim.replayActions.shift();
                    if (action.a === 'lane') { sim.lane = Number(action.v); }
                    else if (action.a === 'ability') {
                        const def = state.statics.abilities[action.v];
                        if (def) { sim.active[action.v] = def.duration || 40; }
                    }
                }
            }
            sim.step();
        }

        drawBattle(ctx, canvas);
        updateBattleHud();

        if (sim && sim.finished) {
            finishBattle();
            return;
        }
        raf = requestAnimationFrame(frame);
    };

    raf = requestAnimationFrame(frame);
}

function updateBattleHud() {
    if (!sim) { return; }
    document.getElementById('sk-battle-timer').textContent = sim.timeLeft + ' s';
    document.getElementById('sk-battle-squad').textContent = sim.aliveCount() + ' Einheiten';

    Object.keys(state.statics.abilities || {}).forEach((key) => {
        const element = document.querySelector(`[data-cd="${key}"]`);
        const button = document.querySelector(`[data-ability="${key}"]`);
        if (!element || !button) { return; }
        const progress = sim.abilityProgress(key);
        element.style.height = (progress * 100) + '%';
        button.classList.toggle('is-cooldown', progress > 0);
    });
}

function drawBattle(ctx, canvas) {
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;
    if (canvas.width !== Math.floor(width * dpr)) {
        canvas.width = Math.floor(width * dpr);
        canvas.height = Math.floor(height * dpr);
    }
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    const sx = width / FIELD_WIDTH;
    const sy = height / FIELD_HEIGHT;
    const X = (v) => v * sx;
    const Y = (v) => v * sy;

    // Hintergrund
    const sky = ctx.createLinearGradient(0, 0, 0, height);
    sky.addColorStop(0, '#16233c');
    sky.addColorStop(1, '#25405f');
    ctx.fillStyle = sky;
    ctx.fillRect(0, 0, width, height);

    // Spuren
    LANES.forEach((laneY, index) => {
        ctx.fillStyle = index === (sim ? sim.lane : 1) ? 'rgba(126,227,166,.16)' : 'rgba(255,255,255,.05)';
        ctx.fillRect(0, Y(laneY - 8), width, Y(16));
        ctx.strokeStyle = 'rgba(255,255,255,.12)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(0, Y(laneY));
        ctx.lineTo(width, Y(laneY));
        ctx.stroke();
    });

    if (!sim) { return; }

    // Ziel
    ctx.fillStyle = '#ffc94a';
    roundRect(ctx, X(OBJECTIVE_X), 0, X(FIELD_WIDTH - OBJECTIVE_X), height, 8);
    ctx.globalAlpha = 0.35;
    ctx.fill();
    ctx.globalAlpha = 1;
    ctx.font = '700 13px system-ui, sans-serif';
    ctx.fillStyle = '#ffc94a';
    ctx.save();
    ctx.translate(X(OBJECTIVE_X + 4), height / 2);
    ctx.rotate(-Math.PI / 2);
    ctx.textAlign = 'center';
    ctx.fillText('ZIEL', 0, 0);
    ctx.restore();

    // Mauern
    sim.walls.forEach((wall) => {
        if (wall.hp <= 0) { return; }
        ctx.fillStyle = '#bdbcb4';
        roundRect(ctx, X(wall.x - 1), Y(LANES[wall.lane] - 7), X(2.2), Y(14), 4);
        ctx.fill();
        ctx.fillStyle = '#8d8c85';
        for (let i = 0; i < 3; i++) {
            ctx.fillRect(X(wall.x - 1), Y(LANES[wall.lane] - 7 + i * 5), X(2.2), Y(1));
        }
    });

    // Türme
    sim.towers.forEach((tower) => {
        if (tower.hp <= 0) { return; }
        const tx = X(tower.x);
        const ty = Y(LANES[tower.lane]) - Y(9);
        ctx.fillStyle = '#cdc9bd';
        roundRect(ctx, tx - X(1.4), ty, X(2.8), Y(11), 3);
        ctx.fill();
        ctx.fillStyle = '#3f7fbf';
        ctx.beginPath();
        ctx.moveTo(tx - X(2), ty);
        ctx.lineTo(tx + X(2), ty);
        ctx.lineTo(tx, ty - Y(5));
        ctx.closePath();
        ctx.fill();
    });

    // Patrouillen
    sim.patrols.forEach((patrol) => {
        if (patrol.hp <= 0) { return; }
        ctx.fillStyle = '#ff9f4a';
        ctx.beginPath();
        ctx.arc(X(patrol.x), Y(LANES[patrol.lane]), Math.max(4, X(1.4)), 0, Math.PI * 2);
        ctx.fill();
    });

    // Schüsse
    sim.shots.forEach((shot) => {
        ctx.strokeStyle = 'rgba(255,200,90,.8)';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(X(shot.x), Y(LANES[shot.lane]) - Y(6));
        ctx.lineTo(X(sim.x), Y(LANES[sim.lane]));
        ctx.stroke();
    });

    // Eigene Truppe
    const alive = sim.units.filter((unit) => unit.hp > 0);
    alive.forEach((unit, index) => {
        const offset = (index % 5 - 2) * 1.6;
        const row = Math.floor(index / 5) * 2.2;
        const ux = X(sim.x - row);
        const uy = Y(LANES[sim.lane] + offset);

        ctx.globalAlpha = 0.25;
        ellipse(ctx, ux, uy + 6, 7, 3, '#000000');
        ctx.globalAlpha = 1;

        ctx.fillStyle = '#3f8cff';
        roundRect(ctx, ux - 4, uy - 7, 8, 11, 3);
        ctx.fill();
        ctx.fillStyle = '#f0c9a0';
        ctx.beginPath();
        ctx.arc(ux, uy - 10, 4, 0, Math.PI * 2);
        ctx.fill();

        // Lebensbalken
        const ratio = Math.max(0, unit.hp / unit.maxHp);
        ctx.fillStyle = 'rgba(0,0,0,.5)';
        ctx.fillRect(ux - 6, uy - 17, 12, 3);
        ctx.fillStyle = ratio > 0.5 ? '#7ee3a6' : (ratio > 0.25 ? '#ffb43f' : '#ff5c6c');
        ctx.fillRect(ux - 6, uy - 17, 12 * ratio, 3);
    });

    // Fortschritt
    ctx.fillStyle = 'rgba(255,255,255,.14)';
    ctx.fillRect(0, height - 6, width, 6);
    ctx.fillStyle = '#7ee3a6';
    ctx.fillRect(0, height - 6, width * Math.max(0, (sim.x - 2) / (OBJECTIVE_X - 2)), 6);

    // Hinweis auf aktive Fähigkeiten
    const active = Object.keys(sim.active);
    if (active.length) {
        ctx.font = '600 12px system-ui, sans-serif';
        ctx.fillStyle = '#7ee3a6';
        ctx.fillText(active.map((key) => (state.statics.abilities[key] || {}).name || key).join(' · '), 12, 22);
    }
}

async function finishBattle() {
    if (raf) { cancelAnimationFrame(raf); raf = null; }
    const result = sim ? sim.result : null;

    if (replayMode || !attackId) {
        setTimeout(() => endBattle(false), 900);
        return;
    }

    try {
        const response = await api.post('attack_finish', {
            attack: attackId,
            actions: JSON.stringify(sim.actions),
            result: JSON.stringify(result)
        });

        endBattle(false);
        showOutcome(response);
    } catch (error) {
        endBattle(false);
        toastError(error);
    }
}

function showOutcome(response) {
    const result = response.result;
    const loot = Object.keys(response.loot || {});

    if (result.success) { sfx.success(); haptics.success(); } else { sfx.error(); haptics.error(); }

    openSheet({
        key: 'battle-result',
        title: result.success ? 'Mission erfolgreich' : 'Angriff gescheitert',
        body: `
            <div class="sk-alert sk-alert--${result.success ? 'ok' : 'error'}">
                <span>${result.success
                    ? 'Deine Truppe hat das Ziel erreicht.'
                    : (result.reason === 'defeated' ? 'Deine Truppe wurde aufgerieben.' : 'Die Zeit war zu knapp.')}
                </span>
            </div>
            ${statGrid([
                { label: 'Fortschritt', value: Math.round(result.progress * 100) + ' %' },
                { label: 'Überlebende', value: full(result.survivors) },
                { label: 'Dauer', value: duration(Math.round(result.ticks / 10)) }
            ])}
            ${loot.length ? '<h3>Beute</h3>' + costList(response.loot) : '<p class="sk-muted">Keine Beute.</p>'}
            ${response.intel ? '<h3>Aufklärung</h3>' + costList(response.intel.store) : ''}
            ${Object.keys(result.losses || {}).length
                ? '<h3>Verluste</h3><p class="sk-muted">' + Object.keys(result.losses).map((key) =>
                    `${result.losses[key]}× ${escapeHtml(state.statics.units[key] ? state.statics.units[key].name : key)}`).join(', ') + '</p>'
                : '<p class="sk-muted">Keine Verluste.</p>'}
        `
    });

    api.get('state').then((data) => {
        applyResult({ patch: { store: data.world.store }, effects: data.effects, rates: data.rates });
    }).catch(() => {});
}

function endBattle(aborted) {
    if (raf) { cancelAnimationFrame(raf); raf = null; }
    document.getElementById('sk-battle').classList.remove('is-open');

    if (aborted && attackId && !replayMode && sim) {
        // Abbruch zählt als gescheiterter Versuch – der Server wertet aus.
        api.post('attack_finish', {
            attack: attackId,
            actions: JSON.stringify(sim.actions),
            result: JSON.stringify(sim.result || { progress: 0 })
        }).catch(() => {});
    }

    sim = null;
    attackId = null;
    replayMode = false;
}
