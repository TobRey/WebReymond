/**
 * Der Editor.
 *
 * Er läuft in der Hülle und arbeitet auf dem Dokument im Rahmen. Das
 * geht nur, weil beide dieselbe Herkunft haben – über eine fremde Domain
 * hinweg dürfte JavaScript den Inhalt eines Rahmens weder lesen noch
 * verändern, und daran ändert keine Einstellung etwas. Genau deshalb
 * wird eine Kundenwebsite hier bearbeitet und dort veröffentlicht, statt
 * sie fernzusteuern.
 *
 * Was diesen Editor vom bisherigen unterscheidet, ist eine Zeile, die es
 * nicht mehr gibt: window.location.reload(). Jede ändernde Antwort
 * bringt das fertige HTML des betroffenen Abschnitts mit, und der Editor
 * tauscht genau dieses eine Element aus. Bildlauf, Auswahl und Verlauf
 * bleiben stehen.
 */

import './editor.css';

import { ziehbar, plaetzeZwischen } from './ziehen.js';
import { auswahl, el, feld, knopf, melden, schalter, text, textfeld } from './hilfen.js';

const daten = JSON.parse(document.getElementById('wa-editor-data')?.textContent || '{}');

const zustand = {
  rahmen: null,
  dok: null,
  gewaehlt: null,
  breite: 'desktop',
  verschiebemodus: false,
  sprache: (daten.sprachen?.[0]?.code) || 'de',
};

start();

function start() {
  zustand.rahmen = document.querySelector('[data-editor-frame]');

  if (!zustand.rahmen) return;

  zustand.rahmen.addEventListener('load', rahmenBereit);

  leiste();
  vorrat();
}

// ====================================================================
// Der Rahmen
// ====================================================================

function rahmenBereit() {
  zustand.dok = zustand.rahmen.contentDocument;

  if (!zustand.dok) return;

  zustand.dok.documentElement.classList.add('wae-aktiv');

  abschnitteMarkieren();
  klicksVerdrahten();
  ziehenVerdrahten();

  // Ein Merker, an dem sich später nachweisen lässt, dass der Rahmen
  // während einer Änderung nicht neu geladen wurde. Ohne so einen Merker
  // ist "es lädt nicht neu" eine Behauptung.
  zustand.dok.defaultView.__waeGeladen = (zustand.dok.defaultView.__waeGeladen || 0) + 1;
}

function abschnitte() {
  return Array.from(zustand.dok?.querySelectorAll('[data-section-id]') || [])
    .filter((s) => s.dataset.sectionId !== '0');
}

function abschnitteMarkieren() {
  abschnitte().forEach((s) => {
    if (s.querySelector(':scope > .wae-griff')) return;

    if (zustand.dok.defaultView.getComputedStyle(s).position === 'static') {
      s.style.position = 'relative';
    }

    const griff = el('div', 'wae wae-griff');

    griff.appendChild(el('span', 'wae-griff__punkte', ['⠿']));
    griff.appendChild(el('span', 'wae-griff__name', [beschriftung(s)]));

    // Kopf und Fuss lassen sich nicht ziehen. Ein Kopf in der Mitte
    // einer Seite ist kein Gestaltungsmittel, sondern ein Versehen.
    if (fest(s)) griff.classList.add('wae-griff--fest');

    s.prepend(griff);
  });
}

function beschriftung(s) {
  const typ = s.dataset.section || 'Abschnitt';
  const eintrag = (daten.typen || []).find((t) => t.typ === typ);

  return eintrag ? eintrag.label : typ;
}

function fest(s) {
  return s.dataset.section === 'header' || s.dataset.section === 'footer';
}

// ====================================================================
// Auswählen
// ====================================================================

function klicksVerdrahten() {
  zustand.dok.addEventListener('click', (e) => {
    // Im Verschiebemodus ist ein Klick eine Ortsangabe, keine Auswahl.
    if (zustand.verschiebemodus) return;

    const abschnitt = e.target.closest?.('[data-section-id]');

    if (!abschnitt || abschnitt.dataset.sectionId === '0') return;

    // Verweise im Editor führen nirgendwohin: Wer auf einen Knopf
    // klickt, will ihn bearbeiten und nicht die Seite verlassen.
    e.preventDefault();
    e.stopPropagation();

    waehlen(abschnitt);
  }, true);

  zustand.dok.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') abwaehlen();
  });
}

