// End-to-end test of the field app against the real PoD package code.
//
// One distribution day, two phones, no signal at the distribution point:
//   phone 1 opens the day with a witnessed count, serves three farmers, and
//   closes the day with the physical count; phone 2 joins the day and serves
//   one of the same farmers again. Back in signal, everything syncs; the
//   duplicate becomes a conflict, the closing waits until phone 1 records the
//   duplicated goods as missing stock, then closes by itself. A supervisor
//   (not the officer) then reviews the conflict.
const { chromium } = require('playwright');
const fs = require('fs');
const assert = require('assert');

const E = process.env.POD_E2E_DIR || require('os').tmpdir() + '/pod-e2e';
const base = process.env.POD_E2E_URL || 'http://127.0.0.1:8099';
fs.mkdirSync(`${E}/shots`, { recursive: true });
const seed = JSON.parse(fs.readFileSync(`${E}/seed.json`, 'utf8'));
const TOKEN = 'e2e-11111111-1111-1111-1111-111111111111~aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'; // an administrator, for checking the server
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
  page.on('pageerror', (e) => errors.push(`${name} page: ${e.message}`));
  pages.push(page);
  return { ctx, page };
}

async function signIn(page, who = 'officer@example.org') {
  await page.goto(`${base}/pod-field/`);
  await page.getByLabel('Email or phone').fill(who);
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

// A reason the primary action is not ready yet, from the action bar (several fold into a list).
async function needs(page, text) {
  const list = page.locator('#actionbar details');
  if (await list.count() && !(await list.evaluate((d) => d.open))) await list.locator('summary').click();
  await page.locator('#actionbar').getByText(text).waitFor();
}

async function count(page, values) {
  for (const [label, value] of Object.entries(values)) {
    await page.getByLabel(label).fill(String(value));
  }
}

async function witness(page, name) {
  await page.getByLabel('Witness: name').fill(name);
  await draw(page, page.getByLabel('Witness: signature'));
}

async function discrepancy(page, option, qty, notes) {
  await page.getByRole('button', { name: 'Damaged or missing stock' }).first().click();
  await page.getByLabel('Item and lot').selectOption({ label: option });
  await page.getByLabel('How many').fill(String(qty));
  await page.getByLabel('What happened').selectOption('other');
  await page.getByLabel('Details').fill(notes);
  await page.getByRole('button', { name: 'Record', exact: true }).click();
  await page.getByText('Recorded.').waitFor();
}

// The distribution's card on the home screen.
const openDay = (page) => page.getByRole('button', { name: /served$/ }).first().click();

const waitIdle = (page) => page.waitForFunction(() => document.getElementById('net').textContent.startsWith('Online') && !document.getElementById('net').textContent.includes('Syncing'), null, { timeout: 30000 });

(async () => {
  const browser = await chromium.launch({ args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', `--use-file-for-fake-video-capture=${E}/isatu.mjpeg`] });

  // ---- At the depot: both phones sign in and download the (planned) day ---------------
  const p1 = await phone(browser, 'phone1', 2);
  const page = p1.page;
  await page.goto(`${base}/pod-field/`);
  await page.screenshot({ animations: 'disabled', path: `${E}/shots/1-sign-in.png` });
  await signIn(page);
  await page.getByRole('button', { name: 'Download' }).click();
  await page.getByText('It works offline now').waitFor();
  await page.getByText('Not opened yet').waitFor();
  await page.waitForFunction(() => navigator.serviceWorker && navigator.serviceWorker.controller !== null, null, { timeout: 15000 }).catch(async () => {
    await page.reload();
    await page.waitForFunction(() => navigator.serviceWorker.controller !== null, null, { timeout: 15000 });
  });
  await page.screenshot({ animations: 'disabled', path: `${E}/shots/2-home.png` });

  const p2 = await phone(browser, 'phone2', 1);
  await signIn(p2.page);
  await p2.page.getByRole('button', { name: 'Download' }).click();
  await p2.page.getByText('It works offline now').waitFor();

  // ---- At the distribution point: no signal ----------------------------------------------
  await p1.ctx.setOffline(true);
  await p2.ctx.setOffline(true);
  await page.reload(); // the app must load with no network (service worker)
  await page.locator('#net', { hasText: 'Offline' }).waitFor();
  await openDay(page);

  // Nothing can be handed over before the day is opened.
  await page.getByText('The day has not been opened yet.').waitFor();

  // Phone 1 opens the day: a witnessed count of the stock at the distribution point.
  await page.getByRole('button', { name: 'Open the day' }).click();
  await count(page, {
    'Counted: Rice seed NERICA L19, 25 kg lot R7': 10,
    'Counted: NPK 15-15-15, 50 kg lot L1': 20,
    'Counted: Herbicide, 1 litre lot H3': 5,
  });
  await needs(page, 'Enter a count for every line'); // the expired lot isn't counted yet
  await page.getByLabel('Counted: NPK 15-15-15, 50 kg lot OLD2').fill('25');
  await needs(page, 'A count is more than the site holds');
  await page.getByLabel('Counted: NPK 15-15-15, 50 kg lot OLD2').fill('3');
  assert.ok(await page.getByRole('button', { name: 'Record opening' }).isDisabled(), 'needs a witness');
  await witness(page, 'Chief Musa Kallon');
  await page.getByText('Ready.', { exact: true }).waitFor();
  await page.screenshot({ animations: 'disabled', path: `${E}/shots/3-opening.png`, fullPage: true });
  await page.getByRole('button', { name: 'Record opening' }).click();
  await page.getByText('The day is open. You can hand over now.').waitFor();
  await page.getByRole('button', { name: 'Close the day' }).waitFor();
  await page.screenshot({ animations: 'disabled', path: `${E}/shots/4-event-offline.png` });

  // 1) Aminata: card code typed, ID last 4 checked, full package including herbicide.
  await typeCode(page, seed.farmers['Aminata Kamara'].code);
  await page.getByText('Card checked').waitFor();
  await page.getByText('I have checked this is the farmer').click();
  await page.getByLabel('Last 4 characters of their ID (optional)').fill('6789');
  await page.getByText('ID matches').waitFor();
  const npkLot = page.getByLabel('Lot for NPK 15-15-15, 50 kg');
  assert.strictEqual(await npkLot.inputValue(), 'L1');
  assert.ok((await npkLot.locator('option[value="OLD2"]').getAttribute('disabled')) !== null, 'expired lot disabled');
  await page.locator('input[type=file]').setInputFiles(`${E}/farmer.jpg`);
  await page.getByAltText('Photo 1').waitFor();
  await draw(page, page.locator('canvas.pad').first());
  assert.ok(await page.getByRole('button', { name: 'Record handover' }).isDisabled(), 'herbicide needs the safety briefing');
  await page.getByText('Safety briefing given').click();
  await page.getByText('Ready to record · identity L3').waitFor();
  await page.screenshot({ animations: 'disabled', path: `${E}/shots/5-handover.png`, fullPage: true });
  await page.getByRole('button', { name: 'Record handover' }).click();
  await page.getByRole('heading', { name: 'Handover recorded' }).waitFor();
  await page.screenshot({ animations: 'disabled', path: `${E}/shots/6-receipt.png` });
  await page.getByRole('button', { name: 'Next farmer' }).click();

  // 2) Isatu: card scanned with the camera; no herbicide (no training); takes 1 NPK of 2.
  await page.getByRole('button', { name: 'Scan card' }).click();
  await page.getByText('Card checked').waitFor({ timeout: 20000 });
  assert.strictEqual(await page.locator('.line-item', { hasText: 'Herbicide' }).count(), 0);
  await page.getByText('I have checked this is the farmer').click();
  await page.getByRole('button', { name: 'One less: NPK 15-15-15, 50 kg' }).click();
  await page.locator('input[type=file]').setInputFiles(`${E}/farmer.jpg`);
  await page.getByRole('button', { name: 'Makes a mark' }).click();
  await draw(page, page.locator('canvas.pad').first());
  await page.getByText('Ready to record · identity L2').waitFor();
  await page.getByRole('button', { name: 'Record handover' }).click();
  await page.getByRole('heading', { name: 'Handover recorded' }).waitFor();
  await page.getByRole('button', { name: 'Next farmer' }).click();

  // 3) Replaced and forged cards are refused offline.
  await typeCode(page, seed.farmers['Musa Bangura'].old_code);
  await page.getByText('This card has been replaced').waitFor();
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
  await page.getByText('Ready to record · identity L1').waitFor();
  await page.getByRole('button', { name: 'Record handover' }).click();
  await page.getByRole('heading', { name: 'Handover recorded' }).waitFor();
  await page.getByRole('button', { name: 'Next farmer' }).click();

  // ---- Phone 2 joins the day and serves Aminata again (it can't know) -------------------
  await openDay(p2.page);
  await p2.page.getByRole('button', { name: 'Join: opened on another phone' }).click();
  await p2.page.getByText('Joined.').waitFor();
  await typeCode(p2.page, seed.farmers['Aminata Kamara'].code);
  await p2.page.getByText('I have checked this is the farmer').click();
  await p2.page.locator('input[type=file]').setInputFiles(`${E}/farmer.jpg`);
  await draw(p2.page, p2.page.locator('canvas.pad').first());
  await p2.page.getByText('Safety briefing given').click();
  await p2.page.getByRole('button', { name: 'Record handover' }).click();
  await p2.page.getByRole('heading', { name: 'Handover recorded' }).waitFor();

  // ---- Phone 1 closes the day with the physical count ------------------------------------
  // Phone 1's records: rice 10-3=7, NPK L1 20-5=15, herbicide 5-1=4, OLD2 3.
  // Phone 2 also gave out 1 rice, 2 NPK and 1 herbicide, so the shelf holds less.
  await page.getByRole('button', { name: 'Close the day' }).click();
  await count(page, {
    'Counted: Rice seed NERICA L19, 25 kg lot R7': 6,
    'Counted: NPK 15-15-15, 50 kg lot L1': 13,
    'Counted: Herbicide, 1 litre lot H3': 3,
    'Counted: NPK 15-15-15, 50 kg lot OLD2': 3,
  });
  await page.getByText('-2 against this phone\'s records').waitFor();
  await witness(page, 'Chief Musa Kallon');
  await page.getByText('3 lines differ from this phone\'s records').waitFor();
  await page.screenshot({ animations: 'disabled', path: `${E}/shots/7-closing.png`, fullPage: true });
  await page.getByRole('button', { name: 'Record closing' }).click();
  await page.getByText('Closing recorded.').waitFor();
  await page.getByText('The day was closed on this phone.').waitFor();
  const badge = await page.locator('#net').textContent();
  assert.match(badge, /Offline · 14 to send/, `badge: ${badge}`); // 5 records (open, 3 handovers, close) + 9 photos and signatures (2 witnesses, 2+2+3 handovers)

  assert.strictEqual((await apiGet(`pod/events/${seed.event}/handovers`)).handovers.length, 0, 'nothing reached the server yet');

  // ---- Back in signal: phone 2 syncs first ----------------------------------------------
  await p2.ctx.setOffline(false);
  await p2.page.goto(`${base}/pod-field/#/`);
  await p2.page.getByText('waiting for the day to be opened on the server').waitFor({ timeout: 30000 });

  // Phone 1 syncs: opening, handovers; the closing doesn't balance yet.
  await p1.ctx.setOffline(false);
  await page.goto(`${base}/pod-field/#/`);
  await page.getByText('the count does not balance yet').waitFor({ timeout: 30000 });
  let event = (await apiGet(`pod/events/${seed.event}`)).event;
  assert.strictEqual(event.status, 'open');
  assert.strictEqual(event.witnesses.open[0].name, 'Chief Musa Kallon');

  // Phone 2 retries: its handover is now a conflict (Aminata already collected).
  await p2.page.getByRole('button', { name: 'Sync now' }).click();
  await p2.page.getByText('1 record needs a supervisor\'s review').waitFor({ timeout: 30000 });
  await p2.page.screenshot({ animations: 'disabled', path: `${E}/shots/8-conflict.png` });

  // Phone 1 records the duplicated goods as missing stock; the closing then goes through.
  await page.getByRole('button', { name: 'Sync now' }).click();
  await waitIdle(page);
  await page.screenshot({ animations: 'disabled', path: `${E}/shots/9-unbalanced.png`, fullPage: true });
  const note = 'Given to Aminata Kamara a second time by phone 2 (see conflict).';
  await discrepancy(page, 'Rice seed NERICA L19, 25 kg · lot R7 · 7 left', 1, note);
  await discrepancy(page, 'NPK 15-15-15, 50 kg · lot L1 · 15 left', 2, note);
  await discrepancy(page, 'Herbicide, 1 litre · lot H3 · 4 left', 1, note);
  await page.getByRole('button', { name: 'Back' }).click();
  await page.getByRole('heading', { name: 'Your distributions' }).waitFor();
  await page.getByRole('button', { name: 'Sync now' }).click();
  await page.getByText('Closed', { exact: true }).waitFor({ timeout: 30000 });
  await waitIdle(page);
  await page.screenshot({ animations: 'disabled', path: `${E}/shots/10-closed.png` });

  // ---- A supervisor reviews the conflict --------------------------------------------------
  // (Separation of duties, a supervisor refused on their own record, is in ConflictReviewTest.)
  // A field officer has no review role: the app doesn't offer it, and the server refuses it.
  await page.getByRole('button', { name: 'Sign out' }).waitFor();
  assert.strictEqual(await page.getByRole('button', { name: 'Review conflicts' }).count(), 0, 'officers do not review');
  const refused = (await fetch(`${base}/int/v1/pod/conflicts`, { headers: { Authorization: 'Bearer e2e-11111111-1111-1111-1111-111111111111', Accept: 'application/json' } })).status;
  assert.strictEqual(refused, 403);

  const sv = await phone(browser, 'supervisor', 2);
  await signIn(sv.page, 'supervisor@example.org');
  await sv.page.getByRole('button', { name: 'Review conflicts' }).click();
  await sv.page.getByRole('button', { name: /Farmer served twice/ }).click();
  // The day is closed, so the loss is already in the closing: no write-off offered.
  await sv.page.getByText('Already on the server').waitFor();
  assert.strictEqual(await sv.page.getByText('Record as missing stock').count(), 0);
  await sv.page.getByRole('img', { name: /photo/ }).first().waitFor();
  await sv.page.waitForFunction(() => [...document.querySelectorAll('.photo-grid img')].some((i) => i.complete && i.naturalWidth > 0), null, { timeout: 15000 });
  await sv.page.getByText('Reviewed, nothing to record').click();
  assert.ok(await sv.page.getByRole('button', { name: 'Resolve' }).isDisabled(), 'notes are required');
  await sv.page.getByLabel('Notes').fill('Duplicate at Baiwalla, loss recorded by the officer before closing.');
  await sv.page.screenshot({ animations: 'disabled', path: `${E}/shots/11-review.png`, fullPage: true });
  await sv.page.getByRole('button', { name: 'Resolve' }).click();
  await sv.page.getByText('Nothing to review').waitFor();
  await sv.page.getByRole('button', { name: 'Resolved', exact: true }).click();
  await sv.page.getByText('Reviewed, nothing to record').waitFor();
  await sv.page.screenshot({ animations: 'disabled', path: `${E}/shots/12-resolved.png` });

  // ---- Another account on phone 1: the officer's data doesn't stay ----------------------
  // Everything is sent, so the officer can sign out; the supervisor then signs in on the
  // same phone and must not see the officer's farmer list (or its card key).
  await page.goto(`${base}/pod-field/#/`);
  await page.getByRole('heading', { name: 'Your distributions' }).waitFor();
  await page.getByRole('button', { name: 'Sign out' }).click();
  await page.getByLabel('Email or phone').waitFor(); // signed out
  await signIn(page, 'supervisor@example.org');
  await page.getByText('Nothing on this phone yet.').waitFor();
  assert.strictEqual(await page.evaluate(() => new Promise((resolve) => {
    const r = indexedDB.open('pod-field');
    r.onsuccess = () => { const q = r.result.transaction('bundles').objectStore('bundles').count(); q.onsuccess = () => resolve(q.result); };
  })), 0, 'no distribution left from the officer');

  // ---- What the server holds ----------------------------------------------------------
  event = (await apiGet(`pod/events/${seed.event}`)).event;
  assert.strictEqual(event.status, 'closed');
  assert.strictEqual(event.reconciliation.balanced, true);
  assert.strictEqual(event.seal_hash.length, 64);
  const handovers = (await apiGet(`pod/events/${seed.event}/handovers`)).handovers;
  const levels = Object.fromEntries(handovers.map((h) => [h.beneficiary.name, [h.identity_level, h.outcome]]));
  assert.deepStrictEqual(levels, { 'Aminata Kamara': ['L3', 'delivered'], 'Isatu Koroma': ['L2', 'partial'], 'Musa Bangura': ['L1', 'delivered'] });
  const conflicts = (await apiGet(`pod/events/${seed.event}/conflicts`)).conflicts;
  assert.deepStrictEqual(conflicts.map((c) => [c.error, c.status, c.resolution]), [['already_collected', 'resolved', 'reviewed']]);
  const media = await apiGet(`pod/events/${seed.event}/media-status`);
  assert.deepStrictEqual([media.expected, media.uploaded, media.missing.length], [7, 7, 0]);
  const report = await apiGet(`pod/events/${seed.event}/report`);
  assert.strictEqual(report.discrepancies.length, 3);
  assert.strictEqual(report.witnesses.close[0].name, 'Chief Musa Kallon');

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
      await p.screenshot({ path: `${E}/shots/fail-${i + 1}.png`, fullPage: true });
      console.error(`--- page ${i + 1}: ` + (await p.locator('#app').innerText({ timeout: 2000 })).slice(0, 800));
    } catch (x) {}
  }
  if (errors.length) console.error('Browser errors:\n' + errors.join('\n'));
  process.exit(1);
});
