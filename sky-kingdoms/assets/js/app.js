/**
 * Sky Kingdoms – Einstiegspunkt der Spieloberfläche.
 *
 * Holt den Spielstand, baut die Welt auf, verbindet Bedienung und Anzeige.
 */

import * as api from '../js/core/api.js';
import { setEnabled as setAudio, setMusic, sfx, unlock as unlockAudio } from './core/audio.js';
import { setEnabled as setHaptics, haptics } from './core/haptics.js';
import { compact, duration, full } from './core/num.js';
import {
    applyResult, buildingsOn, emit as stateEmit, resourceName,
    state, subscribe
} from './core/state.js';
import { setState } from './core/state.js';
import { Camera } from './render/camera.js';
import { drawBuilding } from './render/buildings.js';
import { resourceIcon } from './render/icons.js';
import { CELL, SPACING, SQUASH, WorldRenderer } from './render/world.js';
import { openAttackPlanner, startReplay } from './combat/battle.js';
import { on } from './ui/bus.js';
import {
    openArmy, openBuildCatalog, openBuilding, openBridge, openIslands,
    openLogistics, openMore, openNotifications, openQuests, openRanking,
    openResearch, openRoute, openStorage
} from './ui/panels.js';
import { closeSheet, initSheet, openSheet } from './ui/sheet.js';
import { openAlliance, openReport, openReports, openSettings, openTrade } from './ui/social.js';
import { costList, escapeHtml, statGrid } from './ui/parts.js';
import { toast, toastError } from './ui/toast.js';

const canvas = document.getElementById('sk-canvas');
const camera = new Camera(canvas);
const renderer = new WorldRenderer(canvas, camera);

let placement = null;   // { type, island, cell }
let moving = null;      // Gebäude wird verschoben
let lastFrame = performance.now();
let refreshTimer = null;

// ===================================================================
// Start
// ===================================================================

async function boot() {
    progress(10, 'Spieldaten werden geladen …');

    try {
        const [statics, data] = await Promise.all([
            api.get('static'),
            api.get('state')
        ]);

        state.statics = statics.data;
        state.statics.shopPacks = state.statics.shopPacks || {};
        progress(55, 'Königreich wird aufgebaut …');

        setState(data);

        applySettings((state.profile && state.profile.settings) || {});
        detectQuality();

        progress(80, 'Inseln werden gezeichnet …');

        setupCanvas();
        setupUi();

        // Erste Anzeige von Hand auslösen – subscribe() greift erst ab jetzt.
        renderResources();
        renderProfile();
        renderIslandButtons();
        renderBadges();

        focusIsland(state.island, true);

        progress(100, 'Fertig!');
        setTimeout(hideLoader, 260);

        if (data.welcome) { showWelcome(data.welcome); }

        startLoop();
        startRefresh();
        registerServiceWorker();
    } catch (error) {
        progress(100, 'Fehler beim Laden');
        toastError(error);
        setTimeout(hideLoader, 800);
    }
}

function progress(value, text) {
    const fill = document.getElementById('sk-loader-fill');
    const label = document.getElementById('sk-loader-text');
    if (fill) { fill.style.width = value + '%'; }
    if (label && text) { label.textContent = text; }
}

function hideLoader() {
    const loader = document.getElementById('sk-loader');
    if (loader) {
        loader.classList.add('is-gone');
        setTimeout(() => loader.remove(), 500);
    }
}

// ===================================================================
// Canvas und Kamera
// ===================================================================

function setupCanvas() {
    const resize = () => {
        const dpr = Math.min(window.devicePixelRatio || 1, renderer.quality >= 3 ? 1.5 : 2);
        const width = canvas.clientWidth || window.innerWidth;
        const height = canvas.clientHeight || window.innerHeight;
        canvas.width = Math.floor(width * dpr);
        canvas.height = Math.floor(height * dpr);
        renderer.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        camera.resize(width, height, dpr);
    };

    resize();
    window.addEventListener('resize', () => setTimeout(resize, 60));
    window.addEventListener('orientationchange', () => setTimeout(resize, 220));

    camera.attach();
    camera.onTap = handleTap;
    camera.onLongPress = handleLongPress;
    camera.onDragMove = (world) => {
        if (!placement && !moving) { return; }
        updateGhost(world);
    };
}

