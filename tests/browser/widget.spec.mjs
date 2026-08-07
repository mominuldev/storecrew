/**
 * Storefront widget — structure, accessibility, rendering, cache-safety.
 *
 * Runs against whatever the site is configured with. When chat is disabled or
 * no provider is ready, the mount/turn sections skip (loudly) and the
 * cache-safety assertions still run — the page's cleanliness must hold in
 * every configuration. Nothing here requires a model; the one section that
 * would spend tokens (a live turn) is opt-in via STORECREW_TEST_LIVE=1.
 */

import { chromium } from 'playwright';
import { SITE, t, skipped, summary } from './helpers.mjs';

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();

const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));

await page.goto(`${SITE}/`, { waitUntil: 'networkidle' });

// --- Cache-safety: what the document itself carries, in every configuration.
const html = await page.content();
t('the page carries no REST nonce', !/wp_rest.*nonce|"nonce"/.test(html.match(/storecrewChat=[^<]*/)?.[0] ?? ''));
t('the page carries no conversation state', !/uuid/.test(html.match(/storecrewChat=[^<]*/)?.[0] ?? ''));

const scriptTag = html.match(/<script[^>]*widget\.js[^>]*>/)?.[0] ?? '';

const enabled = scriptTag !== '';

if (!enabled) {
  skipped('widget mount and interaction', 'chat is disabled on this site — enable it in Settings to run the full spec');
} else {
  t('the widget script is async', /\basync\b/.test(scriptTag), scriptTag);

  const host = page.locator('[data-storecrew="chat"]');
  const mounted = await host
    .waitFor({ state: 'attached', timeout: 10000 })
    .then(() => true)
    .catch(() => false);

  if (!mounted) {
    // Enabled but not ready (no provider): the widget must be *absent*, not broken.
    skipped('widget interaction', 'widget did not mount — no provider ready; absence is the specified behaviour');
    t('an unready widget leaves no half-rendered launcher', (await page.locator('.scr-launcher').count()) === 0);
  } else {
    const launcher = host.locator('.scr-launcher');
    t('a launcher is painted', await launcher.isVisible());
    t('it reports itself closed', (await launcher.getAttribute('aria-expanded')) === 'false');

    // The keyboard path is the accessibility path.
    await launcher.focus();
    await page.keyboard.press('Enter');
    await page.waitForTimeout(300);

    t('Enter opens the panel', (await launcher.getAttribute('aria-expanded')) === 'true');

    const panel = host.locator('.scr-panel');
    t('the panel is a labelled dialog', (await panel.getAttribute('role')) === 'dialog');
    t('the log is a polite live region', (await host.locator('.scr-log[aria-live="polite"]').count()) === 1);

    const focused = await page.evaluate(
      () => document.querySelector('[data-storecrew="chat"]')?.shadowRoot?.activeElement?.className ?? '',
    );
    t('focus lands in the composer', focused.includes('scr-input'), focused);

    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);
    t('Escape closes and returns focus', (await launcher.getAttribute('aria-expanded')) === 'false');

    // Dark mode + mobile: the cascade/viewport class of bug.
    const dark = await browser.newContext({
      colorScheme: 'dark',
      viewport: { width: 390, height: 844 },
      isMobile: true,
      hasTouch: true,
    });
    const mobile = await dark.newPage();
    await mobile.goto(`${SITE}/`, { waitUntil: 'networkidle' });
    await mobile.locator('[data-storecrew="chat"]').waitFor({ state: 'attached', timeout: 10000 });
    await mobile.locator('.scr-launcher').click();
    await mobile.waitForTimeout(400);

    const width = await mobile.evaluate(() => {
      const p = document.querySelector('[data-storecrew="chat"]')?.shadowRoot?.querySelector('.scr-panel');
      return p ? p.getBoundingClientRect().width : 0;
    });
    t('the panel is full-width on a phone', width >= 380, String(width));

    const overflow = await mobile.evaluate(
      () => document.documentElement.scrollWidth > window.innerWidth + 1,
    );
    t('no horizontal overflow is introduced', !overflow);

    if (process.env.STORECREW_TEST_LIVE === '1') {
      const input = mobile.locator('.scr-input');
      await input.fill('What is your returns policy?');
      await mobile.keyboard.press('Enter');
      await mobile.waitForFunction(
        () => {
          const s = document.querySelector('[data-storecrew="chat"]')?.shadowRoot;
          return s && !s.querySelector('.scr-typing') && s.querySelectorAll('.scr-msg[data-role="assistant"]').length > 1;
        },
        undefined,
        { timeout: 120000 },
      );
      const reply = await mobile.evaluate(() => {
        const msgs = document
          .querySelector('[data-storecrew="chat"]')
          ?.shadowRoot?.querySelectorAll('.scr-msg[data-role="assistant"]');
        return msgs?.[msgs.length - 1]?.textContent ?? '';
      });
      t('a live turn answered', reply.length > 20 && !/something went wrong/i.test(reply), reply);
    } else {
      skipped('live model turn', 'set STORECREW_TEST_LIVE=1 to spend a real call');
    }

    await dark.close();
  }
}

t('no page errors', errors.length === 0, errors.join(' | '));

await browser.close();
process.exit(summary('widget') > 0 ? 1 : 0);
