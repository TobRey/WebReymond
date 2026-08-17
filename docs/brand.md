# Marke: Farben und Logo

Stand: August 2026. Technisch umgesetzt in
[`packages/ui/src/styles/tokens.css`](../packages/ui/src/styles/tokens.css) – das ist die einzige
Datei mit konkreten Farbwerten.

## Primärfarben

| Name | Wert | Rolle |
|---|---|---|
| Primär Blau | `#2563EB` | Hauptakzent: Schaltflächen, Links, aktive Zustände |
| Primär Türkis | `#06B6D4` | Zweitfarbe: Verläufe, Grafik, Illustration |
| Deep Navy | `#0F172A` | Textfarbe und Flächen im Dunkelmodus |
| Hellgrau | `#F8FAFC` | ruhige Flächen, Abgrenzung ohne Linie |
| Weiss | `#FFFFFF` | Hintergrund und Kartenflächen |

Die Grautöne dazwischen sind aus Deep Navy und Hellgrau abgeleitet (leicht blaustichige Neutrale),
damit Grau und Marke aus derselben Familie stammen und nichts „schmutzig" wirkt.

## Kontrast – die wichtigste Regel

Gemessen als Kontrastverhältnis nach WCAG (4.5:1 ist das Minimum für normalen Text):

| Kombination | Verhältnis | Erlaubt für |
|---|---|---|
| `#2563EB` auf Weiss | **5.2:1** | Text, Links, Schaltflächen ✅ |
| `#06B6D4` auf Weiss | **2.4:1** | **kein Text** – nur Flächen, Verläufe, Grafik ⚠️ |
| `#06B6D4` auf Deep Navy | **7.4:1** | Text im Dunkelmodus ✅ |
| `#2563EB` auf Deep Navy | **3.5:1** | nur grosse Schrift und Bedienelemente |
| `#60A5FA` auf Deep Navy | **7.0:1** | Akzentfarbe im Dunkelmodus ✅ |

Deshalb ist Türkis im Produkt bewusst **keine** Textfarbe. Es erscheint im Logo, im Markenstreifen
am oberen Seitenrand und in Verläufen – dort wirkt es, ohne die Lesbarkeit zu gefährden.
Im Dunkelmodus wechselt der Akzent automatisch auf das hellere Blau `#60A5FA`.

## Logo

Aufbau: eine offene Wolkenkontur mit einem **W** darin, im Verlauf Türkis → Blau.
Der Schriftzug setzt **Web** in Deep Navy und **Heaven** im selben Verlauf.

Umgesetzt als React-Komponente in
[`packages/ui/src/components/Logo.tsx`](../packages/ui/src/components/Logo.tsx):

```tsx
<Logo />              // Bildzeichen + Schriftzug
<Logo markOnly />     // nur das Bildzeichen (z.B. für kleine Flächen)
<Logo size={48} />    // grösser
```

> **Hinweis:** Das Bildzeichen ist als Vektor **nachgebaut**, weil bislang nur eine Bilddatei
> vorlag. Wenn du die Original-SVG-Datei hast, ersetzen wir den Pfad in `Logo.tsx` – der Rest der
> Anwendung bleibt unverändert, weil überall nur diese Komponente verwendet wird.
> Eine Rasterdatei (PNG) genügt als Fallback ebenfalls; sie wäre dann in
> `apps/portal/public/` abzulegen.

Der Schriftzug hat eine einfarbige Rückfallebene: Beherrscht ein Browser `background-clip: text`
nicht, erscheint „Heaven" schlicht in Blau statt unsichtbar.

## Verwendungsregeln

**Tun**

- Farben ausschliesslich über die Tokens verwenden (`var(--wh-color-accent)` statt `#2563EB`)
- Türkis sparsam einsetzen: Logo, Markenstreifen, gelegentliche Grafik
- Viel Weissraum lassen; die Marke wirkt durch Ruhe, nicht durch Farbmenge

**Lassen**

- Keine festen Farbwerte in Komponenten schreiben
- Türkis nicht für Text auf hellem Grund
- Keine grossflächigen Verläufe über ganze Sektionen
- Das Logo nicht verzerren, nicht einfärben, nicht auf unruhige Bilder legen

## Wenn sich die Marke ändert

Farbwerte in `tokens.css` anpassen, `pnpm build` ausführen, fertig – Portal, Adminbereich und später
backendHeaven übernehmen die Änderung automatisch. Genau dafür ist die Token-Ebene da
(siehe [ADR 0010](decisions/0010-design-tokens-und-tailwind.md)).
