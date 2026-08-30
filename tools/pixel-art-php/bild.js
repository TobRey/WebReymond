/**
 * Ein eigenes Bild in ein Zeichenraster übersetzen.
 *
 * Das passiert vollständig im Browser: keine Anfrage an den Server, keine
 * Kosten.
 *
 * Wichtigster Punkt: Pixel-Art soll unverändert bleiben. Solche Bilder sind
 * fast immer vergrössert exportiert – ein 14 × 50 grosser Sprite liegt als
 * 140 × 500 grosse Datei vor, jeder Bildpunkt als 10 × 10 grosser Block.
 * Wird dieses Raster erkannt, übernimmt das Werkzeug die Vorlage Punkt für
 * Punkt. Nur wenn sie nicht ins Raster passt – etwa bei einem Foto – wird
 * gerechnet.
 */

/** Grösser als das braucht niemand, um daraus 64 Pixel zu machen. */
var MAX_QUELLE = 2048;

/** Zeichen, die als Palettenschlüssel dienen. Der Punkt fehlt mit Absicht. */
var SCHLUESSEL = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789+*%$#@';

/** Ab dieser Deckkraft gilt ein Pixel als vorhanden. */
var DECKKRAFT = 128;

function kontext(breite, hoehe) {
  var flaeche = document.createElement('canvas');
  flaeche.width = breite;
  flaeche.height = hoehe;
  return flaeche.getContext('2d', { willReadFrequently: true });
}

async function bildLaden(datei) {
  var bitmap = await createImageBitmap(datei);

  var faktor = Math.min(1, MAX_QUELLE / Math.max(bitmap.width, bitmap.height));
  var breite = Math.max(1, Math.round(bitmap.width * faktor));
  var hoehe = Math.max(1, Math.round(bitmap.height * faktor));

  var ctx = kontext(breite, hoehe);
  ctx.drawImage(bitmap, 0, 0, breite, hoehe);
  bitmap.close();

  var daten = ctx.getImageData(0, 0, breite, hoehe);
  return { voll: rastererkennung(daten), eng: rastererkennung(beschneiden(daten)) };
}

/** Durchsichtigen Rand abschneiden. */
function beschneiden(daten) {
  var links = daten.width;
  var rechts = -1;
  var oben = daten.height;
  var unten = -1;
  var x;
  var y;

  for (y = 0; y < daten.height; y += 1) {
    for (x = 0; x < daten.width; x += 1) {
      if (daten.data[(y * daten.width + x) * 4 + 3] < DECKKRAFT) continue;
      if (x < links) links = x;
      if (x > rechts) rechts = x;
      if (y < oben) oben = y;
      if (y > unten) unten = y;
    }
  }

  // Bild ohne durchsichtige Stellen (etwa ein Foto): nichts abzuschneiden.
  if (rechts < 0) return daten;

  var breite = rechts - links + 1;
  var hoehe = unten - oben + 1;
  if (breite === daten.width && hoehe === daten.height) return daten;

  var ziel = kontext(breite, hoehe).createImageData(breite, hoehe);
  for (y = 0; y < hoehe; y += 1) {
    var von = ((y + oben) * daten.width + links) * 4;
    ziel.data.set(daten.data.subarray(von, von + breite * 4), y * breite * 4);
  }
  return ziel;
}

/** Sucht die Kantenlänge, in der das Bild aus einfarbigen Blöcken besteht. */
function rastererkennung(daten) {
  var grenze = Math.min(64, Math.floor(daten.width / 4), Math.floor(daten.height / 4));

  for (var block = grenze; block >= 2; block -= 1) {
    if (daten.width % block !== 0 || daten.height % block !== 0) continue;
    if (bloeckeEinfarbig(daten, block)) {
      return {
        daten: daten,
        breite: daten.width,
        hoehe: daten.height,
        block: block,
        nativBreite: daten.width / block,
        nativHoehe: daten.height / block,
      };
    }
  }

  return {
    daten: daten,
    breite: daten.width,
    hoehe: daten.height,
    block: 1,
    nativBreite: daten.width,
    nativHoehe: daten.height,
  };
}

