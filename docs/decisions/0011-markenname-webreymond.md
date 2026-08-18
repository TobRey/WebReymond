# 0011 – Markenname WebReymond statt WebHeaven

**Status:** akzeptiert (August 2026) · ersetzt die Namenswahl aus Phase 0

## Kontext

Der Arbeitstitel des Projekts war **WebHeaven**. Bei der Domainprüfung in Phase 0 zeigte sich ein
Problem, das kein Domainproblem war, sondern ein Markenproblem:

- `webheaven.net` – ein **aktiver Webhosting-Anbieter**
- `webheaven.com.au` – ebenfalls **Hosting und Domains**
- `webheaven.org`, `webheaven.com.ua` – weitere aktive Auftritte

Zwei davon arbeiten in exakt derselben Branche. Für uns hätte das bedeutet: schlechtere
Auffindbarkeit, Verwechslungsgefahr bei Kunden und Support, und ein reales Risiko für einen
Namensstreit – der ausgerechnet dann teuer wird, wenn der Name bereits auf Rechnungen,
Verträgen und Kundendomains steht.

## Entscheidung

Die Marke heisst **WebReymond**, abgeleitet vom Nachnamen des Betreibers.

- Hauptadresse: **webreymond.ch** (registriert)
- Zweitadresse: **webreymond.com** (registriert, leitet auf `.ch` weiter)
- Das CMS heisst **backendReymond** (vorher backendHeaven)

## Begründung

- **Eindeutig.** Ein Nachname ist unverwechselbar; es gibt keinen Hosting-Anbieter gleichen Namens.
- **Beide wichtigen Endungen verfügbar.** `.ch` und `.com` in einer Hand – das ist selten und
  verhindert, dass später jemand die Nachbardomain besetzt.
- **Schweizer Markt.** `.ch` schafft bei Schweizer Kunden mehr Vertrauen als jede andere Endung.
- **Persönlich statt generisch.** Bei einer Ein-Personen-Firma ist Nähe ein Verkaufsargument,
  kein Nachteil.

## Der Zeitpunkt war der billigste, den es gab

Die Umbenennung fiel auf einen Stand von vier Pull Requests: kein Kunde, kein Server, keine
E-Mail-Adresse im Umlauf, keine Rechnung geschrieben. Konkret waren 54 Dateien betroffen und ein
Testlauf. Dieselbe Änderung ein halbes Jahr später hätte zusätzlich bedeutet: Kundenkommunikation,
Weiterleitungen für alte Adressen, Migration von Postfächern, neue Verträge, Anpassung von
Zahlungsbelegen.

**Merksatz für später: Ein Name kostet am Anfang eine Stunde und am Ende einen Monat.**

## Konsequenzen

| Bereich | Änderung |
|---|---|
| Pakete | `@webheaven/*` → `@webreymond/*` |
| Design-Tokens | `--wh-*` → `--wr-*` |
| CSS-Klassen | `.wh-button`, `.wh-card`, … → `.wr-*` |
| Sitzungs-Cookie | Präfix `webheaven` → `webreymond` |
| Datenbanken | `webheaven`, `webheaven_test` → `webreymond`, `webreymond_test` |
| Logo | Schriftzug **Web** + **Reymond**, Bildzeichen unverändert |

Der alte Name bleibt bewusst an zwei Stellen dokumentiert – hier und in
`docs/branding-domains.md`. Wer in einem Jahr fragt „warum heisst das eigentlich so", findet die
Antwort, statt sie zu erraten.

## Offen

- Auto-Renew, Zwei-Faktor-Anmeldung und Transfer-Sperre in beiden Registrar-Konten aktivieren
- Markenrecherche für „WebReymond" bei Swissreg und TMview (geringes Risiko, aber prüfen)
- Repository auf GitHub von `WebHeaven` auf `WebReymond` umbenennen
