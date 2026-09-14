/**
 * Oberflaechentest mit Chromium (Playwright).
 * Prueft, ob die Spieloberflaeche ohne JavaScript-Fehler laeuft,
 * alle Bereiche rendern und macht Screenshots.
 *
 * Aufruf: node tools/ui/ui_test.mjs http://127.0.0.1:8787 /pfad/fuer/screenshots
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import fs from 'node:fs';

const BASE = process.argv[2] || 'http://127.0.0.1:8787';
const OUT = process.argv[3] || '/tmp/wit-shots';
fs.mkdirSync(OUT, { recursive: true });

const results = [];
const errors = [];
const check = (label, ok, detail = '') => {
    results.push({ label, ok, detail });
    console.log(`${ok ? '  [OK]  ' : '  [FEHL]'} ${label}${!ok && detail ? ' -> ' + detail : ''}`);
};

const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

const context = await browser.newContext({
    viewport: { width: 1600, height: 950 },
    locale: 'de-DE',
    reducedMotion: 'no-preference',
});
const page = await context.newPage();

page.on('console', (message) => {
    if (message.type() === 'error') {
        const text = message.text();
        if (!/favicon|Failed to load resource: net::ERR/i.test(text)) errors.push('console: ' + text);
    }
});
page.on('pageerror', (error) => errors.push('pageerror: ' + error.message));
page.on('requestfailed', (request) => {
    const url = request.url();
    if (!/favicon/.test(url)) errors.push('request failed: ' + url + ' ' + (request.failure()?.errorText || ''));
});

const step = async (name, fn) => {
    try {
        await fn();
    } catch (error) {
        check(name, false, error.message.split('\n')[0]);
        await page.screenshot({ path: `${OUT}/fehler-${name.replace(/\W+/g, '_')}.png` }).catch(() => {});
        return false;
    }
    return true;
};

/* ---------------- Start und Registrierung ---------------- */
await step('Startseite laedt', async () => {
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.waitForSelector('.hero h1');
    check('Startseite zeigt Titel', (await page.textContent('.hero h1')).includes('TOBY'));
    await page.screenshot({ path: `${OUT}/01-start.png`, fullPage: true });
});

await step('Gastzugang', async () => {
    await page.click('button:has-text("Als Gast starten")');
    await page.waitForURL('**/faelle');
    await page.waitForSelector('.case-card');
    check('Fallliste zeigt eine Akte', (await page.locator('.case-card').count()) >= 1);
    await page.screenshot({ path: `${OUT}/02-faelle.png`, fullPage: true });
});

/* ---------------- Spieloberflaeche ---------------- */
await step('Spiel oeffnen', async () => {
    await page.click('.case-card');
    await page.waitForURL('**/spielen/toby');
    await page.waitForSelector('#age-gate', { timeout: 8000 });
    await page.screenshot({ path: `${OUT}/03-altersfreigabe.png` });
    await page.click('#age-accept');
    await page.waitForSelector('#intro', { state: 'visible', timeout: 8000 });
    await page.screenshot({ path: `${OUT}/04-intro.png` });
    await page.click('#intro-start');
    await page.waitForSelector('.dossier', { timeout: 10000 });
    check('Fallakte gerendert', await page.isVisible('.dossier'));
    await page.screenshot({ path: `${OUT}/05-fallakte.png`, fullPage: false });
});

const panels = [
    ['personen', '.person-card', '06-personen'],
    ['beweise', '.evidence-card, .filters', '07-beweise'],
    ['geraete', '.device-tile', '08-geraete'],
    ['wand', '.board-wrap', '09-wand'],
    ['karte', '.map-wrap', '10-karte'],
    ['zeit', '.panel-grid', '11-zeit'],
    ['notizen', 'textarea', '12-notizen'],
    ['bericht', '.report-form, .report-question', '13-bericht'],
];

for (const [panel, selector, shot] of panels) {
    await step('Bereich ' + panel, async () => {
        await page.click(`.rail__item[data-panel="${panel}"]`);
        await page.waitForSelector(selector, { timeout: 10000 });
        check('Bereich ' + panel + ' rendert', true);
        await page.screenshot({ path: `${OUT}/${shot}.png` });
    });
}

