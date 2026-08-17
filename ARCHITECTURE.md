# Architektur

Stand: Phase 0 (August 2026). Dieses Dokument beschreibt das Zielbild und begründet die Entscheidungen.
Es wird in jeder Phase fortgeschrieben.

## 1. Leitgedanken

1. **Eine Oberfläche für Kunden.** Kunden sehen nur WebHeaven. HestiaCP ist ein internes Werkzeug und
   aus dem Internet nicht erreichbar.
2. **Klein starten, gross werden können.** Alles läuft zunächst auf einem einzigen Server, ist aber so
   getrennt, dass einzelne Teile später auf eigene Server umziehen können.
3. **Keine freie Shell.** Systemänderungen laufen ausschliesslich über eine feste Liste erlaubter,
   typisierter Aktionen – nie über zusammengebaute Kommandozeilen.
4. **Austauschbare Anbieter.** Registrar, DNS, Zahlung, E-Mail, Backup-Ziel stecken hinter Interfaces.
5. **Sparsam mit Arbeitsspeicher.** Das Budget (max. 10 CHF/Monat) bedeutet 4 GB RAM. Jede Komponente
   muss sich rechtfertigen.

## 2. Systemüberblick

```
                          Internet
                             │
                    ┌────────┴─────────┐
                    │  nginx (Hestia)  │  Ports 80/443 – der einzige offene Zugang
                    └────────┬─────────┘
         ┌───────────────────┼────────────────────────┐
         │                   │                        │
         ▼                   ▼                        ▼
  Kundenwebsites      WebHeaven Portal          backendHeaven
  statisch / PHP-FPM   (Next.js, :3000)      (mandantenfähige Laufzeit)
  je Kunde ein            │                        │
  Linux-Benutzer          ▼                        │
                    WebHeaven API  (Fastify, :3001)│
                          │                        │
              ┌───────────┼──────────────┬─────────┘
              ▼           ▼              ▼
        PostgreSQL     Worker      Provider-Adapter
        (Daten,      (Jobs, Retries,  ├─ DomainProvider  → Registrar-API
         Jobs,        Statusmaschine) ├─ DnsProvider     → DNS-API
         Audit-Log)        │          ├─ PaymentProvider → Stripe
                           ▼          ├─ MailProvider    → externer Mail-Anbieter
                  Provisioning-Adapter└─ StorageProvider → Backup-Ziel
                           │
                  feste Befehls-Allowlist
                           │
                     HestiaCP-CLI  (v-add-user, v-add-domain, …)
```

Nur `nginx` ist von aussen erreichbar. Portal, API, Worker und Datenbank lauschen ausschliesslich auf
`127.0.0.1`. SSH und die HestiaCP-Oberfläche sind nur über ein privates VPN (Tailscale) erreichbar.

## 3. Komponenten

| Komponente | Technik | Aufgabe |
|---|---|---|
| **Portal** | Next.js (App Router), React, TypeScript | Kundenportal, Administratorbereich, öffentliche Website |
| **API** | Fastify, Zod, OpenAPI | Geschäftslogik, Autorisierung, alle Datenzugriffe |
| **Worker** | Node.js + pg-boss | Provisioning-Jobs, Webhooks, wiederkehrende Aufgaben |
| **Datenbank** | PostgreSQL 17 + Prisma | WebHeaven-Daten, Jobs, Audit-Log |
| **Hosting-Layer** | HestiaCP (ohne Mail-Teile) | nginx, PHP-FPM, Linux-Benutzer, SSL, MariaDB, Kunden-Backups |
| **Kundendatenbanken** | MariaDB (von Hestia verwaltet) | Datenbanken der Kundenwebsites |
| **backendHeaven** | TypeScript, mandantenfähig | CMS + Website-Auslieferung für viele Kundenwebsites |
| **Backups** | restic → Hetzner Storage Box | verschlüsselte Off-Site-Sicherung |

## 4. Warum diese Technik?

