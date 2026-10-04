/*
 * Browser-Test der Oberfläche (Playwright, Chromium).
 *   NODE_PATH=$(npm root -g) node tests/ui-test.cjs http://127.0.0.1:8099/cash/ <screenshot-ordner>
 * Erwartet einen frischen Testserver (tests/serve.sh) mit leerem Datenverzeichnis.
 */
const { chromium, devices } = require('playwright');
const fs = require('fs');

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
    return { sw: Math.round(max) };
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

  console.log('== Einrichtung (iPhone SE, 375 px) ==');
  await page.goto(BASE);
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
  await page.getByRole('button', { name: 'Einrichtung abschliessen' }).click();
  ok(await page.getByText(/aktuellen Kontostand eingeben/).isVisible(), 'Fehlende Anfangssalden werden abgefragt');
  const stands = page.getByLabel('Aktueller Kontostand');
  await stands.nth(0).fill('1250.40');
  await stands.nth(1).fill('830');
  await page.getByRole('button', { name: 'Einrichtung abschliessen' }).click();
  await page.locator('.hero .big').waitFor();
  ok((await page.locator('.hero .big').innerText()).includes('2’080.40'), 'Kontostand 2’080.40 im Dashboard', await page.locator('.hero .big').innerText());
  ok(await page.getByText('Ferien Thailand').isVisible(), 'Thailand-Budget sichtbar');
  await noOverflow(page, 'Übersicht');
  await shot('03-dashboard-leer');

  console.log('== Ausgabe erfassen ==');
  await page.getByRole('button', { name: 'Ausgabe', exact: true }).click();
  await page.getByRole('dialog').waitFor();
  await page.getByLabel('Betrag').fill('23.80');
  await page.getByRole('radio', { name: 'Lebensmittel' }).click();
  await page.getByLabel('Beschreibung').fill('Coop');
  await shot('04-ausgabe-formular');
  await noOverflow(page, 'Ausgabe-Formular');
  await page.getByRole('button', { name: 'Erfassen' }).dblclick();
  await page.locator('.toast').first().waitFor();
  await page.waitForTimeout(400);
  await page.goto(BASE + '#/buchungen');
  await page.waitForTimeout(300);
  ok(await page.locator('.row', { hasText: 'Coop' }).count() === 1, 'Doppelklick erzeugt genau eine Buchung');

  console.log('== Einnahme ==');
  await page.goto(BASE + '#/uebersicht');
  await page.getByRole('button', { name: 'Einnahme', exact: true }).click();
  await page.getByLabel('Betrag').fill('500');
  await page.getByRole('radio', { name: 'Lohn' }).click();
  await page.getByRole('button', { name: 'Erfassen' }).click();
  await page.waitForTimeout(500);
  ok((await page.locator('.hero .big').innerText()).includes('2’556.60'), 'Kontostand nach Einnahme/Ausgabe 2’556.60', await page.locator('.hero .big').innerText());

  console.log('== THB-Ausgabe über Budget ==');
  await page.goto(BASE + '#/budgets');
  await page.getByRole('button', { name: /Ferien Thailand/ }).click();
  await page.getByRole('button', { name: /Ausgabe erfassen \(THB\)/ }).click();
  const dlg = page.getByRole('dialog').last();
  await dlg.getByText('Bitte den aktuellen Kurs selbst eingeben').waitFor();
  ok(await dlg.getByLabel('Wechselkurs').inputValue() === '', 'Kein Kurs vorbelegt (nicht erfunden)');
  await dlg.getByLabel('Wechselkurs').fill('38.20');
  await dlg.getByLabel('Betrag').fill('450');
  await dlg.getByRole('radio', { name: 'Restaurant & Café' }).click();
  ok((await dlg.locator('.preview').innerText()).includes('CHF 11.78'), 'Vorschau zeigt CHF 11.78 vor dem Speichern', await dlg.locator('.preview').innerText());
  await shot('05-thb-formular');
  await noOverflow(page, 'THB-Formular');
  await dlg.getByRole('button', { name: 'Erfassen' }).click();
  await page.waitForTimeout(600);
  ok(await page.getByText('CHF 303.22').first().isVisible(), 'Thailand-Restbudget CHF 303.22');
  await shot('06-budget-detail');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  await page.getByRole('button', { name: /Ferien Thailand/ }).click();
  await page.getByRole('button', { name: /Ausgabe erfassen \(THB\)/ }).click();
  ok(await page.getByRole('dialog').last().getByLabel('Wechselkurs').inputValue() === '38.2', 'Letzter Kurs wird vorbelegt');
  await page.keyboard.press('Escape'); await page.waitForTimeout(250);
  await page.keyboard.press('Escape'); await page.waitForTimeout(250);
  await shot('07-budgets');

  console.log('== Rechnung ==');
  await page.goto(BASE + '#/rechnungen');
  await page.getByRole('button', { name: 'Neue Rechnung' }).click();
  await page.getByLabel('Name').fill('Handy-Abo');
  await page.getByLabel('Betrag').fill('29.90');
  await page.getByRole('button', { name: 'Rechnung erfassen' }).click();
  await page.waitForTimeout(500);
  await shot('08-rechnungen');
  await noOverflow(page, 'Rechnungen');
  await page.getByRole('button', { name: 'Bezahlt', exact: true }).first().click();
  await page.getByRole('button', { name: /Bezahlt – Ausgabe erfassen/ }).dblclick();
  await page.waitForTimeout(600);
  await page.getByRole('tab', { name: /Bezahlt/ }).click();
  ok(await page.locator('.row', { hasText: 'Handy-Abo' }).count() === 1, 'Rechnung als bezahlt geführt');
  await page.goto(BASE + '#/buchungen');
  await page.waitForTimeout(300);
  ok(await page.locator('.row', { hasText: 'Handy-Abo' }).count() === 1, 'Genau eine verknüpfte Ausgabe trotz Doppelklick');
  await shot('09-buchungen');
  await noOverflow(page, 'Buchungen');
  await page.getByLabel('Buchungen durchsuchen').fill('coop');
  await page.waitForTimeout(200);
  ok(await page.locator('.row').count() === 1, 'Suche filtert');

  console.log('== Schulden ==');
  await page.goto(BASE + '#/schulden');
  await page.getByRole('button', { name: 'Neuer Eintrag' }).click();
  await page.getByLabel('Person').fill('Anna');
  await page.getByLabel('Betrag').fill('120');
  await page.getByRole('button', { name: 'Erfassen' }).click();
  await page.waitForTimeout(500);
  await page.getByRole('button', { name: /Anna/ }).click();
  await page.getByRole('button', { name: 'Zahlung erfassen' }).click();
  const pd = page.getByRole('dialog').last();
  await pd.getByLabel('Betrag').fill('50');
  await pd.getByRole('button', { name: 'Zahlung speichern' }).click();
  await page.waitForTimeout(500);
  ok(await page.getByRole('dialog').getByText('CHF 70.00').first().isVisible(), 'Teilrückzahlung → Rest CHF 70.00');
  await shot('10-schuld-detail');
  await page.keyboard.press('Escape'); await page.waitForTimeout(250);
  await shot('11-schulden');

  console.log('== Sparziel, Konten, Einstellungen, Mehr ==');
  await page.goto(BASE + '#/sparziele');
  await page.getByRole('button', { name: 'Neues Sparziel' }).click();
  await page.getByLabel('Name').fill('Velo');
  await page.locator('.sheet .amount-field input').fill('900');
  await page.getByRole('button', { name: 'Sparziel erstellen' }).click();
  await page.waitForTimeout(500);
  await shot('12-sparziele');
  for (const r of ['mehr', 'konten', 'einstellungen']) {
    await page.goto(BASE + '#/' + r);
    await page.waitForTimeout(400);
    await noOverflow(page, r);
    await shot('13-' + r);
  }
  await page.goto(BASE + '#/uebersicht');
  await page.waitForTimeout(400);
  await shot('14-dashboard');

  console.log('== Angemeldet bleiben ==');
  const cookies = await ctx.cookies();
  ok(cookies.some((c) => c.name === 'CASHREM' && c.httpOnly), 'Token-Cookie ist HttpOnly');
  const ctx2 = await browser.newContext({ ...devices['iPhone 13'] });
  await ctx2.addCookies(cookies.filter((c) => c.name === 'CASHREM'));
  const p2 = await ctx2.newPage();
  await p2.goto(BASE);
  await p2.locator('.hero .big').waitFor({ timeout: 5000 });
  ok(true, 'Neuer Browser mit Token automatisch angemeldet (iPhone 13, 390 px)');
  await noOverflow(p2, 'iPhone 13');
  await p2.screenshot({ path: `${OUT}/15-iphone13.png`, fullPage: true });
  await p2.goto(BASE + '#/mehr');
  await p2.getByRole('button', { name: /Abmelden/ }).click();
  await p2.getByRole('heading', { name: 'Anmelden' }).waitFor();
  await p2.reload();
  ok(await p2.getByRole('heading', { name: 'Anmelden' }).isVisible(), 'Nach Logout kein automatisches Anmelden mehr');
  await p2.screenshot({ path: `${OUT}/16-login.png` });

  console.log('== iPad & Desktop ==');
  for (const [name, vp] of [['ipad', { width: 820, height: 1180 }], ['desktop', { width: 1366, height: 900 }], ['schmal-320', { width: 320, height: 640 }]]) {
    const c = await browser.newContext({ viewport: vp, deviceScaleFactor: 1 });
    await c.addCookies((await ctx.cookies()).filter((x) => x.name === 'CASHSID'));
    const pg = await c.newPage();
    pg.on('pageerror', (e) => errors.push(e.message));
    await pg.goto(BASE + '#/uebersicht');
    await pg.locator('.hero .big').waitFor();
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
