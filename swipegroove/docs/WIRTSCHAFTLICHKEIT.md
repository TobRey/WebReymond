# Wirtschaftlichkeit: 50 KI-Cover bei CHF 2.00 Monatsumsatz

Preis (CHF 2.00) und Kontingent (50 Cover) sind Produktvorgaben und wurden **nicht** verändert. Diese Rechnung zeigt, ob sie sich tragen.

## Annahmen

- Wechselkurs: **1 CHF ≈ 1.25 USD** (Annahme, Stand Oktober 2026 – bitte aktuell prüfen).
- Stripe-Gebühren Schweiz (Kartenzahlung): **2.9 % + CHF 0.30** pro Transaktion (Richtwert laut Drittquellen; Stripe-Preisseite prüfen).
- Bildpreise OpenAI (1024×1024, USD pro Bild). **Hinweis:** openai.com war aus der Build-Umgebung nicht erreichbar; die Werte stammen aus mehreren übereinstimmenden Drittquellen (Stand Oktober 2026) und sind im Adminbereich (*Einstellungen → Preistabelle*) anpassbar:

| Modell            | low    | medium | high   |
|-------------------|--------|--------|--------|
| gpt-image-1-mini  | 0.005  | 0.011  | 0.036  |
| gpt-image-1.5     | 0.009  | 0.034  | 0.133  |
| gpt-image-1 (wird Okt. 2026 eingestellt) | 0.011 | 0.042 | 0.167 |

## Nettoumsatz pro Abo

- Brutto: CHF 2.00
- Stripe: 2.9 % × 2.00 + 0.30 = CHF 0.358
- **Netto ≈ CHF 1.64 ≈ USD 2.05** pro Monat

## Kosten bei voller Ausschöpfung (50 Bilder)

| Modell / Qualität        | Kosten 50 Bilder | Marge pro Abo (USD) | Ergebnis |
|--------------------------|------------------|---------------------|----------|
| gpt-image-1-mini / low    | USD 0.25          | +1.80                | tragfähig |
| **gpt-image-1-mini / medium (Standard)** | **USD 0.55** | **+1.50**       | **tragfähig** |
| gpt-image-1-mini / high   | USD 1.80          | +0.25                | knapp |
| gpt-image-1.5 / low       | USD 0.45          | +1.60                | tragfähig |
| gpt-image-1.5 / medium    | USD 1.70          | +0.35                | knapp |
| gpt-image-1.5 / high      | USD 6.65          | −4.60                | **Verlust** |

Realistisch nutzt nur ein Teil der Abonnenten das volle Kontingent; fehlgeschlagene Generierungen (Anbieterfehler, Inhaltsrichtlinie) werden nicht berechnet und zählen nicht gegen das Kontingent (ausser der Anbieter hat ein Bild geliefert).

## Empfehlung

- Standard **gpt-image-1-mini / medium** beibehalten: selbst bei voller Ausschöpfung bleiben ~USD 1.50 pro Abo.
- `gpt-image-1.5 / high` oder `gpt-image-2` nur mit höherem Preis oder kleinerem Kontingent – nicht mit den aktuellen Vorgaben.
- Der Adminbereich (*KI-Nutzung*) zeigt Anzahl, Fehlerquote und geschätzte Kosten pro Monat; die Preistabelle liegt in den Einstellungen.
- Das Besitzerkonto hat kein Kontingent; Schutz vor Missbrauch (max. parallele Jobs, Rate-Limit 20 Generierungen/10 min) bleibt aktiv, Kosten werden trotzdem erfasst.
