/**
 * Wiedergabe direkt auf der Seite.
 * - Desktop: Spotify Web Playback SDK (Player läuft in diesem Tab, ein Tipp startet ihn).
 * - Mobile Browser: Das SDK wird dort von Spotify nicht unterstützt → Spotify Connect steuert
 *   die Spotify-App im Hintergrund (ohne sie in den Vordergrund zu holen).
 * Voraussetzungen: Spotify Premium, Wiedergabe vom Admin aktiviert. Das kurzlebige Zugriffstoken
 * kommt pro Anfrage vom Server und wird nirgends gespeichert.
 */
import { signal, computed } from '@preact/signals';
import { api, ApiError } from './api';
import { boot, platform, setUser, toast, user } from './state';
import { t } from './i18n';
import { route } from './router';
import type { Me } from './types';

export interface NowPlaying { key: string; title: string; subtitle: string; image: string | null }

interface SdkPlayer {
  connect(): Promise<boolean>;
  disconnect(): void;
  addListener(ev: string, cb: (arg: any) => void): void; // eslint-disable-line @typescript-eslint/no-explicit-any
  togglePlay(): Promise<void>;
  pause(): Promise<void>;
  resume(): Promise<void>;
  activateElement?(): Promise<void>;
}
declare global {
  interface Window { onSpotifyWebPlaybackSDKReady?: () => void; Spotify?: { Player: new (o: { name: string; getOAuthToken: (cb: (tok: string) => void) => void; volume?: number }) => SdkPlayer } }
}

export const now = signal<NowPlaying | null>(null);
export const playing = signal(false);
export const loadingKey = signal<string | null>(null);
export const progress = signal<{ pos: number; dur: number }>({ pos: 0, dur: 0 });
/** Swipe-Autoplay: nach dem ersten Tipp spielt jede neue Karte automatisch. */
export const autoplay = signal(false);

export const playbackEnabled = computed(() => platform.value.playback_mode === 'connect');
const isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent) || (navigator.maxTouchPoints > 1 && /Macintosh/.test(navigator.userAgent));

let sdkPlayer: SdkPlayer | null = null;
let deviceId: string | null = null;
let sdkPromise: Promise<string | null> | null = null;
let tick = 0;

function hasScope(me: Me, s: string): boolean {
  return (me.spotify.scopes ?? []).includes(s);
}

async function fetchToken(): Promise<string> {
  const r = await api<{ access_token: string }>('/api/playback/token');
  return r.access_token;
}

function loadSdk(): Promise<string | null> {
  if (sdkPromise) return sdkPromise;
  sdkPromise = new Promise<string | null>((resolve) => {
    const done = (id: string | null) => resolve(id);
    window.onSpotifyWebPlaybackSDKReady = () => {
      if (!window.Spotify) { done(null); return; }
      const p = new window.Spotify.Player({ name: 'SwipeGroove', volume: 0.8, getOAuthToken: (cb) => { fetchToken().then(cb).catch(() => cb('')); } });
      sdkPlayer = p;
      const fail = () => { sdkPromise = null; done(null); };
      p.addListener('ready', ({ device_id }: { device_id: string }) => { deviceId = device_id; done(device_id); });
      p.addListener('not_ready', () => { deviceId = null; });
      p.addListener('initialization_error', fail);
      p.addListener('authentication_error', () => { toast(t('player.auth_error'), 'error'); fail(); });
      p.addListener('account_error', () => { toast(t('player.premium_required'), 'error'); fail(); });
      p.addListener('playback_error', () => toast(t('player.error'), 'error'));
      p.addListener('player_state_changed', (st: { paused: boolean; position: number; duration: number; track_window?: { current_track?: { name: string; artists: { name: string }[]; album?: { images?: { url: string }[] } } } } | null) => {
        if (!st) return;
        playing.value = !st.paused;
        progress.value = { pos: st.position, dur: st.duration };
        const tr = st.track_window?.current_track;
        if (tr && now.value) now.value = { ...now.value, title: tr.name, subtitle: tr.artists.map((a) => a.name).join(', '), image: tr.album?.images?.[0]?.url ?? now.value.image };
      });
      p.connect().then((ok) => { if (!ok) fail(); });
    };
    const s = document.createElement('script');
    s.src = 'https://sdk.scdn.co/spotify-player.js';
    s.async = true;
    s.onerror = () => { sdkPromise = null; done(null); };
    document.head.appendChild(s);
    window.setTimeout(() => done(deviceId), 12000);
  });
  return sdkPromise;
}

