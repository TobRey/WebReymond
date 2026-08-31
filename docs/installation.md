# WebAtze auf GoDaddy einrichten

Diese Anleitung führt vom fertigen Paket bis zur laufenden Website.
Sie braucht keine Kommandozeile und keine Vorkenntnisse.
Zeitbedarf: etwa zwanzig Minuten.

---

## Was gebraucht wird

- Ein Hosting mit **cPanel** und **PHP 8.1 oder neuer** (GoDaddy erfüllt das)
- Die Datei `webatze-1.0.0.zip`
- Ein Anthropic-Schlüssel – **kann auch später nachgetragen werden**

Ohne Schlüssel läuft alles, nur der Generator arbeitet im Übungsmodus:
Struktur und Vorlagen entstehen, die Texte sind Platzhalter.

---

## 1. Datenbank anlegen

In cPanel unter **Datenbanken → MySQL-Datenbank-Assistent**:

1. **Datenbankname** eingeben, zum Beispiel `db_webatze` → *Nächster Schritt*
2. **Benutzernamen** und ein **Passwort** vergeben → *Benutzer erstellen*
3. Beim Rechte-Bildschirm **Alle Rechte** ankreuzen → *Nächster Schritt*

> **Wichtig:** cPanel stellt beiden Namen ein Kürzel voran. Aus `db_webatze`
> wird zum Beispiel `abc123_db_webatze`. Diese **vollständigen** Namen werden
> gleich gebraucht – am besten sofort notieren.

---

## 2. Paket hochladen und entpacken

In cPanel unter **Dateien → Dateimanager**:

1. In den Ordner **`public_html`** wechseln
2. Oben auf **Hochladen** klicken und `webatze-1.0.0.zip` auswählen
3. Zurück zum Dateimanager, die Datei anklicken, oben auf **Extrahieren**
4. Als Ziel `/public_html` bestätigen
5. Danach die ZIP-Datei löschen – sie wird nicht mehr gebraucht

Im Ordner `public_html` sollten jetzt unter anderem `index.php`,
`install.php`, `app/` und `assets/` liegen.

> Liegt dort noch eine Datei von GoDaddy (oft `index.html` oder eine
> Platzhalterseite), muss sie weg – sonst zeigt der Browser sie statt der
> eigenen Website.

---

## 3. Einrichten

Im Browser die eigene Adresse aufrufen und `/install.php` anhängen:

```
https://deine-domain.ch/install.php
```

Die Seite prüft zuerst, was der Server mitbringt. Steht überall ein Haken,
wird das Formular ausgefüllt:

| Feld | Was hineingehört |
|---|---|
| **Adresse der Website** | Genau so, wie die Seite aufgerufen wird – mit oder ohne `www`, aber einheitlich |
| **Server** | `localhost` |
| **Datenbankname** | Der **vollständige** Name aus Schritt 1, mit Kürzel |
| **Benutzername** | Ebenfalls der vollständige Name |
| **Passwort** | Das Passwort aus Schritt 1 |
| **Benutzername (Konto)** | Der eigene Anmeldename für den versteckten Bereich |
| **Versteckte Adresse** | `create` – oder etwas anderes, das niemand errät |
| **Passwort (Konto)** | Mindestens zehn Zeichen |
| **Anthropic-Schlüssel** | Beginnt mit `sk-ant-`, darf leer bleiben |

Dann auf **Einrichten**. Die Seite legt alles an und **löscht sich danach
selbst**.

> **Zur Adresse:** Stimmt sie nicht mit der überein, unter der die Seite
> wirklich aufgerufen wird, weist der Sicherheitsschutz jedes Formular ab –
> die Anmeldung würde einfach nichts tun. Der Adminbereich meldet das später
> unter *Einstellungen → Selbstprüfung*.

---

## 4. Cronjob einrichten

Ohne ihn werden Aufträge nur beim Anlegen bearbeitet und alte Vorschauen
nie aufgeräumt.

In cPanel unter **Erweitert → Cron-Jobs**:

1. Bei *Allgemeine Einstellungen* **Einmal pro Minute** wählen
2. Als Befehl die Zeile einsetzen, die die Einrichtung angezeigt hat.
   Sie sieht etwa so aus:

   ```
   * * * * * /usr/local/bin/php /home/BENUTZER/public_html/worker.php >/dev/null 2>&1
   ```

3. **Neuen Cron-Job hinzufügen**

Ob er läuft, steht im Adminbereich unter *Einstellungen → Selbstprüfung*.

---

## 5. Fertig

- Die Website läuft unter `https://deine-domain.ch`
- Der versteckte Bereich unter `https://deine-domain.ch/create`

Er ist nirgends verlinkt und für Suchmaschinen gesperrt. Erreichbar ist er
nur, wer die Adresse kennt.

---

## Danach

### Angaben ergänzen

Unter **Einstellungen** stehen Firmenname, Adresse, E-Mail und Telefon.
Sie erscheinen im Fussbereich, im Kontaktbereich und im Impressum. Solange
etwas fehlt, weist der Adminbereich darauf hin – ein unvollständiges
Impressum ist in der Schweiz und in der EU nicht zulässig.

### Passwörter wechseln

Wurden Zugangsdaten irgendwo aufgeschrieben oder verschickt, gehören sie
gewechselt:

- **Datenbank:** cPanel → *MySQL-Datenbanken* → beim Benutzer auf
  *Passwort ändern*. Danach dasselbe Passwort in `app/config.php` eintragen
  (Dateimanager → `public_html/app/config.php` → *Bearbeiten*).
- **Eigenes Konto:** im Adminbereich unter *Einstellungen → Passwort ändern*.

### Sichern

Zu sichern sind zwei Dinge:

- `public_html/app/config.php` – die Zugangsdaten
- Die Datenbank – cPanel → *phpMyAdmin* → Datenbank wählen → *Exportieren*

Die erzeugten Kundenwebsites liegen zusätzlich als Paket unter
`public_html/storage/zips/` und lassen sich im Adminbereich herunterladen.

---

## Wenn etwas klemmt

### Die Seite bleibt weiss

Meist fehlt eine PHP-Erweiterung oder die PHP-Version ist zu alt. In cPanel
unter **MultiPHP Manager** auf **PHP 8.1** oder neuer stellen, und unter
**PHP-Erweiterungen auswählen** sicherstellen, dass `pdo_mysql`, `zip`,
`gd`, `curl`, `mbstring` und `sodium` aktiv sind.

Was genau fehlt, zeigt `install.php` – solange sie noch da ist.

### „Die Datenbank antwortet nicht"

Fast immer fehlt das Kürzel vor dem Datenbanknamen oder der Benutzer ist der
Datenbank nicht zugewiesen. In cPanel unter *MySQL-Datenbanken* ganz unten
nachsehen: Dort steht, welcher Benutzer zu welcher Datenbank gehört.

### Die Anmeldung tut nichts

Dann stimmt `app_url` in `app/config.php` nicht mit der aufgerufenen Adresse
überein – typischerweise `www` zu viel oder zu wenig. Berichtigen, dann geht
es sofort.

### Aufträge bleiben stehen

Der Cronjob läuft nicht. Unter *Einstellungen → Selbstprüfung* steht, wann
er zuletzt gelaufen ist.

### Ein Auftrag ist fehlgeschlagen

Er bleibt auf der Übersicht stehen, bis er angesehen wurde – ein Fehler
verschwindet nie stillschweigend. Die Meldung nennt die Ursache; die
ausführliche Fassung steht in `storage/logs/`.
