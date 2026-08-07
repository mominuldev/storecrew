/**
 * Admin console — every screen renders, in both themes, without console noise.
 *
 * This is the regression guard for the wp-admin cascade class of bug: the
 * failure 06 § 2.4 documents is invisible in light mode, so every screen is
 * walked in dark mode too, and any console error anywhere fails the run.
 *
 * Needs STORECREW_TEST_USER / STORECREW_TEST_PASS; skips loudly without them.
 */

import { chromium } from 'playwright';
import { SITE, t, skipped, summary, login } from './helpers.mjs';

const SCREENS = [
  ['#/', 'Overview'],
  ['#/crew', 'Crew'],
  ['#/knowledge', 'Knowledge'],
  ['#/inbox', 'Inbox'],
  ['#/settings', 'Settings'],
  ['#/setup', 'Setup'],
];

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });

const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));
page.on('console', (m) => {
  if (m.type() === 'error') errors.push(m.text());
});

if (!(await login(page))) {
  skipped('admin console', 'set STORECREW_TEST_USER and STORECREW_TEST_PASS to run this spec');
  await browser.close();
  process.exit(summary('admin') > 0 ? 1 : 0);
}

await page.goto(`${SITE}/wp-admin/admin.php?page=storecrew`, { waitUntil: 'networkidle' });

t('the app mounts', (await page.locator('#storecrew-root > *').count()) > 0);

for (const [hash, name] of SCREENS) {
  await page.goto(`${SITE}/wp-admin/admin.php?page=storecrew${hash}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);

  const text = (await page.locator('#storecrew-root').textContent()) ?? '';
  t(`${name} renders content`, text.trim().length > 40, text.slice(0, 80));
  t(`${name} shows no raw error boundary`, !/could not start/i.test(text), text.slice(0, 120));
}

// The dark-mode walk — where cascade defeats hide.
const toggle = page.locator('#storecrew-root button[aria-label*="theme"]');
t('the theme toggle exists', (await toggle.count()) === 1);
await toggle.click();
await page.waitForTimeout(200);

const darkApplied = await page.evaluate(() => document.documentElement.classList.contains('dark'));

for (const [hash, name] of SCREENS) {
  await page.goto(`${SITE}/wp-admin/admin.php?page=storecrew${hash}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(300);

  // The cascade-defeat signature: wp-admin's unlayered body colour bleeding
  // through means our text colour did not win. Sample the app's own text.
  const colour = await page.evaluate(() => {
    const el = document.querySelector('#storecrew-root p, #storecrew-root span');
    return el ? getComputedStyle(el).color : '';
  });

  t(`${name} text is not wp-admin's default in ${darkApplied ? 'dark' : 'light'} mode`, colour !== 'rgb(60, 67, 74)', colour);
}

// The setup flow (FR-ADMIN-02). Each step is a disclosure button, and its
// control only exists once opened — a step whose panel never expands is a step
// a merchant cannot complete, and nothing in PHP can see that.
await page.goto(`${SITE}/wp-admin/admin.php?page=storecrew#/setup`, { waitUntil: 'networkidle' });
await page.waitForTimeout(500);

const stepHeaders = page.locator('#storecrew-root button[aria-expanded]');
t('the setup flow shows all five steps', (await stepHeaders.count()) === 5, String(await stepHeaders.count()));

const setupText = (await page.locator('#storecrew-root').textContent()) ?? '';
t(
  'the setup flow names the five steps in order',
  ['Connect an AI provider', 'Choose what the crew reads', 'Let the crew read your store', 'Say who is on duty', 'Put the crew on your storefront']
    .every((title) => setupText.includes(title)),
  setupText.slice(0, 160),
);

// Open the first step and check its control actually appeared. The panel is
// conditionally rendered, so a broken step is an empty card, not an error.
await stepHeaders.first().click();
await page.waitForTimeout(500);
t(
  'opening a step reveals its control',
  (await page.locator('#storecrew-root input[type="password"]').count()) > 0,
);

// The cost expectation is the BYO-key mitigation (02 § 5.3) and has to be on
// the key step itself, not buried in settings.
t(
  'the key step sets the cost expectation',
  /spend a few dollars a month/i.test((await page.locator('#storecrew-root').textContent()) ?? ''),
);

// Approval arguments must never render as raw JSON (11 § 3.4, G4-D2).
await page.goto(`${SITE}/wp-admin/admin.php?page=storecrew#/inbox`, { waitUntil: 'networkidle' });
await page.waitForTimeout(400);
const inbox = (await page.locator('#storecrew-root').textContent()) ?? '';
t('the Inbox shows no raw JSON braces', !/\{"/.test(inbox), inbox.slice(0, 120));
t('the Inbox has the human queue section', /Waiting for a human/i.test(inbox));

t('no console errors across both themes', errors.length === 0, errors.join(' | ').slice(0, 300));

await browser.close();
process.exit(summary('admin') > 0 ? 1 : 0);
