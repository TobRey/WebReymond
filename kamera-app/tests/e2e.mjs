// End-to-End-Tests der Web-App (Chromium im iPhone-Profil) gegen Brücke + CCAPI-Test-Doppel.
// Start (aus kamera-app):  python3 tools/build.py && node tests/e2e.mjs
// Playwright wird aus PLAYWRIGHT_MODULE oder der globalen Installation geladen.

import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, mkdirSync, cpSync, readFileSync, rmSync } from 'node:fs';
import net from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const pwPath = process.env.PLAYWRIGHT_MODULE || '/opt/node-tools/node_modules/playwright/index.mjs';
const { chromium, devices } = await import(pwPath);

const procs = [];
const tmp = mkdtempSync(join(tmpdir(), 'kl-e2e-'));
function freePort() {
  return new Promise((res) => { const s = net.createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => res(p)); }); });
}
async function waitPort(port) {
  for (let i = 0; i < 100; i++) {
    const ok = await new Promise((r) => { const c = net.connect(port, '127.0.0.1', () => { c.end(); r(true); }); c.on('error', () => r(false)); });
    if (ok) return;
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error(`Port ${port} nicht erreichbar`);
}
function start(cmd, args) {
  const p = spawn(cmd, args, { stdio: 'ignore' });
  procs.push(p);
  return p;
}
const sha = (buf) => createHash('sha256').update(buf).digest('hex');

const mockPort = await freePort();
const bridgePort = await freePort();
start('python3', [join(ROOT, 'tools/mock_ccapi.py'), '--port', mockPort, '--count', '14', '--rate', '600000']);
await waitPort(mockPort);
start('python3', [join(ROOT, 'bridge/kamera_bruecke.py'), '--web-dir', join(ROOT, 'web'), '--camera', `127.0.0.1:${mockPort}`,
  '--port', bridgePort, '--data-dir', join(tmp, 'bridge'), '--no-qr']);
await waitPort(bridgePort);
const MOCK = `http://127.0.0.1:${mockPort}`;
const APP = `http://127.0.0.1:${bridgePort}/`;
const mockBytes = async (path) => Buffer.from(await (await fetch(MOCK + path)).arrayBuffer());
const mockLog = async () => (await (await fetch(`${MOCK}/mock/log`)).json()).log;

const browser = await chromium.launch();
const results = [];
async function test(name, fn, opts = {}) {
  if (process.env.ONLY && !new RegExp(process.env.ONLY, 'i').test(name)) return;
  const ctx = await browser.newContext({ ...devices['iPhone 13'], hasTouch: true, acceptDownloads: true, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
  const t0 = Date.now();
  try {
    await fn(page, ctx);
    assert.deepEqual(errors, [], 'JavaScript-Fehler');
    results.push(['OK  ', name, Date.now() - t0]);
  } catch (e) {
    results.push(['FAIL', name, Date.now() - t0, e.message.split('\n').slice(0, 6).join('\n')]);
    await page.screenshot({ path: join(tmp, `${name.replace(/\W+/g, '_')}.png`) }).catch(() => {});
  }
  await ctx.close();
}

async function openGallery(page) {
  await page.goto(`${APP}#galerie`);
  await page.waitForSelector('.tile.ok', { timeout: 15000 });
}
async function waitDone(page, path, timeout = 30000) {
  await page.waitForFunction((p) => window.__kl.queue.find(p)?.status === 'done', path, { timeout });
}
async function pageSha(page, path) {
  return page.evaluate(async (p) => {
    const it = window.__kl.queue.find(p);
    const buf = await (await window.__kl.queue.blob(it.id)).arrayBuffer();
    const d = await crypto.subtle.digest('SHA-256', buf);
    return [...new Uint8Array(d)].map((b) => b.toString(16).padStart(2, '0')).join('');
  }, path);
}

// ---------------------------------------------------------------------------
await test('Galerie listet alle Dateien, Filter trennt RAW/JPEG/Video', async (page) => {
  await openGallery(page);
  const paths = await page.$$eval('.tile', (els) => els.map((e) => e.dataset.path));
  let expected = 0;
  for (const d of (await (await fetch(`${MOCK}/ccapi/ver100/contents/sd`)).json()).path) {
    expected += (await (await fetch(`${MOCK}${d}?kind=number`)).json()).contentsnumber;
  }
  assert.equal(paths.length, expected, 'alle Dateien der Karte');
  const all = paths.length;
  await page.click('[data-filter="raw"]');
  const raw = await page.$$eval('.tile', (els) => els.map((e) => e.dataset.path));
  assert.ok(raw.length > 0 && raw.every((p) => p.endsWith('.CR3')), 'RAW-Filter');
  await page.click('[data-filter="video"]');
  const vid = await page.$$eval('.tile', (els) => els.map((e) => e.dataset.path));
  assert.ok(vid.length > 0 && vid.every((p) => p.endsWith('.MP4')), 'Video-Filter');
  await page.click('[data-filter="jpeg"]');
  const jpg = await page.$$eval('.tile', (els) => els.length);
  assert.equal(raw.length + vid.length + jpg, all);
  // neueste zuerst
  await page.click('[data-filter="all"]');
  const first = await page.$eval('.tile', (e) => e.dataset.path);
  assert.match(first, /101CANON/);
});

await test('Original-Download ist bytegleich (JPEG, RAW, Video)', async (page) => {
  await openGallery(page);
  const paths = await page.$$eval('.tile', (els) => els.map((e) => e.dataset.path));
  const pick = [paths.find((p) => p.endsWith('.JPG')), paths.find((p) => p.endsWith('.CR3')), paths.find((p) => p.endsWith('.MP4'))];
  await page.click('#select-toggle');
  for (const p of pick) await page.click(`.tile[data-path="${p}"]`);
  await page.click('#sel-load');
  for (const p of pick) {
    await waitDone(page, p, 60000);
    assert.equal(await pageSha(page, p), sha(await mockBytes(p)), p);
  }
  // Galerie zeigt Status "übertragen"
  assert.equal(await page.$eval(`.tile[data-path="${pick[0]}"]`, (e) => e.dataset.state), 'done');
});

await test('Duplikate werden erkannt und nicht erneut geladen', async (page) => {
  await openGallery(page);
  const p = await page.$eval('.tile[data-path$=".JPG"]', (e) => e.dataset.path);
  await page.click(`.tile[data-path="${p}"]`);
  await page.click('.v-actions .btn.primary');
  await waitDone(page, p);
  const n = await page.evaluate(() => window.__kl.queue.items.size);
  // erneut über Auswahl einreihen
  await page.click('.v-close');
  await page.click('#select-toggle');
  await page.click(`.tile[data-path="${p}"]`);
  await page.click('#sel-load');
  await page.waitForSelector('#toast.show');
  assert.match(await page.textContent('#toast'), /schon übertragen/);
  assert.equal(await page.evaluate(() => window.__kl.queue.items.size), n);
});

await test('Abbruch durch Netzfehler wird per Range fortgesetzt', async (page, ctx) => {
  await openGallery(page);
  const p = await page.$eval('.tile[data-path$=".MP4"]', (e) => e.dataset.path);
  // Kamera-WLAN reisst nach 600 KB ab
  await fetch(`${MOCK}/mock/break?path=${encodeURIComponent(p)}&after=600000`, { method: 'POST' });
  await page.click('#select-toggle');
  await page.click(`.tile[data-path="${p}"]`);
  await page.click('#sel-load');
  await waitDone(page, p, 60000);
  assert.equal(await pageSha(page, p), sha(await mockBytes(p)));
  // Nach dem Abriss wurde ab der gespeicherten Position weitergeladen (nicht von vorn)
  const it = await page.evaluate((x) => window.__kl.queue.find(x), p);
  const log = await mockLog();
  assert.ok(it.chunkCount >= 2, 'mindestens zwei Teilstücke');
  const ranged = log.filter((l) => l.includes(p) && /\[bytes=[1-9]\d*-\]/.test(l));
  assert.ok(ranged.length >= 1, 'Fortsetzung mit Range-Anfrage');
});

await test('Pausieren, Fortsetzen nach Neuladen der Seite, Abbrechen, Wiederholen', async (page) => {
  await openGallery(page);
  const p = await page.$$eval('.tile[data-path$=".MP4"]', (els) => els[1].dataset.path);
  await page.click(`.tile[data-path="${p}"]`);
  await page.click('.v-actions .btn.primary');
  await page.click('.v-close');
  await page.waitForFunction((x) => window.__kl.queue.find(x)?.live > 100000, p);
  await page.evaluate((x) => window.__kl.queue.pause(window.__kl.queue.find(x).id), p);
  await page.waitForFunction((x) => window.__kl.queue.find(x)?.status === 'paused', p);
  const saved = await page.evaluate((x) => window.__kl.queue.find(x).bytes, p);
  assert.ok(saved > 0, 'Teilstück gespeichert');
  await page.reload();
  await page.waitForFunction(() => window.__kl?.queue?.items?.size > 0);
  await page.goto(`${APP}#downloads`);
  await page.waitForSelector('.qrow.s-paused');
  await page.click('.qrow.s-paused [aria-label="Fortsetzen"]');
  await waitDone(page, p, 60000);
  assert.equal(await pageSha(page, p), sha(await mockBytes(p)));
  const log = await mockLog();
  assert.ok(log.some((l) => l.includes(p) && l.includes(`[bytes=${saved}-]`)), 'Range-Anfrage ab gespeicherter Position');

  // Abbrechen + Wiederholen
  const q = await page.evaluate(() => window.__kl.gallery.files.find((f) => f.name.endsWith('.CR3')).path);
  await page.evaluate((x) => window.__kl.gallery && window.__kl.queue.add(window.__kl.gallery.files.find((f) => f.path === x), null), q);
  await page.waitForFunction((x) => window.__kl.queue.find(x)?.status === 'running', q);
  await page.click(`.qrow.s-running [aria-label="Abbrechen"]`);
  await page.waitForFunction((x) => window.__kl.queue.find(x)?.status === 'cancelled', q);
  assert.equal(await page.evaluate((x) => window.__kl.queue.find(x).bytes, q), 0);
  await page.click('.qrow.s-cancelled [aria-label="Wiederholen"]');
  await waitDone(page, q, 60000);
  assert.equal(await pageSha(page, q), sha(await mockBytes(q)));
});

await test('Live: neue Aufnahme erkennen, JPEG gross, automatische Übertragung', async (page) => {
  await openGallery(page);
  await page.goto(`${APP}#live`);
  await page.check('#auto-toggle', { force: true });
  await page.click('[data-autokind="jpeg"]');
  await page.click('#cap-card');
  await page.waitForFunction(() => /Wartet/.test(document.querySelector('#cap-status').textContent));
  const added = (await (await fetch(`${MOCK}/mock/shoot?raw=1`, { method: 'POST' })).json()).added;
  const jpg = added.find((p) => p.endsWith('.JPG'));
  const cr3 = added.find((p) => p.endsWith('.CR3'));
  await page.waitForSelector('#cap-hero .hero', { timeout: 10000 });
  assert.match(await page.textContent('#cap-hero .hero-name'), /\.JPG/);
  await waitDone(page, jpg, 30000);
  assert.equal(await page.evaluate((x) => window.__kl.queue.find(x), cr3), null, 'RAW nicht automatisch (Einstellung JPEG)');
  assert.equal(await pageSha(page, jpg), sha(await mockBytes(jpg)));
  await page.waitForFunction(() => /Original übertragen/.test(document.querySelector('#cap-hero').textContent));
  // auch in der Galerie oben
  assert.equal(await page.evaluate(() => window.__kl.gallery.files[0].path), jpg);
  await page.click('#cap-card');
});

await test('Live: Aufnahme wird auch ohne Kamera-Ereignis gefunden (Ordnerabgleich)', async (page) => {
  await openGallery(page);
  await page.goto(`${APP}#live`);
  await page.click('#cap-card');
  await page.waitForFunction(() => /Wartet/.test(document.querySelector('#cap-status').textContent));
  await fetch(`${MOCK}/mock/events?on=0`, { method: 'POST' });
  const added = (await (await fetch(`${MOCK}/mock/shoot`, { method: 'POST' })).json()).added;
  try {
    await page.waitForFunction((n) => document.querySelector('#cap-hero .hero-name')?.textContent.includes(n), added[0].split('/').pop(), { timeout: 20000 });
  } finally {
    await fetch(`${MOCK}/mock/events?on=1`, { method: 'POST' });
  }
  await page.click('#cap-card');
});

await test('Livebild ein/aus, getrennt von der Fotoübertragung', async (page) => {
  await openGallery(page);
  await page.goto(`${APP}#live`);
  await page.click('#lv-card');
  await page.waitForFunction(() => document.querySelector('#lv-img').src.startsWith('blob:'), null, { timeout: 10000 });
  await page.waitForFunction(() => /Bilder\/s/.test(document.querySelector('#lv-fps').textContent), null, { timeout: 10000 });
  assert.equal(await page.isVisible('.lv-badge'), true);
  await page.click('#lv-card');
  await page.waitForTimeout(500);
  const log = await mockLog();
  assert.ok(log.filter((l) => l.startsWith('POST') && l.includes('shooting/liveview')).length >= 2, 'ein- und ausgeschaltet');
  const st = await (await fetch(`${MOCK}/ccapi/ver100/shooting/liveview/flip`)).status;
  assert.equal(st, 503, 'Livebild an der Kamera wieder aus');
});

await test('ZIP der Auswahl enthält unveränderte Originale', async (page) => {
  await openGallery(page);
  const paths = (await page.$$eval('.tile', (els) => els.map((e) => e.dataset.path))).slice(0, 3);
  await page.click('#select-toggle');
  for (const p of paths) await page.click(`.tile[data-path="${p}"]`);
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('#sel-zip')]);
  const file = join(tmp, 'auswahl.zip');
  await dl.saveAs(file);
  const out = join(tmp, 'zip-out');
  mkdirSync(out, { recursive: true });
  execFileSync('python3', ['-c', `import zipfile,sys; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])`, file, out]);
  for (const p of paths) {
    const rel = p.split('/').slice(-2).join('/');
    assert.equal(sha(readFileSync(join(out, rel))), sha(await mockBytes(p)), rel);
  }
});

await test('Vollbild: Doppeltipp-Zoom, Wischen blättert, Metadaten', async (page) => {
  await openGallery(page);
  await page.click('.tile[data-path$=".JPG"] >> nth=0');
  await page.waitForSelector('.v-img.ok');
  await page.waitForFunction(() => /KB|MB/.test(document.querySelector('.v-meta').textContent));
  assert.match(await page.textContent('.v-meta'), /\d{2}\.\d{2}\.\d{4}/, 'Datum');
  const before = Number((await page.textContent('.v-count')).split('/')[0]);
  const box = await page.$('.v-stage').then((e) => e.boundingBox());
  const cx = box.x + box.width / 2, cy = box.y + box.height / 2;
  await page.mouse.click(cx, cy);
  await page.mouse.click(cx, cy);
  await page.waitForTimeout(300);
  assert.match(await page.$eval('.v-zoom', (e) => e.style.transform), /scale\(2\.5\)/);
  await page.mouse.click(cx, cy); await page.mouse.click(cx, cy);
  await page.waitForTimeout(300);
  await page.mouse.move(cx + 100, cy);
  await page.mouse.down();
  await page.mouse.move(cx - 80, cy, { steps: 8 });
  await page.mouse.up();
  await page.waitForTimeout(300);
  assert.equal(Number((await page.textContent('.v-count')).split('/')[0]), before + 1, 'eins weiter');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);
  assert.equal(await page.isHidden('#viewer'), true);
});

