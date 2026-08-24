/**
 * Ton des Spiels.
 *
 * Effekte laufen über die Web Audio API: Jede Datei wird genau einmal
 * geladen und entschlüsselt, danach kostet ein Abspielen praktisch nichts.
 * Die frühere Fassung hat für jede Variante mehrere <audio>-Elemente
 * angelegt und beim Freischalten alle einmal angespielt - das waren über
 * hundert gleichzeitige Ladevorgänge, hörbare Geräusche schon in der
 * Charakterauswahl und ein spürbares Ruckeln auf dem Handy.
 *
 * Die Musik bleibt ein <audio>-Element: Sie ist mehrere Minuten lang und
 * soll gestreamt und nicht komplett in den Speicher entschlüsselt werden.
 */
const EVENTS = [
  'shoot', 'melee', 'hit', 'crit', 'enemyDeath', 'explosion',
  'upgrade', 'bossSpawn', 'bossWarning', 'playerHit', 'coin', 'potion',
  'gameOver', 'uiClick', 'waveClear', 'gameStart', 'run',
];

const STORAGE_KEY = 'arena.audio.v2';
const clips = new Map();        // Ereignis -> {enabled, chance, volume, variants}
const buffers = new Map();      // Dateipfad -> AudioBuffer
const failed = new Set();       // Dateien, die nicht geladen werden konnten
const pending = new Map();      // laufende Ladevorgänge
const throttled = new Map();

let ctx = null;
let master = null;
let musicTargetVolume = 0.45;
let unlocked = false;

/**
 * Zwei Titel: einer fuer die Menues, einer fuer den Kampf.
 *
 * Es laeuft immer hoechstens einer. Beim Wechsel blendet der alte aus und
 * der neue ein - im Menue ist die Kampfmusik damit wirklich aus, nicht nur
 * leise.
 */
const tracks = {
  menu: { src: '', el: null, fade: 0 },
  game: { src: '', el: null, fade: 0 },
};
let currentTrack = '';
let wantsTrack = '';
let warmTimer = 0;

const settings = loadSettings();

function loadSettings() {
  // musicVolume und sfxVolume sind die Regler des Spielers (0-1) und
  // multiplizieren die im Admin gesetzten Grundlautstärken.
  const fallback = { musicOn: true, sfxOn: true, musicVolume: 1, sfxVolume: 1, sfxMaster: 0.8 };
  try {
    return { ...fallback, ...(JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') || {}) };
  } catch {
    return fallback;
  }
}

function saveSettings() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
  } catch { /* privater Modus */ }
}

/* ------------------------------------------------------------ Freischalten */

/**
 * Browser lassen Ton erst nach einer Nutzergeste zu. Die Geste erzeugt
 * hier den AudioContext und weckt ihn - lautlos, es wird nichts abgespielt.
 */
function unlock() {
  if (!ctx) {
    const Ctor = window.AudioContext || window.webkitAudioContext;
    if (!Ctor) return;
    try {
      ctx = new Ctor();
      master = ctx.createGain();
      master.gain.value = 1;
      master.connect(ctx.destination);
    } catch {
      ctx = null;
      return;
    }
  }
  if (ctx.state === 'suspended') ctx.resume().catch(() => {});
  unlocked = ctx.state === 'running';
  if (unlocked) {
    // Erst die Spielgrafiken, dann die Töne: Auf einer langsamen Leitung
    // sollen die Tondateien nicht mit der Karte um Bandbreite streiten.
    // Fehlt ein Ton beim ersten Mal, wird er dann eben nachgeladen.
    if (!warmTimer) warmTimer = setTimeout(warmup, 2500);
    if (wantsTrack) Audio.playTrack(wantsTrack);
  }
}

// Nicht "once": Auf Mobilgeräten kann der Context später wieder einschlafen,
// dann weckt ihn die nächste Berührung erneut.
['pointerdown', 'keydown', 'touchstart'].forEach((type) =>
  window.addEventListener(type, unlock, { passive: true }));
document.addEventListener('visibilitychange', () => {
  if (!document.hidden) unlock();
});

/* ---------------------------------------------------------------- Laden */

