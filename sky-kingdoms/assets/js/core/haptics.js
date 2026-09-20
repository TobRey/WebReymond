/** Kurzes Vibrieren auf Geräten, die es unterstützen. */

let enabled = true;

export function setEnabled(value) { enabled = !!value; }

function buzz(pattern) {
    if (!enabled) { return; }
    if (navigator.vibrate) {
        try { navigator.vibrate(pattern); } catch (error) { /* egal */ }
    }
}

export const haptics = {
    light()   { buzz(8); },
    medium()  { buzz(18); },
    heavy()   { buzz([22, 30, 22]); },
    success() { buzz([12, 40, 24]); },
    error()   { buzz([30, 60, 30]); }
};
