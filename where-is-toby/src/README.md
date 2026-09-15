# WHERE IS TOBY?

Ein realistisches FBI-Horror-Ermittlungsspiel fuer den Browser. Dateibasiert, ohne Datenbank,
ohne Build-Schritt, lauffaehig auf einfachem PHP-Webhosting (getestet mit PHP 8.4 auf
Apache/cPanel).

> **Inhaltswarnung / Altersfreigabe:** Das Spiel enthaelt Gewaltdarstellungen, Schilderungen von
> Toetungsdelikten an Jugendlichen, Blut, Gefangenschaft und psychologischen Horror. Es ist ab
> 18 Jahren. Beim ersten Start muss die Altersbestaetigung aktiv bestaetigt werden.
> Alle Personen, Orte, Geraete, Logins und Ereignisse sind frei erfunden.

---

## 1. Was ist das?

Der Spieler uebernimmt die Rolle eines FBI-Agenten und bearbeitet Vermisstenfaelle an einer
simulierten Ermittlungsstation:

* **Fallakte** mit Vermisstenanzeige, Funkzellenauswertung, Aktenstuecken und Referenzmaterial
* **Freie Verhoere** mit KI-NPCs (Text und optional Spracheingabe) - die Figuren luegen,
  weichen aus, brechen ab und geben Informationen erst auf die richtige Frage oder nach einer
  Konfrontation mit einem Beweis heraus
* **Geraetearbeit**: Smartphones mit PIN und Mustersperre, Laptops mit Passwort, Dateisysteme,
  Papierkoerbe, E-Mail, Browserverlauf, Anruflisten, Betriebsprotokolle, simulierte Logins
* **Medienauswertung**: Fotos mit Zoom, Bilddetails und EXIF-Daten, Ueberwachungsvideos mit
  Zeitleiste, Audioanalyse mit Wellenform, Tempo- und Rueckwaertswiedergabe
* **Ermittlungswand** mit Karten, Verbindungen, Zoom und automatischer Speicherung
* **Digitale Karte**, **Zeitleisten-Rekonstruktion**, **Notizen**, **Beweisarchiv**
* **Abschlussbericht** mit Bewertung, Rang und mehreren Enden - auch schlechten
* **Horror-System**: seltene, fallabhaengige Ereignisse (keine Jumpscare-Kette), abschaltbar

Der mitgelieferte Fall **"Where is Toby?"** ist vollstaendig ausgearbeitet und getestet:
8 KI-NPCs, 6 Geraete, 25 Beweise, 19 Raetsel, 15 Horror-Ereignisse, 6 Enden,
Spielzeit ca. 15-25 Minuten beim ersten Durchgang.

---

## 2. Installation in drei Minuten

Es gibt zwei Pakete mit identischem Spielinhalt. Der Unterschied liegt nur in der Einrichtung.

| Paket | Einrichtung |
|---|---|
| `where-is-toby-1.0.0.zip` | mit Installationsassistent (`install.php`), eigene Zugangsdaten und KI-Verbindung werden beim Einrichten abgefragt |
| `where-is-toby-1.0.0-ohne-installer.zip` | ohne Assistenten, richtet sich beim ersten Seitenaufruf selbst ein |

### Variante A - mit Assistent

1. Die ZIP-Datei im cPanel-Dateimanager nach `public_html` hochladen.
2. Rechtsklick → **Extract**.
3. Die Domain im Browser oeffnen - der Installationsassistent startet automatisch.
4. Systempruefung bestaetigen, Administratorkonto und (optional) KI-Verbindung eintragen.
5. Fertig. `install.php` sperrt sich selbst; die Datei kann geloescht werden.

### Variante B - ohne Assistent

1. Die ZIP-Datei nach `public_html` hochladen und entpacken.
2. Die Domain im Browser oeffnen.

Mehr ist nicht noetig. Beim ersten Aufruf legt die Anwendung selbst an: Datenverzeichnisse,
Zugriffssperren, den Verschluesselungsschluessel (`app_key`), die Grundeinstellungen, das
Administratorkonto und den Fall "Where is Toby?". Danach wird die Einrichtung gesperrt
(`storage/settings/install.lock`), ein zweiter Aufruf richtet nichts erneut ein.

Das Datenverzeichnis wird dabei moeglichst **ausserhalb** des Webordners angelegt
(`wit_data` neben `public_html`). Liegt das Spiel in einem Unterordner von `public_html`,
waere ein Nachbarordner ueber die URL erreichbar - dann bleibt es beim mitgelieferten Ordner
`storage`, der ueber `.htaccess` und eine `index.php` gesperrt ist.

