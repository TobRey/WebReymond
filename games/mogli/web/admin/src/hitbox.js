// Einen Kasten über einem Bild aufziehen – mit der Maus wie mit dem Finger.
//
// Der Kasten rastet auf ganze Pixel des BILDES ein, nicht auf Bildschirmpixel:
// der Canvas ist 32 Pixel gross und wird per CSS auf 192 aufgeblasen, ein
// Mauspixel ist also ein Sechstel Bildpixel. Ohne Einrasten bekäme man Kästen
// wie 11.83 – und die Physik rechnet mit ganzen Zahlen.

/**
 * @param {HTMLCanvasElement} canvas
 * @param {number} size Kantenlänge des Bildes in Pixeln (32 oder 16).
 * @param {() => {x:number,y:number,w:number,h:number}} getBox
 * @param {(box: {x:number,y:number,w:number,h:number}) => void} onChange
 * @returns {() => void} Abmelden.
 */
export function bindBoxEditor(canvas, size, getBox, onChange) {
  let dragFrom = null;

  /** Bildschirmpunkt -> Bildpixel. */
  function toPixel(event) {
    const rect = canvas.getBoundingClientRect();
    const x = ((event.clientX - rect.left) / rect.width) * size;
    const y = ((event.clientY - rect.top) / rect.height) * size;
    return {
      x: Math.max(0, Math.min(size - 1, Math.floor(x))),
      y: Math.max(0, Math.min(size - 1, Math.floor(y))),
    };
  }

  function boxBetween(a, b) {
    const x = Math.min(a.x, b.x);
    const y = Math.min(a.y, b.y);
    // +1, weil beide Endpunkte zum Kasten gehören: von Pixel 3 bis Pixel 3
    // gezogen ist ein Kasten der Breite 1, nicht 0.
    return { x, y, w: Math.abs(b.x - a.x) + 1, h: Math.abs(b.y - a.y) + 1 };
  }

  function onDown(event) {
    if (event.button !== undefined && event.button !== 0) return;
    canvas.setPointerCapture?.(event.pointerId);
    dragFrom = toPixel(event);
    onChange(boxBetween(dragFrom, dragFrom));
    event.preventDefault();
  }

  function onMove(event) {
    if (dragFrom === null) return;
    onChange(boxBetween(dragFrom, toPixel(event)));
    event.preventDefault();
  }

  function onUp(event) {
    if (dragFrom === null) return;
    dragFrom = null;
    canvas.releasePointerCapture?.(event.pointerId);
  }

  canvas.addEventListener('pointerdown', onDown);
  canvas.addEventListener('pointermove', onMove);
  canvas.addEventListener('pointerup', onUp);
  canvas.addEventListener('pointercancel', onUp);

  return () => {
    canvas.removeEventListener('pointerdown', onDown);
    canvas.removeEventListener('pointermove', onMove);
    canvas.removeEventListener('pointerup', onUp);
    canvas.removeEventListener('pointercancel', onUp);
  };
}

/**
 * Zeichnet Bild und Kasten.
 *
 * Der Kasten ist nicht nur ein Rahmen, sondern dunkelt auch alles ausserhalb
 * ab. Ein blosser Rahmen auf einem bunten Bild ist schwer zu sehen; so liest
 * man auf einen Blick, was drin ist und was nicht.
 *
 * @param {HTMLCanvasElement} canvas
 * @param {HTMLImageElement|null} image
 * @param {{x:number,y:number,w:number,h:number}} box
 * @param {{grid?: boolean, color?: string}} [options]
 */
export function drawBox(canvas, image, box, options = {}) {
  const ctx = canvas.getContext('2d');
  const size = canvas.width;
  ctx.imageSmoothingEnabled = false;
  ctx.clearRect(0, 0, size, canvas.height);

  // Schachbrett als Untergrund: so sieht man durchsichtige Stellen.
  for (let y = 0; y < canvas.height; y += 4) {
    for (let x = 0; x < size; x += 4) {
      ctx.fillStyle = ((x >> 2) + (y >> 2)) % 2 === 0 ? '#14261f' : '#0d1a16';
      ctx.fillRect(x, y, 4, 4);
    }
  }

  if (image !== null) ctx.drawImage(image, 0, 0, size, canvas.height);

  // Alles ausserhalb des Kastens abdunkeln.
  ctx.fillStyle = 'rgba(5, 10, 9, 0.62)';
  ctx.fillRect(0, 0, size, box.y);
  ctx.fillRect(0, box.y + box.h, size, canvas.height - box.y - box.h);
  ctx.fillRect(0, box.y, box.x, box.h);
  ctx.fillRect(box.x + box.w, box.y, size - box.x - box.w, box.h);

  // Rahmen, einen Pixel breit.
  ctx.fillStyle = options.color ?? '#3fe3a8';
  ctx.fillRect(box.x, box.y, box.w, 1);
  ctx.fillRect(box.x, box.y + box.h - 1, box.w, 1);
  ctx.fillRect(box.x, box.y, 1, box.h);
  ctx.fillRect(box.x + box.w - 1, box.y, 1, box.h);
}
