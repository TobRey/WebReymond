# 0004 – Job-Queue mit pg-boss statt Redis/BullMQ

**Status:** akzeptiert (Phase 0, August 2026)

## Kontext
Provisioning (Domain → DNS → Hosting → SSL) dauert lange, kann fehlschlagen und muss wiederholbar
sein. Dafür braucht es eine Warteschlange mit Wiederholungen, Zeitplanung und sichtbarem Status.

## Entscheidung
**pg-boss**: eine Job-Queue, die in der bestehenden PostgreSQL-Datenbank läuft. Kein Redis.

## Begründung
- **Transaktionale Sicherheit:** Bestellung und zugehöriger Job entstehen in derselben Transaktion.
  Damit ist der Fall „Zahlung gespeichert, Provisioning-Job verloren“ technisch ausgeschlossen –
  genau der Fehler, der bei Hosting-Automatisierung am teuersten ist.
- **Ein Dienst weniger:** spart 50–100 MB RAM und eine weitere Komponente, die abgesichert,
  aktualisiert und gesichert werden müsste.
- Jobstatus liegt in derselben Datenbank wie die Geschäftsdaten → „fehlgeschlagene Jobs“ im
  Adminbereich ist eine simple Abfrage, kein zweites System.

## Alternativen
- **BullMQ + Redis:** höherer Durchsatz, mehr Werkzeuge – aber unser Durchsatz sind einige Jobs pro
  Tag, nicht Tausende pro Sekunde. Redis wäre reiner Overhead.
- **Cron-Skripte:** keine Wiederholungen, kein Status, keine Nachvollziehbarkeit.

## Konsequenzen
- Alle Jobs sind idempotent zu schreiben (mehrfache Ausführung darf nicht schaden).
- Jeder Job schreibt Audit-Log-Einträge; endgültig fehlgeschlagene Jobs werden im Adminbereich
  sichtbar und sind manuell erneut startbar.
- Die PostgreSQL-Datenbank ist damit noch kritischer → Backups (siehe BACKUP.md) sind Pflicht.

## Wiedervorlage
Ab mehreren tausend Jobs pro Tag oder wenn die Datenbanklast durch Polling spürbar wird.
