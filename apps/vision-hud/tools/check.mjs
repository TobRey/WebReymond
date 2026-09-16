/**
 * Prüft die ausgelieferte Seite, ohne einen Browser zu starten.
 *
 * Geprüft wird genau das, was beim Hochladen in einen Unterordner schiefgehen
 * kann und beim Entwickeln auf der Wurzel niemandem auffällt:
 *
 *   1. Kein Pfad beginnt mit "/" – sonst sucht der Browser im Wurzelverzeichnis
 *      der Domain statt im Unterordner.
 *   2. Jede referenzierte Datei existiert wirklich.
 *   3. Jede JavaScript-Datei lässt sich fehlerfrei einlesen.
 *   4. Die Modelldateien passen zu ihren Manifesten.
 *
 * Aufruf: node tools/check.mjs   (oder: pnpm --filter @webheaven/vision-hud test)
 */
import { readFile, readdir, access } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { constants } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const SITE = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'site');
const problems = [];
const notes = [];
let checks = 0;

const ok = async (path) => {
  try {
    await access(path, constants.R_OK);
    return true;
  } catch {
    return false;
  }
};

/* --- 1 + 2: Verweise in index.html --- */
const html = await readFile(join(SITE, 'index.html'), 'utf8');
const refs = [...html.matchAll(/(?:src|href)="([^"]+)"/g)].map((m) => m[1]);

for (const ref of refs) {
  if (ref.startsWith('data:') || ref.startsWith('http')) continue;
  checks += 1;
  if (ref.startsWith('/')) {
    problems.push(`index.html verweist absolut auf "${ref}" – im Unterordner nicht auffindbar.`);
    continue;
  }
  if (!(await ok(join(SITE, ref)))) problems.push(`index.html verweist auf fehlende Datei: ${ref}`);
}

/* --- 1 + 3: JavaScript-Module --- */
const jsDir = join(SITE, 'assets', 'js');
const jsFiles = (await readdir(jsDir)).filter((name) => name.endsWith('.js'));
if (jsFiles.length === 0) problems.push('Keine JavaScript-Module gefunden.');

for (const name of jsFiles) {
  checks += 1;
  const source = await readFile(join(jsDir, name), 'utf8');

  for (const match of source.matchAll(/from\s+'([^']+)'/g)) {
    const target = match[1];
    if (target.startsWith('/')) {
      problems.push(`${name}: absoluter Import "${target}".`);
    } else if (target.startsWith('.') && !(await ok(join(jsDir, target)))) {
      problems.push(`${name}: Import zeigt ins Leere: ${target}`);
    }
  }

  // Ein absoluter Pfad in einem String wäre zur Laufzeit ein 404.
  for (const match of source.matchAll(/['"]\/(assets|models|vendor)\//g)) {
    problems.push(`${name}: absoluter Pfad "${match[0]}" gefunden.`);
  }

  // Das alte 80-Klassen-Modell ist raus; ein Verweis darauf wäre ein 404.
  if (source.includes('coco-ssd')) problems.push(`${name}: Verweis auf das entfernte COCO-Modell.`);

  // Syntaxprüfung mit Nodes eigenem Parser: erkennt die Datei dank
  // "type": "module" in der package.json korrekt als ES-Modul und führt
  // dabei keine Zeile aus.
  const parsed = spawnSync(process.execPath, ['--check', join(jsDir, name)], { encoding: 'utf8' });
  if (parsed.status !== 0) {
    problems.push(`${name}: Syntaxfehler – ${(parsed.stderr || '').trim().split('\n')[0]}`);
  }
}

/* --- 1: CSS --- */
const css = await readFile(join(SITE, 'assets', 'css', 'hud.css'), 'utf8');
for (const match of css.matchAll(/url\(["']?(\/[^"')]+)["']?\)/g)) {
  problems.push(`hud.css: absoluter Pfad ${match[1]}`);
}
checks += 1;

/* --- 4: Modelle --- */
const modelsDir = join(SITE, 'assets', 'models');
if (!(await ok(modelsDir))) {
  notes.push(
    'assets/models fehlt – zuerst "pnpm --filter @webheaven/vision-hud vendor" ausführen.',
  );
} else {
  const entries = await readdir(modelsDir);

  for (const manifestName of entries.filter((n) => n.endsWith('-weights_manifest.json'))) {
    checks += 1;
    const manifest = JSON.parse(await readFile(join(modelsDir, manifestName), 'utf8'));
    for (const path of manifest.flatMap((group) => group.paths)) {
      if (!(await ok(join(modelsDir, path)))) {
        problems.push(`Modellgewicht fehlt: ${path} (aus ${manifestName})`);
      }
    }
  }

  // Detektor und Zweitstufe: Manifest vorhanden, jedes Gewicht da, jedes mit Endung.
  for (const [name, label] of [
    ['detector', 'Objektmodell'],
    ['classifier', 'Zweitstufe'],
  ]) {
    const manifestPath = join(modelsDir, name, 'model.json');
    if (!(await ok(manifestPath))) {
      notes.push(`${label} fehlt (assets/models/${name}) – "vendor" ausführen.`);
      continue;
    }
    checks += 1;
    const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
    for (const path of manifest.weightsManifest.flatMap((group) => group.paths)) {
      if (!(await ok(join(modelsDir, name, path)))) {
        problems.push(`${label}: Gewicht fehlt: ${path}`);
      }
      // Dateien ohne Endung blockieren manche Hoster – das war der Grund für
      // "Objektmodell nicht verfügbar" in der ersten Fassung.
      if (!/\.[a-z0-9]+$/i.test(path)) {
        problems.push(`${label}: Gewicht "${path}" ohne Dateiendung.`);
      }
    }
  }

  checks += 1;
  if (!(await ok(join(modelsDir, 'gesture_recognizer.task')))) {
    notes.push(
      'Handzeichen-Modell fehlt (assets/models/gesture_recognizer.task) – "vendor" ausführen.',
    );
  }

  const vendorDir = join(SITE, 'assets', 'vendor');
  for (const file of [
    'face-api.js',
    'mediapipe/vision_bundle.mjs',
    'mediapipe/vision_wasm_internal.js',
    'mediapipe/vision_wasm_internal.wasm',
  ]) {
    checks += 1;
    if (!(await ok(join(vendorDir, file))))
      problems.push(`Bibliothek fehlt: assets/vendor/${file}`);
  }
}

/* --- Ergebnis --- */
for (const note of notes) console.log(`Hinweis: ${note}`);

if (problems.length > 0) {
  console.error(`\n${problems.length} Problem(e):`);
  for (const problem of problems) console.error(`  · ${problem}`);
  process.exitCode = 1;
} else {
  console.log(`vision-hud: ${checks} Prüfungen bestanden, keine absoluten Pfade.`);
}
