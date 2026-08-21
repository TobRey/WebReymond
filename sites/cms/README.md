# Backend – das eigene Redaktionssystem

Die DJ-Website mit eigenem Redaktionssystem: Anmeldung unter `/login`,
Baukasten mit Drag-and-Drop, Dashboard für alle Einstellungen. Alles im
gleichen Stil wie die Website – schwarz, weiss, dunkel.

Kein WordPress, keine Datenbank, keine Fremdbibliothek. Nur PHP-Dateien und
zwei JSON-Dateien mit den Inhalten. Läuft auf jedem einfachen Webhosting.

---

## In einer Minute ausprobieren

```bash
cd sites/cms
php -S localhost:8000 index.php
```

Dann im Browser:

| Adresse | Was |
|---|---|
| `http://localhost:8000/` | die Website |
| `http://localhost:8000/login` | Anmeldung |
| `http://localhost:8000/editor` | Baukasten |
| `http://localhost:8000/dashboard` | Einstellungen |

**Zugang beim ersten Start:** `djatze` / `Marihuana420!!`
→ Bitte nach dem ersten Anmelden im Dashboard unter **Konto** ändern.

---

## Der Baukasten

Nach dem Anmelden landest du direkt im Editor. Die Seite in der Mitte ist die
echte Seite – kein Nachbau. Was du siehst, ist genau das, was Besucher sehen.

**Werkzeugleiste oben** (und nur oben, damit die Breite der Seite unangetastet
bleibt):

| Bedienelement | Wirkung |
|---|---|
| Seitenauswahl | zwischen Start, Musik, Kontakt wechseln |
| **+ Elemente** | Bausteine einblenden – hineinziehen oder anklicken |
| Bildschirm-Symbole | Desktop, iPad, Handy |
| Pfeil zurück | letzte Änderung rückgängig (auch Strg + Z) |
| Auge | Vorschau in einem neuen Tab |
| **Speichern** | sichert die Seite (auch Strg + S) |
| Zahnrad | Dashboard mit allen Einstellungen |
| Pfeil nach oben | Leiste ausblenden – sie kommt über den Griff oben zurück |

**Am Abschnitt selbst** erscheint links oben eine kleine Leiste, sobald die
Maus darüber ist: verschieben (Griff), nach oben, nach unten, verdoppeln,
Einstellungen, löschen.

**Text ändern:** direkt auf der Seite anklicken und tippen. Escape oder ein
Klick daneben übernimmt.

**Einstellungen eines Abschnitts** öffnen sich rechts als schwebendes
Fenster – es schiebt die Seite nicht zur Seite. Drei Reiter:

- **Inhalt** – Texte, Bilder, Knöpfe, Listen
- **Stil** – Hintergrund (auch Bild mit Abdunklung), Abstände, Linien
- **Erweitert** – Sprungmarke, auf dem Handy ausblenden

**Navigation:** ein Klick auf „Musik“ oder „Kontakt“ in der Seitennavigation
speichert und wechselt sofort dorthin – weiterbearbeiten ohne Umweg.

**Responsive** entsteht automatisch: das Layout ist von Grund auf fliessend
gebaut. Die drei Bildschirmgrössen sind zum Prüfen da, nicht zum Nachbauen.

### Die Bausteine

Es gibt genau die Elemente, die auf dieser Website vorkommen – nichts
Überflüssiges:

| Baustein | Was er zeigt |
|---|---|
| Banner | grosses Bild, Titel, Text, Knöpfe, Kennzahlen |
| Seitentitel | kleine Zeile, grosser Titel, kurzer Text |
| Laufband | endlos laufende Begriffe |
| Text mit Titel | grosser Titel links, Text und Stichwörter rechts |
| Freier Text | einfacher Textblock |
| Termine | Liste mit Datum, Anlass, Ort, Status |
| Musikplayer | Cover, grosser Titel, Balkenanzeige, Bedienung |
| Titelliste | alle Titel als anklickbare Liste |
| Kontakt mit Formular | direkte Wege und Anfrageformular |
| Schlussaufruf | grosse Zeile mit Knopf |

