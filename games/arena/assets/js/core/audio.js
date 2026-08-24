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

// Neuer Schluessel: Die alte Fassung hat mit dem Musikknopf auch die
// Effekte abgeschaltet und das gespeichert. Wer davon betroffen war, hatte
// dauerhaft keinen Ton mehr. Mit einem neuen Schluessel starten alle wieder
// mit Ton, und Musik und Effekte sind ab jetzt getrennt.
const STORAGE_KEY = 'arena.audio.v2';
const clips = new Map();
const throttled = new Map();
/** Wie viele fertige Abspieler je Tondatei bereitstehen. */
const NODES_PER_VARIANT = 3;

/** Nimmt einen freien Abspieler, sonst den aeltesten im Ringpuffer. */
function pickNode(variant) {
  for (const node of variant.nodes) {
    if (node.paused || node.ended) return node;
  }
  const node = variant.nodes[variant.next % variant.nodes.length];
  variant.next++;
  return node;
}

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
  // musicOn und sfxOn sind zwei unabhaengige Schalter.
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

/**
 * Erste Nutzergeste schaltet die Wiedergabe frei.
 *
 * Auf Mobilgeraeten reicht das allein nicht: Ein Ton, der spaeter ausserhalb
 * einer Geste zum ersten Mal geladen wird, bleibt dort stumm. Deshalb werden
 * hier alle hinterlegten Dateien einmal lautlos angespielt und sofort wieder
 * angehalten - danach duerfen sie jederzeit klingen.
 */
function unlock() {
  if (unlocked) return;
  unlocked = true;
  primeAll();
  if (wantsPlay) Audio.startMusic();
}

function prime(clip) {
  for (const variant of clip.variants) {
    for (const audio of variant.nodes) primeNode(audio);
  }
}

function primeNode(audio) {
  if (audio.__primed) return;
  audio.__primed = true;
  audio.preload = 'auto';
  audio.muted = true;
  const done = () => {
    audio.pause();
    audio.currentTime = 0;
    audio.muted = false;
  };
  const p = audio.play();
  if (p && typeof p.then === 'function') p.then(done).catch(() => { audio.muted = false; });
  else done();
}

function primeAll() {
  for (const clip of clips.values()) prime(clip);
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

  /**
   * Registriert einen Ton mit bis zu vier Varianten.
   * Beim Abspielen wird zufällig eine davon gewählt.
   */
  registerSet(key, set) {
    if (!set || !Array.isArray(set.variants) || !set.variants.length) {
      clips.delete(key);
      return;
    }
    const clip = {
      enabled: set.enabled !== false,
      chance: typeof set.chance === 'number' ? set.chance : 100,
      volume: typeof set.volume === 'number' ? set.volume : 0.8,
      // Je Variante mehrere fertige Abspieler. Auf Mobilgeraeten darf ein
      // frisch erzeugtes Element ausserhalb einer Nutzergeste nicht tönen -
      // vorbereitete duerfen es. Drei reichen, damit sich schnelle
      // Wiederholungen nicht gegenseitig abschneiden.
      variants: set.variants.map((variant) => {
        const nodes = [];
        for (let i = 0; i < NODES_PER_VARIANT; i++) {
          const audio = new window.Audio(variant.src);
          audio.preload = 'auto';
          nodes.push(audio);
        }
        return { nodes, src: variant.src, volume: typeof variant.volume === 'number' ? variant.volume : 1, next: 0 };
      }),
    };
    clips.set(key, clip);
    if (unlocked) prime(clip);
  },

  /** Einzelne Datei registrieren - kurze Form für eigene Erweiterungen. */
  register(event, src, volume = 1) {
    Audio.registerSet(event, { enabled: true, chance: 100, volume, variants: [{ src, volume: 1 }] });
  },

  /**
   * Übernimmt die komplette Tonkonfiguration: Musik, Ereignisse,
   * Waffen- und Gegnertöne.
   */
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
    if (!settings.sfxOn || !unlocked) return;
    const clip = clips.get(event);
    if (!clip || !clip.enabled) return;      // Kein Ton hinterlegt oder abgeschaltet.
    if (clip.chance < 100 && Math.random() * 100 >= clip.chance) return;

    // Zufällige Variante, damit sich Wiederholungen nicht abnutzen.
    const variant = clip.variants[(Math.random() * clip.variants.length) | 0];
    const node = pickNode(variant);
    node.volume = Math.max(0, Math.min(1,
      volume * variant.volume * clip.volume * (settings.sfxVolume ?? 1) * (settings.sfxMaster ?? 0.8)));
    node.playbackRate = rate;
    try {
      node.currentTime = 0;
    } catch { /* noch nicht geladen - dann spielt er ab Anfang */ }
    const p = node.play();
    if (p && typeof p.catch === 'function') p.catch(() => {});
  },

  /**
   * Spielt den ersten vorhandenen Ton aus einer Liste von Schlüsseln.
   * So kann eine Waffe einen eigenen Ton haben und sonst auf den
   * allgemeinen Schuss- oder Schlagton zurückfallen.
   */
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

  /** Nur für Diagnose und Tests. */
  get element() {
    return music;
  },

  /** Zeigt, was für einen Schlüssel hinterlegt ist. */
  describe(key) {
    const clip = clips.get(key);
    if (!clip) return null;
    return {
      enabled: clip.enabled,
      chance: clip.chance,
      volume: clip.volume,
      variants: clip.variants.map((v) => v.src.split('/').pop()),
    };
  },
};