function handleTap(world) {
    unlockAudio();

    // Baumodus: Feld wählen
    if (placement || moving) {
        updateGhost(world);
        return;
    }

    // Gebäude getroffen?
    const hit = findBuilding(world);
    if (hit) {
        renderer.selected = hit.id;
        sfx.tap();
        haptics.light();
        openBuilding(hit.id);
        return;
    }

    // Brücke getroffen?
    const bridge = findBridge(world);
    if (bridge) {
        sfx.tap();
        openBridge(bridge.id);
        return;
    }

    // Insel getroffen? → dorthin wechseln
    const island = findIsland(world);
    if (island && island.id !== state.island) {
        focusIsland(island.id);
        return;
    }

    renderer.selected = null;
}

function handleLongPress(world) {
    const hit = findBuilding(world);
    if (hit) {
        haptics.heavy();
        startMove(hit.id);
    }
}

function findBuilding(world) {
    let best = null;
    let bestDistance = Infinity;

    Object.values(state.world.buildings).forEach((building) => {
        const island = state.world.islands[building.island];
        if (!island) { return; }
        const def = state.statics.buildings[building.type];
        const [bw, bh] = def ? def.size : [1, 1];
        const position = renderer.cellToWorld(island, building.x + (bw - 1) / 2, building.y + (bh - 1) / 2);
        const dx = Math.abs(world.x - position.x);
        const dy = Math.abs(world.y - position.y);
        if (dx < (bw * CELL) / 2 + 6 && dy < (bh * CELL * SQUASH) / 2 + 14) {
            const distance = dx + dy;
            if (distance < bestDistance) { best = building; bestDistance = distance; }
        }
    });

    return best;
}

function findBridge(world) {
    let best = null;
    Object.values(state.world.bridges).forEach((bridge) => {
        const ends = renderer.bridgeEnds(bridge);
        if (!ends) { return; }
        const distance = distanceToSegment(world, ends.from, ends.to);
        if (distance < 18) { best = bridge; }
    });
    return best;
}

function distanceToSegment(point, a, b) {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const lengthSquared = dx * dx + dy * dy;
    if (lengthSquared === 0) { return Math.hypot(point.x - a.x, point.y - a.y); }
    let t = ((point.x - a.x) * dx + (point.y - a.y) * dy) / lengthSquared;
    t = Math.max(0, Math.min(1, t));
    return Math.hypot(point.x - (a.x + t * dx), point.y - (a.y + t * dy));
}

function findIsland(world) {
    let best = null;
    let bestDistance = Infinity;

    Object.values(state.world.islands).forEach((island) => {
        const center = renderer.islandCenter(island);
        const def = state.statics.islandTypes[island.type];
        const [cols, rows] = def.grid;
        const dx = Math.abs(world.x - center.x);
        const dy = Math.abs(world.y - center.y);
        if (dx < (cols * CELL) / 2 + 20 && dy < (rows * CELL * SQUASH) / 2 + 20) {
            const distance = dx + dy;
            if (distance < bestDistance) { best = island; bestDistance = distance; }
        }
    });

    return best;
}

function focusIsland(islandId, instant) {
    const island = state.world.islands[islandId];
    if (!island) { return; }

    state.island = islandId;
    const center = renderer.islandCenter(island);
    if (instant) {
        camera.moveTo(center.x, center.y, 0.58);
    } else {
        camera.glideTo(center.x, center.y, Math.max(camera.zoom, 0.9));
    }
    renderIslandButtons();
}

// ===================================================================
// Bauen und Verschieben
// ===================================================================

function startPlacement(type, islandId) {
    placement = { type, island: islandId || state.island, cell: null };
    renderer.buildMode = true;
    focusIsland(placement.island);
    showBuildBar(state.statics.buildings[type].name, 'Tippe auf ein freies Feld.');
}

function startMove(buildingId) {
    const building = state.world.buildings[buildingId];
    if (!building) { return; }
    moving = { id: buildingId, island: building.island, cell: null };
    renderer.buildMode = true;
    renderer.selected = buildingId;
    focusIsland(building.island);
    showBuildBar('Verschieben', 'Tippe auf das neue Feld.');
}

function updateGhost(world) {
    const context = placement || moving;
    if (!context) { return; }

    const island = state.world.islands[context.island];
    const cell = renderer.worldToCell(island, world.x, world.y);
    if (!cell) { return; }

    context.cell = cell;
    renderer.buildGhost = {
        island: island.id,
        type: placement ? placement.type : state.world.buildings[moving.id].type,
        x: cell.x,
        y: cell.y
    };

    const type = renderer.buildGhost.type;
    const def = state.statics.buildings[type];
    const ok = canPlaceHere(island, def, cell.x, cell.y, moving ? moving.id : null);
    showBuildBar(def.name, ok ? 'Feld ' + (cell.x + 1) + '/' + (cell.y + 1) + ' – frei' : 'Dieses Feld geht nicht.');
    document.getElementById('sk-build-confirm').classList.toggle('is-disabled', !ok);
}