function waehlen(abschnitt) {
  abwaehlen();

  zustand.gewaehlt = abschnitt;
  abschnitt.classList.add('wae-gewaehlt');

  blatt(abschnitt);
}

function abwaehlen() {
  zustand.dok?.querySelectorAll('.wae-gewaehlt')
    .forEach((s) => s.classList.remove('wae-gewaehlt'));

  zustand.gewaehlt = null;
  document.querySelector('.wae-blatt')?.remove();
}

// ====================================================================
// Ziehen
// ====================================================================

function ziehenVerdrahten() {
  // Abschnitte.
  ziehbar({
    dokument: zustand.dok,
    griff: '.wae-griff:not(.wae-griff--fest)',
    ziel: (griff) => griff.closest('[data-section-id]'),
    plaetze: (element) => plaetzeZwischen(abschnitte(), element, (el) => !fest(el)),
    abgelegt: (element, platz) => abschnittAblegen(element, platz),
  });

  // Listeneinträge innerhalb eines Abschnitts.
  ziehbar({
    dokument: zustand.dok,
    griff: '.s-items > *',
    ziel: (kind) => kind,
    plaetze: (element) => plaetzeZwischen(
      Array.from(element.parentElement.children),
      element,
    ),
    abgelegt: (element, platz) => eintragAblegen(element, platz),
  });
}

async function abschnittAblegen(element, platz) {
  const reihe = abschnitte();

  // Der Platz zählt zwischen den beweglichen Abschnitten; der Server
  // zählt zwischen allen. Ohne diese Umrechnung landet ein Abschnitt
  // einen Platz daneben, sobald es einen Kopf gibt - und das fällt erst
  // auf, wenn jemand genau hinsieht.
  const beweglich = reihe.filter((s) => !fest(s));
  const ziel = reihe.indexOf(
    platz >= beweglich.length ? beweglich[beweglich.length - 1] : beweglich[platz],
  );

  const antwort = await ruf('ziehen', {
    abschnitt: element.dataset.sectionId,
    position: ziel,
  });

  if (!antwort.ok) return;

  // Umgehängt wird nach der Reihenfolge, die der Server geschrieben hat,
  // nicht nach der, die hier ausgerechnet wurde. Zwei Rechnungen für
  // dasselbe laufen irgendwann auseinander, und dann zeigt der Editor
  // etwas anderes als die Seite.
  ordnen(antwort.order);

  melden('Verschoben.');
}

/** Die Abschnitte im Rahmen in die angegebene Reihenfolge bringen. */
function ordnen(ids) {
  if (!Array.isArray(ids) || ids.length === 0) return;

  const eltern = zustand.dok.querySelector('main') || zustand.dok.body;

  ids.forEach((id) => {
    const el = zustand.dok.querySelector(`[data-section-id="${id}"]`);

    // appendChild verschiebt ein vorhandenes Element, statt es zu
    // kopieren. Nacheinander angewandt ergibt das genau die Reihenfolge
    // der Liste.
    if (el) el.parentElement.appendChild(el);
  });
}

async function eintragAblegen(element, platz) {
  const liste = element.parentElement;
  const abschnitt = element.closest('[data-section-id]');
  const von = Array.from(liste.children).indexOf(element);

  const antwort = await ruf('eintrag', {
    abschnitt: abschnitt.dataset.sectionId,
    was: 'ziehen',
    von,
    nach: platz > von ? platz - 1 : platz,
  });

  if (antwort.ok) ersetzen(abschnitt, antwort.html);
}

// ====================================================================
// Die Leiste in der Hülle
// ====================================================================

function leiste() {
  document.querySelectorAll('[data-editor-widths] [data-width]').forEach((b) => {
    b.addEventListener('click', () => breite(b.dataset.width, b));
  });

  document.querySelector('[data-editor-page]')?.addEventListener('change', (e) => {
    const url = new URL(window.location.href);

    url.searchParams.set('seite', e.target.value);
    window.location.href = url.toString();
  });

  document.querySelectorAll('[data-editor-action]').forEach((b) => {
    b.addEventListener('click', () => leistenAktion(b.dataset.editorAction));
  });
}

