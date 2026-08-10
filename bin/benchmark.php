<?php
/**
 * Loupe Search engine benchmark.
 *
 * Runs a suite of representative queries against WP_Loupe_Search_Engine and
 * reports wall-clock latency (min / median / p95 / max / avg) per scenario,
 * alongside the Loupe-reported processing time and hit counts.
 *
 * Caching is disabled for the run so every iteration exercises the engine.
 *
 * Usage (from the plugin directory, against a running Local site):
 *
 *   wp --url=http://plugins.local/loupe/ eval-file bin/benchmark.php
 *
 * Configuration via environment variables:
 *   BENCH_ITER    Iterations per scenario (default 50).
 *   BENCH_WARMUP  Warmup iterations, not measured (default 5).
 *   BENCH_JSON    If set to a writable path, results are also written as JSON.
 *
 * @package Soderlind\Plugin\WPLoupe
 */

namespace Soderlind\Plugin\WPLoupe;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must be run through WP-CLI (wp eval-file).\n" );
	exit( 1 );
}

if ( ! class_exists( __NAMESPACE__ . '\\WP_Loupe_Search_Engine' ) ) {
	\WP_CLI::error( 'Loupe Search is not active on this site.' );
}

$iterations = max( 1, (int) ( getenv( 'BENCH_ITER' ) ?: 50 ) );
$warmup     = max( 0, (int) ( getenv( 'BENCH_WARMUP' ) ?: 5 ) );
$json_path  = getenv( 'BENCH_JSON' ) ?: '';

// Disable the result cache so every iteration hits the engine.
add_filter( 'loupe_search_max_cacheable_query_length', '__return_zero', 999 );

$post_types = WP_Loupe_Utils::get_indexed_post_types();
$engine     = new WP_Loupe_Search_Engine( $post_types );

// Report index readiness up front so empty results are explained.
$ready = [];
foreach ( $post_types as $pt ) {
	$status       = $engine->is_index_ready( $pt );
	$ready[ $pt ] = ! empty( $status['ready'] );
}

\WP_CLI::log( '== Loupe Search benchmark ==' );
\WP_CLI::log( sprintf( 'Post types : %s', implode( ', ', $post_types ) ) );
\WP_CLI::log( sprintf( 'Index ready: %s', implode( ', ', array_map(
	static function ( $pt ) use ( $ready ) {
		return $pt . '=' . ( $ready[ $pt ] ? 'yes' : 'NO' );
	},
	$post_types
) ) ) );
\WP_CLI::log( sprintf( 'Iterations : %d (warmup %d)', $iterations, $warmup ) );
\WP_CLI::log( '' );

/**
 * Scenario definitions.
 *
 * Each scenario is one of:
 *   - simple:   runs $engine->search( $query )
 *   - advanced: runs $engine->search_advanced( $query, $options )
 *
 * Filter/sort/facet fields must be allowlisted in Settings; the engine catches
 * per-post-type errors, so a field missing on one post type is tolerated.
 */
$scenarios = [
	[
		'name'  => 'simple: single common term',
		'type'  => 'simple',
		'query' => 'lorem',
	],
	[
		'name'  => 'simple: two terms',
		'type'  => 'simple',
		'query' => 'lorem ipsum',
	],
	[
		'name'  => 'simple: exact phrase',
		'type'  => 'simple',
		'query' => '"lorem ipsum"',
	],
	[
		'name'  => 'simple: typo tolerance',
		'type'  => 'simple',
		'query' => 'loerm ipzum',
	],
	[
		'name'  => 'simple: rarer term',
		'type'  => 'simple',
		'query' => 'consectetur',
	],
	[
		'name'    => 'advanced: filter by author',
		'type'    => 'advanced',
		'query'   => 'lorem',
		'options' => [ 'filter' => 'post_author = 1', 'limit' => 20 ],
	],
	[
		'name'    => 'advanced: sort by title',
		'type'    => 'advanced',
		'query'   => 'lorem',
		'options' => [ 'sort' => [ 'post_title:asc' ], 'limit' => 20 ],
	],
	[
		'name'    => 'advanced: terms facet',
		'type'    => 'advanced',
		'query'   => 'lorem',
		'options' => [ 'facets' => [ 'taxonomy_category' ], 'limit' => 20 ],
	],
	[
		'name'    => 'advanced: highlight title',
		'type'    => 'advanced',
		'query'   => 'lorem',
		'options' => [
			'attributesToRetrieve'  => [ 'id', 'post_title' ],
			'attributesToHighlight' => [ 'post_title' ],
			'limit'                 => 20,
		],
	],
];

