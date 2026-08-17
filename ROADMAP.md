# Roadmap

23 Phasen von der Planung bis zum ersten echten Kunden. Eine Phase gilt erst als abgeschlossen, wenn
ihre **Definition of Done** erfüllt und getestet ist. Danach: Commit, Tag, kurze Notiz in
`docs/decisions/` bei wichtigen Entscheidungen.

Änderungen gegenüber der ursprünglichen Planung, mit Begründung:
- **Lokale Entwicklung vor dem Serverkauf** – spart mehrere Monatsmieten, bis es etwas zu deployen gibt.
- **Backups vorgezogen (Phase 14 statt 17)** – bevor die ersten echten Daten entstehen, nicht danach.
- **E-Mail nach hinten (Phase 19)** – wird extern eingekauft und ist deshalb kein Blocker.
- **Datenmodell/RBAC als eigene Phase (5)** – Mandantentrennung ist zu wichtig für ein Nebenprodukt.

---

| # | Phase | Definition of Done | Kosten |
|---|---|---|---|
| **0** | **Planung** ✅ | Architektur, Kosten, Sicherheitsarchitektur, Phasen dokumentiert und freigegeben | 0 |
| **1** | Marke, Domain, Konten | Domainverfügbarkeit selbst geprüft, Name entschieden, Domain registriert, Konten bei GitHub/Hetzner/Registrar bestehen, 2FA überall aktiv | ~CHF 13 einmalig |
| **2** | Lokale Entwicklungsumgebung | WSL2 (Ubuntu), Node.js 22, Docker Desktop, Git, VS Code installiert; `git clone` und ein Testcommit funktionieren | 0 |
| **3** | Repo-Skelett + CI | Monorepo mit Portal/API/Worker-Gerüst, Design-Tokens, DE/EN-Kataloge, PostgreSQL per Docker Compose, CI läuft grün | 0 |
| **4** | Authentifizierung | Registrierung, Login, Logout, Passwort-Reset, TOTP-MFA, Rate-Limits, Audit-Log – alles mit Tests | 0 |
| **5** | Datenmodell + RBAC + Mandantentrennung | Rollen, Policy-Schicht, Migrationen, IDOR-Tests grün (Fremdzugriff schlägt nachweislich fehl) | 0 |
| **6** | Portal- und Adminoberfläche | Navigation, Design-System, responsiv, mobil getestet, DE/EN umschaltbar, noch ohne echte Hostingdaten | 0 |
| **7** | VPS + Härtung | Server läuft, SSH nur mit Schlüssel, Firewall aktiv, VPN-Zugang, automatische Updates, fail2ban; Härtungscheckliste in SECURITY.md abgehakt | ab CHF 4.20/Mt. |
| **8** | HestiaCP + erstes Deployment | HestiaCP ohne Mail installiert, Portal öffentlich erreichbar mit gültigem SSL, Deploy per GitHub Actions reproduzierbar | 0 |
| **9** | Provisioning-Layer | „Hosting erstellen“ im Adminbereich legt Linux-Benutzer, Verzeichnis, Rechte, Datenbank und SSL automatisch an; idempotent, mit Retries, Audit-Log und sichtbaren Fehlerjobs | 0 |
| **10** | Domain-Suche + Registrar-Test | Suche über .com/.ch/.de mit echten Preisen; Registrierung in der Testumgebung (OT&E) erfolgreich; `DomainProvider` und `DnsProvider` implementiert | Guthaben |
| **11** | Payments (Testmodus) + Pricing-Engine | Kauf, Abo, fehlgeschlagene Zahlung, Rückerstattung im Testmodus durchgespielt; Pricing-Engine rechnet in Rappen und wahrt die Mindestmarge | 0 |
| **12** | Bestellstrecke Domain → Hosting | Vollständiger Ablauf: Zahlung → Domain → DNS → Hosting → SSL → Portal aktualisiert. Fehler in einem Schritt verliert weder Domain noch Geld | 0 |
| **13** | File Manager | Anzeigen, Hochladen (Drag & Drop), Herunterladen, Umbenennen, Löschen, Ordner, ZIP, Texteditor; Isolation nachweislich getestet (Traversal, ZIP Slip, Symlink, Upload) | 0 |
| **14** | Backups + Restore | Automatische Sicherung von Dateien, Datenbanken und Konfiguration nach aussen; **ein Restore wurde erfolgreich durchgeführt und protokolliert** | +CHF 3.00/Mt. |
| **15** | backendHeaven Core | Seiten, Beiträge, Kategorien, Medien, Navigation, Header/Footer, SEO-Grundeinstellungen; mandantenfähig mit Tenant-Tests | 0 |
| **16** | 1-Klick-Installation | Kunde klickt „backendHeaven installieren“: Tenant, Datenbank, Zugangsdaten, Domain, SSL, Adminbenutzer – ohne Serverkenntnisse | 0 |
| **17** | Visual Editor | True-WYSIWYG mit Komponentenbaum, Drag & Drop, verschiebbaren Panels, Desktop/Tablet/Mobil mit Vererbung und Overrides | 0 |
| **18** | Templates + Formulare | 10 Seitenvorlagen zentral bereitstellbar; Formular-Builder mit Spam-, CSRF- und Injection-Schutz | 0 |
| **19** | E-Mail-Verwaltung | Postfach anlegen, Passwort ändern, Speicher sehen, löschen – über die API des externen Anbieters; SPF/DKIM/DMARC-Einträge automatisch gesetzt | ab ~CHF 1.50/Mt. |
| **20** | Shop-Modul | Produkte, Varianten, Warenkorb, Checkout, Bestellungen – optional aktivierbar, unsichtbar wenn deaktiviert | 0 |
| **21** | Security-Audit | Komplette Checkliste aus SECURITY.md abgearbeitet, Abhängigkeiten geprüft, gefundene Probleme behoben und nachgetestet | 0 |
| **22** | Staging → Produktion | Trennung von Test- und Produktivsystem etabliert; alle Punkte der „Definition of Done“ erfüllt; erster echter Kunde kann aufgenommen werden | – |