/**
 * Desktop, Tablet, Handy.
 *
 * Der Rahmen wird wirklich schmaler. Die alte Vorschau hat stattdessen
 * nur den Body zusammengeschoben – dabei lösen die Umbruchregeln der
 * Seite gar nicht aus, und die Handyansicht zeigte eine schmale
 * Desktopseite.
 */
function breite(welche, knopfElement) {
  zustand.breite = welche;

  const buehne = document.querySelector('[data-editor-stage]');

  buehne.dataset.width = welche;

  document.querySelectorAll('[data-editor-widths] [data-width]')
    .forEach((b) => b.classList.toggle('is-active', b === knopfElement));
}

async function leistenAktion(was) {
  if (was === 'veroeffentlichen') {
    const antwort = await ruf('veroeffentlichen', { seite: daten.seite.id });

    if (antwort.ok) {
      melden(antwort.meldung || 'Veröffentlicht.');
      standSetzen(false);
    }

    return;
  }

  if (was === 'verwerfen') {
    if (!window.confirm('Den Entwurf verwerfen und auf den veröffentlichten Stand zurück?')) {
      return;
    }

    const antwort = await ruf('verwerfen', { seite: daten.seite.id });

    if (antwort.ok) {
      melden('Entwurf verworfen.');
      rahmenNeu();
    }
  }
}

function standSetzen(entwurf) {
  const marke = document.querySelector('[data-editor-state]');

  if (!marke) return;

  marke.dataset.draft = entwurf ? '1' : '0';
  marke.textContent = entwurf ? 'Entwurf' : 'Veröffentlicht';
}

/** Nur wenn es nicht anders geht: die Ansicht im Rahmen neu holen. */
function rahmenNeu() {
  abwaehlen();
  zustand.rahmen.contentWindow.location.reload();
}

// ====================================================================
// Der Vorrat: was sich einsetzen lässt
// ====================================================================

function vorrat() {
  const kasten = el('aside', 'wae-vorrat');

  kasten.appendChild(el('h2', 'wae-vorrat__titel', ['Abschnitt einsetzen']));

  const liste = el('div', 'wae-vorrat__liste');

  (daten.typen || []).forEach((typ) => {
    liste.appendChild(knopf(typ.label, 'wae-vorrat__knopf', () => einsetzen(typ.typ)));
  });

  kasten.appendChild(liste);

  // Das Promptfeld für die ganze Seite. Es darf mehr als das eines
  // Abschnitts: Abschnitte anlegen, löschen, umordnen.
  kasten.appendChild(el('h2', 'wae-vorrat__titel', ['Anweisung für die Seite']));
  kasten.appendChild(seitenPrompt());

  kasten.appendChild(el('h2', 'wae-vorrat__titel', ['Farben der Website']));
  kasten.appendChild(themenfelder());

  document.querySelector('[data-editor]')?.appendChild(kasten);
}

function seitenPrompt() {
  const kasten = el('div', 'wae-prompt');
  const eingabe = el('textarea', 'wae-eingabe');

  eingabe.rows = 3;
  eingabe.placeholder = 'z.B. "häng unten einen Kontaktbereich an und '
    + 'schieb die Referenzen nach oben"';

  const senden = knopf('Ausführen', 'wae-knopf wae-knopf--voll', async () => {
    const befehl = eingabe.value.trim();

    if (befehl === '') return;

    senden.disabled = true;
    senden.textContent = 'Arbeitet …';

    await offeneSpeichern();

    const antwort = await ruf('seitenanweisung', {
      seite: daten.seite.id,
      anweisung: befehl,
    });

    senden.disabled = false;
    senden.textContent = 'Ausführen';

    if (antwort.ok) {
      eingabe.value = '';
      melden(antwort.summary || 'Seite angepasst.');
    }
  });

  kasten.appendChild(eingabe);
  kasten.appendChild(senden);

  return kasten;
}

/**
 * Farben an einer Stelle.
 *
 * Eine Änderung hier zieht durch jeden Abschnitt der Website, ohne dass
 * einer davon angefasst wird - das ist der Sinn der Variablen. Deshalb
 * ist das auch die einzige Stelle im Editor, die die Ansicht bewusst neu
 * holt: Es hat sich nicht ein Abschnitt geändert, sondern alle.
 */
