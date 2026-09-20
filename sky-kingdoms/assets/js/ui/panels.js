/**
 * Die Inhalte aller Bottom-Sheets: Gebäude, Bauen, Transport, Lager, Armee,
 * Forschung, Aufgaben, Allianz, Handel, Shop, Rangliste, Berichte, Einstellungen.
 */

import * as api from '../core/api.js';
import { sfx } from '../core/audio.js';
import { haptics } from '../core/haptics.js';
import { compact, duration, full, percent, rate } from '../core/num.js';
import { resourceIcon, uiIcon } from '../render/icons.js';
import {
    applyResult, buildingDef, buildingsOn, islandDef, now,
    resourceName, routesFor, state, stored
} from '../core/state.js';
import { emit } from './bus.js';
import { bar, costList, emptyNote, escapeHtml, fine, listItem, pill, statGrid } from './parts.js';
import { closeSheet, openSheet, sheetBody } from './sheet.js';
import { toast, toastError } from './toast.js';

// ===================================================================
// Gebäude
// ===================================================================

export async function openBuilding(buildingId) {
    const building = state.world.buildings[buildingId];
    if (!building) { return; }
    const def = buildingDef(building.type);

    openSheet({
        key: 'building:' + buildingId,
        title: def ? def.name : building.type,
        subtitle: `Stufe <strong>${full(building.level)}</strong> · ${escapeHtml(state.world.islands[building.island].name)}`,
        body: '<p class="sk-muted">Wird geladen …</p>'
    });

    await renderBuilding(buildingId);
}

async function renderBuilding(buildingId) {
    const building = state.world.buildings[buildingId];
    if (!building) { closeSheet(); return; }

    const def = buildingDef(building.type);
    let preview = null;
    try {
        const response = await api.get('preview', { type: 'building', id: buildingId });
        preview = response.preview;
    } catch (error) {
        toastError(error);
        return;
    }

    const body = sheetBody();
    if (!body) { return; }

    const rates = state.rates || {};
    const stats = [];

    if (def && Object.keys(def.produces || {}).length) {
        Object.keys(def.produces).forEach((key) => {
            const buffer = (building.out || {})[key] || 0;
            stats.push({
                label: 'Produktion ' + resourceName(key),
                value: rate(preview.benefits.find((b) => b.label.includes(resourceName(key)))?.from || 0),
                delta: 'Puffer: ' + compact(buffer)
            });
        });
    }
    if (def && Object.keys(def.consumes || {}).length) {
        Object.keys(def.consumes).forEach((key) => {
            stats.push({ label: 'Verbraucht ' + resourceName(key), value: compact((building.in || {})[key] || 0) + ' im Eingang' });
        });
    }
    if (def && def.storage && def.storage.class) {
        const benefit = preview.benefits.find((b) => b.label === 'Lagerplatz');
        stats.push({ label: 'Lagerplatz', value: compact(benefit ? benefit.from : 0) });
    }
    if (def && def.workers) {
        stats.push({ label: 'Arbeiter', value: full(def.workers) });
    }
    stats.push({ label: 'Stufe', value: full(building.level) });
    if (preview.next_tier) {
        stats.push({ label: 'Nächste Optik', value: 'Stufe ' + full(preview.next_tier) });
    }

    const benefits = preview.benefits.map((benefit) => `
        <li class="sk-list__item">
            <div class="sk-grow">
                <div class="sk-list__title">${escapeHtml(benefit.label)}</div>
                <div class="sk-list__sub">${fine(benefit.from)}${escapeHtml(benefit.unit || '')} →
                    <strong>${fine(benefit.to)}${escapeHtml(benefit.unit || '')}</strong></div>
            </div>
            <div class="sk-list__right"><span class="sk-pill sk-pill--green">${percent(benefit.percent)}</span></div>
        </li>`).join('');

    const routes = routesFor(buildingId);
    const routeList = routes.length ? routes.map((route) => {
        const info = (rates.routes || {})[route.id] || {};
        const direction = route.src[0] === 'b' && route.src[1] === buildingId ? 'abholen' : 'anliefern';
        return listItem({
            action: true,
            attrs: `data-route="${route.id}"`,
            icon: resourceIcon(route.resource, 28),
            title: `${escapeHtml(resourceName(route.resource))} ${direction}`,
            sub: info.ok
                ? `${compact(info.flow || 0)} /Min. · ${escapeHtml(route.carriers)} Träger`
                    + (info.reason ? `<br><span style="color:#b06a12">${escapeHtml(info.reason)}</span>` : '')
                : `<span style="color:#c0392b">${escapeHtml(info.note || 'unterbrochen')}</span>`,
            right: pill('St. ' + route.level)
        });
    }).join('') : `<p class="sk-muted">Noch keine Route verbunden.</p>`;

    const damaged = building.damage > 0.01;

    body.innerHTML = `
        ${damaged ? `<div class="sk-alert sk-alert--warn"><span>Dieses Gebäude ist zu
            ${Math.round(building.damage * 100)} % beschädigt und arbeitet langsamer.
            <button class="sk-btn sk-btn--small sk-btn--gold" data-action="repair" style="margin-top:8px">Sofort reparieren</button></span></div>` : ''}

        ${def && def.desc ? `<p class="sk-muted">${escapeHtml(def.desc)}</p>` : ''}
        ${statGrid(stats)}

        <h3>Nächste Stufe bringt</h3>
        <ul class="sk-list">${benefits || emptyNote('Diese Stufe verändert keine Werte.')}</ul>

        <h3>Verbessern</h3>
        <div id="sk-upgrade-cost">${upgradeCostBlock(preview, '1')}</div>
        <div class="sk-steps-row">
            ${upgradeButton(preview, '1', '+1')}
            ${upgradeButton(preview, '10', '+10')}
            ${upgradeButton(preview, '100', '+100')}
            ${upgradeButton(preview, 'max', 'MAX')}
        </div>

        <h3 style="margin-top:22px">Transportwege</h3>
        <ul class="sk-list">${routeList}</ul>
        <button class="sk-btn sk-btn--small sk-btn--block" data-action="new-route">Route von hier anlegen</button>

        <div class="sk-row" style="margin-top:20px">
            <button class="sk-btn sk-btn--small sk-btn--ghost sk-grow" data-action="move"
                style="color:var(--sk-primary);box-shadow:inset 0 0 0 1.5px var(--sk-primary)">Verschieben</button>
            ${building.type === 'castle' ? '' :
              '<button class="sk-btn sk-btn--small sk-btn--danger sk-grow" data-action="demolish">Abreissen</button>'}
        </div>
    `;

    body.querySelectorAll('[data-steps]').forEach((button) => {
        button.addEventListener('pointerenter', () => {
            const block = document.getElementById('sk-upgrade-cost');
            if (block) { block.innerHTML = upgradeCostBlock(preview, button.dataset.steps); }
        });
        button.addEventListener('click', () => doUpgrade(buildingId, button.dataset.steps));
    });

    body.querySelector('[data-action="repair"]')?.addEventListener('click', () => doRepair(buildingId));
    body.querySelector('[data-action="demolish"]')?.addEventListener('click', () => doDemolish(buildingId));
    body.querySelector('[data-action="move"]')?.addEventListener('click', () => {
        closeSheet();
        emit('move:start', buildingId);
    });
    body.querySelector('[data-action="new-route"]')?.addEventListener('click', () => openRouteCreator(buildingId));
    body.querySelectorAll('[data-route]').forEach((element) => {
        element.addEventListener('click', () => openRoute(element.dataset.route));
    });
}

