# Vision HUD mit ReyRey

Mobile Kamera-Oberfläche mit Live-Erkennung von Objekten, Personen, Text und
Bewegung – dazu **ReyRey**, ein Assistent, der auf ein Aktivierungswort hört,
Fragen zum Bild beantwortet und die Funktionen der Seite bedient.

Die Auswertung läuft **im Browser**. Kein Server, keine Datenbank, und zum
Erkennen keine Verbindung zu fremden Diensten – auch die Modelle liegen in der
Auslieferung. Damit ist die Seite auf jedem Webhosting-Paket lauffähig, das
statische Dateien ausliefert.

Zwei Funktionen brauchen naturgemäss eine Verbindung nach draussen und sind
deshalb **standardmässig aus**: allgemeine Wissensfragen und Übersetzungen
(siehe [Was lokal läuft und was nicht](#was-lokal-läuft-und-was-nicht)).

---

## Schnellstart

```bash
pnpm --filter @webheaven/vision-hud vendor   # Bibliotheken und Modelle holen (~28 MB, einmalig)
pnpm --filter @webheaven/vision-hud dev      # http://localhost:4173/kamera-hud/
pnpm --filter @webheaven/vision-hud build    # dist/vision-hud-kamera-hud.zip zum Hochladen
```

Der Entwicklungsserver legt die Seite absichtlich in einen Unterordner, damit
schon lokal dieselben Pfade gelten wie später beim Hoster.

> `vendor` lädt rund 28 MB Fremdcode und Modellgewichte. Diese Dateien liegen
> bewusst **nicht** im Repository (siehe `.gitignore`); `vendor-inventory.json`
> hält Herkunft, Grösse und Prüfsumme jeder Datei fest.

---

## Was erkannt wird

| Bereich | Modell | Grösse | Ergebnis |
|---|---|---|---|
| Objekte | COCO-SSD `ssdlite_mobilenet_v2` | ~18 MB | 80 Klassen mit Box und Prozentwert |
| Gesichter finden | `tiny_face_detector` | 188 kB | Position und Sicherheit |
| Gesichter zuordnen | `face_recognition` | 6,3 MB | 128-stelliger Merkmalsvektor |
| Alter/Geschlecht | `age_gender` | 419 kB | Altersschätzung |
| Ausdruck | `face_expression` | 321 kB | grob: neutral, lächelnd, überrascht |
| Text | Tesseract (deu + eng) | ~7 MB | Wörter mit Position, erst auf Zuruf geladen |
| Bewegung | eigene Rechnung | – | Richtung und Annäherung je Ziel |
| Gegenstände | eigener Merkmalsvektor | – | eigene Dinge wiedererkennen |

## Was lokal läuft und was nicht

Diese Trennung ist der Kern des Entwurfs – und der Grund, warum manches nicht
so funktioniert, wie man es sich wünschen würde.

| Funktion | Wo es rechnet | Voreinstellung |
|---|---|---|
| Objekte, Personen, Gesichter, Bewegung, Gefahren | Gerät | an |
| Texterkennung, Vorlesen, Scan, Gedächtnis, Gesten | Gerät | an |
| Sprach**ausgabe** | Gerät | an |
| Sprach**eingabe** | **Browser-Hersteller** | **aus** |
| Allgemeine Fragen, Übersetzung | **Sprachmodell im Netz** | **aus** |

**Spracheingabe.** Die Web Speech API rechnet nicht auf dem Gerät: Chrome
schickt den Ton an Google, Safari an Apple. Das ist eine Eigenschaft des
Browsers – eine Webseite kann das nicht umgehen, ausser sie liefert ein
eigenes Spracherkennungsmodell von mehreren hundert Megabyte mit. Deshalb ist
das Zuhören aus, bis der Nutzer einer Erklärung ausdrücklich zustimmt. Firefox
kennt die Schnittstelle gar nicht; dort wird die Frage getippt.

**Allgemeines Wissen.** Für „Was ist das für eine Marke?“ braucht es ein
Sprachmodell. Es gibt keines, das sich in eine statische Seite packen liesse.
`cloud.js` spricht deshalb wahlweise über einen PHP-Vermittler auf dem eigenen
Webspace (`reyrey-proxy.php`, Schlüssel bleibt serverseitig) oder direkt mit
der Claude-API. Übertragen wird **nur Text**: die Frage und ein Satz darüber,
was die Erkennung sieht – nie das Bild, nie ein Gesichtsabdruck, nie ein
gespeicherter Name.

## ReyRey

Standardmässig hört ReyRey auf **„ReyRey“**; Name und Aktivierungswort sind in
den Einstellungen frei wählbar. Weil die Erkennung „ReyRey“ je nach Aussprache
als „Rey Rey“, „Rei Rei“ oder „Ray Ray“ versteht, erzeugt `voice.js` aus dem
eingestellten Wort klangähnliche Varianten – ohne das reagiert das Wort
gefühlt nie.

Nach einer Antwort bleibt das Mikrofon einige Sekunden offen, sodass man
nachfragen kann, ohne den Namen zu wiederholen. Während der Sprachausgabe
pausiert die Erkennung, damit ReyRey nicht auf die eigene Stimme reagiert.

Befehle laufen über eine Mustertabelle in `assistant.js` und rufen Fähigkeiten
auf, die `app.js` bereitstellt: beschreiben, zählen, scannen, vorlesen,
übersetzen, Personen und Gegenstände erfassen, wiederfinden, Einstellungen
umschalten. Bleibt eine Frage übrig, die nur mit Weltwissen zu beantworten
ist, geht sie – falls freigegeben – nach draussen; sonst sagt ReyRey, dass er
das auf dem Gerät nicht kann.

Alles, was sich nicht rückgängig machen lässt, wird zurückgefragt: Löschen,
Sichern der Kartei und jedes Erfassen einer Person.

## Gesten ohne Handmodell

Die üblichen Handmodelle (MediaPipe Hands, TensorFlow handpose) liegen auf
`tfhub.dev` und lassen sich nicht mitliefern. `gestures.js` verwendet deshalb
die Bildbewegung: Das Kamerabild wird auf 48×36 Graustufen verkleinert und mit
dem vorigen Bild verglichen; aus den geänderten Pixeln entsteht ein
Schwerpunkt. Wandert er schnell und weit genug, ist das ein Wisch.

Erkannt werden Wischen (vier Richtungen), Winken und Abdecken der Kamera. Das
ist **keine Fingererkennung** – „Daumen hoch“ geht damit nicht. Dafür kostet es
fast nichts und läuft auf jedem Gerät. Jede Geste ist frei belegbar.

Beide Bibliotheken teilen sich **eine** TensorFlow.js-Instanz: `face-api.js`
bringt TF 4.22 mit und veröffentlicht sie als `faceapi.tf`; genau diese Instanz
reicht `engine.js` an COCO-SSD weiter. So läuft im Browser ein WebGL-Kontext
statt zwei.

---

## Aufbau

```
site/                     ← genau dieser Ordner wird hochgeladen
  index.html
  pruefung.html           Selbsttest: prüft jede Datei einzeln
  sw.js                   Service Worker (PWA, Offline-Betrieb)
  reyrey-proxy.php        freiwillig: hält den API-Schlüssel serverseitig
  .htaccess               MIME-Typen, Kompression, https-Umleitung, Cache
  LIESMICH.txt            Upload-Anleitung für cPanel
  assets/
    css/hud.css
    js/
      app.js              Orchestrierung, beide Schleifen, Fähigkeiten
      engine.js           Modelle laden, Bildauswertung
      tracker.js          Zuordnung über Bilder hinweg (IoU)
      motion.js           Geschwindigkeit und Richtung je Ziel
      hazard.js           Gefahren-Faustregeln, Sprechsperre
      hud.js              Rahmen, Beschriftungen, Pfeile, Radar, Scanbalken
      camera.js           getUserMedia, Kamerawechsel, Wiederanlauf
      store.js            IndexedDB, Merkmalsabgleich, Verschlüsselung
      memory.js           Objektgedächtnis mit eigenem Merkmalsvektor
      privacy.js          Tresor (PBKDF2 + AES-GCM), Löschfunktion
      assistant.js        ReyRey: Absichten, Kontext, Antworten
      voice.js            Zuhören mit Aktivierungswort, Sprachausgabe
      gestures.js         Gesten über Bildbewegung
      ocr.js              Texterkennung (lädt Tesseract bei Bedarf)
      translate.js        Übersetzung über freigegebenen Dienst
      cloud.js            Claude-API über Proxy oder direkt
      scan.js             vollständige Szenenanalyse
      ui.js               Meldungen, Schubladen, Einstellungen, Rückfragen
      config.js           sämtliche Stellschrauben
      labels.js           deutsche Namen der 80 COCO-Klassen
    vendor/               Fremdcode (wird von `vendor` erzeugt)
    models/               Modellgewichte (wird von `vendor` erzeugt)
tools/
  vendor.mjs              lädt Bibliotheken und Gewichte
  build-zip.mjs           baut das Archiv für cPanel
  serve.mjs               Entwicklungsserver im Unterordner
  check.mjs               Prüfung ohne Browser (`pnpm test`)
```

---

## Warum es auf dem Handy flüssig läuft

Ein Telefon schafft keine vollständige Auswertung pro Bild. Die Arbeit ist
deshalb in drei Stufen mit unterschiedlichem Takt zerlegt:

| Stufe | Takt | Aufwand |
|---|---|---|
| Objekte erkennen | ~140 ms, 480 px breites Arbeitsbild | mittel |
| Gesichter finden | ~120 ms, nur Positionen | gering |
| Gesten | ~90 ms, 48×36 Graustufen | sehr gering |
| Gesicht zuordnen | ~700 ms, **ein** Gesicht, 224-px-Ausschnitt | hoch |
| Gegenstand abgleichen | ~1400 ms, **ein** Ziel | gering |
| Texterkennung, Scan | nur auf Zuruf | sehr hoch |

Dazu kommen zwei getrennte Schleifen: Gezeichnet wird mit
`requestAnimationFrame` bei ~60 Bildern/s, gerechnet wird unabhängig davon.
Zwischen zwei Erkennungen gleiten die Rahmen auf ihre Zielposition zu
(`tracker.advance`), wodurch das Bild ruhig wirkt, obwohl die Erkennung nur
fünf- bis zehnmal pro Sekunde ein Ergebnis liefert.

Der dritte Punkt ist der wichtigste Hebel: Der Merkmalsvektor wird nicht auf
dem Vollbild berechnet, sondern auf einem 224 px grossen Ausschnitt rund um ein
einzelnes Gesicht – gemessen rund 40 % schneller und zugleich treffsicherer als
derselbe Durchlauf mit den Werten des Vollbild-Detektors.

Texterkennung und Scan laufen bewusst **ausserhalb** dieses Takts: Sie dauern
ein bis drei Sekunden und würden jede Schleife sprengen. Deshalb nur auf Zuruf.

Rechenwerk in dieser Reihenfolge: **WebGL** (Grafikeinheit) → **WASM** → reines
JavaScript. Die WASM-Dateien liegen in `assets/vendor/`.

## Privatsphäre

| Regler | Wirkung |
|---|---|
| Privatmodus | Keine Namen im Bild, nichts wird gespeichert, kein Text an den Scan |
| Verschlüsselung | Kennwort → PBKDF2 (210 000 Runden) → AES-GCM über die ganze Kartei |
| Alles löschen | Beide Datenbanken, alle Einstellungen, der PWA-Zwischenspeicher |

Die Verschlüsselung hängt an einem Kennwort, das nur der Nutzer kennt – nicht
an einem automatisch erzeugten Schlüssel daneben. Eine Webseite hat keinen Ort,
an dem sie einen Schlüssel vor jemandem verstecken könnte, der das entsperrte
Gerät in der Hand hält; ein solcher Schlüssel wäre reine Zierde. Der Preis ist
ehrlich benannt: Wer das Kennwort vergisst, verliert die Kartei.

Ohne Kennwort wird **nicht** verschlüsselt, und die Oberfläche sagt das – statt
Sicherheit vorzutäuschen.

Jede Aktion, die sich nicht rückgängig machen lässt, wird zurückgefragt:
Personen erfassen (mit einem Hinweis auf DSGVO Art. 9), löschen, Kartei sichern
(die Sicherungsdatei ist unverschlüsselt – auch das steht dort).

---

## Wiedererkennung

Beim Erfassen werden fünf Aufnahmen desselben Gesichts gespeichert, was
gegenüber einer einzelnen Aufnahme deutlich robuster gegen Kopfhaltung und
Licht ist. Verglichen wird über den euklidischen Abstand zweier
128-Merkmal-Vektoren:

| Abstand | Bedeutung |
|---|---|
| 0,00 – 0,35 | sehr sicher dieselbe Person |
| bis 0,52 | gilt als erkannt (Standardschwelle, einstellbar) |
| ab ~0,60 | gemessen typischer Abstand zwischen zwei **verschiedenen** Personen |

Bei Verwechslungen die Schwelle unter „System → Wiedererkennung“ verkleinern.

Der angezeigte Prozentwert ist ein **Anzeigewert, keine Wahrscheinlichkeit**:
Abstand 0 ergibt 100 %, die Schwelle ergibt 58 % (siehe `confidenceFrom` in
`app.js`).

Gespeichert wird in IndexedDB des jeweiligen Geräts, mit automatischem
Rückfall auf `localStorage`. Sichern und Einlesen als JSON über die Kartei.

---

## Hochladen

`pnpm --filter @webheaven/vision-hud build` erzeugt
`dist/vision-hud-kamera-hud.zip`. Das Archiv enthält **einen** Ordner
(`kamera-hud/`). In `public_html` hochladen und dort entpacken – die Seite
landet dann von selbst in einem Unterordner und rührt die bestehende Website
nicht an. Vollständige Anleitung: `site/LIESMICH.txt`.

**Die Seite braucht https://** – Browser geben die Kamera nur über eine
verschlüsselte Verbindung frei. Bei GoDaddy: cPanel → „SSL/TLS Status“ →
AutoSSL ausführen.

Anderer Ordnername:

```bash
node tools/build-zip.mjs hud     # erzeugt dist/vision-hud-hud.zip mit Ordner hud/
```

---

## Prüfen

```bash
pnpm --filter @webheaven/vision-hud test
```

`check.mjs` prüft ohne Browser genau das, was beim Verschieben in einen
Unterordner bricht: absolute Pfade (`/assets/…`), fehlende referenzierte
Dateien, Syntaxfehler in den Modulen und Modellgewichte, die in ihrem Manifest
stehen, aber fehlen.

---

## Grenzen

Ehrlich aufgezählt, weil jede davon im Betrieb auffällt:

- **Gefahrenhinweise sind keine Abstandsmessung.** Eine einzelne Kamera kennt
  keine Entfernung. „Nah“ heisst hier „gross im Bild“ – ein Lastwagen in
  fünfzig Metern ist grösser als ein Fahrrad in fünf. Jeder Hinweis ist in der
  Oberfläche als unverbindlich gekennzeichnet.
- **Gesichtsausdrücke** werden bewusst mit Fragezeichen angezeigt. Das Modell
  ordnet Muskelstellungen zu, keine Gefühle. „Lächelnd“ heisst nicht „froh“.
- **Altersschätzung** liegt oft mehrere Jahre daneben. Sie ist ein Vorschlag
  beim Erfassen; angezeigt wird das eingetragene Alter.
- **Gesten sind Bewegungsgesten**, keine Fingererkennung (siehe oben).
- **Das Objektgedächtnis** unterscheidet deinen roten Rucksack zuverlässig von
  einem Stuhl, aber nicht von einem zweiten baugleichen roten Rucksack.
- **COCO-SSD** kennt genau 80 Klassen. Was nicht dazugehört, wird nicht erkannt.
- **Gegenlicht, starke Seitenansicht und Masken** verhindern die Zuordnung.
- Die Verfolgung ordnet über Überlappung zu. Kreuzen sich zwei Ziele dicht vor
  der Kamera, können Nummern tauschen.
- **Texterkennung** braucht ruhige, kontrastreiche Schrift. Handschrift und
  schräge Schilder gehen selten gut.
- Erster Aufruf lädt ~34 MB. Danach liegen die Modelle im Browser-Cache und im
  Service Worker.

## Wenn das Objektmodell nicht lädt

Der häufigste Fehler beim Hochladen – und er hat eine unauffällige Ursache:

Die Gewichtsdateien von COCO-SSD heissen im Original `group1-shard1of5`,
**ohne Dateiendung**. Auf gewöhnlichem Webhosting blockieren mod_security und
ähnliche Schutzregeln endungslose Dateien regelmässig mit 403 oder 404. Die
Gesichtsmodelle mit ihrer `.bin`-Endung kommen durch, das Objektmodell nicht –
die Seite läuft dann scheinbar, zeigt aber nur Gesichtsrahmen.

`tools/vendor.mjs` gibt jeder Gewichtsdatei deshalb eine `.bin`-Endung und
schreibt die Pfade im Manifest um. TensorFlow.js lädt schlicht die Pfade, die
im Manifest stehen.

Bei Problemen: **`pruefung.html`** im selben Ordner öffnen. Die Seite fragt
jede einzelne Datei ab und nennt Pfad und HTTP-Status.

## Datenschutz

Bilder und Merkmalsvektoren verlassen das Gerät nicht. Gesichtsmerkmale sind
biometrische Daten: Wer Dritte erfasst, braucht deren Einverständnis
(DSGVO Art. 9, revDSG Art. 5). Für den privaten Gebrauch am eigenen Gerät
greift die Haushaltsausnahme.

## Herkunft des Fremdcodes

| Paket | Version | Lizenz |
|---|---|---|
| [@vladmandic/face-api](https://github.com/vladmandic/face-api) | 1.7.15 | MIT |
| [@tensorflow-models/coco-ssd](https://github.com/tensorflow/tfjs-models) | 2.2.3 | Apache-2.0 |
| [@tensorflow/tfjs-backend-wasm](https://github.com/tensorflow/tfjs) | 4.22.0 | Apache-2.0 |
| [tesseract.js](https://github.com/naptha/tesseract.js) | 7.0.0 | Apache-2.0 |
| COCO-SSD-Gewichte | `storage.googleapis.com/tfjs-models` | Apache-2.0 |
| Tesseract-Sprachdaten | `tessdata_fast` (deu, eng) | Apache-2.0 |
