/**
 * Kleinkram, den der Editor überall braucht.
 *
 * Elemente werden hier gebaut und nie über innerHTML zusammengesetzt.
 * Das ist keine Vorsicht um ihrer selbst willen: Im Editor stehen Texte
 * aus Kundenwebsites, und irgendeiner davon enthält irgendwann eine
 * spitze Klammer.
 *
 * Die einzige Ausnahme ist das HTML eines Abschnitts, das vom eigenen
 * Server kommt und dort gerendert wurde – es wird bewusst als Markup
 * eingesetzt, weil genau das seine Aufgabe ist.
 */

/** Ein Element bauen. */
export function el(tag, klasse, kinder) {
  const knoten = document.createElement(tag);

  if (klasse) knoten.className = klasse;

  (kinder || []).forEach((kind) => {
    knoten.appendChild(typeof kind === 'string' ? text(kind) : kind);
  });

  return knoten;
}

export function text(inhalt) {
  return document.createTextNode(inhalt);
}

/** Eine Schaltfläche mit Beschriftung und Aufgabe. */
export function knopf(beschriftung, klasse, tut) {
  const b = el('button', klasse, [beschriftung]);

  b.type = 'button';
  b.addEventListener('click', tut);

  return b;
}

/** Ein beschriftetes Feld. */
export function feld(beschriftung, eingabe) {
  const kennung = 'ed-' + Math.random().toString(36).slice(2, 9);

  eingabe.id = kennung;

  const marke = el('label', 'wae-feld__marke', [beschriftung]);
  marke.htmlFor = kennung;

  return el('div', 'wae-feld', [marke, eingabe]);
}

/** Ein Auswahlfeld aus einer Liste. */
export function auswahl(werte, gewaehlt, geaendert) {
  const s = el('select', 'wae-eingabe');

  const eintraege = Array.isArray(werte)
    ? werte.map((w) => [w, String(w)])
    : Object.entries(werte);

  eintraege.forEach(([wert, beschriftung]) => {
    const o = el('option', null, [String(beschriftung)]);

    o.value = String(wert);
    o.selected = String(wert) === String(gewaehlt);

    s.appendChild(o);
  });

  s.addEventListener('change', () => geaendert(s.value));

  return s;
}

/** Ein Textfeld, das erst nach einer Pause meldet. */
export function textfeld(wert, geaendert, zeilen) {
  const e = el(zeilen ? 'textarea' : 'input', 'wae-eingabe');

  if (zeilen) e.rows = zeilen;
  else e.type = 'text';

  e.value = wert || '';

  let warten;

  e.addEventListener('input', () => {
    // Nicht bei jedem Tastendruck an den Server: Wer einen Satz tippt,
    // löste sonst dreissig Anfragen aus, und die letzte käme
    // möglicherweise vor der vorletzten an.
    clearTimeout(warten);
    warten = setTimeout(() => geaendert(e.value), 450);
  });

  e.addEventListener('blur', () => {
    clearTimeout(warten);
    geaendert(e.value);
  });

  return e;
}

/** Ein Schalter. */
export function schalter(beschriftung, an, geaendert) {
  const e = el('input');

  e.type = 'checkbox';
  e.checked = !!an;
  e.className = 'wae-schalter';
  e.addEventListener('change', () => geaendert(e.checked));

  const marke = el('label', 'wae-schalterzeile', [e, text(beschriftung)]);

  return marke;
}

/** Eine kurze Meldung, die von selbst wieder geht. */
export function melden(nachricht, art) {
  const alt = document.querySelector('.wae-meldung');
  alt?.remove();

  const m = el('div', 'wae-meldung' + (art ? ' wae-meldung--' + art : ''), [nachricht]);

  document.body.appendChild(m);

  setTimeout(() => m.remove(), art === 'fehler' ? 6000 : 2600);
}
