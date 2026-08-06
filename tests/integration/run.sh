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

for scenario in pro-without-free free-without-woo free-with-old-woo pro-api-too-new pro-api-too-old; do
	run "guard: ${scenario}" test-guards.php "${scenario}"
done

echo "------------------------------------------------------------"
if [ "${failures}" -eq 0 ]; then
	echo "All integration probes passed."
	exit 0
fi

echo "${failures} scenario(s) failed."
exit 1
