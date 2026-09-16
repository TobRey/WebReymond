/**
 * Holt die Bibliotheken und Modellgewichte, die das HUD zur Laufzeit braucht,
 * und legt sie unter site/assets/vendor bzw. site/assets/models ab.
 *
 * Warum lokal statt CDN?
 *  - Die fertige Seite läuft auf einem gewöhnlichen Webhosting-Paket ohne
 *    Internetzugriff auf fremde Domains (strenge CSP, Firmen-Proxys, Offline-Demo).
 *  - Keine dritte Partei erfährt, wer die Kamera-Seite öffnet.
 *
 * Aufruf:  node tools/vendor.mjs      (oder: pnpm --filter @webheaven/vision-hud vendor)
 */
import { createHash } from 'node:crypto';
import { mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { gzipSync } from 'node:zlib';

const HERE = dirname(fileURLToPath(import.meta.url));
const APP = resolve(HERE, '..');
const VENDOR = join(APP, 'site', 'assets', 'vendor');
const MODELS = join(APP, 'site', 'assets', 'models');

/** Feste Versionen – ein Update ist eine bewusste Änderung, kein Zufall. */
const NPM = [
  { name: '@vladmandic/face-api', version: '1.7.15' },
  // Muss zur TensorFlow-Version passen, die in face-api.js steckt (4.22.0).
  { name: '@tensorflow/tfjs-backend-wasm', version: '4.22.0' },
  // Handerkennung mit 21 Landmarken und eingebauten Zeichen.
  { name: '@mediapipe/tasks-vision', version: '1.0.1' },
  // Texterkennung – wird erst beim ersten Vorlesen nachgeladen.
  { name: 'tesseract.js', version: '7.0.0' },
  { name: 'tesseract.js-core', version: '7.0.0' },
];

/**
 * Sprachdaten für die Texterkennung.
 *
 * Bewusst aus `tessdata_fast` statt aus den npm-Paketen: Die npm-Pakete liefern
 * die "best"-Modelle mit 7 bzw. 11 MB je Sprache. Die schnellen Modelle sind
 * 1,5 bzw. 4 MB, gepackt zusammen unter 3 MB – auf einem Handy ist der
 * Genauigkeitsunterschied bei Schildern und Verpackungen nicht der Rede wert.
 */
const TESSDATA = 'https://raw.githubusercontent.com/tesseract-ocr/tessdata_fast/main';
const TESS_LANGS = ['deu', 'eng'];

/** Aus dem face-api-Paket übernommene Modelle. Alles andere bleibt draussen. */
const FACE_MODELS = [
  'tiny_face_detector_model',
  'face_landmark_68_model',
  'face_recognition_model',
  'age_gender_model',
  'face_expression_model',
];

/** Gestenmodell von MediaPipe (Handerkennung + Landmarken + 7 Zeichen). */
const GESTURE_TASK =
  'https://storage.googleapis.com/mediapipe-models/gesture_recognizer/gesture_recognizer/float16/1/gesture_recognizer.task';

/**
 * Objektdetektor (601 Klassen) und Zweitstufe (1000 Klassen) liegen bereits
 * konvertiert im Repository unter apps/vision-hud/models – die Konvertierung
 * braucht Python und mehrere Gigabyte Werkzeuge und passiert deshalb nicht
 * beim Bauen, sondern einmalig (siehe tools/export-models.md).
 */
const MODELS_SRC = join(APP, 'models');

function log(step, detail) {
  process.stdout.write(`  ${step.padEnd(12)} ${detail}\n`);
}

async function download(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(`${res.status} ${res.statusText} für ${url}`);
  return Buffer.from(await res.arrayBuffer());
}

/** Lädt ein npm-Paket und packt es in ein temporäres Verzeichnis aus. */
async function fetchNpmPackage(name, version, targetDir) {
  const scope = name.startsWith('@') ? name.split('/')[0] : null;
  const bare = name.startsWith('@') ? name.split('/')[1] : name;
  const tarballUrl = scope
    ? `https://registry.npmjs.org/${scope}/${bare}/-/${bare}-${version}.tgz`
    : `https://registry.npmjs.org/${bare}/-/${bare}-${version}.tgz`;

  const tgz = join(targetDir, `${bare}.tgz`);
  await writeFile(tgz, await download(tarballUrl));
  const out = join(targetDir, bare);
  await mkdir(out, { recursive: true });
  await new Promise((ok, fail) => {
    const tar = spawn('tar', ['xzf', tgz, '-C', out], { stdio: 'inherit' });
    tar.on('error', fail);
    tar.on('exit', (code) => (code === 0 ? ok() : fail(new Error(`tar endete mit ${code}`))));
  });
  return join(out, 'package');
}

async function main() {
  const tmp = join(APP, '.vendor-tmp');
  await rm(tmp, { recursive: true, force: true });
  await mkdir(tmp, { recursive: true });
  await mkdir(VENDOR, { recursive: true });
  await mkdir(MODELS, { recursive: true });

  const inventory = [];
  const record = async (relPath, buffer) => {
    const sha = createHash('sha256').update(buffer).digest('hex').slice(0, 16);
    inventory.push({ file: relPath, bytes: buffer.length, sha256: sha });
  };

  console.log('\nBibliotheken');
  const faceApiPkg = await fetchNpmPackage(NPM[0].name, NPM[0].version, tmp);
  const wasmPkg = await fetchNpmPackage(NPM[1].name, NPM[1].version, tmp);
  const mediapipePkg = await fetchNpmPackage(NPM[2].name, NPM[2].version, tmp);
  const tessPkg = await fetchNpmPackage(NPM[3].name, NPM[3].version, tmp);
  const tessCorePkg = await fetchNpmPackage(NPM[4].name, NPM[4].version, tmp);

  /*
   * face-api.js bringt TensorFlow.js 4.22 bereits mit und veröffentlicht die
   * Instanz als `faceapi.tf`. Auf genau dieser Instanz laufen auch Detektor
   * und Zweitstufe – so gibt es im Browser einen einzigen WebGL-Kontext.
   */
  const lib = [
    [join(faceApiPkg, 'dist', 'face-api.js'), 'face-api.js'],
    [join(faceApiPkg, 'LICENSE'), 'face-api.LICENSE.txt'],
  ];
  for (const [from, to] of lib) {
    const buf = await readFile(from);
    await writeFile(join(VENDOR, to), buf);
    await record(`vendor/${to}`, buf);
    log('vendor', `${to} (${(buf.length / 1024).toFixed(0)} kB)`);
  }

  /*
   * TensorFlow sucht die WASM-Dateien im selben Ordner wie das Skript. Fehlen
   * sie, meldet jeder Seitenaufruf einen 404 und Geräte ohne WebGL fallen auf
   * reines JavaScript zurück – um ein Vielfaches langsamer als WASM.
   */
  /*
   * Nur der SIMD-Build. Der Build mit Threads braucht Cross-Origin-Isolation
   * (COOP/COEP-Kopfzeilen), die ein gewöhnliches Hosting nicht setzt – TF.js
   * fragt ihn deshalb nie an. Der Build ohne SIMD wäre für Browser, die auch
   * MediaPipe (SIMD-Pflicht) nicht ausführen können. Beide wären toter Ballast.
   */
  for (const file of ['tfjs-backend-wasm-simd.wasm']) {
    const buf = await readFile(join(wasmPkg, 'dist', file));
    await writeFile(join(VENDOR, file), buf);
    await record(`vendor/${file}`, buf);
    log('wasm', `${file} (${(buf.length / 1024).toFixed(0)} kB)`);
  }

  console.log('\nTexterkennung');
  const tessDir = join(VENDOR, 'tesseract');
  await mkdir(tessDir, { recursive: true });

  /*
   * Nur *ein* Core-Build statt der üblichen drei: Zeigt `corePath` direkt auf
   * eine .js-Datei, überspringt tesseract.js die Merkmalserkennung und lädt
   * genau diese. Das spart rund 8 MB. WASM-SIMD können alle Browser, die auch
   * die übrige Seite tragen (Chrome 91+, Firefox 89+, Safari 16.4+).
   */
  for (const [from, to] of [
    [join(tessPkg, 'dist', 'tesseract.min.js'), 'tesseract.min.js'],
    [join(tessPkg, 'dist', 'worker.min.js'), 'worker.min.js'],
    // Zwei Dateien (Lader + rohes WASM) statt der Einzeldatei mit eingebettetem
    // Base64: ein Drittel kleiner und besser komprimierbar. Das WASM wird vom
    // Lader relativ zum Arbeiter gesucht – beide liegen im selben Ordner.
    [join(tessCorePkg, 'tesseract-core-simd-lstm.js'), 'tesseract-core-simd-lstm.js'],
    [join(tessCorePkg, 'tesseract-core-simd-lstm.wasm'), 'tesseract-core-simd-lstm.wasm'],
  ]) {
    const buf = await readFile(from);
    await writeFile(join(tessDir, to), buf);
    await record(`vendor/tesseract/${to}`, buf);
    log('ocr', `${to} (${(buf.length / 1024).toFixed(0)} kB)`);
  }

  for (const lang of TESS_LANGS) {
    const raw = await download(`${TESSDATA}/${lang}.traineddata`);
    // tesseract.js erwartet die Sprachdaten standardmässig gepackt.
    const packed = gzipSync(raw, { level: 9 });
    await writeFile(join(tessDir, `${lang}.traineddata.gz`), packed);
    await record(`vendor/tesseract/${lang}.traineddata.gz`, packed);
    log('ocr', `${lang}.traineddata.gz (${(packed.length / 1024).toFixed(0)} kB)`);
  }

  console.log('\nGesichtsmodelle');
  for (const model of FACE_MODELS) {
    for (const file of [`${model}-weights_manifest.json`, `${model}.bin`]) {
      const buf = await readFile(join(faceApiPkg, 'model', file));
      await writeFile(join(MODELS, file), buf);
      await record(`models/${file}`, buf);
    }
    log('face', `${model} (${((await sizeOf(join(MODELS, `${model}.bin`))) / 1024) | 0} kB)`);
  }

  console.log('\nHanderkennung (MediaPipe)');
  const mpDir = join(VENDOR, 'mediapipe');
  await mkdir(mpDir, { recursive: true });
  /*
   * Nur der SIMD-Build: Alle Browser, die auch den Rest der Seite tragen,
   * können WASM-SIMD (Safari ab 16.4). Der nosimd-Build wäre 11 MB Ballast.
   */
  for (const [from, to] of [
    [join(mediapipePkg, 'vision_bundle.mjs'), 'vision_bundle.mjs'],
    [join(mediapipePkg, 'wasm', 'vision_wasm_internal.js'), 'vision_wasm_internal.js'],
    [join(mediapipePkg, 'wasm', 'vision_wasm_internal.wasm'), 'vision_wasm_internal.wasm'],
    [join(mediapipePkg, 'LICENSE'), 'mediapipe.LICENSE.txt'],
  ]) {
    const buf = await readFile(from).catch(() => null);
    if (!buf) {
      log('hand', `${to} fehlt im Paket – übersprungen`);
      continue;
    }
    await writeFile(join(mpDir, to), buf);
    await record(`vendor/mediapipe/${to}`, buf);
    log('hand', `${to} (${(buf.length / 1024).toFixed(0)} kB)`);
  }
  const task = await download(GESTURE_TASK);
  await writeFile(join(MODELS, 'gesture_recognizer.task'), task);
  await record('models/gesture_recognizer.task', task);
  log('hand', `gesture_recognizer.task (${(task.length / 1024).toFixed(0)} kB)`);

  console.log('\nDetektor und Zweitstufe (aus dem Repository)');
  for (const name of ['detector', 'classifier']) {
    const src = join(MODELS_SRC, name);
    const dst = join(MODELS, name);
    let entries;
    try {
      entries = await readdir(src);
    } catch {
      log(name, `apps/vision-hud/models/${name} fehlt – Modell wird nicht ausgeliefert`);
      continue;
    }
    await rm(dst, { recursive: true, force: true });
    await mkdir(dst, { recursive: true });
    let bytes = 0;
    for (const file of entries) {
      const buf = await readFile(join(src, file));
      await writeFile(join(dst, file), buf);
      await record(`models/${name}/${file}`, buf);
      bytes += buf.length;
    }
    log(name, `${entries.length} Dateien (${(bytes / 1024).toFixed(0)} kB)`);
  }

  const total = inventory.reduce((sum, entry) => sum + entry.bytes, 0);
  await writeFile(
    join(APP, 'vendor-inventory.json'),
    `${JSON.stringify(
      {
        erzeugt: new Date().toISOString().slice(0, 10),
        quellen: {
          'face-api.js': `${NPM[0].name}@${NPM[0].version} (MIT)`,
          'tfjs-wasm': `${NPM[1].name}@${NPM[1].version} (Apache-2.0)`,
          mediapipe: `${NPM[2].name}@${NPM[2].version} (Apache-2.0)`,
          'gesten-modell': GESTURE_TASK,
          detektor: 'yolov8n-oiv7 (Ultralytics, AGPL-3.0) → TF.js, siehe tools/export-models.md',
          zweitstufe: 'yolov8n-cls (Ultralytics, AGPL-3.0) → TF.js',
          tesseract: `${NPM[3].name}@${NPM[3].version} (Apache-2.0)`,
          'tesseract-sprachdaten': TESSDATA,
        },
        summeBytes: total,
        dateien: inventory,
      },
      null,
      2,
    )}\n`,
  );

  await rm(tmp, { recursive: true, force: true });
  console.log(`\nFertig: ${inventory.length} Dateien, ${(total / 1024 / 1024).toFixed(1)} MB\n`);
}

async function sizeOf(path) {
  const { stat } = await import('node:fs/promises');
  return (await stat(path)).size;
}

main().catch((error) => {
  console.error(`\nAbbruch: ${error.message}\n`);
  process.exitCode = 1;
});
