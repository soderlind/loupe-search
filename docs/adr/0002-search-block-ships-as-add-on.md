# Ship the search block as a separate add-on plugin

## Status

accepted

## Context

Issue #71 asks for a richer search block (live results, post-type/taxonomy
facets, sort order). Loupe Search's README promises that the plugin is
engine-only and "intentionally does not ship a front-end search block/shortcode
UI"; custom UIs are expected to query the REST API.

## Decision

The block ships as a separate add-on plugin that talks to Loupe Search only
through its public REST API, the same way Loupe Cross-Site Search ships its
block. Loupe Search itself stays engine-only.

## Considered Options

- **Bundle the block in Loupe Search**: rejected. It breaks the engine-only
  promise and ties front-end release cadence and asset weight to the engine.
- **Generalize the Cross-Site Search block to single sites**: rejected. That
  block is shaped around the multisite hub endpoint and `blog_id` facet, and
  would make single-site users install a multisite plugin.

## Consequences

- Everything the block needs (facets, sorting, snippets) must be reachable via
  the REST API; gaps are fixed in the API, not by private coupling.
