/** Allianz, Handel, Kampfberichte und Einstellungen. */

import * as api from '../core/api.js';
import { setEnabled as setAudio, setMusic, sfx } from '../core/audio.js';
import { setEnabled as setHaptics } from '../core/haptics.js';
import { compact, duration, full } from '../core/num.js';
import { resourceIcon } from '../render/icons.js';
import { applyResult, now, resourceName, state, stored } from '../core/state.js';
import { emit } from './bus.js';
import { costList, emptyNote, escapeHtml, listItem, pill, statGrid } from './parts.js';
import { closeSheet, openSheet, sheetBody } from './sheet.js';
import { toast, toastError } from './toast.js';

// ===================================================================
// Allianz
// ===================================================================

export async function openAlliance() {
    openSheet({ key: 'alliance', title: 'Allianz', body: '<p class="sk-muted">Wird geladen …</p>' });

    try {
        const response = await api.get('alliances');
        const body = sheetBody();
        if (!body) { return; }

        if (response.mine) {
            const mine = response.mine;
            body.innerHTML = `
                ${statGrid([
                    { label: 'Allianz', value: escapeHtml(mine.name) },
                    { label: 'Kürzel', value: escapeHtml(mine.tag) },
                    { label: 'Mitglieder', value: full(mine.members) },
                    { label: 'Punkte', value: compact(mine.score) }
                ])}
                <h3>Mitglieder</h3>
                <ul class="sk-list">${mine.members_list.map((member) => listItem({
                    title: escapeHtml(member.name),
                    sub: `Stufe ${member.level} · zuletzt ${member.last_seen ? duration(now() - member.last_seen) + ' her' : 'unbekannt'}`,
                    right: `<strong>${compact(member.score)}</strong>${member.role === 'leader' ? pill('Leitung', 'gold') : ''}`
                })).join('')}</ul>
                <button class="sk-btn sk-btn--block sk-btn--danger" data-action="leave" style="margin-top:14px">Allianz verlassen</button>`;

            body.querySelector('[data-action="leave"]').addEventListener('click', async () => {
                if (!window.confirm('Allianz wirklich verlassen?')) { return; }
                try {
                    await api.post('alliance_leave', {});
                    toast('Du hast die Allianz verlassen.', 'info');
                    openAlliance();
                } catch (error) { toastError(error); }
            });
            return;
        }

        body.innerHTML = `
            <h3>Eigene Allianz gründen</h3>
            <div class="sk-field"><label for="sk-al-name">Name</label>
                <input id="sk-al-name" maxlength="30" placeholder="Bund der Wolken"></div>
            <div class="sk-field"><label for="sk-al-tag">Kürzel (2–5 Zeichen)</label>
                <input id="sk-al-tag" maxlength="5" placeholder="WOLK" style="text-transform:uppercase"></div>
            ${costList(response.cost)}
            <button class="sk-btn sk-btn--block sk-btn--green" data-action="create">Gründen</button>

            <h3 style="margin-top:22px">Allianz beitreten</h3>
            <ul class="sk-list">${response.alliances.map((alliance) => listItem({
                title: `[${escapeHtml(alliance.tag)}] ${escapeHtml(alliance.name)}`,
                sub: `${alliance.members} Mitglieder · ${compact(alliance.score)} Punkte`,
                right: alliance.open
                    ? `<button class="sk-btn sk-btn--small" data-join="${alliance.id}">Beitreten</button>`
                    : pill('geschlossen', 'muted')
            })).join('') || emptyNote('Noch keine Allianzen vorhanden.')}</ul>`;

        body.querySelector('[data-action="create"]').addEventListener('click', async () => {
            try {
                await api.post('alliance_create', {
                    name: body.querySelector('#sk-al-name').value,
                    tag: body.querySelector('#sk-al-tag').value
                });
                sfx.success();
                toast('Allianz gegründet!', 'gold');
                openAlliance();
            } catch (error) { toastError(error); }
        });

        body.querySelectorAll('[data-join]').forEach((button) => {
            button.addEventListener('click', async () => {
                try {
                    await api.post('alliance_join', { id: button.dataset.join });
                    toast('Beigetreten!', 'ok');
                    openAlliance();
                } catch (error) { toastError(error); }
            });
        });
    } catch (error) { toastError(error); }
}

// ===================================================================
// Handel
// ===================================================================

