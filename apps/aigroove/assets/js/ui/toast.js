/**
 * AI Groove – kurze Hinweise (Toasts).
 *
 * Bewusst unaufdringlich: keine Popups, keine Bestätigungsdialoge für
 * Selbstverständlichkeiten. Fehler bleiben länger stehen als Erfolge.
 */

import { h, clear } from '../core/dom.js';

const root = () => document.getElementById('toast-root');

const live = new Set();

function show(kind, title, text, duration) {
  const container = root();
  if (!container) return () => {};

  const el = h(`div.toast.toast--${kind}`, null, [
    h('span.toast__dot'),
    h('div.toast__body', null, [
      title ? h('div.toast__title', { text: title }) : null,
      text ? h('div.toast__text', { text }) : null,
    ]),
  ]);

  container.appendChild(el);
  live.add(el);

  // Nie mehr als vier gleichzeitig.
  while (live.size > 4) {
    const oldest = live.values().next().value;
    dismiss(oldest);
  }

  let timer = 0;
  if (duration > 0) timer = setTimeout(() => dismiss(el), duration);

  el.addEventListener('click', () => {
    clearTimeout(timer);
    dismiss(el);
  });

  return () => {
    clearTimeout(timer);
    dismiss(el);
  };
}

function dismiss(el) {
  if (!el || !live.has(el)) return;
  live.delete(el);
  el.classList.add('toast--out');
  setTimeout(() => el.remove(), 250);
}

export const toast = {
  info: (title, text = '') => show('info', title, text, 3200),
  ok: (title, text = '') => show('ok', title, text, 2600),
  warn: (title, text = '') => show('warn', title, text, 5200),
  /** Fehler bleiben stehen, bis der Nutzer sie antippt. */
  error: (title, text = '') => show('err', title, text, 9000),
  /** Bleibt sichtbar, bis die zurückgegebene Funktion aufgerufen wird. */
  sticky: (title, text = '', kind = 'info') => show(kind, title, text, 0),
  clearAll() {
    const container = root();
    if (container) clear(container);
    live.clear();
  },
};

/**
 * Zeigt einen Fehler nutzerfreundlich an.
 * Technische Details landen ausschliesslich in der Entwicklerkonsole.
 */
export function reportError(context, error) {
  const message =
    error?.message && error.message.length < 260
      ? error.message
      : 'Es ist ein unerwarteter Fehler aufgetreten.';
  console.error(`[AI Groove] ${context}`, error);
  toast.error(context, message);
}
