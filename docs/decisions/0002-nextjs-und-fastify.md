# 0002 – Next.js für die Oberfläche, Fastify als eigene API

**Status:** akzeptiert (Phase 0, August 2026)

## Kontext
WebHeaven braucht eine schnelle, moderne Oberfläche (öffentliche Website, Kundenportal,
Adminbereich) und eine API, die Provisioning-Aufträge, Webhooks und Anbieter-Integrationen abwickelt.

## Entscheidung
- **Next.js (App Router, React, TypeScript)** für alle Oberflächen.
- **Fastify + Zod + OpenAPI** als getrennte API.
- Beide lauschen nur auf `127.0.0.1`; nginx stellt sie nach aussen bereit.

## Begründung
- Serverseitiges Rendern liefert kurze Ladezeiten und gute Core Web Vitals – ein Verkaufsargument für
  eine Hosting-Plattform.
- Eine getrennte API überlebt Deployments des Portals: Stripe-Webhooks und laufende
  Provisioning-Jobs werden nicht durch einen UI-Build unterbrochen.
- Die API lässt sich später ohne Umbau auf einen eigenen Server verschieben.
- OpenAPI-Beschreibung erlaubt es, später einen typisierten Client (auch für backendHeaven) zu
  erzeugen.

## Alternativen
- **Alles in Next.js (Server Actions / Route Handlers):** weniger Teile, aber langlaufende Jobs und
  Webhooks liegen dann im selben Deployment wie das UI.
- **NestJS:** mehr Struktur, aber deutlich mehr Einarbeitung und Speicherbedarf.
- **tRPC statt REST:** sehr bequem zwischen unseren eigenen Teilen, aber schlechter geeignet, sobald
  Fremdsysteme (CMS-Instanzen, mögliche Mobile-App, Kunden-Integrationen) zugreifen sollen.

## Konsequenzen
- Zwei Prozesse mehr im Speicher (~350 MB zusammen) – bei 4 GB vertretbar.
- Authentifizierung muss zwischen Portal und API konsistent sein (gemeinsame Session-Prüfung).
- API-Verträge müssen gepflegt werden; dafür sind sie überhaupt erst dokumentiert.

## Wiedervorlage
Wenn der Speicherbedarf zum Problem wird (dann Zusammenlegung prüfen) oder wenn die API von
mehreren Clients genutzt wird (dann eher weiter aufteilen).
