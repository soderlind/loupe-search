# Search insights stores daily totals, never individual searches

## Status

accepted

## Context

Issue #70 asks for small-scale search analytics (top queries, zero-result
queries, average result count) with strict data protection. Visitors sometimes
type personal data into search boxes, and a per-search log is personal data
under most privacy regimes.

## Decision

Search insights is opt-in and off by default. It stores only daily totals per
normalized query (search count, zero-result count, summed result count) in a
custom WordPress table. No per-search rows, IP addresses, user IDs, user agents,
or sub-day timestamps are ever written. Queries over 100 characters or that look
like an email address or phone number are discarded before counting. Totals are
purged after a configurable retention period (default 90 days).

## Consequences

- Questions that need individual searches (sessions, refinements, click-through,
  time-of-day) cannot be answered, and cannot be backfilled later.
- The privacy story fits in one sentence, and the plugin can suggest privacy
  policy text without caveats.
