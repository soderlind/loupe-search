<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * Settings page.
 *
 * @package  soderlind\plugin\WPLoupe
 */

if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

/**
 * Settings page.
 * 
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.0.11
 */
class WPLoupe_Settings_Page {

	/**
	 * Custom post types.
	 *
	 * @var array
	 */
	private $cpt = [];

	/**
	 * WPLoupe_Settings_Page constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'wp_loupe_create_settings' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'wp_loupe_setup_sections' ] );
		add_action( 'admin_init', [ $this, 'wp_loupe_setup_fields' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'load-settings_page_wp-loupe', [ $this, 'add_help_tabs' ] );
	}

	// (Removed corrupted meta key handling.)

	/**
	 * Retrieve post meta keys that have non-empty values for a post type.
	 *
	 * @param string $post_type
	 * @return array meta_key => true if it has at least one non-empty value.
	 */
	private function get_post_type_meta_keys_with_values( $post_type ) {
		global $wpdb;

		if ( ! post_type_exists( $post_type ) ) {
			return [];
		}

		// Query distinct meta keys for published posts of this post type with non-empty values.
		// Avoid protected keys (leading underscore) in results.
		$like_protected = $wpdb->esc_like( '_' ) . '%';
		$sql            = $wpdb->prepare(
			"SELECT DISTINCT pm.meta_key
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = %s AND p.post_status = 'publish'
			   AND pm.meta_key NOT LIKE %s
			   AND pm.meta_value <> ''
			 LIMIT 500",
			$post_type,
			$like_protected
		);

		$keys = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is output of $wpdb->prepare().
		if ( ! is_array( $keys ) ) {
			return [];
		}

		$out = [];
		foreach ( $keys as $k ) {
			// Basic validation: skip excessively long keys.
			if ( is_string( $k ) && strlen( $k ) < 128 ) {
				$out[ $k ] = true;
			}
		}

		return $out;
	}

	/**
	 * Convert meta key to readable label
	 * 
	 * @param string $key
	 * @return string
	 */
	private function prettify_meta_key( $key ) {
		return ucwords( str_replace( [ '_', '-' ], ' ', $key ) );
	}

	/**
	 * Get core WordPress fields that should always be available in the UI
	 * 
	 * @return array Associative array of field_key => true
	 */
	private function get_core_fields() {
		return [
			'post_title'    => true,
			'post_content'  => true,
			'post_excerpt'  => true,
			'post_date'     => true,
			'post_modified' => true,
			'post_author'   => true,
			'permalink'     => true,
		];
	}

	/**
	 * Get the available fields for a given post type.
	 *
	 * This consolidates:
	 * 1. Core WordPress fields (always available regardless of indexing status)
	 * 2. The current schema-derived fields (baseline + any saved indexable fields)
	 * 3. Public post meta keys that have at least one non-empty value
	 *
	 * Returning an associative array keyed by field name lets callers simply
	 * use isset( $available_fields[ $field_key ] ) to validate a saved field.
	 *
	 * @param string $post_type
	 * @return array field_key => true
	 */
	private function get_available_fields( $post_type ) {
		$available = [];

		// Start with core fields that should always be available
		$available = $this->get_core_fields();

		// Add schema fields (these reflect saved indexable configuration + baseline)
		if ( class_exists( __NAMESPACE__ . '\\WP_Loupe_Schema_Manager' ) ) {
			$schema_manager = WP_Loupe_Schema_Manager::get_instance();
			$schema         = $schema_manager->get_schema_for_post_type( $post_type );
			foreach ( $schema as $field_name => $_settings ) {
				$available[ $field_name ] = true;
			}
		}

		// Augment with discovered meta keys that have values
		$meta_keys = $this->get_post_type_meta_keys_with_values( $post_type );
		if ( ! empty( $meta_keys ) ) {
			foreach ( $meta_keys as $meta_key => $_ ) {
				if ( ! isset( $available[ $meta_key ] ) ) {
					$available[ $meta_key ] = true;
				}
			}
		}

		return $available;
	}

	/**
	 * Create the settings page.
	 *
	 * @return void
	 */
	public function wp_loupe_create_settings() {
		add_options_page( 'WP Loupe', 'WP Loupe', 'manage_options', 'wp-loupe', [ $this, 'plugin_settings_page_content' ] );
	}

