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
    'app.subtitle': 'Dschungellauf',
    'app.tagline': 'Renn. Spring. Erreich die Flagge.',

    'menu.levels': 'Levels',
    'menu.locked': 'Gesperrt – schaff erst das Level davor',
    'menu.best': 'Bestzeit',
    'menu.noTime': 'noch nicht geschafft',
    'menu.story': 'Geschichte ansehen',
    'menu.nameLabel': 'Dein Name für die Bestenliste',
    'menu.namePlaceholder': 'z. B. REY',
    'menu.langLabel': 'Sprache',
    'menu.editorHint': 'Eigene Levels baust du unter /admin im Reiter „Karten“.',

    'hud.time': 'Zeit',
    'hud.emeralds': 'Smaragde',

    'story.skip': 'Überspringen',
    'story.next': 'Weiter',
    'story.play': 'Los!',
    'story.name.kiki': 'Kiki',
    'story.name.bimbo': 'Bimbo',
    'story.name.schnuff': 'Schnuff',
    'story.1':
      'Mogli! MOGLI! Aufwachen! Der Dschungel hat dich ausgespuckt – und dein Zuhause liegt ganz am anderen Ende.',
    'story.2': 'Zwischen dir und dort: Gruben, Stacheln und Viecher mit schlechter Laune.',
    'story.3': 'Ich hab die Karte gefressen. Sie roch nach Banane. Tut mir leid.',
    'story.4':
      'Egal! Immer nach rechts, da ist die Flagge. Smaragde funkeln unterwegs – jeder schenkt dir eine Sekunde.',
    'story.5': 'Lauf mit ◀ und ▶, spring mit dem grossen Knopf. Länger drücken springt höher.',
    'story.6':
      'Auf platte Gegner kannst du springen, Federn schleudern dich hoch, und die Uhr läuft immer. Los jetzt!',

    'pause.title': 'Pause',
    'pause.resume': 'Weiter',
    'pause.restart': 'Neu starten',
    'pause.menu': 'Zum Menü',

    'over.title': 'Geschafft!',
    'over.time': 'Zeit',
    'over.emeralds': 'Smaragde',
    'over.deaths': 'Stürze',
    'over.newBest': 'Neue Bestzeit!',
    'over.retry': 'Nochmal',
    'over.next': 'Nächstes Level',
    'over.menu': 'Zum Menü',
    'over.submitting': 'Wird gesendet …',
    'over.submitted': 'Eingetragen – Platz {rank}',
    'over.submitError': 'Eintragen hat nicht geklappt: {reason}',

    'board.worldwide': 'Weltweit',
    'board.local': 'Nur auf diesem Gerät',
    'board.localHint':
      'Für die weltweite Liste braucht der Webspace PHP und Schreibrechte. Siehe LIESMICH.txt.',
    'board.loading': 'Wird geladen …',
    'board.empty': 'Noch keine Zeiten. Sei die erste Person.',
    'board.forgeable':
      'Zeiten werden im Browser gemessen und lassen sich fälschen. Nimm die Liste sportlich.',

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
    'misc.tapToStart': 'Lauf los – die Uhr startet mit dir',
    'pad.left': 'Nach links',
    'pad.right': 'Nach rechts',
    'pad.jump': 'Springen',
  },

  en: {
    'app.title': 'Mowgli',
    'app.subtitle': 'Jungle Run',
    'app.tagline': 'Run. Jump. Reach the flag.',

    'menu.levels': 'Levels',
    'menu.locked': 'Locked – beat the level before it first',
    'menu.best': 'Best time',
    'menu.noTime': 'not finished yet',
    'menu.story': 'Watch the story',
    'menu.nameLabel': 'Your name for the leaderboard',
    'menu.namePlaceholder': 'e.g. REY',
    'menu.langLabel': 'Language',
    'menu.editorHint': 'Build your own levels at /admin in the “Karten” tab.',

    'hud.time': 'Time',
    'hud.emeralds': 'Emeralds',

    'story.skip': 'Skip',
    'story.next': 'Next',
    'story.play': 'Go!',
    'story.name.kiki': 'Kiki',
    'story.name.bimbo': 'Bimbo',
    'story.name.schnuff': 'Schnuff',
    'story.1':
      'Mowgli! MOWGLI! Wake up! The jungle spat you out — and home is at the far other end.',
    'story.2': 'Between you and there: pits, spikes, and critters in a bad mood.',
    'story.3': 'I ate the map. It smelled of banana. Sorry.',
    'story.4':
      'Never mind! Keep heading right, that is where the flag is. Emeralds sparkle on the way — each one gifts you a second.',
    'story.5': 'Run with ◀ and ▶, jump with the big button. Hold longer to jump higher.',
    'story.6':
      'You can bounce on squishy enemies, springs launch you high, and the clock never stops. Now go!',

    'pause.title': 'Paused',
    'pause.resume': 'Resume',
    'pause.restart': 'Restart',
    'pause.menu': 'Main menu',

    'over.title': 'Finished!',
    'over.time': 'Time',
    'over.emeralds': 'Emeralds',
    'over.deaths': 'Falls',
    'over.newBest': 'New best time!',
    'over.retry': 'Try again',
    'over.next': 'Next level',
    'over.menu': 'Main menu',
    'over.submitting': 'Sending …',
    'over.submitted': 'Submitted — rank {rank}',
    'over.submitError': 'Could not submit: {reason}',

    'board.worldwide': 'Worldwide',
    'board.local': 'This device only',
    'board.localHint':
      'The worldwide list needs PHP and write permission on the webspace. See LIESMICH.txt.',
    'board.loading': 'Loading …',
    'board.empty': 'No times yet. Be the first.',
    'board.forgeable':
      'Times are measured in the browser and can be forged. Take the list lightly.',

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
    'misc.tapToStart': 'Start running — the clock starts with you',
    'pad.left': 'Left',
    'pad.right': 'Right',
    'pad.jump': 'Jump',
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
