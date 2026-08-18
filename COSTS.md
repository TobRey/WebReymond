# Kosten

**Recherchestand: 17. August 2026.** Alle Preise sind Momentaufnahmen und müssen vor jeder Bestellung
erneut geprüft werden. Hetzner hat allein 2026 zweimal die Preise angehoben (April und 15. Juni) –
Preise aus Blogartikeln sind grundsätzlich als „unbestätigt“ zu behandeln, bis sie im Bestellprozess
des Anbieters stehen.

**Budget:** max. **10 CHF/Monat** Infrastruktur. Nicht enthalten: Claude-Abo, Kundendomains,
Zahlungsgebühren und andere nutzungsabhängige Kosten.

**Wechselkurs-Annahme:** EUR 1 ≈ CHF 0.94 (August 2026: EUR/CHF um 0.93–0.94).
USD 1 ≈ CHF 0.80 (grob, vor Bestellung prüfen).

---

## 1. Kostenlos (keine Lizenzkosten)

HestiaCP (GPLv3) · nginx · PHP-FPM · MariaDB · PostgreSQL · Node.js · Next.js · Fastify · Prisma ·
Better Auth · pg-boss · Vitest · Playwright · Docker · restic · Let's Encrypt (SSL) ·
Tailscale (kostenloser Tarif) · UptimeRobot (kostenloser Tarif) · GitHub inkl. Actions-Kontingent ·
Stripe im Testmodus · Testumgebungen (OT&E) der Registrare · Hetzner DNS (Zonen + API).

> **Es wird ausdrücklich keine cPanel- oder sonstige Panel-Lizenz gekauft.**

## 2. Kostenpflichtig

| Posten | Anbieter/Produkt | Listenpreis | ≈ CHF/Monat | Benötigt ab | Kostenlose Alternative? |
|---|---|---|---|---|---|
| VPS 2 vCPU / 4 GB RAM / 40 GB NVMe / 20 TB Traffic | Hetzner Cloud **CX23** | € 3.99/Mt. | 3.75 | Phase 7 | Nein – ein Server, der immer läuft, kostet Geld |
| IPv4-Adresse | Hetzner | € 0.50/Mt. | 0.47 | Phase 7 | Nur IPv6 wäre gratis, aber viele Kunden/Netze erreichen die Seite dann nicht |
| Off-Site-Backup 1 TB | Hetzner **Storage Box BX11** | € 3.20/Mt. | 3.00 | Phase 14 | Backup auf demselben Server ist kein Backup |
| Eigene Domains: **webreymond.ch** + **webreymond.com** | Registrar (siehe Abschnitt 5) | **bezahlt – tatsächliche Preise noch nachzutragen** | ~2.00 (Schätzung) | erledigt | Nein |
| Kundenpostfächer | z.B. **Migadu Micro** (Schweizer Anbieter, unbegrenzte Domains/Postfächer, 5 GB) | ab USD 19/Jahr | ~1.50 | Phase 19, erst bei Bedarf | Eigener Mailserver wäre „gratis“, kostet aber RAM und hohes Betriebsrisiko |
| Zahlungsgebühren | **Stripe** Schweiz | 2.9 % + CHF 0.30 (Karte), 1.9 % + CHF 0.30 (TWINT) | variabel | Phase 11 | Nein – jeder seriöse Anbieter nimmt Gebühren |
| Kundendomains | Registrar | Einkaufspreis | durchlaufend | Phase 12 | Nein – wird an Kunden weiterverrechnet (+20 % Marge) |

## 3. Budgetstufen

| Stufe | Wann | Enthalten | CHF/Monat |
|---|---|---|---|
| **A – Entwicklung** | Phase 1–6 | nur eigene Domains, Entwicklung lokal auf dem PC | **≈ 2.00** |
| **B – Server aktiv** | ab Phase 7 | + VPS + IPv4 | **≈ 5.30** |
| **C – produktionsnah** | ab Phase 14 | + Off-Site-Backup | **≈ 8.30** |
| D – erster Mailkunde | ab Phase 19 | + Mail-Abo | ≈ 9.80 |

