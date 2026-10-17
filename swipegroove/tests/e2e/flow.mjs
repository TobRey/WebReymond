import { browser, BASE, check, SHOTS } from './pw.mjs';
import fs from 'node:fs';
fs.mkdirSync(SHOTS, { recursive: true });
const b = await browser();
const mobile = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'de-CH' };
const ctx = await b.newContext(mobile);
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));
page.on('console', (m) => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) errors.push(m.text()); });
const failed = [];
page.on('response', (r) => { if (r.status() >= 400 && !r.url().includes('scdn.co') && !r.url().includes('/install')) failed.push(r.status() + ' ' + r.url()); });
const shot = (n) => page.screenshot({ path: `${SHOTS}/${n}.png`, fullPage: false });
const api = async (path, opts = {}) => {
  return page.evaluate(async ({ path, opts }) => {
    const r = await fetch(window.__SG__.basePath + path, { method: opts.method || (opts.body ? 'POST' : 'GET'), headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.__SG__.csrf || '', Accept: 'application/json' }, body: opts.body ? JSON.stringify(opts.body) : undefined });
    return { status: r.status, json: await r.json().catch(() => null) };
  }, { path, opts });
};

// ---- 1. Setup & Besitzer-Registrierung
await page.goto(BASE + '/');
await page.waitForURL(/\/login/);
check(await page.isVisible('text=Die Plattform ist noch nicht eingerichtet'), 'Login zeigt Setup-Hinweis');
await shot('10-login-setup-pending');
await page.goto(BASE + '/setup');
await page.fill('input[type=password]', 'falscher-schluessel');
await page.click('button[type=submit]');
await page.waitForSelector('text=Der Einrichtungsschlüssel ist ungültig');
check(true, 'Falscher Setup-Schlüssel abgelehnt');
await page.fill('input[type=password]', 'local-setup-key-123456');
await page.click('button[type=submit]');
await page.waitForURL(/8090\/authorize/);
check(page.url().includes('code_challenge='), 'PKCE-Challenge in Autorisierungs-URL');
check(page.url().includes('scope=playlist-read-private'), 'Nur Playlist-Scopes angefordert');
await page.click('#login-spotify_owner');
await page.waitForURL(/\/groove\/welcome/);
await shot('11-welcome');
await page.fill('input[maxlength="32"]', 'tobi');
await page.fill('input[maxlength="60"]', 'Tobi');
await page.click('button[type=submit]');
await page.waitForURL((u) => u.pathname === '/groove/' || u.pathname === '/groove');
check(true, 'Onboarding abgeschlossen → Übersicht');

// ---- 2. Übersicht
await page.waitForSelector('.pl-card, .item', { timeout: 30000 });
const me = (await api('/api/me')).json;
check(me.is_owner && me.is_admin && me.premium.active && me.premium.unlimited_covers, 'Besitzer hat Admin + Premium ohne Limit');
const cards = await page.locator('.pl-card').count();
check(cards >= 50, `Übersicht zeigt ${cards} Playlists (Paginierung)`);
await shot('20-overview-mobile');
await page.click('text=Leer');
await page.waitForTimeout(300);
const emptyCards = await page.locator('.pl-card').count();
check(emptyCards >= 2 && emptyCards < cards, `Leer-Filter: ${emptyCards}`);
await page.click('text=Alle');
// Fremde (nur gefolgte) Playlist: eingeschränkte Ansicht statt Fehler
await page.fill('input[placeholder="Playlists durchsuchen"]', 'Fremde');
await page.waitForTimeout(200);
await page.click('.pl-card');
await page.waitForSelector('text=Songliste nicht verfügbar');
check(!(await page.isVisible('text=Da ist etwas schiefgelaufen')), 'Fremde Playlist zeigt Hinweis statt Fehler');
await page.screenshot({ path: `${SHOTS}/21-foreign-playlist.png` });
await page.goto(BASE + '/');
await page.waitForSelector('.pl-card');
await page.fill('input[placeholder="Playlists durchsuchen"]', 'Sommer');
await page.waitForTimeout(200);
check((await page.locator('.pl-card').count()) === 1, 'Suche filtert auf Sommer Vibes');
await page.click('.pl-card');
await page.waitForURL(/\/playlists\/pltest0000000000000001/);

