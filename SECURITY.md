# Sicherheit

WebReymond betreibt fremde Websites, verwaltet Domains und verarbeitet Zahlungen. Damit ist die
Plattform ein attraktives Angriffsziel. Sicherheit hat deshalb Vorrang vor Funktionsumfang und
vor Geschwindigkeit.

Stand: Phase 0 (August 2026). Die Checklisten werden je Phase abgearbeitet und hier abgehakt.

---

## 1. Unverhandelbare Grundregeln

1. **Kein Webprozess läuft als `root`.** Weder WebReymond noch Kundenwebsites.
2. **Keine freie Shell-Schnittstelle im Backend.** Systemänderungen nur über eine feste Allowlist
   typisierter Aktionen.
3. **Keine vom Benutzer eingegebenen Zeichenketten in Shell-Befehle.** Aufruf immer mit
   `execFile(befehl, [arg1, arg2])`, nie mit zusammengesetztem String, nie mit `shell: true`.
4. **Autorisierung immer serverseitig.** Dass das Frontend etwas nicht anzeigt, ist keine Sicherheit.
5. **`tenantId` kommt aus der Session, nie aus dem Request.**
6. **Keine Secrets im Repository, im Frontend oder in Logs.**
7. **Keine Kartendaten auf unseren Servern.** Zahlung ausschliesslich über gehostete Bezahlseiten.
8. **Kein echtes Kundengeld**, bevor Testmodus, Backups mit erfolgreichem Restore und die
   Sicherheitschecks dieser Datei erledigt sind.

---

## 2. Serverhärtung (Phase 7)

| Massnahme | Warum |
|---|---|
| SSH nur mit Schlüssel, `PasswordAuthentication no`, `PermitRootLogin no` | Passwort-Rateangriffe wirkungslos |
| SSH und HestiaCP nur über VPN (Tailscale) erreichbar | Verwaltungsoberflächen gehören nicht ins offene Internet |
| Firewall: eingehend nur 80/443 offen | Kleinstmögliche Angriffsfläche |
| `unattended-upgrades` für Sicherheitsupdates | Bekannte Lücken schliessen sich von selbst |
| fail2ban gegen wiederholte Fehlversuche | Bremst automatisierte Angriffe |
| Getrennter Linux-Benutzer je Hostingkunde | Ein gehacktes Kundenprojekt bleibt eingesperrt |
| PHP je Kunde in eigenem FPM-Pool, `open_basedir`, gefährliche Funktionen deaktiviert | Verhindert Ausbruch aus dem Kundenverzeichnis |
| Kein Mailserver installiert | Entfernt Spam-, Relay- und Reputationsrisiken vollständig |
| Zeitsynchronisation, Logrotation, `journald`-Aufbewahrung | Nachvollziehbarkeit im Ernstfall |

**Hinweis zu HestiaCP:** In Version 1.9.7 wurden neun Sicherheitslücken auf einmal geschlossen,
1.10 brachte eine Sicherheitsüberarbeitung. Konsequenz: Panel niemals öffentlich erreichbar machen,
Updates zeitnah einspielen, Release-Notes verfolgen.

---

## 3. Anwendungssicherheit

### Authentifizierung und Sitzungen
- Passwörter mit **Argon2id** gehasht, nie im Klartext, nie reversibel.
- Sitzungen in der Datenbank, Cookies `HttpOnly`, `Secure`, `SameSite=Lax`.
- Session-ID wird beim Login und beim Rechtewechsel neu vergeben (gegen Session Fixation).
- **MFA (TOTP) für Administratoren verpflichtend**, für Kunden optional.
- Passwort-Reset über Einmal-Token: kurzlebig, einmal verwendbar, an das Konto gebunden;
  identische Antwort für existierende und nicht existierende Adressen (keine Konto-Enumeration).
- Rate-Limits auf Login, Passwort-Reset, Registrierung, Domainsuche, Upload und alle
  provisionierenden Endpunkte, pro Konto **und** pro IP.

### Autorisierung
- RBAC mit drei Standardrollen: **Administrator**, **Kunde**, **Benutzer** – später fein granular
  erweiterbar (Berechtigungen als Daten, nicht als `if`-Kaskade im Code).
- Zentrale Policy-Schicht: jede Ressource wird über `can(user, action, resource)` geprüft.
- Objekt-Ownership wird immer mitgeprüft, nicht nur die Rolle (gegen IDOR).
- Automatisierte Tests versuchen aktiv, fremde Ressourcen zu lesen und zu ändern. Ein bestandener
  Zugriff bricht den Build.

### Eingaben und Ausgaben
- Validierung an jeder Grenze mit Zod (Typ, Länge, Format, erlaubte Werte).
- Datenbankzugriff über Prisma (parametrisiert) – kein zusammengebautes SQL.
- Ausgaben werden von React automatisch escaped; `dangerouslySetInnerHTML` ist verboten
  (Ausnahmen nur mit Sanitizer und schriftlicher Begründung im PR).
- Security-Header: `Content-Security-Policy` (streng, keine `unsafe-inline`-Skripte),
  `Strict-Transport-Security`, `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`.
- CSRF-Schutz über SameSite-Cookies **und** Token bei zustandsändernden Formularen.
- **SSRF-Schutz:** Ausgehende HTTP-Anfragen nur an eine feste Liste bekannter Anbieter-Hosts.
  Keine vom Benutzer bestimmten URLs abrufen. Interne Adressbereiche sind blockiert.