function upgradeButton(preview, key, label) {
    const step = preview.steps[key];
    const disabled = !step || step.count < 1 || !step.afford || !step.safe;
    const count = key === 'max' ? (step && step.count ? '+' + step.count : 'MAX') : label;
    const kind = key === 'max' ? 'sk-btn--gold' : '';
    return `<button class="sk-btn ${kind}${disabled ? ' is-disabled' : ''}" data-steps="${key}">${count}</button>`;
}

function upgradeCostBlock(preview, key) {
    const step = preview.steps[key] || preview.steps['1'];
    if (!step || step.count < 1) {
        return '<div class="sk-muted">Dafür reichen die Rohstoffe nicht.</div>';
    }
    if (!step.safe) {
        return '<div class="sk-alert sk-alert--warn"><span>Diese Menge übersteigt den sicheren Zahlenbereich. Bitte in kleineren Schritten verbessern.</span></div>';
    }
    const gain = preview.gain ? `<div class="sk-muted" style="margin-top:4px">Jede Stufe: <strong>+${String(preview.gain).replace('.', ',')} %</strong></div>` : '';
    return `<div class="sk-muted">Kosten für ${step.count} Stufe${step.count === 1 ? '' : 'n'}:</div>
            ${costList(step.cost, step.missing)}${gain}`;
}

async function doUpgrade(buildingId, steps) {
    try {
        const result = await api.post('upgrade', { id: buildingId, steps });
        applyResult(result);
        sfx.upgrade();
        haptics.medium();
        toast(`Ausgebaut auf Stufe ${full(result.level)} (+${result.steps})`, 'ok');
        emit('effect:burst', { buildingId, color: '#ffc94a' });
        await renderBuilding(buildingId);
    } catch (error) {
        toastError(error);
    }
}

async function doRepair(buildingId) {
    try {
        const result = await api.post('repair', { id: buildingId });
        applyResult(result);
        toast('Repariert.', 'ok');
        await renderBuilding(buildingId);
    } catch (error) { toastError(error); }
}

async function doDemolish(buildingId) {
    if (!window.confirm('Dieses Gebäude wirklich abreissen? Ein Drittel der Baukosten kommt zurück.')) { return; }
    try {
        const result = await api.post('demolish', { id: buildingId });
        applyResult(result);
        toast('Abgerissen.', 'info');
        closeSheet();
    } catch (error) { toastError(error); }
}

// ===================================================================
// Bauen
// ===================================================================

export function openBuildCatalog(islandId) {
    const island = state.world.islands[islandId || state.island];
    if (!island) { return; }

    const types = Object.keys(state.statics.buildings).filter((type) => {
        const def = state.statics.buildings[type];
        return !def.islands.length || def.islands.includes(island.type);
    });

    const groups = {};
    types.forEach((type) => {
        const def = state.statics.buildings[type];
        const group = roleName(def.role);
        (groups[group] = groups[group] || []).push({ type, def });
    });

    const html = Object.keys(groups).map((group) => `
        <h3>${escapeHtml(group)}</h3>
        <ul class="sk-list">
            ${groups[group].map(({ type, def }) => listItem({
                action: true,
                attrs: `data-build="${type}"`,
                icon: `<canvas class="sk-list__icon" data-preview="${type}" width="80" height="80"></canvas>`,
                title: escapeHtml(def.name),
                sub: escapeHtml(def.desc || ''),
                extra: costList(def.cost),
                right: uiIcon('arrow', 18)
            })).join('')}
        </ul>`).join('');

    openSheet({
        key: 'build',
        title: 'Bauen auf ' + island.name,
        subtitle: escapeHtml(islandDef(island.type).desc || ''),
        body: html || emptyNote('Auf dieser Insel gibt es nichts zu bauen.'),
        onMount(body) {
            body.querySelectorAll('[data-build]').forEach((element) => {
                element.addEventListener('click', () => {
                    closeSheet();
                    emit('place:start', { type: element.dataset.build, island: island.id });
                });
            });
            emit('previews:draw', body);
        }
    });
}

function roleName(role) {
    return ({
        core: 'Verwaltung', housing: 'Wohnen', producer: 'Gewinnung', converter: 'Verarbeitung',
        storage: 'Lager', logistics: 'Transport', military: 'Militär', defense: 'Verteidigung',
        research: 'Forschung', trade: 'Handel'
    })[role] || 'Sonstiges';
}

// ===================================================================
// Transport
// ===================================================================

