<?php
namespace Soderlind\Plugin\LoupeSearch;

/**
 * Excluded posts: posts an editor keeps out of the search index.
 *
 * Exclusion is a discoverability choice, not access control. It is stored as
 * post meta and enforced through `loupe_search_is_indexable`, so it applies to
 * every surface that reads the index. Any change to the flag re-evaluates the
 * post, which removes it from or restores it to the index.
 *
 * @package Soderlind\Plugin\LoupeSearch
 * @since 1.4.0
 */
class WP_Loupe_Exclusion {

	public const META_KEY   = '_loupe_search_exclude';
	public const REST_FIELD = 'loupe_search_exclude';
	public const COLUMN     = 'loupe_search';

	private const NONCE_ACTION = 'loupe_search_exclude';
	private const NONCE_FIELD  = 'loupe_search_exclude_nonce';
	private const INPUT        = 'loupe_search_exclude';

	private const BULK_EXCLUDE = 'loupe_search_exclude';
	private const BULK_INCLUDE = 'loupe_search_include';

	/** @var WP_Loupe_Indexer */
	private $indexer;

	/** @var array<int,string> */
	private $post_types;

	/** @var array<int,bool> Posts being deleted; their meta removal must not re-add them. */
	private $deleting = [];

	/**
	 * @param WP_Loupe_Indexer  $indexer    Indexer used to re-evaluate changed posts.
	 * @param array<int,string> $post_types Indexed post types.
	 */
	public function __construct( WP_Loupe_Indexer $indexer, array $post_types ) {
		$this->indexer    = $indexer;
		$this->post_types = array_values( array_filter( $post_types, 'is_string' ) );
	}

