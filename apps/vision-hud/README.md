# Vision HUD mit ReyRey

Mobile Kamera-Oberfläche mit Live-Erkennung von Objekten (601 Klassen plus
Zweitstufe mit 1000), Personen, Text, Handzeichen und Bewegung – dazu
**ReyRey**, ein Assistent, der von selbst zuhört, auf sein Aktivierungswort
reagiert, Fragen zum Bild beantwortet und die Seite bedient. Neue Handzeichen
und „Skills“ (Gruppen wie „Bildschirme und elektrische Geräte“) legt man im
Gespräch an; sie wirken sofort.

Die Auswertung läuft **im Browser**. Kein Server, keine Datenbank, und zum
Erkennen keine Verbindung zu fremden Diensten – auch die Modelle liegen in der
Auslieferung. Damit ist die Seite auf jedem Webhosting-Paket lauffähig, das
statische Dateien ausliefert.

Zwei Funktionen brauchen naturgemäss eine Verbindung nach draussen und sind
deshalb **standardmässig aus**: allgemeine Wissensfragen und Übersetzungen.
Eine dritte – die Spracheingabe – läuft über den Browser-Hersteller und ist
an, weil die Seite sonst nicht „einfach zuhört“ (siehe
[Was lokal läuft und was nicht](#was-lokal-läuft-und-was-nicht)).

---

## Schnellstart

```bash
pnpm --filter @webheaven/vision-hud vendor   # Bibliotheken und Modelle holen (~48 MB, einmalig)
pnpm --filter @webheaven/vision-hud dev      # http://localhost:4173/kamera-hud/
pnpm --filter @webheaven/vision-hud build    # dist/vision-hud-kamera-hud.zip zum Hochladen
pnpm --filter @webheaven/vision-hud test     # Prüfung ohne Browser
```

Der Entwicklungsserver legt die Seite absichtlich in einen Unterordner, damit
schon lokal dieselben Pfade gelten wie später beim Hoster.

> `vendor` lädt Fremdcode und Modellgewichte aus dem Netz und kopiert die im
> Repository liegenden, konvertierten Netze aus `models/`. Was heruntergeladen
> wird, liegt bewusst **nicht** im Repository (siehe `.gitignore`);
> `vendor-inventory.json` hält Herkunft, Grösse und Prüfsumme jeder Datei fest.

---

## Was erkannt wird

| Bereich | Modell | Grösse | Ergebnis |
|---|---|---|---|
| Objekte | YOLOv8-nano, Open Images V7 | 7,0 MB (fp16) | **601 Klassen** – von Akkordeon bis Zucchini – mit Box und Prozentwert; alles gleichzeitig, nicht nur die Bildmitte |
| Zweitstufe | YOLOv8-nano-cls, ImageNet | 5,4 MB (fp16) | **1000 Klassen** auf einzelnen Ausschnitten: aus „Karton“ wird „Paket“, aus „Haushaltsgerät“ „Ventilator“ |
| Hände | MediaPipe Gesture Recognizer | 8 MB + 11,5 MB WASM | 21 Landmarken, 7 eingebaute Zeichen (Faust, offene Hand, Peace, Daumen hoch/runter, Zeigefinger, „I love you“) und beliebig viele eigene |
| Gesichter finden | `tiny_face_detector` | 188 kB | Position und Sicherheit |
| Gesichter zuordnen | `face_recognition` | 6,3 MB | 128-stelliger Merkmalsvektor |
| Alter/Geschlecht | `age_gender` | 419 kB | Altersschätzung |
| Ausdruck | `face_expression` | 321 kB | grob: neutral, lächelnd, überrascht |
| Text | Tesseract (deu + eng) | ~7 MB | Wörter mit Position, im Hintergrund bei ruhiger Kamera; Lupe liest 3× vergrössert |
| Bewegung | eigene Rechnung | – | Richtung und Annäherung je Ziel |
| Gegenstände | eigener Merkmalsvektor | – | eigene Dinge wiedererkennen |

**Sicher und unsicher.** Ab 35 % Sicherheit zeichnet das HUD einen vollen
Rahmen mit Namen, zwischen 12 % und 35 % einen blassen, gestrichelten Rahmen
mit „?“. So sieht man, dass da etwas ist, ohne dass die Seite so tut, als
wüsste sie was. Die Zweitstufe gibt vielen dieser „?“-Rahmen doch noch einen
Namen. Abschaltbar („Unsicheres zeigen“ oder per Sprache „nur Sicheres“).

**Was nicht drin ist.** Keines der beiden Netze kennt *Zigarette* oder
*Teppich* als Klasse. Das Nächste sind „Feuerzeug“ und „Gebetsteppich“ bzw.
„Fussmatte“. Wer solche Dinge braucht, braucht ein anderes Modell – ein
Wortlisten-Trick hilft da nicht, und ich behaupte es deshalb auch nicht.

## Was lokal läuft und was nicht

| Funktion | Wo es rechnet | Voreinstellung |
|---|---|---|
| Objekte, Zweitstufe, Personen, Gesichter, Hände, Bewegung, Gefahren | Gerät | an |
| Texterkennung, Lupe, Vorlesen, Scan, Gedächtnis, Skills | Gerät | an |
| Sprach**ausgabe** | Gerät | an |
| Sprach**eingabe** | **Browser-Hersteller** | **an** (steht auf dem Startbildschirm) |
| Allgemeine Fragen, Übersetzung, KI-Zuordnung für Skills | **Sprachmodell im Netz** | **aus** |

**Spracheingabe.** Die Web Speech API rechnet nicht auf dem Gerät: Chrome
schickt den Ton an Google, Safari an Apple. Das ist eine Eigenschaft des
Browsers – eine Webseite kann das nicht umgehen, ausser sie liefert ein
eigenes Spracherkennungsmodell von mehreren hundert Megabyte mit. Der
Startbildschirm sagt das in einem Satz; wer nicht will, schaltet das Zuhören
mit einem Tipp auf die Kugel aus. Firefox kennt die Schnittstelle gar nicht;
dort wird die Frage getippt.

**Allgemeines Wissen.** Für „Was ist das für eine Marke?“ braucht es ein
Sprachmodell. `cloud.js` spricht wahlweise über einen PHP-Vermittler auf dem
eigenen Webspace (`reyrey-proxy.php`, Schlüssel bleibt serverseitig) oder
direkt mit der Claude-API. Übertragen wird **nur Text**: die Frage und ein Satz
darüber, was die Erkennung sieht – nie das Bild, nie ein Gesichtsabdruck, nie
ein gespeicherter Name. Bei Skills zusätzlich die gesprochene Beschreibung und
die Liste der Klassennamen, damit das Modell die passenden auswählen kann.

## Autostart

Beim ersten Besuch gibt ein Tipp auf „Starten“ Kamera **und** Mikrofon in
einem Zug frei (`getUserMedia({video, audio})`; die Tonspur wird sofort wieder
losgelassen, sie diente nur der Freigabe). Ab dem zweiten Besuch fragt
`app.js` die Permissions API; steht die Kamera auf „granted“, startet alles
ohne Klick, Zuhören inklusive.

Damit Safari sich die Freigabe merkt, muss sie einmal auf **Erlauben** stehen:
aA-Menü → Website-Einstellungen → Kamera und Mikrofon → Erlauben. Steht sie
auf „Fragen“, bleibt der Startknopf – ein Tipp, dann läuft es.

Zwei iOS-Eigenheiten, ehrlich benannt:

- Die **Sprachausgabe** darf erst nach der ersten Berührung der Seite spielen.
  Zuhören und Sprechblase gehen sofort; bis zum ersten Tipp steht „Tippen für
  Ton“ an der Kugel, die erste Antwort wird nachgesprochen.
- Die Spracherkennung bricht in Safari alle 20–60 Sekunden ab. `voice.js`
  startet sie neu, mit wachsender Pause bei schnellen Abbrüchen. Verweigert
  iOS den Start ohne Berührung, steht „Tippen zum Zuhören“ an der Kugel; die
  Einstellung bleibt an, beim nächsten Besuch wird es wieder versucht.

## ReyRey

Standardmässig hört ReyRey auf **„ReyRey“**; Name und Aktivierungswort sind in
den Einstellungen frei wählbar. Weil die Erkennung „ReyRey“ je nach Aussprache
als „Rey Rey“, „Rei Rei“ oder „Ray Ray“ versteht, erzeugt `voice.js` aus dem
eingestellten Wort klangähnliche Varianten.

Nach einer Antwort bleibt das Mikrofon einige Sekunden offen, sodass man
nachfragen kann, ohne den Namen zu wiederholen. Während der Sprachausgabe
pausiert die Erkennung, damit ReyRey nicht auf die eigene Stimme reagiert.

Befehle laufen über eine Mustertabelle in `assistant.js` und rufen Fähigkeiten
auf, die `app.js` bereitstellt: beschreiben, zählen, „was ist das?“ (mit
Zweitstufen-Antwort: „Das ist vermutlich ein Paket, 62 Prozent“), Uhrzeit,
scannen, vorlesen, Lupe, übersetzen, Personen und Gegenstände erfassen,
wiederfinden, Einstellungen umschalten. Alles, was sich nicht rückgängig
machen lässt, wird zurückgefragt.

### Handzeichen anlegen – im Gespräch

```
Du:      ReyRey, erfasse neues Handzeichen
ReyRey:  Zeig das Zeichen in die Kamera und halt es zwei Sekunden ruhig.
         (Ring um die Hand füllt sich – 22 Bilder werden gemittelt)
ReyRey:  Aufgenommen. Welche Funktion soll dieses Zeichen auslösen?
Du:      Objekterkennung umschalten, also an oder aus
ReyRey:  Fertig. Zeichen 1 löst ab jetzt „Objekterkennung umschalten“ aus.
```

Das Zeichen wirkt **sofort**, ohne Upload und ohne neuen Code: Ein Handzeichen
ist ein gespeicherter Fingerabdruck der Hand (49 Zahlen: 42 normalisierte
Landmarken-Koordinaten, fünf Finger-gestreckt-Werte und die Richtung der Hand
im Bild), ein Skill eine Liste von Klassen mit Farbe. Beides liegt in IndexedDB (`visionhud-skills`).

Erkannt wird ein Zeichen, wenn es **0,8 s ruhig gehalten** wird (der Ring um
die Hand füllt sich) – sonst würde jede Handbewegung etwas auslösen. Danach
1,5 s Sperre. Eigene Zeichen werden über Kosinus-Ähnlichkeit (≥ 0,92) gegen
die gespeicherten Vektoren verglichen und schlagen die eingebauten. Die
eingebauten Zeichen sind unter System → Handzeichen frei belegbar.

### Skills – „ab jetzt erkennst du …“

```
Du:      ReyRey, ab jetzt erkennst du Bildschirme und elektrische Geräte
ReyRey:  Ich nehme dafür: Fernseher, Monitor, Laptop, Ventilator, Mikrowelle,
         Toaster und 61 weitere. Passt das?
Du:      Ja
ReyRey:  Skill „Bildschirme Und Elektrische Geräte“ ist aktiv.
```

Die Zuordnung läuft lokal über eine Synonymtabelle (`skills.js` →
`classesFor`) auf die Gruppen der beiden Klassentabellen (screen, appliance,
electronics, vehicle, food, furniture, tool …) und direkte Klassennamen. Ist
der KI-Dienst freigegeben, darf Claude bei unklaren Beschreibungen die
passenden Klassen aus der Liste auswählen. Treffer eines Skills bekommen dessen
Farbe und Etikett, ReyRey sagt neu auftauchende an (höchstens alle 6 s je
Skill). Skills lassen sich per Sprache an- und ausschalten, auflisten und
löschen – oder unter System → Skills.

**Was dieses „Selbst-Programmieren“ ist und was nicht:** Handzeichen und
Skills sind Daten, die die laufende Seite anlegt. Echter neuer Code kommt aus
diesem Repository und wird über GitHub Actions automatisch hochgeladen
(siehe unten). Die Seite schreibt keinen Code in sich selbst – das wäre
langsamer, fehleranfälliger und nicht sicherer als das, was sie tut.

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
      app.js              Orchestrierung, beide Schleifen, Fähigkeiten, Aktionen
      engine.js           TF.js-Instanz, Gesichtsmodelle, Stufen der Auswertung
      detector.js         YOLOv8 (601 Klassen): Letterbox, Netz, NMS, zwei Schwellen
      classifier.js       Zweitstufe (1000 Klassen) auf Ausschnitten, refine()
      hands.js            MediaPipe-Hände, eingebaute und eigene Zeichen, Halten
      skills.js           Aktionsliste, Beschreibung → Klassen, Skill-Ablage
      labels-oiv7.js      601 Klassen: englisch, deutsch, Gruppe
      labels-imagenet.js  1000 Klassen: deutsch, Gruppe
      labels.js           Stimmungen, Artikel („eine Lampe“)
      tracker.js          Zuordnung über Bilder hinweg (IoU), Label-Abstimmung
      motion.js           Geschwindigkeit und Richtung je Ziel
      hazard.js           Gefahren-Faustregeln, Sprechsperre
      hud.js              Rahmen (sicher/unsicher/Skill), Pfeile, Hand, Textblöcke, Radar
      camera.js           getUserMedia (Kamera + Mikrofon), Kamerawechsel, Wiederanlauf
      store.js            IndexedDB, Merkmalsabgleich, Verschlüsselung
      memory.js           Objektgedächtnis mit eigenem Merkmalsvektor
      privacy.js          Tresor (PBKDF2 + AES-GCM), Löschfunktion
      assistant.js        ReyRey: Absichten, geführte Dialoge, Antworten
      voice.js            Zuhören mit Aktivierungswort, Neustart-Schutz, Sprachausgabe
      ocr.js              Texterkennung im Hintergrund, Stillstand, Lupe
      translate.js        Übersetzung über freigegebenen Dienst
      cloud.js            Claude-API über Proxy oder direkt, Klassenzuordnung
      scan.js             vollständige Szenenanalyse
      ui.js               Meldungen, Schubladen, Einstellungen, Uhr, Lupe, Listen
      config.js           sämtliche Stellschrauben
    vendor/               Fremdcode (wird von `vendor` erzeugt)
    models/               Modellgewichte (wird von `vendor` erzeugt)
models/
  detector/               YOLOv8n-oiv7 als TF.js-Graphmodell (im Repository, 7 MB)
  classifier/             YOLOv8n-cls als TF.js-Graphmodell (im Repository, 5,4 MB)
tools/
  vendor.mjs              lädt Bibliotheken und Gewichte, kopiert models/
  export-models.md        wie die beiden Netze konvertiert wurden (wiederholbar)
  build-zip.mjs           baut das Archiv für cPanel
  serve.mjs               Entwicklungsserver im Unterordner
  check.mjs               Prüfung ohne Browser (`pnpm test`)
../../.github/workflows/deploy-vision-hud.yml   automatischer Upload per FTP
```

Alle Netze teilen sich **eine** TensorFlow.js-Instanz: `face-api.js` bringt
TF 4.22 mit und veröffentlicht sie als `faceapi.tf`; Detektor und Zweitstufe
laufen darauf als Graphmodelle. Ein WebGL-Kontext statt drei. MediaPipe
bringt sein eigenes WASM mit und nutzt WebGL für die Handerkennung getrennt.

---

## Warum es auf dem Handy flüssig läuft

Ein Telefon schafft keine vollständige Auswertung pro Bild. Die Arbeit ist
deshalb in Stufen mit unterschiedlichem Takt zerlegt (Werte für iPhone 15/16
in `config.js`):

| Stufe | Takt | Aufwand |
|---|---|---|
| Objekte erkennen (601) | ~110 ms, 512×512 Letterbox | mittel |
| Gesichter finden | ~120 ms, nur Positionen | gering |
| Hände | ~80 ms, MediaPipe auf der Grafikeinheit | gering |
| Zweitstufe (1000) | ~260 ms, **ein** Ausschnitt 224 px, Ergebnis 6 s gültig | mittel |
| Gesicht zuordnen | ~700 ms, **ein** Gesicht, 224-px-Ausschnitt | hoch |
| Gegenstand abgleichen | ~1400 ms, **ein** Ziel | gering |
| Text im Hintergrund | alle 2,5 s, nur bei ruhiger Kamera, im Arbeiter | hoch, aber getrennt |
| Scan, Lupe | auf Zuruf bzw. Tipp | sehr hoch |

Gezeichnet wird mit `requestAnimationFrame` bei ~60 Bildern/s, gerechnet wird
unabhängig davon. Zwischen zwei Erkennungen gleiten die Rahmen auf ihre
Zielposition zu (`tracker.advance`). Mit 601 Klassen wechselt der Detektor
gern zwischen Nachbarn („Karton“, „Kiste“); der Tracker lässt deshalb jede
Erkennung über den Namen abstimmen und zeigt die Mehrheit – der Name springt
nicht mehr bei jedem Bild.

Die Texterkennung läuft nur, wenn `Stillness` weniger als 4 % geänderte Pixel
zwischen zwei Bildern misst: Bewegtes Bild ergibt bei Tesseract nur Salat. Die
Lupe skaliert den Ausschnitt 3× hoch, schärft nach und liest ihn erneut –
kleine Schrift scheitert nicht am Erkenner, sondern an den wenigen Pixeln pro
Buchstabe.

Rechenwerk in dieser Reihenfolge: **WebGL** (Grafikeinheit) → **WASM** → reines
JavaScript.

## Privatsphäre

| Regler | Wirkung |
|---|---|
| Privatmodus | Keine Namen im Bild, nichts wird gespeichert (auch keine Handzeichen und Skills), kein Text im Hintergrund |
| Verschlüsselung | Kennwort → PBKDF2 (210 000 Runden) → AES-GCM über die ganze Kartei |
| Alles löschen | Alle drei Datenbanken, alle Einstellungen, der PWA-Zwischenspeicher |

Die Verschlüsselung hängt an einem Kennwort, das nur der Nutzer kennt. Eine
Webseite hat keinen Ort, an dem sie einen Schlüssel vor jemandem verstecken
könnte, der das entsperrte Gerät in der Hand hält. Wer das Kennwort vergisst,
verliert die Kartei – das steht auch so in der Oberfläche.

Jede Aktion, die sich nicht rückgängig machen lässt, wird zurückgefragt:
Personen erfassen (mit einem Hinweis auf DSGVO Art. 9), löschen, Kartei
sichern, alles löschen.

---

## Wiedererkennung

Beim Erfassen werden fünf Aufnahmen desselben Gesichts gespeichert. Verglichen
wird über den euklidischen Abstand zweier 128-Merkmal-Vektoren:

| Abstand | Bedeutung |
|---|---|
| 0,00 – 0,35 | sehr sicher dieselbe Person |
| bis 0,52 | gilt als erkannt (Standardschwelle, einstellbar) |
| ab ~0,60 | typischer Abstand zwischen zwei **verschiedenen** Personen |

Der angezeigte Prozentwert ist ein **Anzeigewert, keine Wahrscheinlichkeit**:
Abstand 0 ergibt 100 %, die Schwelle ergibt 58 % (`confidenceFrom` in `app.js`).

---

## Hochladen

### Von Hand (ZIP)

`pnpm --filter @webheaven/vision-hud build` erzeugt
`dist/vision-hud-kamera-hud.zip`. Das Archiv enthält **einen** Ordner
(`kamera-hud/`). In `public_html` hochladen und dort entpacken. Vollständige
Anleitung: `site/LIESMICH.txt`.

**Die Seite braucht https://** – Browser geben Kamera und Mikrofon nur über
eine verschlüsselte Verbindung frei.

### Automatischer Upload (GitHub Actions → FTP → cPanel)

`.github/workflows/deploy-vision-hud.yml` lädt `site/` per FTP hoch, sobald
eine Änderung an `apps/vision-hud/**` auf `main` landet – oder von Hand über
„Run workflow“ (dort lässt sich auch ein anderer Branch wählen). Der Lauf holt
Fremdcode und Modelle (`vendor.mjs`), prüft die Auslieferung (`check.mjs`) und
überträgt nur geänderte Dateien.

Zugangsdaten stehen **nicht** im Repository, sondern unter
Settings → Secrets and variables → Actions:

| Art | Name | Wert |
|---|---|---|
| Variable | `FTP_HOST` | FTP-Server, meist `ftp.deine-domain.tld` (cPanel → FTP-Konten → Konfigurieren) |
| Variable | `FTP_USERNAME` | das FTP-Konto, z. B. `claude@deine-domain.tld` |
| Secret | `FTP_PASSWORD` | dessen Passwort – legst **du** an, es geht durch niemandes Hände |
| Variable | `FTP_SERVER_DIR` | `/`, wenn das FTP-Konto direkt im Zielordner wurzelt (so legt cPanel Unterkonten an); sonst der Pfad ab der FTP-Wurzel |
| Variable | `FTP_PROTOCOL` | freiwillig; `ftps` (Vorgabe) oder `ftp`, falls TLS beim Hoster scheitert |

Ob das Konto im richtigen Ordner wurzelt, zeigt cPanel unter FTP-Konten in der
Spalte „Verzeichnis“. Nach dem ersten Lauf: `https://…/pruefung.html` öffnen.

Die Zustandsdatei `.vision-hud-deploy-state.json`, die die Aktion auf dem
Server ablegt, wird von der `.htaccess` gegen Abruf gesperrt.

---

## Prüfen

```bash
pnpm --filter @webheaven/vision-hud test
```

`check.mjs` prüft ohne Browser: absolute Pfade (`/assets/…`), fehlende
referenzierte Dateien, Syntaxfehler in den Modulen, Modellgewichte, die im
Manifest stehen, aber fehlen oder keine Dateiendung haben, und Reste des
entfernten COCO-Modells.

---

## Grenzen

- **Zigarette, Teppich** und Ähnliches sind in keinem der Modelle. Siehe oben.
- **Gefahrenhinweise sind keine Abstandsmessung.** „Nah“ heisst „gross im
  Bild“. Jeder Hinweis ist als unverbindlich gekennzeichnet.
- **Gesichtsausdrücke** werden bewusst mit Fragezeichen angezeigt.
- **Altersschätzung** liegt oft mehrere Jahre daneben.
- **Handzeichen** brauchen eine sichtbare Hand mit Fingern – aus der eigenen
  Perspektive (POV) funktioniert das gut, im Gegenlicht schlecht. Eigene
  Zeichen, die einem eingebauten sehr ähneln, gewinnen zwar, aber knapp.
- **Das Objektgedächtnis** unterscheidet deinen roten Rucksack von einem
  Stuhl, aber nicht von einem zweiten baugleichen roten Rucksack.
- **Texterkennung** braucht ruhige, kontrastreiche Schrift. Die Lupe hilft
  bei kleiner Schrift, nicht bei Handschrift.
- **iOS**: Sprachausgabe erst nach dem ersten Tipp; Spracherkennung mit
  Abbrüchen; die Freigabe muss auf „Erlauben“ stehen, sonst bleibt der
  Startknopf. Getestet wurde hier nur in Chromium – das iPhone-Verhalten
  muss am Gerät geprüft werden.
- Erster Aufruf lädt rund 45 MB. Danach liegen die Modelle im Browser-Cache
  und im Service Worker.

## Wenn das Objektmodell nicht lädt

Die Gewichte des Detektors liegen in `assets/models/detector/` als
`model.json` plus zwei `.bin`-Dateien – **mit** Endung, weil Webhoster
endungslose Dateien regelmässig blockieren (das war in der ersten Fassung der
Grund für „Objektmodell nicht verfügbar“). `check.mjs` prüft das.

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
| [@tensorflow/tfjs-backend-wasm](https://github.com/tensorflow/tfjs) | 4.22.0 | Apache-2.0 |
| [@mediapipe/tasks-vision](https://github.com/google-ai-edge/mediapipe) | 1.0.1 | Apache-2.0 |
| MediaPipe `gesture_recognizer.task` | float16/1 | Apache-2.0 |
| [tesseract.js](https://github.com/naptha/tesseract.js) | 7.0.0 | Apache-2.0 |
| Tesseract-Sprachdaten | `tessdata_fast` (deu, eng) | Apache-2.0 |
| YOLOv8n-oiv7, YOLOv8n-cls ([Ultralytics](https://github.com/ultralytics/ultralytics)) | 8.4 | **AGPL-3.0** |

**Zur AGPL:** Die beiden YOLO-Netze stehen unter AGPL-3.0. Wer die Seite
öffentlich betreibt, muss den Quellcode zugänglich machen – dieses Repository
erfüllt das, solange es öffentlich bleibt – oder eine kommerzielle
Ultralytics-Lizenz erwerben. Für eine private Nutzung ist das unproblematisch;
für ein geschlossenes Produkt wäre ein anderes Modell nötig.
