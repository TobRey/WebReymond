// Zweisprachigkeit ohne Bibliothek.
//
// Im HTML stehen data-i18n="schlüssel" (Textinhalt) und
// data-i18n-attr="attribut:schlüssel, attribut:schlüssel" (Attribute).
// applyTranslations() setzt beides. Die Wörterbücher müssen identische
// Schlüsselmengen haben – das prüft test/i18n.test.mjs, dieselbe Regel wie im
// Portal für messages/de.json und messages/en.json.

import { readString, writeString } from './engine/storage.js';

const LANG_KEY = 'heavenclimb.lang';

export const DICT = {
  de: {
    'app.title': 'HeavenClimb',
    'app.tagline': 'Klettere so hoch du kannst.',

    'menu.start': 'Spiel starten',
    'menu.nameLabel': 'Dein Name für die Bestenliste',
    'menu.namePlaceholder': 'z. B. REY',
    'menu.controlsTitle': 'Steuerung',
    'menu.leaderboardTitle': 'Bestenliste',
    'menu.langLabel': 'Sprache',

    'controls.move': 'Laufen',
    'controls.moveKeys': 'Pfeiltasten oder A / D',
    'controls.jump': 'Springen',
    'controls.jumpKeys': 'Leertaste, W oder Pfeil hoch – gedrückt halten springt höher',
    'controls.double': 'Doppelsprung',
    'controls.doubleKeys': 'In der Luft noch einmal springen',
    'controls.wall': 'Wandsprung',
    'controls.wallKeys': 'An einer Wand rutschen, dann springen – lädt den Doppelsprung neu auf',
    'controls.drop': 'Durchfallen',
    'controls.dropKeys': 'Pfeil runter oder S auf einer dünnen Plattform',
    'controls.pause': 'Pause',
    'controls.pauseKeys': 'Esc oder P',
    'controls.restart': 'Neu starten',
    'controls.restartKeys': 'R',
    'controls.mute': 'Ton aus',
    'controls.muteKeys': 'M',
    'controls.touch': 'Auf dem Handy: die Flächen unten links und unten rechts.',
    'controls.pad': 'Gamepad wird unterstützt.',

    'hud.height': 'Höhe',
    'hud.best': 'Rekord',
    'hud.gems': 'Edelsteine',
    'hud.metres': 'm',

    'pause.title': 'Pause',
    'pause.resume': 'Weiter',
    'pause.restart': 'Neu starten',
    'pause.menu': 'Zum Menü',

    'over.title': 'Vorbei',
    'over.height': 'Höhe',
    'over.gems': 'Edelsteine',
    'over.score': 'Punkte',
    'over.newBest': 'Neuer persönlicher Rekord!',
    'over.retry': 'Nochmal',
    'over.menu': 'Zum Menü',
    'over.submit': 'In die Bestenliste eintragen',
    'over.submitting': 'Wird gesendet …',
    'over.submitted': 'Eingetragen – Platz {rank}',
    'over.submitError': 'Eintragen hat nicht geklappt: {reason}',
    'over.rankNone': 'Reicht noch nicht für die Bestenliste.',

    'board.worldwide': 'Weltweit',
    'board.local': 'Nur auf diesem Gerät',
    'board.localHint':
      'Für die weltweite Liste braucht der Webspace PHP und Schreibrechte. Siehe LIESMICH.txt.',
    'board.loading': 'Wird geladen …',
    'board.empty': 'Noch keine Einträge. Sei die erste Person.',
    'board.forgeable':
      'Punkte werden im Browser berechnet und lassen sich fälschen. Nimm die Liste sportlich.',
    'board.reload': 'Neu laden',

    'error.network': 'Server nicht erreichbar',
    'error.rate_limited': 'Zu viele Einträge in kurzer Zeit',
    'error.invalid_payload': 'Ungültige Daten',
    'error.store_unavailable': 'Server kann nicht speichern',
    'error.unknown': 'Unbekannter Fehler',

    'misc.muteOn': 'Ton aus',
    'misc.muteOff': 'Ton an',
    'misc.version': 'Version',
  },

  en: {
    'app.title': 'HeavenClimb',
    'app.tagline': 'Climb as high as you can.',

    'menu.start': 'Start game',
    'menu.nameLabel': 'Your name for the leaderboard',
    'menu.namePlaceholder': 'e.g. REY',
    'menu.controlsTitle': 'Controls',
    'menu.leaderboardTitle': 'Leaderboard',
    'menu.langLabel': 'Language',

    'controls.move': 'Move',
    'controls.moveKeys': 'Arrow keys or A / D',
    'controls.jump': 'Jump',
    'controls.jumpKeys': 'Space, W or Up — hold to jump higher',
    'controls.double': 'Double jump',
    'controls.doubleKeys': 'Press jump again in mid-air',
    'controls.wall': 'Wall jump',
    'controls.wallKeys': 'Slide down a wall, then jump — this refills the double jump',
    'controls.drop': 'Drop through',
    'controls.dropKeys': 'Down or S while on a thin platform',
    'controls.pause': 'Pause',
    'controls.pauseKeys': 'Esc or P',
    'controls.restart': 'Restart',
    'controls.restartKeys': 'R',
    'controls.mute': 'Mute',
    'controls.muteKeys': 'M',
    'controls.touch': 'On a phone: the areas at the bottom left and bottom right.',
    'controls.pad': 'Gamepads are supported.',

    'hud.height': 'Height',
    'hud.best': 'Best',
    'hud.gems': 'Gems',
    'hud.metres': 'm',

    'pause.title': 'Paused',
    'pause.resume': 'Resume',
    'pause.restart': 'Restart',
    'pause.menu': 'Main menu',

    'over.title': 'Game over',
    'over.height': 'Height',
    'over.gems': 'Gems',
    'over.score': 'Score',
    'over.newBest': 'New personal best!',
    'over.retry': 'Try again',
    'over.menu': 'Main menu',
    'over.submit': 'Submit to leaderboard',
    'over.submitting': 'Sending …',
    'over.submitted': 'Submitted — rank {rank}',
    'over.submitError': 'Could not submit: {reason}',
    'over.rankNone': 'Not enough for the leaderboard yet.',

    'board.worldwide': 'Worldwide',
    'board.local': 'This device only',
    'board.localHint':
      'The worldwide list needs PHP and write permission on the webspace. See LIESMICH.txt.',
    'board.loading': 'Loading …',
    'board.empty': 'No entries yet. Be the first.',
    'board.forgeable':
      'Scores are computed in the browser and can be forged. Take the list lightly.',
    'board.reload': 'Reload',

    'error.network': 'Server unreachable',
    'error.rate_limited': 'Too many submissions in a short time',
    'error.invalid_payload': 'Invalid data',
    'error.store_unavailable': 'Server cannot store scores',
    'error.unknown': 'Unknown error',

    'misc.muteOn': 'Sound off',
    'misc.muteOff': 'Sound on',
    'misc.version': 'Version',
  },
};

