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
let music = null;
let musicSrc = '';
let musicTargetVolume = 0.45;
let fadeTimer = 0;
let wantsMusic = false;
let unlocked = false;
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
    if (wantsMusic) Audio.startMusic();
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

function fadeTo(target, seconds = 1.2) {
  if (!music) return;
  clearInterval(fadeTimer);
  const step = 40;
  const steps = Math.max(1, (seconds * 1000) / step);
  const delta = (target - music.volume) / steps;
  fadeTimer = setInterval(() => {
    if (!music) return clearInterval(fadeTimer);
    const next = music.volume + delta;
    if ((delta > 0 && next >= target) || (delta < 0 && next <= target)) {
      music.volume = Math.max(0, Math.min(1, target));
      clearInterval(fadeTimer);
      if (target === 0) music.pause();
      return;
    }
    music.volume = Math.max(0, Math.min(1, next));
  }, step);
}

export const Audio = {
  events: EVENTS,

  /** Legt den Titel fest. Geladen wird erst beim ersten Abspielen. */
  setMusic(src, volume) {
    if (typeof volume === 'number') musicTargetVolume = Math.max(0, Math.min(1, volume));
    if (!src || src === musicSrc) return;
    musicSrc = src;
    if (music) {
      music.pause();
      music.src = '';
      music = null;
    }
  },

  startMusic() {
    if (!settings.musicOn || !musicSrc) return;
    wantsMusic = true;
    if (!music) {
      music = new window.Audio(musicSrc);
      music.loop = true;
      music.preload = 'auto';
      music.volume = 0;
      // Läuft der Titel doch einmal aus, sofort wieder anwerfen.
      music.addEventListener('ended', () => {
        if (settings.musicOn) music.play().catch(() => {});
      });
    }
    if (!music.paused) return;
    music.play()
      .then(() => fadeTo(Math.min(1, Audio.volume), 1.2))
      .catch(() => { /* wartet auf die nächste Geste */ });
  },

  /**
   * Sorgt dafür, dass die Musik im Spiel wirklich läuft.
   *
   * Wird bei jedem Wellenstart und beim Fortsetzen aufgerufen. Ohne das
   * blieb die Musik nach einer abgewiesenen Wiedergabe stumm und ging erst
   * irgendwann später von selbst wieder an.
   */
  ensureMusic() {
    if (!settings.musicOn || !musicSrc) return;
    if (!music || music.paused) {
      Audio.startMusic();
      return;
    }
    // Nach dem Abblenden auf 30 % wieder auf volle Lautstärke.
    if (music.volume < Audio.volume * 0.9) fadeTo(Audio.volume, 0.5);
  },

  stopMusic({ fade = true } = {}) {
    wantsMusic = false;
    if (!music) return;
    if (fade) fadeTo(0, 0.8);
    else {
      music.pause();
      music.currentTime = 0;
    }
  },

  duckMusic(on) {
    // Während Pause und Upgrade-Auswahl leiser, damit die UI vorne steht.
    if (!music || !settings.musicOn) return;
    if (on) fadeTo(Audio.volume * 0.3, 0.4);
    else Audio.ensureMusic();
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
    if (audio.musicTrack) Audio.setMusic(audio.musicTrack, audio.musicVolume);
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
    if (settings.musicOn) Audio.startMusic();
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
    if (music && settings.musicOn) fadeTo(Audio.volume, 0.2);
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
    return !!music && !music.paused;
  },

  get ready() {
    return !!ctx && ctx.state === 'running';
  },

  get element() {
    return music;
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
