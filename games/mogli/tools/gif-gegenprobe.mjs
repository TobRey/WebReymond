// Gegenprobe: dieselben GIF-Dateien einmal von src/render/gif.js lesen und
// einmal von Chromiums eigenem ImageDecoder – und Pixel für Pixel vergleichen.
//
// Warum das nötig ist: Schreiber und Leser für die Tests stammen beide von
// mir. Enthielten beide denselben Denkfehler, würden sie sich gegenseitig
// bestätigen und jeder Test wäre grün. Chromiums Decoder ist eine fremde,
// unabhängige Umsetzung desselben Formats – erst der Vergleich mit ihr sagt
// etwas aus.
//
// Aufruf (Chromium mit --remote-debugging-port=9222 muss laufen):
//   node games/mogli/tools/gif-gegenprobe.mjs

import { makeGif } from '../test/gifmaker.mjs';
import { decodeGif } from '../web/src/render/gif.js';
import { faelle } from '../test/giffaelle.mjs';

const CDP = 'http://127.0.0.1:9222';
// ImageDecoder gibt es nur in einem sicheren Kontext - about:blank ist keiner.
const SEITE = 'http://127.0.0.1:8110/';

async function tab() {
  const res = await fetch(`${CDP}/json/new?${encodeURIComponent(SEITE)}`, { method: 'PUT' });
  return await res.json();
}

async function verbinde(wsUrl) {
  const ws = new WebSocket(wsUrl);
  const offen = new Map();
  let id = 0;
  ws.addEventListener('message', (ev) => {
    const msg = JSON.parse(ev.data);
    const warte = offen.get(msg.id);
    if (!warte) return;
    offen.delete(msg.id);
    if (msg.error) warte.reject(new Error(JSON.stringify(msg.error)));
    else warte.resolve(msg.result);
  });
  await new Promise((res, rej) => {
    ws.addEventListener('open', res, { once: true });
    ws.addEventListener('error', rej, { once: true });
  });
  return {
    ws,
    async eval(expression) {
      const r = await new Promise((resolve, reject) => {
        const n = ++id;
        offen.set(n, { resolve, reject });
        ws.send(
          JSON.stringify({
            id: n,
            method: 'Runtime.evaluate',
            params: { expression, returnByValue: true, awaitPromise: true },
          }),
        );
      });
      if (r.exceptionDetails)
        throw new Error(r.exceptionDetails.exception?.description ?? 'Fehler');
      return r.result.value;
    },
  };
}

const t = await tab();
const s = await verbinde(t.webSocketDebuggerUrl);

const kann = await s.eval(`typeof ImageDecoder`);
if (kann !== 'function') {
  console.error('Dieser Browser hat keinen ImageDecoder – Gegenprobe nicht möglich.');
  process.exit(2);
}

let fehler = 0;
for (const fall of faelle()) {
  const bytes = makeGif(fall.spec);
  const meins = decodeGif(bytes);
  const b64 = Buffer.from(bytes).toString('base64');

  const fremd = await s.eval(`(async () => {
    const roh = Uint8Array.from(atob(${JSON.stringify(b64)}), (c) => c.charCodeAt(0));
    const dec = new ImageDecoder({ data: roh, type: 'image/gif' });
    await dec.tracks.ready;
    await dec.completed;
    const anzahl = dec.tracks.selectedTrack.frameCount;
    const bilder = [];
    for (let i = 0; i < anzahl; i += 1) {
      const { image } = await dec.decode({ frameIndex: i, completeFramesOnly: true });
      const c = new OffscreenCanvas(image.displayWidth, image.displayHeight);
      const ctx = c.getContext('2d');
      ctx.drawImage(image, 0, 0);
      bilder.push([...ctx.getImageData(0, 0, c.width, c.height).data]);
      image.close();
    }
    return { anzahl, bilder };
  })()`);

  let melde = `${fall.name.padEnd(34)} ${meins.frames.length} Bilder`;
  if (fremd.anzahl !== meins.frames.length) {
    console.log(`FEHLER ${melde}  – Chromium sieht ${fremd.anzahl}`);
    fehler += 1;
    continue;
  }

  let abweichungen = 0;
  for (let f = 0; f < meins.frames.length; f += 1) {
    const a = meins.frames[f].pixels;
    const b = fremd.bilder[f];
    for (let i = 0; i < a.length; i += 1) {
      // Vollständig durchsichtige Pixel: die Farbe darunter ist unerheblich,
      // beide Umsetzungen dürfen dort etwas anderes stehen lassen.
      if (a[3 + (i - (i % 4))] === 0 && b[3 + (i - (i % 4))] === 0) continue;
      if (a[i] !== b[i]) abweichungen += 1;
    }
  }
  if (abweichungen === 0) console.log(`gleich ${melde}`);
  else {
    console.log(`FEHLER ${melde}  – ${abweichungen} abweichende Werte`);
    fehler += 1;
  }
}

s.ws.close();
await fetch(`${CDP}/json/close/${t.id}`);
console.log(fehler === 0 ? '\nAlle Fälle gleich.' : `\n${fehler} Fall/Fälle weichen ab.`);
process.exit(fehler === 0 ? 0 : 1);