function bloeckeEinfarbig(daten, block) {
  var werte = daten.data;

  for (var y = 0; y < daten.height; y += block) {
    for (var x = 0; x < daten.width; x += block) {
      var erster = (y * daten.width + x) * 4;
      for (var dy = 0; dy < block; dy += 1) {
        for (var dx = 0; dx < block; dx += 1) {
          var hier = ((y + dy) * daten.width + x + dx) * 4;
          for (var k = 0; k < 4; k += 1) {
            if (werte[hier + k] !== werte[erster + k]) return false;
          }
        }
      }
    }
  }

  return true;
}

/**
 * Die Grösse, die am besten zum Bild passt.
 *
 * Erste Wahl ist immer eine, in die die Vorlage unverändert hineinpasst –
 * dann muss kein einziger Bildpunkt neu berechnet werden.
 */
function besteGroesse(bild, groessen) {
  var passend = function (raster) {
    var beste = null;
    for (var i = 0; i < groessen.length; i += 1) {
      if (raster.nativBreite > groessen[i].breite || raster.nativHoehe > groessen[i].hoehe)
        continue;
      if (!beste || groessen[i].breite * groessen[i].hoehe < beste.breite * beste.hoehe) {
        beste = groessen[i];
      }
    }
    return beste;
  };

  // Das ganze Bild zuerst: Wer seinen Sprite mit Rand exportiert hat, soll
  // diesen Rand behalten.
  var ganz = passend(bild.voll) || passend(bild.eng);
  if (ganz) return ganz;

  var verhaeltnis = bild.eng.breite / bild.eng.hoehe;
  var beste = groessen[0];
  var abstand = Infinity;

  for (var k = 0; k < groessen.length; k += 1) {
    var eigen = Math.abs(Math.log(groessen[k].breite / groessen[k].hoehe) - Math.log(verhaeltnis));
    // Bei gleichem Seitenverhältnis die grössere Leinwand: mehr Bildpunkte,
    // mehr Ähnlichkeit mit der Vorlage.
    var gleichauf =
      Math.abs(eigen - abstand) <= 0.01 &&
      groessen[k].breite * groessen[k].hoehe > beste.breite * beste.hoehe;
    if (eigen < abstand - 0.01 || gleichauf) {
      abstand = Math.min(abstand, eigen);
      beste = groessen[k];
    }
  }

  return beste;
}

/**
 * Aus dem geladenen Bild ein Raster machen.
 *
 * Passt die Vorlage ins Raster, wird sie unverändert übernommen. Sonst wird
 * sie so gross wie möglich hineingerechnet – mittig und unten bündig, damit
 * die Füsse auf der Grundlinie stehen.
 */
function bildZuRaster(bild, breite, hoehe, maxFarben) {
  maxFarben = maxFarben || 32;

  var passt = function (raster) {
    return raster.nativBreite <= breite && raster.nativHoehe <= hoehe;
  };
  var quelle = passt(bild.voll) ? bild.voll : bild.eng;

  var skalierung = Math.min(breite / quelle.breite, hoehe / quelle.hoehe);
  var genau = passt(quelle);
  var zielBreite = genau ? quelle.nativBreite : Math.max(1, Math.round(quelle.breite * skalierung));
  var zielHoehe = genau ? quelle.nativHoehe : Math.max(1, Math.round(quelle.hoehe * skalierung));

  var versatz = { x: Math.floor((breite - zielBreite) / 2), y: hoehe - zielHoehe };
  var daten = new Uint8ClampedArray(breite * hoehe * 4);

  if (genau) {
    uebernehmen(quelle, daten, breite, versatz);
  } else {
    verkleinern(quelle, zielBreite, zielHoehe, daten, breite, versatz);
  }

  return rasterZuBild(daten, breite, hoehe, maxFarben);
}

/**
 * Die Vorlage Punkt für Punkt übernehmen: dieselben Pixel, dieselben Farben.
 */
