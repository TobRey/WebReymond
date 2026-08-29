# Entscheidungen (ADRs)

Kurze Notizen zu wichtigen technischen Entscheidungen: **was** entschieden wurde, **warum**, welche
**Alternativen** geprüft wurden und **wann wir es neu bewerten**.

Regel: Eine Entscheidung, die man in drei Monaten erklären müsste, gehört hierher. Bestehende ADRs
werden nicht umgeschrieben – wird eine Entscheidung revidiert, entsteht eine neue ADR, die die alte
als „ersetzt durch“ markiert.

| Nr. | Entscheidung | Status |
|---|---|---|
| [0001](0001-monorepo.md) | Monorepo statt mehrerer Repositories | akzeptiert |
| [0002](0002-nextjs-und-fastify.md) | Next.js für die Oberfläche, Fastify als eigene API | akzeptiert |
| [0003](0003-postgresql-und-prisma.md) | PostgreSQL + Prisma für WebHeaven, MariaDB für Kunden | akzeptiert |
| [0004](0004-pgboss-statt-redis.md) | Job-Queue mit pg-boss statt Redis/BullMQ | akzeptiert |
| [0005](0005-better-auth.md) | Better Auth statt externem Login-Dienst | akzeptiert |
| [0006](0006-hestiacp-ohne-mail.md) | HestiaCP als Hosting-Layer, ohne Mail-Komponenten | akzeptiert |
| [0007](0007-hetzner-cx23.md) | Hetzner Cloud CX23 als erster Server | akzeptiert |
| [0008](0008-stripe-zuerst.md) | Stripe als erster Zahlungsanbieter | akzeptiert |
| [0009](0009-backendheaven-mandantenfaehig.md) | backendHeaven mandantenfähig statt Installation je Kunde | akzeptiert |
| [0010](0010-design-tokens-und-tailwind.md) | Design-Tokens als einzige Quelle, Tailwind nur als Werkzeug | akzeptiert |
| [0011](0011-mogli-ohne-build-schritt.md) | Mogli als eigenständiger Ordner ohne Build-Schritt | akzeptiert |
| [0012](0012-ein-knopf-steuerung.md) | Mogli wird ein Ein-Knopf-Spiel, und der Turm folgt der Sprungbahn | akzeptiert |

Format je Datei: Kontext → Entscheidung → Begründung → Alternativen → Konsequenzen → Wiedervorlage.