	/**
	 * Setup the settings sections.
	 *
	 * @return void
	 */
	public function wp_loupe_setup_sections() {
		// General tab sections
		add_settings_section( 'wp_loupe_section', 'WP Loupe Settings', [ $this, 'general_section_callback' ], 'wp-loupe' );
		add_settings_section( 'wp_loupe_fields_section', 'Field Settings', [ $this, 'fields_section_callback' ], 'wp-loupe' );

		// Advanced tab sections
		add_settings_section( 'wp_loupe_tokenization_section', __( 'Tokenization', 'wp-loupe' ),
			[ $this, 'tokenization_section_callback' ], 'wp-loupe-advanced' );
		add_settings_section( 'wp_loupe_prefix_section', __( 'Prefix Search', 'wp-loupe' ),
			[ $this, 'prefix_section_callback' ], 'wp-loupe-advanced' );
		add_settings_section( 'wp_loupe_typo_section', __( 'Typo Tolerance', 'wp-loupe' ),
			[ $this, 'typo_section_callback' ], 'wp-loupe-advanced' );
		add_settings_section( 'wp_loupe_updates_section', __( 'Plugin Updates', 'wp-loupe' ),
			function () {
				echo '<p>' . esc_html__( 'Control automatic update behavior for WP Loupe.', 'wp-loupe' ) . '</p>';
			}, 'wp-loupe-advanced' );
	}

	/**
	 * General settings section description
	 */
	public function general_section_callback() {
		echo '<p>' . esc_html__( 'Select which post types and fields to include in the search index.', 'wp-loupe' ) . '</p>';
	}

	/**
	 * Tokenization section description
	 */
	public function tokenization_section_callback() {
		echo '<p>' . esc_html__( 'Configure how search terms are tokenized.', 'wp-loupe' ) . '</p>';
	}

	/**
	 * Prefix search section description
	 */
	public function prefix_section_callback() {
		echo '<p>' . esc_html__( 'Configure prefix search behavior. Prefix search allows finding terms by typing only the beginning (e.g., "huck" finds "huckleberry"). Prefix search is only performed on the last word in a search query. Prior words must be typed out fully to get accurate results. E.g. my friend huck would find documents containing huckleberry - huck is my friend, however, would not.', 'wp-loupe' ) . '</p>';
	}

	/**
	 * Typo tolerance section description
	 */
	public function typo_section_callback() {
		echo '<p>' . esc_html__( 'Configure typo tolerance for search queries. Typo tolerance allows finding results even when users make typing mistakes.', 'wp-loupe' ) . '</p>';
		echo wp_kses_post( '<p><small>' . sprintf(
			/* translators: %s: link to the research paper on efficient similarity search */
			__( 'Based on the algorithm from "Efficient Similarity Search in Very Large String Sets" %s.', 'wp-loupe' ),
			'<a href="https://hpi.de/fileadmin/user_upload/fachgebiete/naumann/publications/PDFs/2012_ICDE_p1586-fenz.pdf" target="_blank">' . esc_html__( '(read the paper)', 'wp-loupe' ) . '</a>'
		) . '</small></p>' );
	}

	/**
	 * Fields configuration section description
	 */
	public function fields_section_callback() {
		echo '<div id="wp-loupe-fields-config"></div>';
	}

