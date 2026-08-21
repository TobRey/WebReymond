# Prompt: Website mit eigenem Backend bauen

Alles unter der Linie kopieren, den Kopf ausfüllen, abschicken. Was du nicht
ausfüllst, entscheidet Claude selbst – nur Name und Zugang werden gebraucht.

---

## AUFTRAG

**Website-Name:**
**Alte Website (URL):**            ← optional; Texte von dort übernehmen
**Design-Stichwörter:**            ← z. B. „schwarz-weiss, dunkel, viel Animation, flashy“
**Benutzername Backend:**
**Passwort Backend:**
**Zusätzliche Infos:**             ← Seiten, Branche, Sprache, Domain, Besonderheiten

*(Bilder einfach anhängen. Liegt ein Bannerfoto bei: Bildausschnitt beachten und
den Titel auf die freie Seite legen, nie über ein Gesicht.)*

---

## WAS DU BAUST

Ein einziges, fertiges Paket: **Website + Backend + Editor**, alles in einem
Ordner. Reines PHP, HTML, CSS, JavaScript. **Keine Datenbank, kein Framework,
keine Fremdbibliothek, kein Build-Schritt** – es muss auf einfachem
Shared-Hosting (cPanel, GoDaddy, Hostpoint) laufen, hochladen und fertig.

Kein Theme-System und keine Theme-Auswahl: das Design ist fix eingebaut.

### 1. Die Website (Frontend)

- Drei Seiten, sofern nichts anderes gewünscht ist: **Start**, plus zwei
  passende Unterseiten aus den zusätzlichen Infos
- Layout fliessend über `clamp()` und Grid – Handy, Tablet und Desktop
  entstehen automatisch, keine Extra-Fassung
- Das Design folgt den Stichwörtern: Farben, Typografie und Bewegung leitest
  du daraus ab. Alle Farbwerte stehen **nur** ganz oben in der CSS-Datei als
  Variablen, nirgends sonst.
- Animationen: Ladevorhang, Einblenden beim Scrollen, Laufband, Parallaxe im
  Banner, eigener Mauszeiger auf Geräten mit Maus. `prefers-reduced-motion`
  schaltet alles ab.
- Schriften **lokal** ins Projekt legen (woff2 herunterladen, `@font-face`),
  keine Anfrage an Google. Für grosse Titel `font-display: block`, sonst
  blitzt kurz eine zu breite Ersatzschrift auf.
- Kontaktformular: serverseitig verarbeitet, mit Nonce, Spam-Falle und
  Sperre bei zu vielen Anfragen. Jede Anfrage wird zusätzlich gespeichert,
  damit nichts verloren geht, wenn der Mailversand des Hosters klemmt.
- Suchmaschinen: Titel und Beschreibung je Seite, Vorschau fürs Teilen,
  `robots.txt`, `sitemap.xml`, Favicon.

### 2. Das Backend

- Anmeldung unter **`/login`**, im exakt gleichen Stil wie die Website
- Zugang aus dem Kopf des Auftrags; Passwort **nur als Hash** speichern
  (`password_hash`), niemals im Klartext
- Bremse nach fünf Fehlversuchen, CSRF-Token bei jedem schreibenden Zugriff
- Das Backend trägt **keinen Produktnamen** – in Titeln und Leisten steht
  schlicht „Backend“
- Inhalte in einer einzigen JSON-Datei, Zugang in einer zweiten. Beim
  Speichern automatisch die letzten drei Stände als Sicherung ablegen.

### 3. Der Editor

Nach dem Anmelden landet man direkt darin.

- Die **echte Seite** steht in einem Rahmen (iframe) in der Mitte – kein
  Nachbau. Gezeichnet wird immer auf dem Server, damit Backend und Frontend
  garantiert dasselbe HTML zeigen.
- **Werkzeugleiste ausschliesslich oben** und ausblendbar. Keine Seitenleiste:
  die Breite der Seite darf sich nie verändern. Alle Panels legen sich
  darüber, sie schieben nichts zur Seite.
- In der Leiste: Seitenauswahl, **+ Elemente**, Ansicht (Desktop / Tablet /
  Handy), Rückgängig, Vorschau, **Speichern**, Zahnrad zum Dashboard,
  Leiste ausblenden
- **Elemente per Ziehen oder Klick einfügen.** Beim Ziehen zeigt eine Marke,
  wo der Baustein landet.
- Es gibt **nur die Elemente, die auf dieser Website wirklich vorkommen**.
  Kein Kartenmodul, keine Preistabelle, kein Formularbaukasten – nichts, was
  auf der Seite nicht existiert. Jeder Abschnitt der fertigen Seite ist
  gleichzeitig ein Baustein zum Wiedereinfügen.
- Am Abschnitt erscheinen bei Mauskontakt: verschieben, hoch, runter,
  verdoppeln, Einstellungen, löschen
- **Text direkt auf der Seite anklicken und tippen**
- **Einstellungen je Abschnitt** in einem schwebenden Fenster mit drei
  Reitern, ähnlich Elementor:
  - *Inhalt* – Texte, Bilder, Knöpfe, Listen (Listen mit Hinzufügen,
    Entfernen, Sortieren)
  - *Stil* – Hintergrund (Farbe, Verlauf oder Bild mit Abdunklung),
    Abstände, Linien oben und unten
  - *Erweitert* – Sprungmarke, auf dem Handy ausblenden
