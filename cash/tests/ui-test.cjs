/*
 * Browser-Test der Oberfläche (Playwright, Chromium).
 *   NODE_PATH=$(npm root -g) node tests/ui-test.cjs http://127.0.0.1:8099/cash/ <screenshot-ordner>
 * Erwartet einen frischen Testserver (tests/serve.sh) mit leerem Datenverzeichnis.
 */
const { chromium, devices } = require('playwright');
const fs = require('fs');
const PIN = '4826';   // nur Test-PIN – die echte PIN steht nie im Code
async function enterPin(pg) {
  await pg.getByRole('heading', { name: 'PIN eingeben' }).waitFor();
  await pg.getByLabel('PIN').fill(PIN);
  await pg.getByRole('button', { name: 'Entsperren' }).click();
}

const BASE = process.argv[2] || 'http://127.0.0.1:8099/cash/';
const OUT = process.argv[3] || 'screens';
fs.mkdirSync(OUT, { recursive: true });
let fails = 0;
const ok = (c, l, i) => { if (c) console.log('  ✓ ' + l); else { fails++; console.log('  ✗ ' + l + (i !== undefined ? ' → ' + JSON.stringify(i) : '')); } };

async function noOverflow(page, label) {
  const vw = page.viewportSize().width;
  const r = await page.evaluate(() => {
    // breitestes Element (fixierte Navigation ausgenommen), unabhängig vom mobilen Zoom
    let max = document.documentElement.scrollWidth;
    document.querySelectorAll('.main *, .sheet *, .auth *').forEach((e) => { const b = e.getBoundingClientRect(); if (b.width && b.right > max && getComputedStyle(e).position !== 'fixed' && !e.closest('.chips, .cat-grid')) max = b.right; });
    return { sw: Math.round(Math.max(max, window.innerWidth)) };   // mobiler Zoom-out zählt auch als Überlauf
  });
  r.w = vw;
  ok(r.sw <= r.w, 'Kein horizontales Scrollen: ' + label, r);
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME || undefined });
  const ctx = await browser.newContext({ ...devices['iPhone SE'], locale: 'de-CH', timezoneId: 'Europe/Zurich' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  const shot = (n) => page.screenshot({ path: `${OUT}/${n}.png`, fullPage: true });

  const hero = async () => (await page.locator('.hero.free .big').innerText()).replace(/\s+/g, ' ');
  const goHome = async () => { await page.goto(BASE + '#/uebersicht'); await page.waitForTimeout(350); };

  console.log('== Einrichtung (iPhone SE, 375 px) ==');
  await page.goto(BASE);
  await page.getByRole('heading', { name: 'PIN festlegen' }).waitFor();
  ok(await page.locator('script[src*="app.js"]').count() === 0, 'Vor der PIN wird kein App-Code ausgeliefert');
  await shot('00-pin-festlegen');
  await noOverflow(page, 'PIN festlegen');
  await page.getByLabel('Neue PIN').fill(PIN);
  await page.getByLabel('Wiederholen').fill(PIN);
  await page.getByRole('button', { name: 'PIN speichern' }).click();
  await page.getByText('Willkommen').waitFor();
  await shot('01-setup');
  await noOverflow(page, 'Setup');
  await page.getByLabel('Name', { exact: true }).fill('Tobias');
  await page.getByLabel('Benutzername').fill('tobias');
  await page.getByLabel('Passwort (mind. 10 Zeichen)').fill('sehr-geheim-123');
  await page.getByLabel('Passwort wiederholen').fill('sehr-geheim-123');
  await page.getByRole('button', { name: 'Account erstellen' }).click();
  await page.getByText('Konten einrichten').waitFor();
  await shot('02-onboarding');
  await noOverflow(page, 'Onboarding');
  const avs = page.getByRole('checkbox', { name: /Alltagskonto/ });
  ok(await avs.nth(0).isChecked() && !(await avs.nth(1).isChecked()), 'Privatkonto zählt, Sparkonto nicht (Vorauswahl)');
  await page.getByRole('button', { name: 'Einrichtung abschliessen' }).click();
  ok(await page.getByText(/aktuellen Kontostand eingeben/).isVisible(), 'Fehlende Anfangssalden werden abgefragt');
  const stands = page.getByLabel('Aktueller Kontostand');
  await stands.nth(0).fill('1250.40');
  await stands.nth(1).fill('830');
  await page.getByRole('button', { name: 'Einrichtung abschliessen' }).click();
  await page.locator('.hero.free .big').waitFor();
  ok((await hero()).includes('1’250.40'), 'Frei für Freizeit = Privatkonto 1’250.40 (Sparkonto nicht eingerechnet)', await hero());
  await noOverflow(page, 'Übersicht');
  await shot('03-uebersicht-start');

  console.log('== Ausgabe (Doppelklick) ==');
  await page.getByRole('button', { name: 'Ausgabe', exact: true }).click();
  await page.getByRole('dialog').waitFor();
  await page.getByLabel('Betrag').fill('23.80');
  await page.getByRole('radio', { name: 'Lebensmittel' }).click();
  await page.getByLabel('Beschreibung').fill('Coop');
  await shot('04-ausgabe');
  await noOverflow(page, 'Ausgabe-Formular');
  await page.getByRole('button', { name: 'Erfassen' }).dblclick();
  await page.waitForTimeout(700);
  ok((await hero()).includes('1’226.60'), 'Frei sinkt auf 1’226.60', await hero());

  console.log('== Wiederkehrende Ausgabe ==');
  await page.getByRole('button', { name: 'Ausgabe', exact: true }).click();
  await page.getByLabel('Betrag').fill('20');
  await page.getByRole('radio', { name: 'Abos & Medien' }).click();
  await page.getByLabel('Beschreibung').fill('Netflix');
  await page.getByRole('checkbox', { name: /Wiederkehrend/ }).check();
  ok(await page.getByLabel('Wiederholung').isVisible(), 'Intervall erscheint (Jeden Monat)');
  await shot('05-wiederkehrend');
  await page.getByRole('button', { name: 'Erfassen' }).click();
  await page.waitForTimeout(700);
  ok((await hero()).includes('1’206.60'), 'Frei nach Netflix 1’206.60', await hero());
  await page.goto(BASE + '#/fixkosten');
  await page.waitForTimeout(350);
  ok(await page.locator('.row', { hasText: 'Netflix' }).filter({ hasText: 'Monatlich' }).count() === 1, 'Netflix steht unter Fixkosten (monatlich)');

  console.log('== Fixkosten fällig & bezahlen ==');
  await page.getByRole('button', { name: 'Neue Fixkosten' }).click();
  await page.getByLabel('Name').fill('Handy-Abo');
  await page.getByLabel('Betrag').fill('29.90');
  await page.getByRole('button', { name: 'Fixkosten erfassen' }).click();
  await page.waitForTimeout(600);
  await shot('06-fixkosten');
  await noOverflow(page, 'Fixkosten');
  await goHome();
  ok((await hero()).includes('1’176.70'), 'Offene Fixkosten werden abgezogen: 1’176.70', await hero());
  ok(await page.locator('.row', { hasText: 'Handy-Abo' }).count() === 1, 'Handy-Abo unter «Noch zu bezahlen»');
  await shot('07-uebersicht-offen');
  await page.locator('.row', { hasText: 'Handy-Abo' }).getByRole('button', { name: 'Bezahlt' }).click();
  await page.getByRole('button', { name: 'Als bezahlt markieren' }).dblclick();
  await page.waitForTimeout(700);
  ok((await hero()).includes('1’176.70'), 'Nach Bezahlen bleibt «frei» gleich', await hero());
  await page.goto(BASE + '#/buchungen');
  await page.waitForTimeout(300);
  ok(await page.locator('.row', { hasText: 'Handy-Abo' }).count() === 1, 'Doppelklick: genau eine Zahlung');
  ok(await page.locator('.row', { hasText: 'Coop' }).count() === 1, 'Doppelklick: genau eine Coop-Buchung');
  await page.getByLabel('Buchungen durchsuchen').fill('coop');
  await page.waitForTimeout(200);
  ok(await page.locator('.row').count() === 1, 'Suche filtert');
  await noOverflow(page, 'Buchungen');
  await page.getByLabel('Buchungen durchsuchen').fill('');

  console.log('== Sparziel mit Sparrate ==');
  await page.goto(BASE + '#/sparziele');
  await page.getByRole('button', { name: 'Neues Sparziel' }).click();
  await page.getByLabel('Name').fill('Velo');
  await page.locator('.sheet .amount-field input').fill('900');
  await page.getByLabel('Sparrate pro Monat').fill('50');
  await page.getByRole('button', { name: 'Sparziel erstellen' }).click();
  await page.waitForTimeout(600);
  await shot('08-sparziele');
  await noOverflow(page, 'Sparziele');
  await goHome();
  ok((await hero()).includes('1’126.70'), 'Sparrate wird reserviert: 1’126.70', await hero());
  await page.locator('.row', { hasText: 'Sparrate Velo' }).getByRole('button', { name: 'Gespart' }).click();
  const sd = page.getByRole('dialog').last();
  ok(await sd.getByLabel('Auf Konto').inputValue() !== '', 'Sparkonto als Ziel vorgeschlagen');
  await shot('09-gespart');
  await sd.getByRole('button', { name: 'Gespart' }).click();
  await page.waitForTimeout(700);
  ok((await hero()).includes('1’126.70'), '«Gespart» ändert das freie Geld nicht', await hero());
  ok(await page.locator('.row', { hasText: 'E-Jugendsparkonto' }).filter({ hasText: '880.00' }).count() === 1, 'Sparkonto +50 per Umbuchung');

  console.log('== Einnahme ==');
  await page.getByRole('button', { name: 'Einnahme', exact: true }).click();
  await page.getByLabel('Betrag').fill('500');
  await page.getByRole('radio', { name: 'Lohn' }).click();
  await page.getByRole('button', { name: 'Erfassen' }).click();
  await page.waitForTimeout(700);
  ok((await hero()).includes('1’626.70'), 'Einnahme erhöht «frei»: 1’626.70', await hero());
  await shot('10-uebersicht');

  console.log('== Kontostand ändern ==');
  await page.getByRole('button', { name: 'Kontostand Privatkonto ändern' }).click();
  const bd = page.getByRole('dialog').last();
  await bd.getByLabel('Neuer Kontostand').fill('1700');
  ok((await bd.locator('.preview').innerText()).includes('+ 73.30'), 'Korrektur +73.30 wird vor dem Speichern angezeigt', await bd.locator('.preview').innerText());
  await shot('10b-kontostand');
  await noOverflow(page, 'Kontostand ändern');
  await bd.getByRole('button', { name: 'Kontostand speichern' }).dblclick();
  await page.waitForTimeout(700);
  ok((await hero()).includes('1’700.00'), '«Frei» = neuer Kontostand 1’700.00', await hero());
  ok(await page.locator('.row', { hasText: 'Privatkonto' }).filter({ hasText: '1’700.00' }).count() === 1, 'Privatkonto zeigt 1’700.00');

  console.log('== PostFinance-Import ==');
  const t = new Date();
  const dd = (d) => String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear();
  const tomorrow = new Date(t.getTime() + 86400000);
  const csv = 'Konto:;CH76 0900 0000 1234 5678 9\r\nWährung:;CHF\r\n\r\nBuchungsdatum;Avisierungstext;Gutschrift in CHF;Lastschrift in CHF;Valuta;Saldo in CHF\r\n'
    + dd(tomorrow) + ';"KAUF/DIENSTLEISTUNG VOM ' + dd(t) + ' KARTEN NR. XXXX1234 KINO PATHE ZUERICH";;-18.00;' + dd(tomorrow) + ';1682.00\r\n'
    + dd(t) + ';"KAUF/DIENSTLEISTUNG VOM ' + dd(t) + ' KARTEN NR. XXXX1234 MIGROS M ZUERICH";;-23.80;' + dd(t) + ';1700.00\r\n';
  await page.goto(BASE + '#/buchungen');
  await page.getByRole('link', { name: 'Kontoauszug importieren' }).click();
  await page.getByRole('heading', { name: 'Import' }).waitFor();
  await page.locator('input[type=file]').setInputFiles({ name: 'postfinance.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'latin1') });
  await page.locator('.imp-row').first().waitFor();
  ok(await page.locator('.imp-row', { hasText: 'Kino Pathe' }).count() === 1, 'Neue Kinobuchung in der Vorschau');
  ok(await page.locator('.imp-row', { hasText: 'Migros' }).getByText('Schon erfasst').count() === 1, 'Von Hand erfasste Ausgabe (23.80) wird erkannt statt doppelt importiert');
  const kinoCat = page.locator('.imp-row', { hasText: 'Kino Pathe' }).getByLabel('Kategorie');
  ok((await kinoCat.inputValue()).startsWith('cat:') && (await kinoCat.locator('option:checked').innerText()) === 'Freizeit', 'Kino → Freizeit vorgeschlagen');
  await shot('12-import-vorschau');
  await noOverflow(page, 'Import-Vorschau');
  await page.getByRole('button', { name: /übernehmen/ }).last().click();
  await page.waitForTimeout(900);
  ok(await page.getByText('Letzte Importe').isVisible(), 'Import übernommen, Verlauf sichtbar');
  await goHome();
  ok((await hero()).includes('1’682.00'), 'Kontostand aus der Bank übernommen → frei 1’682.00', await hero());
  await page.goto(BASE + '#/import');
  await page.getByRole('button', { name: 'Rückgängig' }).first().click();
  await page.getByRole('dialog').last().getByRole('button', { name: 'Rückgängig' }).click();
  await page.waitForTimeout(800);
  await goHome();
  ok((await hero()).includes('1’700.00'), 'Rückgängig stellt den vorherigen Stand her', await hero());

  for (const r of ['konten', 'einstellungen']) {
    await page.goto(BASE + '#/' + r);
    await page.waitForTimeout(400);
    await noOverflow(page, r);
    await shot('11-' + r);
  }

  console.log('== Angemeldet bleiben ==');
  const cookies = await ctx.cookies();
  ok(cookies.some((c) => c.name === 'CASHREM' && c.httpOnly), 'Token-Cookie ist HttpOnly');
  const ctx2 = await browser.newContext({ ...devices['iPhone 13'] });
  await ctx2.addCookies(cookies.filter((c) => c.name === 'CASHREM'));
  const p2 = await ctx2.newPage();
  await p2.goto(BASE);
  ok(await p2.getByRole('heading', { name: 'PIN eingeben' }).isVisible(), 'Neues Gerät: zuerst PIN');
  await p2.getByLabel('PIN').fill('0000');
  await p2.getByRole('button', { name: 'Entsperren' }).click();
  ok(await p2.getByText('Falsche PIN.').isVisible(), 'Falsche PIN wird abgelehnt');
  await p2.screenshot({ path: `${OUT}/14b-pin-eingeben.png` });
  await enterPin(p2);
  await p2.locator('.hero.free .big').waitFor({ timeout: 5000 });
  ok(true, 'Nach PIN mit Token automatisch angemeldet (iPhone 13, 390 px)');
  await noOverflow(p2, 'iPhone 13');
  await p2.screenshot({ path: `${OUT}/15-iphone13.png`, fullPage: true });
  await p2.goto(BASE + '#/einstellungen');
  await p2.getByRole('button', { name: /Abmelden/ }).last().click();
  await p2.getByRole('heading', { name: 'PIN eingeben' }).waitFor();
  ok(true, 'Logout sperrt auch die PIN-Session');
  await enterPin(p2);
  await p2.getByRole('heading', { name: 'Anmelden' }).waitFor();
  ok(await p2.getByRole('heading', { name: 'Anmelden' }).isVisible(), 'Nach Logout kein automatisches Anmelden mehr (Token widerrufen)');
  await p2.screenshot({ path: `${OUT}/16-login.png` });

  console.log('== iPad & Desktop ==');
  for (const [name, vp] of [['ipad', { width: 820, height: 1180 }], ['desktop', { width: 1366, height: 900 }], ['schmal-320', { width: 320, height: 640 }]]) {
    const c = await browser.newContext({ viewport: vp, deviceScaleFactor: 1 });
    await c.addCookies((await ctx.cookies()).filter((x) => x.name === 'CASHSID'));
    const pg = await c.newPage();
    pg.on('pageerror', (e) => errors.push(e.message));
    await pg.goto(BASE + '#/uebersicht');
    await pg.locator('.hero.free .big').waitFor();
    await pg.waitForTimeout(400);
    await noOverflow(pg, name);
    await pg.screenshot({ path: `${OUT}/17-${name}.png`, fullPage: true });
    await pg.goto(BASE + '#/buchungen');
    await pg.waitForTimeout(300);
    await noOverflow(pg, name + ' Buchungen');
    if (name === 'schmal-320') {
      await pg.getByRole('button', { name: 'Ausgabe', exact: true }).first().click();
      await pg.waitForTimeout(300);
      await noOverflow(pg, '320 px Formular');
      await pg.screenshot({ path: `${OUT}/18-320-formular.png` });
    }
    await c.close();
  }

  ok(errors.length === 0, 'Keine JavaScript-Fehler', errors);
  await browser.close();
  console.log(fails ? `\n${fails} fehlgeschlagen` : '\nAlle UI-Tests bestanden');
  process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