export function openLogistics() {
    const rates = state.rates || {};
    const routes = Object.values(state.world.routes);
    const budget = (state.effects && state.effects.routes) || { used: routes.length, max: routes.length, free: 0 };

    const items = routes.map((route) => {
        const info = (rates.routes || {})[route.id] || {};
        const from = endpointName(route.src);
        const to = endpointName(route.dst);
        const problem = !info.ok || info.jam < 0.95;

        return listItem({
            action: true,
            attrs: `data-route="${route.id}"`,
            icon: resourceIcon(route.resource, 32),
            title: `${escapeHtml(from)} → ${escapeHtml(to)}`,
            sub: info.ok
                ? `${compact(info.flow || 0)} von ${compact(info.nominal || 0)} /Min. möglich`
                    + (info.reason ? `<br><span style="color:#b06a12">${escapeHtml(info.reason)}</span>` : '')
                : `<span style="color:#c0392b">${escapeHtml(info.note || 'unterbrochen')}</span>`,
            extra: info.ok ? bar(info.nominal ? (info.flow / info.nominal) : 0, info.jam < 0.95) : '',
            right: `${pill(route.carriers + '×', problem ? 'red' : 'green')}<div class="sk-list__sub">${escapeHtml(modeName(route.mode))}</div>`
        });
    }).join('');

    const notes = (rates.notes || []).map((note) => `
        <div class="sk-alert sk-alert--${note.type === 'jam' || note.type === 'route_broken' ? 'error' : 'warn'}">
            <span>${escapeHtml(note.text)}</span>
        </div>`).join('');

    openSheet({
        key: 'logistics',
        title: 'Transportübersicht',
        subtitle: `${budget.used} von ${budget.max} Routen belegt`,
        body: `
            ${notes}
            ${statGrid([
                { label: 'Routen', value: `${budget.used} / ${budget.max}` },
                { label: 'Arbeitskraft', value: percent((rates.pressure || 1) - 1 + 1, 0, false) },
                { label: 'Brücken', value: full(Object.keys(state.world.bridges).length) }
            ])}
            <h3>Routen</h3>
            <ul class="sk-list">${items || emptyNote('Noch keine Routen angelegt.')}</ul>
            <button class="sk-btn sk-btn--small sk-btn--block" data-action="route-new">Neue Route anlegen</button>
            <h3 style="margin-top:22px">Brücken</h3>
            <ul class="sk-list">${bridgeList()}</ul>
            <h3 style="margin-top:22px">Transportmittel</h3>
            <div id="sk-modes"><p class="sk-muted">Wird geladen …</p></div>
        `,
        async onMount(body) {
            body.querySelectorAll('[data-route]').forEach((element) => {
                element.addEventListener('click', () => openRoute(element.dataset.route));
            });
            body.querySelectorAll('[data-bridge]').forEach((element) => {
                element.addEventListener('click', () => openBridge(element.dataset.bridge));
            });
            body.querySelector('[data-action="route-new"]')?.addEventListener('click', () => openRouteCreator(null));

            try {
                const response = await api.get('research_list');
                const host = document.getElementById('sk-modes');
                if (host) {
                    host.innerHTML = '<ul class="sk-list">' + response.modes.map((mode) => listItem({
                        action: !mode.unlocked && !mode.requirement,
                        attrs: `data-mode="${mode.key}"`,
                        title: escapeHtml(mode.name),
                        sub: `Tempo ${String(mode.speed).replace('.', ',')} · Ladung ${compact(mode.capacity)}`,
                        extra: mode.unlocked ? '' : costList(mode.cost),
                        right: mode.unlocked ? pill('frei', 'green')
                            : (mode.requirement ? pill(mode.requirement, 'muted') : pill('freischalten', 'gold'))
                    })).join('') + '</ul>';

                    host.querySelectorAll('[data-mode]').forEach((element) => {
                        element.addEventListener('click', async () => {
                            try {
                                const result = await api.post('mode_unlock', { mode: element.dataset.mode });
                                applyResult(result);
                                sfx.success();
                                toast('Transportmittel freigeschaltet!', 'ok');
                                openLogistics();
                            } catch (error) { toastError(error); }
                        });
                    });
                }
            } catch (error) { /* Liste bleibt leer */ }
        }
    });
}

function bridgeList() {
    const rates = (state.rates && state.rates.bridges) || {};
    return Object.values(state.world.bridges).map((bridge) => {
        const info = rates[bridge.id] || {};
        const a = state.world.islands[bridge.a];
        const b = state.world.islands[bridge.b];
        const load = info.capacity ? (info.demand / info.capacity) : 0;
        return listItem({
            action: true,
            attrs: `data-bridge="${bridge.id}"`,
            icon: uiIcon('truck', 28),
            title: `${escapeHtml(a ? a.name : '?')} ↔ ${escapeHtml(b ? b.name : '?')}`,
            sub: `${compact(info.demand || 0)} von ${compact(info.capacity || 0)} /Min.`,
            extra: bar(Math.min(1, load), load > 0.98),
            right: pill('St. ' + bridge.level, load > 0.98 ? 'red' : '')
        });
    }).join('') || emptyNote('Noch keine Brücken.');
}

function endpointName(ref) {
    if (!ref || ref[0] !== 'b') { return 'Lager'; }
    const building = state.world.buildings[ref[1]];
    if (!building) { return 'unbekannt'; }
    const def = buildingDef(building.type);
    return def ? def.name : building.type;
}

function modeName(key) {
    const mode = state.statics.modes[key];
    return mode ? mode.name : key;
}

