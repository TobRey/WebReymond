# 0013 – Mogli ist eine Seite, die aus nichts als dem Spiel besteht

**Status:** akzeptiert (August 2026)
**Ergänzt** [0012](0012-ein-knopf-steuerung.md) – die Ein-Knopf-Steuerung bleibt unverändert
gültig; hier geht es nur darum, wie die Seite darum herum gebaut ist.

## Kontext

Die Fassung aus 0012 war eine gewöhnliche Webseite: Kopfzeile, ein Spielfeld mit festem
Seitenverhältnis von 192 × 256, daneben beziehungsweise darunter eine Spalte mit Bestenliste
und einer Tafel, die die Steuerung erklärt.

Auf dem Rechner sah das ordentlich aus. Auf dem Handy war es kaputt, und zwar auf eine Art,
die im Entwurf niemand sieht:

Auf einem iPhone-Format (390 × 844 CSS-Pixel) war die Seite **1512 Pixel hoch**. Das Spielfeld
war ein 320 × 427 grosser Kasten irgendwo in der Mitte, darunter eine Lücke von 230 Pixeln,
dann die Bestenliste, dann die Steuerungstafel. Wer die Seite öffnete und wischte, sah die
Bestenliste und die Erklärung – und dachte, das Spiel sei nicht da. Genau so wurde es
gemeldet.

Zwei Ursachen, beide entwurfsbedingt:

1. **Feste Bildhöhe.** Ein 3 : 4-Bild in einem Fenster mit Verhältnis 0.46 lässt zwangsläufig
   zwei Drittel der Höhe ungenutzt.
2. **Die Seite war länger als das Fenster.** Alles, was neben dem Spiel steht, verlängert sie –
   und auf dem Handy heisst „neben" immer „darunter".

## Entscheidung

1. **Die Seite besteht aus einem Element.** `index.html` enthält die Bühne, sonst nichts. Sie
   ist fest positioniert, `100dvh` hoch, `overflow: hidden`. Es gibt nichts zu scrollen.
2. **Alles Weitere liegt darüber, nicht darunter.** Bestenliste, Namensfeld, Sprache, Ton und
   Vollbild leben in den Einblendungen (Menü, Ergebnis). Die Bestenliste steht deshalb an zwei
   Stellen im Baum und wird aus einer gemeinsamen Datenquelle in beide gezeichnet.
3. **Keine Steuerungstafel.** Bei genau einem Knopf ist eine Tabelle mit sechs Zeilen ein
   Widerspruch in sich. Ein Hinweis („Tippen und los") liegt über dem Bild und verschwindet
   nach dem ersten Sprung.
4. **Die Bildhöhe richtet sich nach dem Gerät.** `VIEW_W` bleibt bei 192 Pixeln – daran hängt
   die gesamte Levelgeometrie und damit jeder Erreichbarkeitsbeweis in den Tests. `VIEW_H` wird
   beim Start und bei jeder Grössenänderung aus dem Seitenverhältnis gewählt, gerundet auf ein
   Vielfaches der Kachelgrösse, begrenzt auf 256 bis 448 Pixel.
5. **Mogli sitzt anteilig im Bild, nicht in festem Abstand.** `CAM.playerAt = 0.62` statt
   „150 Pixel über dem unteren Rand". Sonst klebte er auf einem hohen Handy in der Bildmitte
   und auf einem kurzen fast oben.
6. **Am Handy geht es beim Losspielen von allein ins Vollbild**, am Rechner nur auf Knopfdruck
   oder mit `F`. Wo es die Vollbild-Schnittstelle nicht gibt (iOS), verschwindet der Knopf,
   statt wirkungslos dazustehen.

## Warum so

**Warum darf die Höhe wandern, die Breite aber nicht?** Weil nur die Breite das Spiel
verändert. Der Schacht ist zwölf Kacheln breit; Sprungweite, Absatzbreite und Lückengrösse
sind daraus hergeleitet, und `test/level.test.mjs` spielt jede vorkommende Kombination mit der
echten Physik durch. Mehr Höhe zeigt dagegen nur mehr Schacht **über** Mogli. Das ist kein
Vorteil: die Gefahr kommt von unten, und was oben liegt, muss er ohnehin erst erklettern.

**Warum nicht einfach kleiner skalieren, bis es passt?** Weil dann die ganzzahlige Skalierung
fällt, und mit ihr die Schärfe der Pixel – der Punkt, um den sich 0011 und 0012 drehen.

**Warum `100dvh` und nicht `100vh`?** Auf dem Handy fährt die Adressleiste ein und aus.
`100vh` meint die grösste Fensterhöhe; ein Teil des Spiels läge dann unter dem Bildschirmrand,
solange die Leiste sichtbar ist.

## Folgen

- Die Einblendung ist die einzige Fläche der Seite, die scrollen darf (`touch-action: pan-y`
  gegen das `touch-action: none` der Bühne). Nachgewiesen mit einem echten Wisch im Browser:
  die Einblendung rollt, das Fenster nicht.
- Die Spielfläche ist der Sprungknopf – aber die Einblendung ist darin ein toter Bereich.
  Ohne diese Ausnahme verschluckt das `preventDefault` des Sprungknopfs das Antippen von
  Knöpfen und den Fokus des Namensfelds.
- Eine Grössenänderung, die die Bildhöhe wirklich ändert, muss den Zeichenpuffer und die
  bildhohen Hintergrundebenen neu bauen. Figuren- und Kachelblätter hängen nicht von der Höhe
  ab und werden zwischengespeichert.
- Neuer Test `test/dom.test.mjs`: jede id, die das JavaScript sucht, muss im HTML stehen, und
  jeder Übersetzungsschlüssel muss in beiden Katalogen vorkommen – in beide Richtungen. Der
  Umbau hier hat genau diesen Fehler erzeugt (das JavaScript fragte Elemente ab, die aus dem
  HTML gefallen waren), und ein fehlendes `getElementById` ergibt nur `null` und stirbt erst
  beim Zugriff.

## Wann neu bewerten

Wenn das Spiel eine Ansicht bekommt, die mehr Platz braucht als eine Einblendung – etwa eine
Bestenliste mit Filtern oder Profilen. Dann wäre eine zweite Seite neben `index.html` die
ehrlichere Lösung, statt die Bühne wieder zu einem Kasten in einem Dokument zu machen.
