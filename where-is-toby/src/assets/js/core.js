/* =============================================================
   Kernbausteine: API-Zugriff, DOM-Helfer, Ton, Overlays, Toasts
   ============================================================= */

export const bootstrap = (() => {
    const node = document.getElementById('wit-bootstrap') || document.getElementById('wit-admin-bootstrap');
    if (!node) return {};
    try { return JSON.parse(node.textContent || '{}'); } catch { return {}; }
})();

/* ---------------------------- DOM ---------------------------- */

export function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
        if (value === null || value === undefined || value === false) continue;
        if (key === 'class') node.className = value;
        else if (key === 'text') node.textContent = value;
        else if (key === 'html') node.innerHTML = value;
        else if (key === 'style' && typeof value === 'object') Object.assign(node.style, value);
        else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
        else if (value === true) node.setAttribute(key, '');
        else node.setAttribute(key, String(value));
    }
    for (const child of [].concat(children)) {
        if (child === null || child === undefined || child === false) continue;
        node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return node;
}

export const $ = (selector, scope = document) => scope.querySelector(selector);
export const $$ = (selector, scope = document) => Array.from(scope.querySelectorAll(selector));

export function clear(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
    return node;
}

export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[char]));
}

/** Absatzweise Textausgabe ohne HTML-Injektion. */
export function paragraphs(text) {
    const wrapper = document.createDocumentFragment();
    String(text ?? '').split(/\n{2,}/).forEach((block) => {
        if (!block.trim()) return;
        wrapper.append(el('p', { text: block.trim() }));
    });
    return wrapper;
}