Die KI-Verbindung wird in dieser Variante nicht abgefragt. Das Spiel startet im
**Offline-Modus** (regelbasierte Dialoge) und ist so vollstaendig spielbar. Ein KI-Anbieter
laesst sich jederzeit unter **Adminbereich → KI** nachtragen, siehe
[`docs/KI-ANBIETER.md`](docs/KI-ANBIETER.md).

> **Wichtig bei Variante B:** Das Startpasswort steht unten in dieser Datei und ist damit
> allgemein bekannt. Direkt nach dem ersten Login aendern - der Adminbereich weist so lange
> darauf hin.

Ausfuehrliche Anleitung mit den cPanel-Schritten fuer beide Varianten:
[`docs/INSTALLATION-GODADDY.md`](docs/INSTALLATION-GODADDY.md)

**Initiale Administrator-Zugangsdaten** (im Installer voreingetragen, in Variante B
automatisch angelegt):

```
Benutzername: tobi
Passwort:     Marihuana420!!
```

Das Passwort wird ausschliesslich als Hash gespeichert (`password_hash`, bcrypt).
Beim ersten Login fordert das System zum Wechsel auf.

---

## 3. Systemvoraussetzungen

| Anforderung | Wert |
|---|---|
| PHP | 8.1 oder neuer (empfohlen 8.4) |
| Pflicht-Erweiterungen | `json`, `mbstring`, `session`, `pcre` |
| Empfohlene Erweiterungen | `curl` (KI), `openssl` oder `sodium` (Verschluesselung), `gd` (Bildgenerierung), `fileinfo` (Uploads), `zip` (Sicherungen), `exif` |
| Webserver | Apache mit `mod_rewrite` (cPanel-Standard) |
| Datenbank | keine |
| Node.js auf dem Server | nicht erforderlich |
| Schreibrechte | `storage/`, `uploads/`, `app/` (nur fuer die Installation) |

Fehlt eine empfohlene Erweiterung, laeuft das Spiel weiter - die Diagnose im Adminbereich
zeigt, welche Funktion dadurch eingeschraenkt ist.

---

## 4. Verzeichnisstruktur

```
public_html/
├── index.php                 Front-Controller (einziger oeffentlicher Einstiegspunkt)
├── install.php               Installationsassistent (sperrt sich selbst; im Paket
│                             "ohne-installer" nicht enthalten - dort richtet sich die
│                             Anwendung beim ersten Aufruf selbst ein)
├── .htaccess                 Rewrite, Sicherheitsheader, Zugriffsschutz
├── app/                      Programmcode (per .htaccess gesperrt)
│   ├── Core/                 Router, Request, Response, Session, CSRF, Crypto, Logger ...
│   ├── Repository/           JSON-Speicher mit Sperren und atomaren Schreibvorgaengen
│   ├── Service/              Fall-Engine, Raetsel-Engine, Hinweise, Horror, NPC-Chat, KI ...
│   ├── Controller/           Spiel, API, Adminbereich, Medien
│   ├── View/                 Templates
│   ├── Data/cases/           mitgelieferte Faelle (Vorlage fuer die Installation)
│   └── config.local.php      vom Installer erzeugt (Pfade, App-Schluessel)
├── assets/                   CSS, JavaScript (ES-Module), Bilder (SVG), Schriften
├── storage/                  Spieldaten (per .htaccess gesperrt)
│   ├── users/ cases/ progress/ sessions/ logs/ backups/ settings/ cache/ media/
├── uploads/                  hochgeladene Medien (nur ueber PHP-Endpunkt erreichbar)
└── docs/                     Dokumentation
```

Die Spieldaten koennen bei der Installation auch **ausserhalb** von `public_html` abgelegt
werden (`../wit_data`). Der Installer schlaegt das vor und prueft die Schreibrechte.

---

## 5. Dokumentation

| Datei | Inhalt |
|---|---|
| [`docs/INSTALLATION-GODADDY.md`](docs/INSTALLATION-GODADDY.md) | Installation auf GoDaddy-cPanel, Schritt fuer Schritt |
| [`docs/KI-ANBIETER.md`](docs/KI-ANBIETER.md) | Kostenlose KI-Anbieter einrichten (Gemini, OpenRouter, Groq, lokal) |
| [`docs/OFFLINE-MODUS.md`](docs/OFFLINE-MODUS.md) | Wie das regelbasierte Dialogsystem funktioniert |
| [`docs/FAELLE.md`](docs/FAELLE.md) | Eigene Faelle bauen: Fall-Editor, Raetseltypen, App-Inhalte |
| [`docs/JSON-STRUKTUR.md`](docs/JSON-STRUKTUR.md) | Aufbau aller JSON-Dateien (Fall, Konto, Fortschritt, Einstellungen) |
| [`docs/SICHERHEIT.md`](docs/SICHERHEIT.md) | Sicherheitsarchitektur und Pruefliste |
| [`docs/BACKUP.md`](docs/BACKUP.md) | Sicherung, Wiederherstellung, Umzug |
| [`docs/FEHLERBEHEBUNG.md`](docs/FEHLERBEHEBUNG.md) | Typische Probleme und Loesungen |
| [`docs/TESTS.md`](docs/TESTS.md) | Testprotokoll und wie die Tests wiederholt werden |
| [`docs/LIZENZEN.md`](docs/LIZENZEN.md) | Verwendete Bibliotheken und Lizenzen |

