/**
 * Audio: Hintergrundmusik plus vorbereitete Soundeffekte.
 *
 * Browser blockieren Wiedergabe ohne Nutzergeste. Deshalb merkt sich das
 * Modul einen Startwunsch und loest ihn bei der ersten Beruehrung oder
 * Taste ein. Lautstaerke und An/Aus liegen im localStorage.
 */
const EVENTS = [
  'shoot', 'melee', 'hit', 'crit', 'enemyDeath', 'explosion',
  'upgrade', 'bossSpawn', 'bossWarning', 'playerHit', 'coin', 'gameOver', 'uiClick',
];

const STORAGE_KEY = 'arena.audio';
const clips = new Map();

let music = null;
let musicSrc = '';
let musicTargetVolume = 0.45;
let fadeTimer = 0;
let wantsPlay = false;
let unlocked = false;

const settings = loadSettings();

function loadSettings() {
  // musicVolume ist der Regler des Spielers (0-1) und multipliziert die
  // im Admin gesetzte Grundlautstaerke.
  const fallback = { musicOn: true, sfxOn: true, musicVolume: 1, sfxVolume: 1 };
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
    // Waehrend Pause und Upgrade-Auswahl leiser, damit die UI im Vordergrund steht.
    if (!music || !settings.musicOn) return;
    fadeTo(on ? Audio.volume * 0.3 : Audio.volume, 0.4);
  },

  /* --------------------------------------------------------- Soundeffekte */

  register(event, src) {
    if (!EVENTS.includes(event)) EVENTS.push(event);
    const audio = new window.Audio(src);
    audio.preload = 'auto';
    clips.set(event, audio);
  },

  play(event, { volume = 1, rate = 1 } = {}) {
    if (!settings.sfxOn) return;
    const clip = clips.get(event);
    if (!clip) return;             // Kein Sound hinterlegt - bewusst still.
    const node = clip.cloneNode();
    node.volume = Math.max(0, Math.min(1, volume * (settings.sfxVolume ?? 0.8)));
    node.playbackRate = rate;
    node.play().catch(() => {});
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

  /** Effektive Musiklautstaerke: Admin-Grundwert mal Spielerregler. */
  get volume() {
    return Math.max(0, Math.min(1, musicTargetVolume * (settings.musicVolume ?? 1)));
  },

  get hasClips() {
    return clips.size > 0;
  },

  get playing() {
    return !!music && !music.paused;
  },

  /** Nur fuer Diagnose und Tests. */
  get element() {
    return music;
  },
};