- **Klick auf einen Verweis in der Navigation speichert und wechselt** auf
  die entsprechende Seite – dort direkt weiterbearbeiten
- Strg + S speichert, Strg + Z macht rückgängig
- Wichtig: Auftrittsanimationen im Editor abschalten, sonst bleibt der Text
  unsichtbar, weil das Frontend-Skript dort nicht mitläuft

### 4. Das Dashboard

Zahnrad in der Leiste, kleines Overlay-Symbol, führt auf eine eigene Seite:

- **Website** – Name, Untertitel, E-Mail, Telefon, Ort, Social-Links
- **Inhalte** – was die Seite an eigenen Daten hat (z. B. Musiktitel,
  Produkte, Termine) samt Hochladen von Dateien
- **Seiten** – anlegen, umbenennen, löschen
- **Darstellung** – die Effekte einzeln an- und abschalten
- **Anfragen** – alles, was übers Formular kam
- **Dateien** – alle Uploads auf einen Blick
- **Konto** – Benutzername und Passwort ändern
- **Daten** – Inhalte herunterladen, Seiten zurücksetzen

### 5. Sicherheit

- Alle Ausgaben escapen, alle Eingaben filtern
- Daten- und Programmordner per `.htaccess` **und** einer zusätzlichen
  `index.php` sperren
- Uploads: nur Bild und Ton, Endung **und** Inhalt prüfen, Dateinamen neu
  vergeben, Skriptausführung im Upload-Ordner abschalten
- Sitzungscookie httponly und SameSite, Kennung nach dem Anmelden erneuern

---

## INHALTE

- Liegt eine **alte Website** bei: Texte von dort holen und übernehmen, dabei
  sprachlich glätten. Kommst du nicht an die Seite heran, sag es und arbeite
  mit Platzhaltern weiter.
- Ohne alte Website: Texte passend zur Branche schreiben – aber **nichts
  erfinden, was nachprüfbar wäre**. Keine erfundenen Referenzen, Zahlen,
  Auszeichnungen oder Termine. Beispieleinträge sichtbar als „Beispiel“
  kennzeichnen.
- Fehlende Bilder durch mitgelieferte, zum Design passende Platzhalter
  ersetzen (SVG, selbst erzeugt), damit nie eine kaputte Seite entsteht.
  Sobald die echte Datei da ist, verschwindet der Platzhalter von selbst.
- Oberfläche und Texte auf Deutsch, ausser die zusätzlichen Infos sagen etwas
  anderes.

---

## ABLIEFERN

1. **Eine ZIP-Datei**, gepackt aus dem Inhalt des Ordners – nach dem
   Entpacken müssen `index.php`, `.htaccess`, `app/`, `assets/`, `data/`,
   `uploads/` **direkt** in `public_html` liegen, ohne Zwischenordner.
2. Eine **README im Paket** mit Aufbau, Bedienung und Anleitung fürs cPanel:
   PHP-Version, Hochladen, Entpacken, Schreibrechte für die Datenordner,
   versteckte Dateien einblenden, Aufrufen, Passwort ändern, HTTPS, Mail.
3. Im Chat: kurze Erklärung, was gebaut wurde, was du offen gelassen hast und
   was ich noch einsetzen muss (Fotos, Dateien, Kontaktdaten).
4. Screenshots der fertigen Seite und des Editors.

---

## PRÜFEN, BEVOR DU ABLIEFERST

Nicht behaupten, sondern ausführen:

1. `php -S` starten und die Seite mit einem echten Browser (Chromium,
   Playwright) aufrufen – Desktop und Handy
2. Prüfen: keine Fehler in der Konsole, kein waagrechtes Scrollen, keine
   404-Meldungen ausser für Dateien, die ich noch liefern muss
3. Den ganzen Weg durchspielen: anmelden (auch mit falschem Passwort),
   Element per Klick **und** per Ziehen einfügen, Abschnitt verschieben,
   Text auf der Seite ändern, speichern, Seite neu laden und prüfen, dass
   alles noch da ist, über die Navigation die Seite wechseln, Handyansicht,
   Dashboard-Einstellung speichern und im Frontend nachsehen
4. Zum Schluss dieselbe Runde **aus der fertigen ZIP heraus**, frisch
   entpackt – nicht nur im Arbeitsordner
5. Im Chat berichten, was du geprüft hast und was dabei herauskam. Läuft
   etwas nicht, sag es offen, statt es zu übergehen.

---

## STIL DES CODES

- Sprechende Namen, kurze Funktionen, jede Datei mit einem Kopfkommentar,
  der ihren Zweck in zwei Sätzen erklärt
- Kommentare auf Deutsch und nur dort, wo sie das **Warum** erklären
- Keine toten Platzhalter-Funktionen und kein Code für Dinge, die es auf
  dieser Website nicht gibt
- PHP 7.4 muss reichen (Shared-Hosting), sauber auch unter PHP 8.4
