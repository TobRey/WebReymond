import { signal } from '@preact/signals';
import { boot } from './state';
import { de } from '../i18n/de';
import { en } from '../i18n/en';

export type Lang = 'de' | 'en';
const ui: Record<Lang, Record<string, string>> = { de, en };
export const lang = signal<Lang>(boot.lang === 'en' ? 'en' : 'de');
/** Server-Übersetzungen (Fehlermeldungen) pro Sprache; Startsprache kommt aus dem Boot-Objekt. */
const server: Record<string, Record<string, string>> = { [boot.lang]: boot.i18n };

export function setServerBundle(l: Lang, bundle: Record<string, string>): void {
  server[l] = bundle;
}

export function t(key: string, params?: Record<string, string | number>): string {
  const l = lang.value;
  let text = ui[l][key] ?? server[l]?.[key] ?? ui.de[key] ?? server.de?.[key] ?? key;
  if (params) {
    for (const [k, v] of Object.entries(params)) {
      text = text.split('{' + k + '}').join(String(v));
    }
  }
  return text;
}

export function hasKey(key: string): boolean {
  const l = lang.value;
  return !!(ui[l][key] ?? server[l]?.[key]);
}

/** Pluralisierung: key.one / key.other */
export function tn(key: string, n: number, params?: Record<string, string | number>): string {
  return t(n === 1 ? key + '.one' : key + '.other', { n, ...(params ?? {}) });
}

export function fmtDate(iso: string | null | undefined, withTime = false): string {
  if (!iso) return '';
  const d = new Date(iso.includes('T') ? iso : iso.replace(' ', 'T') + 'Z');
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString(lang.value === 'de' ? 'de-CH' : 'en-GB', withTime ? { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' } : { day: '2-digit', month: '2-digit', year: 'numeric' });
}

export function timeAgo(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso.includes('T') ? iso : iso.replace(' ', 'T') + 'Z');
  const s = Math.max(0, (Date.now() - d.getTime()) / 1000);
  if (s < 60) return t('time.now');
  if (s < 3600) return t('time.minutes', { n: Math.floor(s / 60) });
  if (s < 86400) return t('time.hours', { n: Math.floor(s / 3600) });
  if (s < 7 * 86400) return t('time.days', { n: Math.floor(s / 86400) });
  return fmtDate(iso);
}

export function fmtDuration(ms: number | null | undefined): string {
  if (!ms) return '';
  const total = Math.round(ms / 1000);
  const m = Math.floor(total / 60);
  const s = total % 60;
  return m + ':' + String(s).padStart(2, '0');
}
