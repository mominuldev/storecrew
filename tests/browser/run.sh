#!/usr/bin/env bash
#
# Browser suites (G4-D3). Plain-node Playwright scripts, no test framework —
# the same PASS/FAIL discipline as tests/schema, in a browser.
#
#   STORECREW_TEST_URL    site root        (default http://wpproduct.test)
#   STORECREW_TEST_USER   wp-admin login   (admin spec skips without it)
#   STORECREW_TEST_PASS   wp-admin password
#   STORECREW_TEST_LIVE=1 include the one section that spends a model call
#
set -u

cd "$(dirname "$0")/../.."

if ! node -e "import('playwright')" 2>/dev/null; then
  echo "playwright is not installed — run: npm install"
  exit 1
fi

status=0

# Pure-logic probe (no browser): the SSE assembler's buffered==streamed
# equivalence (R-TECH-02). Runs first because it needs neither a site nor a key.
node tests/browser/sse.spec.mjs || status=1
echo
node tests/browser/widget.spec.mjs || status=1
echo
node tests/browser/admin.spec.mjs || status=1

exit $status
