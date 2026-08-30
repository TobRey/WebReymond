// Die Vorgeschichte in sechs Tafeln.
//
// Sie hat zwei Aufgaben, und die zweite ist die wichtigere: Irgendwo muss
// stehen, wie man steuert. Statt eines Hilfebildschirms, den niemand liest,
// erklären es die drei Wesen nebenbei in den letzten Tafeln.
//
// Die Texte selbst stehen in i18n.js (seit 3.0 erzählen sie die Reise nach
// rechts, nicht mehr den Turm), hier nur der Ablauf – sonst gäbe es sie
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

export const STORY_SEEN_KEY = 'mogli.story.v2';