**TypeScript überall.** Eine Sprache für Portal, API, Worker und CMS. Für einen Einzelbetreiber ist
das der grösste Effizienzgewinn: kein Kontextwechsel, gemeinsame Typen, gemeinsame Tests.

**Next.js für die Oberfläche.** Serverseitiges Rendern ergibt schnelle Ladezeiten und gute Core Web
Vitals. Öffentliche Website, Kundenportal und Adminbereich in einer Anwendung – bei minimalistischem
Funktionsumfang ist das einfacher zu pflegen als drei getrennte Frontends.

**Fastify als eigene API statt „alles in Next.js“.** Provisioning läuft asynchron und teilweise
minutenlang; Webhooks von Stripe müssen unabhängig vom UI-Deployment erreichbar sein. Eine getrennte
API lässt sich ausserdem später auf einen eigenen Server verschieben, ohne das Portal anzufassen.

**PostgreSQL für WebHeaven, MariaDB für Kunden.** PostgreSQL bietet die Transaktionssicherheit, die
wir für Bestellungen und Provisioning brauchen. MariaDB bleibt, weil PHP-Anwendungen der Kunden es
erwarten und Hestia es ohnehin mitbringt. Die beiden Welten sind strikt getrennt.

**pg-boss statt Redis/BullMQ.** Job-Queue in der bestehenden PostgreSQL-Datenbank: ein Dienst weniger,
rund 50–100 MB RAM gespart und – wichtiger – Jobs können in derselben Transaktion wie die
Geschäftsdaten angelegt werden. Genau das verhindert den Fall „Bestellung gespeichert, Job verloren“.

**Better Auth statt fertigem Login-Dienst.** Self-hosted, Sessions in der Datenbank, TOTP-MFA,
Rate-Limits, keine laufenden Kosten, kein Vendor-Lock-in. Keycloak wäre zu schwer für 4 GB RAM,
Auth0/Clerk kosten ab wenigen Nutzern Geld.

**Docker Compose statt Kubernetes.** Reproduzierbares Deployment ohne Cluster-Overhead. Kubernetes
löst Probleme, die wir bei einem Server nicht haben.

**HestiaCP ohne Mail-Komponenten.** Spart rund 1 GB RAM und entfernt das grösste Betriebsrisiko
(Spam, Blacklisting, Mail-Queues). E-Mail für Kunden wird bei einem spezialisierten Anbieter
eingekauft und über die WebHeaven-Oberfläche verwaltet – siehe [COSTS.md](COSTS.md).

## 5. Mandantenmodell (Mandantentrennung)

Drei Ebenen, die zusammenwirken:

| Ebene | Trennung durch |
|---|---|
| **Betriebssystem** | Jeder Hostingkunde hat einen eigenen Linux-Benutzer, ein eigenes Home-Verzeichnis und einen eigenen PHP-FPM-Pool mit `open_basedir`. |
| **Datenbank (Kunde)** | Eigene MariaDB-Datenbank + eigener Datenbankbenutzer je Kunde, Rechte nur auf die eigene Datenbank. |
| **Anwendung (WebHeaven / backendHeaven)** | Jede Abfrage geht über eine Repository-Schicht, die `tenantId` verpflichtend verlangt. `tenantId` kommt **immer** aus der Session, nie aus dem Request. |

Zusätzlich: Autorisierungstests, die gezielt versuchen, auf fremde Objekte zuzugreifen (IDOR-Tests).
Ein Test, der ohne Berechtigung Daten sieht, ist ein Release-Blocker.

## 6. Provisioning: wie WebHeaven den Server steuert

Der gefährlichste Teil des Systems. Deshalb strikt geregelt:

1. Der Kunde löst im Portal eine **Absicht** aus („Hosting erstellen“), keine Befehle.
2. Die API legt einen **Job** in PostgreSQL an – in derselben Transaktion wie die Bestellung.
3. Der Worker führt den Job über eine **Statusmaschine** aus, Schritt für Schritt:
   `pending → running → step_done(x) → completed | failed`.