Alle Stufen liegen innerhalb des Budgets von 10 CHF.

### Wachstumsschwelle

Ein Upgrade auf **Hetzner CX33** (4 vCPU / 8 GB / 80 GB, € 8.49/Mt. ≈ CHF 8.00) hebt die Gesamtkosten
auf ≈ CHF 12.50/Monat und sprengt damit das aktuelle Budget. Auslöser für diese Entscheidung:

- dauerhaft > 80 % RAM-Auslastung, **oder**
- mehr als etwa 10 aktive Kundenwebsites, **oder**
- der erste zahlende Kunde deckt die Differenz (dann ist es keine Budgetfrage mehr).

RAM und CPU lassen sich bei Hetzner ohne Neuinstallation vergrössern. **Die Vergrösserung der
Festplatte ist einmalig und nicht rückgängig zu machen** – deshalb Disk nur vergrössern, wenn nötig.

## 4. Serververgleich (Stand August 2026)

| Anbieter/Produkt | Leistung | Preis | Bindung | Bewertung |
|---|---|---|---|---|
| **Hetzner CX23** ✅ | 2 vCPU, 4 GB, 40 GB NVMe, 20 TB | € 3.99 + € 0.50 IPv4 | keine, stundengenau | **Empfehlung.** Flexibel, API, Snapshots, EU/DSGVO |
| Hetzner CX33 | 4 vCPU, 8 GB, 80 GB | € 8.49 + IPv4 | keine, stundengenau | Upgrade-Ziel |
| netcup VPS Lite 1 G12s | 4 GB RAM | € 4.10 | 6 Monate | Mehr Leistung pro Euro, aber nur 99.0 % Uptime-Zusage, keine stundengenaue Abrechnung |
| netcup VPS 1000 G12 | 8 GB RAM | € 8.45 | 12 Monate | Gutes Angebot, aber lange Bindung |
| Schweizer Anbieter (Infomaniak, Exoscale) | – | deutlich höher | – | Erst relevant, wenn Kunden Schweizer Datenhaltung verlangen |

Der entscheidende Vorteil von Hetzner für dieses Projekt ist die **stundengenaue Abrechnung**:
Ein Test-/Stagingserver kostet für ein paar Stunden nur Rappen. Damit sparen wir die zweite
Monatsmiete für eine dauerhafte Stagingumgebung.

## 5. Registrar-Kandidaten (Entscheidung in Phase 10)

| Anbieter | API | .com / .ch / .de | Eintrittshürde | Anmerkung |
|---|---|---|---|---|
| **INWX** | JSON-/XML-RPC, OT&E-Testumgebung | ja | Guthaben, keine Mitgliedsgebühr | Erstkandidat; INWX AG mit Schweizer Auftritt, deutschsprachiger Support |
| Netim | REST + SOAP | ja | Reseller-Antrag | Solide Alternative |
| CentralNic Reseller (ex RRPproxy) | REST/EPP, Staffelpreise | ja | Prepaid-Guthaben | Interessant ab grösseren Mengen |
| Openprovider | REST | ja | **jährliche Mitgliedsgebühr** | Rechnet sich erst ab vielen Domains |

**Zu prüfen, bevor entschieden wird** (Phase 10, mit echten Konten):
Registrierungspreis · Verlängerungspreis · Transferpreis · Mindestguthaben · Mitgliedschaftskosten ·
API-Limits · Testumgebung vorhanden · Währung und Umrechnung · Zuverlässigkeit/Support.

**Entscheidend ist der Verlängerungspreis, nicht der Werbepreis des ersten Jahres.**

## 6. Marge und Preisgestaltung

Zielaufschlag auf Domains: **20 %**. Aber: Zahlungsgebühren, Rundung, Währungsumrechnung und Steuern
dürfen die Marge nicht auffressen. Rechenbeispiel:

