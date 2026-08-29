// Zweisprachigkeit ohne Bibliothek.
//
// Im HTML stehen data-i18n="schlüssel" (Textinhalt) und
// data-i18n-attr="attribut:schlüssel, attribut:schlüssel" (Attribute).
// applyTranslations() setzt beides. Die Wörterbücher müssen identische
// Schlüsselmengen haben – das prüft test/i18n.test.mjs, dieselbe Regel wie im
// Portal für messages/de.json und messages/en.json.

import { readString, writeString } from './engine/storage.js';

const LANG_KEY = 'mogli.lang';

export const DICT = {
  de: {
    'app.title': 'Mogli',
    'app.subtitle': 'Tempelflucht',
    'app.tagline': 'Ein Knopf. Immer nach oben.',

    'menu.start': 'Los geht’s',
    'menu.story': 'Geschichte ansehen',
    'menu.nameLabel': 'Dein Name für die Bestenliste',
    'menu.namePlaceholder': 'z. B. REY',
    'menu.leaderboardTitle': 'Bestenliste',
    'menu.langLabel': 'Sprache',

    'hud.height': 'Höhe',
    'hud.best': 'Rekord',
    'hud.emeralds': 'Smaragde',
    'hud.metres': 'm',

    'story.skip': 'Überspringen',
    'story.next': 'Weiter',
    'story.play': 'Los!',
    'story.name.kiki': 'Kiki',
    'story.name.bimbo': 'Bimbo',
    'story.name.schnuff': 'Schnuff',
    'story.1':
      'Mogli! MOGLI! Aufwachen! Der Tempel ist eingestürzt. Also, ein bisschen. Also: komplett.',
    'story.2': 'Und ganz unten kocht jetzt was Oranges. Frag lieber nicht.',
    'story.3': 'Ich hab an EINEM Hebel geschnuppert. An einem einzigen!',
    'story.4':
      'Egal! Oben ist raus. Und unterwegs liegen Smaragde. Die glitzern. Ich mag glitzern.',
    'story.5': 'Renn einfach. Stehenbleiben kannst du sowieso nicht.',
    'story.6':
      'Tippen springt. An der Wand nochmal tippen, dann geht’s andersrum weiter. Und schnapp dir die Ranken – die reissen dich hoch. Los jetzt!',

    'pause.title': 'Pause',
    'pause.resume': 'Weiter',
    'pause.restart': 'Neu starten',
    'pause.menu': 'Zum Menü',

    'over.title': 'Erwischt',
    'over.height': 'Höhe',
    'over.emeralds': 'Smaragde',
    'over.score': 'Punkte',
    'over.newBest': 'Neuer persönlicher Rekord!',
    'over.retry': 'Nochmal',
    'over.menu': 'Zum Menü',
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

    'error.network': 'Server nicht erreichbar',
    'error.rate_limited': 'Zu viele Einträge in kurzer Zeit',
    'error.invalid_payload': 'Ungültige Daten',
    'error.store_unavailable': 'Server kann nicht speichern',
    'error.unknown': 'Unbekannter Fehler',

    'misc.muteOn': 'Ton aus',
    'misc.muteOff': 'Ton an',
    'misc.fullscreen': 'Vollbild',
    'misc.fullscreenExit': 'Vollbild beenden',
    'misc.version': 'Version',
    'misc.tapToStart': 'Tippen und los',
    'misc.tapToJump': 'Tippen zum Springen',
  },

  en: {
    'app.title': 'Mowgli',
    'app.subtitle': 'Temple Escape',
    'app.tagline': 'One button. Always upward.',

    'menu.start': 'Start',
    'menu.story': 'Watch the story',
    'menu.nameLabel': 'Your name for the leaderboard',
    'menu.namePlaceholder': 'e.g. REY',
    'menu.leaderboardTitle': 'Leaderboard',
    'menu.langLabel': 'Language',

    'hud.height': 'Height',
    'hud.best': 'Best',
    'hud.emeralds': 'Emeralds',
    'hud.metres': 'm',

    'story.skip': 'Skip',
    'story.next': 'Next',
    'story.play': 'Go!',
    'story.name.kiki': 'Kiki',
    'story.name.bimbo': 'Bimbo',
    'story.name.schnuff': 'Schnuff',
    'story.1': 'Mowgli! MOWGLI! Wake up! The temple collapsed. Well, a bit. Well: completely.',
    'story.2': 'And something orange is bubbling at the bottom now. Better not ask.',
    'story.3': 'I sniffed ONE lever. One single lever!',
    'story.4':
      'Never mind! The way out is up. And there are emeralds. They sparkle. I like sparkle.',
    'story.5': 'Just run. You cannot stop anyway.',
    'story.6':
      'Tap to jump. Tap again at a wall and you carry on the other way. And grab the vines — they yank you upward. Go!',

    'pause.title': 'Paused',
    'pause.resume': 'Resume',
    'pause.restart': 'Restart',
    'pause.menu': 'Main menu',

    'over.title': 'Caught',
    'over.height': 'Height',
    'over.emeralds': 'Emeralds',
    'over.score': 'Score',
    'over.newBest': 'New personal best!',
    'over.retry': 'Try again',
    'over.menu': 'Main menu',
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

    'error.network': 'Server unreachable',
    'error.rate_limited': 'Too many submissions in a short time',
    'error.invalid_payload': 'Invalid data',
    'error.store_unavailable': 'Server cannot store scores',
    'error.unknown': 'Unknown error',

    'misc.muteOn': 'Sound off',
    'misc.muteOff': 'Sound on',
    'misc.fullscreen': 'Fullscreen',
    'misc.fullscreenExit': 'Leave fullscreen',
    'misc.version': 'Version',
    'misc.tapToStart': 'Tap to go',
    'misc.tapToJump': 'Tap to jump',
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
