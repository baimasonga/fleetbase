// End-to-end test of the field app against the real PoD package code.
const { chromium } = require('playwright');
const fs = require('fs');
const assert = require('assert');

const E = process.env.POD_E2E_DIR || require('os').tmpdir() + '/pod-e2e';
const base = process.env.POD_E2E_URL || 'http://127.0.0.1:8099';
fs.mkdirSync(`${E}/shots`, { recursive: true });
const seed = JSON.parse(fs.readFileSync(`${E}/seed.json`, 'utf8'));
const TOKEN = 'e2e-11111111-1111-1111-1111-111111111111';
const errors = [];
const pages = [];

async function apiGet(path) {
  const r = await fetch(`${base}/int/v1/${path}`, { headers: { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' } });
  return r.json();
}

async function phone(browser, name, scale) {
  const ctx = await browser.newContext({
    viewport: { width: 390, height: 844 }, deviceScaleFactor: scale, isMobile: true, hasTouch: true,
    geolocation: { latitude: 7.9805, longitude: -10.6361 }, permissions: ['geolocation', 'camera'],
  });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error' && !m.text().includes('401')) errors.push(`${name} console: ${m.text()}`); });
  pages.push(page);
  page.on('pageerror', (e) => errors.push(`${name} page: ${e.message}`));
  return { ctx, page };
}

async function signIn(page) {
  await page.goto(`${base}/pod-field/`);
  await page.getByLabel('Email or phone').fill('officer@example.org');
  await page.getByLabel('Password').fill('wrong');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.getByText('These credentials do not match our records.').waitFor();
  await page.getByLabel('Password').fill('field-pass');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.getByRole('heading', { name: 'Download for the field' }).waitFor();
}

async function draw(page, canvas) {
  await canvas.scrollIntoViewIfNeeded();
  await page.evaluate(() => window.scrollBy(0, 120)); // clear of the sticky header
  await canvas.scrollIntoViewIfNeeded();
  const box = await canvas.boundingBox();
  await page.mouse.move(box.x + 30, box.y + 70);
  await page.mouse.down();
  for (let i = 1; i <= 12; i++) await page.mouse.move(box.x + 30 + i * 20, box.y + 70 - Math.sin(i) * 30);
  await page.mouse.up();
}

async function typeCode(page, code) {
  await page.getByRole('button', { name: 'Type code' }).click();
  await page.getByLabel('Code printed under the QR').fill(code);
  await page.getByRole('button', { name: 'Check card' }).click();
}

(async () => {
  const browser = await chromium.launch({ args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', `--use-file-for-fake-video-capture=${E}/isatu.mjpeg`] });

  // ---- Phone 1: sign in, download on "depot Wi-Fi" -----------------------------------
  const p1 = await phone(browser, 'phone1', 2);
  const page = p1.page;
  await page.goto(`${base}/pod-field/`);
  await page.screenshot({ path: `${E}/shots/1-sign-in.png` });
  await signIn(page);
  await page.getByRole('button', { name: 'Download' }).click();
  await page.getByText('It works offline now').waitFor();
  await page.getByRole('heading', { name: 'On this phone' }).waitFor();
  await page.waitForFunction(() => navigator.serviceWorker && navigator.serviceWorker.controller !== null, null, { timeout: 15000 }).catch(async () => {
    await page.reload();
    await page.waitForFunction(() => navigator.serviceWorker.controller !== null, null, { timeout: 15000 });
  });
  await page.screenshot({ path: `${E}/shots/2-home.png` });

  // ---- Phone 2 downloads too, before phone 1 syncs anything ----------------------------
  const p2 = await phone(browser, 'phone2', 1);
  await signIn(p2.page);
  await p2.page.getByRole('button', { name: 'Download' }).click();
  await p2.page.getByText('It works offline now').waitFor();

  // ---- Into the field: no signal --------------------------------------------------------
  await p1.ctx.setOffline(true);
  await page.reload(); // the app must load with no network (service worker)
  await page.getByText('Offline').waitFor();
  await page.getByRole('button', { name: 'Open' }).click();
  await page.getByText('3', { exact: true }).first().waitFor();
  await page.screenshot({ path: `${E}/shots/3-event-offline.png` });

  // 1) Aminata: card code typed, ID last 4 checked, full package including herbicide.
  await typeCode(page, seed.farmers['Aminata Kamara'].code);
  await page.getByText('Card checked').waitFor();
  await page.getByText('I have checked this is the farmer').click();
  await page.getByLabel('Last 4 characters of their ID (optional)').fill('6789');
  await page.getByText('✓ matches').waitFor();
  // The expired NPK lot is listed but can't be chosen.
  const npkLot = page.getByLabel('Lot for NPK 15-15-15, 50 kg');
  assert.strictEqual(await npkLot.inputValue(), 'L1');
  assert.ok((await npkLot.locator('option[value="OLD2"]').getAttribute('disabled')) !== null, 'expired lot disabled');
  assert.ok(await page.getByRole('button', { name: 'Record handover' }).isDisabled(), 'not ready without proof');
  await page.locator('input[type=file]').setInputFiles(`${E}/farmer.jpg`);
  await page.getByAltText('Photo 1').waitFor();
  await draw(page, page.locator('canvas.pad').first());
  assert.ok(await page.getByRole('button', { name: 'Record handover' }).isDisabled(), 'herbicide needs the safety briefing');
  await page.getByText('Safety briefing given').click();
  await page.getByText('Ready: identity L3.').waitFor();
  await page.screenshot({ path: `${E}/shots/4-handover.png`, fullPage: true });
  await page.getByRole('button', { name: 'Record handover' }).click();
  await page.getByText('Handover recorded.').waitFor();
  await page.screenshot({ path: `${E}/shots/5-receipt.png` });
  await page.getByRole('button', { name: 'Next farmer' }).click();

  // 2) Isatu: card scanned with the camera; not trained, so no herbicide; takes 1 NPK of 2.
  await page.getByRole('button', { name: 'Scan card' }).click();
  await page.getByText('Card checked').waitFor({ timeout: 20000 });
  // No safety training, so no herbicide entitlement was generated: it isn't offered at all.
  assert.strictEqual(await page.locator('.line', { hasText: 'Herbicide' }).count(), 0);
  await page.getByText('I have checked this is the farmer').click();
  const npk = page.locator('.line', { hasText: 'NPK 15-15-15' });
  await npk.getByRole('button', { name: 'Less' }).click();
  assert.strictEqual(await npk.locator('output').textContent(), '1');
  await page.locator('input[type=file]').setInputFiles(`${E}/farmer.jpg`);
  await page.getByRole('button', { name: 'Makes a mark' }).click();
  await draw(page, page.locator('canvas.pad').first());
  await page.getByText('Ready: identity L2.').waitFor();
  await page.getByRole('button', { name: 'Record handover' }).click();
  await page.getByText('Handover recorded.').waitFor();
  await page.getByRole('button', { name: 'Next farmer' }).click();

  // 3) Musa's old card was replaced: refused offline.
  await typeCode(page, seed.farmers['Musa Bangura'].old_code);
  await page.getByText('This card has been replaced').waitFor();
  // A forged code too.
  await page.getByLabel('Code printed under the QR').fill(seed.farmers['Musa Bangura'].code.slice(0, -2) + 'AA');
  await page.getByRole('button', { name: 'Check card' }).click();
  await page.getByText('This card is not genuine').waitFor();

  // 4) Musa without his card: photo + witness (identity L1).
  await page.getByRole('button', { name: /Musa Bangura/ }).click();
  await page.getByText('No card', { exact: true }).waitFor();
  await page.getByText('A witness confirms this is the farmer.').click();
  await page.locator('input[type=file]').setInputFiles(`${E}/farmer.jpg`);
  await draw(page, page.locator('canvas.pad').nth(0));
  await page.getByLabel('Witness name').fill('Chief Musa Kallon');
  await draw(page, page.locator('canvas.pad').nth(1));
  await page.getByText('Ready: identity L1.').waitFor();
  await page.getByRole('button', { name: 'Record handover' }).click();
  await page.getByText('Handover recorded.').waitFor();
  await page.getByText('3 to send', { exact: false }).waitFor().catch(() => {});
  const badge = await page.locator('#net').textContent();
  assert.match(badge, /Offline · 10 to send/, `badge: ${badge}`); // 3 records + 7 photos and signatures

  // Nothing has reached the server yet.
  assert.strictEqual((await apiGet(`pod/events/${seed.event}/handovers`)).handovers.length, 0);

  // ---- Phone 2, also offline, serves Aminata again ----------------------------------------
  await p2.ctx.setOffline(true);
  await p2.page.getByRole('button', { name: 'Open' }).click();
  await typeCode(p2.page, seed.farmers['Aminata Kamara'].code);
  await p2.page.getByText('I have checked this is the farmer').click();
  await p2.page.locator('input[type=file]').setInputFiles(`${E}/farmer.jpg`);
  await draw(p2.page, p2.page.locator('canvas.pad').first());
  await p2.page.getByText('Safety briefing given').click();
  await p2.page.getByRole('button', { name: 'Record handover' }).click();
  await p2.page.getByText('Handover recorded.').waitFor();

  // ---- Back in signal: phone 1 syncs by itself -------------------------------------------
  await p1.ctx.setOffline(false);
  await page.waitForFunction(() => document.getElementById('net').textContent === 'Online', null, { timeout: 30000 });
  const handovers = (await apiGet(`pod/events/${seed.event}/handovers`)).handovers;
  assert.strictEqual(handovers.length, 3);
  const levels = Object.fromEntries(handovers.map((h) => [h.beneficiary.name, [h.identity_level, h.outcome]]));
  assert.deepStrictEqual(levels, { 'Aminata Kamara': ['L3', 'delivered'], 'Isatu Koroma': ['L2', 'partial'], 'Musa Bangura': ['L1', 'delivered'] });
  const media = await apiGet(`pod/events/${seed.event}/media-status`);
  assert.deepStrictEqual([media.expected, media.uploaded, media.missing.length], [7, 7, 0]);

  // ---- Phone 2 syncs: its second handover to Aminata becomes a conflict for review --------
  await p2.ctx.setOffline(false);
  await p2.page.goto(`${base}/pod-field/#/`);
  await p2.page.getByText('1 record needs a supervisor\'s review').waitFor({ timeout: 30000 });
  await p2.page.screenshot({ path: `${E}/shots/6-conflict.png` });
  const conflicts = (await apiGet(`pod/events/${seed.event}/conflicts`)).conflicts;
  assert.deepStrictEqual(conflicts.map((c) => c.error), ['already_collected']);
  assert.strictEqual((await apiGet(`pod/events/${seed.event}/handovers`)).handovers.length, 3);

  // Phone 1's list now shows the work.
  await page.goto(`${base}/pod-field/#/event/${seed.event}`);
  await page.getByText('Collected').first().waitFor();

  await browser.close();
  if (errors.length) {
    console.log('Browser errors:\n' + errors.join('\n'));
    process.exit(1);
  }
  console.log('E2E PASSED');
})().catch(async (e) => {
  console.error('E2E FAILED:', e.message);
  for (const [i, p] of pages.entries()) {
    try {
      console.error(`--- page ${i + 1} footer: ` + await p.locator('.footer-actions').textContent({ timeout: 2000 }));
      await p.screenshot({ path: `${E}/shots/fail-${i + 1}.png`, fullPage: true });
    } catch (x) {}
  }
  if (errors.length) console.error('Browser errors:\n' + errors.join('\n'));
  process.exit(1);
});
