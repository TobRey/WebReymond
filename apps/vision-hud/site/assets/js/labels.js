/**
 * Deutsche Wörter, die HUD und ReyRey abseits der Klassentabellen brauchen:
 * Stimmungen des Ausdrucksmodells und der unbestimmte Artikel.
 *
 * Die Objektklassen selbst stehen in labels-oiv7.js (Detektor, 601) und
 * labels-imagenet.js (Zweitstufe, 1000).
 */

/** Stimmungen des Ausdrucksmodells. */
const MOOD_DE = {
  neutral: 'neutral',
  happy: 'froh',
  sad: 'traurig',
  angry: 'wütend',
  fearful: 'ängstlich',
  disgusted: 'angewidert',
  surprised: 'überrascht',
};

export function moodFor(expression) {
  return MOOD_DE[expression] ?? expression;
}

/*
 * Artikel raten. Die Klassentabellen kennen kein Geschlecht, und 1600 Einträge
 * von Hand zu pflegen lohnt nicht: Die Endung trifft bei Alltagswörtern
 * meist. „ein“ deckt männlich und sächlich ab; nur weiblich braucht „eine“.
 */
const FEMININE_ENDINGS = /(e|ung|heit|keit|schaft|ion|tät|ur|ik|ei|enz|anz|ie|a)$/i;
const FEMININE = new Set([
  'Maus',
  'Uhr',
  'Tür',
  'Bank',
  'Wand',
  'Kuh',
  'Gans',
  'Nuss',
  'Frucht',
  'Brust',
  'Faust',
  'Hand',
  'Nase',
  'Stadt',
  'Burg',
  'Brücke',
  'Kunst',
  'Wurst',
  'Milch',
  'Butter',
  'Gabel',
  'Schaufel',
  'Trommel',
  'Orgel',
  'Geige',
  'Ampel',
  'Kartoffel',
  'Zwiebel',
  'Insel',
  'Schüssel',
  'Kugel',
  'Nadel',
  'Regel',
  'Wolke',
  'Sonne',
  'Axt',
  'Bahn',
  'Jacht',
  'Fähre',
  'Yacht',
  'Muschel',
  'Schildkröte',
  'Eidechse',
  'Fledermaus',
  'Möwe',
  'Eule',
  'Ente',
  'Taube',
  'Ziege',
  'Giraffe',
  'Antilope',
  'Katze',
  'Ratte',
  'Spinne',
  'Biene',
  'Schnecke',
]);
/** Endet auf -e oder -a, ist aber nicht weiblich. */
const NOT_FEMININE = new Set([
  'Käse',
  'Hase',
  'Löwe',
  'Affe',
  'Junge',
  'Kunde',
  'Bote',
  'Auge',
  'Ende',
  'Gebäude',
  'Getränk',
  'Sofa',
  'Kamera',
  'Cola',
  'Koala',
  'Puma',
  'Lama',
  'Zebra',
  'Gorilla',
  'Panda',
  'Schema',
  'Thema',
  'Komma',
  'Drama',
  'Pyjama',
  'Boa',
  'Zebra',
]);

/** „Lampe“ → „eine Lampe“, „Tisch“ → „ein Tisch“. */
export function withArticle(name) {
  const word = String(name ?? '').trim();
  if (!word) return 'etwas';
  const head = word.split(/\s+/)[0];
  const feminine = FEMININE.has(head) || (FEMININE_ENDINGS.test(head) && !NOT_FEMININE.has(head));
  return `${feminine ? 'eine' : 'ein'} ${word}`;
}