export const LOCALES = Object.keys(DICT);
export const DEFAULT_LOCALE = 'de';

let current = DEFAULT_LOCALE;
const listeners = new Set();

/**
 * Deutsch ist die Voreinstellung – wie im Portal, wo `/` auf `/de` umleitet.
 * Nur eine früher getroffene Wahl setzt sich darüber hinweg; die
 * Browsersprache wird bewusst nicht ausgewertet, sonst bekäme ein Grossteil
 * der Besucher entgegen der Festlegung Englisch zu sehen.
 */
export function initialLang() {
  const stored = readString(LANG_KEY, null);
  if (stored !== null && LOCALES.includes(stored)) return stored;
  return DEFAULT_LOCALE;
}

export function getLang() {
  return current;
}

export function setLang(lang, root) {
  if (!LOCALES.includes(lang)) return;
  current = lang;
  writeString(LANG_KEY, lang);
  if (typeof document !== 'undefined') {
    document.documentElement.lang = lang;
    applyTranslations(root ?? document);
  }
  for (const fn of listeners) fn(lang);
}

export function onLangChange(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

/** Übersetzt einen Schlüssel; {platzhalter} werden aus `vars` ersetzt. */
export function t(key, vars) {
  const table = DICT[current] ?? DICT[DEFAULT_LOCALE];
  let text = table[key];
  if (text === undefined) text = DICT[DEFAULT_LOCALE][key];
  if (text === undefined) return key;
  if (vars === undefined) return text;
  return text.replace(/\{(\w+)\}/g, (match, name) =>
    Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : match,
  );
}

export function applyTranslations(root) {
  for (const element of root.querySelectorAll('[data-i18n]')) {
    element.textContent = t(element.getAttribute('data-i18n'));
  }
  for (const element of root.querySelectorAll('[data-i18n-attr]')) {
    for (const pair of element.getAttribute('data-i18n-attr').split(',')) {
      const [attr, key] = pair.split(':').map((part) => part.trim());
      if (attr && key) element.setAttribute(attr, t(key));
    }
  }
}
