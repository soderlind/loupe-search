# Defer plugin auto-updates to WordPress core

## Status

accepted

## Context

WP Loupe previously shipped `WP_Loupe_Auto_Update`, which hooked the core
`auto_update_plugin` filter to force auto-updates ON by default (opt-out via a
`wp_loupe_auto_update_enabled` option or the `WP_LOUPE_DISABLE_AUTO_UPDATE`
constant). This dated from when the plugin self-updated from GitHub.

Now that WP Loupe is distributed through the WordPress.org directory, every
install gets WordPress core's native per-plugin "Enable auto-updates" toggle on
the Plugins screen. The custom filter *overrode* that native choice, forcing
auto-updates on regardless of what the user selected — surprising and intrusive.

## Decision

Remove `WP_Loupe_Auto_Update`, the `wp_loupe_auto_update_enabled` option, the
`WP_LOUPE_DISABLE_AUTO_UPDATE` constant, and the "Plugin Updates" settings
section. Auto-update behavior is owned entirely by WordPress core's native UI.

## Consequences

- One fewer class, option, and settings section; the Advanced/Search Behavior
  tab is simpler.
- The `test_auto_update_*` tests are removed.
- Site owners who want a code-level opt-out use core's `auto_update_plugin`
  filter directly.
