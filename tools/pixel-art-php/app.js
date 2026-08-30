/**
 * Die Bedienung der Seite.
 *
 * Ein Aufruf an api.php zeichnet immer genau ein Bild. Eine Animation
 * entsteht deshalb Bild für Bild – nach jedem fertigen Bild wird das GIF neu
 * gebaut, damit man beim Warten sieht, dass es vorangeht.
 */

/** Ein Pixel der Figur wird zu 8×8 Pixeln im GIF. */
var FAKTOR = 8;

/** Anzeigedauer je Bild – etwa acht Bilder pro Sekunde. */
var DAUER_MS = 120;

var zustand = {
  figur: null, // { palette, bild }
  groessen: [],
  beschaeftigt: false,
  passwort: '',
};

try {
  zustand.passwort = window.localStorage.getItem('pixelart-passwort') || '';
} catch (e) {
  // Manche Browser verbieten den Speicher. Dann fragen wir eben jedes Mal.
}

var el = function (id) {
  return document.getElementById(id);
};

function status(text, istFehler) {
  var feld = el('status');
  feld.textContent = text;
  feld.className = istFehler ? 'status fehler' : 'status';
}

function knoepfeSperren(gesperrt) {
  zustand.beschaeftigt = gesperrt;
  el('zeichnen').disabled = gesperrt;
  var knoepfe = el('animationen').querySelectorAll('button');
  for (var i = 0; i < knoepfe.length; i += 1) {
    knoepfe[i].disabled = gesperrt || zustand.figur === null;
  }
}

/** Fragt den Server. Fehler kommen als Ausnahme mit lesbarem Text zurück. */
async function anfrage(daten) {
  daten.passwort = el('passwort').value || zustand.passwort;

  var antwort = await fetch('api.php', {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify(daten),
  });

  var text = await antwort.text();
  var inhalt = null;
  try {
    inhalt = JSON.parse(text);
  } catch (e) {
    throw new Error('Der Server hat keine gültige Antwort geschickt.');
  }

  if (!antwort.ok) {
    if (antwort.status === 401) {
      el('passwortbereich').hidden = false;
      el('passwort').focus();
    }
    throw new Error(inhalt.fehler || 'Das hat nicht geklappt.');
  }

  // Ein Passwort, das angenommen wurde, merken wir uns für das nächste Mal.
  if (daten.passwort) {
    zustand.passwort = daten.passwort;
    try {
      window.localStorage.setItem('pixelart-passwort', daten.passwort);
    } catch (e) {
      // Kein Speicher, kein Problem.
    }
  }

  return inhalt;
}

/** Zeigt ein GIF in einer der beiden Vorschauen und hängt den Download daran. */
function anzeigen(name, palette, bilder, breite, hoehe) {
  var bild = el(name + 'bild');
  var download = el(name + 'download');

  if (bild.src) {
    URL.revokeObjectURL(bild.src);
  }

  var url = URL.createObjectURL(bilderZuGif(palette, bilder, breite, hoehe, FAKTOR, DAUER_MS));
  bild.src = url;
  bild.hidden = false;
  download.href = url;
  download.hidden = false;

  var leer = el(name + 'leer');
  if (leer) leer.hidden = true;
}

function groesse() {
  return zustand.groessen[Number(el('groesse').value)] || zustand.groessen[0];
}

function beschreibung() {
  return el('beschreibung').value.trim();
}

async function figurZeichnen() {
  if (beschreibung().length < 3) {
    status('Bitte zuerst die Figur beschreiben.', true);
    return;
  }

  var masse = groesse();
  knoepfeSperren(true);
  status('Die Figur wird gezeichnet – das dauert einen Moment …');

  try {
    var ergebnis = await anfrage({
      aktion: 'figur',
      beschreibung: beschreibung(),
      breite: masse.breite,
      hoehe: masse.hoehe,
    });

    zustand.figur = { palette: ergebnis.palette, bild: ergebnis.bild, masse: masse };
    anzeigen('figur', ergebnis.palette, [ergebnis.bild], masse.breite, masse.hoehe);
    el('animationshinweis').textContent =
      'Die Bewegung zeigt dieselbe Figur. In Klammern steht die Anzahl der Bilder.';
    status('Fertig. Jetzt eine Bewegung auswählen.');
  } catch (fehler) {
    status(fehler.message, true);
  }

  knoepfeSperren(false);
}

async function animationZeichnen(id, anzahl) {
  if (!zustand.figur) return;

  var masse = zustand.figur.masse;
  var palette = {};
  var taste;
  for (taste in zustand.figur.palette) {
    palette[taste] = zustand.figur.palette[taste];
  }

  var bilder = [];
  knoepfeSperren(true);

  try {
    for (var i = 0; i < anzahl; i += 1) {
      status('Bild ' + (i + 1) + ' von ' + anzahl + ' wird gezeichnet …');

      var ergebnis = await anfrage({
        aktion: 'bild',
        animation: id,
        nummer: i,
        beschreibung: beschreibung(),
        breite: masse.breite,
        hoehe: masse.hoehe,
        palette: zustand.figur.palette,
        bild: zustand.figur.bild,
      });

      // Ein Bild darf eine Farbe ergänzen; die Farben der Figur bleiben.
      for (taste in ergebnis.palette) {
        if (!palette[taste]) palette[taste] = ergebnis.palette[taste];
      }

      bilder.push(ergebnis.bild);
      anzeigen('animations', palette, bilder, masse.breite, masse.hoehe);
    }

    status('Fertig. Das GIF lässt sich jetzt herunterladen.');
  } catch (fehler) {
    status(
      bilder.length > 0
        ? fehler.message + ' (' + bilder.length + ' Bilder sind fertig geworden.)'
        : fehler.message,
      true,
    );
  }

  knoepfeSperren(false);
}

async function starten() {
  var info;
  try {
    info = await anfrage({ aktion: 'info' });
  } catch (fehler) {
    status('Der Server antwortet nicht: ' + fehler.message, true);
    return;
  }

  zustand.groessen = info.groessen;
  var auswahl = el('groesse');
  for (var i = 0; i < info.groessen.length; i += 1) {
    var eintrag = document.createElement('option');
    eintrag.value = String(i);
    eintrag.textContent =
      info.groessen[i].breite + ' × ' + info.groessen[i].hoehe + (i === 0 ? ' (Standard)' : '');
    auswahl.appendChild(eintrag);
  }

  var reihe = el('animationen');
  info.animationen.forEach(function (animation) {
    var knopf = document.createElement('button');
    knopf.className = 'knopf zweit';
    knopf.textContent = animation.name + ' (' + animation.bilder + ')';
    knopf.disabled = true;
    knopf.addEventListener('click', function () {
      animationZeichnen(animation.id, animation.bilder);
    });
    reihe.appendChild(knopf);
  });

  if (info.passwortNoetig && !zustand.passwort) {
    el('passwortbereich').hidden = false;
  }

  if (!info.bereit) {
    status('Noch kein Schlüssel eingetragen. Trage ihn in der Datei config.php ein.', true);
    el('zeichnen').disabled = true;
    return;
  }

  el('zeichnen').addEventListener('click', figurZeichnen);
  el('beschreibung').addEventListener('keydown', function (ereignis) {
    if (ereignis.key === 'Enter' && !zustand.beschaeftigt) figurZeichnen();
  });
}

starten();
