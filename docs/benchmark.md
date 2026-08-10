# Loupe Search benchmark

Performance of the Loupe Search engine versus WordPress core search, measured
with the harness in [`bin/benchmark.sh`](../bin/benchmark.sh) and
[`bin/benchmark.php`](../bin/benchmark.php).

## TL;DR

- At real scale Loupe is **faster on every query** and the gap widens with the
  corpus: the front-end search page went from parity at ~600 docs to **1.58×
  faster** at ~55,600 docs.
- Core search cost is **flat (~15 ms) regardless of the term** — it is a
  `LIKE '%term%'` full-table scan. Loupe scales with result fanout instead, so
  its advantage grows as content grows.
- Loupe also returns results core cannot: the typo query `loerm ipzum` returns
  75 hits in Loupe and **0** in core.

## How to run

```sh
# WP_CLI can point at any wp binary or wrapper (Local by Flywheel shown here).
WP_CLI="bash /path/to/wp-cli-local/scripts/wp" \
  bin/benchmark.sh --url=http://plugins.local/loupe/ --count=5000 --iter=20 --compare-frontend
```

Useful flags: `--skip-seed`, `--http`, `--rate=<limit>` (throttle HTTP transfer),
`--json=<path>`, `--count`, `--iter`, `--warmup`, `--post-types`.

## Methodology

- **Engine benchmark** — times `WP_Loupe_Search_Engine::search()` /
  `search_advanced()` in-process via `wp eval-file`. The result cache is
  disabled for the run (`loupe_search_max_cacheable_query_length` → 0), so every
  iteration exercises the engine. Latency is wall-clock (`hrtime`) over N
  iterations after a warmup; min / median / p95 / max / avg are reported.
- **Engine vs core** — under WP-CLI, Loupe's `posts_pre_query` hook is not
  registered, so a raw `WP_Query` with `s=` runs core's default MySQL
  `LIKE`-based search. Same box, same data, same process.
- **Front-end** — times `GET ?s=lorem` with Loupe active, deactivates
  `loupe-search` network-wide so core search runs, times it again, then
  reactivates (guaranteed by a shell trap). This includes full page render, so
  the engine is only part of the number.

## Environment

- WordPress multisite on Local by Flywheel, subsite `blog_id=4`
  (`plugins.local/loupe/`), `loupe-search` 1.2.6, SQLite-backed index.
- Indexed post types: `post`, `page`, `book`.
- Content: pre-existing FakerPress "latin" posts/pages plus `wp post generate`
  posts (empty content, titles like `Post N`).
- 20 iterations, 5 warmup per scenario. Numbers vary run-to-run by a few percent.

## Engine latency by scenario (avg ms)

| scenario | ~600 docs | ~5,600 docs | ~55,600 docs |
| --- | --- | --- | --- |
| single common term | 1.12 | 1.26 | 5.45 |
| two terms | 2.68 | 3.62 | 9.91 |
| exact phrase | 1.46 | 1.64 | 1.27 |
| typo tolerance | 2.72 | 2.97 | 10.19 |
| rarer term | 3.04 | 2.12 | 3.69 |
| filter by author | 1.61 | 2.52 | 10.64 |
| sort by title | 0.68 | 0.79 | 3.86 |
| terms facet | 1.93 | 2.73 | 12.65 |
| highlight title | 2.37 | 4.02 | 19.60 |

## Loupe engine vs core MySQL LIKE

Avg ms per query; speedup is `core ÷ loupe` (>1 = Loupe faster).

### ~600 docs

| query | loupe hits | core hits | loupe avg | core avg | speedup |
| --- | --- | --- | --- | --- | --- |
| lorem ipsum | 90 | 73 | 2.63 | 2.54 | 1.0× |
| "lorem ipsum" | 0 | 0 | 1.38 | 2.15 | 1.6× |
| loerm ipzum | 75 | 0 | 2.63 | 2.02 | 0.8× |
| consectetur | 67 | 71 | 2.07 | 1.18 | 0.6× |

### ~5,600 docs

| query | loupe hits | core hits | loupe avg | core avg | speedup |
| --- | --- | --- | --- | --- | --- |
| lorem ipsum | 90 | 73 | 3.21 | 4.00 | 1.2× |
| "lorem ipsum" | 0 | 0 | 1.46 | 3.32 | 2.3× |
| loerm ipzum | 75 | 0 | 3.01 | 3.76 | 1.2× |
| consectetur | 67 | 71 | 2.20 | 2.47 | 1.1× |

### ~55,600 docs

| query | loupe hits | core hits | loupe avg | core avg | speedup |
| --- | --- | --- | --- | --- | --- |
| lorem ipsum | 90 | 73 | 10.17 | 16.20 | 1.6× |
| "lorem ipsum" | 0 | 0 | 1.98 | 16.09 | 8.1× |
| loerm ipzum | 75 | 0 | 9.87 | 16.24 | 1.6× |
| consectetur | 67 | 71 | 3.82 | 15.12 | 4.0× |

Core search is essentially flat at ~15 ms for every query — the cost of a full
`LIKE '%term%'` scan — while Loupe scales with result fanout.

## Front-end search (`GET ?s=lorem`, full page render)

| corpus | Loupe active | Loupe off (core) | ratio |
| --- | --- | --- | --- |
| ~600 docs | 45.42 ms | 46.16 ms | 1.02× |
| ~5,600 docs | 45.49 ms | 60.97 ms | 1.34× |
| ~55,600 docs | 41.97 ms | 66.21 ms | 1.58× |

## Why the single word "lorem" is excluded

The single-word `lorem` query is left out of the comparison above because its
hit counts are not comparable: core reports 90, Loupe reports 2.

Measured on the corpus:

```text
lorem: substring=88   whole-word=1   contain-dolorem=87
```

The Faker "latin" content contains the word **`dolorem`** (and `doloremque`) in
87 docs, but almost never the word `lorem`.

- Core runs `LIKE '%lorem%'`, a blind **substring** match, so `do`**`lorem`** and
  `do`**`lorem`**`que` both match → 90 hits, ~88 of them false positives.
- Loupe matches the **token** `lorem`, which is a whole word in only ~1–2 docs →
  2 hits. This is more correct, not a miss.

Because the two engines are counting different things, comparing their latency
on `lorem` is meaningless, so the row is dropped.

The two-term `lorem ipsum` query is OR-based; `ipsum` is a real word in ~77 docs,
so the union is ~90 real word matches — kept because both engines agree on
scope. For the cleanest per-row speed comparison, use `consectetur` (a real
whole word in ~71 docs for both engines: 4.0× at ~55,600 docs), or seed real
prose by piping text into `wp post generate --post_content` so query terms are
whole words in both engines.