/**
 * Compute percentile from a sorted list of values.
 *
 * @param float[] $sorted Ascending list of milliseconds.
 * @param float   $pct    Percentile 0..100.
 * @return float
 */
$percentile = static function ( array $sorted, $pct ) {
	$n = count( $sorted );
	if ( 0 === $n ) {
		return 0.0;
	}
	if ( 1 === $n ) {
		return $sorted[0];
	}
	$rank  = ( $pct / 100 ) * ( $n - 1 );
	$low   = (int) floor( $rank );
	$high  = (int) ceil( $rank );
	$frac  = $rank - $low;
	return $sorted[ $low ] + ( $sorted[ $high ] - $sorted[ $low ] ) * $frac;
};

/**
 * Run one scenario and return timing stats.
 *
 * @return array<string,mixed>
 */
$run_scenario = static function ( array $scenario ) use ( $engine, $iterations, $warmup, $percentile ) {
	$is_advanced = ( ( $scenario['type'] ?? 'simple' ) === 'advanced' );
	$query       = (string) $scenario['query'];
	$options     = isset( $scenario['options'] ) && is_array( $scenario['options'] ) ? $scenario['options'] : [];

	$invoke = static function () use ( $engine, $is_advanced, $query, $options ) {
		if ( $is_advanced ) {
			return $engine->search_advanced( $query, $options );
		}
		return $engine->search( $query );
	};

	// Warmup: opens indexes, primes OpCache/PHP, not measured.
	$last_hits = 0;
	for ( $i = 0; $i < $warmup; $i++ ) {
		$invoke();
	}

	$samples = [];
	for ( $i = 0; $i < $iterations; $i++ ) {
		$start  = hrtime( true );
		$result = $invoke();
		$elapsed = ( hrtime( true ) - $start ) / 1e6; // ns -> ms
		$samples[] = $elapsed;

		if ( $is_advanced ) {
			$last_hits = isset( $result['totalHits'] ) ? (int) $result['totalHits'] : ( is_array( $result['hits'] ?? null ) ? count( $result['hits'] ) : 0 );
		} else {
			$last_hits = is_array( $result ) ? count( $result ) : 0;
		}
	}

	sort( $samples );
	$sum = array_sum( $samples );

	return [
		'name'   => $scenario['name'],
		'hits'   => $last_hits,
		'min'    => $samples[0],
		'median' => $percentile( $samples, 50 ),
		'p95'    => $percentile( $samples, 95 ),
		'max'    => end( $samples ),
		'avg'    => $sum / count( $samples ),
		'ops'    => $sum > 0 ? ( count( $samples ) * 1000 / $sum ) : 0.0,
	];
};

$results = [];
foreach ( $scenarios as $scenario ) {
	$results[] = $run_scenario( $scenario );
}

// Tabular report.
$rows = [];
foreach ( $results as $r ) {
	$rows[] = [
		'scenario'  => $r['name'],
		'hits'      => (string) $r['hits'],
		'min (ms)'  => number_format( $r['min'], 2 ),
		'med (ms)'  => number_format( $r['median'], 2 ),
		'p95 (ms)'  => number_format( $r['p95'], 2 ),
		'max (ms)'  => number_format( $r['max'], 2 ),
		'avg (ms)'  => number_format( $r['avg'], 2 ),
		'ops/s'     => number_format( $r['ops'], 1 ),
	];
}

if ( class_exists( '\\WP_CLI\\Utils' ) || function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
	\WP_CLI\Utils\format_items(
		'table',
		$rows,
		[ 'scenario', 'hits', 'min (ms)', 'med (ms)', 'p95 (ms)', 'max (ms)', 'avg (ms)', 'ops/s' ]
	);
} else {
	foreach ( $rows as $row ) {
		\WP_CLI::log( implode( ' | ', $row ) );
	}
}

