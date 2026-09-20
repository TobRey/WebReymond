/**
 * Bottom-Sheet – auf grossen Bildschirmen wird daraus ein Seitenpanel
 * (siehe game.css). Ein einziges Element für alle Inhalte.
 */

import { sfx } from '../core/audio.js';

let current = null;
let onClose = null;

function elements() {
    return {
        sheet: document.getElementById('sk-sheet'),
        backdrop: document.getElementById('sk-backdrop'),
        title: document.getElementById('sk-sheet-title'),
        sub: document.getElementById('sk-sheet-sub'),
        body: document.getElementById('sk-sheet-body'),
        icon: document.getElementById('sk-sheet-icon')
    };
}

export function openSheet(options) {
    const el = elements();
    if (!el.sheet) { return; }

    current = options.key || null;
    onClose = options.onClose || null;

    el.title.textContent = options.title || '';
    el.sub.innerHTML = options.subtitle || '';
    el.body.innerHTML = options.body || '';

    if (options.iconSvg) {
        el.icon.outerHTML = `<span class="sk-sheet__icon" id="sk-sheet-icon">${options.iconSvg}</span>`;
    } else {
        const icon = document.getElementById('sk-sheet-icon');
        if (icon) { icon.className = 'sk-sheet__icon sk-hidden'; }
    }

    el.sheet.classList.add('is-open');
    el.backdrop.classList.add('is-open');
    el.body.scrollTop = 0;
    sfx.open();

    if (typeof options.onMount === 'function') {
        options.onMount(el.body);
    }
}

export function updateSheet(html) {
    const el = elements();
    if (el.body && el.sheet.classList.contains('is-open')) {
        el.body.innerHTML = html;
    }
}

export function sheetBody() {
    return document.getElementById('sk-sheet-body');
}

export function closeSheet() {
    const el = elements();
    if (!el.sheet || !el.sheet.classList.contains('is-open')) { return; }

    el.sheet.classList.remove('is-open');
    el.backdrop.classList.remove('is-open');
    sfx.close();

    const callback = onClose;
    current = null;
    onClose = null;
    if (callback) { callback(); }
}

export function isOpen(key) {
    const el = elements();
    if (!el.sheet || !el.sheet.classList.contains('is-open')) { return false; }
    return key ? current === key : true;
}

export function currentKey() {
    return current;
}

/** Einmalig die Schliess-Ereignisse verbinden. */
export function initSheet() {
    const el = elements();
    if (!el.sheet) { return; }

    document.getElementById('sk-sheet-close').addEventListener('click', closeSheet);
    el.backdrop.addEventListener('click', closeSheet);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') { closeSheet(); }
    });

    // Nach unten wischen schliesst
    let startY = 0;
    let dragging = false;
    const grip = el.sheet.querySelector('.sk-sheet__grip');

    const start = (event) => {
        if (window.innerWidth >= 900) { return; }
        startY = event.touches ? event.touches[0].clientY : event.clientY;
        dragging = true;
    };
    const move = (event) => {
        if (!dragging) { return; }
        const y = event.touches ? event.touches[0].clientY : event.clientY;
        const delta = y - startY;
        if (delta > 0) {
            el.sheet.style.transform = `translateY(${delta}px)`;
        }
    };
    const end = (event) => {
        if (!dragging) { return; }
        dragging = false;
        const y = event.changedTouches ? event.changedTouches[0].clientY : event.clientY;
        el.sheet.style.transform = '';
        if (y - startY > 90) { closeSheet(); }
    };

    [el.sheet.querySelector('.sk-sheet__head'), grip].forEach((handle) => {
        if (!handle) { return; }
        handle.addEventListener('touchstart', start, { passive: true });
        handle.addEventListener('touchmove', move, { passive: true });
        handle.addEventListener('touchend', end);
    });
}