	/**
	 * Whether a post is an Excluded post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_excluded( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, self::META_KEY, true );
	}

	/**
	 * Number of published Excluded posts of a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return int
	 */
	public static function count_excluded( string $post_type ): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT p.ID ) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE p.post_type = %s AND p.post_status = 'publish'
				AND m.meta_key = %s AND m.meta_value NOT IN ( '', '0' )",
				$post_type,
				self::META_KEY
			)
		);

		return (int) $count;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Early priority so integrations see the final answer for Excluded posts.
		add_filter( 'loupe_search_is_indexable', [ $this, 'filter_is_indexable' ], 5, 2 );
		add_action( 'init', [ $this, 'register_meta' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_field' ] );

		add_action( 'added_post_meta', [ $this, 'on_meta_change' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'on_meta_change' ], 10, 3 );
		add_action( 'deleted_post_meta', [ $this, 'on_meta_change' ], 10, 3 );
		add_action( 'before_delete_post', [ $this, 'mark_deleting' ], 1 );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'save_post', [ $this, 'save_from_form' ], 10, 2 );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'quick_edit_custom_box', [ $this, 'render_quick_edit' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_list_assets' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'admin_notices', [ $this, 'bulk_action_notice' ] );

		foreach ( $this->post_types as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", [ $this, 'add_column' ] );
			add_action( "manage_{$post_type}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
			add_filter( "bulk_actions-edit-{$post_type}", [ $this, 'add_bulk_actions' ] );
			add_filter( "handle_bulk_actions-edit-{$post_type}", [ $this, 'handle_bulk_action' ], 10, 3 );
		}
	}

	/**
	 * Veto Excluded posts.
	 *
	 * @param bool  $indexable Whether the post may be indexed.
	 * @param mixed $post      Post object.
	 * @return bool
	 */
	public function filter_is_indexable( $indexable, $post = null ): bool {
		if ( ! $indexable || ! $post instanceof \WP_Post ) {
			return (bool) $indexable;
		}
		return ! self::is_excluded( (int) $post->ID );
	}

	/**
	 * Register the meta key so writes are type-checked and capability-gated.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		foreach ( $this->post_types as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_KEY,
				[
					'type'              => 'boolean',
					'single'            => true,
					'default'           => false,
					'show_in_rest'      => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', (int) $post_id );
					},
				]
			);
		}
	}

	/**
	 * Expose the flag to the block editor.
	 *
	 * A dedicated field rather than REST meta: post meta only appears in REST for
	 * post types that support custom-fields, which many indexed types do not.
	 *
	 * @return void
	 */
	public function register_rest_field(): void {
		register_rest_field(
			$this->post_types,
			self::REST_FIELD,
			[
				'get_callback'    => static function ( $post ) {
					return self::is_excluded( (int) ( $post[ 'id' ] ?? 0 ) );
				},
				'update_callback' => static function ( $value, $post ) {
					$post_id = (int) ( $post->ID ?? 0 );
					if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
						return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to change search exclusion for this post.', 'loupe-search' ), [ 'status' => rest_authorization_required_code() ] );
					}
					self::set_excluded( $post_id, (bool) $value );
					return true;
				},
				'schema'          => [
					'description' => __( 'Whether the post is excluded from Loupe Search.', 'loupe-search' ),
					'type'        => 'boolean',
					'context'     => [ 'edit' ],
				],
			]
		);
	}

	/**
	 * Set or clear the flag.
	 *
	 * @param int  $post_id  Post ID.
	 * @param bool $excluded Whether the post is excluded.
	 * @return void
	 */
	public static function set_excluded( int $post_id, bool $excluded ): void {
		if ( $excluded ) {
			update_post_meta( $post_id, self::META_KEY, '1' );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}
	}

	/**
	 * Re-evaluate a post whenever its exclusion flag changes.
	 *
	 * @param mixed  $meta_id   Meta ID(s).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return void
	 */
	public function on_meta_change( $meta_id, $object_id, $meta_key ): void {
		$post_id = (int) $object_id;
		if ( self::META_KEY !== $meta_key || $post_id <= 0 || isset( $this->deleting[ $post_id ] ) ) {
			return;
		}
		$this->indexer->reindex_object_ids( [ $post_id ] );
	}

	/**
	 * Remember posts being permanently deleted.
	 *
	 * wp_delete_post() removes meta after the index entry is gone; without this
	 * guard the meta removal would restore the post to the index.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function mark_deleting( $post_id ): void {
		$this->deleting[ (int) $post_id ] = true;
	}

	/**
	 * Whether the current admin screen lists or edits an indexed post type.
	 *
	 * @param string $base Screen base: 'edit' or 'post'.
	 * @return bool
	 */
	private function is_indexed_screen( string $base ): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return is_object( $screen ) && $base === $screen->base && in_array( $screen->post_type, $this->post_types, true );
	}

	/**
	 * Add the Search column to post lists.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function add_column( $columns ): array {
		$columns                 = (array) $columns;
		$columns[ self::COLUMN ] = __( 'Search', 'loupe-search' );
		return $columns;
	}

	/**
	 * Render the Search column; data-excluded seeds the Quick Edit checkbox.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_column( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$excluded = self::is_excluded( (int) $post_id );
		printf(
			'<span class="loupe-search-exclusion" data-excluded="%1$s">%2$s</span>',
			$excluded ? '1' : '0',
			$excluded ? esc_html__( 'Excluded', 'loupe-search' ) : '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Included', 'loupe-search' ) . '</span>'
		);
	}

	/**
	 * Render the Quick Edit checkbox.
	 *
	 * @param string $column    Column name.
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function render_quick_edit( $column, $post_type ): void {
		if ( self::COLUMN !== $column || ! in_array( $post_type, $this->post_types, true ) ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right loupe-search-quick-edit">
			<div class="inline-edit-col">
				<label class="alignleft">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<input type="checkbox" name="<?php echo esc_attr( self::INPUT ); ?>" value="1" />
					<span class="checkbox-title"><?php esc_html_e( 'Exclude from Loupe Search', 'loupe-search' ); ?></span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Add the classic-editor meta box. The block editor uses its own panel.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function add_meta_box( $post_type ): void {
		if ( ! in_array( $post_type, $this->post_types, true ) ) {
			return;
		}
		add_meta_box(
			'loupe-search-exclude',
			__( 'Loupe Search', 'loupe-search' ),
			[ $this, 'render_meta_box' ],
			$post_type,
			'side',
			'default',
			[ '__back_compat_meta_box' => true ]
		);
	}

	/**
	 * Render the classic-editor meta box.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function render_meta_box( $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::INPUT ); ?>" value="1" <?php checked( self::is_excluded( (int) $post->ID ) ); ?> />
			<?php esc_html_e( 'Exclude from Loupe Search', 'loupe-search' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Hides this post from site search. It stays reachable by its URL; this is not access control.', 'loupe-search' ); ?></p>
		<?php
	}

	/**
	 * Save the flag from Quick Edit or the classic-editor meta box.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save_from_form( $post_id, $post ): void {
		$post_id = (int) $post_id;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified as a nonce below.
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? wp_unslash( $_POST[ self::NONCE_FIELD ] ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->post_types, true ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		self::set_excluded( $post_id, ! empty( $_POST[ self::INPUT ] ) );
	}

	/**
	 * Add Exclude/Include bulk actions.
	 *
	 * @param array<string,string> $actions Bulk actions.
	 * @return array<string,string>
	 */
	public function add_bulk_actions( $actions ): array {
		$actions                       = (array) $actions;
		$actions[ self::BULK_EXCLUDE ] = __( 'Exclude from search', 'loupe-search' );
		$actions[ self::BULK_INCLUDE ] = __( 'Include in search', 'loupe-search' );
		return $actions;
	}

	/**
	 * Apply a bulk action. Core has already verified the bulk-posts nonce.
	 *
	 * @param string          $redirect_to Redirect URL.
	 * @param string          $action      Bulk action.
	 * @param array<int,int>  $post_ids    Selected post IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_to, $action, $post_ids ): string {
		if ( self::BULK_EXCLUDE !== $action && self::BULK_INCLUDE !== $action ) {
			return (string) $redirect_to;
		}

		$exclude = self::BULK_EXCLUDE === $action;
		$changed = 0;
		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			self::set_excluded( $post_id, $exclude );
			++$changed;
		}

		return add_query_arg(
			[
				'loupe_search_bulk'    => $exclude ? 'excluded' : 'included',
				'loupe_search_changed' => $changed,
			],
			remove_query_arg( [ 'loupe_search_bulk', 'loupe_search_changed' ], (string) $redirect_to )
		);
	}

	/**
	 * Confirm a bulk action on the post list.
	 *
	 * @return void
	 */
	public function bulk_action_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! isset( $_GET[ 'loupe_search_bulk' ], $_GET[ 'loupe_search_changed' ] ) || ! $this->is_indexed_screen( 'edit' ) ) {
			return;
		}
		$changed  = absint( $_GET[ 'loupe_search_changed' ] );
		$excluded = 'excluded' === sanitize_key( wp_unslash( $_GET[ 'loupe_search_bulk' ] ) );
		// phpcs:enable

		$message = $excluded
			/* translators: %s: number of posts. */
			? _n( '%s post excluded from search.', '%s posts excluded from search.', $changed, 'loupe-search' )
			/* translators: %s: number of posts. */
			: _n( '%s post included in search.', '%s posts included in search.', $changed, 'loupe-search' );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( $message, number_format_i18n( $changed ) ) )
		);
	}

	/**
	 * Seed the Quick Edit checkbox from the row being edited.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public function enqueue_list_assets( $hook ): void {
		if ( 'edit.php' !== $hook || ! $this->is_indexed_screen( 'edit' ) ) {
			return;
		}
		wp_enqueue_script(
			'loupe-search-exclude-quick-edit',
			LOUPE_SEARCH_URL . 'lib/js/exclude-quick-edit.js',
			[ 'inline-edit-post' ],
			$this->asset_version( 'lib/js/exclude-quick-edit.js' ),
			true
		);
	}

	/**
	 * Add the block-editor panel for indexed post types.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		if ( ! $this->is_indexed_screen( 'post' ) ) {
			return;
		}
		wp_enqueue_script(
			'loupe-search-exclude-editor',
			LOUPE_SEARCH_URL . 'lib/js/exclude-editor.js',
			[ 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ],
			$this->asset_version( 'lib/js/exclude-editor.js' ),
			true
		);
		wp_set_script_translations( 'loupe-search-exclude-editor', 'loupe-search', LOUPE_SEARCH_PATH . 'languages' );
	}

	/**
	 * Cache-busting version for a bundled asset.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	private function asset_version( string $relative ): string {
		$file = LOUPE_SEARCH_PATH . $relative;
		return file_exists( $file ) ? (string) filemtime( $file ) : '1';
	}
}