function themenfelder() {
  const kasten = el('div', 'wae-thema');
  const wahl = { primary: '', secondary: '', accent: '', mode: '' };

  const senden = async () => {
    const antwort = await ruf('thema', wahl);
    if (antwort.ok) melden('Farben übernommen.');
  };

  [['primary', 'Hauptfarbe'], ['secondary', 'Zweitfarbe'], ['accent', 'Dunkelton']]
    .forEach(([schluessel, beschriftung]) => {
      const e = el('input', 'wae-farbe');

      e.type = 'color';
      e.addEventListener('change', () => {
        wahl[schluessel] = e.value;
        senden();
      });

      kasten.appendChild(feld(beschriftung, e));
    });

  kasten.appendChild(feld('Grundton', auswahl(
    { hell: 'Hell', dunkel: 'Dunkel' },
    'hell',
    (wert) => {
      wahl.mode = wert;
      senden();
    },
  )));

  return kasten;
}

async function einsetzen(typ) {
  // Direkt hinter den ausgewählten Abschnitt, sonst ans Ende. Das ist,
  // was jemand erwartet, der eben etwas angeklickt hat.
  const reihe = abschnitte();
  const nach = zustand.gewaehlt ? reihe.indexOf(zustand.gewaehlt) + 1 : '';

  const antwort = await ruf('einsetzen', {
    seite: daten.seite.id,
    typ,
    position: nach,
  });

  if (!antwort.ok || !antwort.html) return;

  const neu = zustand.dok.createRange().createContextualFragment(antwort.html);
  const element = neu.firstElementChild;

  if (nach !== '' && reihe[nach]) reihe[nach].before(neu);
  else reihe[reihe.length - 1]?.before(neu);

  abschnitteMarkieren();
  if (element) waehlen(zustand.dok.querySelector(`[data-section-id="${antwort.id}"]`));

  melden('Eingesetzt.');
}

// ====================================================================
// Das Einstellungsblatt
// ====================================================================

function blatt(abschnitt) {
  const id = abschnitt.dataset.sectionId;
  const typ = abschnitt.dataset.section;

  const b = el('div', 'wae-blatt');

  b.appendChild(kopfzeile(abschnitt, typ));

  const koerper = el('div', 'wae-blatt__koerper');

  koerper.appendChild(gruppe('Anweisung', [promptfeld(id)]));
  koerper.appendChild(gruppe('Vorlage', [vorlagenfeld(abschnitt, typ)]));
  koerper.appendChild(gruppe('Hintergrund und Bewegung', effektfelder(id)));
  koerper.appendChild(gruppe('Diesen Abschnitt', werkzeuge(abschnitt)));

  b.appendChild(koerper);

  document.querySelector('[data-editor]')?.appendChild(b);
}

function kopfzeile(abschnitt, typ) {
  const z = el('div', 'wae-blatt__kopf');

  z.appendChild(el('strong', null, [beschriftung(abschnitt)]));
  z.appendChild(knopf('×', 'wae-blatt__zu', () => abwaehlen()));

  return z;
}

function gruppe(titel, kinder) {
  const g = el('section', 'wae-gruppe');

  g.appendChild(el('h3', 'wae-gruppe__titel', [titel]));
  kinder.filter(Boolean).forEach((k) => g.appendChild(k));

  return g;
}

/**
 * Das Promptfeld.
 *
 * Erst wird gespeichert, was von Hand geändert wurde, dann läuft die
 * Anweisung. Andersherum arbeitete die Schnittstelle auf einem Stand,
 * den es nicht mehr gibt, und überschriebe die Handarbeit.
 */
function promptfeld(id) {
  const kasten = el('div', 'wae-prompt');

  const eingabe = el('textarea', 'wae-eingabe');

  eingabe.rows = 3;
  eingabe.placeholder = 'Was soll an diesem Abschnitt anders sein?';

  const senden = knopf('Ausführen', 'wae-knopf wae-knopf--voll', async () => {
    const befehl = eingabe.value.trim();

    if (befehl === '') return;

    senden.disabled = true;
    senden.textContent = 'Arbeitet …';

    // Erst speichern, was offen ist. Die Textfelder melden mit
    // Verzögerung; ein Prompt darf ihnen nicht zuvorkommen.
    await offeneSpeichern();

    const antwort = await ruf('anweisung', { abschnitt: id, anweisung: befehl });

    senden.disabled = false;
    senden.textContent = 'Ausführen';

    if (antwort.ok) {
      eingabe.value = '';
      if (antwort.html) ersetzen(zustand.gewaehlt, antwort.html);
      melden(antwort.summary || 'Angepasst.');
    }
  });

  kasten.appendChild(eingabe);
  kasten.appendChild(senden);

  return kasten;
}

