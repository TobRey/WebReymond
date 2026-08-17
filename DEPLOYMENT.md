# Deployment

> **Status: Zielbild (Phase 0).** Konkrete Befehle und Konfigurationsdateien entstehen in Phase 7
> (Server + Härtung) und Phase 8 (HestiaCP + erstes Deployment). Dieses Dokument beschreibt, wie es
> aussehen wird und welche Regeln dabei gelten – damit später nichts improvisiert wird.

## 1. Umgebungen

| Umgebung | Wo | Zweck | Daten |
|---|---|---|---|
| **Entwicklung** | Windows-PC mit WSL2 + Docker Compose | tägliches Arbeiten | Testdaten |
| **Test/Staging** | Hetzner-Server, **stundenweise** gemietet | Prüfen vor dem Ausrollen | Kopie ohne echte Kundendaten |
| **Produktion** | Hetzner CX23 | echte Kunden | echte Daten |

Warum kein Dauer-Staging: Ein zweiter Server würde das Budget verdoppeln. Hetzner rechnet
stundengenau – ein Testserver für zwei Stunden kostet Rappen. **Nie experimenteller Code auf echten
Kundendaten.**

## 2. Aufbau auf dem Produktivserver

```
Debian 13 (oder Ubuntu LTS)
├── HestiaCP (ohne Exim/Dovecot/ClamAV/SpamAssassin)
│   ├── nginx            → Ports 80/443, einziger öffentlicher Zugang
│   ├── PHP-FPM je Kunde → Kundenwebsites
│   └── MariaDB          → Kundendatenbanken
├── Docker Compose (WebHeaven)
│   ├── portal   (Next.js)     → 127.0.0.1:3000
│   ├── api      (Fastify)     → 127.0.0.1:3001
│   ├── worker   (pg-boss)     → kein offener Port
│   └── postgres (PostgreSQL)  → 127.0.0.1:5432, Volume auf dem Host
└── Tailscale → SSH und HestiaCP-Oberfläche nur über VPN
```

nginx (von Hestia verwaltet) leitet `portal.<domain>` an `127.0.0.1:3000` und `api.<domain>` an
`127.0.0.1:3001` weiter. SSL-Zertifikate kommen von Let's Encrypt über Hestia – dadurch gibt es
genau **einen** Ort, der Zertifikate verwaltet.

## 3. Ablauf einer Veröffentlichung

1. Änderung auf einem `feature/…`-Branch entwickeln, lokal testen.
2. Pull Request nach `main`. GitHub Actions führt aus: Lint, Typprüfung, Unit- und Integrationstests,
   Build. **Rot = kein Merge.**
3. Merge nach `main` löst den Deploy-Workflow aus:
   - Docker-Images bauen und in die GitHub Container Registry pushen
   - per SSH (Deploy-Schlüssel) auf dem Server: `docker compose pull && docker compose up -d`
   - **Datenbankmigrationen laufen als eigener Schritt vor dem Start der neuen Version**
   - Health-Check; schlägt er fehl, wird die vorherige Image-Version wieder gestartet
4. Ergebnis im Adminbereich und per Uptime-Check kontrollieren.

## 4. Regeln

- **Nie direkt auf dem Server Code ändern.** Was nicht im Repository steht, existiert nach dem
  nächsten Deployment nicht mehr.
- **Migrationen sind vorwärtskompatibel** geschrieben (erst Spalte hinzufügen, dann Code umstellen,
  dann alte Spalte entfernen) – so ist ein Rollback ohne Datenverlust möglich.
- **Secrets** liegen ausschliesslich in `/opt/webheaven/.env` (Rechte `600`) und in den
  GitHub-Actions-Secrets. Nie im Image, nie im Repository.
- **Vor jedem Deployment mit Migration:** aktuelles Backup vorhanden (siehe [BACKUP.md](BACKUP.md)).
- **Kein Dienst läuft als root**, Container laufen mit unprivilegiertem Benutzer.

## 5. Wiederherstellung des ganzen Servers („Server ist weg“)

Ziel: aus dem Repository plus Backup in überschaubarer Zeit wieder betriebsbereit sein.

1. Neuen Hetzner-Server bestellen (gleiche Distribution).
2. Härtungsskript aus dem Repository ausführen (Phase 7 legt es an).
3. HestiaCP installieren – mit denselben Optionen wie dokumentiert (ohne Mail).
4. `docker compose up -d` mit den Images aus der Registry.
5. Daten aus dem restic-Backup zurückspielen (siehe [BACKUP.md](BACKUP.md)).
6. DNS auf die neue IP-Adresse zeigen lassen; SSL neu ausstellen.
7. Funktionsprüfung nach der Checkliste in [SECURITY.md](SECURITY.md) Abschnitt 8.

Dieser Ablauf wird in Phase 14 einmal vollständig geübt – auf einem stundenweise gemieteten Server.

## 6. Offene Punkte für Phase 7/8

- [ ] Distribution festlegen (Debian 13 vs. Ubuntu LTS, abhängig von der HestiaCP-Empfehlung)
- [ ] Härtungsskript schreiben und im Repository ablegen
- [ ] `docker-compose.yml` und nginx-Weiterleitungen erstellen
- [ ] Deploy-Workflow in GitHub Actions inkl. Health-Check und Rollback
- [ ] Swap-Datei konfigurieren (bei 4 GB RAM sinnvoll)
- [ ] Uptime-Überwachung einrichten