/* ---------------- Geraet entsperren (PIN-Pad) ---------------- */
await step('Handy per PIN entsperren', async () => {
    await page.click('.rail__item[data-panel="geraete"]');
    await page.waitForSelector('.device-tile');
    await page.click('.device-tile:has-text("Tobys Smartphone")');
    await page.waitForSelector('.pinpad');
    await page.screenshot({ path: `${OUT}/14-sperrbildschirm.png` });
    for (const digit of ['0', '4', '0', '9']) {
        await page.click(`.pinpad button:text-is("${digit}")`);
        await page.waitForTimeout(120);
    }
    await page.waitForSelector('.app-grid', { timeout: 10000 });
    check('Telefon entsperrt und Apps sichtbar', (await page.locator('.app-icon').count()) >= 5);
    await page.screenshot({ path: `${OUT}/15-telefon.png` });
});

await step('Nachrichten-App oeffnen', async () => {
    await page.click('.app-icon:has-text("Nachrichten")');
    await page.waitForSelector('.mailrow, .threadlist', { timeout: 8000 });
    await page.click('.mailrow >> nth=0');
    await page.waitForSelector('.thread-bubble');
    check('Chatverlauf im Handy sichtbar', (await page.locator('.thread-bubble').count()) > 3);
    await page.screenshot({ path: `${OUT}/16-handy-chat.png` });
});

/* ---------------- Verhoer ---------------- */
await step('Verhoer fuehren', async () => {
    await page.click('.rail__item[data-panel="personen"]');
    await page.waitForSelector('.person-card');
    await page.click('.person-card:has-text("Nora")');
    await page.waitForSelector('.chat__log .bubble', { timeout: 10000 });
    await page.fill('.chat__input textarea', 'Wo warst du am Freitagabend?');
    await page.click('.chat__input button:has-text("Senden")');
    await page.waitForFunction(() => document.querySelectorAll('.chat__log .bubble').length >= 3, null, { timeout: 15000 });
    const bubbles = await page.locator('.chat__log .bubble').count();
    check('NPC antwortet im Chat', bubbles >= 3, 'Blasen: ' + bubbles);
    await page.screenshot({ path: `${OUT}/17-verhoer.png` });
});

/* ---------------- Hinweis (Gluehbirne) ---------------- */
await step('Hinweis anfordern', async () => {
    await page.click('#btn-hint');
    await page.waitForSelector('#overlay:not([hidden]) .overlay__body', { timeout: 8000 });
    const text = await page.textContent('#overlay-body');
    check('Hinweis wird angezeigt', text.length > 20, text.slice(0, 60));
    await page.screenshot({ path: `${OUT}/18-hinweis.png` });
    await page.click('#overlay-close');
});

/* ---------------- Ermittlungswand ---------------- */
await step('Karte auf die Wand legen', async () => {
    await page.click('.rail__item[data-panel="wand"]');
    await page.waitForSelector('.board-toolbar');
    await page.click('.board-toolbar button:has-text("Freie Notiz")');
    await page.waitForSelector('.board-node', { timeout: 8000 });
    check('Karte auf der Wand sichtbar', (await page.locator('.board-node').count()) >= 1);
    const node = page.locator('.board-node').first();
    const box = await node.boundingBox();
    await page.mouse.move(box.x + 40, box.y + 20);
    await page.mouse.down();
    await page.mouse.move(box.x + 240, box.y + 160, { steps: 12 });
    await page.mouse.up();
    await page.waitForTimeout(1200);
    const moved = await node.boundingBox();
    check('Karte laesst sich ziehen', Math.abs(moved.x - box.x) > 50, `von ${Math.round(box.x)} nach ${Math.round(moved.x)}`);
    await page.screenshot({ path: `${OUT}/19-wand.png` });
});

/* ---------------- Einstellungen ---------------- */
await step('Einstellungen oeffnen', async () => {
    await page.click('#btn-settings');
    await page.waitForSelector('#overlay:not([hidden])');
    check('Einstellungen enthalten Lautstaerke', (await page.textContent('#overlay-body')).includes('Lautstaerke'));
    await page.screenshot({ path: `${OUT}/20-einstellungen.png` });
    await page.click('#overlay-close');
});

