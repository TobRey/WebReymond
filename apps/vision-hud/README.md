# Vision HUD

Mobile Kamera-Oberfläche mit Live-Erkennung von Objekten und Gesichtern.
Personen lassen sich mit **Name und Alter** erfassen und werden danach
automatisch wiedererkannt. Die Darstellung ist ein dunkles Sci-Fi-HUD mit
türkisen Verfolgungsrahmen, schwebenden Beschriftungen und Prozentwerten.

Die Auswertung läuft **vollständig im Browser**. Es gibt keinen Server, keine
Datenbank und keine Verbindung zu fremden Diensten – auch nicht zum Laden der
Modelle. Damit ist die Seite auf jedem Webhosting-Paket lauffähig, das
statische Dateien ausliefern kann.

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
| Stimmung | `face_expression` | 321 kB | froh, neutral, überrascht … |

Beide Bibliotheken teilen sich **eine** TensorFlow.js-Instanz: `face-api.js`
bringt TF 4.22 mit und veröffentlicht sie als `faceapi.tf`; genau diese Instanz
reicht `engine.js` an COCO-SSD weiter. So läuft im Browser ein WebGL-Kontext
statt zwei.

---

## Aufbau

```
site/                     ← genau dieser Ordner wird hochgeladen
  index.html
  .htaccess               MIME-Typen, Kompression, https-Umleitung, Cache
  LIESMICH.txt            Upload-Anleitung für cPanel
  assets/
    css/hud.css
    js/
      app.js              Orchestrierung, beide Schleifen, Erfassung
      engine.js           Modelle laden, Bildauswertung
      tracker.js          Zuordnung über Bilder hinweg (IoU)
      hud.js              Zeichnen von Rahmen, Beschriftungen, Radar
      camera.js           getUserMedia, Kamerawechsel, Fehlertexte
      store.js            IndexedDB + Abgleich der Merkmalsvektoren
      ui.js               Meldungen, Schubladen, Einstellungen, Kartei
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
| Gesicht zuordnen | ~700 ms, **ein** Gesicht, 224-px-Ausschnitt | hoch |

Dazu kommen zwei getrennte Schleifen: Gezeichnet wird mit
`requestAnimationFrame` bei ~60 Bildern/s, gerechnet wird unabhängig davon.
Zwischen zwei Erkennungen gleiten die Rahmen auf ihre Zielposition zu
(`tracker.advance`), wodurch das Bild ruhig wirkt, obwohl die Erkennung nur
fünf- bis zehnmal pro Sekunde ein Ergebnis liefert.

Der dritte Punkt ist der wichtigste Hebel: Der Merkmalsvektor wird nicht auf
dem Vollbild berechnet, sondern auf einem 224 px grossen Ausschnitt rund um ein
einzelnes Gesicht – gemessen rund 40 % schneller und zugleich treffsicherer als
derselbe Durchlauf mit den Werten des Vollbild-Detektors.

Rechenwerk in dieser Reihenfolge: **WebGL** (Grafikeinheit) → **WASM** → reines
JavaScript. Die WASM-Dateien liegen in `assets/vendor/`.

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

- **Altersschätzung** stammt aus dem Modell und liegt oft mehrere Jahre daneben.
  Sie ist ein Vorschlag beim Erfassen; massgeblich ist das eingetragene Alter.
- **COCO-SSD** kennt genau 80 Klassen. Was nicht dazugehört, wird nicht erkannt.
- **Gegenlicht, starke Seitenansicht und Masken** verhindern die Zuordnung.
- Die Verfolgung ordnet über Überlappung zu. Kreuzen sich zwei Ziele dicht vor
  der Kamera, können Nummern tauschen.
- Erster Aufruf lädt ~28 MB. Danach liegen die Modelle im Browser-Cache
  (`.htaccess` setzt `immutable` für ein Jahr).

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
| COCO-SSD-Gewichte | `storage.googleapis.com/tfjs-models` | Apache-2.0 |