4. Jeder Schritt ist **idempotent**: Ein zweiter Durchlauf richtet keinen Schaden an
   („existiert schon“ ist ein Erfolg, kein Fehler).
5. Systembefehle laufen über den **Provisioning-Adapter**: eine feste Liste erlaubter Aktionen mit
   typisierten Parametern. Aufruf per `execFile` mit Argument-Array, niemals per Shell-String.
   Jeder Parameter wird gegen ein striktes Schema geprüft (erlaubte Zeichen, Länge, Format).
6. Jeder Schritt schreibt einen **Audit-Log-Eintrag** (wer, was, wann, Ergebnis).
7. Fehler führen zu **Retries mit Backoff**; unlösbare Fehler landen sichtbar im Adminbereich unter
   „fehlgeschlagene Jobs“ – niemals stillschweigend.

Beispielablauf „Domain gekauft“:

```
registerDomain → createHostingAccount → createWebDirectory → setPermissions
   → createDatabase (falls nötig) → createDnsRecords → issueSslCertificate
   → prepareMailbox (falls gebucht) → updatePortalState
```

Schlägt Schritt 4 fehl, bleibt die Domain registriert (Schritt 1 wird nicht rückgängig gemacht –
Domains gehen nie verloren). Der Job bleibt wiederaufnehmbar.

## 7. backendHeaven: mandantenfähig statt „eine Installation pro Kunde“

**Entscheidung:** Eine backendHeaven-Laufzeit bedient viele Kundenwebsites. Die Zuordnung erfolgt
über die angefragte Domain.

Begründung: Ein eigener Node-Prozess je Kunde bräuchte 150–250 MB RAM – bei 4 GB wären nach etwa
fünf Kunden Schluss, und der Serverpreis würde linear mit der Kundenzahl steigen. Mandantenfähigkeit
hält die Serverkosten flach; der Preis dafür ist, dass die Tenant-Grenze in der Anwendung sauber
erzwungen werden muss (siehe Abschnitt 5).

„1-Klick-Installation“ bedeutet dadurch: Tenant anlegen, Datenbankschema anlegen, Zugangsdaten
erzeugen, Domain verbinden, SSL ausstellen, Adminbenutzer erstellen – **kein** neuer Serverprozess.

Datenmodell des Editors (bewusst **kein** freies HTML):

```
Page → Section → Container → Component → Properties
```

Gespeichert als strukturierter Komponentenbaum. Nur so bleiben Templates, Versionierung und
responsive Overrides möglich. Responsive Werte kaskadieren: Desktop → Tablet → Mobil, wobei jede
Ebene den geerbten Wert überschreiben kann.

## 8. Internationalisierung

Deutsch und Englisch ab dem ersten Tag. Keine Texte direkt im Code, sondern Nachrichtenkataloge
(`de.json` / `en.json`). Sprache wählbar pro Benutzer und pro gehosteter Website.

## 9. Design-System

Zentrale Design-Tokens als CSS-Variablen (Farben, Abstände, Radien, Typografie, Schatten).
Logo und Firmenfarben werden später an genau einer Stelle eingetragen und wirken sich auf WebHeaven
und backendHeaven gleichzeitig aus. Stil: viel Weissraum, klare Typografie, wenige Farben, dezente
Animationen, kleine Komponentenbibliothek.

## 10. Performance-Grundsätze

Kleine Bundles, Lazy Loading, optimierte Bilder, Caching mit klaren Invalidierungsregeln,
Datenbankindizes für jede Filterspalte, Pagination statt „alles laden“, Vorbereitung für ein CDN
(alle statischen Pfade cache-fähig).

## 11. Was bewusst nicht gebaut wird (Stand Phase 0)

Kubernetes · Redis · eigener Mailserver · eigene Nameserver · Microservices · GraphQL ·
eigene Registrar-Akkreditierung · Ausführung von kundeneigenem JavaScript oder PHP im Editor.

Begründungen und Wiedervorlage-Kriterien: [ROADMAP.md](ROADMAP.md) und `docs/decisions/`.
