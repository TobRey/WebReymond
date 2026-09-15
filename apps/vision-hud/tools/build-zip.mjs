/**
 * Packt die Seite zu einem ZIP für den Upload per cPanel-Dateimanager.
 *
 * Das Archiv enthält **einen einzigen Ordner** (standardmässig `kamera-hud`).
 * Das ist Absicht: Wer das ZIP in `public_html` hochlädt und dort entpackt,
 * bekommt die Seite automatisch unter example.com/kamera-hud/ – und nicht
 * über die bestehende Website gestreut.
 *
 * Aufruf:  node tools/build-zip.mjs [ordnername]
 */
import { cp, mkdir, rm, readdir, stat } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';

const APP = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const SITE = join(APP, 'site');
const DIST = join(APP, 'dist');

const folder = process.argv[2] ?? 'kamera-hud';
if (!/^[a-z0-9][a-z0-9-]{0,40}$/.test(folder)) {
  console.error(`Ungültiger Ordnername "${folder}" – erlaubt sind a–z, 0–9 und Bindestrich.`);
  process.exit(1);
}

/** Summiert Dateigrössen und zählt Dateien. */
async function measure(dir) {
  let bytes = 0;
  let files = 0;
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) {
      const inner = await measure(path);
      bytes += inner.bytes;
      files += inner.files;
    } else {
      bytes += (await stat(path)).size;
      files += 1;
    }
  }
  return { bytes, files };
}

function run(command, args, cwd) {
  return new Promise((ok, fail) => {
    const child = spawn(command, args, { cwd, stdio: ['ignore', 'pipe', 'pipe'] });
    let stderr = '';
    child.stderr.on('data', (chunk) => (stderr += chunk));
    child.on('error', fail);
    child.on('exit', (code) =>
      code === 0 ? ok() : fail(new Error(`${command} endete mit ${code}: ${stderr}`)),
    );
  });
}

/*
 * Ohne Modelle gibt es nichts zu packen. Das ist der Normalzustand eines
 * frischen Klons – die rund 28 MB liegen bewusst nicht im Repository.
 * Deshalb wird hier sauber übersprungen statt abgebrochen: Sonst würde
 * `pnpm build` im gesamten Monorepo (und damit die CI) fehlschlagen.
 */
const models = join(SITE, 'assets', 'models');
try {
  await stat(join(models, 'face_recognition_model.bin'));
} catch {
  console.log(
    [
      '',
      '  Übersprungen: die Modelle fehlen noch.',
      '  Zum Erzeugen des ZIP zuerst ausführen:',
      '      pnpm --filter @webheaven/vision-hud vendor',
      '',
    ].join('\n'),
  );
  process.exit(0);
}

await rm(DIST, { recursive: true, force: true });
await mkdir(DIST, { recursive: true });

const staged = join(DIST, folder);
// Punktdateien wie .htaccess werden mitkopiert – sie sind Teil der Auslieferung.
await cp(SITE, staged, { recursive: true });

const { bytes, files } = await measure(staged);
const zipName = `vision-hud-${folder}.zip`;

// -r rekursiv, -q leise, -9 stärkste Kompression.
await run('zip', ['-r', '-q', '-9', zipName, folder], DIST);
const zipBytes = (await stat(join(DIST, zipName))).size;

console.log(`
  Archiv:        dist/${zipName}
  Ordner darin:  ${folder}/
  Inhalt:        ${files} Dateien, ${(bytes / 1048576).toFixed(1)} MB
  Archivgrösse:  ${(zipBytes / 1048576).toFixed(1)} MB

  Hochladen:     cPanel → Dateimanager → public_html → Hochladen → ${zipName}
                 danach Rechtsklick auf das ZIP → Extract
  Erreichbar:    https://DEINE-DOMAIN.tld/${folder}/
`);
