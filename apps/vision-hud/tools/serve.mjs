/**
 * Kleiner Entwicklungsserver.
 *
 * Er legt die Seite absichtlich in einen Unterordner (/kamera-hud/), damit
 * beim Entwickeln genau dieselben Pfade gelten wie später beim Hoster. Auf
 * localhost gibt der Browser die Kamera auch ohne https:// frei.
 */
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { dirname, extname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const SITE = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'site');
const PORT = Number(process.env.PORT ?? 4173);
const MOUNT = '/kamera-hud';

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json',
  '.webmanifest': 'application/manifest+json',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.bin': 'application/octet-stream',
};

createServer(async (request, response) => {
  let path = decodeURIComponent(request.url.split('?')[0]);

  if (path === '/' || path === MOUNT) {
    response.writeHead(302, { location: `${MOUNT}/` });
    response.end();
    return;
  }
  if (!path.startsWith(`${MOUNT}/`)) {
    response.writeHead(404).end('Nicht gefunden');
    return;
  }

  path = path.slice(MOUNT.length);
  const file = join(SITE, path.endsWith('/') ? `${path}index.html` : path);

  // Verhindert, dass ".." aus dem Ordner herausführt.
  if (!file.startsWith(SITE)) {
    response.writeHead(403).end('Verboten');
    return;
  }

  try {
    await stat(file);
    response.writeHead(200, {
      'content-type': TYPES[extname(file)] ?? 'application/octet-stream',
      'cache-control': 'no-store',
    });
    response.end(await readFile(file));
  } catch {
    response.writeHead(404).end('Nicht gefunden');
  }
}).listen(PORT, () => {
  console.log(`\n  Vision HUD:  http://localhost:${PORT}${MOUNT}/\n`);
});
