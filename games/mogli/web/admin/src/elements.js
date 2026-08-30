// Der Reiter "Elemente": je Katalog-Typ ein Bild und eine Trefferfläche.
//
// Die Liste baut sich aus game/elements.js – ein neues Element im Katalog
// erscheint hier von selbst. Bilder gehen in beliebiger Grösse hinein
// (GIF: erstes Bild) und werden nur so weit verkleinert, dass sie ins Paket
// passen; das Spiel zieht sie beim Zeichnen ohnehin auf die Elementgrösse.

import { ELEMENTS, ELEMENT_TYPES } from '../../src/game/elements.js';
import { decodeGif } from '../../src/render/gif.js';
import { bindBoxEditor } from './hitbox.js';
import { bindFileArea, loadImage } from './sheet.js';

const MAX_SIDE = 128;

/**
 * Öffnet eine beliebige Bilddatei und bringt sie – nur wenn nötig – unter
 * MAX_SIDE Kantenlänge. KEIN Auffüllen auf ein Quadrat: das Seitenverhältnis
 * gehört dem Bild, und Ränder würden später mitgestreckt.
 */
async function acceptAnySize(file) {
  let quelle;
  let w;
  let h;
  if (file.type.includes('gif') || /\.gif$/i.test(file.name)) {
    const gif = decodeGif(new Uint8Array(await file.arrayBuffer()));
    quelle = document.createElement('canvas');
    quelle.width = gif.width;
    quelle.height = gif.height;
    const ctx = quelle.getContext('2d');
    const bild = ctx.createImageData(gif.width, gif.height);
    bild.data.set(gif.frames[0].pixels);
    ctx.putImageData(bild, 0, 0);
    w = gif.width;
    h = gif.height;
  } else {
    const bitmap = await createImageBitmap(file);
    quelle = bitmap;
    w = bitmap.width;
    h = bitmap.height;
  }

  const faktor = Math.min(1, MAX_SIDE / Math.max(w, h));
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, Math.round(w * faktor));
  canvas.height = Math.max(1, Math.round(h * faktor));
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingEnabled = faktor < 1;
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(quelle, 0, 0, canvas.width, canvas.height);
  quelle.close?.();
  return { dataUrl: canvas.toDataURL('image/png'), width: canvas.width, height: canvas.height };
}

/**
 * Baut den Reiter auf.
 *
 * @param {HTMLElement} container
 * @param {{ get: () => object, status: (text: string, bad?: boolean) => void }} host
 *   host.get() liefert das Paket-Objekt (pack), an dem direkt geändert wird.
 */
export function buildElementsTab(container, host) {
  container.replaceChildren();

  for (const type of ELEMENT_TYPES) {
    const def = ELEMENTS[type];
    const card = document.createElement('div');
    card.className = 'element';

    const title = document.createElement('h3');
    title.textContent = def.name.de;
    const wish = document.createElement('p');
    wish.className = 'note';
    wish.textContent = `Wunsch: ${def.sprite.de} (${def.sprite.size})`;

    const row = document.createElement('div');
    row.className = 'element__row';

    // Bildfläche: Klick oder Ablegen setzt das Bild.
    const view = document.createElement('canvas');
    view.className = 'element__view';
    view.width = def.cells[0] * 16;
    view.height = def.cells[1] * 16;

    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.hidden = true;

    // Trefferflächen-Canvas: zeigt das Bild in Originalauflösung.
    const boxCanvas = document.createElement('canvas');
    boxCanvas.className = 'element__box';
    boxCanvas.width = 32;
    boxCanvas.height = 32;

    const numbers = document.createElement('p');
    numbers.className = 'numbers';

    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'btn btn--ghost';
    clear.textContent = 'Zurücksetzen';

    let image = null;
    let unbind = null;

    const entry = () => {
      const pack = host.get();
      pack.elements ??= {};
      pack.elements[type] ??= {};
      return pack.elements[type];
    };
    const stored = () => host.get().elements?.[type] ?? {};

    const redraw = () => {
      const vctx = view.getContext('2d');
      vctx.imageSmoothingEnabled = false;
      vctx.clearRect(0, 0, view.width, view.height);
      if (image !== null) vctx.drawImage(image, 0, 0, view.width, view.height);
      view.classList.toggle('element__view--filled', image !== null);

      const bctx = boxCanvas.getContext('2d');
      bctx.imageSmoothingEnabled = false;
      bctx.clearRect(0, 0, boxCanvas.width, boxCanvas.height);
      if (image !== null) bctx.drawImage(image, 0, 0, boxCanvas.width, boxCanvas.height);
      const box = stored().box;
      if (box !== undefined) {
        bctx.strokeStyle = '#3fe3a8';
        bctx.lineWidth = Math.max(1, Math.round(boxCanvas.width / 48));
        bctx.strokeRect(box.x + 0.5, box.y + 0.5, box.w - 1, box.h - 1);
        numbers.textContent = `Kasten: x ${box.x} y ${box.y} · ${box.w} × ${box.h}`;
      } else {
        numbers.textContent = image === null ? 'mitgelieferte Grafik' : 'Kasten: ganzes Bild';
      }
    };

    const rebindBox = () => {
      unbind?.();
      // Der Kasten wird auf BILDpixel gezogen; Grösse = die des Bildes.
      unbind = bindBoxEditor(
        boxCanvas,
        Math.max(boxCanvas.width, boxCanvas.height),
        () => stored().box ?? { x: 0, y: 0, w: boxCanvas.width, h: boxCanvas.height },
        (box) => {
          if (image === null) return;
          const clamp = (v, max) => Math.max(0, Math.min(max, v));
          entry().box = {
            x: clamp(box.x, boxCanvas.width - 1),
            y: clamp(box.y, boxCanvas.height - 1),
            w: Math.max(1, Math.min(box.w, boxCanvas.width - clamp(box.x, boxCanvas.width - 1))),
            h: Math.max(1, Math.min(box.h, boxCanvas.height - clamp(box.y, boxCanvas.height - 1))),
          };
          redraw();
          host.status(`${def.name.de}: Trefferfläche geändert – noch nicht gespeichert.`);
        },
      );
    };

    const applyImage = async (dataUrl) => {
      image = await loadImage(dataUrl);
      boxCanvas.width = image.naturalWidth;
      boxCanvas.height = image.naturalHeight;
      rebindBox();
      redraw();
    };

    bindFileArea(view, input, async (file) => {
      try {
        const result = await acceptAnySize(file);
        entry().image = result.dataUrl;
        delete entry().box;
        await applyImage(result.dataUrl);
        host.status(
          `${def.name.de}: ${result.width} × ${result.height} übernommen. Noch nicht gespeichert.`,
        );
      } catch (error) {
        host.status(`${def.name.de}: ${error.message}`, true);
      }
    });

    clear.addEventListener('click', () => {
      const pack = host.get();
      if (pack.elements !== undefined) delete pack.elements[type];
      image = null;
      boxCanvas.width = 32;
      boxCanvas.height = 32;
      rebindBox();
      redraw();
      host.status(`${def.name.de}: zurück auf mitgeliefert – noch nicht gespeichert.`);
    });

    row.append(view, boxCanvas);
    card.append(title, wish, row, numbers, clear, input);
    container.append(card);

    rebindBox();
    // Gespeichertes Bild anzeigen, falls vorhanden.
    const existing = stored().image;
    if (existing !== undefined) {
      applyImage(existing).catch(() => {});
    } else {
      redraw();
    }
  }
}
