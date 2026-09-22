#!/usr/bin/env bash
# Scope bundled Composer dependencies into vendor-prefixed/ with Strauss so that
# Loupe and its Symfony/Doctrine/PSR libraries cannot collide with copies bundled
# by other active plugins. Run after `composer install`.
set -euo pipefail

cd "$(dirname "$0")/.."

STRAUSS_VERSION="0.30.0"
PHAR="bin/strauss.phar"
ELD="vendor/nitotm/efficient-language-detector"
ELD_PREFIXED="vendor-prefixed/nitotm/efficient-language-detector"

if [ ! -f "$PHAR" ]; then
	echo "Downloading Strauss ${STRAUSS_VERSION}…"
	curl -fsSL -o "$PHAR" \
		"https://github.com/BrianHenryIE/strauss/releases/download/${STRAUSS_VERSION}/strauss.phar"
fi

# Strauss tokenises every file to build its symbol table. The bundled language
# detector ships 60+ MB of ngram data arrays (no PHP symbols) that exhaust
# memory, so move its resources aside for the scan and relocate the runtime data
# into the scoped package afterwards.
STASH=""
if [ -d "$ELD/resources" ]; then
	STASH="$(mktemp -d)"
	mv "$ELD/resources" "$STASH/resources"
fi

# If Strauss fails before deleting the original package, put the detector
# resources back so the working tree is left intact.
cleanup() {
	if [ -n "${STASH:-}" ] && [ -d "$STASH/resources" ] && [ -d "$ELD" ]; then
		mv "$STASH/resources" "$ELD/resources"
	fi
	[ -n "${STASH:-}" ] && rm -rf "$STASH" 2>/dev/null || true
}
trap cleanup EXIT

php -d memory_limit=1G "$PHAR"

# Strauss has copied the detector's PHP source into vendor-prefixed/ and removed
# the original from vendor/. Place its runtime data (LARGE preset, ISO639_1
# schemes) into the scoped package: ELD resolves these via __DIR__/../resources,
# so they must live alongside the prefixed source. `cp` does not tokenise, so the
# large files are safe to copy here.
if [ -n "${STASH:-}" ] && [ -d "$ELD_PREFIXED" ] && [ -d "$STASH/resources" ]; then
	cp -R "$STASH/resources" "$ELD_PREFIXED/resources"
	# Drop unused presets to keep the payload lean (mirrors .distignore).
	NG="$ELD_PREFIXED/resources/ngrams"
	rm -f \
		"$NG"/extralarge.php "$NG"/large.php "$NG"/medium.php "$NG"/small.php \
		"$NG"/blob/extralarge.* "$NG"/blob/medium.* "$NG"/blob/small.* 2>/dev/null || true
fi

