#!/usr/bin/env bash
#
# Boot-and-handshake probes for the StoreCrew plugin pair.
#
# These run outside WordPress against a minimal hook/option shim, so they are
# fast enough to run on every change. They cover the seam between the two
# plugins — the part that has no unit-test surface because it only exists once
# both plugins are loaded in the same process.
#
# Every guard here is probe-tested: the suite deliberately violates each rule
# and asserts it fires. A guard that has never been seen to fail is not a guard.

set -uo pipefail

cd "$(dirname "$0")"

failures=0

run() {
	local label="$1"
	shift
	echo "=== ${label} ==="
	if ! php "$@" 2>&1 | sed 's/^/  /'; then
		failures=$((failures + 1))
	fi
	# php exits non-zero on assertion failure; capture it through the pipe.
	if [ "${PIPESTATUS[0]:-0}" -ne 0 ]; then
		failures=$((failures + 1))
	fi
	echo
}

run "boot + handshake + entitlement" test-boot.php

# The same boot against the built distribution, when one has been assembled.
# Skipped loudly rather than silently: the dist is a different artifact — a
# --no-dev autoloader, no tests, no composer.lock — and "it works in the repo"
# has never been evidence about the zip.
# tests/integration -> tests -> storecrew -> plugins, the same three levels
# test-boot.php walks. Two levels lands inside the plugin and silently skips.
DIST="$(cd ../../.. && pwd)/storecrew-dist"

if [ -d "$DIST" ]; then
	echo "=== boot: the built dist ==="
	if ! STORECREW_FREE_DIR="$DIST" php test-boot.php 2>&1 | sed 's/^/  /'; then
		failures=$((failures + 1))
	fi
	if [ "${PIPESTATUS[0]:-0}" -ne 0 ]; then
		failures=$((failures + 1))
	fi
	echo
else
	echo "=== boot: the built dist — SKIPPED (run tools/build-dist.sh first) ==="
	echo
fi

for scenario in pro-without-free free-without-woo free-with-old-woo pro-api-too-new pro-api-too-old pro-uninstall pro-i18n; do
	run "guard: ${scenario}" test-guards.php "${scenario}"
done

echo "------------------------------------------------------------"
if [ "${failures}" -eq 0 ]; then
	echo "All integration probes passed."
	exit 0
fi

echo "${failures} scenario(s) failed."
exit 1