// ---- 3. Playlist-Detail: Duplikate, Auswahl, Veröffentlichung
await page.waitForSelector('.list .item');
check((await page.locator('.list .item').count()) === 8, 'Detail zeigt 8 Songs');
await shot('30-playlist-detail');
await page.click('button[aria-label="Mehr"]');
await page.click('text=Doppelte Songs finden');
await page.waitForSelector('text=2× enthalten');
await shot('31-duplicates');
await page.click('button:has-text("1 Duplikate entfernen")');
await page.waitForSelector('text=Duplikate entfernt');
await page.waitForFunction(() => document.querySelectorAll('.list .item').length === 7, null, { timeout: 15000 });
check(true, 'Duplikat entfernt → 7 Songs');
// Mobile Umsortierung per Pfeil
const firstName = await page.locator('.list .item .title').first().textContent();
await page.locator('.list .item').first().locator('button[aria-label="Nach unten"]').click();
await page.waitForTimeout(800);
const secondName = await page.locator('.list .item .title').nth(1).textContent();
check(firstName === secondName, 'Pfeil-Sortierung verschiebt Song nach unten');
// Veröffentlichen
await page.click('role=switch[name="Auf SwipeGroove veröffentlichen"]');
await page.waitForSelector('text=Playlist veröffentlicht');
check(true, 'Playlist veröffentlicht');
// Auswahl + Kopieren
await page.click('button:has-text("Auswählen")');
await page.locator('.list .item').nth(0).click();
await page.locator('.list .item').nth(1).click();
await page.waitForSelector('text=2 ausgewählt');
await page.click('button:has-text("Kopieren nach")');
await page.waitForSelector('.sheet');
await page.click('.sheet .item:has-text("Workout")');
await page.waitForSelector('text=Alle Schritte wurden erfolgreich ausgeführt', { timeout: 20000 });
await shot('32-copy-done');
await page.click('.sheet button:has-text("OK")');
check(true, 'Kopieren in Workout abgeschlossen');

// ---- 4. Swipe-Feed
await page.goto(BASE + '/discover?target=pltest0000000000000001');
await page.waitForSelector('.swipe-card', { timeout: 30000 });
await shot('40-swipe');
const title1 = await page.locator('.swipe-card:not(.behind) .title').textContent();
await page.click('button[aria-label="Zur Playlist hinzufügen"]');
await page.waitForSelector('text=Zu „Sommer Vibes“ hinzugefügt');
await page.waitForTimeout(600);
const title2 = await page.locator('.swipe-card:not(.behind) .title').textContent();
check(title1 !== title2, 'Nächste Karte nach Hinzufügen');
await page.click('button[aria-label="Ausblenden"]');
await page.waitForTimeout(700);
await page.click('button[aria-label="Rückgängig"]');
await page.waitForSelector('text=Ausblenden rückgängig gemacht');
await page.waitForTimeout(400);
check((await page.locator('.swipe-card:not(.behind) .title').textContent()) === title2, 'Rückgängig bringt Karte zurück');
// Wischgeste (rechts)
const card = page.locator('.swipe-card:not(.behind)');
const box = await card.boundingBox();
await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
await page.mouse.down();
for (let i = 1; i <= 8; i++) { await page.mouse.move(box.x + box.width / 2 + i * 25, box.y + box.height / 2, { steps: 2 }); }
await page.mouse.up();
await page.waitForSelector('text=Zu „Sommer Vibes“ hinzugefügt');
check(true, 'Wischgeste nach rechts fügt hinzu');
const fakeState = await (await fetch('http://127.0.0.1:8090/__state')).json();
check(fakeState.playlists.pltest0000000000000001.items.length === 9, 'Zielplaylist hat nun 9 Songs (7 + 2 Swipes)');
check(new Set(fakeState.playlists.pltest0000000000000001.items).size === 9, 'Keine doppelten Songs in der Zielplaylist');
// Modus-Wechsel: Selbst einstellen ohne Filter → ehrlicher Zustand
await page.click('text=Selbst einstellen');
await page.waitForSelector('text=Keine Filter gesetzt');
check(true, 'Custom-Modus ohne Filter zeigt ehrlichen Leerzustand');

