<?php http_response_code(404); exit; ?>
/* CASH – privates Finanzsystem. Vanilla JS, keine Abhängigkeiten. */
(() => {
  'use strict';

  // ================================================================ Grundlagen
  const $app = document.getElementById('app');
  const SVGNS = 'http://www.w3.org/2000/svg';
  const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
  const MONTHS_S = ['Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sept.', 'Okt.', 'Nov.', 'Dez.'];
  const WEEKDAYS = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
  const INTERVALS = {
    once: 'Einmalig', weekly: 'Wöchentlich', biweekly: 'Alle 2 Wochen', monthly: 'Monatlich',
    quarterly: 'Vierteljährlich', semiannual: 'Halbjährlich', yearly: 'Jährlich',
  };
  const ACCOUNT_TYPES = { checking: 'Privatkonto', savings: 'Sparkonto', cash: 'Bargeld', card: 'Kreditkarte', other: 'Anderes' };
  const ACCOUNT_ICONS = { checking: 'wallet', savings: 'piggy', cash: 'coins', card: 'card', other: 'bank' };
  const CAT_ICONS = ['cart', 'coffee', 'home', 'bus', 'car', 'heart', 'shield', 'ticket', 'plane', 'bag', 'play', 'phone',
    'book', 'gift', 'file', 'dots', 'briefcase', 'coins', 'rotate', 'tag', 'percent', 'bolt', 'paw', 'dumbbell', 'shirt',
    'music', 'wallet', 'piggy', 'bank', 'card', 'sparkles', 'users'];

  const ICONS = {
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/><path d="M10 20v-5.5h4V20"/>',
    list: '<path d="M8.5 6h12M8.5 12h12M8.5 18h12"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01" stroke-width="2.6"/>',
    pie: '<path d="M21 13.5A9 9 0 1 1 10.5 3v10.5z"/><path d="M14 3.2A8.5 8.5 0 0 1 20.8 10H14z"/>',
    grid: '<rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    minus: '<path d="M5 12h14"/>',
    in: '<path d="M17 7 7 17"/><path d="M7 9v8h8"/>',
    out: '<path d="M7 17 17 7"/><path d="M9 7h8v8"/>',
    transfer: '<path d="M7 4 3 8l4 4"/><path d="M3 8h14"/><path d="m17 20 4-4-4-4"/><path d="M21 16H7"/>',
    receipt: '<path d="M6 2.5h12v19l-3-2-3 2-3-2-3 2z"/><path d="M9 7.5h6M9 11.5h6M9 15.5h3.5"/>',
    target: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.2"/>',
    piggy: '<path d="M19 9.5c.9.6 1.4 1.5 1.6 2.5h1.4v3h-1.8a6.4 6.4 0 0 1-2.2 2.4V20h-3v-1.5a8.6 8.6 0 0 1-3 0V20h-3v-2.6A5.8 5.8 0 0 1 5.5 12.5C5.5 9 8.6 6.5 12.5 6.5c1.2 0 2.3.2 3.3.6L18 5.5z"/><path d="M16 11h.01" stroke-width="2.4"/><path d="M5.5 11.5C4 11.5 3 10.5 3 9"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18 14.3a6.5 6.5 0 0 1 3.5 5.7"/>',
    wallet: '<path d="M19 7V5.5A1.5 1.5 0 0 0 17.5 4H5a2 2 0 0 0 0 4h14a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a2 2 0 0 1-2-2V6"/><path d="M16.5 14h.01" stroke-width="2.6"/>',
    bank: '<path d="M3 9.5 12 4l9 5.5"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8"/><path d="M3 20.5h18"/>',
    card: '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6.5 15h4"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    chevL: '<path d="m15 18-6-6 6-6"/>',
    chevR: '<path d="m9 18 6-6-6-6"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    trash: '<path d="M3.5 6h17"/><path d="M8.5 6V4h7v2"/><path d="m18.5 6-.9 14H6.4L5.5 6"/><path d="M10 10.5v6M14 10.5v6"/>',
    edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    calendar: '<rect x="3" y="4.5" width="18" height="16.5" rx="2.5"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
    download: '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
    upload: '<path d="M12 21V9"/><path d="m7 14 5-5 5 5"/><path d="M5 3h14"/>',
    alert: '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5"/><path d="M12 16.5h.01" stroke-width="2.4"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5"/><path d="M12 7.6h.01" stroke-width="2.4"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>',
    repeat: '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
    lock: '<rect x="4" y="10.5" width="16" height="10.5" rx="2.5"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    cart: '<circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/><path d="M2.5 3h2.6l2.4 12.2a1.5 1.5 0 0 0 1.5 1.2h8.8a1.5 1.5 0 0 0 1.5-1.2L21 7H6"/>',
    coffee: '<path d="M4 8h13v6a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5z"/><path d="M17 9.5h1.5a2.5 2.5 0 0 1 0 5H17"/><path d="M8 2.5V5M12 2.5V5"/>',
    bus: '<rect x="4" y="3" width="16" height="15" rx="3"/><path d="M4 11h16M8 18v2.5M16 18v2.5"/><path d="M8 14.5h.01M16 14.5h.01" stroke-width="2.4"/>',
    car: '<path d="M5 17H3.5v-4.5L5.5 7a2 2 0 0 1 1.9-1.4h9.2A2 2 0 0 1 18.5 7l2 5.5V17H19"/><path d="M3.5 12.5h17"/><circle cx="7.5" cy="17" r="2"/><circle cx="16.5" cy="17" r="2"/><path d="M9.5 17h5"/>',
    heart: '<path d="M20.4 5.6a5.3 5.3 0 0 0-7.5 0L12 6.5l-.9-.9a5.3 5.3 0 0 0-7.5 7.5l.9.9L12 21.5l7.5-7.5.9-.9a5.3 5.3 0 0 0 0-7.5z"/>',
    shield: '<path d="M12 21.5s8-3.8 8-9.8V5.2L12 2.5 4 5.2v6.5c0 6 8 9.8 8 9.8z"/>',
    ticket: '<path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-2a2 2 0 0 0 0-4z"/><path d="M14 6v2M14 11v2M14 16v2"/>',
    plane: '<path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>',
    bag: '<path d="M5 7.5h14l-1 13.5H6z"/><path d="M9 10.5V6.5a3 3 0 0 1 6 0v4"/>',
    play: '<rect x="2.5" y="4.5" width="19" height="15" rx="3"/><path d="m10 9 5 3-5 3z"/>',
    phone: '<rect x="6" y="2.5" width="12" height="19" rx="3"/><path d="M11 18h2"/>',
    book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
    gift: '<rect x="3" y="8" width="18" height="4.5" rx="1"/><path d="M5 12.5V21h14v-8.5M12 8v13"/><path d="M12 8H8.5a2.5 2.5 0 1 1 0-5C11 3 12 8 12 8zM12 8h3.5a2.5 2.5 0 1 0 0-5C13 3 12 8 12 8z"/>',
    file: '<path d="M14 2.5H6a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8.5z"/><path d="M14 2.5v6h6M8.5 13h7M8.5 17h5"/>',
    dots: '<path d="M5.5 12h.01M12 12h.01M18.5 12h.01" stroke-width="3"/>',
    briefcase: '<rect x="3" y="7" width="18" height="13" rx="2.5"/><path d="M8.5 7V5a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v2M3 13h18"/>',
    coins: '<circle cx="9" cy="9" r="6"/><path d="M18.1 10.4A6 6 0 1 1 10.4 18.1"/><path d="M8 7h1.5v4"/>',
    rotate: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
    tag: '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><path d="M7.5 7.5h.01" stroke-width="2.6"/>',
    percent: '<path d="M19 5 5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    bolt: '<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>',
    paw: '<circle cx="11" cy="4.5" r="2"/><circle cx="18" cy="8.5" r="2"/><circle cx="4" cy="8.5" r="2"/><path d="M8.5 14c0-2 1.5-4 3.5-4s3.5 2 3.5 4c0 1 .8 2 2 3s1 4-2 4c-1.5 0-2-1-3.5-1s-2 1-3.5 1c-3 0-3.2-3-2-4s2-2 2-3z"/>',
    dumbbell: '<path d="M6.5 6.5v11M17.5 6.5v11M3 9.5v5M21 9.5v5M6.5 12h11"/>',
    shirt: '<path d="M20.4 6.6 16 4a4 4 0 0 1-8 0L3.6 6.6 5 11l2-1v11h10V10l2 1z"/>',
    music: '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
    sparkles: '<path d="M12 3 13.9 8.1 19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15v4M17 17h4"/>',
    trend: '<path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
    archive: '<rect x="2.5" y="3.5" width="19" height="5" rx="1.5"/><path d="M4.5 8.5V19a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V8.5M10 13h4"/>',
    undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
    skip: '<path d="m5 4 10 8-10 8z"/><path d="M19 5v14"/>',
    devices: '<rect x="2.5" y="4" width="13" height="10" rx="2"/><path d="M5.5 18h7"/><rect x="17" y="8" width="4.5" height="12" rx="1.5"/>',
    filter: '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
  };

  function icon(name, cls) {
    const s = document.createElementNS(SVGNS, 'svg');
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('class', 'i' + (cls ? ' ' + cls : ''));
    s.setAttribute('aria-hidden', 'true');
    s.innerHTML = ICONS[name] || ICONS.dots;   // nur feste, interne Zeichenketten
    return s;
  }

  /** Sicherer Element-Baukasten: Texte werden nie als HTML interpretiert. */
  function h(tag, attrs, ...kids) {
    const el = document.createElement(tag);
    if (attrs) {
      for (const [k, v] of Object.entries(attrs)) {
        if (v === null || v === undefined || v === false) continue;
        if (k === 'class') el.className = v;
        else if (k === 'text') el.textContent = v;
        else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
        else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
        else if (['value', 'checked', 'disabled', 'selected', 'type'].includes(k)) el[k] = v;
        else el.setAttribute(k, v === true ? '' : String(v));
      }
    }
    append(el, kids);
    return el;
  }
  function append(el, kids) {
    for (const k of kids) {
      if (k === null || k === undefined || k === false) continue;
      if (Array.isArray(k)) append(el, k);
      else if (k instanceof Node) el.appendChild(k);
      else el.appendChild(document.createTextNode(String(k)));
    }
    return el;
  }

  // ================================================================ Formate
  const APOS = '’';
  const MINUS = '−';
  function fmt(c) {
    const neg = c < 0;
    const a = Math.abs(c);
    const int = Math.floor(a / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, APOS);
    return (neg ? MINUS : '') + int + '.' + String(a % 100).padStart(2, '0');
  }
  const chf = (c) => 'CHF ' + fmt(c);
  function fmtShort(c) {
    return Math.round(c / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, APOS);
  }
  function signed(c, type) {
    if (type === 'income' || type === 'debt_in') return '+ ' + fmt(c);
    if (type === 'expense' || type === 'debt_out') return MINUS + ' ' + fmt(c);
    return fmt(c);
  }
  /** "1'234.50" / "12,5" → Rappen (int) oder null. Spiegelt die Serverprüfung. */
  function parseAmount(v) {
    let s = String(v || '').trim().replace(/[’'   ]/g, '').replace(',', '.');
    const m = /^(\d{1,10})(?:\.(\d{0,2}))?$/.exec(s);
    if (!m) return null;
    return parseInt(m[1], 10) * 100 + parseInt((m[2] || '').padEnd(2, '0'), 10);
  }
  function parseRate(v) {
    const s = String(v || '').trim().replace(/[’' ]/g, '').replace(',', '.');
    const m = /^(\d{1,6})(?:\.(\d{0,6}))?$/.exec(s);
    if (!m) return null;
    const micro = BigInt(m[1]) * 1000000n + BigInt((m[2] || '').padEnd(6, '0'));
    return micro > 0n ? micro : null;
  }
  /** Gleiche Rundung wie der Server: half up auf ganze Rappen. */
  function fxToChf(minor, rateMicro) {
    const num = BigInt(minor) * 1000000n;
    return Number((2n * num + rateMicro) / (2n * rateMicro));
  }
  const toInput = (c) => (c === null || c === undefined) ? '' : (c / 100).toFixed(2);
  function d8(s) { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); }
  function fmtDate(s, withYear = true) {
    if (!s) return '';
    const d = d8(s);
    return d.getDate() + '. ' + MONTHS_S[d.getMonth()] + (withYear ? ' ' + d.getFullYear() : '');
  }
  function fmtDay(s) {
    const t = today();
    if (s === t) return 'Heute';
    if (s === addDays(t, -1)) return 'Gestern';
    const d = d8(s);
    return WEEKDAYS[d.getDay()] + ', ' + fmtDate(s, d.getFullYear() !== d8(t).getFullYear());
  }
  function addDays(s, n) {
    const d = d8(s); d.setDate(d.getDate() + n);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function monthLabel(m) { const [y, mo] = m.split('-').map(Number); return MONTHS[mo - 1] + ' ' + y; }
  function addMonth(m, n) {
    const [y, mo] = m.split('-').map(Number);
    const t = y * 12 + (mo - 1) + n;
    return Math.floor(t / 12) + '-' + String(t % 12 + 1).padStart(2, '0');
  }
  function uid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    const a = new Uint8Array(16); crypto.getRandomValues(a);
    return Array.from(a, (b) => b.toString(16).padStart(2, '0')).join('');
  }
  function clientToday() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  const today = () => (S.st && S.st.today) || clientToday();

  // ================================================================ Zustand & API
  const S = {
    status: null, user: null, st: null, month: null, csrf: '',
    tx: { q: '', month: null, type: '', account: '', category: '' },
    catTab: 'expense', lastRoute: '',
  };

  class ApiFail extends Error { constructor(msg, status) { super(msg); this.status = status; } }

  async function api(action, body, query) {
    let url = 'api.php?a=' + encodeURIComponent(action);
    if (query) for (const [k, v] of Object.entries(query)) if (v !== undefined && v !== null) url += '&' + k + '=' + encodeURIComponent(v);
    const opts = { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = S.csrf;
      opts.body = JSON.stringify(body);
    }
    let res, data;
    try {
      res = await fetch(url, opts);
    } catch (e) {
      throw new ApiFail('Keine Verbindung. Bitte Internet prüfen.', 0);
    }
    try { data = await res.json(); } catch (e) { data = { ok: false, error: 'Unerwartete Antwort vom Server.' }; }
    if (data && data.csrf) S.csrf = data.csrf;
    if (!res.ok || !data.ok) {
      if (res.status === 401 && data && data.pin) {
        location.reload();   // PIN-Session abgelaufen → Server zeigt die PIN-Seite
        throw new ApiFail('PIN erforderlich.', 401);
      }
      if (res.status === 401 && action !== 'auth.login') {
        S.user = null; S.st = null;
        closeAllSheets();
        render();
      }
      throw new ApiFail((data && data.error) || 'Fehler', res.status);
    }
    return data;
  }

  /** Änderung senden. requestId verhindert doppelte Speicherung (z. B. Doppelklick). */
  async function mutate(action, payload, requestId) {
    const body = Object.assign({}, payload, { month: S.month, requestId: requestId || uid() });
    let r;
    try {
      r = await api(action, body);
    } catch (e) {
      if (e.status === 419) { await refreshStatus(); r = await api(action, body); } else throw e;
    }
    if (r.state) { S.st = r.state; S.month = r.state.month; }
    if (r.user) S.user = r.user;
    render();
    if (r.message) toast(r.message);
    return r;
  }

  async function refreshStatus() {
    const r = await api('auth.status');
    S.status = r; S.csrf = r.csrf; S.user = r.user;
    return r;
  }

  async function loadState(month) {
    const r = await api('state', undefined, { month: month || S.month || undefined });
    S.st = r.state; S.user = r.user; S.month = r.state.month;
    if (!S.tx.month) S.tx.month = S.month;
  }

  // Lookups
  const byId = (list, id) => list.find((x) => x.id === id) || null;
  const acc = (id) => byId(S.st.accounts, id);
  const cat = (id) => byId(S.st.categories, id);
  const bud = (id) => byId(S.st.budgets, id);
  const bill = (id) => byId(S.st.bills, id);
  const activeAccounts = () => S.st.accounts.filter((a) => !a.archived);
  function defaultAccountId() {
    const last = acc(S.st.settings.lastAccountId);
    if (last && !last.archived) return last.id;
    const a = activeAccounts()[0];
    return a ? a.id : '';
  }

  // ================================================================ UI-Bausteine
  let toastBox = null;
  function toast(msg, isErr) {
    if (!toastBox) { toastBox = h('div', { class: 'toasts', role: 'status' }); document.body.appendChild(toastBox); }
    const t = h('div', { class: 'toast' + (isErr ? ' err' : '') }, icon(isErr ? 'alert' : 'check', 's'), msg);
    toastBox.appendChild(t);
    setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 260); }, isErr ? 4200 : 2600);
  }

  const openSheets = [];
  function sheet(title, build) {
    const body = h('div', { class: 'sheet-b' });
    const titleEl = h('h3', { text: title });
    const box = h('div', { class: 'sheet', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
      h('div', { class: 'sheet-h' }, titleEl, h('button', { class: 'icon-btn flat', 'aria-label': 'Schliessen', onclick: () => ctl.close() }, icon('x'))),
      body);
    const overlay = h('div', { class: 'overlay' }, box);
    let closed = false;
    const onKey = (e) => { if (e.key === 'Escape' && openSheets[openSheets.length - 1] === ctl) ctl.close(); };
    const ctl = {
      body, overlay,
      setTitle: (t) => { titleEl.textContent = t; },
      close() {
        if (closed) return; closed = true;
        document.removeEventListener('keydown', onKey);
        const i = openSheets.indexOf(ctl); if (i >= 0) openSheets.splice(i, 1);
        overlay.classList.add('closing');
        setTimeout(() => { overlay.remove(); if (!openSheets.length) document.body.style.overflow = ''; }, 180);
        if (ctl.onClose) ctl.onClose();
      },
    };
    overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) ctl.close(); });
    document.addEventListener('keydown', onKey);
    openSheets.push(ctl);
    document.body.style.overflow = 'hidden';
    document.body.appendChild(overlay);
    build(body, ctl);
    return ctl;
  }
  function closeAllSheets() { [...openSheets].forEach((s) => s.close()); }

  function confirmBox(title, text, okLabel, danger) {
    return new Promise((resolve) => {
      let done = false;
      const s = sheet(title, (b, c) => {
        append(b, [
          h('p', { class: 'muted', style: { margin: '0 0 16px' }, text }),
          h('div', { class: 'btn-row' },
            h('button', { class: 'btn light', type: 'button', onclick: () => { done = true; resolve(false); c.close(); } }, 'Abbrechen'),
            h('button', { class: 'btn ' + (danger ? 'danger' : 'mint'), type: 'button', onclick: () => { done = true; resolve(true); c.close(); } }, okLabel || 'OK')),
        ]);
      });
      s.onClose = () => { if (!done) resolve(false); };
    });
  }

  function field(label, input, opt) {
    return h('label', { class: 'field' }, h('span', null, label, opt ? h('span', { class: 'opt', text: ' · optional' }) : null), input);
  }
  const textInput = (value, attrs) => h('input', Object.assign({ class: 'inp', type: 'text', value: value || '', autocomplete: 'off' }, attrs || {}));
  const moneyInput = (value, attrs) => textInput(value, Object.assign({ inputmode: 'decimal', placeholder: '0.00', enterkeyhint: 'done' }, attrs || {}));
  const dateInput = (value, attrs) => h('input', Object.assign({ class: 'inp', type: 'date', value: value || '' }, attrs || {}));
  function selectInput(options, value, attrs) {
    const s = h('select', Object.assign({ class: 'inp' }, attrs || {}));
    for (const o of options) {
      if (o.group) {
        const g = h('optgroup', { label: o.group });
        o.options.forEach((x) => g.appendChild(h('option', { value: x.value, selected: x.value === value }, x.label)));
        s.appendChild(g);
      } else s.appendChild(h('option', { value: o.value, selected: o.value === value }, o.label));
    }
    return s;
  }
  function accountOptions(current, none) {
    const opts = none ? [{ value: '', label: none }] : [];
    S.st.accounts.filter((a) => !a.archived || a.id === current).forEach((a) => opts.push({ value: a.id, label: a.name + (a.archived ? ' (archiviert)' : '') }));
    return opts;
  }
  function amountField(value, cur) {
    const input = h('input', { type: 'text', inputmode: 'decimal', placeholder: '0.00', value: value || '', autocomplete: 'off', 'aria-label': 'Betrag', enterkeyhint: 'done' });
    const curEl = h('span', { class: 'cur', text: cur || 'CHF' });
    const wrap = h('div', { class: 'amount-field' }, curEl, input);
    return { wrap, input, setCur: (c) => { curEl.textContent = c; } };
  }
  function seg(options, value, onChange, cls) {
    const el = h('div', { class: 'seg' + (cls ? ' ' + cls : ''), role: 'tablist' });
    let cur = value;
    const btns = options.map((o) => h('button', {
      type: 'button', class: o.value === value ? 'on' : '', role: 'tab', 'aria-selected': String(o.value === value),
      onclick: () => { if (cur === o.value) return; cur = o.value; btns.forEach((b, i) => { const on = options[i].value === cur; b.className = on ? 'on' : ''; b.setAttribute('aria-selected', String(on)); }); onChange(cur); },
    }, o.label));
    append(el, btns);
    return el;
  }
  function categoryPicker(type, value, onPick) {
    const grid = h('div', { class: 'cat-grid', role: 'radiogroup', 'aria-label': 'Kategorie' });
    let cur = value;
    const list = S.st.categories.filter((c) => c.type === type && (!c.archived || c.id === value));
    list.forEach((c) => {
      const b = h('button', { type: 'button', class: c.id === cur ? 'on' : '', role: 'radio', 'aria-checked': String(c.id === cur), onclick: () => {
        cur = c.id; [...grid.children].forEach((x) => { x.className = ''; x.setAttribute('aria-checked', 'false'); });
        b.className = 'on'; b.setAttribute('aria-checked', 'true'); if (onPick) onPick(c.id);
      } }, icon(c.icon), c.name);
      grid.appendChild(b);
    });
    return { el: grid, get value() { return cur; } };
  }
  function errBox() { return h('div', { class: 'err', role: 'alert' }); }

  /** Sperrt den Knopf während der Anfrage (Schutz vor Doppelklick) und zeigt Fehler an. */
  function runner(btn, err, fn) {
    return async (e) => {
      if (e) e.preventDefault();
      if (btn.disabled) return;
      btn.disabled = true;
      err.textContent = '';
      try { await fn(); } catch (ex) { err.textContent = ex.message; } finally { btn.disabled = false; }
    };
  }

  function progress(spent, total) {
    const pct = total > 0 ? spent / total : (spent > 0 ? 2 : 0);
    const bar = h('i');
    const el = h('div', { class: 'progress' + (pct > 1 ? ' over' : pct >= 0.85 ? ' warn' : ''), role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(Math.round(Math.min(pct, 1) * 100)) }, bar);
    bar.style.width = '0%';
    requestAnimationFrame(() => requestAnimationFrame(() => { bar.style.width = Math.min(100, Math.max(0, pct * 100)).toFixed(1) + '%'; }));
    return el;
  }

  function topbar(title, opts) {
    opts = opts || {};
    return h('div', { class: 'topbar' + (opts.wide ? ' wide' : '') },
      opts.back ? h('a', { class: 'icon-btn flat back', href: '#/' + opts.back, 'aria-label': 'Zurück' }, icon('chevL')) : null,
      h('h1', { text: title }),
      opts.right || null);
  }
  function empty(iconName, text) { return h('div', { class: 'empty' }, icon(iconName), h('div', { text })); }
  function note(text, iconName, cls) { return h('div', { class: 'note' + (cls ? ' ' + cls : '') }, icon(iconName || 'info', 's'), h('div', { text })); }
  function kv(k, v) { return h('div', { class: 'kv' }, h('span', { text: k }), h('span', { text: v })); }

  // ================================================================ Router & Shell
  const NAV = [
    { r: 'uebersicht', label: 'Übersicht', icon: 'home' },
    { r: 'buchungen', label: 'Buchungen', icon: 'list' },
    { r: 'fixkosten', label: 'Fixkosten', icon: 'repeat' },
    { r: 'sparziele', label: 'Sparziele', icon: 'target' },
  ];
  const MORE = [
    { r: 'konten', label: 'Konten', icon: 'wallet' },
    { r: 'einstellungen', label: 'Einstellungen', icon: 'settings' },
  ];
  const PAGES = {};
  function route() {
    const r = (location.hash || '').replace(/^#\/?/, '').split('/')[0];
    return PAGES[r] ? r : 'uebersicht';
  }

  function render() {
    if (!S.status) return;
    if (!S.status.installed) return renderSetup();
    if (!S.user) return renderLogin();
    if (!S.user.onboarded) return renderOnboarding();
    if (!S.st) return;
    const r = route();
    const same = r === S.lastRoute;
    const y = window.scrollY;
    let shell = $app.querySelector('.shell');
    if (!shell) {
      $app.textContent = '';
      shell = h('div', { class: 'shell' }, h('nav', { class: 'nav', 'aria-label': 'Hauptnavigation' }), h('main', { class: 'main', id: 'main' }));
      $app.appendChild(shell);
    }
    const nav = shell.querySelector('.nav');
    nav.textContent = '';
    append(nav, [
      h('div', { class: 'brand' }, h('img', { src: 'assets/icon.svg', alt: '' }), 'CASH'),
      NAV.map((n) => h('a', { href: '#/' + n.r, class: n.r === r ? 'active' : '', 'aria-current': n.r === r ? 'page' : null },
        h('span', { class: 'pill' }, icon(n.icon)), n.label)),
      MORE.map((m) => h('a', { href: '#/' + m.r, class: 'sub' + (m.r === r ? ' active' : '') }, h('span', { class: 'pill' }, icon(m.icon, 's')), m.label)),
    ]);
    const main = shell.querySelector('.main');
    main.textContent = '';
    const page = h('div', { class: same ? '' : 'page' });
    PAGES[r](page);
    main.appendChild(page);
    if (same) window.scrollTo(0, y); else window.scrollTo(0, 0);
    S.lastRoute = r;
    document.title = (NAV.concat(MORE).find((n) => n.r === r) || { label: 'CASH' }).label + ' · CASH';
    renderHooks.forEach((fn) => { try { fn(); } catch (e) { /* Sheet bereits geschlossen */ } });
  }
  window.addEventListener('hashchange', () => { closeAllSheets(); render(); });

  // ================================================================ Anmeldung
  function authFrame(title, lead, content) {
    $app.textContent = '';
    S.lastRoute = '';
    $app.appendChild(h('div', { class: 'auth' }, h('div', { class: 'auth-box' },
      h('div', { class: 'auth-logo' }, h('img', { src: 'assets/icon.svg', alt: '' }), h('b', { text: 'CASH' })),
      h('h1', { text: title }), lead ? h('p', { class: 'lead', text: lead }) : null, content)));
  }

  function renderSetup() {
    const name = textInput('', { autocomplete: 'name', required: true });
    const user = textInput('', { autocomplete: 'username', autocapitalize: 'none', spellcheck: 'false', required: true });
    const mail = textInput('', { type: 'email', autocomplete: 'email', autocapitalize: 'none' });
    const pw = textInput('', { type: 'password', autocomplete: 'new-password', required: true, minlength: '10' });
    const pw2 = textInput('', { type: 'password', autocomplete: 'new-password', required: true });
    const rem = h('input', { type: 'checkbox', checked: true });
    const err = errBox();
    const btn = h('button', { class: 'btn mint block', type: 'submit' }, 'Account erstellen');
    const form = h('form', { class: 'form' },
      field('Name', name), field('Benutzername', user), field('E-Mail', mail, true),
      field('Passwort (mind. 10 Zeichen)', pw), field('Passwort wiederholen', pw2),
      h('label', { class: 'check' }, rem, h('span', { text: 'Angemeldet bleiben (dieses Gerät)' })), err, btn,
      note('Nach der Einrichtung ist die öffentliche Registrierung automatisch deaktiviert.', 'lock'));
    form.addEventListener('submit', runner(btn, err, async () => {
      if (pw.value !== pw2.value) throw new Error('Die Passwörter stimmen nicht überein.');
      const r = await api('auth.setup', { name: name.value, username: user.value, email: mail.value, password: pw.value, remember: rem.checked });
      S.status = r; S.user = r.user;
      render();
    }));
    authFrame('Willkommen', 'Richte deinen persönlichen CASH-Account ein.', form);
    name.focus();
  }

  function renderLogin(register) {
    if (register && S.status.registrationOpen) return renderRegister();
    const user = textInput('', { autocomplete: 'username', autocapitalize: 'none', spellcheck: 'false', required: true });
    const pw = textInput('', { type: 'password', autocomplete: 'current-password', required: true });
    const rem = h('input', { type: 'checkbox', checked: true });
    const err = errBox();
    const btn = h('button', { class: 'btn mint block', type: 'submit' }, 'Anmelden');
    const form = h('form', { class: 'form' },
      field('Benutzername oder E-Mail', user), field('Passwort', pw),
      h('label', { class: 'check' }, rem, h('span', { text: 'Angemeldet bleiben' })), err, btn,
      S.status.registrationOpen ? h('button', { class: 'btn ghost block', type: 'button', onclick: () => renderRegister() }, 'Neuen Account registrieren') : null);
    form.addEventListener('submit', runner(btn, err, async () => {
      const r = await api('auth.login', { username: user.value, password: pw.value, remember: rem.checked });
      S.status = r; S.user = r.user;
      await start();
    }));
    authFrame('Anmelden', 'Willkommen zurück.', form);
    user.focus();
  }

  function renderRegister() {
    const name = textInput('', { autocomplete: 'name' });
    const user = textInput('', { autocomplete: 'username', autocapitalize: 'none' });
    const mail = textInput('', { type: 'email', autocomplete: 'email', autocapitalize: 'none' });
    const pw = textInput('', { type: 'password', autocomplete: 'new-password' });
    const err = errBox();
    const btn = h('button', { class: 'btn mint block', type: 'submit' }, 'Registrieren');
    const form = h('form', { class: 'form' }, field('Name', name), field('Benutzername', user), field('E-Mail', mail, true),
      field('Passwort (mind. 10 Zeichen)', pw), err, btn,
      h('button', { class: 'btn ghost block', type: 'button', onclick: () => renderLogin() }, 'Zur Anmeldung'));
    form.addEventListener('submit', runner(btn, err, async () => {
      const r = await api('auth.register', { name: name.value, username: user.value, email: mail.value, password: pw.value, remember: false });
      S.status = r; S.user = r.user;
      render();
    }));
    authFrame('Registrieren', 'Jeder Account hat vollständig getrennte Daten.', form);
  }

  function renderOnboarding() {
    const rows = [];
    const list = h('div', { class: 'stack' });
    function addRow(nameVal, type) {
      const n = textInput(nameVal, { 'aria-label': 'Kontoname' });
      const o = moneyInput('', { 'aria-label': 'Aktueller Kontostand', placeholder: 'z. B. 1250.00' });
      o.inputMode = 'decimal';
      const t = selectInput(Object.entries(ACCOUNT_TYPES).map(([v, l]) => ({ value: v, label: l })), type);
      const av = h('input', { type: 'checkbox', checked: type !== 'savings' });
      t.addEventListener('change', () => { av.checked = t.value !== 'savings'; });
      const row = { n, o, t, av };
      const box = h('div', { class: 'onb-acc' }, field('Kontoname', n), h('div', { class: 'row2' }, field('Aktueller Stand (CHF)', o), field('Art', t)),
        h('label', { class: 'check' }, av, h('span', { text: 'Alltagskonto – zählt zum frei verfügbaren Geld' })));
      if (rows.length >= 1) {
        box.appendChild(h('button', { type: 'button', class: 'btn ghost sm', onclick: () => { rows.splice(rows.indexOf(row), 1); box.remove(); } }, icon('trash', 's'), 'Entfernen'));
      }
      rows.push(row);
      list.appendChild(box);
    }
    addRow('Privatkonto', 'checking');
    addRow('E-Jugendsparkonto', 'savings');
    const err = errBox();
    const btn = h('button', { class: 'btn mint block', type: 'submit' }, 'Einrichtung abschliessen');
    const form = h('form', { class: 'form' },
      note('Gib die heutigen Kontostände ein. Sie gelten als Anfangssaldo und zählen nicht als Einnahme. 0 ist erlaubt.', 'info', 'mint'),
      list,
      h('button', { type: 'button', class: 'btn light', onclick: () => addRow('', 'other') }, icon('plus', 's'), 'Weiteres Konto'),
      err, btn);
    form.addEventListener('submit', runner(btn, err, async () => {
      const accounts = rows.map((r) => {
        if (!r.n.value.trim()) throw new Error('Bitte jedem Konto einen Namen geben.');
        if (r.o.value.trim() === '') throw new Error('Bitte für «' + r.n.value.trim() + '» den aktuellen Kontostand eingeben (0 ist erlaubt).');
        return { name: r.n.value, opening: r.o.value, type: r.t.value, available: r.av.checked };
      });
      await mutate('onboarding.complete', { accounts });
      location.hash = '#/uebersicht';
    }));
    authFrame('Konten einrichten', 'Hallo ' + S.user.name + '! Noch ein Schritt: deine Konten und Anfangssalden.', form);
    if (!S.st) loadState().catch(() => {});
  }

  async function logout() {
    try { await api('auth.logout', {}); } catch (e) { /* ignorieren */ }
    location.hash = '';
    location.reload();   // zurück zur PIN-Seite
  }
  async function lockApp() {
    try { await api('pin.lock', {}); } catch (e) { /* ignorieren */ }
    location.reload();
  }

  // ================================================================ Übersicht
  function monthEnd(m) { return addDays(addMonth(m, 1) + '-01', -1); }
  function statCard(label, ic, color, value, hint, href) {
    return h(href ? 'a' : 'div', { class: 'card stat', href: href || null, style: href ? { display: 'block', color: 'inherit' } : null },
      h('div', { class: 'label' }, h('span', { class: 'dot ' + color }, icon(ic, 's')), label),
      h('div', { class: 'val num' }, h('small', { text: 'CHF' }), fmt(value)),
      hint ? h('div', { class: 'hint', text: hint }) : null);
  }
  function calcRow(label, value, hint) {
    return h('div', { class: 'calc-r' },
      h('span', null, label, hint ? h('small', { text: ' · ' + hint }) : null),
      h('span', { class: 'num', text: (value < 0 ? MINUS + ' ' : value > 0 ? '+ ' : '') + fmt(Math.abs(value)) }));
  }
  function goalDueRow(g) {
    return h('div', { class: 'row' },
      h('span', { class: 'ic mint' }, icon('target', 's')),
      h('span', { class: 'tx' }, h('div', { class: 't1', text: 'Sparrate ' + g.name }), h('div', { class: 't2', text: 'noch zu sparen im ' + MONTHS[Number(today().slice(5, 7)) - 1] })),
      h('span', { class: 'end' }, h('div', { class: 'amt', text: fmt(g.dueThisMonth) }),
        h('button', { class: 'btn mint sm', type: 'button', style: { marginTop: '4px' }, onclick: () => openSave(g) }, 'Gespart')));
  }

  PAGES.uebersicht = (p) => {
    const st = S.st;
    const f = st.summary.free;
    const monthName = MONTHS[Number(f.month.slice(5, 7)) - 1];
    const neg = f.free < 0;
    const end = monthEnd(f.month);
    const open = st.occurrences.filter((o) => o.status === 'open' && o.dueDate <= end).sort((a, b) => a.dueDate.localeCompare(b.dueDate));
    const goalsDue = st.goals.filter((g) => !g.archived && g.dueThisMonth > 0);
    const hasRates = st.goals.some((g) => !g.archived && g.monthly > 0);
    const accs = activeAccounts();

    const todo = h('div', { class: 'card flush' },
      h('div', { class: 'card-h' }, h('h2', { text: 'Noch zu bezahlen im ' + monthName }), h('a', { class: 'link', href: '#/fixkosten', text: 'Fixkosten' })));
    if (!open.length && !goalsDue.length) {
      todo.appendChild(h('div', { class: 'empty' }, icon('check'), h('div', { text: st.bills.some((b) => b.active) ? 'Alles für ' + monthName + ' erledigt.' : 'Noch keine Fixkosten. Tipp: Beim Erfassen einer Ausgabe «Wiederkehrend» einschalten.' })));
    }
    open.forEach((o) => todo.appendChild(occRow(o)));
    goalsDue.forEach((g) => todo.appendChild(goalDueRow(g)));

    append(p, [
      topbar('Übersicht', { right: h('div', { class: 'flex' },
        h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Sperren', title: 'Sperren', onclick: lockApp }, icon('lock', 's')),
        h('a', { class: 'icon-btn', href: '#/einstellungen', 'aria-label': 'Einstellungen' }, icon('settings', 's'))) }),
      h('div', { class: 'grid g-dash' },
        h('div', null,
          h('div', { class: 'card hero free' + (neg ? ' neg' : '') },
            h('div', { class: 'label', text: neg ? 'Es fehlen bis Ende ' + monthName : 'Frei für Freizeit bis Ende ' + monthName }),
            h('div', { class: 'big num' }, h('small', { text: 'CHF' }), fmt(Math.abs(f.free))),
            h('div', { class: 'hero-sub', text: neg ? 'Nach allen Fixkosten' + (hasRates ? ' und Sparraten' : '') + ' reicht das Geld diesen Monat nicht.'
              : f.daysLeft > 1 ? 'Etwa ' + chf(f.perDay) + ' pro Tag für die restlichen ' + f.daysLeft + ' Tage.' : 'Heute ist der letzte Tag des Monats.' }),
            h('div', { class: 'calc' },
              calcRow('Auf Alltagskonten', f.available),
              calcRow('Offene Fixkosten', -f.fixedOpen, f.fixedOpenCount ? f.fixedOpenCount + ' offen' : 'alles bezahlt'),
              hasRates || f.savingsDue ? calcRow('Noch zu sparen', -f.savingsDue) : null,
              h('div', { class: 'calc-r total' }, h('span', { text: 'Bleibt frei' }), h('span', { class: 'num', text: (neg ? MINUS + ' ' : '') + fmt(Math.abs(f.free)) })))),
          h('div', { class: 'quick' },
            h('button', { class: 'btn mint', type: 'button', onclick: () => openTx({ type: 'expense' }) }, icon('minus', 's'), 'Ausgabe'),
            h('button', { class: 'btn out', type: 'button', onclick: () => openTx({ type: 'income' }) }, icon('plus', 's'), 'Einnahme'))),
        h('div', null,
          todo,
          h('div', { class: 'grid g2' },
            statCard('Ausgegeben', 'out', 'dark', f.spentThisMonth, 'im ' + monthName, '#/buchungen'),
            statCard('Fixkosten', 'repeat', 'amber', f.fixedPerMonth, 'pro Monat', '#/fixkosten')))),
      h('div', { class: 'card flush mt' },
        h('div', { class: 'card-h' }, h('h2', { text: 'Konten' }), h('a', { class: 'link', href: '#/konten', text: 'Verwalten' })),
        accs.map((a) => h('button', { class: 'row', type: 'button', 'aria-label': 'Kontostand ' + a.name + ' ändern', onclick: () => openBalance(a) },
          h('span', { class: 'ic ' + (a.available ? 'mint' : '') }, icon(ACCOUNT_ICONS[a.type] || 'bank', 's')),
          h('span', { class: 'tx' }, h('div', { class: 't1', text: a.name }), h('div', { class: 't2', text: a.available ? 'zählt zum freien Geld' : 'nicht eingerechnet' })),
          h('span', { class: 'amt num' + (a.balance < 0 ? ' neg' : ''), text: fmt(a.balance) })))),
      f.fixedNextMonth ? h('div', { class: 'small muted', style: { textAlign: 'center', margin: '4px 0 12px' }, text: 'Fixkosten im ' + MONTHS[Number(addMonth(f.month, 1).slice(5, 7)) - 1] + ': ' + chf(f.fixedNextMonth) }) : null,
    ]);
  };

  // ================================================================ Buchungen
  const TYPE_LABEL = { income: 'Einnahme', expense: 'Ausgabe', transfer: 'Umbuchung', debt_in: 'Darlehen / Rückzahlung erhalten', debt_out: 'Darlehen / Rückzahlung bezahlt' };

  function txRow(t) {
    const c = cat(t.categoryId);
    let ic = c ? c.icon : 'dots', icCls = t.type === 'income' ? 'mint' : 'dark', title, sub;
    const a = acc(t.accountId);
    if (t.type === 'transfer') {
      ic = 'transfer'; icCls = 'blue';
      title = t.description || 'Umbuchung';
      sub = (a ? a.name : '?') + ' → ' + ((acc(t.toAccountId) || {}).name || '?');
    } else if (t.type === 'debt_in' || t.type === 'debt_out') {
      ic = 'users'; icCls = 'amber';
      title = t.description || TYPE_LABEL[t.type];
      sub = 'Darlehen · ' + (a ? a.name : '');
    } else {
      title = t.description || (c ? c.name : TYPE_LABEL[t.type]);
      sub = [t.description ? (c ? c.name : '') : null, a ? a.name : null, t.link && t.link.kind === 'bill' ? 'Fixkosten' : null, t.imports && t.imports.length ? 'importiert' : null].filter(Boolean).join(' · ');
    }
    const b = t.budgetId ? bud(t.budgetId) : null;
    const cls = t.type === 'income' ? 'pos' : t.type === 'expense' ? 'neg' : 'neu';
    return h('button', { class: 'row', type: 'button', onclick: () => openTx({ tx: t }) },
      h('span', { class: 'ic ' + icCls }, icon(ic, 's')),
      h('span', { class: 'tx' }, h('div', { class: 't1', text: title }), h('div', { class: 't2', text: sub })),
      h('span', { class: 'end' },
        h('div', { class: 'amt ' + cls, text: signed(t.amount, t.type) }),
        t.fx ? h('div', { class: 'sub', text: t.fx.currency + ' ' + fmt(t.fx.original) }) : b ? h('div', { class: 'sub', text: b.name }) : null));
  }

  function filteredTx() {
    const f = S.tx;
    const q = f.q.trim().toLowerCase();
    return S.st.transactions.filter((t) => {
      if (f.month && f.month !== 'all' && !t.date.startsWith(f.month)) return false;
      if (f.type === 'debt' ? !t.type.startsWith('debt') : f.type && t.type !== f.type) return false;
      if (f.account && t.accountId !== f.account && t.toAccountId !== f.account) return false;
      if (f.category && t.categoryId !== f.category) return false;
      if (q) {
        const c = cat(t.categoryId), a = acc(t.accountId), b = bud(t.budgetId);
        const hay = [t.description, c && c.name, a && a.name, b && b.name, fmt(t.amount), (t.amount / 100).toFixed(2), TYPE_LABEL[t.type]].join(' ').toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }

  PAGES.buchungen = (p) => {
    const f = S.tx;
    if (!f.month) f.month = S.month;
    const months = new Set([S.month, today().slice(0, 7)]);
    S.st.transactions.forEach((t) => months.add(t.date.slice(0, 7)));
    const monthSel = selectInput([{ value: 'all', label: 'Alle Monate' }].concat([...months].sort().reverse().map((m) => ({ value: m, label: monthLabel(m) }))), f.month, { 'aria-label': 'Monat' });
    const accSel = selectInput([{ value: '', label: 'Alle Konten' }].concat(S.st.accounts.map((a) => ({ value: a.id, label: a.name }))), f.account, { 'aria-label': 'Konto' });
    const catSel = selectInput([{ value: '', label: 'Alle Kategorien' },
      { group: 'Ausgaben', options: S.st.categories.filter((c) => c.type === 'expense').map((c) => ({ value: c.id, label: c.name })) },
      { group: 'Einnahmen', options: S.st.categories.filter((c) => c.type === 'income').map((c) => ({ value: c.id, label: c.name })) }], f.category, { 'aria-label': 'Kategorie' });
    const search = textInput(f.q, { type: 'search', placeholder: 'Suchen …', 'aria-label': 'Buchungen durchsuchen', enterkeyhint: 'search' });
    const listBox = h('div');
    const typeSeg = h('div', { class: 'chips' }, [['', 'Alle'], ['expense', 'Ausgaben'], ['income', 'Einnahmen'], ['transfer', 'Umbuchungen']].concat(S.st.transactions.some((t) => t.type.startsWith('debt')) ? [['debt', 'Darlehen']] : []).map(([v, l]) =>
      h('button', { type: 'button', class: 'chip' + (f.type === v ? ' on' : ''), onclick: (e) => { f.type = v; [...typeSeg.children].forEach((x) => x.classList.remove('on')); e.currentTarget.classList.add('on'); drawList(); } }, l)));
    monthSel.addEventListener('change', () => { f.month = monthSel.value; drawList(); });
    accSel.addEventListener('change', () => { f.account = accSel.value; drawList(); });
    catSel.addEventListener('change', () => { f.category = catSel.value; drawList(); });
    search.addEventListener('input', () => { f.q = search.value; drawList(); });

    function drawList() {
      listBox.textContent = '';
      const list = filteredTx();
      let inc = 0, exp = 0;
      list.forEach((t) => { if (t.type === 'income') inc += t.amount; if (t.type === 'expense') exp += t.amount; });
      listBox.appendChild(h('div', { class: 'small muted', style: { margin: '2px 4px 4px' } },
        list.length + (list.length === 1 ? ' Buchung' : ' Buchungen') + ' · Einnahmen ' + fmt(inc) + ' · Ausgaben ' + fmt(exp)));
      if (!list.length) { listBox.appendChild(h('div', { class: 'card' }, empty('list', 'Keine Buchungen gefunden.'))); return; }
      let day = null, card = null;
      list.slice(0, 600).forEach((t) => {
        if (t.date !== day) {
          day = t.date;
          listBox.appendChild(h('div', { class: 'day-h' }, h('span', { text: fmtDay(day) })));
          card = h('div', { class: 'card flush' });
          listBox.appendChild(card);
        }
        card.appendChild(txRow(t));
      });
      if (list.length > 600) listBox.appendChild(h('div', { class: 'empty', text: 'Nur die neuesten 600 Treffer werden angezeigt – bitte Filter verwenden.' }));
    }

    const csvLink = h('a', { class: 'icon-btn', href: 'api.php?a=export.csv' + (f.month && f.month !== 'all' ? '&month=' + f.month : ''), 'aria-label': 'CSV exportieren', title: 'CSV exportieren', download: '' }, icon('download', 's'));
    monthSel.addEventListener('change', () => { csvLink.href = 'api.php?a=export.csv' + (f.month !== 'all' ? '&month=' + f.month : ''); });
    append(p, [
      topbar('Buchungen', { right: h('div', { class: 'flex' }, h('a', { class: 'icon-btn', href: '#/import', 'aria-label': 'Kontoauszug importieren', title: 'Kontoauszug importieren' }, icon('upload', 's')), csvLink, h('button', { class: 'icon-btn mint', type: 'button', 'aria-label': 'Neue Buchung', onclick: () => openTx({ type: 'expense' }) }, icon('plus'))) }),
      h('div', { class: 'filters' },
        h('div', { class: 'search' }, icon('search', 's'), search),
        typeSeg,
        h('div', { class: 'row2' }, monthSel, accSel),
        catSel),
      h('div', { class: 'quick-sub' },
        h('button', { class: 'btn light sm', type: 'button', onclick: () => openTx({ type: 'income' }) }, icon('plus', 's'), 'Einnahme'),
        h('button', { class: 'btn light sm', type: 'button', onclick: () => openTx({ type: 'expense' }) }, icon('minus', 's'), 'Ausgabe'),
        h('button', { class: 'btn light sm', type: 'button', onclick: () => openTx({ type: 'transfer' }) }, icon('transfer', 's'), 'Umbuchung')),
      listBox,
    ]);
    drawList();
  };

  /**
   * Mini-Formular für Einnahme / Ausgabe / Umbuchung.
   * opts: { type, tx (Bearbeiten), budgetId (Vorauswahl) }
   */
  function openTx(opts) {
    const tx = opts.tx || null;
    if (tx && tx.link && tx.link.kind === 'debt') return openDebtTxInfo(tx);
    let type = tx ? tx.type : (opts.type || 'expense');
    const requestId = uid();
    const isBill = !!(tx && tx.link && tx.link.kind === 'bill');
    const titles = { expense: 'Ausgabe', income: 'Einnahme', transfer: 'Umbuchung' };
    sheet((tx ? '' : 'Neue ') + titles[type], (b, ctl) => {
      const st = S.st;
      const amt = amountField(tx && !tx.fx ? toInput(tx.amount) : '');
      const fxBox = h('div', { class: 'stack' });
      const curSegBox = h('div');
      const rateInp = moneyInput('', { placeholder: 'z. B. 38.50', 'aria-label': 'Wechselkurs' });
      const preview = h('div', { class: 'preview', 'aria-live': 'polite' });
      const previewBox = h('div');   // Kurs steht vor dem Betrag, Vorschau danach
      let currency = tx && tx.fx ? tx.fx.currency : 'CHF';
      if (tx && tx.fx) { amt.input.value = toInput(tx.fx.original); rateInp.value = tx.fx.rate; }

      const date = dateInput(tx ? tx.date : today(), { required: true });
      const accSel = selectInput(accountOptions(tx ? tx.accountId : null), tx ? tx.accountId : defaultAccountId());
      const toSel = selectInput(accountOptions(tx ? tx.toAccountId : null), tx && tx.toAccountId ? tx.toAccountId : (activeAccounts().find((a) => a.id !== accSel.value) || {}).id);
      const desc = textInput(tx ? tx.description : '', { maxlength: '200', placeholder: type === 'transfer' ? 'z. B. Sparen' : 'z. B. Migros' });
      let catPick = null;
      const catBox = h('div');
      const rec = h('input', { type: 'checkbox' });
      const recInterval = selectInput([{ value: 'monthly', label: 'Jeden Monat' }, { value: 'quarterly', label: 'Alle 3 Monate' }, { value: 'yearly', label: 'Jedes Jahr' }], 'monthly', { 'aria-label': 'Wiederholung' });
      const recBox = h('div', { class: 'rec-box' },
        h('label', { class: 'check' }, rec, h('span', null, h('b', { text: 'Wiederkehrend' }), h('br'), h('span', { class: 'small muted', text: 'Wird als Fixkosten geführt und vom freien Geld abgezogen, bis bezahlt.' }))),
        recInterval);
      const syncRec = () => recInterval.classList.toggle('hidden', !rec.checked);
      rec.addEventListener('change', syncRec);
      const err = errBox();
      const save = h('button', { class: 'btn mint block', type: 'submit' }, tx ? 'Speichern' : 'Erfassen');

      function foreignCurrency() {
        return tx && tx.fx ? tx.fx.currency : null;   // Fremdwährung nur noch für bestehende Buchungen
      }
      function drawCurrency() {
        curSegBox.textContent = '';
        fxBox.textContent = '';
        previewBox.textContent = '';
        const fc = type === 'expense' ? foreignCurrency() : null;
        if (!fc) { currency = 'CHF'; amt.setCur('CHF'); return; }
        if (currency !== 'CHF' && currency !== fc) currency = fc;
        curSegBox.appendChild(seg([{ value: fc, label: 'Betrag in ' + fc }, { value: 'CHF', label: 'Betrag in CHF' }], currency, (v) => { currency = v; drawCurrency(); }, 'sm'));
        amt.setCur(currency);
        if (currency === 'CHF') return;
        if (!rateInp.value && st.settings.lastRates[fc]) rateInp.value = st.settings.lastRates[fc];
        append(fxBox, [
          field('Wechselkurs: ' + fc + ' pro CHF', rateInp),
          !st.settings.lastRates[fc] && !rateInp.value ? note('Bitte den aktuellen Kurs selbst eingeben (z. B. aus deiner Bank-App). CASH erfindet keinen Kurs.', 'info', 'amber') : null,
        ]);
        previewBox.appendChild(preview);
        updatePreview();
      }
      function updatePreview() {
        if (currency === 'CHF') return;
        const minor = parseAmount(amt.input.value);
        const rate = parseRate(rateInp.value);
        preview.textContent = '';
        if (!minor || !rate) { preview.textContent = 'Betrag und Kurs eingeben – der CHF-Betrag erscheint hier vor dem Speichern.'; return; }
        const c = fxToChf(minor, rate);
        append(preview, [currency + ' ' + fmt(minor) + ' ÷ ' + rateInp.value.replace(',', '.') + ' = ', h('b', { text: chf(c) }), h('div', { class: 'small muted', text: 'Gerundet auf ganze Rappen. Spätere Kursänderungen verändern diese Buchung nicht.' })]);
      }
      amt.input.addEventListener('input', updatePreview);
      rateInp.addEventListener('input', updatePreview);

      function drawCats() {
        catBox.textContent = '';
        if (type === 'transfer') return;
        const def = tx && tx.type === type ? tx.categoryId : (opts.categoryId || null);
        catPick = categoryPicker(type, def);
        catBox.appendChild(h('div', { class: 'field' }, h('span', { text: 'Kategorie' }), catPick.el));
      }

      const typeSeg = (!tx || !isBill) ? seg([{ value: 'expense', label: 'Ausgabe' }, { value: 'income', label: 'Einnahme' }, { value: 'transfer', label: 'Umbuchung' }], type, (v) => {
        type = v; ctl.setTitle((tx ? '' : 'Neue ') + titles[type]); layout();
      }) : null;
      const accLabel = h('span', { text: 'Konto' });
      const accField = h('label', { class: 'field' }, accLabel, accSel);
      const toField = field('Auf Konto', toSel);
      const transferNote = note('Umbuchungen verändern die Kontostände, zählen aber nicht als Einnahme oder Ausgabe.', 'transfer');

      function layout() {
        accLabel.textContent = type === 'transfer' ? 'Von Konto' : 'Konto';
        toField.classList.toggle('hidden', type !== 'transfer');
        transferNote.classList.toggle('hidden', type !== 'transfer');
        recBox.classList.toggle('hidden', !!tx || type !== 'expense');
        desc.placeholder = type === 'transfer' ? 'z. B. Sparen' : type === 'income' ? 'z. B. Lohn Oktober' : 'z. B. Migros';
        drawCats();
        drawCurrency();
      }

      const form = h('form', { class: 'form' },
        typeSeg,
        isBill ? note('Gehört zu wiederkehrenden Fixkosten. Änderungen werden dort übernommen; Löschen setzt den Termin wieder auf «offen».', 'repeat', 'amber') : null,
        tx && tx.link && tx.link.kind === 'goal' ? note('Spar-Umbuchung für ein Sparziel. Änderungen werden im Sparziel übernommen.', 'target', 'mint') : null,
        curSegBox, fxBox, amt.wrap, previewBox,
        catBox,
        h('div', { class: 'row2' }, field('Datum', date), accField),
        toField, transferNote,
        field('Beschreibung', desc, true),
        recBox,
        err,
        h('div', { class: 'btn-row' },
          tx ? h('button', { class: 'btn danger', type: 'button', onclick: async () => {
            if (!(await confirmBox('Buchung löschen?', isBill ? 'Der Fixkosten-Termin wird wieder als offen geführt.' : 'Die Buchung wird endgültig gelöscht.', 'Löschen', true))) return;
            try { await mutate('tx.delete', { id: tx.id }); ctl.close(); } catch (e) { err.textContent = e.message; }
          } }, icon('trash', 's'), 'Löschen') : null,
          save));
      form.addEventListener('submit', runner(save, err, async () => {
        const payload = {
          id: tx ? tx.id : undefined, type, date: date.value, accountId: accSel.value, description: desc.value,
        };
        if (type === 'transfer') payload.toAccountId = toSel.value;
        else {
          if (!catPick.value) throw new Error('Bitte eine Kategorie wählen.');
          payload.categoryId = catPick.value;
        }
        if (type === 'expense') payload.budgetId = tx ? (tx.budgetId || null) : null;
        if (!tx && type === 'expense' && rec.checked) { payload.recurring = true; payload.interval = recInterval.value; }
        if (type === 'expense' && currency !== 'CHF') {
          if (!parseRate(rateInp.value)) { rateInp.focus(); throw new Error('Bitte zuerst den Wechselkurs «' + currency + ' pro CHF» eingeben.'); }
          payload.currency = currency; payload.originalAmount = amt.input.value; payload.rate = rateInp.value;
        } else {
          payload.amount = amt.input.value;
        }
        if (parseAmount(amt.input.value) === null || parseAmount(amt.input.value) === 0) { amt.input.focus(); throw new Error('Bitte einen Betrag grösser als 0 eingeben.'); }
        await mutate('tx.save', payload, requestId);
        ctl.close();
      }));
      b.appendChild(form);
      layout();
      syncRec();
      if (!tx) setTimeout(() => (currency !== 'CHF' && !rateInp.value ? rateInp : amt.input).focus(), 60);
    });
  }

  function openDebtTxInfo(tx) {
    sheet('Darlehensbuchung', (b, ctl) => {
      const a = acc(tx.accountId);
      append(b, [
        h('div', { class: 'detail-head' }, h('div', { class: 'big num', text: signed(tx.amount, tx.type) }), h('div', { class: 'sub', text: tx.description })),
        h('div', { class: 'card' }, kv('Datum', fmtDate(tx.date)), kv('Konto', a ? a.name : '–'), kv('Art', TYPE_LABEL[tx.type])),
        note('Diese Kontobewegung stammt aus einem früher erfassten Darlehen. Sie zählt nicht als Einnahme oder Ausgabe und bleibt unverändert.', 'users'),
      ]);
    });
  }

  // Detailansichten in Sheets aktualisieren sich nach jeder Änderung.
  const renderHooks = new Set();
  function onRender(fn) { renderHooks.add(fn); return () => renderHooks.delete(fn); }

  // ================================================================ Fixkosten
  const INTERVAL_CHOICES = ['monthly', 'quarterly', 'yearly', 'once'];
  function occRow(o) {
    const b = bill(o.billId);
    const c = b ? cat(b.categoryId) : null;
    const t = today();
    let badge;
    if (o.status === 'paid') badge = h('span', { class: 'badge mint' }, icon('check', 'xs'), 'bezahlt');
    else if (o.status === 'skipped') badge = h('span', { class: 'badge' }, 'übersprungen');
    const late = o.status === 'open' && o.dueDate < t;
    return h('div', { class: 'row', style: { cursor: 'pointer' }, role: 'button', tabindex: '0', onclick: () => openOcc(o.id), onkeydown: (e) => { if (e.key === 'Enter') openOcc(o.id); } },
      h('span', { class: 'ic ' + (o.status === 'paid' ? 'mint' : late ? 'red' : 'amber') }, icon(c ? c.icon : 'receipt', 's')),
      h('span', { class: 'tx' },
        h('div', { class: 't1', text: b ? b.name : 'Fixkosten' }),
        h('div', { class: 't2', text: (o.status === 'paid' ? 'bezahlt ' + fmtDate(o.paidDate, false) : late ? 'überfällig seit ' + fmtDate(o.dueDate, false) : 'fällig ' + fmtDate(o.dueDate, o.dueDate.slice(0, 4) !== t.slice(0, 4))) })),
      h('span', { class: 'end' },
        h('div', { class: 'amt', text: fmt(o.status === 'paid' ? o.paidAmount : o.amount) }),
        o.status === 'open'
          ? h('button', { class: 'btn mint sm', type: 'button', style: { marginTop: '4px' }, onclick: (e) => { e.stopPropagation(); openPay(o.id); } }, 'Bezahlt')
          : badge));
  }

  PAGES.fixkosten = (p) => {
    const st = S.st;
    const f = st.summary.free;
    const t = today();
    const end = monthEnd(f.month);
    const monthName = MONTHS[Number(f.month.slice(5, 7)) - 1];
    const thisMonth = st.occurrences.filter((o) => (o.dueDate.startsWith(f.month) || (o.status === 'open' && o.dueDate < t)) && o.dueDate <= end)
      .sort((a, b) => (a.status === 'open' ? 0 : 1) - (b.status === 'open' ? 0 : 1) || a.dueDate.localeCompare(b.dueDate));
    const active = st.bills.filter((b) => b.active).sort((a, b) => (a.nextDue || '9').localeCompare(b.nextDue || '9'));
    const ended = st.bills.filter((b) => !b.active);
    const billRow = (b) => {
      const c = cat(b.categoryId);
      return h('button', { class: 'row', type: 'button', onclick: () => openBillForm(b) },
        h('span', { class: 'ic ' + (b.active ? 'amber' : '') }, icon(c ? c.icon : 'repeat', 's')),
        h('span', { class: 'tx' }, h('div', { class: 't1', text: b.name }),
          h('div', { class: 't2', text: INTERVALS[b.interval] + (b.active ? (b.nextDue ? ' · nächste ' + fmtDate(b.nextDue, false) : '') : ' · beendet') })),
        h('span', { class: 'end' }, h('div', { class: 'amt', text: fmt(b.amount) })));
    };
    append(p, [
      topbar('Fixkosten', { right: h('button', { class: 'icon-btn mint', type: 'button', 'aria-label': 'Neue Fixkosten', onclick: () => openBillForm() }, icon('plus')) }),
      h('div', { class: 'grid g2', style: { marginBottom: '12px' } },
        statCard('Noch offen', 'clock', 'amber', f.fixedOpen, 'bis Ende ' + monthName),
        statCard('Pro Monat', 'repeat', 'dark', f.fixedPerMonth, active.length + (active.length === 1 ? ' Position' : ' Positionen'))),
      h('span', { class: 'section-t', text: 'Im ' + monthName }),
      h('div', { class: 'card flush' }, thisMonth.length ? thisMonth.map(occRow) : empty('check', 'Im ' + monthName + ' ist nichts fällig.')),
      h('span', { class: 'section-t', text: 'Alle Fixkosten' }),
      h('div', { class: 'card flush' }, active.length ? active.map(billRow) : empty('repeat', 'Noch keine Fixkosten. Tipp: Beim Erfassen einer Ausgabe «Wiederkehrend» einschalten.')),
      ended.length ? h('details', null, h('summary', { class: 'section-t', style: { cursor: 'pointer' }, text: 'Beendet (' + ended.length + ')' }), h('div', { class: 'card flush' }, ended.map(billRow))) : null,
      note('Offene Fixkosten werden vom freien Geld abgezogen, bis du sie als bezahlt markierst. Bezahlt wird nie automatisch.', 'info'),
    ]);
  };

  function openOcc(id) {
    sheet('Fixkosten', (body, ctl) => {
      const draw = () => {
        const o = byId(S.st.occurrences, id);
        if (!o) { ctl.close(); return; }
        const b = bill(o.billId);
        ctl.setTitle(b ? b.name : 'Fixkosten');
        body.textContent = '';
        const t = today();
        const status = o.status === 'paid' ? 'Bezahlt' : o.status === 'skipped' ? 'Übersprungen' : o.dueDate < t ? 'Überfällig' : 'Offen';
        const busy = async (btn, action, payload) => { btn.disabled = true; try { await mutate(action, payload); } catch (e) { toast(e.message, true); } finally { btn.disabled = false; } };
        append(body, [
          h('div', { class: 'detail-head' }, h('div', { class: 'big num', text: chf(o.status === 'paid' ? o.paidAmount : o.amount) }), h('div', { class: 'sub', text: status + ' · fällig ' + fmtDate(o.dueDate) })),
          h('div', { class: 'card' },
            b ? kv('Wiederholung', INTERVALS[b.interval]) : null,
            b ? kv('Kategorie', (cat(b.categoryId) || {}).name || '–') : null,
            o.status === 'paid' ? kv('Bezahlt am', fmtDate(o.paidDate)) : null,
            o.status === 'paid' ? kv('Konto', (acc(o.accountId) || {}).name || '–') : null),
          h('div', { class: 'stack mt' },
            o.status === 'open' ? h('button', { class: 'btn mint block', type: 'button', onclick: () => openPay(o.id) }, icon('check', 's'), 'Als bezahlt markieren') : null,
            o.status === 'paid' ? h('button', { class: 'btn light block', type: 'button', onclick: async (e) => {
              const btn = e.currentTarget;
              if (!(await confirmBox('Zahlung zurücknehmen?', 'Die verknüpfte Ausgabe wird gelöscht und der Betrag ist wieder offen.', 'Zurücknehmen', true))) return;
              busy(btn, 'occ.unpay', { id: o.id });
            } }, icon('undo', 's'), 'Zahlung zurücknehmen') : null,
            o.status === 'open' ? h('button', { class: 'btn light block', type: 'button', onclick: (e) => busy(e.currentTarget, 'occ.skip', { id: o.id }) }, icon('skip', 's'), 'Diesmal nicht fällig (überspringen)') : null,
            o.status === 'skipped' ? h('button', { class: 'btn light block', type: 'button', onclick: (e) => busy(e.currentTarget, 'occ.unskip', { id: o.id }) }, icon('undo', 's'), 'Wieder öffnen') : null,
            b ? h('button', { class: 'btn ghost block', type: 'button', onclick: () => openBillForm(b) }, icon('edit', 's'), 'Fixkosten bearbeiten') : null),
        ]);
      };
      draw();
      ctl.onClose = onRender(draw);
    });
  }

  function openPay(id) {
    const o = byId(S.st.occurrences, id);
    if (!o || o.status !== 'open') return;
    const b = bill(o.billId);
    const requestId = uid();   // gleiche ID bei Doppelklick → keine doppelte Ausgabe
    sheet('Bezahlt', (body, ctl) => {
      const date = dateInput(today());
      const accSel = selectInput(accountOptions(null), b && b.accountId && acc(b.accountId) && !acc(b.accountId).archived ? b.accountId : defaultAccountId());
      const amt = moneyInput(toInput(o.amount));
      const err = errBox();
      const save = h('button', { class: 'btn mint block', type: 'submit' }, icon('check', 's'), 'Als bezahlt markieren');
      const form = h('form', { class: 'form' },
        h('div', { class: 'detail-head' }, h('div', { class: 'big num', text: chf(o.amount) }), h('div', { class: 'sub', text: (b ? b.name : '') + ' · fällig ' + fmtDate(o.dueDate) })),
        h('div', { class: 'row2' }, field('Bezahlt am', date), field('Konto', accSel)),
        field('Betrag (CHF)', amt),
        err, save);
      form.addEventListener('submit', runner(save, err, async () => {
        await mutate('occ.pay', { id: o.id, date: date.value, accountId: accSel.value, amount: amt.value }, requestId);
        ctl.close();
      }));
      body.appendChild(form);
    });
  }

  function openBillForm(b) {
    const requestId = uid();
    sheet(b ? 'Fixkosten bearbeiten' : 'Neue Fixkosten', (body, ctl) => {
      const name = textInput(b ? b.name : '', { maxlength: '80', placeholder: 'z. B. Handy-Abo' });
      const amt = amountField(b ? toInput(b.amount) : '');
      const catSel = selectInput(S.st.categories.filter((c) => c.type === 'expense' && (!c.archived || (b && c.id === b.categoryId))).map((c) => ({ value: c.id, label: c.name })), b ? b.categoryId : (S.st.categories.find((c) => c.type === 'expense' && c.name.startsWith('Abos')) || {}).id);
      const start = dateInput(b ? b.startDate : today());
      const choices = INTERVAL_CHOICES.concat(b && !INTERVAL_CHOICES.includes(b.interval) ? [b.interval] : []);
      const interval = selectInput(choices.map((v) => ({ value: v, label: INTERVALS[v] })), b ? b.interval : 'monthly');
      const accSel = selectInput(accountOptions(b ? b.accountId : null, 'Zuletzt verwendetes'), b ? (b.accountId || '') : defaultAccountId());
      const err = errBox();
      const save = h('button', { class: 'btn mint block', type: 'submit' }, b ? 'Speichern' : 'Fixkosten erfassen');
      const form = h('form', { class: 'form' },
        field('Name', name), amt.wrap,
        h('div', { class: 'row2' }, field(b ? 'Erste Fälligkeit' : 'Nächste Fälligkeit', start), field('Wiederholung', interval)),
        h('div', { class: 'row2' }, field('Kategorie', catSel), field('Konto', accSel, true)),
        b ? note('Wenn du Datum oder Wiederholung änderst, werden offene Termine neu geplant. Bezahlte bleiben.', 'info') : null,
        err, save,
        b ? h('div', { class: 'btn-row' },
          h('button', { class: 'btn light', type: 'button', onclick: async () => {
            try { await mutate('bill.active', { id: b.id, active: !b.active }); ctl.close(); } catch (e) { err.textContent = e.message; }
          } }, icon(b.active ? 'archive' : 'repeat', 's'), b.active ? 'Beenden' : 'Wieder aktiv'),
          h('button', { class: 'btn danger', type: 'button', onclick: async () => {
            if (!(await confirmBox('Fixkosten löschen?', 'Offene Termine werden entfernt. Bereits bezahlte Ausgaben bleiben als Buchungen erhalten.', 'Löschen', true))) return;
            try { await mutate('bill.delete', { id: b.id }); ctl.close(); } catch (e) { err.textContent = e.message; }
          } }, icon('trash', 's'), 'Löschen')) : null);
      form.addEventListener('submit', runner(save, err, async () => {
        await mutate('bill.save', { id: b ? b.id : undefined, name: name.value, amount: amt.input.value, categoryId: catSel.value, startDate: start.value,
          interval: interval.value, endDate: b ? b.endDate : null, accountId: accSel.value || null, note: b ? b.note : '' }, requestId);
        ctl.close();
      }));
      body.appendChild(form);
    });
  }

  // ================================================================ Sparziele
  PAGES.sparziele = (p) => {
    const goals = S.st.goals;
    const active = goals.filter((g) => !g.archived);
    const arch = goals.filter((g) => g.archived);
    const monthName = MONTHS[Number(today().slice(5, 7)) - 1];
    const card = (g) => h('div', { class: 'card budget-item', style: { marginBottom: '12px', cursor: 'pointer' }, role: 'button', tabindex: '0', onclick: () => openGoal(g.id), onkeydown: (e) => { if (e.key === 'Enter') openGoal(g.id); } },
      h('div', { class: 'top' },
        h('span', { class: 'dot mint' }, icon('target', 's')),
        h('span', { class: 'tx' }, h('div', { class: 't1', style: { fontWeight: 680 }, text: g.name }),
          h('div', { class: 't2', text: (g.monthly ? chf(g.monthly) + ' pro Monat' : 'ohne feste Sparrate') + (g.targetDate ? ' · bis ' + fmtDate(g.targetDate) : '') })),
        h('span', { class: 'rest', text: Math.min(100, Math.round(g.target ? g.saved / g.target * 100 : 0)) + ' %' })),
      progress(g.saved, g.target),
      h('div', { class: 'meta' }, h('span', { text: chf(g.saved) + ' von ' + chf(g.target) }), h('span', { text: g.remaining ? 'noch ' + chf(g.remaining) : 'Ziel erreicht' })),
      g.dueThisMonth > 0 ? h('div', { class: 'goal-due' },
        h('span', { text: 'Im ' + monthName + ' noch ' + chf(g.dueThisMonth) + ' zu sparen' }),
        h('button', { class: 'btn mint sm', type: 'button', onclick: (e) => { e.stopPropagation(); openSave(g); } }, 'Gespart'))
        : g.monthly > 0 && g.remaining > 0 ? h('div', { class: 'goal-due done' }, icon('check', 'xs'), 'Sparrate für ' + monthName + ' erledigt') : null);
    append(p, [
      topbar('Sparziele', { right: h('button', { class: 'icon-btn mint', type: 'button', 'aria-label': 'Neues Sparziel', onclick: () => openGoalForm() }, icon('plus')) }),
      note('Mit einer Sparrate pro Monat reserviert CASH das Geld automatisch – es wird vom freien Geld abgezogen, bis du «Gespart» drückst. Das verschiebt das Geld per Umbuchung auf dein Sparkonto.', 'info', 'mint'),
      h('div', { class: 'mt' }),
      active.length ? h('div', { class: 'cols' }, active.map((g) => h('div', null, card(g)))) : h('div', { class: 'card' }, empty('target', 'Noch keine Sparziele.')),
      arch.length ? h('details', null, h('summary', { class: 'section-t', style: { cursor: 'pointer' }, text: 'Archiviert (' + arch.length + ')' }), arch.map(card)) : null,
    ]);
  };

  function openGoal(id) {
    sheet('Sparziel', (body, ctl) => {
      const draw = () => {
        const g = byId(S.st.goals, id);
        if (!g) { ctl.close(); return; }
        ctl.setTitle(g.name);
        body.textContent = '';
        append(body, [
          h('div', { class: 'detail-head' }, h('div', { class: 'sub', text: 'Gespart' }), h('div', { class: 'big num', text: chf(g.saved) }), progress(g.saved, g.target)),
          h('div', { class: 'card' }, kv('Zielbetrag', chf(g.target)), kv('Fehlt noch', chf(g.remaining)),
            kv('Sparrate', g.monthly ? chf(g.monthly) + ' / Monat' : '–'), g.monthly ? kv('Diesen Monat gespart', chf(g.savedThisMonth)) : null,
            kv('Zieldatum', g.targetDate ? fmtDate(g.targetDate) : '–')),
          h('button', { class: 'btn mint block', type: 'button', onclick: () => openSave(g) }, icon('plus', 's'), 'Gespart'),
          h('div', { class: 'btn-row mt' },
            h('button', { class: 'btn light', type: 'button', onclick: () => openSave(g, 'remove') }, icon('minus', 's'), 'Entnehmen'),
            h('button', { class: 'btn light', type: 'button', onclick: () => openGoalForm(g) }, icon('edit', 's'), 'Bearbeiten')),
          h('span', { class: 'section-t', text: 'Verlauf' }),
          h('div', { class: 'card flush' }, g.allocations.length ? g.allocations.slice().reverse().map((a) => h('div', { class: 'row' },
            h('span', { class: 'ic ' + (a.amount > 0 ? 'mint' : '') }, icon(a.txId ? 'transfer' : a.amount > 0 ? 'plus' : 'minus', 's')),
            h('span', { class: 'tx' }, h('div', { class: 't1', text: a.note || (a.amount > 0 ? 'Gespart' : 'Entnommen') }),
              h('div', { class: 't2', text: fmtDate(a.date) + (a.txId ? ' · mit Umbuchung' : ' · nur zugewiesen') })),
            h('span', { class: 'amt', text: (a.amount > 0 ? '+ ' : '') + fmt(a.amount) }),
            h('button', { class: 'icon-btn flat', type: 'button', 'aria-label': 'Eintrag löschen', onclick: async () => {
              if (!(await confirmBox('Eintrag löschen?', a.txId ? 'Die Zuweisung und die zugehörige Umbuchung werden gelöscht.' : 'Die Zuweisung wird entfernt.', 'Löschen', true))) return;
              try { await mutate('goal.allocDelete', { goalId: g.id, allocId: a.id }); } catch (e) { toast(e.message, true); }
            } }, icon('trash', 's')))) : empty('target', 'Noch nichts gespart.')),
        ]);
      };
      draw();
      ctl.onClose = onRender(draw);
    });
  }

  /** «Gespart»: Betrag dem Ziel zuweisen und (optional) per Umbuchung aufs Sparkonto verschieben. */
  function openSave(g, mode) {
    mode = mode || 'add';
    const requestId = uid();
    sheet(mode === 'add' ? 'Gespart für ' + g.name : 'Entnehmen aus ' + g.name, (body, ctl) => {
      const amt = amountField(mode === 'add' && (g.dueThisMonth || g.monthly) ? toInput(g.dueThisMonth || g.monthly) : '');
      const date = dateInput(today());
      const accs = activeAccounts();
      const savingsAcc = accs.find((a) => !a.available);
      const spendAcc = acc(defaultAccountId());
      const [defFrom, defTo] = mode === 'add' ? [spendAcc, savingsAcc] : [savingsAcc, spendAcc];
      const fromSel = selectInput(accountOptions(null), defFrom ? defFrom.id : defaultAccountId());
      const toSel = selectInput(accountOptions(null, 'Keine Umbuchung (nur zuweisen)'), defTo && defFrom && defTo.id !== defFrom.id ? defTo.id : '');
      const fromField = field('Von Konto', fromSel);
      const sync = () => fromField.classList.toggle('hidden', !toSel.value);
      toSel.addEventListener('change', sync);
      const err = errBox();
      const save = h('button', { class: 'btn mint block', type: 'submit' }, mode === 'add' ? 'Gespart' : 'Entnehmen');
      const form = h('form', { class: 'form' }, amt.wrap,
        h('div', { class: 'row2' }, fromField, field('Auf Konto', toSel)),
        field('Datum', date),
        note(mode === 'add' ? 'Die Umbuchung verschiebt echtes Geld und zählt nicht als Ausgabe.' : 'Verringert den gesparten Betrag des Ziels.', 'transfer'),
        err, save);
      form.addEventListener('submit', runner(save, err, async () => {
        const payload = { goalId: g.id, mode, amount: amt.input.value, date: date.value };
        if (toSel.value) { payload.fromAccountId = fromSel.value; payload.toAccountId = toSel.value; }
        await mutate('goal.allocate', payload, requestId);
        ctl.close();
      }));
      body.appendChild(form);
      sync();
    });
  }

  function openGoalForm(g) {
    const requestId = uid();
    sheet(g ? 'Sparziel bearbeiten' : 'Neues Sparziel', (body, ctl) => {
      const name = textInput(g ? g.name : '', { maxlength: '60', placeholder: 'z. B. Neues Velo' });
      const target = amountField(g ? toInput(g.target) : '');
      const monthly = moneyInput(g && g.monthly ? toInput(g.monthly) : '', { placeholder: 'z. B. 50.00' });
      const date = dateInput(g ? g.targetDate : '');
      const initial = moneyInput('');
      const err = errBox();
      const save = h('button', { class: 'btn mint block', type: 'submit' }, g ? 'Speichern' : 'Sparziel erstellen');
      const form = h('form', { class: 'form' }, field('Name', name), h('div', { class: 'field' }, h('span', { text: 'Zielbetrag' }), target.wrap),
        h('div', { class: 'row2' }, field('Sparrate pro Monat', monthly, true), field('Zieldatum', date, true)),
        g ? null : field('Schon gespart (CHF)', initial, true), err, save,
        g ? h('div', { class: 'btn-row' },
          h('button', { class: 'btn light', type: 'button', onclick: async () => { try { await mutate('goal.save', { id: g.id, name: g.name, target: toInput(g.target), monthly: toInput(g.monthly), targetDate: g.targetDate, archived: !g.archived }); ctl.close(); } catch (e) { err.textContent = e.message; } } }, icon('archive', 's'), g.archived ? 'Aktivieren' : 'Archivieren'),
          h('button', { class: 'btn danger', type: 'button', onclick: async () => {
            if (!(await confirmBox('Sparziel löschen?', 'Kontostände bleiben unverändert; bereits erfasste Umbuchungen bleiben bestehen.', 'Löschen', true))) return;
            try { await mutate('goal.delete', { id: g.id }); closeAllSheets(); } catch (e) { err.textContent = e.message; }
          } }, icon('trash', 's'), 'Löschen')) : null);
      form.addEventListener('submit', runner(save, err, async () => {
        await mutate('goal.save', { id: g ? g.id : undefined, name: name.value, target: target.input.value, monthly: monthly.value, targetDate: date.value || null, initial: initial.value }, requestId);
        ctl.close();
      }));
      body.appendChild(form);
    });
  }

  // ================================================================ Import (PostFinance)
  const IMPORT = { preview: null, content: null, filename: '', accountId: '', decisions: {}, sync: true, busy: false, error: '' };
  const STATUS_LABEL = {
    new: ['Neu', 'mint'], duplicate: ['Bereits importiert', ''], match: ['Schon erfasst', 'blue'],
    transfer: ['Umbuchung erfasst', 'blue'], before: ['Vor Einrichtung', 'amber'],
  };

  function toBase64(buf) {
    const bytes = new Uint8Array(buf);
    let bin = '';
    for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    return btoa(bin);
  }

  async function runPreview() {
    IMPORT.busy = true; IMPORT.error = ''; render();
    try {
      const r = await api('import.preview', { content: IMPORT.content, filename: IMPORT.filename, accountId: IMPORT.accountId || null });
      if (r.needAccount) {
        IMPORT.preview = null;
        IMPORT.error = 'Zu welchem Konto gehört diese Datei' + (r.iban ? ' (IBAN ' + r.iban + ')' : '') + '? Bitte oben wählen.';
      } else {
        IMPORT.preview = r.preview;
        IMPORT.accountId = r.preview.accountId;
        IMPORT.decisions = {};
        IMPORT.sync = !!r.preview.closing;
      }
    } catch (e) { IMPORT.error = e.message; IMPORT.preview = null; }
    IMPORT.busy = false;
    render();
  }

  function decisionFor(row) {
    if (!IMPORT.decisions[row.i]) {
      IMPORT.decisions[row.i] = {
        i: row.i, action: row.action, type: row.type, categoryId: row.categoryId,
        transferAccountId: row.transferAccountId, payBill: !!row.billOccId,
      };
    }
    return IMPORT.decisions[row.i];
  }

  function importRow(row) {
    const dec = decisionFor(row);
    const [label, cls] = STATUS_LABEL[row.status] || ['', ''];
    const amtCls = row.amount > 0 ? 'pos' : 'neg';
    const box = h('div', { class: 'imp-row' + (dec.action === 'skip' ? ' off' : '') });
    const head = h('div', { class: 'imp-top' },
      h('div', { class: 'tx' }, h('div', { class: 't1', text: row.text }),
        h('div', { class: 't2' }, fmtDate(row.date), ' · ', h('span', { class: 'badge ' + cls, text: label }))),
      h('div', { class: 'amt ' + amtCls, text: (row.amount > 0 ? '+ ' : MINUS + ' ') + fmt(Math.abs(row.amount)) }));
    box.appendChild(head);
    if (row.status === 'duplicate') return box;

    const ctrl = h('div', { class: 'imp-ctrl' });
    if (row.status === 'match' || row.status === 'transfer') {
      const sel = selectInput([
        { value: 'link', label: 'Verknüpfen: ' + row.matchLabel },
        { value: 'import', label: 'Trotzdem als neue Buchung' },
        { value: 'skip', label: 'Überspringen' },
      ], dec.action, { 'aria-label': 'Aktion' });
      sel.addEventListener('change', () => { dec.action = sel.value; render(); });
      ctrl.appendChild(sel);
    } else {
      const inc = h('input', { type: 'checkbox', checked: dec.action !== 'skip', 'aria-label': 'Übernehmen' });
      inc.addEventListener('change', () => { dec.action = inc.checked ? 'import' : 'skip'; render(); });
      ctrl.appendChild(h('label', { class: 'check imp-inc' }, inc, h('span', { text: row.status === 'before' ? 'trotzdem übernehmen' : 'übernehmen' })));
    }
    if (dec.action === 'import') {
      const type = row.amount > 0 ? 'income' : 'expense';
      const own = activeAccounts().filter((a) => a.id !== IMPORT.accountId);
      const opts = [{ group: row.amount > 0 ? 'Einnahme' : 'Ausgabe', options: S.st.categories.filter((c) => c.type === type && (!c.archived || c.id === dec.categoryId)).map((c) => ({ value: 'cat:' + c.id, label: c.name })) }];
      if (own.length) opts.push({ group: 'Umbuchung (eigenes Konto)', options: own.map((a) => ({ value: 'acc:' + a.id, label: (row.amount > 0 ? 'von ' : 'auf ') + a.name })) });
      const cur = dec.type === 'transfer' ? 'acc:' + dec.transferAccountId : 'cat:' + dec.categoryId;
      const sel = selectInput(opts, cur, { 'aria-label': 'Kategorie' });
      sel.addEventListener('change', () => {
        const [k, id] = sel.value.split(':');
        if (k === 'acc') { dec.type = 'transfer'; dec.transferAccountId = id; } else { dec.type = type; dec.categoryId = id; }
      });
      ctrl.appendChild(sel);
      if (row.billOccId && dec.type !== 'transfer') {
        const pb = h('input', { type: 'checkbox', checked: dec.payBill });
        pb.addEventListener('change', () => { dec.payBill = pb.checked; });
        ctrl.appendChild(h('label', { class: 'check small' }, pb, h('span', { text: 'Fixkosten als bezahlt markieren: ' + row.billLabel })));
      }
    }
    box.appendChild(ctrl);
    return box;
  }

  PAGES.import = (p) => {
    const accs = activeAccounts();
    if (!IMPORT.accountId && accs.length) {
      IMPORT.accountId = (accs.find((a) => a.iban) || accs.find((a) => a.available) || accs[0]).id;
    }
    const accSel = selectInput(accs.map((a) => ({ value: a.id, label: a.name + (a.iban ? ' · ' + a.iban.slice(-4) : '') })), IMPORT.accountId, { 'aria-label': 'Konto' });
    accSel.addEventListener('change', () => { IMPORT.accountId = accSel.value; if (IMPORT.content) runPreview(); });
    const file = h('input', { type: 'file', accept: '.csv,.xml,.txt,text/csv,application/xml,text/xml', class: 'hidden' });
    file.addEventListener('change', async () => {
      const f = file.files[0];
      file.value = '';
      if (!f) return;
      if (f.size > 5 * 1024 * 1024) { toast('Die Datei ist zu gross (max. 5 MB).', true); return; }
      IMPORT.content = toBase64(await f.arrayBuffer());
      IMPORT.filename = f.name;
      runPreview();
    });
    const pv = IMPORT.preview;
    append(p, [
      topbar('Import', { back: 'buchungen' }),
      h('div', { class: 'card' },
        h('div', { class: 'form' },
          field('Konto', accSel),
          h('button', { class: 'btn mint block', type: 'button', disabled: IMPORT.busy, onclick: () => file.click() }, icon('upload', 's'), IMPORT.busy ? 'Wird gelesen …' : (IMPORT.content ? 'Andere Datei wählen' : 'PostFinance-Datei wählen')),
          file,
          IMPORT.filename ? h('div', { class: 'small muted', text: 'Datei: ' + IMPORT.filename }) : null,
          IMPORT.error ? h('div', { class: 'err', role: 'alert', text: IMPORT.error }) : null,
          !pv ? note('So geht’s: In PostFinance E-Finance → Konto → Bewegungen den Zeitraum wählen und als CSV exportieren (oder den Kontoauszug als camt.053-XML). Datei hier wählen – du siehst zuerst eine Vorschau, gespeichert wird erst nach Bestätigung. Bereits importierte Buchungen werden erkannt.', 'info') : null)),
      pv ? importPreviewView(pv) : null,
      importHistory(),
    ]);
  };

  function importPreviewView(pv) {
    const rows = pv.rows;
    const dupes = rows.filter((r) => r.status === 'duplicate');
    const shown = rows.filter((r) => r.status !== 'duplicate');
    const n = () => rows.filter((r) => r.status !== 'duplicate' && decisionFor(r).action !== 'skip').length;
    const err = errBox();
    const commit = h('button', { class: 'btn mint block', type: 'button' }, icon('check', 's'), n() + (n() === 1 ? ' Eintrag übernehmen' : ' Einträge übernehmen'));
    commit.addEventListener('click', runner(commit, err, async () => {
      const decisions = rows.filter((r) => r.status !== 'duplicate').map((r) => decisionFor(r));
      await mutate('import.commit', { previewId: pv.id, rows: decisions, syncBalance: IMPORT.sync });
      IMPORT.preview = null; IMPORT.content = null; IMPORT.filename = '';
      render();
    }));
    const syncBox = pv.closing ? (() => {
      const cb = h('input', { type: 'checkbox', checked: IMPORT.sync });
      cb.addEventListener('change', () => { IMPORT.sync = cb.checked; });
      return h('label', { class: 'check' }, cb, h('span', null, h('b', { text: 'Kontostand übernehmen: ' + chf(pv.closing.amount) }), h('br'),
        h('span', { class: 'small muted', text: 'Stand der Bank per ' + fmtDate(pv.closing.date) + '. CASH gleicht die Differenz aus – ohne Einnahme oder Ausgabe.' })));
    })() : null;
    return h('div', null,
      h('div', { class: 'card' },
        h('div', { class: 'card-h' }, h('h2', { text: 'Vorschau' })),
        kv('Zeitraum', pv.from ? fmtDate(pv.from) + ' – ' + fmtDate(pv.to) : '–'),
        kv('Format', pv.format === 'camt' ? 'ISO 20022 (camt)' : 'PostFinance CSV'),
        pv.iban ? kv('IBAN', pv.iban) : null,
        kv('Neu', String(pv.counts.new || 0)),
        (pv.counts.match || pv.counts.transfer) ? kv('Schon erfasst', String((pv.counts.match || 0) + (pv.counts.transfer || 0))) : null,
        dupes.length ? kv('Bereits importiert', String(dupes.length)) : null,
        pv.counts.before ? kv('Vor Kontoeinrichtung', String(pv.counts.before)) : null,
        syncBox ? h('div', { class: 'mt' }, syncBox) : null),
      shown.length ? h('div', { class: 'card flush imp-list' }, shown.map(importRow)) : h('div', { class: 'card' }, empty('check', 'Alles bereits importiert – nichts Neues.')),
      dupes.length ? h('details', { class: 'mb' }, h('summary', { class: 'section-t', style: { cursor: 'pointer' }, text: dupes.length + ' bereits importiert (werden übersprungen)' }),
        h('div', { class: 'card flush imp-list' }, dupes.map(importRow))) : null,
      err,
      h('div', { class: 'stack' }, commit,
        h('button', { class: 'btn light block', type: 'button', onclick: () => { IMPORT.preview = null; IMPORT.content = null; IMPORT.filename = ''; api('import.cancel', {}).catch(() => {}); render(); } }, 'Abbrechen')));
  }

  function importHistory() {
    const list = S.st.imports || [];
    if (!list.length) return null;
    return [h('span', { class: 'section-t', text: 'Letzte Importe' }),
      h('div', { class: 'card flush' }, list.map((b) => h('div', { class: 'row' },
        h('span', { class: 'ic mint' }, icon('download', 's')),
        h('span', { class: 'tx' }, h('div', { class: 't1', text: ((acc(b.accountId) || {}).name || 'Konto') + ' · ' + b.created_count + ' neu' + (b.linked_count ? ', ' + b.linked_count + ' verknüpft' : '') }),
          h('div', { class: 't2', text: new Date(b.created).toLocaleDateString('de-CH') + (b.from ? ' · ' + fmtDate(b.from, false) + ' – ' + fmtDate(b.to, false) : '') })),
        h('button', { class: 'btn sm light', type: 'button', onclick: async (e) => {
          const btn = e.currentTarget;
          if (!(await confirmBox('Import rückgängig machen?', 'Alle durch diesen Import erstellten Buchungen werden gelöscht (auch wenn du sie danach bearbeitet hast) und der Kontostand-Abgleich wird zurückgenommen.', 'Rückgängig', true))) return;
          btn.disabled = true;
          try { await mutate('import.undo', { batchId: b.id }); } catch (ex) { toast(ex.message, true); btn.disabled = false; }
        } }, 'Rückgängig'))))];
  }

  // ================================================================ Konten
  PAGES.konten = (p) => {
    const active = activeAccounts();
    const arch = S.st.accounts.filter((a) => a.archived);
    const row = (a) => h('button', { class: 'row', type: 'button', onclick: () => openAccountForm(a) },
      h('span', { class: 'ic ' + (a.archived ? '' : 'mint') }, icon(ACCOUNT_ICONS[a.type] || 'bank', 's')),
      h('span', { class: 'tx' }, h('div', { class: 't1', text: a.name }), h('div', { class: 't2', text: ACCOUNT_TYPES[a.type] + (a.available ? ' · zählt zum freien Geld' : ' · nicht eingerechnet') })),
      h('span', { class: 'end' }, h('div', { class: 'amt ' + (a.balance < 0 ? 'neg' : ''), text: chf(a.balance) })));
    append(p, [
      topbar('Konten', { back: 'uebersicht', right: h('button', { class: 'icon-btn mint', type: 'button', 'aria-label': 'Neues Konto', onclick: () => openAccountForm() }, icon('plus')) }),
      h('div', { class: 'card hero' }, h('div', { class: 'label', text: 'Kontostand · aktive Konten' }), h('div', { class: 'big num' }, h('small', { text: 'CHF' }), fmt(S.st.summary.totalBalance))),
      h('div', { class: 'card flush' }, active.map(row)),
      h('button', { class: 'btn light block', type: 'button', onclick: () => openTx({ type: 'transfer' }) }, icon('transfer', 's'), 'Umbuchung zwischen Konten'),
      h('a', { class: 'btn light block mt', href: '#/import' }, icon('upload', 's'), 'PostFinance-Kontoauszug importieren'),
      arch.length ? [h('span', { class: 'section-t', text: 'Archiviert' }), h('div', { class: 'card flush' }, arch.map(row))] : null,
      h('div', { class: 'mt' }), note('«Frei für Freizeit» rechnet mit den Alltagskonten. Sparkonten zählen nicht dazu – Geld dorthin verschiebst du mit einer Umbuchung oder «Gespart».', 'info'),
    ]);
  };

  function openAccountForm(a) {
    const requestId = uid();
    sheet(a ? a.name : 'Neues Konto', (body, ctl) => {
      const name = textInput(a ? a.name : '', { maxlength: '60' });
      const type = selectInput(Object.entries(ACCOUNT_TYPES).map(([v, l]) => ({ value: v, label: l })), a ? a.type : 'checking');
      const opening = moneyInput(a ? toInput(a.opening) : '', { placeholder: 'Aktueller Stand bei Einrichtung' });
      const iban = textInput(a ? (a.iban || '') : '', { placeholder: 'CH.. (für den Import)', autocapitalize: 'characters', spellcheck: 'false', maxlength: '42' });
      const av = h('input', { type: 'checkbox', checked: a ? a.available : true });
      if (!a) type.addEventListener('change', () => { av.checked = type.value !== 'savings'; });
      const err = errBox();
      const save = h('button', { class: 'btn mint block', type: 'submit' }, a ? 'Speichern' : 'Konto anlegen');
      const form = h('form', { class: 'form' },
        a ? h('div', { class: 'detail-head' }, h('div', { class: 'sub', text: 'Aktueller Kontostand' }), h('div', { class: 'big num', text: chf(a.balance) }),
          h('button', { class: 'btn light sm mt', type: 'button', onclick: () => { ctl.close(); openBalance(a); } }, icon('edit', 's'), 'Kontostand ändern')) : null,
        field('Name', name), h('div', { class: 'row2' }, field('Art', type), field('Anfangssaldo (CHF)', opening)),
        field('IBAN', iban, true),
        h('label', { class: 'check' }, av, h('span', null, 'Alltagskonto', h('br'), h('span', { class: 'small muted', text: 'Guthaben zählt zu «Frei für Freizeit». Für Sparkonten ausschalten.' }))),
        note(a ? 'Der Anfangssaldo ist der Stand bei der Einrichtung und zählt nicht als Einnahme. Eine Änderung wirkt sich auf den Kontostand aus.' : 'Gib den heutigen Kontostand ein. Er zählt nicht als Einnahme.', 'info'),
        err, save,
        a ? h('div', { class: 'btn-row' },
          h('button', { class: 'btn light', type: 'button', onclick: async () => {
            if (!a.archived && a.balance !== 0 && !(await confirmBox('Konto archivieren?', 'Das Konto hat noch ' + chf(a.balance) + '. Archivierte Konten zählen nicht mehr zum Gesamtstand.', 'Archivieren'))) return;
            try { await mutate('account.archive', { id: a.id, archived: !a.archived }); ctl.close(); } catch (e) { err.textContent = e.message; }
          } }, icon('archive', 's'), a.archived ? 'Wiederherstellen' : 'Archivieren'),
          h('button', { class: 'btn danger', type: 'button', onclick: async () => {
            if (!(await confirmBox('Konto löschen?', 'Nur möglich, wenn keine Buchungen existieren.', 'Löschen', true))) return;
            try { await mutate('account.delete', { id: a.id }); ctl.close(); } catch (e) { err.textContent = e.message; }
          } }, icon('trash', 's'), 'Löschen')) : null);
      form.addEventListener('submit', runner(save, err, async () => {
        if (opening.value.trim() === '') throw new Error('Bitte den Anfangssaldo eingeben (0 ist erlaubt).');
        await mutate('account.save', { id: a ? a.id : undefined, name: name.value, type: type.value, opening: opening.value.replace(/^\u2212/, '-'), available: av.checked, iban: iban.value }, requestId);
        ctl.close();
      }));
      body.appendChild(form);
    });
  }

  /** Aktuellen Kontostand direkt setzen (z. B. Wert aus der Bank-App). */
  function openBalance(a) {
    const requestId = uid();
    sheet('Kontostand ändern', (body, ctl) => {
      const amt = amountField(toInput(a.balance));
      amt.input.setAttribute('aria-label', 'Neuer Kontostand');
      const diffEl = h('div', { class: 'preview', 'aria-live': 'polite' });
      const parse = () => {
        const raw = amt.input.value.trim().replace(/^[\u2212-]\s*/, '');
        const v = parseAmount(raw);
        return v === null ? null : (/^\s*[\u2212-]/.test(amt.input.value) ? -v : v);
      };
      const update = () => {
        const v = parse();
        diffEl.textContent = '';
        if (v === null) { diffEl.textContent = 'Bitte einen gültigen Betrag eingeben (minus mit «-» davor).'; return; }
        const d = v - a.balance;
        append(diffEl, d === 0 ? 'Keine Änderung.' : ['Korrektur ', h('b', { text: (d > 0 ? '+ ' : MINUS + ' ') + fmt(Math.abs(d)) }), h('div', { class: 'small muted', text: 'Zählt nicht als Einnahme oder Ausgabe. Deine Buchungen bleiben unverändert.' })]);
      };
      amt.input.addEventListener('input', update);
      const err = errBox();
      const save = h('button', { class: 'btn mint block', type: 'submit' }, 'Kontostand speichern');
      const form = h('form', { class: 'form' },
        h('div', { class: 'small muted', text: a.name + ' · bisher ' + chf(a.balance) }),
        amt.wrap, diffEl, err, save);
      form.addEventListener('submit', runner(save, err, async () => {
        const v = parse();
        if (v === null) throw new Error('Bitte einen gültigen Betrag eingeben.');
        await mutate('account.setBalance', { id: a.id, balance: (v < 0 ? '-' : '') + toInput(Math.abs(v)) }, requestId);
        ctl.close();
      }));
      body.appendChild(form);
      update();
      setTimeout(() => { amt.input.focus(); amt.input.select(); }, 60);
    });
  }

  // ================================================================ Einstellungen
  PAGES.einstellungen = (p) => {
    const u = S.user;
    // Profil
    const name = textInput(u.name, { autocomplete: 'name' });
    const user = textInput(u.username, { autocomplete: 'username', autocapitalize: 'none' });
    const mail = textInput(u.email, { type: 'email', autocomplete: 'email', autocapitalize: 'none' });
    const pErr = errBox();
    const pBtn = h('button', { class: 'btn block', type: 'submit' }, 'Profil speichern');
    const pForm = h('form', { class: 'form' }, field('Name', name), field('Benutzername', user), field('E-Mail', mail, true), pErr, pBtn);
    pForm.addEventListener('submit', runner(pBtn, pErr, async () => {
      const r = await api('profile.save', { name: name.value, username: user.value, email: mail.value });
      S.user = r.user; toast(r.message); render();
    }));
    const openPinChange = () => sheet('PIN ändern', (b, ctl) => {
      const pinAttrs = { type: 'password', inputmode: 'numeric', maxlength: '8', autocomplete: 'off' };
      const cur = textInput('', pinAttrs);
      const nw = textInput('', pinAttrs);
      const nw2 = textInput('', pinAttrs);
      const e = errBox();
      const btn = h('button', { class: 'btn mint block', type: 'submit' }, 'PIN ändern');
      const f = h('form', { class: 'form' }, field('Aktuelle PIN', cur), h('div', { class: 'row2' }, field('Neue PIN (4–8 Ziffern)', nw), field('Wiederholen', nw2)),
        note('Alle anderen Geräte müssen danach die neue PIN eingeben.', 'lock'), e, btn);
      f.addEventListener('submit', runner(btn, e, async () => {
        const r = await api('pin.change', { current: cur.value, pin: nw.value, pin2: nw2.value });
        toast(r.message); ctl.close();
      }));
      b.appendChild(f);
    });
    // Passwort (kleines Formular im Sheet)
    const openPassword = () => sheet('Passwort ändern', (b, ctl) => {
      const cur = textInput('', { type: 'password', autocomplete: 'current-password' });
      const nw = textInput('', { type: 'password', autocomplete: 'new-password' });
      const nw2 = textInput('', { type: 'password', autocomplete: 'new-password' });
      const wErr = errBox();
      const wBtn = h('button', { class: 'btn mint block', type: 'submit' }, 'Passwort ändern');
      const wForm = h('form', { class: 'form' }, field('Aktuelles Passwort', cur), field('Neues Passwort (mind. 10 Zeichen)', nw), field('Neues Passwort wiederholen', nw2),
        note('Andere Geräte werden danach abgemeldet.', 'lock'), wErr, wBtn);
      wForm.addEventListener('submit', runner(wBtn, wErr, async () => {
        if (nw.value !== nw2.value) throw new Error('Die neuen Passwörter stimmen nicht überein.');
        const r = await api('password.change', { current: cur.value, password: nw.value });
        toast(r.message); ctl.close(); loadSessions();
      }));
      b.appendChild(wForm);
    });
    // Kategorien
    const catBox = h('div');
    const drawCats = () => {
      catBox.textContent = '';
      const list = S.st.categories.filter((c) => c.type === S.catTab);
      append(catBox, [
        seg([{ value: 'expense', label: 'Ausgaben' }, { value: 'income', label: 'Einnahmen' }], S.catTab, (v) => { S.catTab = v; drawCats(); }, 'sm'),
        h('div', { class: 'card flush mt', style: { boxShadow: 'none', background: 'var(--surface-2)' } }, list.map((c) => h('button', { class: 'row', type: 'button', onclick: () => openCategoryForm(c) },
          h('span', { class: 'ic ' + (c.archived ? '' : c.type === 'income' ? 'mint' : 'dark') }, icon(c.icon, 's')),
          h('span', { class: 'tx' }, h('div', { class: 't1', text: c.name }), c.archived ? h('div', { class: 't2', text: 'ausgeblendet' }) : null), icon('chevR', 'xs')))),
        h('button', { class: 'btn light block mt', type: 'button', onclick: () => openCategoryForm(null, S.catTab) }, icon('plus', 's'), 'Kategorie hinzufügen'),
      ]);
    };
    drawCats();
    // Kurse
    const rateBox = h('div', { class: 'stack' });
    S.st.settings.currencies.forEach((c) => {
      const inp = moneyInput(S.st.settings.lastRates[c] || '', { placeholder: 'noch nicht erfasst', 'aria-label': c + ' pro CHF' });
      const btn = h('button', { class: 'btn sm light', type: 'button' }, 'Speichern');
      const e = errBox();
      btn.addEventListener('click', runner(btn, e, async () => { await mutate('rate.set', { currency: c, rate: inp.value }); }));
      rateBox.appendChild(h('div', null, h('div', { class: 'flex' }, h('label', { class: 'field', style: { flex: 1 } }, h('span', { text: c + ' pro CHF' }), inp), h('div', { style: { alignSelf: 'flex-end' } }, btn)), e));
    });
    // Geräte & Backups
    const sessBox = h('div', null, h('div', { class: 'muted small', text: 'Lade …' }));
    const backupList = h('div');
    async function loadSessions() {
      try {
        const r = await api('sessions.list');
        sessBox.textContent = '';
        if (!r.sessions.length) sessBox.appendChild(h('div', { class: 'small muted', text: 'Keine Geräte mit «Angemeldet bleiben».' }));
        r.sessions.forEach((s) => sessBox.appendChild(h('div', { class: 'row', style: { padding: '10px 0' } },
          h('span', { class: 'ic' }, icon('devices', 's')),
          h('span', { class: 'tx' }, h('div', { class: 't1', text: s.device + (s.current ? ' (dieses Gerät)' : '') }),
            h('div', { class: 't2', text: 'Zuletzt ' + new Date(s.lastUsed).toLocaleDateString('de-CH') + ' · gültig bis ' + new Date(s.expires).toLocaleDateString('de-CH') })),
          h('button', { class: 'btn sm light', type: 'button', onclick: async () => {
            try { const x = await api('sessions.revoke', { id: s.id }); toast(x.message); loadSessions(); } catch (e) { toast(e.message, true); }
          } }, 'Abmelden'))));
        backupList.textContent = '';
        if (r.backups.length) {
          backupList.appendChild(h('div', { class: 'small muted mt', text: 'Automatische Sicherungen auf dem Server: ' + r.backups.length + ' (neueste ' + new Date(r.backups[0].date).toLocaleString('de-CH') + ')' }));
        }
      } catch (e) { sessBox.textContent = e.message; }
    }
    loadSessions();
    // Wiederherstellung
    const file = h('input', { type: 'file', accept: '.json,application/json', class: 'hidden' });
    file.addEventListener('change', async () => {
      const f = file.files[0];
      file.value = '';
      if (!f) return;
      if (f.size > 10 * 1024 * 1024) { toast('Die Datei ist zu gross.', true); return; }
      let data;
      try { data = JSON.parse(await f.text()); } catch (e) { toast('Die Datei ist kein gültiges JSON.', true); return; }
      if (!data || data.format !== 'cash-backup') { toast('Das ist keine CASH-Sicherung.', true); return; }
      const n = (data.data && data.data.transactions || []).length;
      if (!(await confirmBox('Sicherung wiederherstellen?', 'Sicherung vom ' + new Date(data.exportedAt).toLocaleString('de-CH') + ' mit ' + n + ' Buchungen. Deine aktuellen Daten werden ersetzt – vorher wird automatisch eine Sicherung auf dem Server angelegt.', 'Wiederherstellen', true))) return;
      try {
        const r = await api('backup.restore', { backup: data, month: S.month });
        S.st = r.state; S.month = r.state.month; render(); toast(r.message);
      } catch (e) { toast(e.message, true); }
    });

    const regToggle = h('input', { type: 'checkbox', checked: !!(S.status && S.status.registrationOpen) });
    regToggle.addEventListener('change', async () => {
      try { const r = await api('admin.registration', { open: regToggle.checked }); S.status.registrationOpen = r.registrationOpen; toast(r.message); }
      catch (e) { regToggle.checked = !regToggle.checked; toast(e.message, true); }
    });

    append(p, [
      topbar('Einstellungen', { back: 'uebersicht' }),
      h('div', { class: 'cols' },
        h('div', null,
          h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', { text: 'Profil' })), pForm),
          h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', { text: 'Sicherheit' })),
            h('div', { class: 'stack' },
              h('button', { class: 'btn light block', type: 'button', onclick: lockApp }, icon('lock', 's'), 'Jetzt sperren'),
              u.role === 'owner' ? h('button', { class: 'btn light block', type: 'button', onclick: openPinChange }, icon('lock', 's'), 'PIN ändern') : null,
              h('button', { class: 'btn light block', type: 'button', onclick: openPassword }, icon('lock', 's'), 'Passwort ändern'),
              u.role === 'owner' ? h('button', { class: 'btn ghost block', type: 'button', onclick: async () => {
                if (!(await confirmBox('Andere Geräte vergessen?', 'Alle anderen Geräte gelten danach als unbekannt und unterliegen wieder der strengeren globalen PIN-Sperre.', 'Vergessen', true))) return;
                try { const r = await api('pin.forgetDevices', {}); toast(r.message); } catch (e) { toast(e.message, true); }
              } }, 'Bekannte PIN-Geräte vergessen') : null,
              note('Hinweis: Eine 4-stellige PIN hat nur 10’000 Kombinationen und bietet nur begrenzten Schutz. CASH sperrt nach Fehlversuchen; für mehr Sicherheit eine 6–8-stellige PIN wählen.', 'info', 'amber'))),
          h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', { text: 'Angemeldete Geräte' })), sessBox,
            h('button', { class: 'btn light block mt', type: 'button', onclick: async () => {
              if (!(await confirmBox('Alle anderen Geräte abmelden?', 'Alle «Angemeldet bleiben»-Tokens anderer Geräte werden widerrufen.', 'Abmelden', true))) return;
              try { const r = await api('sessions.revokeOthers', {}); toast(r.message); loadSessions(); } catch (e) { toast(e.message, true); }
            } }, 'Alle anderen Geräte abmelden')),
          u.role === 'owner' ? h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', { text: 'Registrierung' })),
            h('label', { class: 'check' }, regToggle, h('span', null, 'Öffentliche Registrierung erlauben', h('br'), h('span', { class: 'small muted', text: 'Standardmässig aus. Jeder Account hat vollständig getrennte Daten.' })))) : null),
        h('div', null,
          h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', { text: 'Konten' })),
            h('a', { class: 'btn light block', href: '#/konten' }, icon('wallet', 's'), 'Konten verwalten')),
          h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', { text: 'Kategorien' })), catBox),
          h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', { text: 'Wechselkurse (manuell)' })),
            note('Kurs als «Fremdwährung pro CHF», z. B. 38.50 THB pro CHF. Änderungen wirken nur auf neue Buchungen.', 'info'), h('div', { class: 'mt' }), rateBox),
          h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', { text: 'Daten & Backup' })),
            h('div', { class: 'stack' },
              h('a', { class: 'btn mint block', href: '#/import' }, icon('upload', 's'), 'PostFinance-Kontoauszug importieren'),
              h('a', { class: 'btn light block', href: 'api.php?a=backup.download', download: '' }, icon('download', 's'), 'JSON-Backup herunterladen'),
              h('button', { class: 'btn light block', type: 'button', onclick: () => file.click() }, icon('upload', 's'), 'Backup wiederherstellen'),
              h('a', { class: 'btn light block', href: 'api.php?a=export.csv', download: '' }, icon('file', 's'), 'Alle Buchungen als CSV'),
              file, backupList)),
          h('div', { class: 'card flush' },
            h('button', { class: 'row', type: 'button', onclick: logout }, h('span', { class: 'ic' }, icon('logout', 's')), h('span', { class: 'tx' }, h('div', { class: 't1', text: 'Abmelden' }), h('div', { class: 't2', text: 'Widerruft auch «Angemeldet bleiben» auf diesem Gerät' })))),
          h('div', { class: 'small muted', style: { textAlign: 'center', margin: '18px 0' }, text: 'CASH ' + (S.status ? S.status.version : '') }))),
    ]);
  };

  function openCategoryForm(c, type) {
    const requestId = uid();
    sheet(c ? 'Kategorie bearbeiten' : 'Neue Kategorie', (body, ctl) => {
      const name = textInput(c ? c.name : '', { maxlength: '40' });
      let ic = c ? c.icon : 'dots';
      const grid = h('div', { class: 'icon-grid' }, CAT_ICONS.map((n) => h('button', { type: 'button', class: n === ic ? 'on' : '', 'aria-label': n, onclick: (e) => {
        ic = n; [...grid.children].forEach((x) => x.classList.remove('on')); e.currentTarget.classList.add('on');
      } }, icon(n, 's'))));
      const err = errBox();
      const save = h('button', { class: 'btn mint block', type: 'submit' }, 'Speichern');
      const form = h('form', { class: 'form' }, field('Name', name), h('div', { class: 'field' }, h('span', { text: 'Symbol' }), grid), err, save,
        c ? h('div', { class: 'btn-row' },
          h('button', { class: 'btn light', type: 'button', onclick: async () => { try { await mutate('category.archive', { id: c.id, archived: !c.archived }); ctl.close(); } catch (e) { err.textContent = e.message; } } }, icon('archive', 's'), c.archived ? 'Einblenden' : 'Ausblenden'),
          h('button', { class: 'btn danger', type: 'button', onclick: async () => { try { await mutate('category.delete', { id: c.id }); ctl.close(); } catch (e) { err.textContent = e.message; } } }, icon('trash', 's'), 'Löschen')) : null);
      form.addEventListener('submit', runner(save, err, async () => {
        await mutate('category.save', { id: c ? c.id : undefined, type: c ? c.type : type, name: name.value, icon: ic }, requestId);
        ctl.close();
      }));
      body.appendChild(form);
    });
  }

  // ================================================================ Start
  async function start() {
    try {
      if (!S.status) await refreshStatus();
      if (S.user) await loadState();
      render();
    } catch (e) {
      $app.textContent = '';
      $app.appendChild(h('div', { class: 'auth' }, h('div', { class: 'auth-box' },
        h('h1', { text: 'Verbindung fehlgeschlagen' }), h('p', { class: 'lead', text: e.message }),
        h('button', { class: 'btn mint', type: 'button', onclick: () => location.reload() }, 'Erneut versuchen'))));
    }
  }
  // Beim Zurückkehren in die App (z. B. iPhone) Daten auffrischen.
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && S.user && S.st && !openSheets.length) {
      loadState().then(render).catch(() => {});
    }
  });
  start();
})();