function canPlaceHere(island, def, x, y, ignoreId) {
    const [bw, bh] = def.size;
    for (let dx = 0; dx < bw; dx++) {
        for (let dy = 0; dy < bh; dy++) {
            if (!renderer.isBuildable(island, x + dx, y + dy)) { return false; }
        }
    }
    return !buildingsOn(island.id).some((other) => {
        if (other.id === ignoreId) { return false; }
        const otherDef = state.statics.buildings[other.type];
        const [ow, oh] = otherDef ? otherDef.size : [1, 1];
        return x < other.x + ow && x + bw > other.x && y < other.y + oh && y + bh > other.y;
    });
}

function showBuildBar(title, hint) {
    const bar = document.getElementById('sk-buildbar');
    bar.classList.add('is-open');
    document.getElementById('sk-buildbar-info').innerHTML =
        `<strong>${escapeHtml(title)}</strong>${escapeHtml(hint)}`;
}

function endBuildMode() {
    placement = null;
    moving = null;
    renderer.buildMode = false;
    renderer.buildGhost = null;
    document.getElementById('sk-buildbar').classList.remove('is-open');
}

async function confirmBuild() {
    const context = placement || moving;
    if (!context || !context.cell) {
        toast('Bitte zuerst ein Feld antippen.', 'bad');
        return;
    }

    try {
        if (placement) {
            const result = await api.post('build', {
                island: placement.island,
                type: placement.type,
                x: context.cell.x,
                y: context.cell.y
            });
            applyResult(result);
            sfx.build();
            haptics.success();
            toast('Gebaut!', 'ok');
            burstAt(placement.island, context.cell, '#7ee3a6');
        } else {
            const result = await api.post('move', {
                id: moving.id,
                x: context.cell.x,
                y: context.cell.y
            });
            applyResult(result);
            sfx.tap();
            toast('Verschoben.', 'ok');
        }
        endBuildMode();
    } catch (error) {
        toastError(error);
    }
}

function burstAt(islandId, cell, color) {
    const island = state.world.islands[islandId];
    if (!island) { return; }
    const position = renderer.cellToWorld(island, cell.x, cell.y);
    renderer.burst(position.x, position.y, color, 16);
}

// ===================================================================
// Anzeige
// ===================================================================

function renderResources() {
    const host = document.getElementById('sk-resources');
    if (!host || !state.world) { return; }

    const resources = Object.keys(state.statics.resources)
        .filter((key) => state.statics.resources[key].hud || (state.world.store[key] || 0) > 0)
        .sort((a, b) => state.statics.resources[a].order - state.statics.resources[b].order);

    const storage = (state.effects && state.effects.storage) || {};
    const rates = (state.rates && state.rates.delivery) || {};

    host.innerHTML = resources.map((key) => {
        const def = state.statics.resources[key];
        const classInfo = storage[def.class] || {};
        const amount = state.world.store[key] || 0;
        const inflow = rates[key] || 0;
        return `<button class="sk-res__item${classInfo.full ? ' is-full' : ''}" data-res="${key}"
                    title="${escapeHtml(def.name)}${classInfo.full ? ' – Lager voll!' : ''}">
            <span class="sk-res__icon">${resourceIcon(key, 24)}</span>
            <span class="sk-res__value">${compact(amount)}</span>
            ${inflow > 0 ? `<span style="opacity:.6;font-size:.72rem">+${compact(inflow)}</span>` : ''}
        </button>`;
    }).join('');

    host.querySelectorAll('[data-res]').forEach((element) => {
        element.addEventListener('click', () => openStorage());
    });
}

function renderIslandButtons() {
    const host = document.getElementById('sk-islands');
    if (!host || !state.world) { return; }

    host.innerHTML = Object.values(state.world.islands).map((island) => {
        const def = state.statics.islandTypes[island.type];
        return `<button class="sk-islands__btn${island.id === state.island ? ' is-active' : ''}" data-island="${island.id}">
            <span class="sk-islands__dot" style="background:${def ? def.tint : '#888'}"></span>
            <span>${escapeHtml(island.name)}</span>
        </button>`;
    }).join('');

    host.querySelectorAll('[data-island]').forEach((element) => {
        element.addEventListener('click', () => {
            sfx.tap();
            focusIsland(element.dataset.island);
        });
    });
}

function renderProfile() {
    if (!state.profile) { return; }
    document.getElementById('sk-playername').textContent = state.profile.name;
    document.getElementById('sk-level').textContent = 'Stufe ' + state.profile.level
        + ' · ' + compact(state.profile.score);
    document.getElementById('sk-avatar').textContent = (state.profile.name || '?').charAt(0).toUpperCase();
}

