# reymond-tobias.ch – DJ-Website

Schwarz-weisse, dunkle DJ-Seite mit drei Seiten: **Start**, **Musik**, **Kontakt**.
Reines HTML, CSS und JavaScript – kein Build, keine Abhängigkeiten, kein Server nötig.

## Ansehen

```bash
cd sites/reymond-tobias
python3 -m http.server 8080
# Browser: http://localhost:8080
```

Ein Doppelklick auf `index.html` funktioniert auch, nur der Player braucht
wegen der Browser-Sicherheitsregeln einen richtigen Webserver.

## Aufbau

```
sites/reymond-tobias/
├── index.html          Startseite (Banner, Über, Termine)
├── musik.html          Player und Titelliste
├── kontakt.html        Booking-Formular und direkte Wege
└── assets/
    ├── css/style.css   Alle Stile, Farben ganz oben als Variablen
    ├── js/main.js      Vorhang, Mauszeiger, Menü, Animationen
    ├── js/player.js    Audioplayer, Balkenanzeige, Titelliste
    ├── js/contact.js   Formular (Mailprogramm oder Formulardienst)
    ├── img/            Banner, Cover, Favicon
    └── audio/          Musikdateien (siehe audio/README.md)
```

## Das Wichtigste zuerst ändern

### 1. Bannerbild

Das Foto vom DJ-Pult als **`assets/img/banner.jpg`** ablegen (quer, mindestens
2000 px breit). Solange es fehlt, zeigt die Seite automatisch den Platzhalter
`banner-placeholder.svg`.

Auf dem Foto steht Tobias rechts – deshalb liegt der Titel links und die
Abdunklung ist links am stärksten. Passt der Bildausschnitt nicht, in
`assets/css/style.css` die Zeile `object-position: 72% 28%;` (`.hero__img`)
anpassen: erster Wert links/rechts, zweiter oben/unten.

### 2. Titel und Musik

In `assets/js/player.js` ganz oben steht die Liste `TRACKS`. Pro Titel:

```js
{
  title: 'Nachtschicht',              // grosser Titel im Player
  tag: 'Peak Time Techno',            // kleine Zeile darunter
  duration: '6:12',                   // Anzeige, bis die Datei geladen ist
  audio: 'assets/audio/nachtschicht.mp3',
  cover: 'assets/img/cover-01.svg',   // eigenes Bild? einfach hier eintragen
}
```

Die mitgelieferten Cover sind schwarz-weisse Platzhalter-Grafiken. Eigene
Bilder (quadratisch, z. B. 1200 × 1200) nach `assets/img/` legen und den Pfad
eintragen.

### 3. Kontaktdaten

In allen drei HTML-Dateien ersetzen:

- `booking@reymond-tobias.ch` → echte Adresse
- Telefonnummer in `kontakt.html` (`+41 00 000 00 00`) oder den Block löschen
- Social-Links in `kontakt.html` (`href="#"`) eintragen

### 4. Beispielinhalte ersetzen

- **Termine** auf der Startseite sind mit „Beispiel“ markiert – echte Termine
  eintragen oder den ganzen Abschnitt löschen.
- Der Text im Abschnitt „Wer hinter dem Pult steht“ ist ein Vorschlag und darf
  frei umgeschrieben werden.

## Kontaktformular

Ohne Server öffnet das Formular das Mailprogramm des Besuchers mit fertig
ausgefüllter Anfrage – das funktioniert sofort.

Für echten Versand ohne Mailprogramm einen Formulardienst eintragen:

```html
<form class="form" data-contact-form data-endpoint="https://formspree.io/f/DEIN-CODE">
```

Sobald `data-endpoint` gesetzt ist, wird die Anfrage im Hintergrund verschickt
und die Seite bleibt stehen. Eine versteckte Spam-Falle (`website`-Feld) ist
bereits eingebaut.

## Bedienung des Players

| Taste | Wirkung |
|---|---|
| Leertaste | Abspielen / Pause |
| Umschalt + → | Nächster Titel |
| Umschalt + ← | Vorheriger Titel |
| → / ← auf dem Fortschrittsbalken | 5 % vor / zurück |

## Technische Hinweise

- **Geräte:** Handy, Tablet und Desktop; Layout über `clamp()` und Grid, kein
  fester Breakpoint-Zoo.
- **Animation:** Wer im Betriebssystem „Bewegung reduzieren“ eingestellt hat,
  bekommt eine ruhige Seite ohne Bewegung (`prefers-reduced-motion`).
- **Schriften:** Anton und Space Grotesk von Google Fonts. Sollen keine
  externen Anfragen stattfinden (Datenschutz), die beiden `<link>`-Zeilen in
  den HTML-Dateien entfernen und die Schriftdateien lokal ablegen – die
  Ersatzschriften in `--font-display` / `--font-body` greifen sonst automatisch.
- **Farben:** ausschliesslich Schwarz, Weiss und Grautöne, definiert in
  Abschnitt 1 von `style.css`.

## Veröffentlichen

Der Ordner ist eine fertige statische Seite. Inhalt von
`sites/reymond-tobias/` auf den Webspace von `reymond-tobias.ch` kopieren
(z. B. per FTP nach `public_html/`) – fertig. Ein Build-Schritt entfällt.