/* ---------------- Mobile Ansicht ---------------- */
await step('Mobile Darstellung', async () => {
    const mobile = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, locale: 'de-DE' });
    const mobilePage = await mobile.newPage();
    mobilePage.on('pageerror', (error) => errors.push('mobile pageerror: ' + error.message));
    await mobilePage.goto(BASE + '/', { waitUntil: 'networkidle' });
    await mobilePage.click('button:has-text("Als Gast starten")');
    await mobilePage.waitForURL('**/faelle');
    await mobilePage.screenshot({ path: `${OUT}/21-mobil-faelle.png`, fullPage: true });
    await mobilePage.goto(BASE + '/spielen/toby', { waitUntil: 'networkidle' });
    const gate = await mobilePage.$('#age-accept');
    if (gate) await gate.click();
    const intro = await mobilePage.$('#intro-start');
    if (intro) await intro.click();
    await mobilePage.waitForSelector('.rail', { timeout: 10000 });
    const overflow = await mobilePage.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    check('Kein horizontaler Ueberlauf auf dem Smartphone', overflow <= 2, 'Ueberlauf: ' + overflow + 'px');
    await mobilePage.screenshot({ path: `${OUT}/22-mobil-spiel.png` });
    await mobile.close();
});

/* ---------------- Adminbereich ---------------- */
await step('Adminbereich pruefen', async () => {
    const adminContext = await browser.newContext({ viewport: { width: 1600, height: 950 }, locale: 'de-DE' });
    const adminPage = await adminContext.newPage();
    adminPage.on('pageerror', (error) => errors.push('admin pageerror: ' + error.message));
    adminPage.on('console', (message) => { if (message.type() === 'error') errors.push('admin console: ' + message.text()); });

    await adminPage.goto(BASE + '/login', { waitUntil: 'networkidle' });
    await adminPage.fill('input[name="username"]', 'tobi');
    await adminPage.fill('input[name="password"]', process.env.WIT_ADMIN_PASSWORD || 'Ermittlung#2024x');
    await adminPage.click('button[type="submit"]');
    await adminPage.waitForURL(/admin|konto/, { timeout: 10000 });
    await adminPage.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    check('Admin-Dashboard laedt', await adminPage.isVisible('.admin-shell'));
    await adminPage.screenshot({ path: `${OUT}/23-admin.png`, fullPage: true });

    await adminPage.goto(BASE + '/admin/fall/toby', { waitUntil: 'networkidle' });
    await adminPage.waitForSelector('.editor-nav button', { timeout: 10000 });
    const sections = await adminPage.locator('.editor-nav button').count();
    check('Fall-Editor zeigt alle Abschnitte', sections >= 15, 'Abschnitte: ' + sections);
    await adminPage.click('.editor-nav button:has-text("Personen")');
    await adminPage.waitForSelector('.repeat-item', { timeout: 8000 });
    await adminPage.click('.repeat-item__head >> nth=0');
    await adminPage.waitForSelector('.repeat-item .field-grid', { timeout: 8000 });
    check('NPC-Formular oeffnet sich', await adminPage.isVisible('.repeat-item .field-grid'));
    await adminPage.screenshot({ path: `${OUT}/24-editor.png`, fullPage: false });

    await adminPage.goto(BASE + '/admin/diagnose', { waitUntil: 'networkidle' });
    await adminPage.click('[data-action="diag-run"]');
    await adminPage.waitForTimeout(2500);
    check('Diagnose im Adminbereich laeuft', (await adminPage.locator('.check-row').count()) > 10);
    await adminPage.screenshot({ path: `${OUT}/25-diagnose.png`, fullPage: true });
    await adminContext.close();
});

check('Keine JavaScript-Fehler', errors.length === 0, errors.slice(0, 6).join(' | '));

await browser.close();

const failed = results.filter((entry) => !entry.ok);
console.log(`\n${results.length - failed.length} Pruefungen bestanden, ${failed.length} fehlgeschlagen`);
if (failed.length) {
    console.log('\nFehlgeschlagen:');
    failed.forEach((entry) => console.log(' - ' + entry.label + (entry.detail ? ': ' + entry.detail : '')));
}
console.log('Screenshots: ' + OUT);
process.exit(failed.length ? 1 : 0);
