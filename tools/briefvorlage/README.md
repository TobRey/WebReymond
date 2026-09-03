# Die Briefvorlage

`docs/vorlagen/WebAtze-Briefvorlage.docx` – A4, Kopfzeile mit Logo und
Absender, Verlaufslinie, dreispaltige Fusszeile mit Seitenzahl. In eckigen
Klammern steht, was auszufüllen ist.

## Neu erzeugen

```bash
cd tools/briefvorlage
npm install docx          # nur beim ersten Mal
node bauen.js
python3 nachbearbeiten.py WebAtze-Briefvorlage.docx
cp WebAtze-Briefvorlage.docx ../../docs/vorlagen/
```

`nachbearbeiten.py` ist kein Beiwerk: `docx-js` packt Anfang, Anweisung,
Trenner und Ende einer Feldfunktion in einen einzigen Lauf und lässt das
Ergebnis weg. Word kommt damit klar, andere Betrachter nicht – im Fuss
stand dann »Seite 1 von 1« mit winzigen Wörtern und grossen Zahlen. Das
Skript teilt die Felder auf, wie es die Norm vorsieht, und prüft danach,
ob sich jedes XML im Paket noch einlesen lässt.

## Die Bilder

`logo.png`, `regel.png` und `regel-hell.png` entstehen aus `logo.html` –
dieselben SVG-Pfade wie das Logo der Website, mit eingebetteter
Outfit-Schrift, gerendert über Chromium:

```bash
node render.mjs           # legt die drei PNG neben logo.html
```

Ändert sich das Logo, wird `logo.html` angepasst und neu gerendert.

## Ansehen, was herauskommt

```bash
soffice --headless --convert-to pdf WebAtze-Briefvorlage.docx
pdftoppm -jpeg -r 110 WebAtze-Briefvorlage.pdf seite
```
