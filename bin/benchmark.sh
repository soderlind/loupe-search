#!/usr/bin/env bash
#
# Loupe Search benchmark orchestrator.
#
# Seeds content with WP-CLI, reindexes, then runs the engine benchmark
# (bin/benchmark.php). Optionally runs an end-to-end HTTP benchmark against
# the REST search endpoint.
#
# Examples:
#   bin/benchmark.sh --url=http://plugins.local/loupe/
#   bin/benchmark.sh --url=http://plugins.local/loupe/ --count=2000 --iter=100
#   bin/benchmark.sh --url=http://plugins.local/loupe/ --skip-seed
#   bin/benchmark.sh --url=http://plugins.local/loupe/ --http
#   bin/benchmark.sh --url=http://plugins.local/loupe/ --http --rate=4m
#   bin/benchmark.sh --url=http://plugins.local/loupe/ --compare-frontend
#
# Front-end comparison (--compare-frontend):
#   Times GET ?s=lorem with Loupe active, then deactivates loupe-search
#   (network-wide) so core MySQL search runs, times it again, and reactivates.
#   Reactivation is guaranteed via a trap. Toggles a network-active plugin, so
#   run only against a disposable/dev site.
#
# HTTP bandwidth throttling:
#   --rate=<value> caps the HTTP transfer rate via curl --limit-rate (e.g.
#   4m for a normal 4G/broadband connection, 1600k for Fast 3G). Only affects
#   the --http benchmark; the engine benchmark runs in-process without network.
#
# WP-CLI binary:
#   By default the script calls `wp`. Set WP_CLI to use a wrapper, e.g. the
#   Local by Flywheel wrapper:
#     WP_CLI="bash /path/to/wp-cli-local/scripts/wp" bin/benchmark.sh --url=...
#
set -euo pipefail

URL=""
COUNT=500
POST_TYPES="post"
ITER=50
WARMUP=5
SKIP_SEED=0
DO_HTTP=0
JSON_OUT=""
RATE=""
DO_FE=0

for arg in "$@"; do
	case "$arg" in
		--url=*)        URL="${arg#*=}" ;;
		--count=*)      COUNT="${arg#*=}" ;;
		--post-types=*) POST_TYPES="${arg#*=}" ;;
		--iter=*)       ITER="${arg#*=}" ;;
		--warmup=*)     WARMUP="${arg#*=}" ;;
		--json=*)       JSON_OUT="${arg#*=}" ;;
		--rate=*)       RATE="${arg#*=}" ;;
		--skip-seed)    SKIP_SEED=1 ;;
		--http)         DO_HTTP=1 ;;
		--compare-frontend) DO_FE=1 ;;
		-h|--help)
			grep '^#' "$0" | sed 's/^# \{0,1\}//'
			exit 0 ;;
		*)
			echo "Unknown argument: $arg" >&2
			exit 1 ;;
	esac
done

if [[ -z "$URL" ]]; then
	echo "Error: --url is required (e.g. --url=http://plugins.local/loupe/)" >&2
	exit 1
fi

# WP-CLI invocation. WP_CLI may contain arguments (e.g. "bash .../wp").
WP_BIN="${WP_CLI:-wp}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# shellcheck disable=SC2086
wp() { $WP_BIN --url="$URL" "$@"; }

echo "== Loupe Search benchmark =="
echo "Site        : $URL"
echo "Seed count  : $COUNT (post types: $POST_TYPES)"
echo "Iterations  : $ITER (warmup $WARMUP)"
echo

if [[ "$SKIP_SEED" -eq 0 ]]; then
	IFS=',' read -r -a TYPES <<< "$POST_TYPES"
	for pt in "${TYPES[@]}"; do
		pt="$(echo "$pt" | tr -d '[:space:]')"
		[[ -z "$pt" ]] && continue
		echo "Seeding $COUNT '$pt' posts…"
		wp post generate --count="$COUNT" --post_type="$pt" --post_status=publish
	done
	echo
	echo "Reindexing…"
	wp loupe-search reindex
	echo
