/**
 * Audio: Hintergrundmusik plus vorbereitete Soundeffekte.
 *
 * Browser blockieren Wiedergabe ohne Nutzergeste. Deshalb merkt sich das
 * Modul einen Startwunsch und löst ihn bei der ersten Berührung oder
 * Taste ein. Lautstärke und An/Aus liegen im localStorage.
 */
const EVENTS = [
  'shoot', 'melee', 'hit', 'crit', 'enemyDeath', 'explosion',
  'upgrade', 'bossSpawn', 'bossWarning', 'playerHit', 'coin', 'gameOver', 'uiClick',
];

const STORAGE_KEY = 'arena.audio';
const clips = new Map();
const throttled = new Map();

let music = null;
let musicSrc = '';
let musicTargetVolume = 0.45;
let fadeTimer = 0;
let wantsPlay = false;
let unlocked = false;

const settings = loadSettings();

function loadSettings() {
  // musicVolume ist der Regler des Spielers (0-1) und multipliziert die
  // im Admin gesetzte Grundlautstärke.
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

/** Erste Nutzergeste schaltet die Wiedergabe frei. */
function unlock() {
  unlocked = true;
  if (wantsPlay) Audio.startMusic();
}
['pointerdown', 'keydown', 'touchstart'].forEach((type) =>
  window.addEventListener(type, unlock, { once: true, passive: true }));

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

  /* --------------------------------------------------------------- Musik */

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
    if (!unlocked) {
      wantsPlay = true;              // wird bei der ersten Geste nachgeholt
      return;
    }
    wantsPlay = false;
    if (!music) {
      music = new window.Audio(musicSrc);
      music.loop = true;
      music.preload = 'auto';
      music.volume = 0;
    }
    const target = Audio.volume;
    music.play()
      .then(() => fadeTo(Math.min(1, target), 1.4))
      .catch(() => { wantsPlay = true; });
  },

  stopMusic({ fade = true } = {}) {
    if (!music) return;
    if (fade) fadeTo(0, 0.8);
    else {
      music.pause();
      music.currentTime = 0;
    }
  },

  duckMusic(on) {
    // Während Pause und Upgrade-Auswahl leiser, damit die UI im Vordergrund steht.
    if (!music || !settings.musicOn) return;
    fadeTo(on ? Audio.volume * 0.3 : Audio.volume, 0.4);
  },

  /* --------------------------------------------------------- Soundeffekte */

  register(event, src, volume = 1) {
    if (!EVENTS.includes(event)) EVENTS.push(event);
    if (!src) {
      clips.delete(event);
      return;
    }
    const audio = new window.Audio(src);
    audio.preload = 'none';        // erst laden, wenn der Ton wirklich gebraucht wird
    clips.set(event, { audio, volume });
  },

  /**
   * Übernimmt die komplette Tonkonfiguration aus dem Admin:
   * Musiktitel, Lautstärken und je Ereignis eine Datei.
   */
  configure(config = {}) {
    if (config.musicTrack) Audio.setMusic(config.musicTrack, config.musicVolume);
    if (typeof config.sfxVolume === 'number') settings.sfxMaster = config.sfxVolume;
    const sounds = config.sounds || {};
    for (const [event, entry] of Object.entries(sounds)) {
      Audio.register(event, entry && entry.src, entry && typeof entry.volume === 'number' ? entry.volume : 1);
    }
  },

  play(event, { volume = 1, rate = 1 } = {}) {
    if (!settings.sfxOn || !unlocked) return;
    const clip = clips.get(event);
    if (!clip) return;             // Kein Sound hinterlegt - bewusst still.
    const node = clip.audio.cloneNode();
    node.volume = Math.max(0, Math.min(1,
      volume * clip.volume * (settings.sfxVolume ?? 1) * (settings.sfxMaster ?? 0.8)));
    node.playbackRate = rate;
    node.play().catch(() => {});
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

  /** Nur für Diagnose und Tests. */
  get element() {
    return music;
  },
};