### Protokollierung
- **Audit-Log** für: Login/Logout, fehlgeschlagene Logins, Rechteänderungen, Domainaktionen,
  Zahlungen, Provisioning-Schritte, Datei- und Datenbankoperationen, Adminzugriffe auf Kundendaten.
- Einträge werden nur angehängt, nie geändert oder gelöscht.
- Logs enthalten **niemals** Passwörter, Tokens, API-Schlüssel oder Kartendaten (Filter im Logger).

---

## 4. File Manager – besondere Risiken (Phase 13)

| Angriff | Gegenmassnahme |
|---|---|
| **Path Traversal** (`../../etc/passwd`) | Browser sendet nie Pfade, sondern IDs. Serverseitig `realpath` auflösen und prüfen, dass das Ergebnis innerhalb der Kundenwurzel liegt. |
| **Symlink-Angriffe** | Symlinks werden nicht verfolgt; Ziel ausserhalb der Wurzel → abgelehnt. |
| **ZIP Slip** (Archiveintrag `../`) | Jeder Eintrag wird vor dem Entpacken normalisiert und geprüft; absolute Pfade und `..` werden abgelehnt. |
| **Gefährliche Uploads** | Upload-Verzeichnisse ohne Ausführungsrechte; PHP-Ausführung in Upload-Pfaden serverseitig deaktiviert. |
| **MIME-Type-Spoofing** | Typ aus dem Dateiinhalt bestimmen, nicht aus dem Header des Browsers. Downloads mit `Content-Disposition: attachment` und `X-Content-Type-Options: nosniff`. |
| **XSS über Dateinamen oder Inhalte** | Dateinamen normalisieren und escapen; Vorschau von HTML nur in einem `sandbox`-iframe ohne Zugriff auf die Portal-Domain. |
| **Race Conditions** | Schreiben über temporäre Datei + atomares Umbenennen; pro Kunde und Pfad serialisiert. |
| **Ressourcenmissbrauch** | Grössenlimits, Dateianzahl-Limits, Timeouts, Speicherquote je Kunde. |
| **Rechteänderungen** | Nur eine kleine Auswahl sicherer Modi erlaubt (z.B. 644/755). SetUID/SetGID nie. |

---

## 5. Formulare in backendReymond (Phase 18)

Spam-Schutz ohne externe Tracker (Honeypot + Zeitprüfung + Rate-Limit, optional datenschutz-
freundliches Captcha), CSRF-Token, Validierung serverseitig, Ausgabe escaped, Datei-Uploads mit
denselben Regeln wie im File Manager, Empfängeradressen niemals aus dem Formular übernehmen
(sonst wird das Formular zum Spam-Relay).

---

## 6. Kundeneigener Code

Kunden dürfen **kein** eigenes JavaScript oder PHP einbringen. Eine HTML-Komponente existiert
höchstens für Administratoren und bleibt vorerst deaktiviert. Grund: Eigener Code hebelt CSP,
Mandantentrennung und Upload-Schutz aus.

---

## 7. Secret-Management

- Secrets ausschliesslich in Umgebungsvariablen (Datei `.env` mit Rechten `600`, Besitzer = Dienst-
  benutzer) bzw. in GitHub-Actions-Secrets für Deployments.
- `.env.example` enthält nur Schlüsselnamen und Platzhalter – **nie echte Werte**.
- Getrennte Konfigurationen für Entwicklung, Test und Produktion; getrennte Schlüssel je Umgebung.
- Erzeugte Kundenpasswörter (Datenbank, CMS-Admin) werden nur verschlüsselt gespeichert und im
  Portal einmalig bzw. auf ausdrückliche Anforderung angezeigt.
- Bei Verdacht auf Kompromittierung: Schlüssel sofort rotieren, Vorfall im Audit-Log vermerken.

---

## 8. Checkliste vor dem ersten echten Kunden

- [x] Registrierung, Login, Logout, Passwort-Reset getestet *(Phase 4)*
- [ ] MFA für Administrator aktiv und getestet *(Technik eingebaut, Oberfläche fehlt)*
- [x] Rate-Limits greifen nachweislich (Test vorhanden) *(Phase 4)*
- [ ] RBAC- und IDOR-Tests grün
- [ ] Security-Header und CSP im Produktivsystem geprüft
- [ ] Zahlung im Testmodus vollständig durchgespielt (inkl. fehlgeschlagener Zahlung und Rückerstattung)
- [ ] Domainprüfung und Registrierung in der Testumgebung des Registrars erfolgreich
- [ ] Hosting-Erstellung inkl. SSL und Datenbank automatisch erfolgreich
- [ ] File-Manager-Isolation getestet (Traversal, ZIP Slip, Symlink, Upload)
- [ ] backendReymond-Installation getestet
- [ ] Backup läuft **und ein Restore wurde erfolgreich durchgeführt**
- [ ] Monitoring meldet Ausfälle nachweislich
- [ ] Audit-Log enthält alle sicherheitsrelevanten Ereignisse *(Anmeldeereignisse ab Phase 4 protokolliert)*
- [ ] Abhängigkeiten ohne bekannte kritische Schwachstellen (`npm audit` / Dependabot)
- [ ] Serverhärtung nach Abschnitt 2 vollständig umgesetzt

---

## 9. Sicherheitslücke melden

Bis eine offizielle Adresse existiert: Meldungen bitte direkt an den Betreiber des Repositories.
Bitte keine öffentlichen Issues für Sicherheitsprobleme. Ab Phase 22 wird hier eine
`security@`-Adresse und eine Frist für Rückmeldungen ergänzt.