// --- Comparison: Loupe engine vs default WordPress (MySQL) search ---
//
// Under WP-CLI, Loupe's posts_pre_query hook is not registered, so a raw
// WP_Query with `s` runs core's default LIKE-based search. That makes this an
// apples-to-apples "same box, same data" comparison. Only the plain-text
// queries are compared; WordPress core search has no filter/facet/sort/typo
// equivalent to the advanced scenarios.
//
// The single word "lorem" is intentionally excluded: core's LIKE '%lorem%'
// matches the substring inside "dolorem"/"doloremque", so its hit count is
// inflated versus Loupe's whole-word token match and the two are not comparable.
$compare_queries = [ 'lorem ipsum', '"lorem ipsum"', 'loerm ipzum', 'consectetur' ];

/**
 * Time a callable over warmup + iterations and return stats.
 *
 * @param callable $fn Returns an int hit count.
 * @return array<string,float|int>
 */
$time_callable = static function ( callable $fn ) use ( $iterations, $warmup, $percentile ) {
	for ( $i = 0; $i < $warmup; $i++ ) {
		$fn();
	}
	$samples = [];
	$hits    = 0;
	for ( $i = 0; $i < $iterations; $i++ ) {
		$start     = hrtime( true );
		$hits      = (int) $fn();
		$samples[] = ( hrtime( true ) - $start ) / 1e6;
	}
	sort( $samples );
	$sum = array_sum( $samples );
	return [
		'hits'   => $hits,
		'avg'    => $sum / count( $samples ),
		'median' => $percentile( $samples, 50 ),
		'p95'    => $percentile( $samples, 95 ),
	];
};

$compare = [];
foreach ( $compare_queries as $q ) {
	$loupe = $time_callable( static function () use ( $engine, $q ) {
		return count( $engine->search( $q ) );
	} );
	$wp = $time_callable( static function () use ( $q, $post_types ) {
		$wp_query = new \WP_Query( [
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			's'                      => $q,
			'posts_per_page'         => 1000,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'cache_results'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );
		return is_array( $wp_query->posts ) ? count( $wp_query->posts ) : 0;
	} );
	$speedup   = $loupe['avg'] > 0 ? ( $wp['avg'] / $loupe['avg'] ) : 0.0;
	$compare[] = [
		'query'      => $q,
		'loupe_hits' => $loupe['hits'],
		'wp_hits'    => $wp['hits'],
		'loupe_avg'  => $loupe['avg'],
		'wp_avg'     => $wp['avg'],
		'loupe_p95'  => $loupe['p95'],
		'wp_p95'     => $wp['p95'],
		'speedup'    => $speedup,
	];
}

\WP_CLI::log( '' );
\WP_CLI::log( '== Loupe engine vs default WordPress (MySQL LIKE) search ==' );
$cmp_rows = [];
foreach ( $compare as $c ) {
	$cmp_rows[] = [
		'query'          => $c['query'],
		'loupe hits'     => (string) $c['loupe_hits'],
		'wp hits'        => (string) $c['wp_hits'],
		'loupe avg (ms)' => number_format( $c['loupe_avg'], 2 ),
		'wp avg (ms)'    => number_format( $c['wp_avg'], 2 ),
		'loupe p95 (ms)' => number_format( $c['loupe_p95'], 2 ),
		'wp p95 (ms)'    => number_format( $c['wp_p95'], 2 ),
		'speedup'        => number_format( $c['speedup'], 1 ) . '×',
	];
}
if ( function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
	\WP_CLI\Utils\format_items(
		'table',
		$cmp_rows,
		[ 'query', 'loupe hits', 'wp hits', 'loupe avg (ms)', 'wp avg (ms)', 'loupe p95 (ms)', 'wp p95 (ms)', 'speedup' ]
	);
} else {
	foreach ( $cmp_rows as $row ) {
		\WP_CLI::log( implode( ' | ', $row ) );
	}
}
\WP_CLI::log( 'Note: hit counts differ because Loupe is typo-tolerant and relevance-ranked; core search is exact substring (LIKE).' );

if ( '' !== $json_path ) {
	$payload = [
		'generated_at' => gmdate( 'c' ),
		'site'         => home_url(),
		'post_types'   => $post_types,
		'index_ready'  => $ready,
		'iterations'   => $iterations,
		'warmup'       => $warmup,
		'results'      => $results,
		'comparison'   => $compare,
	];
	if ( false !== file_put_contents( $json_path, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
		\WP_CLI::log( '' );
		\WP_CLI::log( 'JSON written to ' . $json_path );
	} else {
		\WP_CLI::warning( 'Could not write JSON to ' . $json_path );
	}
}

\WP_CLI::log( '' );
\WP_CLI::success( 'Benchmark complete.' );
