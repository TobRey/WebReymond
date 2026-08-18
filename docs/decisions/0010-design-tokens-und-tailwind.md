# 0010 – Design-Tokens als einzige Quelle, Tailwind nur als Werkzeug

**Status:** akzeptiert (Phase 3, August 2026)

## Kontext
Logo und Firmenfarben stehen noch nicht fest, das Produkt soll aber schon aussehen wie eine
hochwertige SaaS-Plattform – und später ohne Umbau umgefärbt werden können. Ausserdem sollen
WebReymond und backendReymond dasselbe Aussehen teilen.

## Entscheidung
Zwei Ebenen, klar getrennt:

1. **`packages/ui/src/styles/tokens.css`** – reine CSS-Variablen (`--wr-color-*`, `--wr-space-*`,
   `--wr-radius-*`, `--wr-text-*`). Das ist die **einzige** Stelle mit konkreten Farbwerten.
   Aufgeteilt in Grundpalette (rohe Werte) und Semantik (wofür eine Farbe da ist). Komponenten
   verwenden ausschliesslich die Semantik-Namen.
2. **Tailwind CSS 4** im Portal – aber ohne eigene Farbpalette. Über `@theme inline` werden die
   Tailwind-Namen auf unsere Tokens gemappt: `bg-surface` ist damit per Definition
   `var(--wr-color-surface)`.

Die Basiskomponenten in `packages/ui` benutzen **kein** Tailwind, sondern eigene CSS-Klassen
(`.wr-button`, `.wr-card`, `.wr-input`) aus denselben Tokens.

## Begründung
- **Umfärben ist ein Ein-Datei-Vorgang.** Wenn dein Logo kommt, ändern wir `tokens.css` – Portal,
  Adminbereich und später backendReymond ziehen automatisch nach.
- **Kein Vendor-Lock-in beim Styling.** Reine CSS-Variablen funktionieren überall: in Next.js, in
  generierten backendReymond-Websites, sogar in einer E-Mail-Vorlage. Tailwind ist austauschbar, die
  Tokens bleiben.
- **Die UI-Komponenten sind unabhängig vom Build-Werkzeug.** Sie brauchen keine
  Tailwind-Klassenerkennung über Paketgrenzen hinweg – das ist genau die Stelle, an der solche
  Monorepos sonst brechen.
- **Kleines Ergebnis:** Das gesamte Stylesheet der Startseite liegt bei rund 14 KB.

## Alternativen
- **Nur Tailwind, ohne Token-Ebene:** schneller zu schreiben, aber Farben verteilen sich über
  hunderte Dateien. Ein Rebranding wäre dann Suchen-und-Ersetzen mit Fehlerrisiko.
- **CSS-in-JS (styled-components, Emotion):** mehr Laufzeit-Overhead, schlechtere Core Web Vitals.
- **Nur handgeschriebenes CSS:** volle Kontrolle, aber Layoutarbeit wird deutlich langsamer.

## Konsequenzen
- **Regel:** Kein `#hex` ausserhalb von `tokens.css`. Taucht dort eins auf, ist es ein Fehler.
- Dunkelmodus wird ausschliesslich über die Semantik-Ebene abgebildet – die Grundpalette bleibt
  unangetastet.
- Sichtbarer Tastaturfokus und `prefers-reduced-motion` sind fest in `components.css` verankert und
  keine spätere Zutat.

## Wiedervorlage
Wenn backendReymond-Websites eigene Themes pro Kunde bekommen sollen: Dann wird aus der einen
Token-Datei ein pro Tenant generierter Satz Variablen – die Struktur trägt das bereits.
