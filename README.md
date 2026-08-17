# WebHeaven

WebHeaven ist eine im Aufbau befindliche Webhosting-Plattform aus der Schweiz:
Webdesign, Domainregistrierung, Webhosting, Datei-Hosting, Datenbanken, Website-Verwaltung –
mit einem eigenen CMS namens **backendHeaven** und einem visuellen Website-Builder.

> **Aktueller Stand: Phase 3 – das Grundgerüst steht und läuft lokal.**
> Portal (zweisprachig), API, Design-System, Datenbank und automatische Tests sind vorhanden.
> Noch **nicht** gebaut: Anmeldung, Hosting-Verwaltung, Domains, Zahlungen – siehe [ROADMAP.md](ROADMAP.md).

---

## Was steht wo?

| Datei | Inhalt |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | Wie das System aufgebaut ist und warum |
| [SECURITY.md](SECURITY.md) | Sicherheitsarchitektur, Regeln, Checklisten |
| [COSTS.md](COSTS.md) | Was kostenlos ist, was Geld kostet, laufende Kosten |
| [ROADMAP.md](ROADMAP.md) | Die Phasen 0–22 mit „fertig heisst …“ je Phase |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Wie WebHeaven später auf den Server kommt |
| [BACKUP.md](BACKUP.md) | Was gesichert wird, wohin, und wie ein Restore getestet wird |
| [docs/branding-domains.md](docs/branding-domains.md) | Domainprüfung, Namensalternativen, Markenrisiko |
| [docs/phase-2-entwicklungsumgebung.md](docs/phase-2-entwicklungsumgebung.md) | Windows-11-Anleitung: WSL2, Node, pnpm, Git, Docker, VS Code |
| [docs/decisions/](docs/decisions/) | Kurze Begründungen der wichtigsten Technikentscheidungen (ADRs) |

## Lokal starten

Voraussetzung: einmalig [docs/phase-2-entwicklungsumgebung.md](docs/phase-2-entwicklungsumgebung.md)
durcharbeiten (WSL2, Node 22, pnpm, Docker).

```bash
pnpm install     # Bibliotheken laden
pnpm db:up       # PostgreSQL starten (Docker)
pnpm dev         # Portal + API starten
```

| Adresse | Was |
|---|---|
| http://localhost:3000 | Portal (leitet auf `/de` weiter) |
| http://localhost:3000/en | dieselbe Seite auf Englisch |
| http://localhost:3001/health | API-Statusmeldung |

Beenden mit **Strg + C**, danach `pnpm db:down`.

| Befehl | Wofür |
|---|---|
| `pnpm test` | Alle automatischen Tests |
| `pnpm typecheck` | Typprüfung ohne Ausführung |
| `pnpm lint` | Code-Regeln prüfen |
| `pnpm build` | Produktionsbuild wie in der CI |
| `pnpm format` | Einheitliche Formatierung |

## Aufbau des Repositories

```
apps/
  portal/     Next.js – öffentliche Website, Kundenportal, Adminbereich (Port 3000)
  api/        Fastify – Geschäftslogik und Autorisierung (Port 3001)
packages/
  ui/         Design-Tokens und Basiskomponenten
  shared/     gemeinsame Typen und Validierungsschemas
docs/         Anleitungen und Entscheidungen
```

## Die Kurzfassung der Architektur

```
Internet
   │
   ▼
[ nginx (HestiaCP) ]  ──► Kundenwebsites (statisch / PHP, je Kunde ein eigener Linux-Benutzer)
   │
   ├──► WebHeaven Portal   (Next.js  – das, was Kunden sehen)
   └──► WebHeaven API      (Fastify  – die Logik, nur intern erreichbar)
                │
                ├── PostgreSQL   (WebHeaven-Daten, Jobs, Audit-Log)
                ├── Worker       (führt Provisioning-Aufträge aus)
                └── Provider-Adapter (Registrar, DNS, Stripe, E-Mail)
```

