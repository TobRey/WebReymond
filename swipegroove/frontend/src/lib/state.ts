import { signal, computed } from '@preact/signals';
import type { Boot, Me, Platform } from './types';

function readBoot(): Boot | undefined {
  const el = document.getElementById('sg-boot');
  if (!el) return undefined;
  try {
    const b = JSON.parse(el.textContent || '') as Boot;
    (window as unknown as { __SG__?: Boot }).__SG__ = b;
    return b;
  } catch {
    return undefined;
  }
}
const raw = readBoot();
export const boot: Boot = raw ?? {
  basePath: '', baseUrl: location.origin, lang: 'de', csrf: null, version: '0', user: null, i18n: {},
  platform: { owner_registered: false, registration_open: false, operation_mode: 'setup', spotify_configured: false, playback_mode: 'off', covers_available: false, cover_model: '', billing_available: false, billing_mode: 'test', premium_price_chf: '2.00', premium_cover_quota: 50, legal: { operator_name: '', operator_address: '', operator_email: '', operator_country: '' } },
};

export const user = signal<Me | null>(boot.user);
export const platform = signal<Platform>(boot.platform);
export const csrf = signal<string | null>(boot.csrf);
export const isLoggedIn = computed(() => user.value !== null);
export const isAdmin = computed(() => !!user.value?.is_admin);

export interface Toast { id: number; kind: 'ok' | 'error' | 'info'; text: string }
export const toasts = signal<Toast[]>([]);
let toastId = 0;
export function toast(text: string, kind: Toast['kind'] = 'info', ms = 3200): void {
  const id = ++toastId;
  toasts.value = [...toasts.value, { id, kind, text }];
  window.setTimeout(() => { toasts.value = toasts.value.filter((t) => t.id !== id); }, ms);
}

export function setUser(me: Me | null): void {
  user.value = me;
}