function vorlagenfeld(abschnitt, typ) {
  const kasten = el('div', 'wae-vorlagen');
  const id = abschnitt.dataset.sectionId;

  fetch(`${daten.api}/../../api/sections/${id}/templates`, {
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  })
    .then((r) => r.json())
    .then((antwort) => {
      if (!antwort.ok) return;

      kasten.appendChild(auswahl(
        Object.fromEntries(antwort.variants.map((v) => [v.key, v.label])),
        antwort.variants.find((v) => v.current)?.key,
        async (wert) => {
          const a = await ruf('vorlage', { abschnitt: id, variante: wert });
          if (a.ok) ersetzen(zustand.gewaehlt, a.html);
        },
      ));
    })
    .catch(() => {});

  return kasten;
}

function effektfelder(id) {
  const beschreibung = daten.effekte || {};
  const felder = [];

  const senden = async (was, wert) => {
    aktuelleEffekte[was] = wert;

    const antwort = await rufJson('effekte', { abschnitt: id, effekte: aktuelleEffekte });

    if (antwort.ok) ersetzen(zustand.gewaehlt, antwort.html);
  };

  const aktuelleEffekte = { hintergrund: {} };

  const h = beschreibung.hintergrund?.felder || {};

  Object.entries(h).forEach(([name, f]) => {
    felder.push(feld(f.label, auswahl(f.werte, '', (wert) => {
      aktuelleEffekte.hintergrund[name] = wert;
      senden('hintergrund', aktuelleEffekte.hintergrund);
    })));
  });

  ['bewegung', 'parallaxe'].forEach((name) => {
    const f = beschreibung[name];
    if (!f?.werte) return;

    felder.push(feld(f.label, auswahl(f.werte, '', (wert) => senden(name, wert))));
  });

  ['kippen', 'magnet', 'zaehlen'].forEach((name) => {
    const f = beschreibung[name];
    if (!f) return;

    felder.push(schalter(f.label, false, (an) => senden(name, an)));
  });

  return felder;
}

function werkzeuge(abschnitt) {
  const id = abschnitt.dataset.sectionId;
  const reihe = el('div', 'wae-werkzeuge');

  // Auf einem Telefon ist Ziehen mühsam. Antippen, dann den Platz
  // antippen, ist dort oft schneller – und bei zittriger Hand die
  // einzige Art, die verlässlich funktioniert.
  reihe.appendChild(knopf('Verschieben', 'wae-knopf', () => verschiebemodus(abschnitt)));

  reihe.appendChild(knopf(
    abschnitt.classList.contains('wae-versteckt') ? 'Einblenden' : 'Ausblenden',
    'wae-knopf',
    async () => {
      const antwort = await ruf('sichtbar', { abschnitt: id });

      if (antwort.ok) {
        abschnitt.classList.toggle('wae-versteckt', antwort.hidden);
        melden(antwort.hidden ? 'Ausgeblendet.' : 'Wieder sichtbar.');
      }
    },
  ));

  if (!fest(abschnitt)) {
    reihe.appendChild(knopf('Verdoppeln', 'wae-knopf', async () => {
      const antwort = await ruf('verdoppeln', { abschnitt: id });

      if (antwort.ok && antwort.html) {
        abschnitt.after(zustand.dok.createRange().createContextualFragment(antwort.html));
        abschnitteMarkieren();
        melden('Verdoppelt.');
      }
    }));

    reihe.appendChild(knopf('Löschen', 'wae-knopf wae-knopf--gefahr', async () => {
      if (!window.confirm('Diesen Abschnitt löschen?')) return;

      const antwort = await ruf('entfernen', { abschnitt: id });

      if (antwort.ok) {
        abschnitt.remove();
        abwaehlen();
        melden('Gelöscht.');
      }
    }));
  }

  return [reihe];
}