/** Höchstens zwei Dateien gleichzeitig - der Rest wartet in der Schlange. */
let inFlight = 0;
const queue = [];

function pump() {
  while (inFlight < 2 && queue.length) {
    const src = queue.shift();
    inFlight++;
    fetchBuffer(src).finally(() => {
      inFlight--;
      pump();
    });
  }
}

async function fetchBuffer(src) {
  try {
    const res = await fetch(src, { cache: 'force-cache' });
    if (!res.ok) throw new Error(String(res.status));
    const raw = await res.arrayBuffer();
    const buffer = await new Promise((resolve, reject) => {
      // Safari kennt decodeAudioData nur mit Callbacks.
      const p = ctx.decodeAudioData(raw, resolve, reject);
      if (p && typeof p.then === 'function') p.then(resolve, reject);
    });
    buffers.set(src, buffer);
  } catch {
    failed.add(src);
  } finally {
    pending.delete(src);
  }
}

function request(src) {
  if (!ctx || buffers.has(src) || pending.has(src) || failed.has(src)) return;
  pending.set(src, true);
  queue.push(src);
  pump();
}

/** Lädt alle hinterlegten Dateien im Hintergrund - ohne sie abzuspielen. */
function warmup() {
  warmTimer = 0;
  for (const clip of clips.values()) {
    for (const variant of clip.variants) request(variant.src);
  }
}

/* ---------------------------------------------------------------- Musik */

/** Blendet einen Titel weich auf die Ziellautstaerke. 0 haelt ihn an. */
function fadeTrack(track, target, seconds = 1.2) {
  if (!track || !track.el) return;
  clearInterval(track.fade);
  const el = track.el;
  const step = 40;
  const steps = Math.max(1, (seconds * 1000) / step);
  const delta = (target - el.volume) / steps;
  track.fade = setInterval(() => {
    const next = el.volume + delta;
    if ((delta >= 0 && next >= target) || (delta < 0 && next <= target)) {
      el.volume = Math.max(0, Math.min(1, target));
      clearInterval(track.fade);
      if (target === 0) {
        el.pause();
        el.currentTime = 0;
      }
      return;
    }
    el.volume = Math.max(0, Math.min(1, next));
  }, step);
}

function element(track) {
  if (!track.src) return null;
  if (!track.el) {
    const el = new window.Audio(track.src);
    el.loop = true;
    el.preload = 'none';
    el.volume = 0;
    track.el = el;
  }
  return track.el;
}

