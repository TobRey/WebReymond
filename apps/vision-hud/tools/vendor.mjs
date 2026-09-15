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
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';

const HERE = dirname(fileURLToPath(import.meta.url));
const APP = resolve(HERE, '..');
const VENDOR = join(APP, 'site', 'assets', 'vendor');
const MODELS = join(APP, 'site', 'assets', 'models');

/** Feste Versionen – ein Update ist eine bewusste Änderung, kein Zufall. */
const NPM = [
  { name: '@vladmandic/face-api', version: '1.7.15' },
  { name: '@tensorflow-models/coco-ssd', version: '2.2.3' },
  // Muss zur TensorFlow-Version passen, die in face-api.js steckt (4.22.0).
  { name: '@tensorflow/tfjs-backend-wasm', version: '4.22.0' },
];

/** Aus dem face-api-Paket übernommene Modelle. Alles andere bleibt draussen. */
const FACE_MODELS = [
  'tiny_face_detector_model',
  'face_landmark_68_model',
  'face_recognition_model',
  'age_gender_model',
  'face_expression_model',
];

/** COCO-SSD („ssdlite_mobilenet_v2“) – das schnellste der drei Grundmodelle. */
const COCO_BASE = 'https://storage.googleapis.com/tfjs-models/savedmodel/ssdlite_mobilenet_v2';

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
  const cocoPkg = await fetchNpmPackage(NPM[1].name, NPM[1].version, tmp);
  const wasmPkg = await fetchNpmPackage(NPM[2].name, NPM[2].version, tmp);

  /*
   * face-api.js bringt TensorFlow.js 4.22 bereits mit und veröffentlicht die
   * Instanz als `faceapi.tf`. Genau diese Instanz reicht die Seite an COCO-SSD
   * weiter – so läuft im Browser nur ein einziger WebGL-Kontext statt zwei.
   */
  const lib = [
    [join(faceApiPkg, 'dist', 'face-api.js'), 'face-api.js'],
    [join(faceApiPkg, 'LICENSE'), 'face-api.LICENSE.txt'],
    [join(cocoPkg, 'dist', 'coco-ssd.min.js'), 'coco-ssd.min.js'],
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
  for (const file of [
    'tfjs-backend-wasm.wasm',
    'tfjs-backend-wasm-simd.wasm',
    'tfjs-backend-wasm-threaded-simd.wasm',
  ]) {
    const buf = await readFile(join(wasmPkg, 'dist', file));
    await writeFile(join(VENDOR, file), buf);
    await record(`vendor/${file}`, buf);
    log('wasm', `${file} (${(buf.length / 1024).toFixed(0)} kB)`);
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

  console.log('\nObjektmodell (COCO-SSD)');
  const cocoDir = join(MODELS, 'coco-ssd');
  await mkdir(cocoDir, { recursive: true });
  const manifestBuf = await download(`${COCO_BASE}/model.json`);
  await writeFile(join(cocoDir, 'model.json'), manifestBuf);
  await record('models/coco-ssd/model.json', manifestBuf);
  log('coco', `model.json (${(manifestBuf.length / 1024).toFixed(0)} kB)`);

  const manifest = JSON.parse(manifestBuf.toString('utf8'));
  const shards = manifest.weightsManifest.flatMap((group) => group.paths);
  for (const shard of shards) {
    const buf = await download(`${COCO_BASE}/${shard}`);
    await writeFile(join(cocoDir, shard), buf);
    await record(`models/coco-ssd/${shard}`, buf);
    log('coco', `${shard} (${(buf.length / 1024).toFixed(0)} kB)`);
  }

  const total = inventory.reduce((sum, entry) => sum + entry.bytes, 0);
  await writeFile(
    join(APP, 'vendor-inventory.json'),
    `${JSON.stringify(
      {
        erzeugt: new Date().toISOString().slice(0, 10),
        quellen: {
          'face-api.js': `${NPM[0].name}@${NPM[0].version} (MIT)`,
          'coco-ssd': `${NPM[1].name}@${NPM[1].version} (Apache-2.0)`,
          'tfjs-wasm': `${NPM[2].name}@${NPM[2].version} (Apache-2.0)`,
          'coco-ssd-gewichte': COCO_BASE,
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
