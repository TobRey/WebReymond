// Wie ein beliebiges Bild auf ein festes Mass kommt.
//
// WARUM ES DIESE DATEI GIBT
// Der Admin-Bereich hat früher jede Datei abgelehnt, die nicht schon genau
// 32 × 32 oder 16 × 16 war. Das ist für den, der Grafik einsetzen will, eine
// Zumutung: er soll zeichnen, nicht Masse abzählen. Jetzt wird umgerechnet.
//
// Hier steht nur die Rechnerei – keine Leinwand, kein DOM –, damit sie sich
// prüfen lässt, ohne einen Browser zu starten. Das Zeichnen selbst macht
// admin/src/sheet.js.
//
// Verkleinern ist immer ein Verlust; die Frage ist nur, welcher. Für Pixelart
// ist Glätten der falsche: aus scharfen Kanten wird Matsch. Für ein Foto ist
// Nicht-Glätten der falsche: es entstehen Treppen und flimmernde Kanten.
// Welcher Fall vorliegt, verrät das Verhältnis der Masse (siehe scaleMode).

/**
 * Das Zielrechteck für ein Bild, das in ein Feld gelegt wird.
 *
 * `contain` legt es vollständig hinein und lässt gegebenenfalls Rand: eine
 * Figur, die 40 × 20 gezeichnet ist, wird nicht zum Quadrat gezerrt. Für
 * Sprites und Kacheln ist das die richtige Wahl – ein verzerrter Kopf fällt
 * sofort auf, ein durchsichtiger Rand niemandem.
 *
 * @returns {{x: number, y: number, w: number, h: number}} ganzzahlig
 */
export function containRect(srcW, srcH, dstW, dstH) {
  if (srcW <= 0 || srcH <= 0) return { x: 0, y: 0, w: dstW, h: dstH };
  const faktor = Math.min(dstW / srcW, dstH / srcH);
  const w = Math.max(1, Math.round(srcW * faktor));
  const h = Math.max(1, Math.round(srcH * faktor));
  return { x: Math.round((dstW - w) / 2), y: Math.round((dstH - h) / 2), w, h };
}

/**
 * Die Masse für eine Hintergrundebene.
 *
 * Sie muss zwei Aufgaben können, und die zweite ist die, an der die erste
 * Fassung gescheitert ist:
 *
 *   - mitlaufend (Tempo > 0): sie wird auf die Breite des Spiels gezogen und
 *     untereinander wiederholt.
 *   - stehend (Tempo 0): sie füllt den ganzen Bildschirm.
 *
 * Für das Füllen reicht "auf Spielbreite bringen" nicht. Ein Foto im
 * Verhältnis 1:2 ist bei 256 Pixeln Breite nur 534 hoch, der Bildschirm aber
 * bis zu 640 - es müsste zur Laufzeit vergrössert werden und würde weich.
 * Deshalb wird hier schon so gerechnet, dass das Bild den höchsten möglichen
 * Bildschirm deckt: vergrössert wird nie, nur verkleinert.
 *
 * Für ein breites Bild führt dieselbe Rechnung ins Absurde - ein 16:9-Foto
 * müsste 1139 Pixel breit werden, um 640 hoch zu sein, und davon sähe man ein
 * Viertel. Deshalb die Breitengrenze: darüber bleibt es beim Einpassen in die
 * Breite, und der Rest ist beim Füllen eben etwas weicher.
 *
 * @returns {{width: number, height: number, crop: {y: number, h: number}}}
 */
export function layerSize(srcW, srcH, ziel) {
  const { width: dstW, coverHeight, maxHeight: maxH, maxWidth: maxW = dstW * 2 } = ziel;
  if (srcW <= 0 || srcH <= 0) return { width: dstW, height: dstW, crop: { y: 0, h: srcH } };

  // So gross, dass sowohl die Breite als auch der höchste Bildschirm gedeckt
  // sind. coverHeight ist die Bildschirmhöhe, maxH die Grenze der Ablage -
  // die beiden zu verwechseln macht aus jedem Bild eine unnötig grosse Datei.
  let faktor = Math.max(dstW / srcW, coverHeight / srcH);
  // Aber nie breiter als erlaubt, und nie vergrössert.
  faktor = Math.min(faktor, maxW / srcW, 1);
  // Die Breite des Spiels ist das Mindeste, sonst wäre die Ebene schmaler als
  // der Schacht - auch dann, wenn die Quelle winzig ist.
  faktor = Math.max(faktor, dstW / srcW);

  const width = Math.max(1, Math.round(srcW * faktor));
  const volleHoehe = Math.max(1, Math.round(srcH * faktor));
  if (volleHoehe <= maxH) {
    return { width, height: volleHoehe, crop: { y: 0, h: srcH } };
  }

  // Zu hoch: mittig so viel herausschneiden, dass es gerade passt. Gestaucht
  // sähe der Hintergrund falsch aus, ein Ausschnitt nur anders.
  const behalten = Math.max(1, Math.round((maxH * srcW) / width));
  return {
    width,
    height: maxH,
    crop: { y: Math.round((srcH - behalten) / 2), h: behalten },
  };
}

/**
 * Glätten oder nicht?
 *
 * Vergrössern ist immer Pixelart-Fall: aus 16 × 16 werden 32 × 32, und dabei
 * soll jeder Punkt ein sauberes Quadrat werden, kein Farbverlauf.
 *
 * Beim Verkleinern entscheidet, ob das Verhältnis glatt aufgeht. 64 → 32 ist
 * genau die Hälfte: jeder Zielpunkt hat eine eindeutige Quelle, ohne Glätten
 * bleibt die Zeichnung scharf. 100 → 32 geht nicht auf; ohne Glätten fielen
 * dort ganze Zeilen weg, und übrig bliebe ein zerfranstes Bild.
 *
 * @returns {'pixel'|'weich'}
 */
export function scaleMode(srcW, srcH, dstW, dstH) {
  if (srcW <= dstW && srcH <= dstH) return 'pixel';
  const teilerX = srcW / dstW;
  const teilerY = srcH / dstH;
  const glatt = (t) => Math.abs(t - Math.round(t)) < 1e-6;
  return glatt(teilerX) && glatt(teilerY) ? 'pixel' : 'weich';
}
