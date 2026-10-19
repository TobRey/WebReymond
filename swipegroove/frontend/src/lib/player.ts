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
  seek(ms: number): Promise<void>;
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
/** Startpunkt beim Swipen in Sekunden (0, 30, 60) – pro Gerät gemerkt. */
function readStart(): number { try { return Number(localStorage.getItem('sg_start') || '0') || 0; } catch { return 0; } }
export const swipeStart = signal<number>(readStart());
export function cycleSwipeStart(): void {
  const next = swipeStart.value === 0 ? 30 : swipeStart.value === 30 ? 60 : 0;
  swipeStart.value = next;
  try { localStorage.setItem('sg_start', String(next)); } catch { /* egal */ }
}
/** Groove-Modus: Tempo (BPM) für die Seitenanimation, per Tippen einstellbar. Keine Audioanalyse. */
function readBpm(): number { try { return Number(localStorage.getItem('sg_bpm') || '0') || 112; } catch { return 112; } }
export const bpm = signal<number>(readBpm());
export const beatEpoch = signal(0);
let taps: number[] = [];
export function tapTempo(): void {
  const t0 = performance.now();
  taps = [...taps.filter((x) => t0 - x < 2500), t0].slice(-6);
  beatEpoch.value++;
  if (taps.length >= 3) {
    const gaps = taps.slice(1).map((x, i) => x - taps[i]);
    const avg = gaps.reduce((a, b) => a + b, 0) / gaps.length;
    const v = Math.round(Math.max(60, Math.min(200, 60000 / avg)));
    bpm.value = v;
    try { localStorage.setItem('sg_bpm', String(v)); } catch { /* egal */ }
  }
}

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

const sdkMode = () => !!(sdkPlayer && deviceId && !isMobile);
let polls = 0;

/** Holt Titel/Position vom Server (Spotify Connect); im Browser-Player liefert das SDK die Daten selbst. */
async function pollState(): Promise<void> {
  if (!now.value || sdkMode() || document.visibilityState !== 'visible') return;
  try {
    const st = await api<{ active: boolean; is_playing?: boolean; progress_ms?: number; duration_ms?: number; name?: string; artists?: string[]; image_url?: string | null }>('/api/playback/state');
    if (!st.active || !now.value) return;
    playing.value = !!st.is_playing;
    progress.value = { pos: st.progress_ms ?? 0, dur: st.duration_ms ?? 0 };
    if (st.name) now.value = { ...now.value, title: st.name, subtitle: (st.artists ?? []).join(', '), image: st.image_url ?? now.value.image };
  } catch { /* leise */ }
}

function startTicker() {
  window.clearInterval(tick);
  polls = 0;
  tick = window.setInterval(() => {
    if (playing.value && progress.value.dur > 0) progress.value = { ...progress.value, pos: Math.min(progress.value.dur, progress.value.pos + 1000) };
    if (!sdkMode() && (++polls === 2 || polls % 5 === 0)) pollState();
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

async function start(key: string, meta: Omit<NowPlaying, 'key'>, body: Record<string, unknown>, dur = 0, posMs = 0): Promise<boolean> {
  if (!(await ensureReady())) return false;
  loadingKey.value = key;
  try {
    let device: string | null = null;
    if (!isMobile) {
      device = deviceId ?? (await loadSdk());
      try { await sdkPlayer?.activateElement?.(); } catch { /* optional */ }
    }
    await api('/api/playback/play', { body: { ...body, position_ms: posMs || undefined, device_id: device ?? undefined } });
    now.value = { key, ...meta };
    playing.value = true;
    progress.value = { pos: posMs, dur };
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

/**
 * Spielt eine Liste von Spotify-Track-IDs ab (max. 50). Muss aus einem Tipp/Klick heraus aufgerufen werden.
 */
export async function playTracks(key: string, trackIds: string[], meta: Omit<NowPlaying, 'key'>, offset = 0, opts: { positionMs?: number; durationMs?: number } = {}): Promise<boolean> {
  const ids = trackIds.filter((x) => /^[A-Za-z0-9]{22}$/.test(x)).slice(0, 50);
  if (ids.length === 0) { toast(t('player.nothing'), 'info'); return false; }
  const pos = opts.durationMs && opts.positionMs && opts.positionMs > opts.durationMs - 15000 ? 0 : (opts.positionMs ?? 0);
  return start(key, meta, { track_ids: ids, offset }, opts.durationMs ?? 0, pos);
}

/** Spielt eine ganze Spotify-Playlist (beliebig lang) ab Song Nr. offset (0 = erster Song). */
export async function playPlaylist(key: string, playlistId: string, meta: Omit<NowPlaying, 'key'>, offset = 0): Promise<boolean> {
  if (!/^[A-Za-z0-9]{22}$/.test(playlistId)) { toast(t('player.nothing'), 'info'); return false; }
  return start(key, meta, { playlist_id: playlistId, offset });
}

/** Springt an eine Stelle im aktuellen Song (Millisekunden). */
export async function seekTo(ms: number): Promise<void> {
  if (!now.value) return;
  const dur = progress.value.dur;
  const pos = Math.max(0, dur > 0 ? Math.min(dur - 500, ms) : ms);
  progress.value = { ...progress.value, pos };
  try {
    if (sdkMode()) await sdkPlayer!.seek(pos);
    else await api('/api/playback/seek', { body: { position_ms: Math.round(pos) } });
  } catch (e) { if (e instanceof ApiError) toast(e.message, 'error'); }
}

export function skipBy(deltaMs: number): Promise<void> {
  return seekTo(progress.value.pos + deltaMs);
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