/**
 * Verschieben durch Antippen.
 *
 * Alle möglichen Plätze werden als grosse Flächen gezeigt; eine davon
 * antippen setzt den Abschnitt dorthin. Kein Ziehen, kein Zielen.
 */
function verschiebemodus(abschnitt) {
  zustand.verschiebemodus = true;
  zustand.dok.documentElement.classList.add('wae-verschiebt');

  const reihe = abschnitte();
  const felder = [];

  reihe.forEach((s, i) => {
    if (fest(s) || s === abschnitt) return;

    const platz = el('button', 'wae wae-platz');

    platz.type = 'button';
    platz.textContent = 'Hierhin';
    platz.style.top = `${s.offsetTop}px`;

    platz.addEventListener('click', async (e) => {
      e.stopPropagation();

      const antwort = await ruf('ziehen', {
        abschnitt: abschnitt.dataset.sectionId,
        position: i,
      });

      if (antwort.ok) {
        s.before(abschnitt);
        melden('Verschoben.');
      }

      beenden();
    });

    zustand.dok.body.appendChild(platz);
    felder.push(platz);
  });

  const abbrechen = el('button', 'wae wae-platz wae-platz--zurueck');

  abbrechen.type = 'button';
  abbrechen.textContent = 'Abbrechen';
  abbrechen.addEventListener('click', beenden);
  zustand.dok.body.appendChild(abbrechen);
  felder.push(abbrechen);

  function beenden() {
    felder.forEach((f) => f.remove());
    zustand.dok.documentElement.classList.remove('wae-verschiebt');
    zustand.verschiebemodus = false;
  }
}

// ====================================================================
// Austauschen statt neu laden
// ====================================================================

/**
 * Einen Abschnitt durch seine neue Fassung ersetzen.
 *
 * Das ist der Kern des "kein Neuladen". Der Server rendert genau diesen
 * einen Abschnitt und schickt sein HTML; hier wird das Element getauscht
 * und die Auswahl wieder daraufgesetzt.
 */
function ersetzen(alt, html) {
  if (!alt || !html) return;

  const war = alt === zustand.gewaehlt;
  const stelle = zustand.dok.createRange().createContextualFragment(html);
  const neu = stelle.firstElementChild;

  alt.replaceWith(stelle);

  abschnitteMarkieren();

  if (war && neu) {
    zustand.gewaehlt = zustand.dok
      .querySelector(`[data-section-id="${neu.dataset.sectionId}"]`);
    zustand.gewaehlt?.classList.add('wae-gewaehlt');
  }
}

/** Alle Textfelder, die noch auf ihre Verzögerung warten, jetzt melden. */
async function offeneSpeichern() {
  document.querySelectorAll('.wae-blatt .wae-eingabe').forEach((e) => e.blur());

  // Ein Wimpernschlag, damit die blur-Meldungen noch abgehen.
  await new Promise((fertig) => setTimeout(fertig, 60));
}

// ====================================================================
// Die Schnittstelle
// ====================================================================

function ruf(was, koerper) {
  const daten2 = new URLSearchParams();

  Object.entries(koerper || {}).forEach(([k, v]) => daten2.append(k, String(v)));
  daten2.append('_token', daten.token);

  return schicken(`${daten.api}/${was}`, daten2, null);
}

function rufJson(was, koerper) {
  return schicken(
    `${daten.api}/${was}`,
    JSON.stringify({ ...koerper, _token: daten.token }),
    'application/json',
  );
}

async function schicken(adresse, koerper, typ) {
  const kopf = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-Token': daten.token,
  };

  if (typ) kopf['Content-Type'] = typ;

  try {
    const antwort = await fetch(adresse, { method: 'POST', headers: kopf, body: koerper });
    const inhalt = await antwort.json().catch(() => ({}));

    if (!antwort.ok || inhalt.ok === false) {
      melden(inhalt.error || 'Das hat nicht geklappt.', 'fehler');
      return { ok: false };
    }

    if (Object.prototype.hasOwnProperty.call(inhalt, 'entwurf')) {
      standSetzen(inhalt.entwurf);
    }

    if (inhalt.neuladen) rahmenNeu();

    return inhalt;
  } catch {
    melden('Keine Verbindung zum Server.', 'fehler');
    return { ok: false };
  }
}