export async function openRoute(routeId) {
    const route = state.world.routes[routeId];
    if (!route) { return; }
    const info = (state.rates && state.rates.routes && state.rates.routes[routeId]) || {};

    let preview = null;
    try {
        preview = (await api.get('preview', { type: 'route', id: routeId })).preview;
    } catch (error) { /* ohne Vorschau weiter */ }

    openSheet({
        key: 'route:' + routeId,
        title: `${endpointName(route.src)} → ${endpointName(route.dst)}`,
        subtitle: `${escapeHtml(resourceName(route.resource))} · ${escapeHtml(modeName(route.mode))}`,
        iconSvg: resourceIcon(route.resource, 46),
        body: `
            ${statGrid([
                { label: 'Durchsatz', value: compact(info.flow || 0) + ' /Min.' },
                { label: 'Ohne Stau', value: compact(info.nominal || 0) + ' /Min.' },
                { label: 'Träger', value: full(route.carriers) },
                { label: 'Rundfahrt', value: duration(Math.round(info.trip || 0)) }
            ])}
            ${info.ok ? '' : `<div class="sk-alert sk-alert--error"><span>${escapeHtml(info.note || 'Diese Route ist unterbrochen.')}</span></div>`}
            ${info.jam < 0.95 ? `<div class="sk-alert sk-alert--warn"><span>Stau: nur ${Math.round(info.jam * 100)} % Durchsatz.
                Baue eine weitere Brücke, verbessere die vorhandene oder verteile den Verkehr.</span></div>` : ''}
            ${info.reason && info.jam >= 0.95 ? `<div class="sk-alert sk-alert--info"><span>${escapeHtml(info.reason)}</span></div>` : ''}

            <h3>Träger</h3>
            <div class="sk-row">
                <button class="sk-btn sk-btn--small" data-action="carrier-add">+1 Träger</button>
                <button class="sk-btn sk-btn--small sk-btn--ghost" data-action="carrier-remove"
                    style="color:var(--sk-primary);box-shadow:inset 0 0 0 1.5px var(--sk-primary)">−1</button>
            </div>

            ${preview ? `
            <h3 style="margin-top:20px">Route verbessern</h3>
            <div id="sk-route-cost">${upgradeCostBlock(preview, '1')}</div>
            <ul class="sk-list">${preview.benefits.map((b) => `
                <li class="sk-list__item"><div class="sk-grow">
                    <div class="sk-list__title">${escapeHtml(b.label)}</div>
                    <div class="sk-list__sub">${fine(b.from)} → <strong>${fine(b.to)}</strong></div>
                </div><div class="sk-list__right">${pill(percent(b.percent), 'green')}</div></li>`).join('')}</ul>
            <div class="sk-steps-row">
                ${upgradeButton(preview, '1', '+1')}
                ${upgradeButton(preview, '10', '+10')}
                ${upgradeButton(preview, '100', '+100')}
                ${upgradeButton(preview, 'max', 'MAX')}
            </div>` : ''}

            <h3 style="margin-top:20px">Transportmittel</h3>
            <select class="sk-grow" id="sk-route-mode" style="width:100%;min-height:48px;border-radius:12px;border:1.5px solid rgba(16,26,46,.12);padding:0 12px">
                ${Object.keys(state.statics.modes).map((key) => {
                    const unlocked = key === 'foot' || (state.world.modes && state.world.modes[key]);
                    if (!unlocked) { return ''; }
                    return `<option value="${key}"${route.mode === key ? ' selected' : ''}>${escapeHtml(state.statics.modes[key].name)}</option>`;
                }).join('')}
            </select>

            <div class="sk-row" style="margin-top:20px">
                <button class="sk-btn sk-btn--small sk-grow" data-action="toggle">${route.enabled ? 'Anhalten' : 'Fortsetzen'}</button>
                <button class="sk-btn sk-btn--small sk-btn--danger sk-grow" data-action="delete">Route löschen</button>
            </div>
        `,
        onMount(body) {
            body.querySelector('[data-action="carrier-add"]')?.addEventListener('click', async () => {
                try {
                    applyResult(await api.post('route_carrier_add', { id: routeId, count: 1 }));
                    sfx.coins();
                    openRoute(routeId);
                } catch (error) { toastError(error); }
            });
            body.querySelector('[data-action="carrier-remove"]')?.addEventListener('click', async () => {
                try {
                    applyResult(await api.post('route_carrier_remove', { id: routeId }));
                    openRoute(routeId);
                } catch (error) { toastError(error); }
            });
            body.querySelectorAll('[data-steps]').forEach((button) => {
                button.addEventListener('click', async () => {
                    try {
                        const result = await api.post('route_upgrade', { id: routeId, steps: button.dataset.steps });
                        applyResult(result);
                        sfx.upgrade();
                        toast(`Route auf Stufe ${full(result.level)}`, 'ok');
                        openRoute(routeId);
                    } catch (error) { toastError(error); }
                });
                button.addEventListener('pointerenter', () => {
                    const block = document.getElementById('sk-route-cost');
                    if (block && preview) { block.innerHTML = upgradeCostBlock(preview, button.dataset.steps); }
                });
            });
            body.querySelector('#sk-route-mode')?.addEventListener('change', async (event) => {
                try {
                    applyResult(await api.post('route_mode', { id: routeId, mode: event.target.value }));
                    toast('Transportmittel gewechselt.', 'ok');
                    openRoute(routeId);
                } catch (error) { toastError(error); }
            });
            body.querySelector('[data-action="toggle"]')?.addEventListener('click', async () => {
                try {
                    applyResult(await api.post('route_toggle', { id: routeId, enabled: route.enabled ? '0' : '1' }));
                    openRoute(routeId);
                } catch (error) { toastError(error); }
            });
            body.querySelector('[data-action="delete"]')?.addEventListener('click', async () => {
                if (!window.confirm('Route wirklich löschen?')) { return; }
                try {
                    applyResult(await api.post('route_delete', { id: routeId }));
                    toast('Route gelöscht.', 'info');
                    openLogistics();
                } catch (error) { toastError(error); }
            });
        }
    });
}

