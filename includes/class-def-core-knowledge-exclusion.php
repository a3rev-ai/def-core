<?php
/**
 * Per-item exclusion from Digital Employee knowledge ingestion.
 *
 * Stores `_def_exclude_from_ingestion` post meta (REST-exposed). DEF
 * backend's wp_sync skips flagged items + removes them from the indexes.
 * Admin surfaces: Gutenberg sidebar, classic meta box, Quick Edit, bulk
 * action, list column indicator.
 *
 * Per-item deindex (no Full Sync required): a change to the flag is detected
 * at the meta-write itself (covers every edit surface — Gutenberg REST,
 * classic, Quick Edit, bulk, programmatic) and:
 *  - records the transition for the `/content/deleted` feed's `excluded_ids`,
 *    which the DEF Search index reads to drop the stale object; and
 *  - bumps `post_modified` so the next INCREMENTAL content/products export
 *    re-fetches the item — the chunk (knowledge) index then deindexes it
 *    (still flagged) or re-ingests it (re-included) without a Full Sync.
 *
 * @package def-core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DEF_Core_Knowledge_Exclusion {

	const META_KEY     = '_def_exclude_from_ingestion';
	const NONCE_FIELD  = 'def_core_exclusion_nonce';
	const NONCE_ACTION = 'def_core_save_exclusion';
	const BULK_EXCLUDE = 'def_core_exclude';
	const BULK_INCLUDE = 'def_core_include';
	const COLUMN_KEY   = 'def_core_exclusion';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'register_admin_hooks' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );
		add_action( 'save_post', array( __CLASS__, 'save_post' ), 10, 1 );

		// Detect flag changes at the meta write itself so every edit surface is
		// covered (Gutenberg saves the flag via REST, NOT the save_post form
		// handler). Records the transition + bumps post_modified for per-item
		// deindex. Registered unconditionally (REST / WP-CLI / cron can write meta).
		add_action( 'added_post_meta', array( __CLASS__, 'on_exclusion_meta_write' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_exclusion_meta_write' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_exclusion_meta_delete' ), 10, 4 );
	}

	public static function register_meta(): void {
		foreach ( self::get_supported_post_types() as $post_type ) {
			register_post_meta( $post_type, self::META_KEY, array(
				'type'          => 'boolean',
				'single'        => true,
				'default'       => false,
				'show_in_rest'  => true,
				'auth_callback' => array( __CLASS__, 'auth_can_edit_post' ),
			) );
		}
	}

	/** WP core passes ($allowed, $meta_key, $object_id, ...) — only $object_id is used. */
	public static function auth_can_edit_post( $allowed, $meta_key, $object_id ): bool {
		return current_user_can( 'edit_post', (int) $object_id );
	}

	/** Public + REST-visible post types; attachment uses parent rollup, not standalone. */
	public static function get_supported_post_types(): array {
		$post_types = get_post_types( array( 'public' => true, 'show_in_rest' => true ), 'names' );
		unset( $post_types['attachment'] );
		return array_values( $post_types );
	}

	public static function is_excluded( $post_id ): bool {
		return (bool) get_post_meta( (int) $post_id, self::META_KEY, true );
	}

	// Per-item deindex on flag change -------------------------------------

	/**
	 * Fired on added_post_meta / updated_post_meta. WP passes
	 * ($meta_id, $object_id, $meta_key, $meta_value).
	 *
	 * @param int    $meta_id    Meta row id (unused).
	 * @param int    $post_id    Post the meta belongs to.
	 * @param string $meta_key   Meta key being written.
	 * @param mixed  $meta_value New meta value.
	 */
	public static function on_exclusion_meta_write( $meta_id, $post_id, $meta_key, $meta_value ): void {
		if ( $meta_key !== self::META_KEY ) {
			return;
		}
		// Truthy '1'/true/1 = excluded; '' / '0' / false = included.
		$excluded = ! empty( $meta_value ) && '0' !== (string) $meta_value;
		self::record_exclusion_transition( (int) $post_id, $excluded );
	}

	/**
	 * Fired on deleted_post_meta (flag removed === back to included). WP passes
	 * ($meta_ids, $object_id, $meta_key, $meta_value).
	 *
	 * @param array  $meta_ids   Meta row ids (unused).
	 * @param int    $post_id    Post the meta belonged to.
	 * @param string $meta_key   Meta key removed.
	 * @param mixed  $meta_value Prior value (unused).
	 */
	public static function on_exclusion_meta_delete( $meta_ids, $post_id, $meta_key, $meta_value ): void {
		if ( $meta_key !== self::META_KEY ) {
			return;
		}
		self::record_exclusion_transition( (int) $post_id, false );
	}

	/**
	 * Record an exclusion-flag transition for the deindex feed and bump
	 * post_modified so the next incremental sync re-evaluates this item only.
	 *
	 * @param int  $post_id  Post id.
	 * @param bool $excluded True when the item is now excluded.
	 */
	public static function record_exclusion_transition( int $post_id, bool $excluded ): void {
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$post_type = get_post_type( $post_id );
		if ( ! $post_type || ! in_array( $post_type, self::get_supported_post_types(), true ) ) {
			return;
		}

		// Feed the DEF Search index's deindex path (/content/deleted → excluded_ids).
		// The chunk (knowledge) index instead deindexes via /content/export, so it
		// needs the item re-fetched — the post_modified bump below handles that.
		if ( class_exists( 'DEF_Core_Knowledge_Export' ) ) {
			DEF_Core_Knowledge_Export::track_exclusion_change( $post_id, (string) $post_type, $excluded );
		}

		self::touch_post_modified( $post_id );
	}

	/**
	 * Bump post_modified / post_modified_gmt to now so the item re-enters the
	 * incremental content/products export window. Direct SQL (not wp_update_post)
	 * to avoid re-entrancy with the in-progress save — no save_post /
	 * transition_post_status / meta hooks refire.
	 *
	 * @param int $post_id Post id.
	 */
	private static function touch_post_modified( int $post_id ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'update' ) ) {
			return;
		}
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			),
			array( 'ID' => $post_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}
	}

	public static function register_admin_hooks(): void {
		foreach ( self::get_supported_post_types() as $pt ) {
			add_filter( "manage_{$pt}_posts_columns", array( __CLASS__, 'add_list_column' ) );
			add_action( "manage_{$pt}_posts_custom_column", array( __CLASS__, 'render_list_column' ), 10, 2 );
			add_filter( "bulk_actions-edit-{$pt}", array( __CLASS__, 'add_bulk_actions' ) );
			add_filter( "handle_bulk_actions-edit-{$pt}", array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
			add_action( "add_meta_boxes_{$pt}", array( __CLASS__, 'register_classic_meta_box' ) );
		}
		add_action( 'quick_edit_custom_box', array( __CLASS__, 'add_quick_edit_field' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_bulk_action_notice' ) );
	}

	public static function enqueue_admin_scripts( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, self::get_supported_post_types(), true ) ) {
			return;
		}
		if ( $hook === 'edit.php' ) {
			wp_enqueue_script(
				'def-core-knowledge-exclusion-quick-edit',
				DEF_CORE_PLUGIN_URL . 'assets/js/def-core-knowledge-exclusion-quick-edit.js',
				array( 'jquery', 'inline-edit-post' ),
				DEF_CORE_VERSION,
				true
			);
			return;
		}
		$is_block = ( $hook === 'post.php' || $hook === 'post-new.php' )
			&& method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();
		if ( $is_block ) {
			wp_enqueue_script(
				'def-core-knowledge-exclusion-gutenberg',
				DEF_CORE_PLUGIN_URL . 'assets/js/def-core-knowledge-exclusion-gutenberg.js',
				array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ),
				DEF_CORE_VERSION,
				true
			);
		}
	}

	// List column ----------------------------------------------------------

	public static function add_list_column( array $columns ): array {
		$columns[ self::COLUMN_KEY ] = esc_html__( 'DEF', 'digital-employees' );
		return $columns;
	}

	public static function render_list_column( $column, $post_id ): void {
		if ( $column !== self::COLUMN_KEY ) {
			return;
		}
		$on    = self::is_excluded( $post_id );
		$color = $on ? '#b91c1c' : '#16a34a';
		$label = $on
			? esc_attr__( 'Excluded from Digital Employee knowledge', 'digital-employees' )
			: esc_attr__( 'Included in Digital Employee knowledge', 'digital-employees' );
		printf(
			'<span class="def-core-exclusion-flag" data-excluded="%d" title="%s" aria-label="%s" style="display:inline-block;width:12px;height:12px;border-radius:50%%;background:%s;vertical-align:middle;"></span>',
			$on ? 1 : 0,
			$label,
			$label,
			esc_attr( $color )
		);
	}

	// Quick Edit + classic meta box ----------------------------------------

	public static function add_quick_edit_field( $column_name, $post_type ): void {
		if ( $column_name !== self::COLUMN_KEY ) {
			return;
		}
		if ( ! in_array( $post_type, self::get_supported_post_types(), true ) ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right"><div class="inline-edit-col">
			<label class="inline-edit-group">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="checkbox" name="<?php echo esc_attr( self::META_KEY ); ?>" value="1" />
				<span class="checkbox-title"><?php esc_html_e( 'Exclude from Digital Employee knowledge', 'digital-employees' ); ?></span>
			</label>
		</div></fieldset>
		<?php
	}

	public static function register_classic_meta_box( $post ): void {
		add_meta_box(
			'def_core_knowledge_exclusion',
			esc_html__( 'Digital Employees', 'digital-employees' ),
			array( __CLASS__, 'render_classic_meta_box' ),
			$post->post_type,
			'side'
		);
	}

	public static function render_classic_meta_box( $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<p><label>
			<input type="checkbox" name="<?php echo esc_attr( self::META_KEY ); ?>" value="1" <?php checked( self::is_excluded( $post->ID ) ); ?> />
			<?php esc_html_e( 'Exclude from Digital Employee knowledge', 'digital-employees' ); ?>
		</label></p>
		<p class="description">
			<?php esc_html_e( 'When checked, this item is excluded from Digital Employee knowledge. If it was already indexed, it is removed on the next sync — this item only, no Full Sync needed.', 'digital-employees' ); ?>
		</p>
		<?php
	}

	/** Save handler — shared between Quick Edit and classic meta box (same nonce). */
	public static function save_post( $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! wp_verify_nonce( $_POST[ self::NONCE_FIELD ], self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}
		$post_type = get_post_type( $post_id );
		if ( ! $post_type || ! in_array( $post_type, self::get_supported_post_types(), true ) ) {
			return;
		}
		$value = isset( $_POST[ self::META_KEY ] ) && $_POST[ self::META_KEY ] === '1';
		update_post_meta( (int) $post_id, self::META_KEY, $value );
	}

	// Bulk actions ---------------------------------------------------------

	public static function add_bulk_actions( array $actions ): array {
		$actions[ self::BULK_EXCLUDE ] = esc_html__( 'Exclude from DEF knowledge', 'digital-employees' );
		$actions[ self::BULK_INCLUDE ] = esc_html__( 'Include in DEF knowledge', 'digital-employees' );
		return $actions;
	}

	public static function handle_bulk_actions( $redirect_url, $action, $post_ids ): string {
		if ( $action !== self::BULK_EXCLUDE && $action !== self::BULK_INCLUDE ) {
			return $redirect_url;
		}
		$value      = ( $action === self::BULK_EXCLUDE );
		$post_types = self::get_supported_post_types();
		$updated    = 0;
		foreach ( (array) $post_ids as $pid ) {
			$pid = (int) $pid;
			if ( ! $pid || ! current_user_can( 'edit_post', $pid ) ) {
				continue;
			}
			if ( ! in_array( get_post_type( $pid ), $post_types, true ) ) {
				continue;
			}
			update_post_meta( $pid, self::META_KEY, $value );
			$updated++;
		}
		return add_query_arg(
			array( 'def_core_bulk_action' => $action, 'def_core_bulk_count' => $updated ),
			$redirect_url
		);
	}

	public static function maybe_show_bulk_action_notice(): void {
		if ( empty( $_GET['def_core_bulk_action'] ) || empty( $_GET['def_core_bulk_count'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_GET['def_core_bulk_action'] ) );
		if ( $action !== self::BULK_EXCLUDE && $action !== self::BULK_INCLUDE ) {
			return;
		}
		$count = (int) $_GET['def_core_bulk_count'];
		$verb  = $action === self::BULK_EXCLUDE ? 'excluded from' : 'included in';
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf(
				'%d items %s Digital Employee knowledge. Applied per item on the next sync — no Full Sync needed.',
				$count, $verb
			) )
		);
	}
}
