// YouGBT – Browser-Test mit zwei Geräten (Desktop-Host + iPhone-Gast) gegen den lokalen Server.
import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const { chromium, devices } = require(process.env.PW_PATH || 'playwright');
const BASE = process.env.BASE;
const OUT = process.env.OUT || '.';
let fails = 0;
const ok = (c, l) => { console.log((c ? '  ✔ ' : '  ✖ ') + l); if (!c) fails++; };

const browser = await chromium.launch();
const ctxA = await browser.newContext({ viewport: { width: 1280, height: 860 } });
const ctxB = await browser.newContext({ ...devices['iPhone 13'] });
const A = await ctxA.newPage();
const B = await ctxB.newPage();
const errors = [];
for (const [n, p] of [['A', A], ['B', B]]) {
  p.on('pageerror', (e) => errors.push(n + ': ' + e.message));
  p.on('console', (m) => { if (m.type() === 'error') errors.push(n + ' console: ' + m.text()); });
}

await A.goto(BASE);
await A.waitForSelector('.home');
await A.screenshot({ path: OUT + '/01-home-desktop.png' });
await B.goto(BASE);
await B.waitForSelector('.home');
await B.screenshot({ path: OUT + '/02-home-mobile.png', fullPage: true });

// Host erstellt Raum (Roulette-Modus)
await A.click('text=Raum erstellen');
await A.fill('#name', 'Tobi');
await A.click('.chip:has-text("Roulette")');
await A.click('button[type=submit]');
await A.waitForSelector('.room-code');
const code = (await A.textContent('.room-code')).trim();
ok(/^[A-Z2-9]{5}$/.test(code), 'Lobby mit Raumcode ' + code);
ok((await A.url()).includes('?r=' + code), 'Einladungs-URL gesetzt');

// Gast tritt per Einladungslink bei
await B.goto(BASE + '?r=' + code);
await B.waitForSelector('#jname');
await B.fill('#jname', 'Bea');
await B.click('button[type=submit]');
await B.waitForSelector('.lobby');
await A.waitForFunction(() => document.querySelectorAll('#players .player:not(.empty)').length === 2);
ok(true, 'Beide Spieler in der Lobby sichtbar');

// Chat (XSS-Versuch wird als Text angezeigt)
await A.fill('#chat-in', '<img src=x onerror=alert(1)> hallo 🔥');
await A.press('#chat-in', 'Enter');
await B.click('#fab');
await B.waitForSelector('.chat-msg:has-text("hallo")', { timeout: 8000 });
ok((await B.locator('.chat-msg img').count()) === 0, 'Chat-HTML wird nicht ausgeführt, nur als Text gezeigt');
await B.screenshot({ path: OUT + '/03-lobby-mobile-chat.png' });
await B.click('.side-close');
await A.screenshot({ path: OUT + '/04-lobby-desktop.png' });

// Start → Roulette → Frage
await A.click('text=Partie starten');
await A.waitForSelector('.wheel', { timeout: 8000 });
await B.waitForSelector('.wheel', { timeout: 8000 });
await A.waitForTimeout(1500);
await A.screenshot({ path: OUT + '/05-roulette.png' });
await A.waitForSelector('.bubble.main-q', { timeout: 20000 });
await B.waitForSelector('.bubble.main-q', { timeout: 20000 });
await A.waitForTimeout(1500);
const qa = await A.textContent('.q-text');
const qb = await B.textContent('.q-text');
ok(qa.includes('Tobi AI') && qb.includes('Bea AI'), '{AI}-Anrede je Spieler personalisiert: "' + qa.slice(0, 30) + '…"');
ok(await A.isVisible('#timer') && await B.isVisible('#timer'), 'Countdown auf beiden Geräten sichtbar');
const ta = parseInt(await A.textContent('#timer-num'), 10), tb = parseInt(await B.textContent('#timer-num'), 10);
ok(Math.abs(ta - tb) <= 1, 'Timer synchron (' + ta + ' / ' + tb + ')');
ok(await A.isVisible('.joker-btn'), 'Risiko-Joker sichtbar');
await A.screenshot({ path: OUT + '/06-question-desktop.png' });

