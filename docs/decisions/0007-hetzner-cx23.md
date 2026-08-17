# 0007 – Hetzner Cloud CX23 als erster Server

**Status:** akzeptiert (Phase 0, August 2026) – Bestellung erfolgt erst in Phase 7

## Kontext
Budget: max. 10 CHF/Monat für die gesamte Infrastruktur. Gebraucht wird ein Server, der Hosting-Layer,
WebHeaven und später backendHeaven trägt und später wachsen kann.

## Entscheidung
**Hetzner Cloud CX23** – 2 vCPU, 4 GB RAM, 40 GB NVMe, 20 TB Traffic, Standort Deutschland oder
Finnland. € 3.99/Monat + € 0.50 für die IPv4-Adresse (≈ CHF 4.20).

## Begründung
- Passt mit Abstand ins Budget und lässt Raum für Backup-Speicher.
- **Stundengenaue Abrechnung ohne Mindestlaufzeit:** Ein Test-/Stagingserver für ein paar Stunden
  kostet Rappen. Das ersetzt eine dauerhafte, teure Stagingumgebung.
- RAM und CPU sind ohne Neuinstallation vergrösserbar (Upgrade-Ziel: CX33 mit 8 GB).
- API, Snapshots, Firewall und DNS-Zonen sind kostenlos enthalten – später automatisierbar.
- EU-Standort, DSGVO-konform; für Schweizer Kunden in der Regel akzeptabel.

## Alternativen
- **netcup VPS Lite 1 G12s** (4 GB, € 4.10): mehr Leistung pro Euro, aber 6 Monate Mindestlaufzeit,
  keine stundengenaue Abrechnung und nur 99.0 % Uptime-Zusage.
- **netcup VPS 1000 G12** (8 GB, € 8.45): gutes Angebot, aber 12 Monate Bindung.
- **Schweizer Anbieter** (Infomaniak, Exoscale): deutlich teurer; erst relevant, wenn Kunden
  Schweizer Datenhaltung verlangen.

## Konsequenzen
- 4 GB RAM sind knapp → HestiaCP ohne Mail (ADR 0006), keine Redis (ADR 0004), backendHeaven
  mandantenfähig (ADR 0009), Swap-Datei einrichten, Speicherverbrauch beobachten.
- **Die Vergrösserung der Festplatte ist bei Hetzner einmalig und nicht rückgängig zu machen** –
  Disk nur vergrössern, wenn wirklich nötig.
- Preise 2026 sind volatil (zwei Erhöhungen im selben Jahr) → vor der Bestellung erneut prüfen.

## Wiedervorlage
Bei dauerhaft > 80 % RAM-Auslastung, mehr als ~10 aktiven Kundenwebsites oder sobald ein zahlender
Kunde die Differenz zum CX33 deckt.