// ---- 5. Community & Profil
await page.goto(BASE + '/community');
await page.waitForSelector('text=hat eine Playlist veröffentlicht');
await shot('50-community');
await page.click('.item:has-text("Sommer Vibes")');
await page.waitForURL(/\/community\/playlists\/\d+/);
await page.fill('textarea', 'Starke Liste!');
await page.click('button[aria-label="Senden"]');
await page.waitForSelector('text=Starke Liste!');
await page.click('button:has-text("0")');
await page.waitForSelector('button:has-text("1")');
check(true, 'Kommentar und Like funktionieren');
await shot('51-published-playlist');

// ---- 6. Einstellungen: Sprache EN
await page.goto(BASE + '/settings');
await page.click('button:has-text("English")');
await page.waitForSelector('text=Private profile');
check(await page.isVisible('nav.bottom-nav >> text=Overview'), 'Navigation auf Englisch');
await shot('60-settings-en');
await page.click('button:has-text("Deutsch")');
await page.waitForSelector('text=Privates Profil');

// ---- 7. Admin öffnet Registrierung, zweiter Nutzer registriert sich
await page.goto(BASE + '/admin/settings');
await page.waitForSelector('role=switch[name="Öffentliche Registrierung"]');
await page.click('role=switch[name="Öffentliche Registrierung"]');
await page.click('button:has-text("Speichern")');
await page.waitForSelector('text=Gespeichert');
await shot('70-admin-settings');
const ctx2 = await b.newContext({ viewport: { width: 1280, height: 860 }, locale: 'de-CH' });
const p2 = await ctx2.newPage();
p2.on('pageerror', (e) => errors.push('p2: ' + e));
await p2.goto(BASE + '/login');
await p2.click('text=Mit Spotify anmelden');
await p2.waitForURL(/8090\/authorize/);
await p2.click('#login-spotify_user2');
await p2.waitForURL(/\/groove\/welcome/);
await p2.fill('input[maxlength="32"]', 'alice');
await p2.fill('input[maxlength="60"]', 'Alice');
await p2.click('button[type=submit]');
await p2.waitForURL((u) => u.pathname === '/groove/' || u.pathname === '/groove');
await p2.waitForSelector('.sidebar');
await p2.screenshot({ path: `${SHOTS}/71-desktop-overview.png` });
check(true, 'Zweiter Nutzer registriert (Desktop-Layout mit Seitenleiste)');
// Alice folgt Tobi, sendet Freundschaftsanfrage und Nachricht
await p2.goto(BASE + '/u/tobi');
await p2.waitForSelector('button:has-text("Folgen")');
await p2.click('button:has-text("Folgen")');
await p2.waitForSelector('button:has-text("Entfolgen")');
await p2.click('button:has-text("Freund hinzufügen")');
await p2.waitForSelector('text=Anfrage gesendet');
await p2.click('button:has-text("Nachricht")');
await p2.waitForURL(/\/messages\/\d+/);
await p2.fill('textarea', 'Hallo Tobi!');
await p2.press('textarea', 'Enter');
await p2.waitForSelector('.bubble.mine');
await p2.screenshot({ path: `${SHOTS}/72-desktop-chat.png` });
check(true, 'Alice: Folgen, Freundschaftsanfrage, Nachrichtenanfrage gesendet');
// Tobi: Benachrichtigungen + Anfrage annehmen + Antwort
await page.goto(BASE + '/notifications');
await page.waitForSelector('text=Freundschaftsanfragen');
await page.click('button:has-text("Annehmen")');
await page.waitForTimeout(500);
await page.goto(BASE + '/messages');
await page.waitForSelector('text=Nachrichtenanfrage');
await page.click('.item:has-text("Alice")');
await page.waitForSelector('button:has-text("Annehmen")');
await page.click('button:has-text("Annehmen")');
await page.fill('textarea', 'Hi Alice, willkommen!');
await page.press('textarea', 'Enter');
await page.waitForSelector('.bubble.mine');
await shot('73-chat-mobile');
// Alice sieht Antwort per Polling
await p2.waitForSelector('text=Hi Alice, willkommen!', { timeout: 15000 });
check(true, 'Antwort kommt per Polling an');
// Alice schaltet Profil privat → Tobi sieht nur Basisdaten
await p2.goto(BASE + '/settings');
await p2.click('role=switch[name="Privates Profil"]');
await p2.waitForTimeout(600);
await page.goto(BASE + '/u/alice');
await page.waitForSelector('text=Dieses Profil ist privat');
check(true, 'Privates Profil wird respektiert');