export function openBridge(bridgeId) {
    const bridge = state.world.bridges[bridgeId];
    if (!bridge) { return; }
    const info = (state.rates && state.rates.bridges && state.rates.bridges[bridgeId]) || {};

    api.get('preview', { type: 'bridge', id: bridgeId }).then((response) => {
        const preview = response.preview;
        const a = state.world.islands[bridge.a];
        const b = state.world.islands[bridge.b];

        openSheet({
            key: 'bridge:' + bridgeId,
            title: 'Brücke',
            subtitle: `${escapeHtml(a ? a.name : '?')} ↔ ${escapeHtml(b ? b.name : '?')}`,
            body: `
                ${statGrid([
                    { label: 'Stufe', value: full(bridge.level) },
                    { label: 'Kapazität', value: compact(info.capacity || 0) + ' /Min.' },
                    { label: 'Auslastung', value: compact(info.demand || 0) + ' /Min.' }
                ])}
                ${bar(info.capacity ? Math.min(1, info.demand / info.capacity) : 0, (info.factor || 1) < 0.99)}
                ${(info.factor || 1) < 0.99 ? `<div class="sk-alert sk-alert--warn"><span>Diese Brücke ist überlastet
                    (${Math.round(info.factor * 100)} %). Höhere Stufen schaffen mehr Verkehr.</span></div>` : ''}
                ${bridge.damage > 0.01 ? `<div class="sk-alert sk-alert--error"><span>Beschädigt zu
                    ${Math.round(bridge.damage * 100)} %.
                    <button class="sk-btn sk-btn--small sk-btn--gold" data-action="repair" style="margin-top:8px">Reparieren</button></span></div>` : ''}
                <h3>Ausbauen</h3>
                <div id="sk-bridge-cost">${upgradeCostBlock(preview, '1')}</div>
                <div class="sk-steps-row">
                    ${upgradeButton(preview, '1', '+1')}
                    ${upgradeButton(preview, '10', '+10')}
                    ${upgradeButton(preview, '100', '+100')}
                    ${upgradeButton(preview, 'max', 'MAX')}
                </div>`,
            onMount(body) {
                body.querySelectorAll('[data-steps]').forEach((button) => {
                    button.addEventListener('pointerenter', () => {
                        const block = document.getElementById('sk-bridge-cost');
                        if (block) { block.innerHTML = upgradeCostBlock(preview, button.dataset.steps); }
                    });
                    button.addEventListener('click', async () => {
                        try {
                            const result = await api.post('bridge_upgrade', { id: bridgeId, steps: button.dataset.steps });
                            applyResult(result);
                            sfx.upgrade();
                            toast(`Brücke auf Stufe ${full(result.level)}`, 'ok');
                            openBridge(bridgeId);
                        } catch (error) { toastError(error); }
                    });
                });
                body.querySelector('[data-action="repair"]')?.addEventListener('click', async () => {
                    try {
                        applyResult(await api.post('repair', { id: bridgeId }));
                        toast('Brücke repariert.', 'ok');
                        openBridge(bridgeId);
                    } catch (error) { toastError(error); }
                });
            }
        });
    }).catch(toastError);
}

/** Neue Route: Quelle, Ziel und Ware wählen. */
export function openRouteCreator(fromBuildingId) {
    const producers = [];
    const consumers = [];

    Object.values(state.world.buildings).forEach((building) => {
        const def = buildingDef(building.type);
        if (!def) { return; }
        Object.keys(def.produces || {}).forEach((res) => producers.push({ building, res }));
        Object.keys(def.consumes || {}).forEach((res) => consumers.push({ building, res }));
    });

    openSheet({
        key: 'route-new',
        title: 'Neue Transportroute',
        subtitle: 'Waren müssen ins Lager gebracht werden, bevor du sie ausgeben kannst.',
        body: `
            <div class="sk-field">
                <label for="sk-rc-src">Von</label>
                <select id="sk-rc-src">
                    ${producers.map(({ building, res }) => {
                        const def = buildingDef(building.type);
                        const selected = fromBuildingId === building.id ? ' selected' : '';
                        return `<option value="b:${building.id}|${res}"${selected}>${escapeHtml(def.name)} – ${escapeHtml(resourceName(res))}</option>`;
                    }).join('')}
                    <option value="store|">Lager (Ware herausgeben)</option>
                </select>
            </div>
            <div class="sk-field">
                <label for="sk-rc-dst">Nach</label>
                <select id="sk-rc-dst">
                    <option value="store|">Lager</option>
                    ${consumers.map(({ building, res }) => {
                        const def = buildingDef(building.type);
                        return `<option value="b:${building.id}|${res}">${escapeHtml(def.name)} – ${escapeHtml(resourceName(res))}</option>`;
                    }).join('')}
                </select>
            </div>
            <div class="sk-field">
                <label for="sk-rc-res">Ware</label>
                <select id="sk-rc-res"></select>
            </div>
            ${costList((state.routeCost || {}))}
            <button class="sk-btn sk-btn--block sk-btn--green" data-action="create">Route anlegen</button>
            <p class="sk-field__hint">Tipp: Eine Route von einer Mine zum Lager bringt Erz dorthin,
                wo du es ausgeben kannst. Eine Route vom Lager zu einer Werkstatt versorgt sie mit Nachschub.</p>
        `,
        onMount(body) {
            const src = body.querySelector('#sk-rc-src');
            const dst = body.querySelector('#sk-rc-dst');
            const res = body.querySelector('#sk-rc-res');

            const refresh = () => {
                const srcRes = (src.value.split('|')[1] || '');
                const dstRes = (dst.value.split('|')[1] || '');
                const options = new Set();
                if (srcRes) { options.add(srcRes); }
                if (dstRes) { options.add(dstRes); }
                if (options.size === 0) { Object.keys(state.statics.resources).forEach((k) => options.add(k)); }
                res.innerHTML = [...options].map((key) =>
                    `<option value="${key}">${escapeHtml(resourceName(key))}</option>`).join('');
            };
            src.addEventListener('change', refresh);
            dst.addEventListener('change', refresh);
            refresh();

            body.querySelector('[data-action="create"]').addEventListener('click', async () => {
                try {
                    const result = await api.post('route_create', {
                        src: src.value.split('|')[0],
                        dst: dst.value.split('|')[0],
                        resource: res.value,
                        mode: 'foot'
                    });
                    applyResult(result);
                    sfx.build();
                    toast('Route angelegt. Die Träger machen sich auf den Weg.', 'ok');
                    openLogistics();
                } catch (error) { toastError(error); }
            });
        }
    });
}

// ===================================================================
// Lager
// ===================================================================

