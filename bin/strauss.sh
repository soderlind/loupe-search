#!/usr/bin/env bash
# Scope bundled Composer dependencies into vendor-prefixed/ with Strauss so that
# Loupe and its Symfony/Doctrine/PSR libraries cannot collide with copies bundled
# by other active plugins. Run after `composer install`.
set -euo pipefail

cd "$(dirname "$0")/.."

STRAUSS_VERSION="0.30.0"
# SHA-256 of the official strauss.phar for STRAUSS_VERSION. The PHAR is executed
# with access to the build workspace, so verify its integrity before running it
# to guard against a tampered or swapped release asset.
STRAUSS_SHA256="08c1a8e553594745c22294e158129005fd11ed09ed452d7d4f48566f38c66c96"
PHAR="bin/strauss.phar"
ELD="vendor/nitotm/efficient-language-detector"
ELD_PREFIXED="vendor-prefixed/nitotm/efficient-language-detector"

sha256_file() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{print $1}'
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 "$1" | awk '{print $1}'
	else
		echo "Error: neither sha256sum nor shasum is available to verify $1" >&2
		exit 1
	fi
}

if [ ! -f "$PHAR" ]; then
	echo "Downloading Strauss ${STRAUSS_VERSION}…"
	curl -fsSL -o "$PHAR" \
		"https://github.com/BrianHenryIE/strauss/releases/download/${STRAUSS_VERSION}/strauss.phar"
fi

# Verify the PHAR every run (a cached copy could have been tampered with too).
ACTUAL_SHA256="$(sha256_file "$PHAR")"
if [ "$ACTUAL_SHA256" != "$STRAUSS_SHA256" ]; then
	echo "Error: checksum mismatch for $PHAR" >&2
	echo "  expected: $STRAUSS_SHA256" >&2
	echo "  actual:   $ACTUAL_SHA256" >&2
	rm -f "$PHAR"
	exit 1
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

# Loupe hashes the ELD version via the scoped Composer\InstalledVersions. ELD is
# left unscoped, so register it in the scoped installed.php; otherwise
# getVersion() throws "Package ... is not installed" during search/reindex.
php bin/register-eld-version.php

