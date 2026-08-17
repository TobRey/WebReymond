# 0008 – Stripe als erster Zahlungsanbieter

**Status:** akzeptiert (Phase 0, August 2026) – produktiv erst nach Phase 11/22

## Kontext
Benötigt werden Einmalzahlungen (Domains), Abos (Hosting), Zahlungsstatus, Webhooks, Rückerstattungen
und der Umgang mit fehlgeschlagenen Zahlungen – bei anfangs sehr kleinem Umsatz.

## Entscheidung
**Stripe** mit **gehostetem Checkout** und Customer Portal. Start ausschliesslich im **Testmodus**.
Zugriff über eine `PaymentProvider`-Abstraktion.

## Begründung
- **Keine monatliche Grundgebühr, keine Einrichtungsgebühr** – bei null Umsatz kostet es null.
  Genau das passt zur Startphase.
- Gehosteter Checkout: **Kartendaten berühren unseren Server nie**, der PCI-Aufwand bleibt minimal.
- Abos, Rechnungen, Webhooks, Rückerstattungen und Mahnläufe sind fertig vorhanden.
- TWINT wird unterstützt (1.9 % + CHF 0.30) – in der Schweiz günstiger als Karte (2.9 % + CHF 0.30).
- Sehr gute Dokumentation und Testkarten – wichtig, weil wir den gesamten Ablauf vor dem ersten
  echten Franken durchspielen wollen.

## Alternativen
- **Payrexx** und **wallee** (beide Schweizer): niedrigere Transaktionsgebühren, dafür monatliche
  Grundgebühren und teils eingeschränkte Entwicklerschnittstellen. Bei kleinem Umsatz frisst die
  Grundgebühr den Vorteil auf.
- **PayPal:** hohe Gebühren, schwächere Abo-Funktionen.

Achtung bei Vergleichstabellen: Die ausführlichsten Vergleiche stammen von den Anbietern selbst und
sind entsprechend gefärbt.

## Konsequenzen
- Gebühren müssen **vor** der Margenberechnung abgezogen werden (siehe COSTS.md, Pricing-Engine).
- Webhooks müssen signaturgeprüft und idempotent verarbeitet werden (jedes Ereignis kann mehrfach
  eintreffen).
- Geldbeträge werden ausschliesslich als Ganzzahl in Rappen gerechnet, nie als Fliesskommazahl.

## Wiedervorlage
Ab etwa CHF 500 Umsatz pro Monat: Payrexx/wallee gegenrechnen. Dank `PaymentProvider`-Abstraktion
ist ein Wechsel dann keine Neuentwicklung.
