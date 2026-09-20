/** Kurze Hinweise am unteren Rand. */

import { sfx } from '../core/audio.js';
import { haptics } from '../core/haptics.js';

const container = () => document.getElementById('sk-toasts');

export function toast(message, kind = 'info', duration = 3200) {
    const host = container();
    if (!host) { return; }

    const element = document.createElement('div');
    element.className = 'sk-toast' + (kind === 'ok' ? ' sk-toast--ok' : (kind === 'bad' ? ' sk-toast--bad' : (kind === 'gold' ? ' sk-toast--gold' : '')));
    element.textContent = message;
    host.appendChild(element);

    if (kind === 'bad') { sfx.error(); haptics.error(); }
    else if (kind === 'ok') { sfx.success(); haptics.success(); }

    setTimeout(() => {
        element.classList.add('is-leaving');
        setTimeout(() => element.remove(), 260);
    }, duration);

    // Höchstens vier gleichzeitig
    while (host.children.length > 4) { host.firstChild.remove(); }
}

export function toastError(error) {
    toast(error && error.message ? error.message : String(error), 'bad', 4200);
}