// ---- 8. Admin hinterlegt OpenAI-Schlüssel; Cover/Premium mit nicht erreichbaren Diensten → ehrliche Fehler
await page.goto(BASE + '/covers');
await page.waitForSelector('text=Der Cover-Generator ist noch nicht eingerichtet');
check(await page.isDisabled('button:has-text("Cover erzeugen")'), 'Ohne API-Schlüssel ist Generieren deaktiviert');
await page.goto(BASE + '/admin/integrations');
await page.waitForSelector('text=OpenAI');
await page.fill('input[placeholder="(unverändert lassen)"] >> nth=2', 'sk-local-test-key');
await page.click('button:has-text("Speichern")');
await page.waitForSelector('text=Gespeichert');
await page.goto(BASE + '/covers');
await page.waitForSelector('text=Unbegrenzte Cover');
await page.fill('textarea', 'Neon-Sonnenuntergang über dem Meer');
await page.click('button:has-text("Cover erzeugen")');
await page.waitForSelector('.toast.error', { timeout: 60000 });
check(true, 'Cover-Generator meldet fehlenden Anbieterzugang ehrlich');
await shot('80-covers');
await page.goto(BASE + '/premium');
await page.waitForSelector('text=dauerhaft Premium');
await shot('81-premium');

// ---- 9. Leere Playlists entfernen
await page.goto(BASE + '/playlists/tools/empty');
await page.waitForSelector('.item', { timeout: 60000 });
await shot('90-empty');
await page.click('button:has-text("Playlists entfernen")');
await page.waitForSelector('.sheet');
await page.click('.sheet button:has-text("Entfernen")');
await page.waitForSelector('text=Playlists entfernt', { timeout: 60000 });
const st2 = await (await fetch('http://127.0.0.1:8090/__state')).json();
check(st2.unfollowed.length >= 2, `Leere Playlists bei Spotify entfolgt (${st2.unfollowed.length})`);

// ---- 10. Rechtliche Seiten & Logout
await page.goto(BASE + '/legal/privacy');
await page.waitForSelector('text=Strukturvorlage');
await page.goto(BASE + '/settings');
await page.click('button:has-text("Abmelden")');
await page.waitForURL(/\/login/);
check(true, 'Abmelden');

console.log('HTTP-Fehler (erwartet: 502 Cover-Anbieter nicht erreichbar):', failed.join(' | '));
check(errors.length === 0, 'Keine JavaScript-Fehler (' + errors.join(' | ') + ')');
await b.close();
console.log('FLOW OK');
