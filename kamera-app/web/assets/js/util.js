// Kleine Hilfsfunktionen ohne Abhängigkeiten.

export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

/** Element bauen: h('div', {class: 'x', onclick}, 'Text', child) */
export function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v == null || v === false) continue;
    if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else if (k === 'class') el.className = v;
    else if (k === 'html') el.innerHTML = v;
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else el.setAttribute(k, v === true ? '' : v);
  }
  for (const c of children.flat()) {
    if (c == null || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return el;
}

export function icon(name, cls = '') {
  return `<svg class="ic ${cls}" aria-hidden="true"><use href="#i-${name}"/></svg>`;
}

const nf1 = new Intl.NumberFormat('de-CH', { maximumFractionDigits: 1 });
export function fmtBytes(n) {
  if (n == null || isNaN(n)) return '–';
  if (n < 1024) return `${n} B`;
  const u = ['KB', 'MB', 'GB', 'TB'];
  let i = -1;
  do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
  return `${nf1.format(n)} ${u[i]}`;
}

const dtf = new Intl.DateTimeFormat('de-CH', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const tf = new Intl.DateTimeFormat('de-CH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
export const fmtDate = (d) => (d ? dtf.format(d) : '–');
export const fmtTime = (d) => (d ? tf.format(d) : '');

/** CCAPI liefert 'lastmodifieddate' als Text; Format ist nicht garantiert. */
export function parseCamDate(s) {
  if (!s) return null;
  const d = new Date(s);
  if (!isNaN(d)) return d;
  const m = /(\d{4})[:\-/](\d{2})[:\-/](\d{2})[ T](\d{2}):(\d{2}):?(\d{2})?/.exec(s);
  return m ? new Date(+m[1], m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)) : null;
}

const TYPES = {
  jpg: ['jpeg', 'JPEG', 'image/jpeg'], jpeg: ['jpeg', 'JPEG', 'image/jpeg'],
  cr3: ['raw', 'RAW', 'image/x-canon-cr3'], cr2: ['raw', 'RAW', 'image/x-canon-cr2'],
  heif: ['heif', 'HEIF', 'image/heif'], hif: ['heif', 'HEIF', 'image/heif'],
  mp4: ['video', 'Video', 'video/mp4'], mov: ['video', 'Video', 'video/quicktime'],
};
export function fileKind(name) {
  const ext = (name.split('.').pop() || '').toLowerCase();
  const t = TYPES[ext] || ['other', ext.toUpperCase() || 'Datei', 'application/octet-stream'];
  return { ext: ext.toUpperCase(), type: t[0], label: t[1], mime: t[2] };
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

let toastTimer;
export function toast(msg, kind = '') {
  const el = $('#toast');
  if (!el) return;
  el.textContent = msg;
  el.className = `toast show ${kind}`;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (el.className = 'toast'), 3200);
}

export function lsGet(key, fallback) {
  try {
    const v = localStorage.getItem(key);
    return v == null ? fallback : JSON.parse(v);
  } catch { return fallback; }
}
export function lsSet(key, value) {
  try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* privater Modus */ }
}

/** fetch mit Zeitlimit */
export async function fetchT(url, opts = {}, ms = 8000) {
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), ms);
  if (opts.signal) opts.signal.addEventListener('abort', () => ctl.abort(), { once: true });
  try {
    return await fetch(url, { ...opts, signal: ctl.signal });
  } finally {
    clearTimeout(t);
  }
}

/** Bildschirm wach halten (nur in sicherem Kontext verfügbar). */
let wakeLock = null;
let wakeWanted = 0;
export async function keepAwake(on) {
  wakeWanted = Math.max(0, wakeWanted + (on ? 1 : -1));
  if (!('wakeLock' in navigator)) return;
  try {
    if (wakeWanted > 0 && !wakeLock) {
      wakeLock = await navigator.wakeLock.request('screen');
      wakeLock.addEventListener('release', () => (wakeLock = null));
    } else if (wakeWanted === 0 && wakeLock) {
      await wakeLock.release();
      wakeLock = null;
    }
  } catch { /* nicht erlaubt */ }
}
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && wakeWanted > 0 && !wakeLock) {
    wakeWanted--; keepAwake(true);
  }
});