```
Einkauf Domain              CHF 10.00
+ 20 % Aufschlag            CHF 12.00
- Stripe-Gebühr (2.9 %+0.30) CHF  0.65
= Deckungsbeitrag           CHF  1.35   (13.5 %, nicht 20 %)
```

Konsequenz für die **Pricing-Engine (Phase 11)**:
alle Beträge in Rappen als Ganzzahl rechnen (nie Fliesskommazahlen für Geld), Wechselkurs mit
Sicherheitspuffer, Zahlungsgebühren **vor** der Margenberechnung abziehen, kaufmännisch aufrunden,
Mindestmarge je Produkt definieren, und bei Einkaufspreisänderungen automatisch warnen.

## 7. Was bewusst verschoben wird

| Verschoben | Ersparnis | Wiedervorlage |
|---|---|---|
| Zweiter Server / Dauer-Staging | ~CHF 5/Mt. | ab regelmässigem Kundenbetrieb |
| Hetzner-Backupfunktion (+20 % des Serverpreises) | ~CHF 0.80/Mt. | – (restic ist günstiger und portabler) |
| Eigener Mailserver | 0 CHF, aber ~1 GB RAM und hohes Risiko | ab eigenem Mail-Budget |
| Eigene Nameserver | – | ab zweitem Server |
| Object Storage / CDN | – | ab ~50 GB Medien oder internationalem Traffic |
| Monitoring-Stack (Grafana/Loki) | – | ab 8 GB RAM |
| Schweizer Hosting-Standort | ~CHF 10–20/Mt. | wenn Kunden es verlangen |

## 8. Quellen (abgerufen 17.08.2026)

- Hetzner Preisanpassung 15.06.2026 – https://docs.hetzner.com/general/infrastructure-and-availability/price-adjustment/
- Hetzner Cloud (Produktseite) – https://www.hetzner.com/cloud/
- Hetzner CX23 Preisübersicht – https://comparedge.com/tools/hetzner/pricing
- Hetzner CX33 / CX32-Nachfolge – https://vpsfor.dev/posts/hetzner-cx32-pricing-2026/
- Hetzner Storage Box BX11 – https://www.hetzner.com/storage/storage-box/bx11/
- netcup VPS Lite – https://www.netcup.com/de/server/vps-lite
- netcup Preisübersicht 2026 – https://netcupvoucher.com/blog/netcup-pricing-2026
- HestiaCP 1.10.0 Release – https://www.linuxcompatible.org/story/hestiacp-1100-ships-with-php-85-security-overhaul-and-debian-13-support
- HestiaCP 1.9.7 Sicherheitsrelease – https://www.linuxcompatible.org/story/hestiacp-197-drops-security-patch-for-9-vulnerabilities-and-adds-support-for-debian-13-and-ubuntu
- Stripe TWINT – https://stripe.com/de/payment-method/twint
- Stripe Gebühren Schweiz (Übersicht) – https://wise.com/ch/blog/stripe-gebuehren-schweiz
- Schweizer PSP-Vergleich (Anbietersicht, mit Vorsicht lesen) – https://payrexx.com/comparison
- INWX Preisliste – https://www.inwx.com/en/domain/pricelist
- Netim Reseller-API – https://www.netim.com/en/reseller-program/netim-api
- Openprovider Membership – https://www.openprovider.com/membership-pricing
- CentralNic Reseller API – https://www.centralnicreseller.com/api/
- Migadu Preise – https://shipmail.to/vs/migadu
- EUR/CHF-Kurs August 2026 – https://www.exchange-rates.org/exchange-rate-history/chf-eur-2026

> Anbieter-Blogs (z.B. Vergleichstabellen von Payrexx oder Openprovider) sind nie neutral.
> Vor jeder Bestellung gilt der Preis im Bestellprozess des Anbieters, nicht der im Artikel.