---

## 6. Bedienung im Spiel

| Taste | Funktion |
|---|---|
| `1` - `9` | Bereiche wechseln (Akte, Personen, Beweise, Geraete, Wand, Karte, Zeit, Notizen, Bericht) |
| `H` | Hinweis anfordern (Gluehbirne) |
| `N` | Notiz anlegen |
| `Esc` | Fenster schliessen |

* **Hinweise:** drei Stufen (Andeutung → konkreter Hinweis → fast direkte Hilfe).
  Spieler haben pro Fall standardmaessig zwei Hinweise, Administratoren unbegrenzt viele.
* **Spracheingabe:** Mikrofon-Symbol im Chat. Der erkannte Text erscheint zuerst im
  Eingabefeld und kann korrigiert werden (nur in Browsern mit Web-Speech-API).
* **Einstellungen:** Lautstaerke, Untertitel, Bildeffekte, Schreckmomente, Bewegung
  reduzieren, Schriftgroesse. `prefers-reduced-motion` wird automatisch beruecksichtigt.

---

## 7. Adminbereich

Erreichbar unter `/admin` (Login mit Administratorkonto):

* **Uebersicht** mit Systemzustand und Protokollauszug
* **Faelle**: anlegen, bearbeiten, duplizieren, importieren, exportieren, veroeffentlichen,
  Versionsverlauf, Konsistenzpruefung, Testmodus
* **Fall-Editor** ohne Programmierkenntnisse: 18 Abschnitte von Stammdaten bis Enden,
  Listenfelder mit Sortieren/Kopieren/Loeschen, Rohdaten-Ansicht als Rueckfallebene
* **Medien**: sichere Uploads, automatische Erzeugung von Vermisstenplakat, FBI-Aktenkarte,
  Profilbild, Kontaktbild, Fall-Cover und Schwarz-Weiss-Dokumentversion (PHP-GD)
* **Spieler**: Konten anlegen, Rollen, Passwoerter, Sperren aufheben, Fortschritt loeschen
* **KI**: Anbieter, Basis-URL, Modell, Schluessel (verschluesselt), Limits, Verbindungstest
* **Einstellungen**: Seite, Spielregeln, Sicherheit, Impressum, Datenschutz
* **Sicherung**: ZIP erstellen, herunterladen, wiederherstellen (mit Sicherheitskopie)
* **Protokolle** und **Diagnose** (inkl. Belastungstest fuer paralleles Schreiben)
* **KI-Vorschlaege** im Editor: jeder Vorschlag ist ein Entwurf und wird erst nach
  manueller Bestaetigung uebernommen

---

## 8. Grenzen der dateibasierten Speicherung

Alle Daten liegen als JSON-Dateien. Schreibvorgaenge laufen ueber Dateisperren (`flock`) und
atomare Umbenennungen; beschaedigte Dateien werden automatisch nach
`storage/backups/corrupt/` gesichert.

Das ist ausgelegt fuer **kleine bis mittlere Spielerzahlen** (Richtwert: bis etwa 150
gleichzeitig aktive Spielende auf typischem Shared Hosting). Fuer deutlich mehr parallele
Zugriffe waere eine Datenbank die richtige Wahl.

---

## 9. Was dieses Spiel nicht tut

* Es greift **keine realen Systeme** an. Alle Logins, Geraete, Netzwerke und
  "Hacking"-Vorgaenge sind Spielinhalte in JSON-Dateien.
* Es sendet **keinen API-Schluessel an den Browser**. KI-Aufrufe laufen ausschliesslich
  serverseitig.
* Es setzt **keine Tracker und keine Werbe-Cookies**. Es gibt genau ein technisch
  notwendiges Sitzungs-Cookie.
* Es speichert **keine unnoetigen personenbezogenen Daten**: Benutzername, optionale
  E-Mail, Passwort-Hash, Spielstand. Konten koennen jederzeit selbst geloescht werden.

---

## 10. Version

Version 1.0.0 · Schema-Version 3 · Deutsch (Oberflaeche und Inhalte)
