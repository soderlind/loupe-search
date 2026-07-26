<?php
// Hook-related WP function shims.
//
// IMPORTANT: This file must be included only after Patchwork is loaded,
// otherwise Brain Monkey cannot redefine these functions.

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) { /* no-op */ }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) { /* no-op */ }
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $tag ) {
		// For tests we never actually fire hooks, return 0.
		return 0;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) { /* no-op */ }
}
if ( ! function_exists( 'apply_filters_deprecated' ) ) {
	function apply_filters_deprecated( $tag, $args, $version = '', $replacement = '', $message = '' ) {
		// Tests never fire hooks; return the filtered value unchanged.
		return is_array( $args ) ? ( $args[0] ?? null ) : $args;
	}
}
