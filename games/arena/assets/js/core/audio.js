/**
 * Zentrale Audioschicht. Aktuell liegen keine Sounddateien im Projekt,
 * deshalb sind alle Events registriert, aber stumm. Sobald Dateien
 * vorhanden sind, genuegt Audio.register('shoot', 'assets/audio/shoot.wav').
 */
const EVENTS = [
  'shoot', 'melee', 'hit', 'crit', 'enemyDeath', 'explosion',
  'upgrade', 'bossSpawn', 'bossWarning', 'playerHit', 'coin', 'gameOver', 'uiClick',
];

const clips = new Map();
let enabled = true;
let volume = 0.7;

export const Audio = {
  events: EVENTS,

  register(event, src) {
    if (!EVENTS.includes(event)) EVENTS.push(event);
    const audio = new window.Audio(src);
    audio.preload = 'auto';
    clips.set(event, audio);
  },

  play(event, { volume: v = 1, rate = 1 } = {}) {
    if (!enabled) return;
    const clip = clips.get(event);
    if (!clip) return; // Kein Sound hinterlegt - bewusst still.
    const node = clip.cloneNode();
    node.volume = Math.max(0, Math.min(1, v * volume));
    node.playbackRate = rate;
    node.play().catch(() => {});
  },

  setEnabled(on) {
    enabled = !!on;
  },

  setVolume(v) {
    volume = Math.max(0, Math.min(1, v));
  },

  get hasClips() {
    return clips.size > 0;
  },
};