export function formatTime(seconds) {
    const total = Math.max(0, Math.floor(seconds));
    const minutes = Math.floor(total / 60);
    const rest = total % 60;
    if (minutes >= 60) {
        const hours = Math.floor(minutes / 60);
        return `${String(hours).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
    }
    return `${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
}

export function clockFromIso(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
}

/* ---------------------------- API ---------------------------- */

const BASE = (bootstrap.base || '').replace(/\/$/, '');
const CSRF = bootstrap.csrf || '';

export class ApiError extends Error {
    constructor(message, status) {
        super(message);
        this.status = status;
    }
}

export async function api(path, options = {}) {
    const { method = 'GET', body = null, timeout = 45000 } = options;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);

    try {
        const response = await fetch(BASE + path, {
            method,
            headers: {
                'Accept': 'application/json',
                ...(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
                'X-CSRF-Token': CSRF,
                'X-Requested-With': 'fetch',
            },
            body: body instanceof FormData ? body : (body ? JSON.stringify({ ...body, _csrf: CSRF }) : null),
            signal: controller.signal,
            credentials: 'same-origin',
        });

        const text = await response.text();
        let data = {};
        try { data = text ? JSON.parse(text) : {}; } catch { data = {}; }

        if (!response.ok || data.ok === false) {
            throw new ApiError(data.error || `Serverfehler (${response.status})`, response.status);
        }
        return data;
    } catch (error) {
        if (error.name === 'AbortError') {
            throw new ApiError('Zeitueberschreitung. Die Verbindung zur Zentrale ist gestoert.', 0);
        }
        if (error instanceof ApiError) throw error;
        throw new ApiError('Keine Verbindung zur Zentrale.', 0);
    } finally {
        clearTimeout(timer);
    }
}

export const post = (path, body) => api(path, { method: 'POST', body });
export const get = (path) => api(path);

/* ---------------------------- Toasts ---------------------------- */

export function toast(message, { title = '', kind = '', timeout = 5200 } = {}) {
    const host = document.getElementById('toasts') || document.body;
    const node = el('div', { class: `toast ${kind ? 'toast--' + kind : ''}` }, [
        title ? el('strong', { text: title }) : null,
        el('span', { text: message }),
    ]);
    host.append(node);
    setTimeout(() => {
        node.style.transition = 'opacity .3s ease, transform .3s ease';
        node.style.opacity = '0';
        node.style.transform = 'translateX(14px)';
        setTimeout(() => node.remove(), 320);
    }, timeout);
    return node;
}

/* ---------------------------- Overlay ---------------------------- */

const overlayState = { onClose: null };

export function openOverlay(title, content, { onClose = null, wide = false } = {}) {
    const overlay = document.getElementById('overlay');
    if (!overlay) return null;
    const titleNode = document.getElementById('overlay-title');
    const body = document.getElementById('overlay-body');
    titleNode.textContent = title;
    clear(body);
    body.append(content instanceof Node ? content : document.createTextNode(String(content)));
    overlay.hidden = false;
    overlay.querySelector('.overlay__box').style.width = wide ? 'min(1180px, 100%)' : '';
    overlayState.onClose = onClose;
    document.getElementById('overlay-close')?.focus();
    return body;
}

export function closeOverlay() {
    const overlay = document.getElementById('overlay');
    if (!overlay || overlay.hidden) return;
    overlay.hidden = true;
    clear(document.getElementById('overlay-body'));
    if (typeof overlayState.onClose === 'function') {
        const callback = overlayState.onClose;
        overlayState.onClose = null;
        callback();
    }
}

export function isOverlayOpen() {
    const overlay = document.getElementById('overlay');
    return overlay && !overlay.hidden;
}

export function initOverlay() {
    document.getElementById('overlay-close')?.addEventListener('click', closeOverlay);
    document.getElementById('overlay')?.addEventListener('click', (event) => {
        if (event.target.id === 'overlay') closeOverlay();
    });
}

/* ---------------------------- Ton ---------------------------- */

class SoundManager {
    constructor() {
        this.enabled = true;
        this.volume = 0.7;
        this.context = null;
        this.cache = new Map();
        this.ambience = null;
    }

    setVolume(value) {
        this.volume = Math.max(0, Math.min(1, Number(value) || 0));
    }

    setEnabled(value) {
        this.enabled = Boolean(value);
        if (!this.enabled) this.stopAmbience();
    }

    ctx() {
        if (!this.context) {
            const Ctor = window.AudioContext || window.webkitAudioContext;
            if (!Ctor) return null;
            this.context = new Ctor();
        }
        if (this.context.state === 'suspended') this.context.resume().catch(() => {});
        return this.context;
    }

    url(track) {
        return `${BASE}/klang/${encodeURIComponent(track)}.wav`;
    }

    async buffer(track) {
        if (this.cache.has(track)) return this.cache.get(track);
        const context = this.ctx();
        if (!context) return null;
        try {
            const response = await fetch(this.url(track), { credentials: 'same-origin' });
            const data = await response.arrayBuffer();
            const decoded = await context.decodeAudioData(data);
            this.cache.set(track, decoded);
            return decoded;
        } catch {
            return null;
        }
    }

    async play(track, { gain = 1, loop = false, rate = 1 } = {}) {
        if (!this.enabled || this.volume <= 0) return null;
        const context = this.ctx();
        const buffer = await this.buffer(track);
        if (!context || !buffer) return null;
        const source = context.createBufferSource();
        source.buffer = buffer;
        source.loop = loop;
        source.playbackRate.value = rate;
        const amp = context.createGain();
        amp.gain.value = this.volume * gain;
        source.connect(amp).connect(context.destination);
        source.start();
        return { source, amp };
    }

    async startAmbience(track = 'amb_station') {
        if (!this.enabled || this.ambience) return;
        this.ambience = await this.play(track, { gain: 0.28, loop: true });
    }

    stopAmbience() {
        if (this.ambience) {
            try { this.ambience.source.stop(); } catch { /* bereits gestoppt */ }
            this.ambience = null;
        }
    }
}

export const sound = new SoundManager();

/* ---------------------------- Speicher ---------------------------- */

export const store = {
    get(key, fallback = null) {
        try {
            const raw = localStorage.getItem('wit:' + key);
            return raw === null ? fallback : JSON.parse(raw);
        } catch { return fallback; }
    },
    set(key, value) {
        try { localStorage.setItem('wit:' + key, JSON.stringify(value)); } catch { /* privater Modus */ }
    },
};

/* ---------------------------- Diverses ---------------------------- */

export function debounce(fn, delay = 300) {
    let timer = null;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
}

export function assetUrl(path) {
    if (!path) return '';
    if (/^(https?:)?\/\//.test(path) || path.startsWith('data:')) return path;
    return BASE + '/' + String(path).replace(/^\/+/, '');
}

export function sectionTitle(title, subtitle = '', actions = []) {
    return el('header', { class: 'panel-head' }, [
        el('div', {}, [
            el('h2', { text: title }),
            subtitle ? el('p', { class: 'hint', text: subtitle }) : null,
        ]),
        actions.length ? el('div', { class: 'toolbar', style: { margin: 0 } }, actions) : null,
    ]);
}