export async function openTrade() {
    openSheet({ key: 'trade', title: 'Handel', body: '<p class="sk-muted">Wird geladen …</p>' });

    try {
        const response = await api.get('trades');
        const body = sheetBody();
        if (!body) { return; }

        const resources = Object.keys(state.statics.resources);
        const options = (filter) => resources
            .filter((key) => !filter || stored(key) > 0)
            .map((key) => `<option value="${key}">${escapeHtml(resourceName(key))}${filter ? ' (' + compact(stored(key)) + ')' : ''}</option>`)
            .join('');

        body.innerHTML = `
            <h3>Angebot einstellen</h3>
            <div class="sk-row">
                <div class="sk-field sk-grow"><label for="sk-tr-give">Ich gebe</label>
                    <select id="sk-tr-give">${options(true)}</select></div>
                <div class="sk-field sk-grow"><label for="sk-tr-give-a">Menge</label>
                    <input id="sk-tr-give-a" type="number" min="1" value="500" inputmode="numeric"></div>
            </div>
            <div class="sk-row">
                <div class="sk-field sk-grow"><label for="sk-tr-want">Ich möchte</label>
                    <select id="sk-tr-want">${options(false)}</select></div>
                <div class="sk-field sk-grow"><label for="sk-tr-want-a">Menge</label>
                    <input id="sk-tr-want-a" type="number" min="1" value="500" inputmode="numeric"></div>
            </div>
            <button class="sk-btn sk-btn--block sk-btn--green" data-action="offer">Angebot einstellen</button>
            <p class="sk-field__hint">Die angebotene Ware wird sofort aus deinem Lager genommen.
                Ziehst du das Angebot zurück, bekommst du sie zurück.
                Auf Goldzahlungen erhebt der Markt ${Math.round(response.fee * 100)} % Gebühr.</p>

            <h3 style="margin-top:22px">Offene Angebote</h3>
            <ul class="sk-list">${response.offers.map((offer) => {
                const giveKey = Object.keys(offer.give)[0];
                const wantKey = Object.keys(offer.want)[0];
                const mine = offer.seller === response.me;
                return listItem({
                    icon: resourceIcon(giveKey, 32),
                    title: `${compact(offer.give[giveKey])} ${escapeHtml(resourceName(giveKey))}
                            → ${compact(offer.want[wantKey])} ${escapeHtml(resourceName(wantKey))}`,
                    sub: `von ${escapeHtml(offer.seller_name)}`,
                    right: mine
                        ? `<button class="sk-btn sk-btn--small sk-btn--danger" data-cancel="${offer.id}">Zurück</button>`
                        : `<button class="sk-btn sk-btn--small" data-accept="${offer.id}">Annehmen</button>`
                });
            }).join('') || emptyNote('Keine offenen Angebote.')}</ul>`;

        body.querySelector('[data-action="offer"]').addEventListener('click', async () => {
            try {
                await api.post('trade_create', {
                    give: body.querySelector('#sk-tr-give').value,
                    give_amount: body.querySelector('#sk-tr-give-a').value,
                    want: body.querySelector('#sk-tr-want').value,
                    want_amount: body.querySelector('#sk-tr-want-a').value
                });
                sfx.coins();
                toast('Angebot eingestellt.', 'ok');
                openTrade();
            } catch (error) { toastError(error); }
        });

        body.querySelectorAll('[data-accept]').forEach((button) => {
            button.addEventListener('click', async () => {
                try {
                    await api.post('trade_accept', { id: button.dataset.accept });
                    sfx.coins();
                    toast('Handel abgeschlossen!', 'gold');
                    emit('refresh:rates');
                    openTrade();
                } catch (error) { toastError(error); }
            });
        });
        body.querySelectorAll('[data-cancel]').forEach((button) => {
            button.addEventListener('click', async () => {
                try {
                    await api.post('trade_cancel', { id: button.dataset.cancel });
                    toast('Angebot zurückgezogen.', 'info');
                    emit('refresh:rates');
                    openTrade();
                } catch (error) { toastError(error); }
            });
        });
    } catch (error) { toastError(error); }
}

// ===================================================================
// Kampfberichte
// ===================================================================

export async function openReports() {
    openSheet({ key: 'reports', title: 'Kampfberichte', body: '<p class="sk-muted">Wird geladen …</p>' });

    try {
        const response = await api.get('reports');
        const body = sheetBody();
        if (!body) { return; }

        body.innerHTML = '<ul class="sk-list">' + (response.reports.map((report) => {
            const attacker = report.attacker.name;
            const won = report.success;
            return listItem({
                action: true,
                attrs: `data-report="${report.id}"`,
                title: escapeHtml(report.mission_name),
                sub: `${escapeHtml(attacker)} → ${escapeHtml(report.defender.name)} · ${duration(now() - report.ts)} her`,
                right: pill(won ? 'Erfolg' : 'Abgewehrt', won ? 'green' : 'red')
            });
        }).join('') || emptyNote('Noch keine Berichte.')) + '</ul>';

        body.querySelectorAll('[data-report]').forEach((element) => {
            element.addEventListener('click', () => openReport(element.dataset.report));
        });
    } catch (error) { toastError(error); }
}

