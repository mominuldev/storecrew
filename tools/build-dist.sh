#!/usr/bin/env bash
#
# Assemble the WordPress.org distribution, exactly as it will be submitted.
#
#   tools/build-dist.sh [target-dir]
#
# Defaults to ../storecrew-dist. Everything the merchant downloads is here and
# nothing else: src/, the built assets/, languages/, readme.txt, storecrew.php,
# uninstall.php, composer.json, and a --no-dev vendor/.
#
# This exists because every claim about the plugin that matters at submission —
# Plugin Check clean, no dev files shipped, the right size — is a claim about
# the *dist*, and the dist was previously assembled by hand from a comment in
# .distignore. A verification you cannot repeat is a verification you cannot
# trust the second time.
#
# It never touches the working tree's vendor/: `composer install --no-dev`
# there would delete phpstan and phpcs, and `composer check` would stop working
# with no obvious cause. The copy gets its own install.

set -euo pipefail

cd "$(dirname "$0")/.."
SOURCE="$(pwd)"
TARGET="${1:-$(cd .. && pwd)/storecrew-dist}"

if [ -z "${SKIP_BUILD:-}" ]; then
	echo "==> Building front-end assets from current source"
	npm run build
else
	echo "==> SKIP_BUILD set — shipping assets/ as they stand"
fi

echo "==> Assembling $TARGET"
rm -rf "$TARGET"
mkdir -p "$TARGET"

# .distignore is written for `wp dist-archive`: comments, blank lines, and
# paths that are root-relative when they start with a slash. rsync reads
# --exclude patterns the same way relative to the transfer root, so the file
# converts directly and stays the single source of truth for what ships.
EXCLUDES="$(mktemp)"
trap 'rm -f "$EXCLUDES"' EXIT
grep -v -e '^\s*#' -e '^\s*$' .distignore > "$EXCLUDES"

# composer.lock is excluded from the dist but required to install from, so it
# rides along and is deleted after vendor/ is built.
rsync -a --exclude-from="$EXCLUDES" --exclude='/vendor' \
	--include='/composer.lock' \
	"$SOURCE/" "$TARGET/"

cp composer.json composer.lock "$TARGET/"

echo "==> Installing production dependencies"
( cd "$TARGET" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )

rm -f "$TARGET/composer.lock"

echo
echo "==> Contents"
( cd "$TARGET" && find . -maxdepth 1 -mindepth 1 | sed 's|^\./|  |' | sort )

echo
printf '  %s files, %s\n' \
	"$(find "$TARGET" -type f | wc -l | tr -d ' ')" \
	"$(du -sh "$TARGET" | cut -f1)"

echo
echo "Verify it, do not assume it:"
echo "  wp plugin check $(basename "$TARGET") --slug=storecrew"