function uebernehmen(quelle, ziel, zeilenBreite, versatz) {
  var daten = quelle.daten.data;

  for (var y = 0; y < quelle.nativHoehe; y += 1) {
    for (var x = 0; x < quelle.nativBreite; x += 1) {
      var i = (y * quelle.block * quelle.breite + x * quelle.block) * 4;
      if (daten[i + 3] < DECKKRAFT) continue;

      var j = ((y + versatz.y) * zeilenBreite + (x + versatz.x)) * 4;
      ziel[j] = daten[i];
      ziel[j + 1] = daten[i + 1];
      ziel[j + 2] = daten[i + 2];
      ziel[j + 3] = 255;
    }
  }
}

/**
 * Verkleinern durch Mitteln über ganze Blöcke.
 *
 * Der Browser könnte das selbst, aber sein Filter greift über den Block
 * hinaus und mischt an jeder Kante Zwischenfarben an – aus fünf Farben
 * würden dreissig. Hier mittelt jeder Zielpixel genau über die Quellpixel,
 * die auf ihn fallen.
 */
function verkleinern(quelle, zielBreite, zielHoehe, ziel, zeilenBreite, versatz) {
  var daten = quelle.daten.data;

  for (var y = 0; y < zielHoehe; y += 1) {
    var y0 = Math.floor((y * quelle.hoehe) / zielHoehe);
    var y1 = Math.max(y0 + 1, Math.floor(((y + 1) * quelle.hoehe) / zielHoehe));

    for (var x = 0; x < zielBreite; x += 1) {
      var x0 = Math.floor((x * quelle.breite) / zielBreite);
      var x1 = Math.max(x0 + 1, Math.floor(((x + 1) * quelle.breite) / zielBreite));

      var summeR = 0;
      var summeG = 0;
      var summeB = 0;
      var summeA = 0;
      var anzahl = 0;

      for (var qy = y0; qy < y1; qy += 1) {
        for (var qx = x0; qx < x1; qx += 1) {
          var i = (qy * quelle.breite + qx) * 4;
          var a = daten[i + 3];
          // Farben nach Deckkraft gewichten, sonst zieht ein durchsichtiger
          // Rand die Farbe der Figur nach Schwarz.
          summeR += daten[i] * a;
          summeG += daten[i + 1] * a;
          summeB += daten[i + 2] * a;
          summeA += a;
          anzahl += 1;
        }
      }

      if (summeA === 0 || summeA / anzahl < DECKKRAFT) continue;

      var j = ((y + versatz.y) * zeilenBreite + (x + versatz.x)) * 4;
      ziel[j] = Math.round(summeR / summeA);
      ziel[j + 1] = Math.round(summeG / summeA);
      ziel[j + 2] = Math.round(summeB / summeA);
      ziel[j + 3] = 255;
    }
  }
}

/** Aus fertigen Bildpunkten Palette und Zeichenraster machen. */
function rasterZuBild(daten, breite, hoehe, maxFarben) {
  var i;

  // Erst zählen, welche Farben überhaupt vorkommen …
  var haeufigkeit = {};
  for (i = 0; i < daten.length; i += 4) {
    if (daten[i + 3] < DECKKRAFT) continue;
    var wert = (daten[i] << 16) | (daten[i + 1] << 8) | daten[i + 2];
    haeufigkeit[wert] = (haeufigkeit[wert] || 0) + 1;
  }

  var vorkommen = Object.keys(haeufigkeit).map(function (schluessel) {
    return { farbe: Number(schluessel), anzahl: haeufigkeit[schluessel] };
  });

  if (vorkommen.length === 0) {
    throw new Error('Auf dem Bild ist nichts zu sehen.');
  }

  // … dann auf so wenige zusammenfassen, dass eine Palette daraus wird.
  var farben = medianCut(vorkommen, maxFarben);

  var palette = {};
  for (i = 0; i < farben.length; i += 1) {
    palette[SCHLUESSEL.charAt(i)] = '#' + ('000000' + farben[i].toString(16)).slice(-6);
  }

  // Jede Quellfarbe bekommt den nächstgelegenen Paletteneintrag – einmal
  // ausgerechnet, danach nachgeschlagen.
  var zuordnung = {};
  vorkommen.forEach(function (eintrag) {
    var beste = 0;
    var abstand = Infinity;
    for (var k = 0; k < farben.length; k += 1) {
      var d = abstandQuadrat(eintrag.farbe, farben[k]);
      if (d < abstand) {
        abstand = d;
        beste = k;
      }
    }
    zuordnung[eintrag.farbe] = SCHLUESSEL.charAt(beste);
  });

  var zeilen = [];
  for (var y = 0; y < hoehe; y += 1) {
    var zeile = '';
    for (var x = 0; x < breite; x += 1) {
      var pos = (y * breite + x) * 4;
      if (daten[pos + 3] < DECKKRAFT) {
        zeile += '.';
        continue;
      }
      var farbe = (daten[pos] << 16) | (daten[pos + 1] << 8) | daten[pos + 2];
      zeile += zuordnung[farbe] || '.';
    }
    zeilen.push(zeile);
  }

  return { palette: palette, bild: zeilen };
}

