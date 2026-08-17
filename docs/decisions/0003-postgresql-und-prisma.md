# 0003 – PostgreSQL + Prisma für WebHeaven, MariaDB für Kundendatenbanken

**Status:** akzeptiert (Phase 0, August 2026)

## Kontext
WebHeaven verwaltet Bestellungen, Domains, Hosting, Zahlungen und ein Audit-Log – dort sind
Transaktionen und saubere Migrationen entscheidend. Kundenwebsites erwarten dagegen typischerweise
MySQL/MariaDB.

## Entscheidung
- **PostgreSQL 17** für alle WebHeaven-eigenen Daten, Jobs und das Audit-Log.
- **Prisma** als ORM und für Migrationen.
- **MariaDB** (von HestiaCP verwaltet) ausschliesslich für Kundendatenbanken – strikt getrennt.

## Begründung
- PostgreSQL bietet verlässliche Transaktionen, `JSONB` für flexible Strukturen (Editor-Bäume),
  Volltextsuche und ausgereifte Sperrmechanismen – Letzteres brauchen wir für die Job-Queue (ADR 0004).
- Prisma erzeugt nachvollziehbare Migrationsdateien, die im Repository liegen, und ist für Einsteiger
  gut dokumentiert. Der generierte Client verhindert zusammengebautes SQL und damit SQL-Injection.
- MariaDB bleibt, weil PHP-Anwendungen der Kunden es voraussetzen und Hestia es ohnehin mitbringt.

## Alternativen
- **Alles in MariaDB:** ein Datenbanksystem weniger, aber schwächer bei Transaktionen, JSON und
  Queue-Mustern; Vermischung von Kunden- und Plattformdaten wäre ein Sicherheitsrisiko.
- **Drizzle statt Prisma:** schlanker und näher an SQL, aber weniger Einsteigermaterial.

## Konsequenzen
- Zwei Datenbanksysteme auf einem Server (~250 MB RAM zusammen) – vertretbar.
- Zugriffe auf Kundendatenbanken laufen ausschliesslich über den Provisioning-Adapter, nie direkt
  aus dem Portal.
- Migrationen werden vorwärtskompatibel geschrieben, damit ein Rollback ohne Datenverlust möglich ist.

## Wiedervorlage
Wenn Prisma sich als zu schwer erweist (Speicher, Buildzeit) → Drizzle prüfen.
Wenn Kundenwebsites PostgreSQL verlangen → als zusätzliches Angebot, nicht als Ersatz.
