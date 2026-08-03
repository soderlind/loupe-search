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
		add_action( 'admin_menu', [ $this, 'loupe_search_create_settings' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'loupe_search_setup_sections' ] );
		add_action( 'admin_init', [ $this, 'loupe_search_setup_fields' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'load-settings_page_loupe-search', [ $this, 'add_help_tabs' ] );
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
	public function loupe_search_create_settings() {
		add_options_page( 'Loupe Search', 'Loupe Search', 'manage_options', 'loupe-search', [ $this, 'plugin_settings_page_content' ] );
	}

	/**
	 * Setup the settings sections.
	 *
	 * @return void
	 */
	public function loupe_search_setup_sections() {
		// Fields tab sections
		add_settings_section( 'loupe_search_section', __( 'Post Types', 'loupe-search' ), [ $this, 'general_section_callback' ], 'loupe-search' );
		add_settings_section( 'loupe_search_fields_section', __( 'Field Settings', 'loupe-search' ), [ $this, 'fields_section_callback' ], 'loupe-search' );

		// Search Behavior tab sections
		add_settings_section( 'loupe_search_tokenization_section', __( 'Tokenization', 'loupe-search' ),
			[ $this, 'tokenization_section_callback' ], 'loupe-search-advanced' );
		add_settings_section( 'loupe_search_prefix_section', __( 'Prefix Search', 'loupe-search' ),
			[ $this, 'prefix_section_callback' ], 'loupe-search-advanced' );
		add_settings_section( 'loupe_search_typo_section', __( 'Typo Tolerance', 'loupe-search' ),
			[ $this, 'typo_section_callback' ], 'loupe-search-advanced' );
	}

	/**
	 * General settings section description
	 */
	public function general_section_callback() {
		echo '<p>' . esc_html__( 'Select which post types and fields to include in the search index.', 'loupe-search' ) . '</p>';
	}

	/**
	 * Tokenization section description
	 */
	public function tokenization_section_callback() {
		echo '<p>' . esc_html__( 'Configure how search terms are tokenized.', 'loupe-search' ) . '</p>';
	}

	/**
	 * Prefix search section description
	 */
	public function prefix_section_callback() {
		echo '<p>' . esc_html__( 'Configure prefix search behavior. Prefix search allows finding terms by typing only the beginning (e.g., "huck" finds "huckleberry"). Prefix search is only performed on the last word in a search query. Prior words must be typed out fully to get accurate results. E.g. my friend huck would find documents containing huckleberry - huck is my friend, however, would not.', 'loupe-search' ) . '</p>';
	}

	/**
	 * Typo tolerance section description
	 */
	public function typo_section_callback() {
		echo '<p>' . esc_html__( 'Configure typo tolerance for search queries. Typo tolerance allows finding results even when users make typing mistakes.', 'loupe-search' ) . '</p>';
		echo wp_kses_post( '<p><small>' . sprintf(
			/* translators: %s: link to the research paper on efficient similarity search */
			__( 'Based on the algorithm from "Efficient Similarity Search in Very Large String Sets" %s.', 'loupe-search' ),
			'<a href="https://hpi.de/fileadmin/user_upload/fachgebiete/naumann/publications/PDFs/2012_ICDE_p1586-fenz.pdf" target="_blank">' . esc_html__( '(read the paper)', 'loupe-search' ) . '</a>'
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
	public function loupe_search_setup_fields() {
		$this->cpt = array_diff( get_post_types(
			[
				'public' => true,
			],
			'names',
			'and'
		), [ 'attachment' ] );

		add_settings_field(
			'loupe_search_post_type_field',
			__( 'Select Post Types', 'loupe-search' ),
			[ $this, 'loupe_search_post_type_field_callback' ],
			'loupe-search',
			'loupe_search_section'
		);

		// Advanced tab fields (tokenization)
		add_settings_field(
			'loupe_search_max_query_tokens',
			__( 'Max Query Tokens', 'loupe-search' ),
			[ $this, 'number_field_callback' ],
			'loupe-search-advanced',
			'loupe_search_tokenization_section',
			[
				'name'        => 'loupe_search_advanced[max_query_tokens]',
				'value'       => $this->get_advanced_option( 'max_query_tokens', 12 ),
				'description' => __( 'Maximum number of tokens processed in a search query.', 'loupe-search' ),
			]
		);

		// Prefix search settings
		add_settings_field(
			'loupe_search_min_prefix_length',
			__( 'Minimum Prefix Length', 'loupe-search' ),
			[ $this, 'number_field_callback' ],
			'loupe-search-advanced',
			'loupe_search_prefix_section',
			[
				'name'        => 'loupe_search_advanced[min_prefix_length]',
				'value'       => $this->get_advanced_option( 'min_prefix_length', 3 ),
				'description' => __( 'Minimum characters before prefix search activates.', 'loupe-search' ),
			]
		);

		// Typo tolerance settings
		add_settings_field(
			'loupe_search_typo_enabled',
			__( 'Enable Typo Tolerance', 'loupe-search' ),
			[ $this, 'checkbox_field_callback' ],
			'loupe-search-advanced',
			'loupe_search_typo_section',
			[
				'name'        => 'loupe_search_advanced[typo_enabled]',
				'value'       => $this->get_advanced_option( 'typo_enabled', true ),
				'description' => __( 'Allow search to return results with minor spelling mistakes.', 'loupe-search' ),
			]
		);

		add_settings_field(
			'loupe_search_alphabet_size',
			__( 'Alphabet Size', 'loupe-search' ),
			[ $this, 'number_field_callback' ],
			'loupe-search-advanced',
			'loupe_search_typo_section',
			[
				'name'        => 'loupe_search_advanced[alphabet_size]',
				'value'       => $this->get_advanced_option( 'alphabet_size', 4 ),
				'description' => __( 'Size of internal alphabet used for typo tolerance.', 'loupe-search' ),
			]
		);

		add_settings_field(
			'loupe_search_index_length',
			__( 'Index Length', 'loupe-search' ),
			[ $this, 'number_field_callback' ],
			'loupe-search-advanced',
			'loupe_search_typo_section',
			[
				'name'        => 'loupe_search_advanced[index_length]',
				'value'       => $this->get_advanced_option( 'index_length', 14 ),
				'description' => __( 'Internal index length; affects accuracy vs. size.', 'loupe-search' ),
			]
		);

		add_settings_field(
			'loupe_search_typo_prefix_search',
			__( 'Typo Tolerance for Prefix Search', 'loupe-search' ),
			[ $this, 'checkbox_field_callback' ],
			'loupe-search-advanced',
			'loupe_search_typo_section',
			[
				'name'        => 'loupe_search_advanced[typo_prefix_search]',
				'value'       => $this->get_advanced_option( 'typo_prefix_search', false ),
				'description' => __( 'Allow typos when matching prefix (can slow searches).', 'loupe-search' ),
			]
		);

		add_settings_field(
			'loupe_search_first_char_typo_double',
			__( 'Double Count First Character Typo', 'loupe-search' ),
			[ $this, 'checkbox_field_callback' ],
			'loupe-search-advanced',
			'loupe_search_typo_section',
			[
				'name'        => 'loupe_search_advanced[first_char_typo_double]',
				'value'       => $this->get_advanced_option( 'first_char_typo_double', true ),
				'description' => __( 'Treat a typo at the start of a word as two mistakes.', 'loupe-search' ),
			]
		);
	}

	/**
	 * Get advanced option with default
	 */
	private function get_advanced_option( $key, $default ) {
		$options = get_option( 'loupe_search_advanced', [] );
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
	public function loupe_search_post_type_field_callback() {
		$options      = get_option( 'loupe_search_custom_post_types', [] );
		$selected_ids = ! empty( $options ) && isset( $options[ 'loupe_search_post_type_field' ] )
			? (array) $options[ 'loupe_search_post_type_field' ]
			: [ 'post', 'page' ]; // Default selection

		echo '<fieldset id="loupe_search_custom_post_types" class="wp-loupe-post-types">';
		echo '<legend class="screen-reader-text">' . esc_html__( 'Select Post Types', 'loupe-search' ) . '</legend>';
		foreach ( $this->cpt as $post_type ) {
			$obj   = get_post_type_object( $post_type );
			$label = ( is_object( $obj ) && isset( $obj->labels->name ) ) ? $obj->labels->name : $post_type;
			echo sprintf(
				'<label class="wp-loupe-post-type-option"><input type="checkbox" class="wp-loupe-post-type-checkbox" name="loupe_search_custom_post_types[loupe_search_post_type_field][]" value="%1$s" %2$s> %3$s <code>%1$s</code></label>',
				esc_attr( $post_type ),
				checked( in_array( $post_type, $selected_ids, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Adding a post type creates its index; removing it deletes the index. Save settings, then run Reindex from the Dashboard.', 'loupe-search' ) . '</p>';
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

		$current_tab = isset( $_GET[ 'tab' ] ) ? sanitize_key( $_GET[ 'tab' ] ) : 'dashboard';
		$valid_tabs  = [ 'dashboard', 'fields', 'search-behavior' ];
		if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
			$current_tab = 'dashboard';
		}

		?>
		<div class="wrap">
			<h2><?php echo esc_html( get_admin_page_title() ); ?></h2>

			<nav class="nav-tab-wrapper">
				<a href="?page=loupe-search&tab=dashboard"
					class="nav-tab <?php echo $current_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Dashboard', 'loupe-search' ); ?>
				</a>
				<a href="?page=loupe-search&tab=fields"
					class="nav-tab <?php echo $current_tab === 'fields' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Fields', 'loupe-search' ); ?>
				</a>
				<a href="?page=loupe-search&tab=search-behavior"
					class="nav-tab <?php echo $current_tab === 'search-behavior' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Search Behavior', 'loupe-search' ); ?>
				</a>
			</nav>

			<?php
			if ( 'dashboard' === $current_tab ) {
				$this->render_dashboard_tab();
			} else {
				?>
				<form action="options.php" method="POST">
					<?php
					wp_nonce_field( 'loupe_search_nonce_action', 'loupe_search_nonce_field' );

					if ( 'search-behavior' === $current_tab ) {
						settings_fields( 'loupe-search-advanced' );
						do_settings_sections( 'loupe-search-advanced' );
					} else {
						settings_fields( 'loupe-search' );
						do_settings_sections( 'loupe-search' );
					}

					submit_button( __( 'Save Settings', 'loupe-search' ) );
					?>
				</form>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the Dashboard tab: index health, reindex, and system status.
	 *
	 * @return void
	 */
	public function render_dashboard_tab() {
		?>
		<div class="wp-loupe-dashboard">
			<div class="wp-loupe-card">
				<h3><?php esc_html_e( 'Index health', 'loupe-search' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Status of each indexed post type. Run Reindex after changing post types or field settings.', 'loupe-search' ); ?>
				</p>
				<div id="wp-loupe-index-health" aria-live="polite">
					<p class="description"><?php esc_html_e( 'Loading…', 'loupe-search' ); ?></p>
				</div>
			</div>

			<div class="wp-loupe-card">
				<h3><?php esc_html_e( 'Reindex', 'loupe-search' ); ?></h3>
				<p class="description" style="max-width:800px;">
					<?php esc_html_e( 'Reindexing runs in small batches to avoid request timeouts. Keep this tab open until it finishes.', 'loupe-search' ); ?>
				</p>
				<p>
					<button type="button" class="button button-primary" id="wp-loupe-reindex-button">
						<?php esc_html_e( 'Reindex now', 'loupe-search' ); ?>
					</button>
					<button type="button" class="button button-secondary hidden" id="wp-loupe-reindex-cancel">
						<?php esc_html_e( 'Cancel', 'loupe-search' ); ?>
					</button>
				</p>
				<div id="wp-loupe-reindex-progress" class="wp-loupe-progress hidden">
					<progress id="wp-loupe-reindex-bar" max="100" value="0"></progress>
					<span id="wp-loupe-reindex-progress-label"></span>
				</div>
			</div>

			<div class="wp-loupe-card">
				<h3><?php esc_html_e( 'System status', 'loupe-search' ); ?></h3>
				<?php $this->render_system_status(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the system requirements status table.
	 *
	 * @return void
	 */
	private function render_system_status() {
		$checks = WP_Loupe_Utils::get_requirements_checks();
		echo '<table class="wp-loupe-status-table widefat striped">';
		echo '<tbody>';
		foreach ( $checks as $check ) {
			$ok    = ! empty( $check[ 'ok' ] );
			$icon  = $ok ? 'dashicons-yes-alt' : 'dashicons-warning';
			$color = $ok ? '#46b450' : '#dc3232';
			printf(
				'<tr><td><span class="dashicons %1$s" style="color:%2$s;" aria-hidden="true"></span> %3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_attr( $icon ),
				esc_attr( $color ),
				esc_html( $check[ 'label' ] ),
				esc_html( $check[ 'value' ] ),
				esc_html( $check[ 'required' ] )
			);
		}
		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * Register all settings
	 */
	public function register_settings() {
		// General settings group
		register_setting( 'loupe-search', 'loupe_search_custom_post_types', [
			'sanitize_callback' => [ $this, 'sanitize_post_types_setting' ],
		] );
		register_setting( 'loupe-search', 'loupe_search_fields', [
			'type'              => 'array',
			'description'       => 'Field configuration for each post type',
			'sanitize_callback' => [ $this, 'sanitize_fields_settings' ],
		] );

		// Advanced settings group
		register_setting( 'loupe-search-advanced', 'loupe_search_advanced', [
			'type'              => 'array',
			'description'       => 'Advanced search configuration options',
			'sanitize_callback' => [ $this, 'sanitize_advanced_settings' ],
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

			// Validate the post type key: must be a sanitized, registered post type.
			$post_type = sanitize_key( $post_type );
			if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
				continue;
			}

			foreach ( $fields as $field_key => $settings ) {
				// Constrain the field key to a safe field-name character set.
				$field_key = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $field_key );
				if ( '' === $field_key ) {
					continue;
				}

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
		if ( isset( $value['loupe_search_post_type_field'] ) && is_array( $value['loupe_search_post_type_field'] ) ) {
			$sanitized['loupe_search_post_type_field'] = array_values(
				array_filter( array_map( 'sanitize_key', $value['loupe_search_post_type_field'] ) )
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
		if ( ! in_array( $hook, [ 'settings_page_loupe-search', 'tools_page_loupe-search' ] ) ) {
			return;
		}

		$version = WP_Loupe_Utils::get_version_number();

		// Register and enqueue admin assets
		wp_register_style(
			'loupe-search-admin',
			LOUPE_SEARCH_URL . 'lib/css/admin.css',
			[],
			$version
		);

		wp_register_script(
			'loupe-search-admin',
			LOUPE_SEARCH_URL . 'lib/js/admin.js',
			[ 'wp-api-fetch', 'wp-i18n' ],
			$version,
			true
		);

		// Enqueue all assets
		wp_enqueue_style( 'loupe-search-admin' );
		wp_enqueue_script( 'loupe-search-admin' );

		// Add some custom styles for the typo thresholds
		wp_add_inline_style( 'loupe-search-admin', '
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
		' );

		// Localize script with enhanced field data
		wp_localize_script( 'loupe-search-admin', 'loupeSearchAdmin', [
			'restUrl'             => rest_url( 'loupe-search/v1' ),
			'nonce'               => wp_create_nonce( 'wp_rest' ),
			'savedFields'         => $this->prepare_fields_for_js(),
			'availableCache'      => $this->prepare_available_fields_for_js(), // Provide available fields so JS can build UI even if REST route missing
			'configuredPostTypes' => $this->get_configured_post_types(),
		] );
	}

	/**
	 * Get the list of post types currently saved in the index configuration.
	 *
	 * @return array
	 */
	private function get_configured_post_types() {
		$options = get_option( 'loupe_search_custom_post_types', [] );
		if ( ! empty( $options ) && isset( $options[ 'loupe_search_post_type_field' ] ) && is_array( $options[ 'loupe_search_post_type_field' ] ) ) {
			return array_values( array_map( 'sanitize_key', $options[ 'loupe_search_post_type_field' ] ) );
		}
		return [ 'post', 'page' ];
	}

	/**
	 * Prepare field data for JavaScript
	 * 
	 * @return array
	 */
	private function prepare_fields_for_js() {
		$saved_fields    = get_option( 'loupe_search_fields', [] );
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
			'id'      => 'loupe_search_help_overview',
			'title'   => __( 'Overview', 'loupe-search' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><p>%s</p><div class="wp-loupe-help-sections"><div class="wp-loupe-help-section basic"><h3>%s</h3><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul></div><div class="wp-loupe-help-section advanced"><h3>%s</h3><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul></div></div>',
				__( 'Loupe Search Help', 'loupe-search' ),
				__( 'WP Loupe settings are organized into three tabs: Dashboard, Fields, and Search Behavior.', 'loupe-search' ),
				__( 'The Dashboard tab shows index health for each post type, lets you reindex your content, and reports whether the required PHP extensions are available.', 'loupe-search' ),
				__( 'Fields', 'loupe-search' ),
				__( 'Configure which content is searchable and how:', 'loupe-search' ),
				__( 'Select post types to include in search', 'loupe-search' ),
				__( 'Configure field weights for relevance', 'loupe-search' ),
				__( 'Set filterable and sortable fields', 'loupe-search' ),
				__( 'Search Behavior', 'loupe-search' ),
				__( 'Fine-tune how queries are matched:', 'loupe-search' ),
				__( 'Tokenization (max query tokens)', 'loupe-search' ),
				__( 'Prefix search configuration', 'loupe-search' ),
				__( 'Typo tolerance customization', 'loupe-search' )
			),
		] );

		// Basic settings help tabs - remove "BASIC:" prefix
		$screen->add_help_tab( [
			'id'      => 'loupe_search_weight',
			'title'   => __( 'Weight', 'loupe-search' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul>',
				__( 'Field Weight', 'loupe-search' ),
				__( 'Weight determines how important a field is in search results:', 'loupe-search' ),
				__( 'Higher weight (e.g., 2.0) makes matches in this field more important in results ranking', 'loupe-search' ),
				__( 'Default weight is 1.0', 'loupe-search' ),
				__( 'Lower weight (e.g., 0.5) makes matches less important but still searchable', 'loupe-search' )
			),
		] );

		$screen->add_help_tab( [
			'id'      => 'loupe_search_filterable',
			'title'   => __( 'Filterable', 'loupe-search' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul><p>%s</p>',
				__( 'Filterable Fields', 'loupe-search' ),
				__( 'Filterable fields can be used to refine search results:', 'loupe-search' ),
				__( 'Enable this option to allow filtering search results by this field\'s values', 'loupe-search' ),
				__( 'Best for fields with consistent, categorized values like taxonomies, status fields, or controlled metadata', 'loupe-search' ),
				__( 'Examples: categories, tags, post type, author, or custom taxonomies', 'loupe-search' ),
				__( 'Note: Fields with highly variable or unique values (like content) make poor filters as each post would have its own filter value.', 'loupe-search' )
			),
		] );

		$screen->add_help_tab( [
			'id'      => 'loupe_search_sortable',
			'title'   => __( 'Sortable', 'loupe-search' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul><h3>%s</h3><p>%s</p><ul><li>%s</li><li>%s</li></ul>',
				__( 'Sortable Fields', 'loupe-search' ),
				__( 'Sortable fields can be used to order search results:', 'loupe-search' ),
				__( 'Enable this option to allow sorting search results by this field\'s values', 'loupe-search' ),
				__( 'Works best with numerical fields, dates, or short text values', 'loupe-search' ),
				__( 'Examples: date, price, rating, title', 'loupe-search' ),
				__( 'Why some fields are not sortable', 'loupe-search' ),
				__( 'Not all fields make good candidates for sorting:', 'loupe-search' ),
				__( 'Long text fields (like content) don\'t provide meaningful sort order', 'loupe-search' ),
				__( 'Fields with complex values (like arrays or objects) cannot be directly sorted', 'loupe-search' )
			),
		] );

		// Advanced settings help tabs - remove "ADVANCED:" prefix
		$screen->add_help_tab( [
			'id'      => 'loupe_search_tokenization',
			'title'   => __( 'Tokenization', 'loupe-search' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><h3>%s</h3><p>%s</p>',
				__( 'Tokenization Settings', 'loupe-search' ),
				__( 'Tokenization controls how search queries are split into searchable pieces.', 'loupe-search' ),
				__( 'Max Query Tokens', 'loupe-search' ),
				__( 'Limits the number of words processed in a search query. Higher values allow longer queries but may impact performance.', 'loupe-search' )
			),
		] );

		$screen->add_help_tab( [
			'id'      => 'loupe_search_prefix_search',
			'title'   => __( 'Prefix Search', 'loupe-search' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><p>%s</p><h3>%s</h3><p>%s</p><p>%s</p>',
				__( 'Prefix Search', 'loupe-search' ),
				__( 'Prefix search allows users to find words by typing just the beginning of the term. For example, "huck" will match "huckleberry. Prefix search is only performed on the last word in a search query. Prior words must be typed out fully to get accurate results. E.g. my friend huck would find documents containing huckleberry - huck is my friend, however, would not.', 'loupe-search' ),
				__( 'Only the last word in a query is treated as a prefix. Earlier words must be typed fully.', 'loupe-search' ),
				__( 'Minimum Prefix Length', 'loupe-search' ),
				__( 'Sets the minimum number of characters before prefix search activates. Default is 3.', 'loupe-search' ),
				__( 'Lower values (1-2) provide more immediate results but may slow searches on large sites. Higher values (4+) improve performance but require more typing.', 'loupe-search' )
			),
		] );

		$screen->add_help_tab( [
			'id'      => 'loupe_search_typo_tolerance',
			'title'   => __( 'Typo Tolerance', 'loupe-search' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><p>%s</p><h3>%s</h3><p>%s</p>',
				__( 'Typo Tolerance', 'loupe-search' ),
				__( 'Typo tolerance allows users to find results even when they make spelling mistakes in their search queries.', 'loupe-search' ),
				__( 'For example, searching for "potatos" would still find "potatoes".', 'loupe-search' ),
				__( 'Enable Typo Tolerance', 'loupe-search' ),
				__( 'Turn typo tolerance on or off. Disabling may increase search speed but reduces forgiveness for spelling errors.', 'loupe-search' )
			),
		] );

		$screen->add_help_tab( [
			'id'      => 'loupe_search_typo_advanced',
			'title'   => __( 'Typo Details', 'loupe-search' ),
			'content' => sprintf(
				'<h2>%s</h2><h3>%s</h3><p>%s</p><h3>%s</h3><p>%s</p><h3>%s</h3><p>%s</p>',
				__( 'Advanced Typo Settings', 'loupe-search' ),
				__( 'Alphabet Size & Index Length', 'loupe-search' ),
				__( 'These settings affect index size and search performance. Higher values improve accuracy but increase index size. Default values work well for most sites.', 'loupe-search' ),
				__( 'First Character Typo Weight', 'loupe-search' ),
				__( 'When enabled, typos at the beginning of a word count as two mistakes. This helps prioritize more relevant results, as most typos occur in the middle of words.', 'loupe-search' ),
				__( 'Typo Tolerance for Prefix Search', 'loupe-search' ),
				__( 'Allows typos in prefix searches. Not recommended for large sites as it can significantly impact performance.', 'loupe-search' )
			),
		] );

	}
}