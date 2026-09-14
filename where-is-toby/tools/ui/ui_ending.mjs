/**
 * Spielt den Fall per API im Browserkontext durch und macht Screenshots
 * von Beweisdetail, Video, Audio, Bericht und Abschlussbewertung.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import fs from 'node:fs';

const BASE = process.argv[2] || 'http://127.0.0.1:8787';
const OUT = process.argv[3] || '/tmp/wit-ending';
fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });
const page = await browser.newPage({ viewport: { width: 1600, height: 950 }, locale: 'de-DE' });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

await page.goto(BASE + '/registrieren', { waitUntil: 'networkidle' });
const user = 'ende' + Math.floor(Math.random() * 100000);
await page.fill('input[name="username"]', user);
await page.fill('input[name="password"]', 'Ermittlung#2024');
await page.fill('input[name="password_repeat"]', 'Ermittlung#2024');
await page.check('input[name="age_confirm"]');
await page.click('button[type="submit"]');
await page.waitForURL('**/faelle');
await page.goto(BASE + '/spielen/toby', { waitUntil: 'networkidle' });

const gate = await page.$('#age-accept');
if (gate) await gate.click();
const intro = await page.$('#intro-start');
if (intro) { await page.waitForSelector('#intro:not([hidden])'); await page.click('#intro-start'); }
await page.waitForSelector('.dossier');

/* Fall per API loesen (im Browserkontext, damit Sitzung und Token stimmen) */
const solved = await page.evaluate(async () => {
    const csrf = JSON.parse(document.getElementById('wit-bootstrap').textContent).csrf;
    const call = async (path, body) => {
        const response = await fetch(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
            body: JSON.stringify({ ...body, _csrf: csrf }),
            credentials: 'same-origin',
        });
        return response.json();
    };
    const base = window.location.pathname.split('/spielen/')[0];
    const api = base + '/api/case/toby';
    for (const id of ['E02', 'E03', 'E04', 'E25']) await call(api + '/evidence', { evidence: id });
    const puzzles = [
        ['pz_phone_pin', '0409'], ['pz_laptop_pw', 'nachtlinie2003'], ['pz_oldphone_pattern', '14789'],
        ['pz_recover_chat', ['f4473', 'f4474', 'f4475', 'f4476', 'f4477', 'f4478']],
        ['pz_audio_background', ['opt_pumpe', 'opt_zug']], ['pz_audio_reverse', 'P4'],
        ['pz_cam_ridge', 497], ['pz_plate', '7KD418'], ['pz_cam_gas', 288], ['pz_cam_garage', 3347],
    ];
    for (const [id, answer] of puzzles) await call(api + '/puzzle', { puzzle: id, answer });
    for (const id of ['E07', 'E08']) await call(api + '/evidence', { evidence: id });
    const chats = [['npc_nora', 'E06'], ['npc_diane', 'E11'], ['npc_frank', 'E12'], ['npc_hale', 'E16']];
    for (const [npc, evidence] of chats) await call(api + '/chat', { npc, message: 'Ich lege Ihnen das vor.', evidence });
    for (const id of ['E20', 'E24']) await call(api + '/evidence', { evidence: id });
    const rest = [
        ['pz_ww_login', 'Halloway98'], ['pz_find_flush', '4'], ['pz_keycard', 'WW-0114'],
        ['pz_cam_pump', 453], ['pz_doss_folder', '1998'], ['pz_style_message', 'opt_stil'],
        ['pz_bus_contradiction', ['opt_card', 'opt_cell']],
        ['pz_locate_final', { x: 0.78, y: 0.35 }],
        ['pz_timeline', ['tl_streit', 'tl_rad', 'tl_turm', 'tl_tanken', 'tl_zaun', 'tl_van', 'tl_frank', 'tl_audio', 'tl_last', 'tl_msg', 'tl_card']],
    ];
    for (const [id, answer] of rest) await call(api + '/puzzle', { puzzle: id, answer });
    const state = await (await fetch(api + '/state', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })).json();
    return state.state.progress.solved.length;
});
console.log('geloeste Raetsel:', solved);

await page.reload({ waitUntil: 'networkidle' });
await page.waitForSelector('.dossier, .panel-grid');

/* Beweisdetail */
await page.click('.rail__item[data-panel="beweise"]');
await page.waitForSelector('.evidence-card');
await page.click('.evidence-card >> nth=3');
await page.waitForSelector('#overlay:not([hidden])');
await page.screenshot({ path: `${OUT}/30-beweis.png` });
await page.click('#overlay-close');

/* Kameraauswertung */
await page.click('.rail__item[data-panel="geraete"]');
await page.waitForSelector('.device-tile');
await page.click('.device-tile:has-text("Auswertungsplatz")');
await page.waitForSelector('.app-icon');
await page.click('.app-icon:has-text("Kameras")');
await page.waitForSelector('.evidence-card');
await page.click('.evidence-card >> nth=0');
await page.waitForSelector('.cctv__screen');
await page.waitForTimeout(600);
await page.screenshot({ path: `${OUT}/31-kamera.png` });
await page.click('#overlay-close');

/* Zeitleiste */
await page.click('.rail__item[data-panel="zeit"]');
await page.waitForSelector('.timeline');
await page.screenshot({ path: `${OUT}/32-zeitleiste.png` });

/* Bericht ausfuellen und einreichen */
await page.click('.rail__item[data-panel="bericht"]');
await page.waitForSelector('.report-question');
await page.screenshot({ path: `${OUT}/33-bericht.png` });

await page.fill('.report-question textarea >> nth=0', 'Walter Doss hat Toby als "Lantern" ans Nordtor gelockt, mit dem Werkstransporter zur Pumpstation 4 gebracht und haelt ihn im Wartungsschacht fest. Die Spuelung in Abschnitt 4 war die Deckung, die Nachricht um 03:14 Uhr und die Busbuchung waren ein Koeder.');
await page.click('.option:has-text("Walter Doss")');
for (const name of ['Diane Brennan', 'Frank Brennan', 'Nora Vance', 'Gregory Hale']) {
    await page.click(`.report-question:has-text("Wer hat gelogen") .option:has-text("${name}")`);
}
await page.click('.option:has-text("Wartungsschacht unter Pumpstation 4")');
await page.click('.option:has-text("Toby stand kurz davor")');
for (const code of ['E-08', 'E-09', 'E-13', 'E-15', 'E-19', 'E-22']) {
    const option = page.locator(`.report-question:has-text("Welche Beweise") .option:has-text("${code}")`).first();
    if (await option.count()) await option.click();
}
await page.screenshot({ path: `${OUT}/34-bericht-ausgefuellt.png`, fullPage: true });

await page.click('button:has-text("Bericht einreichen")');
await page.waitForSelector('#overlay:not([hidden])');
await page.click('#overlay-body button:has-text("Einreichen")');
await page.waitForSelector('.verdict__rank', { timeout: 20000 });
await page.waitForTimeout(800);
await page.screenshot({ path: `${OUT}/35-bewertung.png`, fullPage: true });

const rank = await page.textContent('.verdict__rank');
const ending = await page.textContent('.verdict h2');
console.log('Rang:', rank.trim(), '| Ende:', ending.trim());
console.log('JS-Fehler:', errors.length ? errors.slice(0, 5).join(' | ') : 'keine');

await browser.close();
