# 0001 – Monorepo statt mehrerer Repositories

**Status:** akzeptiert (Phase 0, August 2026)

## Kontext
WebReymond besteht aus mehreren Programmen: Portal, API, Worker, später backendReymond und der Editor.
Sie teilen sich Typen, Design-Tokens, Übersetzungen und Validierungsregeln.

## Entscheidung
Alles liegt in **einem** Repository (`TobRey/WebReymond`) mit pnpm-Workspaces:

```
apps/       portal, api, worker, (später) backendreymond
packages/   shared (Typen, Zod-Schemas), ui (Komponenten, Design-Tokens), i18n, providers
```

## Begründung
- Eine Änderung an einem gemeinsamen Typ wird in einem einzigen Pull Request überall angepasst –
  keine Versionsabgleiche zwischen Repositories.
- Ein CI-Lauf prüft das Gesamtsystem.
- Für einen Einzelbetreiber ist die Verwaltung mehrerer Repositories reiner Zusatzaufwand.

## Alternativen
- **Getrennte Repositories:** saubere Grenzen, aber ständiges Versionieren gemeinsamer Pakete.
- **Ein einziges Programm ohne Trennung:** anfangs am einfachsten, blockiert aber späteres
  Aufteilen auf mehrere Server.

## Konsequenzen
- Build-Werkzeug muss nur geänderte Pakete neu bauen (Turborepo oder pnpm-Filter).
- Deployments müssen einzeln auslösbar bleiben (nicht jede Änderung deployt alles).
- Geheimnisse und Zugriffe gelten für das ganze Repository – wichtig, sobald andere mitarbeiten.

## Wiedervorlage
Wenn backendReymond ein eigenständiges Produkt mit eigenem Veröffentlichungszyklus wird oder externe
Entwickler nur an einem Teil arbeiten sollen.
