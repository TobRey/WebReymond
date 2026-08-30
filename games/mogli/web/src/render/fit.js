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
 * Die Masse für eine Hintergrundebene: Breite fest, Höhe im Verhältnis.
 *
 * Die Ebene wird im Spiel über die volle Breite gezogen und untereinander
 * wiederholt – die Höhe bestimmt also, nach welcher Strecke sich das Muster
 * wiederholt, und darf beliebig sein. Nach oben gibt es trotzdem eine Grenze,
 * sonst wird aus einem Handyfoto eine Datei, die kein Webspace mehr annimmt.
 * Was darüber liegt, wird mittig beschnitten statt gestaucht: ein gestauchter
 * Hintergrund sieht falsch aus, ein Ausschnitt nur anders.
 *
 * @returns {{width: number, height: number, crop: {y: number, h: number}}}
 */
export function layerSize(srcW, srcH, dstW, maxH) {
  if (srcW <= 0 || srcH <= 0) return { width: dstW, height: dstW, crop: { y: 0, h: srcH } };

  const volleHoehe = Math.max(1, Math.round((srcH * dstW) / srcW));
  if (volleHoehe <= maxH) {
    return { width: dstW, height: volleHoehe, crop: { y: 0, h: srcH } };
  }

  // Zu hoch: mittig so viel herausschneiden, dass es gerade passt.
  const behalten = Math.max(1, Math.round((maxH * srcW) / dstW));
  return {
    width: dstW,
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
