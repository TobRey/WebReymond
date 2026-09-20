/**
 * Verbindung zum Server.
 *
 * - Alle verändernden Aufrufe gehen per POST mit CSRF-Token.
 * - Bei Verbindungsabbruch wird automatisch wiederholt (mit Wartezeit).
 * - Die Basisadresse kommt vom Server; nichts ist fest verdrahtet.
 */

const boot = window.SK_BOOT || {};

let online = navigator.onLine !== false;
const listeners = new Set();

window.addEventListener('online', () => setOnline(true));
window.addEventListener('offline', () => setOnline(false));

function setOnline(value) {
    if (online === value) { return; }
    online = value;
    listeners.forEach((fn) => fn(online));
}

export function onConnectionChange(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

export function isOnline() {
    return online;
}

export const base = boot.base || '/';
export const assets = boot.assets || (base + 'assets/');

/** Adresse eines Projektpfads. */
export function url(path) {
    return base + String(path).replace(/^\/+/, '');
}

/** Adresse einer Datei in assets/. */
export function asset(path) {
    return assets + String(path).replace(/^\/+/, '') + (boot.v ? '?v=' + boot.v : '');
}

class ApiError extends Error {
    constructor(message, status, data) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data || {};
    }
}

export { ApiError };

async function request(action, data, method, attempt = 0, query = '') {
    const target = (boot.api || url('api/')) + '?a=' + encodeURIComponent(action)
        + (query ? '&' + query : '');
    const options = {
        method,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch' },
        cache: 'no-store'
    };

    if (method === 'POST') {
        const body = new URLSearchParams();
        Object.keys(data || {}).forEach((key) => {
            const value = data[key];
            if (value !== undefined && value !== null) { body.append(key, String(value)); }
        });
        body.append('_token', boot.csrf || '');
        options.body = body;
        options.headers['X-SK-CSRF'] = boot.csrf || '';
        options.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
    }

    let response;
    try {
        response = await fetch(target, options);
    } catch (error) {
        // Netzproblem: bis zu drei Versuche mit steigender Wartezeit.
        if (attempt < 3) {
            await new Promise((resolve) => setTimeout(resolve, 400 * Math.pow(2, attempt)));
            return request(action, data, method, attempt + 1, query);
        }
        setOnline(false);
        throw new ApiError('Keine Verbindung zum Server.', 0, {});
    }

    setOnline(true);

    let payload = {};
    const text = await response.text();
    try { payload = text ? JSON.parse(text) : {}; } catch (error) { payload = {}; }

    if (!response.ok || payload.ok === false) {
        // Sitzung abgelaufen: zurück zur Anmeldung
        if (response.status === 401) {
            window.location.href = url('?p=login');
        }
        // Serverseitig ausgelastet: kurz warten und erneut versuchen
        if (response.status === 429 && attempt < 2) {
            const wait = Math.max(1, Number(payload.retry_after) || 1);
            await new Promise((resolve) => setTimeout(resolve, Math.min(wait, 5) * 1000));
            return request(action, data, method, attempt + 1, query);
        }
        throw new ApiError(payload.error || 'Das hat nicht geklappt.', response.status, payload);
    }

    return payload;
}

export function get(action, params) {
    const query = params
        ? Object.keys(params).map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k])).join('&')
        : '';
    return request(action, null, 'GET', 0, query);
}

export function post(action, data) {
    return request(action, data || {}, 'POST');
}
