# Installation auf GoDaddy-cPanel (Schritt fuer Schritt)

Dauer: etwa drei Minuten. Es werden keine Shell-Zugriffe, keine Datenbank und kein
Composer/Node benoetigt.

Es gibt zwei Pakete mit identischem Spielinhalt:

| Paket | Einrichtung | Anleitung |
|---|---|---|
| `where-is-toby-1.0.0.zip` | Installationsassistent im Browser | Abschnitte 1-5 |
| `where-is-toby-1.0.0-ohne-installer.zip` | richtet sich beim ersten Aufruf selbst ein | Abschnitte 1-3 und 4b |

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
3. Oben **Upload** klicken und die heruntergeladene ZIP-Datei auswaehlen
   (`where-is-toby-1.0.0.zip` oder `where-is-toby-1.0.0-ohne-installer.zip`).
4. Zurueck zum File Manager, die ZIP-Datei mit der rechten Maustaste anklicken → **Extract**
   → Zielpfad bestaetigen.
5. Die ZIP-Datei nach dem Entpacken loeschen.

Nach dem Entpacken muessen im Zielverzeichnis unter anderem liegen:
`index.php`, `.htaccess`, `app/`, `assets/`, `storage/`, `uploads/` - beim Paket mit
Assistent zusaetzlich `install.php`.

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

In der Regel sind diese Rechte nach dem Entpacken bereits gesetzt. Falls die Einrichtung
spaeter meldet, dass keine Dateien angelegt werden konnten, liegt es fast immer hier.

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

## 4b. Paket ohne Installationsassistent

Bei `where-is-toby-1.0.0-ohne-installer.zip` entfaellt Abschnitt 4 vollstaendig.

1. Die Domain im Browser oeffnen (z. B. `https://example.com/`).
2. Die Startseite erscheint - fertig.

Beim ersten Aufruf richtet sich die Anwendung selbst ein:

* Datenverzeichnisse inklusive Zugriffssperren (`.htaccess` und `index.php`)
* Verschluesselungsschluessel `app_key` fuer gespeicherte API-Schluessel
* Grundeinstellungen und der Basispfad (auch in einem Unterordner)
* Administratorkonto `tobi` / `Marihuana420!!`
* der mitgelieferte Fall "Where is Toby?"
* `storage/settings/install.lock`, damit die Einrichtung nur einmal laeuft

Die Konfiguration landet in `app/config.local.php`; ist `app/` nicht beschreibbar, weicht die
Anwendung auf `storage/settings/config.local.php` aus. Beide Orte sind ueber `.htaccess`
gesperrt.

**Wo liegen die Spieldaten?** Nach Moeglichkeit ausserhalb des Webordners, also `wit_data`
neben `public_html`. Das geht nur, wenn das Spiel direkt in `public_html` liegt. In einem
Unterordner (z. B. `public_html/toby`) waere ein Nachbarordner ueber die URL erreichbar -
dann verwendet die Anwendung den mitgelieferten, gesperrten Ordner `storage/`.

**KI-Verbindung:** wird hier nicht abgefragt. Das Spiel startet im Offline-Modus und ist so
vollstaendig spielbar. Nachtragen unter **Adminbereich → KI**, siehe `KI-ANBIETER.md`.

> **Startpasswort sofort aendern.** Es ist oeffentlich dokumentiert. Nach dem ersten Login
> unter **Adminbereich → Konto** ein eigenes Passwort setzen; bis dahin blendet der
> Adminbereich eine Warnung ein.

Erscheint statt der Startseite der Hinweis, dass keine Dateien angelegt werden konnten:
Rechte gemaess Abschnitt 3 setzen (Ordner 755, Dateien 644) und die Seite neu laden.

---

## 5. Nach der Installation

1. `install.php` im File Manager loeschen (empfohlen; im Paket ohne Assistent nicht vorhanden).
2. Adminbereich oeffnen: `https://example.com/admin`
3. Unter **Konto** das Startpasswort aendern.
4. Unter **Diagnose** die Pruefung ausfuehren - alle Punkte sollten gruen oder gelb sein.
5. Unter **Einstellungen** Impressum und Datenschutzhinweis ausfuellen.
6. HTTPS aktivieren: cPanel → **Security** → **SSL/TLS Status** → **Run AutoSSL**.

---

## 6. Umzug in einen Unterordner oder auf eine andere Domain

Der Basispfad steht in `app/config.local.php` (`base_path`). Beim Umzug entweder

* diesen Wert anpassen, oder
* die Datei `app/config.local.php` loeschen (bestehende Spieldaten bleiben erhalten, wenn
  `storage_path` gleich bleibt). Beim Paket mit Assistent dann `install.php` erneut hochladen,
  beim Paket ohne Assistent genuegt der naechste Seitenaufruf.

> Beim Loeschen der Konfiguration wird ein **neuer App-Schluessel** erzeugt. Ein bereits
> gespeicherter API-Schluessel laesst sich damit nicht mehr entschluesseln und muss unter
> **Adminbereich → KI** neu eingetragen werden. Die Diagnose weist darauf hin. Konten,
> Spielstaende und Faelle bleiben unveraendert.

Die mitgelieferte `.htaccess` kommt ohne festes `RewriteBase` aus und funktioniert deshalb
sowohl direkt in `public_html` als auch in jedem Unterordner.

---

## 7. Deinstallation

Alle Daten liegen in `storage/` (oder `../wit_data`) und `uploads/`. Zum vollstaendigen
Entfernen: Verzeichnisse und Programmdateien loeschen. Es bleiben keine Datenbankreste zurueck.