---

## Definition of Done vor dem ersten echten Kunden

Aus dem Auftrag übernommen, gilt als Gesamtabnahme (Phase 22):

Kundenregistrierung · Login · Passwort-Reset · MFA für Administrator · Zahlung im Testmodus ·
Domain-Verfügbarkeitsprüfung · Domainregistrierung in der Sandbox · Hosting-Erstellung · SSL ·
Datenbank · File-Manager-Isolation · backendHeaven-Installation · Backup · **Restore** ·
Benutzerrechte · Rate-Limits · Logs · Monitoring.

**Kein echtes Kundengeld, bevor all das nachweislich funktioniert.**

---

## Abdeckung der Phase-0-Anforderungen

| Geforderter Punkt | Wo dokumentiert |
|---|---|
| 1. Vorgeschlagene Architektur | [ARCHITECTURE.md](ARCHITECTURE.md) Abschnitt 2–4 |
| 2. Benötigte Komponenten | [ARCHITECTURE.md](ARCHITECTURE.md) Abschnitt 3 |
| 3. Was kostenlos ist | [COSTS.md](COSTS.md) Abschnitt 1 |
| 4. Was Geld kostet | [COSTS.md](COSTS.md) Abschnitt 2 |
| 5. Geschätzte monatliche Startkosten | [COSTS.md](COSTS.md) Abschnitt 3 |
| 6. Vorgeschlagener VPS | [COSTS.md](COSTS.md) Abschnitt 4 + `docs/decisions/0007` |
| 7. Kostenloser Hosting-Layer | `docs/decisions/0006` |
| 8. Domain-API-Anbieter | [COSTS.md](COSTS.md) Abschnitt 5 |
| 9. Payment-Anbieter | `docs/decisions/0008` |
| 10. Sicherheitsarchitektur | [SECURITY.md](SECURITY.md) |
| 11. Reihenfolge der Entwicklung | dieses Dokument |
| 12. Zurückgestelltes | [COSTS.md](COSTS.md) Abschnitt 7 |
| Domainprüfung `webheaven.com` | [docs/branding-domains.md](docs/branding-domains.md) |