else
	echo "Skipping seed + reindex (--skip-seed)."
	echo
fi

echo "Running engine benchmark…"
if [[ -n "$JSON_OUT" ]]; then
	BENCH_ITER="$ITER" BENCH_WARMUP="$WARMUP" BENCH_JSON="$JSON_OUT" \
		wp eval-file "$PLUGIN_DIR/bin/benchmark.php"
else
	BENCH_ITER="$ITER" BENCH_WARMUP="$WARMUP" \
		wp eval-file "$PLUGIN_DIR/bin/benchmark.php"
fi

if [[ "$DO_HTTP" -eq 1 ]]; then
	echo
	echo "Running HTTP benchmark (POST /wp-json/loupe-search/v1/search)…"
	ENDPOINT="${URL%/}/wp-json/loupe-search/v1/search"
	RATE_ARGS=()
	if [[ -n "$RATE" ]]; then
		RATE_ARGS=(--limit-rate "$RATE")
		echo "Bandwidth throttle: $RATE"
	fi
	# Warmup.
	curl -s -o /dev/null "${RATE_ARGS[@]}" -H 'Content-Type: application/json' \
		-d '{"q":"lorem"}' "$ENDPOINT" || true
	total=0
	runs=20
	for _ in $(seq "$runs"); do
		t=$(curl -s -o /dev/null -w '%{time_total}' "${RATE_ARGS[@]}" \
			-H 'Content-Type: application/json' \
			-d '{"q":"lorem","page":{"number":1,"size":10}}' "$ENDPOINT")
		# Convert seconds to ms and accumulate with awk for float safety.
		total=$(awk -v a="$total" -v b="$t" 'BEGIN { printf "%.6f", a + b * 1000 }')
	done
	awk -v tot="$total" -v n="$runs" \
		'BEGIN { printf "HTTP avg over %d requests: %.2f ms/request\n", n, tot / n }'
fi

if [[ "$DO_FE" -eq 1 ]]; then
	echo
	echo "Front-end search comparison (GET ?s=lorem): Loupe vs default WordPress…"
	FE_URL="${URL%/}/?s=lorem"
	fe_runs=20

	# Average GET wall time in ms over N runs (after one warmup).
	http_avg_get() {
		local u="$1" n="$2" total=0 t
		curl -s -o /dev/null "$u" || true
		for _ in $(seq "$n"); do
			t=$(curl -s -o /dev/null -w '%{time_total}' "$u")
			total=$(awk -v a="$total" -v b="$t" 'BEGIN { printf "%.6f", a + b * 1000 }')
		done
		awk -v tot="$total" -v n="$n" 'BEGIN { printf "%.2f", tot / n }'
	}

	loupe_ms=$(http_avg_get "$FE_URL" "$fe_runs")
	echo "  Loupe active      : ${loupe_ms} ms/request"

	# Toggle the network-active plugin; always reactivate, even on error/interrupt.
	reactivate_loupe() { wp plugin activate loupe-search --network >/dev/null 2>&1 || true; }
	trap reactivate_loupe EXIT INT TERM
	echo "  Deactivating loupe-search (network-wide)…"
	wp plugin deactivate loupe-search --network >/dev/null

	default_ms=$(http_avg_get "$FE_URL" "$fe_runs")
	echo "  Default WP search : ${default_ms} ms/request"

	echo "  Reactivating loupe-search…"
	wp plugin activate loupe-search --network >/dev/null
	trap - EXIT INT TERM

	awk -v l="$loupe_ms" -v d="$default_ms" \
		'BEGIN { if (l > 0) printf "  Ratio (default/Loupe): %.2f×  (>1 = Loupe faster)\n", d / l }'
	echo "  Note: full page render dominates this number; the search engine is only part of it."
fi

echo
echo "Done."