// Gast: Entwurf tippen, neu laden → Zustand wiederhergestellt
await B.fill('#answer', 'Wegen Rayleigh-Streuung');
await B.reload();
await B.waitForSelector('.bubble.main-q', { timeout: 10000 });
await B.waitForTimeout(1600);
ok((await B.textContent('.q-text')) === qb && (await B.inputValue('#answer')) === 'Wegen Rayleigh-Streuung', 'Nach Neuladen: gleiche Frage, Entwurf wiederhergestellt');

// Gast: Hinweis holen
await B.click('.composer-top button:has-text("💡")');
await B.click('.modal .btn-primary');
await B.waitForSelector('.hint-shown', { timeout: 8000 });
ok((await B.textContent('.hint-shown')).includes('Streuung'), 'Hinweis-Stichwort angezeigt inkl. Abzug');
await B.fill('#answer', 'HIGH Der Himmel ist blau wegen Rayleigh-Streuung am Sonnenlicht.');
ok((await B.textContent('.counter')).startsWith('6'), 'Zeichenzähler aktualisiert');
await B.screenshot({ path: OUT + '/07-answer-mobile.png' });
await B.click('#send-btn');
await B.waitForSelector('.wait-box', { timeout: 8000 });
ok(await B.isVisible('.bubble.out'), 'Eigene Antwort als Chatblase fixiert');

// Host: Joker aktivieren und PERFECT antworten
await A.click('.joker-btn');
ok((await A.textContent('.modal')).includes('75'), 'Joker-Regel wird vor Aktivierung gezeigt');
await A.click('.modal .btn-primary');
await A.fill('#answer', 'PERFECT Antwort mit allen Details.');
await A.click('#send-btn');
await A.waitForSelector('.results', { timeout: 20000 });
await B.waitForSelector('.results', { timeout: 20000 });
ok(await A.isVisible('.perfect-overlay'), 'Perfekte-100-Feier erscheint');
await A.waitForTimeout(600);
await A.screenshot({ path: OUT + '/08-perfect.png' });
await A.waitForTimeout(3000);
await A.screenshot({ path: OUT + '/09-reveal-desktop.png', fullPage: true });
await B.screenshot({ path: OUT + '/10-reveal-mobile.png', fullPage: true });
ok((await B.textContent('.results')).includes('Streuung'), 'Hinweis nach Auflösung für alle sichtbar');
ok((await A.textContent('.results')).includes('+200'), 'Joker verdoppelt 100 → 200');

// Light Mode + Englisch
await A.click('#btn-lang');
await A.waitForTimeout(400);
ok((await A.getAttribute('html', 'data-theme')) === 'dark' && (await A.locator('#btn-theme').count()) === 0, 'Nur Dark Mode');
ok((await A.locator('.model').count()) === 0, 'Keine Musterantwort in der Auflösung');
ok((await A.textContent('.reveal-actions')).includes('Next'), 'Oberfläche auf Englisch umgeschaltet');
await A.screenshot({ path: OUT + '/11-reveal-theme-en.png' });

// Beide bereit → Runde 2
await B.click('.reveal-actions .btn-primary');
await A.click('.reveal-actions .btn-primary');
await A.waitForSelector('.wheel, .bubble.main-q, .typing-bubble', { timeout: 15000 });
ok(true, 'Beide bereit → nächste Runde beginnt');

// Gast verlässt → Host gewinnt als letzter Spieler
await B.click('.hud-right button[aria-label="Partie verlassen"]');
await B.click('.modal .btn-primary');
await A.waitForSelector('.final-title', { timeout: 15000 });
ok((await A.textContent('.final-title')).length > 3, 'Letzter Spieler: Siegeranzeige');
await A.waitForTimeout(1500);
await A.screenshot({ path: OUT + '/12-final.png', fullPage: true });

// Reduzierte Bewegung respektiert
const ctxC = await browser.newContext({ reducedMotion: 'reduce', viewport: { width: 820, height: 1180 } });
const C = await ctxC.newPage();
await C.goto(BASE);
await C.waitForSelector('.home');
ok(await C.evaluate(() => document.documentElement.classList.contains('reduce-motion')), 'prefers-reduced-motion wird respektiert');
await C.screenshot({ path: OUT + '/13-ipad-home.png' });

ok(errors.length === 0, 'Keine JS-Fehler in der Konsole' + (errors.length ? ': ' + errors.join(' | ') : ''));
await browser.close();
console.log(fails ? `\n${fails} UI-Checks fehlgeschlagen` : '\nAlle UI-Checks bestanden');
process.exit(fails ? 1 : 0);
