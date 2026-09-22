<?php
/**
 * Register the (unscoped) nitotm/efficient-language-detector package in the
 * scoped Composer metadata.
 *
 * ELD is intentionally left unscoped: its 60+ MB ngram data makes prefixing
 * impractical, and its `Nitotm\Eld` namespace is collision-safe, so the scoped
 * Loupe still references `\Nitotm\Eld\*` from the root vendor/. Loupe, however,
 * hashes the ELD version through the *scoped* `Composer\InstalledVersions`,
 * which only knows the packages Strauss moved into vendor-prefixed/. Without a
 * nitotm entry there, `InstalledVersions::getVersion()` throws
 * "Package nitotm/efficient-language-detector is not installed", which surfaces
 * as a search/reindex failure. This inserts a minimal nitotm entry into the
 * scoped installed.php so the lookup resolves. Idempotent.
 *
 * Run from the plugin root: `php bin/register-eld-version.php`.
 */

$root_dir    = dirname( __DIR__ );
$scoped_file = $root_dir . '/vendor-prefixed/composer/installed.php';
$root_file   = $root_dir . '/vendor/composer/installed.php';
$package     = 'nitotm/efficient-language-detector';

if ( ! is_file( $scoped_file ) || ! is_file( $root_file ) ) {
	fwrite( STDERR, "register-eld-version: installed.php not found, skipping.\n" );
	exit( 0 );
}

$scoped = file_get_contents( $scoped_file );
if ( false === $scoped ) {
	fwrite( STDERR, "register-eld-version: cannot read scoped installed.php.\n" );
	exit( 1 );
}

if ( false !== strpos( $scoped, $package ) ) {
	// Already registered (e.g. Strauss scoped ELD, or a previous run added it).
	exit( 0 );
}

$root = require $root_file;
if ( ! isset( $root['versions'][ $package ] ) ) {
	fwrite( STDERR, "register-eld-version: {$package} absent from root installed.php, skipping.\n" );
	exit( 0 );
}

$entry           = $root['versions'][ $package ];
$pretty_version  = $entry['pretty_version'] ?? 'dev';
$version         = $entry['version'] ?? '0.0.0.0';
$reference       = $entry['reference'] ?? null;
$type            = $entry['type'] ?? 'library';

$block  = '    ' . var_export( $package, true ) . " => \n";
$block .= "    array (\n";
$block .= '      ' . var_export( 'pretty_version', true ) . ' => ' . var_export( $pretty_version, true ) . ",\n";
$block .= '      ' . var_export( 'version', true ) . ' => ' . var_export( $version, true ) . ",\n";
$block .= '      ' . var_export( 'reference', true ) . ' => ' . var_export( $reference, true ) . ",\n";
$block .= '      ' . var_export( 'type', true ) . ' => ' . var_export( $type, true ) . ",\n";
// ELD ships unscoped in the root vendor/; path is relative to vendor-prefixed/composer/.
$block .= "      'install_path' => __DIR__ . '/../../vendor/nitotm/efficient-language-detector',\n";
$block .= "      'aliases' => \n";
$block .= "      array (\n";
$block .= "      ),\n";
$block .= "      'dev_requirement' => false,\n";
$block .= "    ),\n";

$count   = 0;
$scoped  = preg_replace_callback(
	"/('versions' =>\s*\n\s*array \(\n)/",
	static function ( $m ) use ( $block ) {
		return $m[1] . $block;
	},
	$scoped,
	1,
	$count
);

if ( 1 !== $count ) {
	fwrite( STDERR, "register-eld-version: could not locate versions array in scoped installed.php.\n" );
	exit( 1 );
}

if ( false === file_put_contents( $scoped_file, $scoped ) ) {
	fwrite( STDERR, "register-eld-version: failed to write scoped installed.php.\n" );
	exit( 1 );
}

// Fail loudly if the rewritten file is not valid PHP.
$check = shell_exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $scoped_file ) . ' 2>&1' );
if ( ! is_string( $check ) || false === strpos( $check, 'No syntax errors' ) ) {
	fwrite( STDERR, "register-eld-version: rewritten installed.php failed lint:\n{$check}\n" );
	exit( 1 );
}

echo "register-eld-version: registered {$package} {$pretty_version} in scoped installed.php\n";