	/**
	 * Setup the settings fields.
	 *
	 * @return void
	 */
	public function wp_loupe_setup_fields() {
		$this->cpt = array_diff( get_post_types(
			[
				'public' => true,
			],
			'names',
			'and'
		), [ 'attachment' ] );

		add_settings_field(
			'wp_loupe_post_type_field',
			__( 'Select Post Types', 'wp-loupe' ),
			[ $this, 'wp_loupe_post_type_field_callback' ],
			'wp-loupe',
			'wp_loupe_section'
		);

		// Advanced tab fields (tokenization)
		add_settings_field(
			'wp_loupe_max_query_tokens',
			__( 'Max Query Tokens', 'wp-loupe' ),
			[ $this, 'number_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_tokenization_section',
			[
				'name'        => 'wp_loupe_advanced[max_query_tokens]',
				'value'       => $this->get_advanced_option( 'max_query_tokens', 12 ),
				'description' => __( 'Maximum number of tokens processed in a search query.', 'wp-loupe' ),
			]
		);

		// Prefix search settings
		add_settings_field(
			'wp_loupe_min_prefix_length',
			__( 'Minimum Prefix Length', 'wp-loupe' ),
			[ $this, 'number_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_prefix_section',
			[
				'name'        => 'wp_loupe_advanced[min_prefix_length]',
				'value'       => $this->get_advanced_option( 'min_prefix_length', 3 ),
				'description' => __( 'Minimum characters before prefix search activates.', 'wp-loupe' ),
			]
		);

		// Typo tolerance settings
		add_settings_field(
			'wp_loupe_typo_enabled',
			__( 'Enable Typo Tolerance', 'wp-loupe' ),
			[ $this, 'checkbox_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_typo_section',
			[
				'name'        => 'wp_loupe_advanced[typo_enabled]',
				'value'       => $this->get_advanced_option( 'typo_enabled', true ),
				'description' => __( 'Allow search to return results with minor spelling mistakes.', 'wp-loupe' ),
			]
		);

		add_settings_field(
			'wp_loupe_alphabet_size',
			__( 'Alphabet Size', 'wp-loupe' ),
			[ $this, 'number_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_typo_section',
			[
				'name'        => 'wp_loupe_advanced[alphabet_size]',
				'value'       => $this->get_advanced_option( 'alphabet_size', 4 ),
				'description' => __( 'Size of internal alphabet used for typo tolerance.', 'wp-loupe' ),
			]
		);

		add_settings_field(
			'wp_loupe_index_length',
			__( 'Index Length', 'wp-loupe' ),
			[ $this, 'number_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_typo_section',
			[
				'name'        => 'wp_loupe_advanced[index_length]',
				'value'       => $this->get_advanced_option( 'index_length', 14 ),
				'description' => __( 'Internal index length; affects accuracy vs. size.', 'wp-loupe' ),
			]
		);

		add_settings_field(
			'wp_loupe_typo_prefix_search',
			__( 'Typo Tolerance for Prefix Search', 'wp-loupe' ),
			[ $this, 'checkbox_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_typo_section',
			[
				'name'        => 'wp_loupe_advanced[typo_prefix_search]',
				'value'       => $this->get_advanced_option( 'typo_prefix_search', false ),
				'description' => __( 'Allow typos when matching prefix (can slow searches).', 'wp-loupe' ),
			]
		);

		add_settings_field(
			'wp_loupe_first_char_typo_double',
			__( 'Double Count First Character Typo', 'wp-loupe' ),
			[ $this, 'checkbox_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_typo_section',
			[
				'name'        => 'wp_loupe_advanced[first_char_typo_double]',
				'value'       => $this->get_advanced_option( 'first_char_typo_double', true ),
				'description' => __( 'Treat a typo at the start of a word as two mistakes.', 'wp-loupe' ),
			]
		);

		// Advanced tab: auto update moved here (page wp-loupe-advanced)
		add_settings_field(
			'wp_loupe_auto_update_enabled',
			__( 'Automatic Plugin Updates', 'wp-loupe' ),
			[ $this, 'checkbox_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_updates_section',
			[
				'name'        => 'wp_loupe_auto_update_enabled',
				'value'       => (bool) get_option( 'wp_loupe_auto_update_enabled', true ),
				'description' => __( 'Automatically install new versions of WP Loupe when available.', 'wp-loupe' ),
			]
		);
	}

	/**
	 * Get advanced option with default
	 */
	private function get_advanced_option( $key, $default ) {
		$options = get_option( 'wp_loupe_advanced', [] );
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}

	/**
	 * Callback for number input fields
	 */
	public function number_field_callback( $args ) {
		printf(
			'<input type="number" name="%s" value="%s" class="regular-text">
			<p class="description">%s</p>',
			esc_attr( $args[ 'name' ] ),
			esc_attr( $args[ 'value' ] ),
			esc_html( $args[ 'description' ] )
		);
	}

	/**
	 * Callback for checkbox fields
	 */
	public function checkbox_field_callback( $args ) {
		printf(
			'<input type="checkbox" name="%s" %s>
			<p class="description">%s</p>',
			esc_attr( $args[ 'name' ] ),
			checked( $args[ 'value' ], true, false ),
			esc_html( $args[ 'description' ] )
		);
	}

	/**
	 * Sanitize advanced settings
	 */
	public function sanitize_advanced_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$sanitized = [];

		// Sanitize numeric fields
		$numeric_fields = [ 'max_query_tokens', 'min_prefix_length', 'alphabet_size', 'index_length' ];
		foreach ( $numeric_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$sanitized[ $field ] = absint( $input[ $field ] );
			}
		}

		// Sanitize boolean fields
		$boolean_fields = [ 'typo_enabled', 'typo_prefix_search', 'first_char_typo_double' ];
		foreach ( $boolean_fields as $field ) {
			$sanitized[ $field ] = ! empty( $input[ $field ] );
		}

		return $sanitized;
	}

	/**
	 * Callback for the post type field.
	 *
	 * @return void
	 */
	public function wp_loupe_post_type_field_callback() {
		$options      = get_option( 'wp_loupe_custom_post_types', [] );
		$selected_ids = ! empty( $options ) && isset( $options[ 'wp_loupe_post_type_field' ] )
			? (array) $options[ 'wp_loupe_post_type_field' ]
			: [ 'post', 'page' ]; // Default selection

		echo '<select id="wp_loupe_custom_post_types" name="wp_loupe_custom_post_types[wp_loupe_post_type_field][]" multiple>';
		foreach ( $this->cpt as $post_type ) {
			echo sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $post_type ),
				selected( in_array( $post_type, $selected_ids, true ), true, false ),
				esc_html( $post_type )
			);
		}
		echo '</select>';
	}

	/**
	 * Settings page content.
	 *
	 * @return void
	 */
	public function plugin_settings_page_content() {
		// Check if user is allowed access.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_tab = isset( $_GET[ 'tab' ] ) ? sanitize_key( $_GET[ 'tab' ] ) : 'general';

		?>
		<div class="wrap">
			<h2><?php echo esc_html( get_admin_page_title() ); ?></h2>
			<p class="description" style="margin-top:0;">
				<?php echo esc_html( WP_Loupe_Utils::get_requirements_diagnostic_line() ); ?>
			</p>

			<nav class="nav-tab-wrapper">
				<a href="?page=wp-loupe" class="nav-tab <?php echo $current_tab === 'general' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'General', 'wp-loupe' ); ?>
				</a>
				<a href="?page=wp-loupe&tab=advanced"
					class="nav-tab <?php echo $current_tab === 'advanced' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Advanced', 'wp-loupe' ); ?>
				</a>
			</nav>

			<form action="options.php" method="POST">
				<?php
				wp_nonce_field( 'wp_loupe_nonce_action', 'wp_loupe_nonce_field' );

				if ( $current_tab === 'advanced' ) {
					settings_fields( 'wp-loupe-advanced' );
					do_settings_sections( 'wp-loupe-advanced' );
				} else {
					settings_fields( 'wp-loupe' );
					do_settings_sections( 'wp-loupe' );
				}

				submit_button( __( 'Save Settings', 'wp-loupe' ) );
				if ( $current_tab === 'general' ) {
					echo '<button type="button" class="button button-secondary" id="wp-loupe-reindex-button" style="margin-left:8px;">' . esc_html__( 'Reindex', 'wp-loupe' ) . '</button>';
					echo '<p class="description" style="max-width:800px;">' . esc_html__( 'Reindex runs in small batches to avoid request timeouts. Save settings first, then click Reindex.', 'wp-loupe' ) . '</p>';
				}
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Register all settings
	 */
	public function register_settings() {
		// General settings group
		register_setting( 'wp-loupe', 'wp_loupe_custom_post_types', [
			'sanitize_callback' => [ $this, 'sanitize_post_types_setting' ],
		] );
		register_setting( 'wp-loupe', 'wp_loupe_fields', [
			'type'              => 'array',
			'description'       => 'Field configuration for each post type',
			'sanitize_callback' => [ $this, 'sanitize_fields_settings' ],
		] );

		// Advanced settings group
		register_setting( 'wp-loupe-advanced', 'wp_loupe_advanced', [
			'type'              => 'array',
			'description'       => 'Advanced search configuration options',
			'sanitize_callback' => [ $this, 'sanitize_advanced_settings' ],
		] );

		// Auto-update option registered with advanced group (field rendered in Advanced tab)
		register_setting( 'wp-loupe-advanced', 'wp_loupe_auto_update_enabled', [
			'type'              => 'boolean',
			'description'       => 'Enable or disable automatic WP Loupe plugin updates',
			'sanitize_callback' => function ( $value ) {
				return (bool) $value;
			},
			'default'           => true,
		] );
	}

	/**
	 * Sanitize and validate field settings
	 * 
	 * @param array $input
	 * @return array
	 */
	public function sanitize_fields_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$sanitized = [];
		foreach ( $input as $post_type => $fields ) {
			if ( ! is_array( $fields ) )
				continue;

			foreach ( $fields as $field_key => $settings ) {
				// Only include the field if it's explicitly marked as indexable
				if ( ! empty( $settings[ 'indexable' ] ) ) {
					$sanitized[ $post_type ][ $field_key ] = [
						'indexable'      => true,
						'weight'         => isset( $settings[ 'weight' ] ) ?
							floatval( $settings[ 'weight' ] ) : 1.0,
						'filterable'     => ! empty( $settings[ 'filterable' ] ),
						'sortable'       => ! empty( $settings[ 'sortable' ] ),
						'sort_direction' => isset( $settings[ 'sort_direction' ] ) &&
							in_array( $settings[ 'sort_direction' ], [ 'asc', 'desc' ] ) ?
							$settings[ 'sort_direction' ] : 'desc'
					];
				}
			}
		}

		// Clear schema cache when settings are updated
		$schema_manager = new WP_Loupe_Schema_Manager();
		$schema_manager->clear_cache();

		return $sanitized;
	}

	/**
	 * Sanitize the post types setting.
	 *
	 * @param mixed $value Raw value from the form submission.
	 * @return array Sanitized array with validated post type slugs.
	 */
	public function sanitize_post_types_setting( $value ) {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$sanitized = [];
		if ( isset( $value['wp_loupe_post_type_field'] ) && is_array( $value['wp_loupe_post_type_field'] ) ) {
			$sanitized['wp_loupe_post_type_field'] = array_values(
				array_filter( array_map( 'sanitize_key', $value['wp_loupe_post_type_field'] ) )
			);
		}
		return $sanitized;
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		// Check if we're on the WP Loupe settings page
		if ( ! in_array( $hook, [ 'settings_page_wp-loupe', 'tools_page_wp-loupe' ] ) ) {
			return;
		}

		$version = WP_Loupe_Utils::get_version_number();

		// Register and enqueue Select2
		wp_register_style(
			'select2css',
			WP_LOUPE_URL . 'lib/css/select2.min.css',
			[],
			$version
		);

		wp_register_script(
			'select2',
			WP_LOUPE_URL . 'lib/js/select2.min.js',
			[ 'jquery' ],
			$version,
			true
		);

		// Register and enqueue admin assets
		wp_register_style(
			'wp-loupe-admin',
			WP_LOUPE_URL . 'lib/css/admin.css',
			[ 'select2css' ],
			$version
		);

		wp_register_script(
			'wp-loupe-admin',
			WP_LOUPE_URL . 'lib/js/admin.js',
			[ 'jquery', 'select2', 'wp-api-fetch' ],
			$version,
			true
		);

		// Enqueue all assets
		wp_enqueue_style( 'select2css' );
		wp_enqueue_style( 'wp-loupe-admin' );
		wp_enqueue_script( 'select2' );
		wp_enqueue_script( 'wp-loupe-admin' );

		// Add some custom styles for the typo thresholds
		wp_add_inline_style( 'wp-loupe-admin', '
			.wp-loupe-typo-thresholds {
				margin-bottom: 10px;
			}
			.wp-loupe-threshold-row {
				margin-bottom: 8px;
			}
			.wp-loupe-threshold-row input[type="number"] {
				width: 60px;
			}
			.nav-tab-wrapper {
				margin-bottom: 20px;
			}
		' );

		// Add Select2 initialization
		wp_add_inline_script( 'select2', '
			jQuery(document).ready(function($) {
				$("#wp_loupe_custom_post_types").select2({
					placeholder: "Select post types",
					width: "400px"
				});
			});
		' );

		// Localize script with enhanced field data
		wp_localize_script( 'wp-loupe-admin', 'wpLoupeAdmin', [
			'restUrl'        => rest_url( 'wp-loupe/v1' ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'savedFields'    => $this->prepare_fields_for_js(),
			'availableCache' => $this->prepare_available_fields_for_js(), // Provide available fields so JS can build UI even if REST route missing
		] );
	}

	/**
	 * Prepare field data for JavaScript
	 * 
	 * @return array
	 */
	private function prepare_fields_for_js() {
		$saved_fields    = get_option( 'wp_loupe_fields', [] );
		$enhanced_fields = [];

		foreach ( $saved_fields as $post_type => $fields ) {
			$available_fields = $this->get_available_fields( $post_type );

			$enhanced_fields[ $post_type ] = [];
			foreach ( $fields as $field_key => $settings ) {
				if ( isset( $available_fields[ $field_key ] ) ) {
					$enhanced_fields[ $post_type ][ $field_key ] = $settings;
				}
			}
		}

		return $enhanced_fields;
	}

	/**
	 * Prepare available fields for JS (meta + schema baseline) keyed by post type.
	 * Falls back gracefully if no saved fields yet.
	 */
	private function prepare_available_fields_for_js() {
		$post_types = array_diff( get_post_types( [ 'public' => true ], 'names', 'and' ), [ 'attachment' ] );
		$out        = [];
		foreach ( $post_types as $pt ) {
			$available  = $this->get_available_fields( $pt );
			$out[ $pt ] = [];
			foreach ( array_keys( $available ) as $field_key ) {
				$out[ $pt ][ $field_key ] = [
					'label' => $this->prettify_meta_key( $field_key ),
				];
			}
		}
		return $out;
	}

	/**
	 * Add help tabs to explain field configuration options
	 */
	public function add_help_tabs() {
		$screen = get_current_screen();

		// Add overview help tab that explains the structure
		$screen->add_help_tab( [
			'id'      => 'wp_loupe_help_overview',
			'title'   => __( 'Overview', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><div class="wp-loupe-help-sections"><div class="wp-loupe-help-section basic"><h3>%s</h3><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul></div><div class="wp-loupe-help-section advanced"><h3>%s</h3><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul></div></div>',
				__( 'WP Loupe Help', 'wp-loupe' ),
				__( 'WP Loupe provides powerful search functionality with both basic and advanced configuration options.', 'wp-loupe' ),
				__( 'Basic Settings', 'wp-loupe' ),
				__( 'Configure which content is searchable and how:', 'wp-loupe' ),
				__( 'Select post types to include in search', 'wp-loupe' ),
				__( 'Configure field weights for relevance', 'wp-loupe' ),
				__( 'Set filterable and sortable fields', 'wp-loupe' ),
				__( 'Advanced Settings', 'wp-loupe' ),
				__( 'Fine-tune search behavior with advanced options:', 'wp-loupe' ),
				__( 'Tokenization and language settings', 'wp-loupe' ),
				__( 'Prefix search configuration', 'wp-loupe' ),
				__( 'Typo tolerance customization', 'wp-loupe' )
			),
		] );

		// Basic settings help tabs - remove "BASIC:" prefix
		$screen->add_help_tab( [
			'id'      => 'wp_loupe_weight',
			'title'   => __( 'Weight', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul>',
				__( 'Field Weight', 'wp-loupe' ),
				__( 'Weight determines how important a field is in search results:', 'wp-loupe' ),
				__( 'Higher weight (e.g., 2.0) makes matches in this field more important in results ranking', 'wp-loupe' ),
				__( 'Default weight is 1.0', 'wp-loupe' ),
				__( 'Lower weight (e.g., 0.5) makes matches less important but still searchable', 'wp-loupe' )
			),
		] );

		$screen->add_help_tab( [
			'id'      => 'wp_loupe_filterable',
			'title'   => __( 'Filterable', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul><p>%s</p>',
				__( 'Filterable Fields', 'wp-loupe' ),
				__( 'Filterable fields can be used to refine search results:', 'wp-loupe' ),
				__( 'Enable this option to allow filtering search results by this field\'s values', 'wp-loupe' ),
				__( 'Best for fields with consistent, categorized values like taxonomies, status fields, or controlled metadata', 'wp-loupe' ),
				__( 'Examples: categories, tags, post type, author, or custom taxonomies', 'wp-loupe' ),
				__( 'Note: Fields with highly variable or unique values (like content) make poor filters as each post would have its own filter value.', 'wp-loupe' )
			),
		] );

		$screen->add_help_tab( [
			'id'      => 'wp_loupe_sortable',
			'title'   => __( 'Sortable', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul><h3>%s</h3><p>%s</p><ul><li>%s</li><li>%s</li></ul>',
				__( 'Sortable Fields', 'wp-loupe' ),
				__( 'Sortable fields can be used to order search results:', 'wp-loupe' ),
				__( 'Enable this option to allow sorting search results by this field\'s values', 'wp-loupe' ),
				__( 'Works best with numerical fields, dates, or short text values', 'wp-loupe' ),
				__( 'Examples: date, price, rating, title', 'wp-loupe' ),
				__( 'Why some fields are not sortable', 'wp-loupe' ),
				__( 'Not all fields make good candidates for sorting:', 'wp-loupe' ),
				__( 'Long text fields (like content) don\'t provide meaningful sort order', 'wp-loupe' ),
				__( 'Fields with complex values (like arrays or objects) cannot be directly sorted', 'wp-loupe' )
			),
		] );

		// Advanced settings help tabs - remove "ADVANCED:" prefix
		$screen->add_help_tab( [
			'id'      => 'wp_loupe_tokenization',
			'title'   => __( 'Tokenization', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><h3>%s</h3><p>%s</p><h3>%s</h3><p>%s</p>',
				__( 'Tokenization Settings', 'wp-loupe' ),
				__( 'Tokenization controls how search queries are split into searchable pieces.', 'wp-loupe' ),
				__( 'Max Query Tokens', 'wp-loupe' ),
				__( 'Limits the number of words processed in a search query. Higher values allow longer queries but may impact performance.', 'wp-loupe' ),
				__( 'Languages', 'wp-loupe' ),
				__( 'Select languages to properly handle word splitting, stemming, and special characters. Include all languages your content uses.', 'wp-loupe' )
			),
		] );

		$screen->add_help_tab( [
			'id'      => 'wp_loupe_prefix_search',
			'title'   => __( 'Prefix Search', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><p>%s</p><h3>%s</h3><p>%s</p><p>%s</p>',
				__( 'Prefix Search', 'wp-loupe' ),
				__( 'Prefix search allows users to find words by typing just the beginning of the term. For example, "huck" will match "huckleberry. Prefix search is only performed on the last word in a search query. Prior words must be typed out fully to get accurate results. E.g. my friend huck would find documents containing huckleberry - huck is my friend, however, would not.', 'wp-loupe' ),
				__( 'Only the last word in a query is treated as a prefix. Earlier words must be typed fully.', 'wp-loupe' ),
				__( 'Minimum Prefix Length', 'wp-loupe' ),
				__( 'Sets the minimum number of characters before prefix search activates. Default is 3.', 'wp-loupe' ),
				__( 'Lower values (1-2) provide more immediate results but may slow searches on large sites. Higher values (4+) improve performance but require more typing.', 'wp-loupe' )
			),
		] );

		$screen->add_help_tab( [
			'id'      => 'wp_loupe_typo_tolerance',
			'title'   => __( 'Typo Tolerance', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><p>%s</p><h3>%s</h3><p>%s</p>',
				__( 'Typo Tolerance', 'wp-loupe' ),
				__( 'Typo tolerance allows users to find results even when they make spelling mistakes in their search queries.', 'wp-loupe' ),
				__( 'For example, searching for "potatos" would still find "potatoes".', 'wp-loupe' ),
				__( 'Enable Typo Tolerance', 'wp-loupe' ),
				__( 'Turn typo tolerance on or off. Disabling may increase search speed but reduces forgiveness for spelling errors.', 'wp-loupe' )
			),
		] );

		$screen->add_help_tab( [
			'id'      => 'wp_loupe_typo_advanced',
			'title'   => __( 'Typo Details', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><h3>%s</h3><p>%s</p><h3>%s</h3><p>%s</p><h3>%s</h3><p>%s</p><h3>%s</h3><p>%s</p>',
				__( 'Advanced Typo Settings', 'wp-loupe' ),
				__( 'Alphabet Size & Index Length', 'wp-loupe' ),
				__( 'These settings affect index size and search performance. Higher values improve accuracy but increase index size. Default values work well for most sites.', 'wp-loupe' ),
				__( 'Typo Thresholds', 'wp-loupe' ),
				__( 'Control how many typos are allowed based on word length. Longer words typically allow more typos than shorter words.', 'wp-loupe' ),
				__( 'First Character Typo Weight', 'wp-loupe' ),
				__( 'When enabled, typos at the beginning of a word count as two mistakes. This helps prioritize more relevant results, as most typos occur in the middle of words.', 'wp-loupe' ),
				__( 'Typo Tolerance for Prefix Search', 'wp-loupe' ),
				__( 'Allows typos in prefix searches. Not recommended for large sites as it can significantly impact performance.', 'wp-loupe' )
			),
		] );

		// Add some custom styling to the help tabs
		$screen->add_help_tab( [
			'id'      => 'wp_loupe_help_styles',
			'title'   => '',
			'content' => '<style>
				.wp-loupe-help-sections {
					display: flex;
					gap: 20px;
					margin-top: 15px;
				}
				.wp-loupe-help-section {
					flex: 1;
					padding: 15px;
					border-radius: 5px;
				}
				.wp-loupe-help-section.basic {
					background-color: #e7f5fa;
					border-left: 4px solid #2271b1;
				}
				.wp-loupe-help-section.advanced {
					background-color: #faf5e7;
					border-left: 4px solid #b17a22;
				}
				.wp-loupe-help-section h3 {
					margin-top: 0;
				}
			</style>',
		] );
	}

	/**
	 * Callback for language selection
	 */
	public function languages_field_callback( $args ) {
		$available_languages = [
			'ar' => __( 'Arabic', 'wp-loupe' ),
			'hy' => __( 'Armenian', 'wp-loupe' ),
			'eu' => __( 'Basque', 'wp-loupe' ),
			'ca' => __( 'Catalan', 'wp-loupe' ),
			'zh' => __( 'Chinese', 'wp-loupe' ),
			'cs' => __( 'Czech', 'wp-loupe' ),
			'da' => __( 'Danish', 'wp-loupe' ),
			'nl' => __( 'Dutch', 'wp-loupe' ),
			'en' => __( 'English', 'wp-loupe' ),
			'fi' => __( 'Finnish', 'wp-loupe' ),
			'fr' => __( 'French', 'wp-loupe' ),
			'gl' => __( 'Galician', 'wp-loupe' ),
			'de' => __( 'German', 'wp-loupe' ),
			'el' => __( 'Greek', 'wp-loupe' ),
			'hi' => __( 'Hindi', 'wp-loupe' ),
			'hu' => __( 'Hungarian', 'wp-loupe' ),
			'id' => __( 'Indonesian', 'wp-loupe' ),
			'ga' => __( 'Irish', 'wp-loupe' ),
			'it' => __( 'Italian', 'wp-loupe' ),
			'ja' => __( 'Japanese', 'wp-loupe' ),
			'ko' => __( 'Korean', 'wp-loupe' ),
			'no' => __( 'Norwegian', 'wp-loupe' ),
			'fa' => __( 'Persian', 'wp-loupe' ),
			'pt' => __( 'Portuguese', 'wp-loupe' ),
			'ro' => __( 'Romanian', 'wp-loupe' ),
			'ru' => __( 'Russian', 'wp-loupe' ),
			'sr' => __( 'Serbian', 'wp-loupe' ),
			'es' => __( 'Spanish', 'wp-loupe' ),
			'sv' => __( 'Swedish', 'wp-loupe' ),
			'ta' => __( 'Tamil', 'wp-loupe' ),
			'th' => __( 'Thai', 'wp-loupe' ),
			'tr' => __( 'Turkish', 'wp-loupe' ),
			'uk' => __( 'Ukrainian', 'wp-loupe' ),
			'ur' => __( 'Urdu', 'wp-loupe' ),
		];

		echo '<select multiple name="' . esc_attr( $args[ 'name' ] ) . '[]" class="wp-loupe-select2" style="width: 400px;">';
		foreach ( $available_languages as $code => $name ) {
			$selected = in_array( $code, $args[ 'value' ] ) ? 'selected="selected"' : '';
			echo '<option value="' . esc_attr( $code ) . '" ' . esc_attr( $selected ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html( $args[ 'description' ] ) . '</p>';

		// Add inline script to initialize Select2 on this field
		wp_print_inline_script_tag( '
		jQuery(document).ready(function($) {
			$(".wp-loupe-select2").select2({
				placeholder: "' . esc_js( __( "Select languages", "wp-loupe" ) ) . '"
			});
		});
		' );

	}

	/**
	 * Callback for typo thresholds
	 */
	public function typo_thresholds_callback( $args ) {
		$thresholds = $args[ 'value' ];

		echo '<div class="wp-loupe-typo-thresholds">';

		// First threshold
		echo '<div class="wp-loupe-threshold-row">';
		echo '<label>' . esc_html__( 'Word length ≥', 'wp-loupe' ) . ' </label>';
		echo '<input type="number" name="' . esc_attr( $args[ 'name' ] ) . '[threshold1][length]" value="' . ( isset( $thresholds[ '9' ] ) ? '9' : ( isset( $thresholds[ 'threshold1' ][ 'length' ] ) ? esc_attr( $thresholds[ 'threshold1' ][ 'length' ] ) : '9' ) ) . '" min="3" max="20" step="1">';
		echo ' ' . esc_html__( 'characters: Allow', 'wp-loupe' ) . ' ';
		echo '<input type="number" name="' . esc_attr( $args[ 'name' ] ) . '[threshold1][typos]" value="' . ( isset( $thresholds[ '9' ] ) ? esc_attr( $thresholds[ '9' ] ) : ( isset( $thresholds[ 'threshold1' ][ 'typos' ] ) ? esc_attr( $thresholds[ 'threshold1' ][ 'typos' ] ) : '2' ) ) . '" min="1" max="3" step="1">';
		echo ' ' . esc_html__( 'typos', 'wp-loupe' );
		echo '</div>';

		// Second threshold
		echo '<div class="wp-loupe-threshold-row">';
		echo '<label>' . esc_html__( 'Word length ≥', 'wp-loupe' ) . ' </label>';
		echo '<input type="number" name="' . esc_attr( $args[ 'name' ] ) . '[threshold2][length]" value="' . ( isset( $thresholds[ '5' ] ) ? '5' : ( isset( $thresholds[ 'threshold2' ][ 'length' ] ) ? esc_attr( $thresholds[ 'threshold2' ][ 'length' ] ) : '5' ) ) . '" min="2" max="8" step="1">';
		echo ' ' . esc_html__( 'characters: Allow', 'wp-loupe' ) . ' ';
		echo '<input type="number" name="' . esc_attr( $args[ 'name' ] ) . '[threshold2][typos]" value="' . ( isset( $thresholds[ '5' ] ) ? esc_attr( $thresholds[ '5' ] ) : ( isset( $thresholds[ 'threshold2' ][ 'typos' ] ) ? esc_attr( $thresholds[ 'threshold2' ][ 'typos' ] ) : '1' ) ) . '" min="1" max="2" step="1">';
		echo ' ' . esc_html__( 'typos', 'wp-loupe' );
		echo '</div>';

		echo '</div>';
		echo '<p class="description">' . esc_html( $args[ 'description' ] ) . '</p>';
	}
}