export function openStorage() {
    const storage = (state.effects && state.effects.storage) || {};
    const rates = state.rates || {};
    const resources = Object.keys(state.statics.resources)
        .sort((a, b) => state.statics.resources[a].order - state.statics.resources[b].order);

    const classes = Object.keys(storage).map((key) => {
        const entry = storage[key];
        return `<div style="margin-bottom:14px">
            <div class="sk-row" style="justify-content:space-between">
                <strong>${escapeHtml(entry.name)}</strong>
                <span class="sk-muted">${compact(entry.used)} / ${compact(entry.cap)}</span>
            </div>
            ${bar(entry.ratio, entry.full)}
            ${entry.full ? '<div class="sk-field__error">Voll – die Zulieferung stockt!</div>' : ''}
        </div>`;
    }).join('');

    const list = resources.map((key) => {
        const amount = stored(key);
        const production = (rates.production || {})[key] || 0;
        const delivery = (rates.delivery || {})[key] || 0;
        const consumption = (rates.consumption || {})[key] || 0;
        if (amount === 0 && production === 0 && consumption === 0) { return ''; }
        const net = delivery - consumption;

        return listItem({
            icon: resourceIcon(key, 32),
            title: escapeHtml(resourceName(key)),
            sub: `Erzeugt ${compact(production)} /Min. · Geliefert ${compact(delivery)} /Min.`
                + (consumption ? ` · Verbraucht ${compact(consumption)} /Min.` : ''),
            right: `<strong>${compact(amount)}</strong>
                <div class="sk-list__sub" style="color:${net >= 0 ? '#1f8a5b' : '#c0392b'}">
                    ${net >= 0 ? '+' : ''}${compact(net)}/Min.</div>`
        });
    }).join('');

    openSheet({
        key: 'storage',
        title: 'Lager',
        subtitle: 'Nur eingelagerte Waren kannst du ausgeben.',
        body: `${classes}<h3>Bestände</h3><ul class="sk-list">${list || emptyNote('Noch nichts eingelagert.')}</ul>`
    });
}

// ===================================================================
// Armee
// ===================================================================

export function openArmy() {
    const units = state.statics.units;
    const effects = state.effects || {};
    const owned = state.world.units || {};
    const used = Object.keys(owned).reduce((sum, key) => sum + owned[key] * (units[key] ? units[key].pop : 1), 0);

    const list = Object.keys(units).map((key) => {
        const unit = units[key];
        const level = (state.world.unit_levels || {})[key] || 1;
        return listItem({
            title: escapeHtml(unit.name),
            sub: `Stufe ${level} · ${compact(unit.hp)} LP · ${compact(unit.damage)} Schaden · Trägt ${compact(unit.carry)}`,
            extra: costList(unit.cost),
            right: `<strong>${full(owned[key] || 0)}</strong>
                <div class="sk-row" style="margin-top:6px;gap:4px">
                    <button class="sk-btn sk-btn--small" data-train="${key}" data-count="1">+1</button>
                    <button class="sk-btn sk-btn--small" data-train="${key}" data-count="10">+10</button>
                </div>
                <button class="sk-btn sk-btn--small sk-btn--gold" data-unit-up="${key}" style="margin-top:6px">Ausbilden</button>`
        });
    }).join('');

    openSheet({
        key: 'army',
        title: 'Armee',
        subtitle: `${used} von ${effects.army_capacity || 0} Plätzen belegt`,
        body: `
            ${statGrid([
                { label: 'Truppenplätze', value: `${used} / ${effects.army_capacity || 0}` },
                { label: 'Verteidigung', value: compact(effects.defense_hp || 0) + ' LP' },
                { label: 'Turmschaden', value: compact(effects.defense_damage || 0) }
            ])}
            <ul class="sk-list">${list}</ul>
            <button class="sk-btn sk-btn--block sk-btn--danger" data-action="attack" style="margin-top:12px">Angriff planen</button>
            <button class="sk-btn sk-btn--block sk-btn--small" data-action="reports" style="margin-top:8px">Kampfberichte</button>
        `,
        onMount(body) {
            body.querySelectorAll('[data-train]').forEach((button) => {
                button.addEventListener('click', async () => {
                    try {
                        const result = await api.post('train', { unit: button.dataset.train, count: button.dataset.count });
                        applyResult(result);
                        sfx.build();
                        toast('Truppen ausgebildet.', 'ok');
                        openArmy();
                    } catch (error) { toastError(error); }
                });
            });
            body.querySelectorAll('[data-unit-up]').forEach((button) => {
                button.addEventListener('click', async () => {
                    try {
                        const result = await api.post('unit_upgrade', { unit: button.dataset.unitUp, steps: '1' });
                        applyResult(result);
                        sfx.upgrade();
                        toast(`Ausbildungsstufe ${full(result.level)}`, 'ok');
                        openArmy();
                    } catch (error) { toastError(error); }
                });
            });
            body.querySelector('[data-action="attack"]')?.addEventListener('click', () => emit('attack:open'));
            body.querySelector('[data-action="reports"]')?.addEventListener('click', () => emit('reports:open'));
        }
    });
}

// ===================================================================
// Aufgaben
// ===================================================================

export async function openQuests() {
    openSheet({ key: 'quests', title: 'Aufgaben', body: '<p class="sk-muted">Wird geladen …</p>' });

    try {
        const response = await api.get('quests');
        const body = sheetBody();
        if (!body) { return; }

        const render = (items, title) => `
            <h3>${title}</h3>
            <ul class="sk-list">${items.map((quest) => listItem({
                title: escapeHtml(quest.name),
                sub: escapeHtml(quest.desc),
                extra: bar(quest.current / quest.goal) +
                    `<div class="sk-list__sub">${compact(quest.current)} / ${compact(quest.goal)}</div>` +
                    (Object.keys(quest.reward || {}).length ? costList(quest.reward) : ''),
                right: quest.claimed ? pill('erledigt', 'muted')
                    : (quest.done ? `<button class="sk-btn sk-btn--small sk-btn--gold" data-quest="${quest.id}">Abholen</button>`
                        : pill(Math.round((quest.current / quest.goal) * 100) + ' %'))
            })).join('') || emptyNote('Keine Aufgaben.')}</ul>`;

        const daily = response.quests.filter((q) => q.type === 'daily');
        const main = response.quests.filter((q) => q.type !== 'daily');

        body.innerHTML = render(daily, 'Täglich') + render(main, 'Langfristig') + `
            <h3>Erfolge</h3>
            <ul class="sk-list">${response.achievements.map((achievement) => listItem({
                title: escapeHtml(achievement.name),
                sub: escapeHtml(achievement.desc),
                extra: bar(achievement.current / achievement.goal),
                right: achievement.done ? pill('erreicht', 'green') : pill(compact(achievement.current) + '/' + compact(achievement.goal), 'muted')
            })).join('')}</ul>`;

        body.querySelectorAll('[data-quest]').forEach((button) => {
            button.addEventListener('click', async () => {
                try {
                    const result = await api.post('quest_claim', { id: button.dataset.quest });
                    applyResult(result);
                    sfx.quest();
                    haptics.success();
                    toast('Belohnung erhalten!', 'gold');
                    openQuests();
                } catch (error) { toastError(error); }
            });
        });
    } catch (error) { toastError(error); }
}

