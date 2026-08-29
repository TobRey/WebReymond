// Die Vorgeschichte in sechs Tafeln.
//
// Sie hat zwei Aufgaben, und die zweite ist die wichtigere: Bei einem Spiel
// mit genau einem Knopf muss irgendwo stehen, was dieser Knopf tut. Statt
// eines Hilfebildschirms, den niemand liest, erklären es die drei Wesen
// nebenbei im letzten Absatz. Wer sie überspringt, findet dieselben Sätze
// unter "Steuerung" im Menü.
//
// Die Texte selbst stehen in i18n.js, hier nur der Ablauf – sonst gäbe es sie
// zweimal, einmal je Sprache.

export const STORY = [
  { who: 'kiki', text: 'story.1' },
  { who: 'bimbo', text: 'story.2' },
  { who: 'schnuff', text: 'story.3' },
  { who: 'kiki', text: 'story.4' },
  { who: 'bimbo', text: 'story.5' },
  { who: 'kiki', text: 'story.6' },
];

/** Schlüssel für die Namen der Wesen, gleich benannt wie in i18n.js. */
export function nameKey(who) {
  return `story.name.${who}`;
}

export const STORY_SEEN_KEY = 'mogli.story.v1';