---

## Das Dashboard

Zahnrad in der Leiste oder `/dashboard`:

- **Website** – Name, Untertitel, E-Mail, Telefon, Ort, Social-Links
- **Musik** – Titel für den Player: Name, Stilrichtung, Dauer, Audiodatei,
  Cover. Reihenfolge mit den Pfeilen.
- **Seiten** – Seiten anlegen, umbenennen, löschen
- **Darstellung** – Filmkorn, eigener Mauszeiger, Ladevorhang, Vignette
- **Anfragen** – alle eingegangenen Booking-Anfragen (auch wenn eine Mail
  einmal nicht durchkommt)
- **Dateien** – alles Hochgeladene auf einen Blick
- **Konto** – Benutzername und Passwort ändern
- **Daten** – Inhalte als Datei herunterladen, Seiten zurücksetzen

---

## Installation bei GoDaddy (cPanel)

### 1. PHP-Version prüfen

cPanel → **MultiPHP Manager** → Domain auswählen → **PHP 8.0** oder neuer
setzen. Weniger als PHP 7.4 läuft nicht.

### 2. Dateien hochladen

cPanel → **Dateimanager** → Ordner `public_html` öffnen.

Falls dort noch eine Platzhalterseite von GoDaddy liegt (`index.html`,
`default.html`, `coming-soon.html`), diese **löschen** – sonst zeigt der
Server sie statt der neuen Seite.

Dann **Upload** → `cms.zip` hochladen → zurück in den Dateimanager →
rechte Maustaste auf die Datei → **Extract** → als Ziel `/public_html`
bestätigen.

Die ZIP-Datei ist so gepackt, dass danach `index.php`, `app/`, `assets/`,
`data/` und `uploads/` **direkt** in `public_html` liegen – genau richtig.
Sollte doch ein Unterordner entstanden sein: Ordner öffnen, alles markieren,
**Move** nach `/public_html`.

Die ZIP-Datei danach löschen.

### 3. Schreibrechte setzen

Der Baukasten muss speichern können. Im Dateimanager:

- Rechtsklick auf **`data`** → *Change Permissions* → **755**
- Rechtsklick auf **`uploads`** → *Change Permissions* → **755**

Klappt das Speichern später nicht, hier auf **775** erhöhen.

### 4. Verstecke Dateien einblenden (einmalig)

Dateimanager → rechts oben **Settings** → Häkchen bei
*Show Hidden Files (dotfiles)*. Nur so siehst du die `.htaccess`-Dateien.
Die drei mitgelieferten (`/.htaccess`, `data/.htaccess`, `uploads/.htaccess`)
müssen vorhanden sein – sie schalten die sauberen Adressen frei und sperren
die Datenordner.

### 5. Aufrufen

`https://reymond-tobias.ch` → die Website läuft.
`https://reymond-tobias.ch/login` → anmelden mit
`djatze` / `Marihuana420!!`.

**Sofort danach:** Dashboard → **Konto** → neues Passwort setzen.

### 6. HTTPS einschalten

cPanel → **SSL/TLS Status** → Domain auswählen → **Run AutoSSL**.
Danach cPanel → **Domains** → bei der Domain **Force HTTPS Redirect**
einschalten. Das Anmeldeformular gehört nur über HTTPS ins Netz.

### 7. E-Mail prüfen

Eine Testanfrage über das Kontaktformular schicken. GoDaddy versendet über
die PHP-Funktion `mail()`; der Absender ist `noreply@deine-domain`. Kommt
nichts an:

- im Dashboard unter **Anfragen** steht sie trotzdem – nichts geht verloren
- im Dashboard unter **Website** bei *Anfragen senden an* eine Adresse
  derselben Domain eintragen (z. B. `booking@reymond-tobias.ch`, in cPanel
  unter *Email Accounts* anlegen). Fremde Adressen wie gmail.com landen
  häufiger im Spam.