// ===================================================================
// Benachrichtigungen
// ===================================================================

export async function openNotifications() {
    openSheet({ key: 'notifications', title: 'Benachrichtigungen', body: '<p class="sk-muted">Wird geladen …</p>' });

    try {
        const response = await api.get('notifications');
        const body = sheetBody();
        if (!body) { return; }

        body.innerHTML = '<ul class="sk-list">' + (response.items.map((item) => listItem({
            action: !!(item.data && item.data.report),
            attrs: item.data && item.data.report ? `data-report="${item.data.report}"` : '',
            title: escapeHtml(item.title),
            sub: escapeHtml(item.text) + ' · ' + duration(now() - item.ts) + ' her',
            right: item.read ? '' : pill('neu', 'gold')
        })).join('') || emptyNote('Keine Nachrichten.')) + '</ul>';

        body.querySelectorAll('[data-report]').forEach((element) => {
            element.addEventListener('click', () => emit('report:open', element.dataset.report));
        });

        await api.post('notifications_read', {});
        state.unread = 0;
        emit('badges:update');
    } catch (error) { toastError(error); }
}

// ===================================================================
// Mehr: Forschung, Allianz, Handel, Shop, Rangliste, Einstellungen
// ===================================================================

export function openMore() {
    openSheet({
        key: 'more',
        title: 'Mehr',
        body: `
            <ul class="sk-list">
                ${listItem({ action: true, attrs: 'data-open="research"', title: 'Forschung', sub: 'Dauerhafte Verbesserungen für das ganze Reich', right: uiIcon('arrow', 18) })}
                ${listItem({ action: true, attrs: 'data-open="islands"', title: 'Inseln', sub: 'Neue Inselplätze freischalten', right: uiIcon('arrow', 18) })}
                ${listItem({ action: true, attrs: 'data-open="alliance"', title: 'Allianz', sub: 'Gemeinsam stärker', right: uiIcon('arrow', 18) })}
                ${listItem({ action: true, attrs: 'data-open="trade"', title: 'Handel', sub: 'Waren mit anderen tauschen', right: uiIcon('arrow', 18) })}
                ${listItem({ action: true, attrs: 'data-open="shop"', title: 'Markt', sub: 'Waren kaufen und verkaufen', right: uiIcon('arrow', 18) })}
                ${listItem({ action: true, attrs: 'data-open="ranking"', title: 'Rangliste', sub: 'Die stärksten Königreiche', right: uiIcon('arrow', 18) })}
                ${listItem({ action: true, attrs: 'data-open="reports"', title: 'Kampfberichte', sub: 'Angriffe und Verteidigung', right: uiIcon('arrow', 18) })}
                ${listItem({ action: true, attrs: 'data-open="settings"', title: 'Einstellungen', sub: 'Ton, Animationen, Qualität', right: uiIcon('arrow', 18) })}
                ${listItem({ action: true, attrs: 'data-open="profile"', title: 'Profil und Konto', sub: 'Statistik, Passwort, Abmelden', right: uiIcon('arrow', 18) })}
            </ul>`,
        onMount(body) {
            body.querySelectorAll('[data-open]').forEach((element) => {
                element.addEventListener('click', () => {
                    const target = element.dataset.open;
                    if (target === 'profile') { window.location.href = api.url('?p=profil'); return; }
                    if (target === 'ranking') { openRanking(); return; }
                    if (target === 'research') { openResearch(); return; }
                    if (target === 'islands') { openIslands(); return; }
                    if (target === 'alliance') { emit('alliance:open'); return; }
                    if (target === 'trade') { emit('trade:open'); return; }
                    if (target === 'shop') { openShop(); return; }
                    if (target === 'reports') { emit('reports:open'); return; }
                    if (target === 'settings') { emit('settings:open'); }
                });
            });
        }
    });
}

export async function openResearch() {
    openSheet({ key: 'research', title: 'Forschung', body: '<p class="sk-muted">Wird geladen …</p>' });

    try {
        const response = await api.get('research_list');
        const body = sheetBody();
        if (!body) { return; }

        body.innerHTML = '<ul class="sk-list">' + response.research.map((item) => listItem({
            title: escapeHtml(item.name) + ' <span class="sk-pill">St. ' + item.level + '</span>',
            sub: escapeHtml(item.desc) + (item.unlocked ? '' : ` · <span style="color:#c0392b">${escapeHtml(item.requirement)}</span>`),
            extra: costList(item.cost) +
                `<div class="sk-list__sub">Jede Stufe: <strong>${percent(item.per_level - 1)}</strong></div>`,
            right: item.unlocked
                ? `<button class="sk-btn sk-btn--small" data-research="${item.key}">Erforschen</button>`
                : pill('gesperrt', 'muted')
        })).join('') + '</ul>';

        body.querySelectorAll('[data-research]').forEach((button) => {
            button.addEventListener('click', async () => {
                try {
                    const result = await api.post('research', { key: button.dataset.research, steps: '1' });
                    applyResult(result);
                    sfx.success();
                    toast(`Forschung auf Stufe ${full(result.level)}`, 'ok');
                    openResearch();
                } catch (error) { toastError(error); }
            });
        });
    } catch (error) { toastError(error); }
}

