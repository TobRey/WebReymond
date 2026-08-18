# Backups und Wiederherstellung

> **Status: Zielbild (Phase 0).** Umgesetzt und geübt wird in **Phase 14** – bewusst *vor* dem ersten
> echten Kunden, nicht danach.

**Grundsatz: Ein Backup gilt erst als funktionierend, wenn mindestens ein Restore erfolgreich
getestet wurde.** Alles andere ist eine Hoffnung, keine Sicherung.

## 1. Was gesichert wird

| Inhalt | Wo es liegt | Wie |
|---|---|---|
| Kundendateien (Websites, Uploads) | `/home/<kunde>/` | restic |
| Kundendatenbanken (MariaDB) | MariaDB | `mysqldump` je Datenbank, dann restic |
| WebReymond-Datenbank (PostgreSQL) | PostgreSQL | `pg_dump`, dann restic |
| HestiaCP-Konfiguration | `/usr/local/hestia/data/` | restic |
| Server-Konfiguration | `/etc/` (relevante Teile) | restic |
| Anwendungscode | GitHub | Git (kein Backup nötig) |
| Secrets | `/opt/webreymond/.env` | **verschlüsselt und getrennt** aufbewahren, nicht im normalen Backup-Lauf |

Nicht gesichert: Caches, temporäre Dateien, Docker-Images (aus der Registry rekonstruierbar).

## 2. Wohin

**Hetzner Storage Box BX11** (1 TB, ≈ CHF 3.00/Monat) – ein anderer Dienst als der Server, damit ein
Serverausfall die Sicherungen nicht mitnimmt.

Werkzeug: **restic** (Open Source, kostenlos).
- **Verschlüsselt vor dem Hochladen** – der Speicheranbieter kann die Daten nicht lesen.
- **Dedupliziert** – tägliche Sicherungen brauchen kaum zusätzlichen Platz.
- Anbieterunabhängig: dasselbe Backup kann später zu einem anderen Anbieter wandern
  (`StorageProvider`-Gedanke, siehe [ARCHITECTURE.md](ARCHITECTURE.md)).

Das **Repository-Passwort von restic** wird an einem zweiten, sicheren Ort ausserhalb des Servers
aufbewahrt. Ohne dieses Passwort ist das Backup unwiederbringlich verloren.

## 3. Zeitplan und Aufbewahrung

| Was | Häufigkeit | Aufbewahrung (Standard) |
|---|---|---|
| Dateien + Datenbanken | täglich nachts | 7 tägliche, 4 wöchentliche, 3 monatliche |
| Vor jedem Deployment mit Migration | zusätzlich | bis zum nächsten regulären Lauf |
| Manuell | auf Knopfdruck im Portal | wie täglich |

Kunden sollen später im Portal zwischen **täglich**, **wöchentlich** und **manuell** wählen können;
die Aufbewahrung ist je Paket konfigurierbar. Technisch umgesetzt über restic-Policies
(`forget --keep-daily … --prune`).

## 4. Wiederherstellung

### Einzelne Datei (häufigster Fall)
1. Im Portal (später) oder per `restic snapshots` den passenden Zeitpunkt wählen.
2. `restic restore <snapshot> --include <pfad> --target /tmp/restore`
3. Datei prüfen, dann an den richtigen Ort zurückkopieren – **nie direkt über das Original**, bevor
   der Inhalt kontrolliert wurde.

### Eine Kundendatenbank
1. Dump aus dem Snapshot zurückholen.
2. In eine **temporäre** Datenbank einspielen und prüfen.
3. Erst dann die produktive Datenbank ersetzen.

### Kompletter Server
Ablauf siehe [DEPLOYMENT.md](DEPLOYMENT.md) Abschnitt 5.

## 5. Restore-Test (Pflicht)

**Mindestens einmal pro Quartal** und zusätzlich nach jeder grösseren Änderung an der
Backup-Konfiguration:

1. Stundenweise einen Testserver mieten (Kosten: Rappen).
2. Backup dort vollständig zurückspielen.
3. Prüfen: Startet die Anwendung? Sind die Daten aktuell? Fehlt etwas?
4. Ergebnis mit Datum in `docs/restore-tests.md` protokollieren (wird in Phase 14 angelegt).
5. Testserver löschen.

Ein Restore-Test, der nicht protokolliert wurde, hat nicht stattgefunden.

## 6. Was noch fehlt (Phase 14)

- [ ] restic auf dem Server einrichten, Storage Box anbinden
- [ ] Backup-Skript inkl. Datenbank-Dumps, im Repository versioniert
- [ ] Zeitplan über systemd-Timer
- [ ] Überwachung: **Alarm, wenn ein Backup ausbleibt** (ein stiller Ausfall ist der gefährlichste)
- [ ] Erster vollständiger Restore-Test, protokolliert
- [ ] Portal-Oberfläche für kundeneigene Backups und Wiederherstellungen