/**
 * Median-Cut: die Farben so lange in zwei Hälften teilen, bis genug Gruppen
 * da sind. Jede Gruppe wird zu einer Farbe der Palette.
 *
 * Hat das Bild von sich aus wenige Farben – wie fast jede Pixel-Art –, endet
 * jede Gruppe bei genau einer Farbe: Die Palette ist dann die ursprüngliche,
 * Pixel für Pixel.
 */
function medianCut(vorkommen, maxFarben) {
  var gruppen = [vorkommen];

  while (gruppen.length < maxFarben) {
    var index = -1;
    var groesste = 0;

    for (var i = 0; i < gruppen.length; i += 1) {
      var spanne = spannweite(gruppen[i]);
      if (gruppen[i].length > 1 && spanne.weite > groesste) {
        groesste = spanne.weite;
        index = i;
      }
    }

    if (index === -1) break;

    var gruppe = gruppen[index];
    var kanal = spannweite(gruppe).kanal;
    gruppe.sort(function (a, b) {
      return anteil(a.farbe, kanal) - anteil(b.farbe, kanal);
    });
    var mitte = Math.floor(gruppe.length / 2);
    gruppen.splice(index, 1, gruppe.slice(0, mitte), gruppe.slice(mitte));
  }

  return gruppen.map(mittelwert);
}

function anteil(farbe, kanal) {
  return (farbe >> (16 - kanal * 8)) & 0xff;
}

function spannweite(gruppe) {
  var kanal = 0;
  var weite = -1;

  for (var k = 0; k < 3; k += 1) {
    var klein = 255;
    var gross = 0;
    for (var i = 0; i < gruppe.length; i += 1) {
      var wert = anteil(gruppe[i].farbe, k);
      if (wert < klein) klein = wert;
      if (wert > gross) gross = wert;
    }
    if (gross - klein > weite) {
      weite = gross - klein;
      kanal = k;
    }
  }

  return { kanal: kanal, weite: weite };
}

/** Mittelwert einer Gruppe, gewichtet danach, wie oft eine Farbe vorkommt. */
function mittelwert(gruppe) {
  var summe = [0, 0, 0];
  var anzahl = 0;

  for (var i = 0; i < gruppe.length; i += 1) {
    for (var k = 0; k < 3; k += 1) {
      summe[k] += anteil(gruppe[i].farbe, k) * gruppe[i].anzahl;
    }
    anzahl += gruppe[i].anzahl;
  }

  return (
    (Math.round(summe[0] / anzahl) << 16) |
    (Math.round(summe[1] / anzahl) << 8) |
    Math.round(summe[2] / anzahl)
  );
}

function abstandQuadrat(a, b) {
  var dr = anteil(a, 0) - anteil(b, 0);
  var dg = anteil(a, 1) - anteil(b, 1);
  var db = anteil(a, 2) - anteil(b, 2);
  return dr * dr + dg * dg + db * db;
}
