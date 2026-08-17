# 0005 – Better Auth statt externem Login-Dienst

**Status:** akzeptiert (Phase 0, August 2026)

## Kontext
Benötigt werden: Registrierung, Login, Passwort-Reset, Sitzungen, TOTP-MFA für Administratoren,
Rate-Limits und drei Rollen – ohne laufende Kosten und ohne Abhängigkeit von einem Anbieter.

## Entscheidung
**Better Auth**, selbst gehostet, mit Sitzungen in der eigenen PostgreSQL-Datenbank.
Passwörter mit **Argon2id**. TOTP-MFA für Administratoren verpflichtend.

## Begründung
- Kostenlos und quelloffen; keine Preisstufen, die ab X Nutzern zuschlagen.
- Sitzungen in der Datenbank statt reiner JWTs: Ein Login kann sofort widerrufen werden – wichtig für
  eine Plattform, auf der Adminzugriff gleichbedeutend mit Serverzugriff ist.
- Bringt TOTP, Rate-Limits und Account-Verknüpfung mit, statt dass wir Sicherheitskritisches selbst
  bauen (eigene Auth-Implementierungen sind eine klassische Fehlerquelle).
- Alle Benutzerdaten bleiben bei uns – relevant für Schweizer Kunden und Datenschutz.

## Alternativen
- **Auth0 / Clerk:** sehr bequem, aber ab wenigen Nutzern kostenpflichtig und harter Vendor-Lock-in
  bei einem zentralen Baustein.
- **Keycloak:** mächtig, aber als Java-Anwendung viel zu speicherhungrig für 4 GB RAM.
- **Selbst schreiben:** keine Option bei sicherheitskritischer Funktionalität.

## Konsequenzen
- Wir sind selbst für Updates dieser Bibliothek verantwortlich → Dependabot aktivieren.
- Passwort-Reset braucht E-Mail-Versand; dafür wird ein einfacher, externer Versanddienst genutzt
  (kein eigener Mailserver, siehe ADR 0006).
- Rollen und Rechte liegen als Daten in der Datenbank, damit sie später fein granular erweiterbar sind.

## Wiedervorlage
Wenn Kunden Single-Sign-on (SAML/OIDC) verlangen oder die Nutzerzahl den Betrieb komplex macht.
