# Sky Kingdoms installieren

Diese Anleitung ist für cPanel-Hosting geschrieben (GoDaddy, Hostpoint, Namecheap und
ähnliche). Sie dauert etwa **fünf Minuten**. Du brauchst **keine Datenbank**, keinen
SSH-Zugang, kein Node.js und keinen Cronjob.

---

## Was der Server können muss

| Anforderung | Wert |
|---|---|
| PHP | **8.2 oder neuer** (getestet mit 8.2, 8.3 und 8.4) |
| PHP-Erweiterungen | `json`, `mbstring`, `session` – alle drei sind praktisch überall vorhanden |
| Optional | `zip` (Sicherungen herunterladen), `gd` (eigene Symbole erzeugen), `openssl` (SMTP mit TLS), `curl` (Selbsttest) |
| Schreibrechte | Auf die Ordner `config/` und `storage/` |
| Datenbank | **keine** – alle Spielstände liegen als Dateien |

---

## Schritt für Schritt

### 1. ZIP hochladen

1. Melde dich im **cPanel** an und öffne den **Dateimanager**.
2. Wechsle in den Ordner, in dem das Spiel liegen soll. Das darf sein:
   - `public_html` (Spiel direkt unter `deine-domain.de`)
   - `public_html/spiel` (Spiel unter `deine-domain.de/spiel`)
   - das Document Root einer Subdomain, z. B. `spiel.deine-domain.de`

   **Alle drei Varianten funktionieren ohne Anpassung.** Das Spiel erkennt seinen
   Ordner selbst.
3. Klicke oben auf **Hochladen** und wähle `sky-kingdoms-1.0.0.zip`.
4. Zurück im Dateimanager: Rechtsklick auf die ZIP → **Extract** (Entpacken).
5. Die ZIP kannst du danach löschen.

Nach dem Entpacken liegen im Ordner unter anderem:
`index.php`, `install/`, `api/`, `admin/`, `app/`, `assets/`, `config/`, `storage/`.

> Es gibt **keinen** zusätzlichen Oberordner. Falls dein Entpacker doch einen anlegt,
> verschiebe den Inhalt eine Ebene nach oben.

### 2. PHP-Version prüfen

Im cPanel unter **MultiPHP Manager** (oder „Select PHP Version") die Domain auswählen
und **PHP 8.2 oder neuer** einstellen.

### 3. Schreibrechte setzen

Im Dateimanager mit Rechtsklick → **Change Permissions**:

| Ordner | Rechte |
|---|---|
| `config` | 755 (bei Fehlern: 775) |
| `storage` | 755 (bei Fehlern: 775) |

Meist stimmen die Rechte schon nach dem Entpacken. Der Installer sagt dir im nächsten
Schritt genau, ob etwas fehlt.

### 4. Installer aufrufen

Öffne im Browser die Adresse deines Ordners mit `/install/` am Ende, zum Beispiel:

```
https://deine-domain.de/install/
https://deine-domain.de/spiel/install/
https://spiel.deine-domain.de/install/
```

Der Installer führt dich durch fünf Schritte:

1. **Systemprüfung** – alles Grüne ist in Ordnung. Rote Punkte erklären, was zu tun ist.
   Gelbe Punkte sind optional.
2. **Grundeinstellungen** – Spielname, Zeitzone, wie viele Stunden Abwesenheit
   nachgerechnet werden, ob sich jeder registrieren darf.
3. **Administratorkonto** – dein Spielername, deine E-Mail-Adresse, dein Passwort.
   Dieses Konto ist gleichzeitig dein Spielerkonto **und** dein Zugang zur Verwaltung.
4. **Übersicht** – letzte Kontrolle, dann auf „Jetzt installieren".
5. **Fertig** – der Installer sperrt sich selbst.

### 5. Aufräumen (empfohlen)

Lösche im Dateimanager den Ordner **`install/`**. Nötig ist das nicht – der Assistent
sperrt sich selbst –, aber es ist sauberer.

### 6. Spielen

```
https://deine-domain.de/            → Anmeldung
https://deine-domain.de/admin/      → Verwaltung
```

Auf dem Handy kannst du das Spiel über „Zum Home-Bildschirm hinzufügen" wie eine App
installieren (PWA).

---

## Häufige Fragen

**Muss ich eine MySQL-Datenbank anlegen?**
Nein. Sky Kingdoms speichert alles in Dateien unter `storage/data`. Es gibt keine
Zugangsdaten, nichts einzurichten und nichts, was kaputtgehen kann.

**Sind meine Spielstände öffentlich erreichbar?**
Nein, doppelt abgesichert:
1. `.htaccess`-Dateien sperren die Ordner.
2. Jede Datendatei ist zusätzlich eine PHP-Datei, die beim Aufruf sofort abbricht und
   nichts ausgibt. Das wirkt auch dann, wenn `.htaccess` nicht beachtet wird.

Der Installer prüft das am Ende selbst und sagt dir das Ergebnis.

**Mein Hoster erlaubt keine `.htaccess` – was nun?**
Das Spiel läuft trotzdem, und die Spielstände bleiben durch den PHP-Wächter geschützt.
Noch sicherer: Verschiebe `storage/data` aus dem Web-Ordner heraus und trage den neuen
Pfad in `config/config.php` bei `data_dir` ein (absoluter Pfad erlaubt).

**Die Seite zeigt nur eine leere Seite oder „500".**
Meist ist die PHP-Version zu alt. Stelle im MultiPHP Manager 8.2+ ein.
Zum Fehlersuchen kannst du in `config/game.php` `'debug' => true` setzen –
**danach unbedingt wieder auf `false` stellen.**

**Kann ich das Spiel später in einen anderen Ordner verschieben?**
Ja. Ordner verschieben, fertig – alle Adressen richten sich automatisch neu aus.
Nur die Adresse für E-Mails solltest du im Adminbereich prüfen.

**Wie sichere ich meine Spielstände?**
Adminbereich → Diagnose → „Sicherung aller Spielstände herunterladen" (ZIP).
Oder im Dateimanager den Ordner `storage/data` komprimieren und herunterladen.

**Wie stelle ich eine Sicherung wieder her?**
Inhalt des Ordners `daten/` aus der ZIP zurück nach `storage/data/` kopieren.

**Wie ändere ich den Spielnamen?**
Adminbereich → Einstellungen. Oder direkt in `config/game.php` bei `'name'`.

**Wie viele Spieler verträgt das?**
Auf gewöhnlichem Shared Hosting sind einige hundert Konten problemlos. Für sehr viel
mehr empfiehlt sich ein eigener Server – die Speicherschicht ist dafür austauschbar
aufgebaut (siehe `GAME_DESIGN.md`).

---

## Ohne Hochladen testen

Auf dem eigenen Rechner mit PHP 8.2+:

```bash
cd sky-kingdoms
php -S localhost:8000
```

Dann `http://localhost:8000/install/` aufrufen.

Die mitgelieferten Prüfungen:

```bash
php tests/run.php     # Formeln, Simulation, Speicher, Sicherheit
php tools/smoke.php   # Vollständiger Durchlauf gegen einen echten Webserver
```
