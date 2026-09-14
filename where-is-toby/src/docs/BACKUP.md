# Sicherung und Wiederherstellung

## 1. Was gesichert werden muss

| Pfad | Inhalt | Pflicht |
|---|---|---|
| `storage/` (oder `../wit_data`) | Konten, Faelle, Spielstaende, Einstellungen, Protokolle | ja |
| `app/config.local.php` | Pfade und **App-Schluessel** | ja |
| `uploads/` | hochgeladene und erzeugte Medien | ja, wenn eigene Medien verwendet werden |
| Programmdateien (`app/`, `assets/`, `index.php` ...) | Code | nein (steckt in der ZIP-Datei) |

> Ohne `app/config.local.php` laesst sich ein gespeicherter API-Schluessel nicht mehr
> entschluesseln. Alles andere bleibt lesbar.

---

## 2. Sicherung im Adminbereich

**Adminbereich → Sicherung → Sicherung erstellen**

* erzeugt eine ZIP-Datei unter `storage/backups/` (ohne Sitzungen und Zwischenspeicher)
* Haken "Uploads mitsichern" nimmt zusaetzlich `uploads/` auf
* die Liste zeigt Dateiname, Groesse und Zeitpunkt; ueber **Herunterladen** kommt die Datei
  auf den eigenen Rechner
* es werden automatisch die letzten 12 Sicherungen behalten

Fehlt die PHP-Erweiterung `zip`, wird stattdessen ein JSON-Archiv der Datenverzeichnisse
erzeugt. Uploads sind darin nicht enthalten.

---

## 3. Wiederherstellung

**Adminbereich → Sicherung → Wiederherstellen**

1. Das System legt zuerst automatisch eine Sicherheitskopie des aktuellen Stands an.
2. Danach werden die Dateien aus dem Archiv zurueckgespielt.
3. Sitzungen werden nicht ueberschrieben; alle Spielenden bleiben angemeldet.
4. Zur Bestaetigung muss das Wort `WIEDERHERSTELLEN` eingegeben werden.

Wiederhergestellt werden nur Pfade innerhalb von `storage/` und `uploads/`. Ausfuehrbare
Dateien werden dabei uebersprungen.

---

## 4. Sicherung ohne Adminbereich (cPanel)

1. cPanel → **File Manager** → Ordner `storage` markieren → **Compress** → ZIP.
2. ZIP herunterladen und ausserhalb des Servers aufbewahren.
3. Ebenso `app/config.local.php` und gegebenenfalls `uploads/`.

Alternativ legt cPanel unter **Backup → Download a Full Account Backup** ein komplettes
Konto-Archiv an.

---

## 5. Umzug auf einen anderen Server

1. Neue Installation mit der ZIP-Datei durchfuehren (Installer bis zum Ende).
2. `storage/` der alten Installation ueber das neue `storage/` kopieren.
3. `app/config.local.php` der **neuen** Installation behalten, aber `app_key` aus der alten
   Datei uebernehmen - sonst geht der verschluesselte API-Schluessel verloren.
4. Im Adminbereich die Diagnose ausfuehren.

---

## 6. Empfohlener Rhythmus

| Situation | Empfehlung |
|---|---|
| Vor jeder Aenderung an Faellen | Sicherung erstellen (dauert Sekunden) |
| Laufender Betrieb | woechentlich, zusaetzlich cPanel-Vollsicherung |
| Vor einem Update der Anwendung | Sicherung **und** Download |

---

## 7. Beschaedigte Dateien

Beim Lesen einer defekten JSON-Datei wird sie automatisch nach
`storage/backups/corrupt/<name>_<zeit>.corrupt.json` gesichert, im Protokoll vermerkt und
durch Standardwerte ersetzt. Die Diagnose zeigt, wenn solche Dateien vorliegen. In der Regel
laesst sich der Inhalt von Hand reparieren (meist fehlt eine schliessende Klammer).