export function openIslands() {
    const owned = Object.values(state.world.islands);
    const types = state.statics.islandTypes;

    openSheet({
        key: 'islands',
        title: 'Inseln',
        subtitle: `${owned.length} Inseln in deinem Reich`,
        body: `
            <ul class="sk-list">${owned.map((island) => listItem({
                action: true,
                attrs: `data-goto="${island.id}"`,
                title: escapeHtml(island.name),
                sub: escapeHtml(types[island.type] ? types[island.type].name : island.type)
                    + ' · ' + buildingsOn(island.id).length + ' Gebäude',
                right: uiIcon('arrow', 18)
            })).join('')}</ul>
            <h3>Neue Insel freischalten</h3>
            <ul class="sk-list">${Object.keys(types).map((key) => listItem({
                title: escapeHtml(types[key].name),
                sub: escapeHtml(types[key].desc || ''),
                extra: costList(types[key].unlock_cost),
                right: `<button class="sk-btn sk-btn--small sk-btn--gold" data-unlock="${key}">Freischalten</button>`
            })).join('')}</ul>
            <h3>Neue Brücke</h3>
            <div class="sk-row">
                <select id="sk-bridge-a" class="sk-grow" style="min-height:48px;border-radius:12px;border:1.5px solid rgba(16,26,46,.12);padding:0 10px">
                    ${owned.map((i) => `<option value="${i.id}">${escapeHtml(i.name)}</option>`).join('')}
                </select>
                <select id="sk-bridge-b" class="sk-grow" style="min-height:48px;border-radius:12px;border:1.5px solid rgba(16,26,46,.12);padding:0 10px">
                    ${owned.map((i) => `<option value="${i.id}">${escapeHtml(i.name)}</option>`).join('')}
                </select>
            </div>
            <button class="sk-btn sk-btn--small sk-btn--block" data-action="bridge" style="margin-top:10px">Brücke bauen</button>
        `,
        onMount(body) {
            body.querySelectorAll('[data-goto]').forEach((element) => {
                element.addEventListener('click', () => {
                    closeSheet();
                    emit('island:focus', element.dataset.goto);
                });
            });
            body.querySelectorAll('[data-unlock]').forEach((button) => {
                button.addEventListener('click', async () => {
                    try {
                        const result = await api.post('island_unlock', { type: button.dataset.unlock });
                        applyResult(result);
                        sfx.success();
                        toast('Neue Insel erschlossen!', 'gold');
                        emit('world:changed');
                        openIslands();
                    } catch (error) { toastError(error); }
                });
            });
            body.querySelector('[data-action="bridge"]')?.addEventListener('click', async () => {
                try {
                    const result = await api.post('bridge_build', {
                        a: body.querySelector('#sk-bridge-a').value,
                        b: body.querySelector('#sk-bridge-b').value
                    });
                    applyResult(result);
                    sfx.build();
                    toast('Brücke gebaut.', 'ok');
                    openIslands();
                } catch (error) { toastError(error); }
            });
        }
    });
}

export function openShop() {
    const packs = (state.statics.shopPacks) || {};

    openSheet({
        key: 'shop',
        title: 'Markt',
        subtitle: 'Nur Spielwährung – keine Echtgeldkäufe.',
        body: `
            <h3>Verkaufen</h3>
            <div class="sk-field">
                <label for="sk-sell-res">Ware</label>
                <select id="sk-sell-res">
                    ${Object.keys(state.statics.resources).filter((k) => k !== 'gold' && stored(k) > 0)
                        .map((k) => `<option value="${k}">${escapeHtml(resourceName(k))} (${compact(stored(k))})</option>`).join('')
                        || '<option value="">Nichts im Lager</option>'}
                </select>
            </div>
            <div class="sk-field">
                <label for="sk-sell-amount">Menge</label>
                <input id="sk-sell-amount" type="number" min="1" value="100" inputmode="numeric">
            </div>
            <button class="sk-btn sk-btn--block sk-btn--gold" data-action="sell">Verkaufen</button>
            <p class="sk-field__hint">Der Markt zahlt in Goldmünzen. Der Preis hängt von der Ware ab.</p>
        `,
        onMount(body) {
            body.querySelector('[data-action="sell"]')?.addEventListener('click', async () => {
                const resource = body.querySelector('#sk-sell-res').value;
                const amount = body.querySelector('#sk-sell-amount').value;
                if (!resource) { return; }
                try {
                    const result = await api.post('shop_sell', { resource, amount });
                    applyResult(result);
                    sfx.coins();
                    toast(`${compact(result.sold)} verkauft für ${compact(result.gold)} Gold.`, 'gold');
                    openShop();
                } catch (error) { toastError(error); }
            });
        }
    });
}

export async function openRanking(page = 1) {
    openSheet({ key: 'ranking', title: 'Rangliste', body: '<p class="sk-muted">Wird geladen …</p>' });

    try {
        const response = await api.get('ranking', { page });
        const body = sheetBody();
        if (!body) { return; }

        const data = response.ranking;
        body.innerHTML = `
            <p class="sk-muted">${full(data.total)} Königreiche · dein Platz:
                <strong>${response.me.rank || '–'}</strong></p>
            <ul class="sk-list">${data.entries.map((entry) => listItem({
                title: `<span class="sk-pill sk-pill--muted">${entry.rank}</span> ${escapeHtml(entry.name)}`,
                sub: escapeHtml(entry.kingdom || '') + ' · Stufe ' + entry.level,
                right: `<strong>${compact(entry.score)}</strong><div class="sk-list__sub">Punkte</div>`
            })).join('')}</ul>
            <div class="sk-row" style="justify-content:space-between;margin-top:12px">
                <button class="sk-btn sk-btn--small" data-page="${Math.max(1, data.page - 1)}"
                    ${data.page <= 1 ? 'disabled' : ''}>Zurück</button>
                <span class="sk-muted">Seite ${data.page} / ${data.pages}</span>
                <button class="sk-btn sk-btn--small" data-page="${Math.min(data.pages, data.page + 1)}"
                    ${data.page >= data.pages ? 'disabled' : ''}>Weiter</button>
            </div>`;

        body.querySelectorAll('[data-page]').forEach((button) => {
            button.addEventListener('click', () => openRanking(Number(button.dataset.page)));
        });
    } catch (error) { toastError(error); }
}