### Wenn die Unterseiten nicht gehen

Zeigt `/musik` einen 404-Fehler, ist `mod_rewrite` nicht aktiv. Zwei Wege:

1. In der `.htaccess` im Hauptordner die Zeile `# RewriteBase /` entstummen
   (das `#` entfernen).
2. Oder die Seiten über `index.php?p=musik` aufrufen – das funktioniert immer.

### Wenn das CMS in einem Unterordner liegen soll

Beispiel `public_html/neu`: in `.htaccess` die Zeile
`RewriteBase /neu` eintragen. Alles andere findet sich von selbst.

---

## Sicherheit

Eingebaut ist:

- Passwort nur als Hash gespeichert (`password_hash`), nie im Klartext
- Anmeldebremse: nach fünf Fehlversuchen 15 Minuten Pause
- Token gegen untergeschobene Formulare (CSRF) bei jedem schreibenden Aufruf
- `data/` und `app/` sind per `.htaccess` und zusätzlicher `index.php` gesperrt
- Uploads: nur Bilder und Ton, Inhalt wird geprüft, im Ordner ist
  Skriptausführung abgeschaltet
- alle Ausgaben werden escaped, alle Eingaben gefiltert
- Spam-Falle im Kontaktformular

Was du selbst tun solltest:

1. **Passwort sofort ändern** – das Startpasswort steht in dieser Anleitung
   und damit möglicherweise auch anderswo.
2. **HTTPS erzwingen** (Schritt 6).
3. Noch sicherer: den Datenordner ausserhalb von `public_html` legen.
   Dazu neben `index.php` eine Datei `config.local.php` anlegen:

   ```php
   <?php
   define( 'RC_DATA', '/home/DEINBENUTZER/cms-daten' );
   ```

   Den Ordner im Dateimanager eine Ebene über `public_html` anlegen und die
   Dateien aus `data/` hineinschieben.

---

## Aufbau

```
cms/
├── index.php              Verteiler: welche Adresse zeigt was
├── .htaccess              saubere Adressen, Schutz, Zwischenspeicher
├── app/
│   ├── config.php         Pfade, Sitzung, kleine Helfer
│   ├── store.php          Lesen und Schreiben der JSON-Dateien
│   ├── auth.php           Anmeldung, Bremse, CSRF
│   ├── sections.php       welche Bausteine es gibt und welche Felder
│   ├── render.php         erzeugt das HTML der Abschnitte
│   ├── layout.php         Kopf, Navigation, Fuss
│   ├── api.php            Schnittstellen des Baukastens
│   ├── contact.php        Anfragen entgegennehmen
│   ├── upload.php         Dateien annehmen und prüfen
│   ├── defaults.php       Startinhalte
│   └── views/             login.php, editor.php, dashboard.php
├── assets/
│   ├── css/style.css      die Website
│   ├── css/editor.css     Anmeldung, Baukasten, Dashboard
│   ├── js/main.js         Animationen der Website
│   ├── js/player.js       Musikplayer
│   ├── js/contact.js      Formularprüfung
│   ├── js/canvas.js       im Rahmen: auswählen, ziehen, tippen
│   ├── js/editor.js       Werkzeugleiste, Modell, Speichern
│   ├── js/dashboard.js    Einstellungen
│   ├── fonts/             Anton und Space Grotesk (lokal)
│   └── img/               Platzhalter, Cover, Favicon
├── data/                  site.json, users.json (entstehen beim ersten Start)
└── uploads/               hochgeladene Bilder und Musik
```

Alle Inhalte stehen in **einer** Datei: `data/site.json`. Sicherung heisst
also: diese Datei kopieren. Beim Speichern legt das System zusätzlich
automatisch die letzten drei Stände unter `data/backups/` ab.

---

## Zurück auf Anfang

Alles löschen in `data/` (ausser `.htaccess` und `index.php`) – beim nächsten
Aufruf entsteht die Website neu, so wie ausgeliefert, samt Startzugang.