function renderBadges() {
    const questBadge = document.getElementById('sk-quest-badge');
    const notifyBadge = document.getElementById('sk-notify-badge');

    const claimable = (state.quests || []).filter((quest) => quest.done && !quest.claimed).length;
    questBadge.textContent = claimable;
    questBadge.classList.toggle('sk-hidden', claimable === 0);

    notifyBadge.textContent = state.unread;
    notifyBadge.classList.toggle('sk-hidden', !state.unread);
}

// ===================================================================
// Bedienung
// ===================================================================

function setupUi() {
    initSheet();

    document.querySelectorAll('.sk-nav__btn').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelectorAll('.sk-nav__btn').forEach((other) => other.classList.remove('is-active'));
            button.classList.add('is-active');
            unlockAudio();
            sfx.tap();

            switch (button.dataset.panel) {
                case 'map': closeSheet(); endBuildMode(); break;
                case 'build': openBuildCatalog(state.island); break;
                case 'logistics': openLogistics(); break;
                case 'storage': openStorage(); break;
                case 'army': openArmy(); break;
                case 'more': openMore(); break;
                default: break;
            }
        });
    });

    document.querySelectorAll('[data-panel]').forEach((button) => {
        if (button.classList.contains('sk-nav__btn')) { return; }
        button.addEventListener('click', () => {
            unlockAudio();
            if (button.dataset.panel === 'quests') { openQuests(); }
            if (button.dataset.panel === 'notifications') { openNotifications(); }
            if (button.dataset.panel === 'settings') { openSettings(); }
        });
    });

    document.getElementById('sk-profile').addEventListener('click', () => {
        window.location.href = api.url('?p=profil');
    });

    document.getElementById('sk-build-cancel').addEventListener('click', () => {
        endBuildMode();
        sfx.close();
    });
    document.getElementById('sk-build-confirm').addEventListener('click', confirmBuild);

    // Ereignisse aus den Panels
    on('place:start', ({ type, island }) => startPlacement(type, island));
    on('move:start', (buildingId) => startMove(buildingId));
    on('island:focus', (islandId) => focusIsland(islandId));
    on('attack:open', () => openAttackPlanner());
    on('reports:open', () => openReports());
    on('report:open', (id) => openReport(id));
    on('alliance:open', () => openAlliance());
    on('trade:open', () => openTrade());
    on('settings:open', () => openSettings());
    on('battle:replay', (report) => startReplay(report));
    on('badges:update', renderBadges);
    on('world:changed', () => { renderer.islandCache.clear(); renderIslandButtons(); });
    on('refresh:rates', refreshRates);
    on('settings:changed', applySettings);
    on('effect:burst', ({ buildingId, color }) => {
        const building = state.world.buildings[buildingId];
        if (!building) { return; }
        const island = state.world.islands[building.island];
        const position = renderer.cellToWorld(island, building.x, building.y);
        renderer.burst(position.x, position.y, color, 14);
    });
    on('previews:draw', drawCataloguePreviews);

    subscribe(() => {
        renderResources();
        renderProfile();
        renderBadges();
    });

    api.onConnectionChange((online) => {
        if (!online) {
            toast('Keine Verbindung – Aktionen werden erneut versucht.', 'bad', 5000);
        } else {
            toast('Verbindung wieder da.', 'ok', 2000);
            refreshRates();
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) { refreshRates(); }
    });
}

/** Kleine Vorschaubilder im Baukatalog. */
function drawCataloguePreviews(root) {
    root.querySelectorAll('[data-preview]').forEach((element) => {
        const ctx = element.getContext('2d');
        ctx.clearRect(0, 0, element.width, element.height);
        ctx.save();
        ctx.translate(element.width / 2, element.height * 0.84);
        drawBuilding(ctx, element.dataset.preview, element.width * 0.52, element.height * 0.26, 1, 0);
        ctx.restore();
    });
}

// ===================================================================
// Willkommen zurück
// ===================================================================

