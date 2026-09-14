# Installation auf GoDaddy-cPanel (Schritt fuer Schritt)

Dauer: etwa drei Minuten. Es werden keine Shell-Zugriffe, keine Datenbank und kein
Composer/Node benoetigt.

---

## 1. PHP-Version pruefen

1. In cPanel einloggen.
2. Bereich **Software** → **MultiPHP Manager**.
3. Die Domain auswaehlen und **PHP 8.1 oder neuer** einstellen (empfohlen: 8.4).
4. **Apply** klicken.

Optional, aber empfohlen: **Select PHP Version** → **Extensions** und dort
`curl`, `gd`, `fileinfo`, `zip`, `openssl` (oder `sodium`), `exif` aktivieren.
Ohne diese Erweiterungen laeuft das Spiel, einzelne Zusatzfunktionen sind dann deaktiviert.

---

## 2. ZIP hochladen

1. Bereich **Files** → **File Manager**.
2. In das Verzeichnis **`public_html`** wechseln.
   * Soll das Spiel in einem Unterordner laufen (z. B. `example.com/toby`), stattdessen dort
     einen Ordner anlegen und hineinwechseln. Das Spiel erkennt den Unterordner automatisch.
3. Oben **Upload** klicken und die Datei `where-is-toby-1.0.0.zip` auswaehlen.
4. Zurueck zum File Manager, die ZIP-Datei mit der rechten Maustaste anklicken → **Extract**
   → Zielpfad bestaetigen.
5. Die ZIP-Datei nach dem Entpacken loeschen.

Nach dem Entpacken muessen im Zielverzeichnis unter anderem liegen:
`index.php`, `install.php`, `.htaccess`, `app/`, `assets/`, `storage/`, `uploads/`.

> **Wichtig:** Manche Browser entpacken ZIP-Dateien beim Download automatisch in einen
> Unterordner. Achte darauf, dass `index.php` direkt in `public_html` liegt und nicht in
> `public_html/where-is-toby-1.0.0/`.

---

## 3. Rechte pruefen

Im File Manager sollten folgende Verzeichnisse die Rechte **755** haben, Dateien **644**:

* `storage/` und alle Unterordner
* `uploads/`
* `app/` (nur fuer die Installation, danach kann `app/` auf 755 bleiben)

Rechte aendern: Datei/Ordner markieren → **Permissions**.

---

## 4. Installationsassistent ausfuehren

1. Die Domain im Browser oeffnen (z. B. `https://example.com/`).
   Es erscheint automatisch der Installationsassistent.
2. **Schritt 1 - Systempruefung:** Alle Pflichtpunkte muessen gruen sein. Rote Punkte werden
   mit Loesungshinweis angezeigt (meist PHP-Version oder Schreibrechte).
3. **Schritt 2 - Grundeinstellungen:** weiter klicken.
4. **Schritt 3 - Konto und KI:**
   * Seitenname eintragen.
   * Haken "Spieldaten nach Moeglichkeit ausserhalb von public_html speichern" aktiv lassen.
     Der Installer legt dann `../wit_data` an. Falls das nicht moeglich ist, wird automatisch
     `storage/` mit `.htaccess`-Schutz verwendet.
   * Administratorkonto: voreingetragen sind `tobi` / `Marihuana420!!`.
   * KI-Verbindung: **Offline-Modus** waehlen, wenn (noch) kein Schluessel vorliegt. Der Fall
     "Toby" ist damit vollstaendig spielbar. Details: `KI-ANBIETER.md`.
   * Inhaltshinweis bestaetigen (ab 18 Jahren).
5. **Installation abschliessen** klicken.

Der Assistent legt Verzeichnisse und JSON-Dateien an, erzeugt den App-Schluessel, spielt den
Fall "Toby" ein und **sperrt sich selbst** (`storage/settings/install.lock`).

---

## 5. Nach der Installation

1. `install.php` im File Manager loeschen (empfohlen).
2. Adminbereich oeffnen: `https://example.com/admin`
3. Unter **Konto** das Startpasswort aendern.
4. Unter **Diagnose** die Pruefung ausfuehren - alle Punkte sollten gruen oder gelb sein.
5. Unter **Einstellungen** Impressum und Datenschutzhinweis ausfuellen.
6. HTTPS aktivieren: cPanel → **Security** → **SSL/TLS Status** → **Run AutoSSL**.

---

## 6. Umzug in einen Unterordner oder auf eine andere Domain

Der Basispfad steht in `app/config.local.php` (`base_path`). Beim Umzug entweder

* diesen Wert anpassen, oder
* die Datei `app/config.local.php` loeschen und den Installer erneut hochladen
  (bestehende Spieldaten bleiben erhalten, wenn `storage_path` gleich bleibt).

---

## 7. Deinstallation

Alle Daten liegen in `storage/` (oder `../wit_data`) und `uploads/`. Zum vollstaendigen
Entfernen: Verzeichnisse und Programmdateien loeschen. Es bleiben keine Datenbankreste zurueck.
