/**
 * Shared harness for the browser suites.
 *
 * These exist because two whole bug classes are invisible to the PHP suites:
 * cascade fights with wp-admin (layer order beats specificity, and the failure
 * only shows in dark mode) and cookie/cache behaviour on the storefront. Both
 * were found by hand in a real browser; this directory is what makes those
 * findings repeatable (G4-D3).
 *
 * Configuration by environment:
 *   STORECREW_TEST_URL   Site root       (default http://wpproduct.test)
 *   STORECREW_TEST_USER  wp-admin login  (admin spec skips without it)
 *   STORECREW_TEST_PASS  wp-admin password
 */

export const SITE = (process.env.STORECREW_TEST_URL ?? 'http://wpproduct.test').replace(/\/$/, '');

let pass = 0;
let fail = 0;
let skip = 0;

export const t = (label, ok, detail = '') => {
  if (ok) {
    pass += 1;
    console.log(`  PASS  ${label}`);
  } else {
    fail += 1;
    console.log(`  FAIL  ${label}${detail ? ' — ' + String(detail).slice(0, 200) : ''}`);
  }
};

export const skipped = (label, why) => {
  skip += 1;
  console.log(`  SKIP  ${label} — ${why}`);
};

export const summary = (suite) => {
  console.log('-'.repeat(60));
  console.log(`${suite}: ${pass} passed, ${fail} failed${skip ? `, ${skip} skipped` : ''}`);

  return fail;
};

/**
 * Sign in to wp-admin through the real login form.
 *
 * Returns false (rather than throwing) when credentials are absent or wrong,
 * so the admin spec can skip loudly instead of failing mysteriously.
 */
export async function login(page) {
  const user = process.env.STORECREW_TEST_USER;
  const pass_ = process.env.STORECREW_TEST_PASS;

  if (!user || !pass_) {
    return false;
  }

  await page.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass_);
  await page.click('#wp-submit');
  await page.waitForLoadState('domcontentloaded');

  return !page.url().includes('wp-login.php');
}

/** The widget's shadow root accessor, evaluated in the page. */
export const inWidget = (page, fn, arg) =>
  page.evaluate(
    ({ body, arg: a }) => {
      const shadow = document.querySelector('[data-storecrew="chat"]')?.shadowRoot;
      // eslint-disable-next-line no-new-func
      return new Function('shadow', 'arg', `return (${body})(shadow, arg);`)(shadow, a);
    },
    { body: fn.toString(), arg },
  );