function startTicker() {
  window.clearInterval(tick);
  tick = window.setInterval(() => {
    if (playing.value && progress.value.dur > 0) progress.value = { ...progress.value, pos: Math.min(progress.value.dur, progress.value.pos + 1000) };
  }, 1000);
}

/** Prüft Voraussetzungen; zeigt sonst eine kurze Erklärung. */
async function ensureReady(): Promise<boolean> {
  const me = user.value;
  if (!me) return false;
  if (!playbackEnabled.value) { toast(t('player.disabled'), 'info', 4500); return false; }
  if (!hasScope(me, 'user-modify-playback-state') || (!isMobile && !hasScope(me, 'streaming'))) {
    toast(t('player.reconnect_needed'), 'info', 5000);
    window.setTimeout(() => { location.href = boot.basePath + '/auth/spotify/start?purpose=reconnect&redirect=' + encodeURIComponent(route.value.path + location.search); }, 1200);
    return false;
  }
  if (!me.playback_enabled) {
    try { setUser(await api<Me>('/api/me', { method: 'PATCH', body: { playback_enabled: true } })); } catch { return false; }
  }
  return true;
}

/**
 * Spielt eine Liste von Spotify-Track-IDs ab (max. 50). Muss aus einem Tipp/Klick heraus aufgerufen werden.
 */
export async function playTracks(key: string, trackIds: string[], meta: Omit<NowPlaying, 'key'>, offset = 0): Promise<boolean> {
  const ids = trackIds.filter((x) => /^[A-Za-z0-9]{22}$/.test(x)).slice(0, 50);
  if (ids.length === 0) { toast(t('player.nothing'), 'info'); return false; }
  if (!(await ensureReady())) return false;
  loadingKey.value = key;
  try {
    let device: string | null = null;
    if (!isMobile) {
      device = deviceId ?? (await loadSdk());
      try { await sdkPlayer?.activateElement?.(); } catch { /* optional */ }
    }
    await api('/api/playback/play', { body: { track_ids: ids, offset, device_id: device ?? undefined } });
    now.value = { key, ...meta };
    playing.value = true;
    progress.value = { pos: 0, dur: 0 };
    startTicker();
    return true;
  } catch (e) {
    if (e instanceof ApiError && e.code === 'playback.no_device') toast(isMobile ? t('player.no_device_mobile') : t('player.no_device'), 'info', 6000);
    else if (e instanceof ApiError && e.code === 'playback.premium_required') toast(t('player.premium_required'), 'error', 5000);
    else if (e instanceof ApiError) toast(e.message, 'error');
    return false;
  } finally {
    loadingKey.value = null;
  }
}

export async function togglePause(): Promise<void> {
  if (!now.value) return;
  try {
    if (sdkPlayer && deviceId && !isMobile) {
      await sdkPlayer.togglePlay();
      playing.value = !playing.value;
    } else if (playing.value) {
      await api('/api/playback/pause', { body: {} });
      playing.value = false;
    } else {
      playing.value = true;
      await api('/api/playback/play', { body: { resume: true } }).catch(() => { playing.value = false; });
    }
  } catch { /* leise */ }
}

export function stop(): void {
  if (playing.value) {
    if (sdkPlayer && deviceId && !isMobile) sdkPlayer.pause().catch(() => undefined);
    else api('/api/playback/pause', { body: {} }).catch(() => undefined);
  }
  playing.value = false;
  now.value = null;
  autoplay.value = false;
  window.clearInterval(tick);
}