function showWelcome(summary) {
    const gains = Object.keys(summary.gained || {});
    if (gains.length === 0 && (summary.notes || []).length === 0) { return; }

    openSheet({
        key: 'welcome',
        title: 'Willkommen zurück!',
        subtitle: `In deiner Abwesenheit (${duration(summary.simulated)}) ist einiges passiert.`,
        body: `
            ${gains.length ? `<div class="sk-welcome__gains">${gains.map((key) => `
                <div class="sk-welcome__gain">
                    ${resourceIcon(key, 28)}
                    <b>+${compact(summary.gained[key])}</b>
                    <span class="sk-list__sub">${escapeHtml(resourceName(key))}</span>
                </div>`).join('')}</div>`
                : '<p class="sk-muted">Es wurde nichts eingelagert – prüfe deine Routen.</p>'}

            ${summary.skipped > 60 ? `<div class="sk-alert sk-alert--info"><span>
                Es wurden höchstens ${duration(summary.simulated)} nachgerechnet.
                ${duration(summary.skipped)} darüber hinaus bleiben unberücksichtigt.</span></div>` : ''}

            ${(summary.notes || []).length ? '<h3>Das solltest du dir ansehen</h3>' +
                summary.notes.map((note) => `<div class="sk-alert sk-alert--warn"><span>${escapeHtml(note.text)}</span></div>`).join('')
                : ''}

            <button class="sk-btn sk-btn--block sk-btn--green" data-action="close">Weiterspielen</button>`,
        onMount(body) {
            body.querySelector('[data-action="close"]').addEventListener('click', closeSheet);
        }
    });
    sfx.coins();
}

// ===================================================================
// Schleife und Aktualisierung
// ===================================================================

function startLoop() {
    const frame = (time) => {
        const dt = Math.min(0.05, (time - lastFrame) / 1000);
        lastFrame = time;

        camera.update(dt);
        renderer.render(dt);
        drawGhost();

        requestAnimationFrame(frame);
    };
    requestAnimationFrame(frame);
}

function drawGhost() {
    if (!renderer.buildGhost) { return; }

    const ghost = renderer.buildGhost;
    const island = state.world.islands[ghost.island];
    const def = state.statics.buildings[ghost.type];
    if (!island || !def) { return; }

    const [bw, bh] = def.size;
    const world = renderer.cellToWorld(island, ghost.x + (bw - 1) / 2, ghost.y + (bh - 1) / 2);
    const screen = camera.worldToScreen(world.x, world.y + (bh * CELL * SQUASH) / 2);
    const ok = canPlaceHere(island, def, ghost.x, ghost.y, moving ? moving.id : null);

    const ctx = renderer.ctx;
    ctx.save();
    ctx.globalAlpha = 0.72;
    ctx.translate(screen.x, screen.y);
    drawBuilding(ctx, ghost.type, bw * CELL * camera.zoom, bh * CELL * SQUASH * camera.zoom, 0, renderer.time);
    ctx.restore();

    ctx.save();
    ctx.strokeStyle = ok ? '#7ee3a6' : '#ff5c6c';
    ctx.lineWidth = 3;
    ctx.setLineDash([7, 5]);
    const p = camera.worldToScreen(world.x - (bw * CELL) / 2, world.y - (bh * CELL * SQUASH) / 2);
    ctx.strokeRect(p.x, p.y, bw * CELL * camera.zoom, bh * CELL * SQUASH * camera.zoom);
    ctx.restore();
}

function startRefresh() {
    if (refreshTimer) { clearInterval(refreshTimer); }
    refreshTimer = setInterval(() => {
        if (!document.hidden) { refreshRates(); }
    }, 25000);
}

async function refreshRates() {
    try {
        const data = await api.get('rates');
        if (state.world) { state.world.store = data.store; }
        state.effects = data.effects;
        state.rates = data.rates;
        state.unread = data.unread || 0;
        state.serverNow = data.now;
        state.localNow = Date.now() / 1000;
        stateEmit('tick');
    } catch (error) { /* stiller Fehlschlag – nächster Versuch folgt */ }
}

// ===================================================================
// Einstellungen und Leistung
// ===================================================================

function applySettings(settings) {
    setAudio(settings.sound !== false);
    setMusic(!!settings.music);
    setHaptics(settings.haptics !== false);
    renderer.setAnimations(settings.animations !== false);

    const quality = settings.quality === 'auto' || !settings.quality ? detectQuality() : settings.quality;
    renderer.setQuality(quality);
}

/** Schwächere Geräte erkennen und die Qualität anpassen. */
function detectQuality() {
    const memory = navigator.deviceMemory || 4;
    const cores = navigator.hardwareConcurrency || 4;
    const small = Math.min(window.innerWidth, window.innerHeight) < 380;

    let quality = 'high';
    if (memory <= 2 || cores <= 2) { quality = 'low'; }
    else if (memory <= 4 || cores <= 4 || small) { quality = 'medium'; }

    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        renderer.setAnimations(false);
    }

    renderer.setQuality(quality);
    return quality;
}

function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) { return; }
    navigator.serviceWorker.register(api.url('sw.js')).catch(() => { /* ohne Service Worker läuft alles weiter */ });
}

// Los geht's
boot();