export const Audio = {
  events: EVENTS,

  /** Zustand der beiden Musiktitel - fuer Diagnose und Tests. */
  get musicState() {
    return {
      wants: wantsTrack,
      current: currentTrack,
      tracks: Object.entries(tracks).map(([name, t]) => ({
        name,
        src: t.src,
        laeuft: !!(t.el && !t.el.paused),
        volume: t.el ? +t.el.volume.toFixed(2) : 0,
      })),
    };
  },

  /**
   * Legt die beiden Titel fest.
   *
   * @param sources {menu, game} - Pfade, leer laesst den jeweiligen Titel weg
   */
  setTracks(sources = {}, volume) {
    if (typeof volume === 'number') musicTargetVolume = Math.max(0, Math.min(1, volume));
    for (const key of ['menu', 'game']) {
      const src = sources[key] || '';
      if (src === tracks[key].src) continue;
      if (tracks[key].el) {
        clearInterval(tracks[key].fade);
        tracks[key].el.pause();
        tracks[key].el.src = '';
        tracks[key].el = null;
      }
      tracks[key].src = src;
    }
  },

  /** Alte Form: setzt nur den Kampftitel. */
  setMusic(src, volume) {
    Audio.setTracks({ menu: tracks.menu.src, game: src }, volume);
  },

  /**
   * Wechselt den laufenden Titel. 'menu', 'game' oder '' fuer Stille.
   * Ein bereits laufender Titel wird nicht neu gestartet.
   */
  playTrack(key) {
    wantsTrack = key || '';
    if (!settings.musicOn) {
      Audio.stopMusic({ fade: false });
      return;
    }
    const laeuftSchon = currentTrack === key;

    // Alle anderen sofort stoppen. Ein Ausblenden waere huebscher, aber beim
    // Wechsel zwischen Menue und Runde liefen dann kurz beide Titel.
    for (const [name, track] of Object.entries(tracks)) {
      if (name === key || !track.el) continue;
      clearInterval(track.fade);
      track.el.pause();
      track.el.currentTime = 0;
      track.el.volume = 0;
    }
    currentTrack = key || '';
    if (!key || !tracks[key]) return;

    const track = tracks[key];
    const el = element(track);
    if (!el || !unlocked) return;          // die naechste Geste holt es nach

    if (!el.paused) {
      // Laeuft bereits - nur sicherstellen, dass er nicht leise haengt.
      if (el.volume < Audio.volume * 0.9) fadeTrack(track, Audio.volume, 0.5);
      return;
    }

    el.preload = 'auto';
    el.play()
      .then(() => fadeTrack(track, Audio.volume, laeuftSchon ? 0.4 : 1.0))
      .catch(() => { /* wartet auf die naechste Geste */ });
  },

  startMusic() {
    Audio.playTrack(wantsTrack || currentTrack || 'menu');
  },

  /**
   * Sorgt dafuer, dass der gewuenschte Titel wirklich laeuft.
   * Wird bei jedem Wellenstart und beim Fortsetzen aufgerufen.
   */
  ensureMusic() {
    if (!settings.musicOn || !wantsTrack) return;
    Audio.playTrack(wantsTrack);
  },

  stopMusic({ fade = true } = {}) {
    for (const track of Object.values(tracks)) {
      if (!track.el) continue;
      if (fade) fadeTrack(track, 0, 0.6);
      else {
        clearInterval(track.fade);
        track.el.pause();
        track.el.currentTime = 0;
        track.el.volume = 0;
      }
    }
    currentTrack = '';
  },

  duckMusic(on) {
    const track = tracks[currentTrack];
    if (!track || !track.el || !settings.musicOn) return;
    if (on) fadeTrack(track, Audio.volume * 0.3, 0.4);
    else fadeTrack(track, Audio.volume, 0.5);
  },

  /* --------------------------------------------------------- Soundeffekte */

  /** Registriert ein Ereignis mit bis zu vier Varianten. */
  registerSet(key, set) {
    if (!set || !Array.isArray(set.variants) || !set.variants.length) {
      clips.delete(key);
      return;
    }
    const clip = {
      enabled: set.enabled !== false,
      chance: typeof set.chance === 'number' ? set.chance : 100,
      volume: typeof set.volume === 'number' ? set.volume : 0.8,
      variants: set.variants.map((variant) => ({
        src: variant.src,
        volume: typeof variant.volume === 'number' ? variant.volume : 1,
      })),
    };
    clips.set(key, clip);
    // Nach dem Aufwärmen registrierte Ereignisse holen ihre Dateien sofort.
    if (ctx && !warmTimer && unlocked) for (const variant of clip.variants) request(variant.src);
  },

  /** Holt die Tondateien jetzt - nach dem Laden der Spielgrafiken. */
  warm() {
    if (!ctx || ctx.state !== 'running') return;
    clearTimeout(warmTimer);
    warmup();
  },

  register(event, src, volume = 1) {
    Audio.registerSet(event, { enabled: true, chance: 100, volume, variants: [{ src, volume: 1 }] });
  },

  /** Übernimmt Musik, Ereignisse, Waffen- und Gegnertöne aus den Spieldaten. */
  configure(content = {}) {
    const audio = content.audio || {};
    Audio.setTracks({ menu: audio.musicMenu || '', game: audio.musicTrack || '' }, audio.musicVolume);
    if (typeof audio.sfxVolume === 'number') {
      settings.sfxMaster = audio.sfxVolume;
      saveSettings();
    }
    for (const [event, set] of Object.entries(audio.sounds || {})) Audio.registerSet(event, set);
    for (const weapon of content.weapons || []) Audio.registerSet('weapon:' + weapon.id, weapon.sound);
    for (const enemy of content.enemies || []) {
      Audio.registerSet('enemy:' + enemy.id + ':hit', enemy.soundHit);
      Audio.registerSet('enemy:' + enemy.id + ':death', enemy.soundDeath);
    }
  },

  play(event, { volume = 1, rate = 1 } = {}) {
    if (!settings.sfxOn || !ctx || ctx.state !== 'running') return;
    const clip = clips.get(event);
    if (!clip || !clip.enabled) return;
    if (clip.chance < 100 && Math.random() * 100 >= clip.chance) return;

    // Zufällige Variante, damit sich Wiederholungen nicht abnutzen.
    const variant = clip.variants[(Math.random() * clip.variants.length) | 0];
    const buffer = buffers.get(variant.src);
    if (!buffer) {
      request(variant.src);        // beim nächsten Mal ist sie da
      return;
    }

    const gain = ctx.createGain();
    gain.gain.value = Math.max(0, Math.min(1,
      volume * variant.volume * clip.volume * (settings.sfxVolume ?? 1) * (settings.sfxMaster ?? 0.8)));
    const source = ctx.createBufferSource();
    source.buffer = buffer;
    source.playbackRate.value = rate;
    source.connect(gain);
    gain.connect(master);
    source.start(0);
    source.onended = () => {
      source.disconnect();
      gain.disconnect();
    };
  },

  /** Spielt den ersten hinterlegten Ton aus einer Liste von Schlüsseln. */
  playFirst(keys, options) {
    for (const key of keys) {
      if (key && clips.has(key)) {
        Audio.play(key, options);
        return;
      }
    }
  },

  /** Wiederholende Geräusche wie Schritte mit eigenem Mindestabstand. */
  playThrottled(event, minGapMs, options) {
    if (!clips.has(event)) return;
    const now = performance.now();
    const last = throttled.get(event) || 0;
    if (now - last < minGapMs) return;
    throttled.set(event, now);
    Audio.play(event, options);
  },

  /* -------------------------------------------------------- Einstellungen */

  get settings() {
    return { ...settings };
  },

  setMusicOn(on) {
    settings.musicOn = !!on;
    saveSettings();
    if (settings.musicOn) Audio.playTrack(wantsTrack || 'menu');
    else Audio.stopMusic({ fade: false });
    return settings.musicOn;
  },

  setSfxOn(on) {
    settings.sfxOn = !!on;
    saveSettings();
    return settings.sfxOn;
  },

  setMusicVolume(value) {
    settings.musicVolume = Math.max(0, Math.min(1, value));
    saveSettings();
    const track = tracks[currentTrack];
    if (track && settings.musicOn) fadeTrack(track, Audio.volume, 0.2);
  },

  setSfxVolume(value) {
    settings.sfxVolume = Math.max(0, Math.min(1, value));
    saveSettings();
  },

  /** Ein Schalter für alles - hinter dem Lautsprechersymbol im Spiel. */
  get muted() {
    return !settings.musicOn && !settings.sfxOn;
  },

  setMuted(on) {
    Audio.setMusicOn(!on);
    Audio.setSfxOn(!on);
    return Audio.muted;
  },

  /** Effektive Musiklautstärke: Admin-Grundwert mal Spielerregler. */
  get volume() {
    return Math.max(0, Math.min(1, musicTargetVolume * (settings.musicVolume ?? 1)));
  },

  get hasClips() {
    return clips.size > 0;
  },

  get playing() {
    const track = tracks[currentTrack];
    return !!(track && track.el && !track.el.paused);
  },

  /** Welcher Titel gerade laeuft: 'menu', 'game' oder ''. */
  get track() {
    return currentTrack;
  },

  get ready() {
    return !!ctx && ctx.state === 'running';
  },

  get element() {
    const track = tracks[currentTrack];
    return track ? track.el : null;
  },

  /** Nur für Diagnose und Tests. */
  describe(key) {
    const clip = clips.get(key);
    if (!clip) return null;
    return {
      enabled: clip.enabled,
      chance: clip.chance,
      volume: clip.volume,
      variants: clip.variants.map((v) => v.src.split('/').pop()),
      geladen: clip.variants.filter((v) => buffers.has(v.src)).length,
    };
  },

  get stats() {
    return { ereignisse: clips.size, geladen: buffers.size, fehlend: failed.size, wartend: queue.length };
  },
};