**Grundregel:** Kunden sehen ausschliesslich WebHeaven. Das darunterliegende Hosting-Panel
(HestiaCP) ist aus dem Internet gar nicht erreichbar.

## Geplante Hosting-Pakete

| Paket | Enthält |
|---|---|
| 1 – Hosting | Website-Hosting, SSL, Datenbank, E-Mail |
| 2 – Hosting + File Manager | zusätzlich den WebHeaven File Manager |
| 3 – Hosting + backendHeaven | zusätzlich das CMS |
| 4 – Komplett | zusätzlich den visuellen Website-Builder |

Speicherplatz ist in allen Paketen gleich gross; unterschieden wird über Funktionen.
Preise werden in Phase 11 mit der Pricing-Engine berechnet (inkl. Zahlungsgebühren und Marge).

## Wie wir arbeiten

1. Wir arbeiten **phasenweise** (siehe [ROADMAP.md](ROADMAP.md)). Eine Phase gilt erst als fertig,
   wenn ihre „Definition of Done“ erfüllt und getestet ist.
2. **Nichts Kostenpflichtiges ohne ausdrückliche Zustimmung.** Jede kostenpflichtige Ressource wird
   vorher in [COSTS.md](COSTS.md) mit Preis, Alternative und Begründung dokumentiert.
3. **Kein echtes Kundengeld**, bevor Testmodus, Backups und Sicherheitsprüfungen funktionieren.
4. Wichtige Entscheidungen landen als kurze Notiz in `docs/decisions/`.

## Git – die Regeln in vier Sätzen

Für Einsteiger bewusst einfach gehalten:

1. **`main`** ist immer funktionsfähig. Dort wird nie direkt entwickelt.
2. Für jede Aufgabe ein eigener Branch: `feature/kurzer-name` (z.B. `feature/login`).
   Für Reparaturen: `fix/kurzer-name`.
3. Zusammengeführt wird per **Pull Request**, und erst wenn die automatischen Tests grün sind.
4. **Niemals Passwörter, API-Schlüssel oder `.env`-Dateien committen.** Nur `.env.example` mit
   leeren Platzhaltern gehört ins Repository.

Nach jeder abgeschlossenen Phase setzen wir ein Tag: `v0.1`, `v0.2`, …

## Kleines Glossar

| Begriff | Bedeutung in einfachen Worten |
|---|---|
| **VPS** | Ein gemieteter Computer im Rechenzentrum, der immer läuft |
| **SSH** | Sichere Fernsteuerung dieses Computers über die Kommandozeile |
| **SSH-Key** | Ein digitales Schlüsselpaar statt eines Passworts – sicherer und bequemer |
| **DNS** | Das Telefonbuch des Internets: Domainname → Serveradresse |
| **Nameserver** | Die Server, die dieses Telefonbuch für deine Domain beantworten |
| **SSL/TLS-Zertifikat** | Sorgt für `https://` und das Schloss-Symbol im Browser |
| **Registrar** | Firma, bei der man Domains kauft (wir kaufen ein, statt selbst Registrar zu werden) |
| **Control Panel** | Verwaltungsoberfläche für Server (bei uns: HestiaCP, intern) |
| **Provisioning** | Das automatische Anlegen von Hosting, Datenbank, DNS und SSL |
| **Mandantentrennung** | Kunde A darf nichts von Kunde B sehen – technisch erzwungen |
| **RBAC** | Rechte anhand von Rollen (Administrator / Kunde / Benutzer) |
| **MFA / TOTP** | Zweiter Faktor beim Login (6-stelliger Code aus einer App) |
| **CI** | Automatische Tests bei jeder Änderung (GitHub Actions) |
| **Monorepo** | Ein Repository, das mehrere zusammengehörige Programme enthält |
| **Tenant** | Ein Mandant = eine Kundenwebsite innerhalb einer gemeinsamen Anwendung |

## Nächster Schritt

**Phase 1 – Marke, Domain und Konten.** Details und Prüfanleitung:
[docs/branding-domains.md](docs/branding-domains.md).