await test('Verbindung: echter Status, falsche Adresse wird angezeigt', async (page) => {
  await page.goto(`${APP}#verbindung`);
  await page.waitForSelector('#conn-steps li.ok >> nth=2');
  assert.match(await page.textContent('#pill'), /TEST-DOUBLE/);
  await page.fill('#cam-addr', '127.0.0.1:1');
  await page.click('#cam-form button[type=submit]');
  await page.waitForFunction(() => /nicht erreichbar/.test(document.querySelector('#pill').textContent), null, { timeout: 15000 });
  await page.fill('#cam-addr', `127.0.0.1:${mockPort}`);
  await page.click('#cam-form button[type=submit]');
  await page.waitForFunction(() => /TEST-DOUBLE/.test(document.querySelector('#pill').textContent), null, { timeout: 15000 });
});

await test('Bewegung reduzieren: App läuft ohne Animationen', async (page) => {
  await openGallery(page);
  const dur = await page.$eval('.view:not([hidden])', (e) => getComputedStyle(e).animationDuration);
  assert.ok(parseFloat(dur) < 0.01, dur);
}, { reducedMotion: 'reduce' });

// ---- Web-Version im Unterordner (PHP) --------------------------------------
const docroot = join(tmp, 'www');
mkdirSync(join(docroot, 'public_html/irgendein-name'), { recursive: true });
cpSync(join(ROOT, 'dist/kamera'), join(docroot, 'public_html/irgendein-name'), { recursive: true });
const phpPort = await freePort();
start('php', ['-S', `127.0.0.1:${phpPort}`, '-t', join(docroot, 'public_html')]);
await waitPort(phpPort);
const HOSTED = `http://127.0.0.1:${phpPort}/irgendein-name/`;

