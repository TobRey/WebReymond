/** Wiederkehrende Bausteine für die Sheets. */

import { compact, full, percent, rate } from '../core/num.js';
import { resourceIcon } from '../render/icons.js';
import { state, resourceName, stored } from '../core/state.js';

export function escapeHtml(value) {
    return String(value === undefined || value === null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

/** Liste von Kosten mit Symbolen; fehlende Mengen werden rot markiert. */
export function costList(cost, missing) {
    const keys = Object.keys(cost || {});
    if (keys.length === 0) { return '<div class="sk-muted">kostenlos</div>'; }

    return '<div class="sk-cost">' + keys.map((key) => {
        const lacking = missing && missing[key];
        return `<span class="sk-cost__item${lacking ? ' is-missing' : ''}" title="${escapeHtml(resourceName(key))}">
            ${resourceIcon(key, 20)}<span>${compact(cost[key])}</span>
        </span>`;
    }).join('') + '</div>';
}

/** Rohstoffmenge als Chip. */
export function resourceChip(key, amount) {
    return `<span class="sk-cost__item">${resourceIcon(key, 20)}<span>${compact(amount)}</span></span>`;
}

/** Feine Anzeige kleiner Werte: 2,02 statt 2,0 – sonst wirkt eine Stufe wirkungslos. */
export function fine(value) {
    const number = Number(value) || 0;
    if (Math.abs(number) >= 1000) { return compact(number); }
    if (Number.isInteger(number)) { return full(number); }
    return full(number, Math.abs(number) < 10 ? 2 : 1);
}

export function statGrid(items) {
    return '<div class="sk-stat-grid">' + items.map((item) => `
        <div class="sk-stat">
            <div class="sk-stat__label">${escapeHtml(item.label)}</div>
            <div class="sk-stat__value">${item.value}</div>
            ${item.delta ? `<div class="sk-stat__delta">${item.delta}</div>` : ''}
        </div>`).join('') + '</div>';
}

export function bar(ratio, full_) {
    const width = Math.max(0, Math.min(100, Math.round(ratio * 100)));
    return `<div class="sk-bar"><div class="sk-bar__fill${full_ ? ' is-full' : ''}" style="width:${width}%"></div></div>`;
}

export function listItem(options) {
    return `<li class="sk-list__item${options.action ? ' sk-list__item--action' : ''}"
                ${options.attrs || ''}>
        ${options.icon || ''}
        <div class="sk-grow">
            <div class="sk-list__title">${options.title}</div>
            ${options.sub ? `<div class="sk-list__sub">${options.sub}</div>` : ''}
            ${options.extra || ''}
        </div>
        ${options.right ? `<div class="sk-list__right">${options.right}</div>` : ''}
    </li>`;
}

export function pill(text, kind = '') {
    return `<span class="sk-pill${kind ? ' sk-pill--' + kind : ''}">${escapeHtml(text)}</span>`;
}

export function emptyNote(text) {
    return `<p class="sk-muted sk-center" style="padding:20px 0">${escapeHtml(text)}</p>`;
}

export { compact, full, percent, rate, stored };