export async function openReport(reportId) {
    try {
        const response = await api.get('report', { id: reportId });
        const report = response.report;

        const loot = Object.keys(report.loot || {});
        const losses = Object.keys(report.losses || {});

        openSheet({
            key: 'report:' + reportId,
            title: escapeHtml(report.mission_name),
            subtitle: `${escapeHtml(report.attacker.name)} → ${escapeHtml(report.defender.name)}`,
            body: `
                <div class="sk-alert sk-alert--${report.success ? 'ok' : 'error'}">
                    <span>${report.success ? 'Die Mission war erfolgreich.' : 'Der Angriff wurde abgewehrt.'}
                        Fortschritt: ${Math.round(report.progress * 100)} %</span>
                </div>
                ${statGrid([
                    { label: 'Überlebende', value: full(report.survivors) },
                    { label: 'Verluste', value: full(losses.reduce((sum, key) => sum + report.losses[key], 0)) },
                    { label: 'Zeitpunkt', value: duration(now() - report.ts) + ' her' }
                ])}
                ${loot.length ? `<h3>Beute</h3>${costList(report.loot)}` : ''}
                ${report.intel ? `<h3>Aufklärung</h3>${costList(report.intel.store)}
                    <p class="sk-muted">Verteidigung: ${report.intel.defense.buildings} Bauwerke,
                        davon ${report.intel.defense.towers} Türme · ${report.intel.islands} Inseln</p>` : ''}
                ${report.replay ? '<button class="sk-btn sk-btn--block sk-btn--small" data-action="replay" style="margin-top:14px">Wiederholung ansehen</button>' : ''}
            `,
            onMount(body) {
                body.querySelector('[data-action="replay"]')?.addEventListener('click', () => {
                    closeSheet();
                    emit('battle:replay', report);
                });
            }
        });
    } catch (error) { toastError(error); }
}

// ===================================================================
// Einstellungen
// ===================================================================

export function openSettings() {
    const settings = (state.profile && state.profile.settings) || {};
    const check = (key, label, hint) => `
        <label class="sk-check">
            <input type="checkbox" data-setting="${key}" ${settings[key] === false ? '' : 'checked'}>
            <span>${escapeHtml(label)}${hint ? `<br><small class="sk-muted">${escapeHtml(hint)}</small>` : ''}</span>
        </label>`;

    openSheet({
        key: 'settings',
        title: 'Einstellungen',
        body: `
            ${check('sound', 'Soundeffekte')}
            <label class="sk-check">
                <input type="checkbox" data-setting="music" ${settings.music ? 'checked' : ''}>
                <span>Hintergrundmusik</span>
            </label>
            ${check('animations', 'Animationen', 'Ausschalten spart Akku auf schwächeren Geräten')}
            ${check('haptics', 'Vibration')}

            <div class="sk-field" style="margin-top:14px">
                <label for="sk-quality">Grafikqualität</label>
                <select id="sk-quality" data-setting="quality">
                    <option value="auto"${(settings.quality || 'auto') === 'auto' ? ' selected' : ''}>Automatisch</option>
                    <option value="high"${settings.quality === 'high' ? ' selected' : ''}>Hoch</option>
                    <option value="medium"${settings.quality === 'medium' ? ' selected' : ''}>Mittel</option>
                    <option value="low"${settings.quality === 'low' ? ' selected' : ''}>Sparsam</option>
                </select>
            </div>

            <h3 style="margin-top:20px">Königreich</h3>
            <div class="sk-field">
                <label for="sk-kingdom">Name deines Königreichs</label>
                <input id="sk-kingdom" maxlength="30" value="${escapeHtml(state.world.name || '')}">
            </div>
            <button class="sk-btn sk-btn--small" data-action="rename">Namen speichern</button>

            <h3 style="margin-top:20px">Konto</h3>
            <a class="sk-btn sk-btn--small sk-btn--block" href="${api.url('?p=konto')}">Passwort, Konto löschen, Abmelden</a>
            <p class="sk-field__hint" style="margin-top:14px">Version ${escapeHtml((window.SK_BOOT.game || {}).version || '')}</p>
        `,
        onMount(body) {
            const save = async () => {
                const payload = {};
                body.querySelectorAll('[data-setting]').forEach((element) => {
                    const key = element.dataset.setting;
                    payload[key] = element.type === 'checkbox' ? (element.checked ? '1' : '0') : element.value;
                });
                try {
                    const result = await api.post('settings_save', payload);
                    if (state.profile) { state.profile.settings = result.settings; }
                    setAudio(result.settings.sound);
                    setMusic(result.settings.music);
                    setHaptics(result.settings.haptics);
                    emit('settings:changed', result.settings);
                    toast('Gespeichert.', 'ok', 1600);
                } catch (error) { toastError(error); }
            };

            body.querySelectorAll('[data-setting]').forEach((element) => {
                element.addEventListener('change', save);
            });

            body.querySelector('[data-action="rename"]').addEventListener('click', async () => {
                try {
                    const result = await api.post('rename', { name: body.querySelector('#sk-kingdom').value });
                    applyResult(result);
                    toast('Name gespeichert.', 'ok');
                } catch (error) { toastError(error); }
            });
        }
    });
}
