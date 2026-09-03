import { chromium } from 'playwright';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--disable-background-networking','--no-first-run','--force-color-profile=srgb'] });
const page = await browser.newPage({ deviceScaleFactor: 2 });
await page.goto('file://./logo.html');
await page.evaluate(() => document.fonts.ready);
await page.waitForTimeout(300);
const dir = './';
await page.locator('#logo').screenshot({ path: dir + 'logo.png', omitBackground: true });
await page.locator('#regel').screenshot({ path: dir + 'regel.png', omitBackground: true });
await page.locator('#regelhell').screenshot({ path: dir + 'regel-hell.png', omitBackground: true });
for (const n of ['logo','regel','regel-hell']) {
  const box = await page.locator('#' + (n === 'regel-hell' ? 'regelhell' : n)).boundingBox();
  console.log(n + '.png:', Math.round(box.width) + '×' + Math.round(box.height), 'CSS-Pixel');
}
await browser.close();