await test('Web-Version im beliebigen Unterordner: Seite, Assets, Downloads, Offline', async (page) => {
  const res = await page.goto(HOSTED);
  assert.equal(res.status(), 200);
  assert.equal(await page.getAttribute('html', 'data-mode'), 'hosted');
  await page.waitForSelector('#view-start:not([hidden])');
  assert.equal(await page.isHidden('.tabbar'), true);
  for (const f of ['py', 'mac', 'win', 'readme', 'ios']) {
    const r = await fetch(`${HOSTED}download.php?f=${f}`);
    assert.equal(r.status, 200, f);
  }
  const py = await (await fetch(`${HOSTED}download.php?f=py`)).text();
  assert.match(py, /EMBEDDED_WEB: dict\[str, str\] = \{/);
  assert.equal((await fetch(`${HOSTED}download.php?f=../index.php`)).status, 404);
  assert.equal((await fetch(`${HOSTED}manifest.webmanifest`)).status, 200);
  // Service Worker hält die Seite offline verfügbar
  await page.evaluate(() => navigator.serviceWorker.ready);
  await page.reload();
  await page.waitForFunction(() => navigator.serviceWorker.controller);
  await page.context().setOffline(true);
  await page.reload();
  assert.equal(await page.getAttribute('html', 'data-mode'), 'hosted');
  await page.context().setOffline(false);
  // Brücken-Adresse führt zur Brücke
  await page.fill('#bridge-addr', `127.0.0.1:${bridgePort}`);
  await Promise.all([page.waitForURL(APP), page.click('#bridge-form button')]);
  assert.equal(await page.getAttribute('html', 'data-mode'), 'bridge');
});

// ---- Abschluss ----------------------------------------------------------------
await test('Kein einziger Lösch-/Schreibzugriff auf die Kamera', async () => {
  const log = await mockLog();
  const bad = log.filter((l) => l.startsWith('DELETE') || l.startsWith('PUT') || (l.startsWith('POST') && !/shooting\/liveview|^POST \/mock\//.test(l)));
  assert.deepEqual(bad, []);
});

await browser.close();
for (const p of procs) p.kill();
let failed = 0;
for (const [st, name, ms, err] of results) {
  console.log(`${st} ${name} (${ms} ms)`);
  if (err) { failed++; console.log(`     ${err.replace(/\n/g, '\n     ')}`); }
}
console.log(`\n${results.length - failed}/${results.length} bestanden${failed ? ` – Screenshots in ${tmp}` : ''}`);
if (!failed) rmSync(tmp, { recursive: true, force: true });
process.exit(failed ? 1 : 0);
