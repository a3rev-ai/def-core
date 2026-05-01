<?php
/**
 * Per-item exclusion from Digital Employee knowledge ingestion.
 *
 * Stores a boolean post meta `_def_exclude_from_ingestion` on any
 * DEF-ingestible post type. When true, the DEF backend's wp_sync pipeline
 * skips indexing the item AND deletes any previously-indexed chunks on
 * the next sync run (full or incremental).
 *
 * Admin surfaces (v3.1.0 v1):
 *   - Quick Edit checkbox (posts / pages / CPTs / WC products list view)
 *   - Classic editor meta box (sidebar)
 *   - List column indicator
 *   - Bulk action ("Exclude from DEF" / "Include in DEF")
 *
 * Gutenberg sidebar panel deferred to v3.1.1 follow-up.
 *
 * @package def-core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DEF_Core_Knowledge_Exclusion {

	const META_KEY      = '_def_exclude_from_ingestion';
	const NONCE_FIELD   = 'def_core_exclusion_nonce';
	const NONCE_ACTION  = 'def_core_save_exclusion';
	const BULK_EXCLUDE  = 'def_core_exclude';
	const BULK_INCLUDE  = 'def_core_include';
	const COLUMN_KEY    = 'def_core_exclusion';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'register_admin_hooks' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );
		add_action( 'save_post', array( __CLASS__, 'save_post' ), 10, 1 );
	}

	/**
	 * Register the meta key on every DEF-ingestible post type.
	 *
	 * Public + REST-exposed are the gating criteria — those are the ones the
	 * DEF backend can read via the WP REST API during sync. Attachments are
	 * excluded because they're processed via parent rollup, not standalone.
	 */
	public static function register_meta(): void {
		foreach ( self::get_supported_post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_KEY,
				array(
					'type'          => 'boolean',
					'single'        => true,
					'default'       => false,
					'show_in_rest'  => true,
					'auth_callback' => array( __CLASS__, 'auth_can_edit_post' ),
				)
			);
		}
	}

	/**
	 * Per-meta auth check. WP core passes ($allowed, $meta_key, $object_id, $user_id, $cap, $caps).
	 * We only need object_id to delegate to standard edit_post capability.
	 *
	 * @param bool   $allowed
	 * @param string $meta_key
	 * @param int    $object_id
	 */
	public static function auth_can_edit_post( $allowed, $meta_key, $object_id ): bool {
		return current_user_can( 'edit_post', (int) $object_id );
	}

	/**
	 * @return array<int,string>
	 */
	public static function get_supported_post_types(): array {
		$post_types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			),
			'names'
		);
		unset( $post_types['attachment'] );
		return array_values( $post_types );
	}

	/**
	 * @param int $post_id
	 */
	public static function is_excluded( $post_id ): bool {
		return (bool) get_post_meta( (int) $post_id, self::META_KEY, true );
	}

	/**
	 * Register all admin-side hooks (list column, bulk actions, classic meta box).
	 */
	public static function register_admin_hooks(): void {
		foreach ( self::get_supported_post_types() as $post_type ) {
			add_filter(
				"manage_{$post_type}_posts_columns",
				array( __CLASS__, 'add_list_column' )
			);
			add_action(
				"manage_{$post_type}_posts_custom_column",
				array( __CLASS__, 'render_list_column' ),
				10,
				2
			);
			add_filter(
				"bulk_actions-edit-{$post_type}",
				array( __CLASS__, 'add_bulk_actions' )
			);
			add_filter(
				"handle_bulk_actions-edit-{$post_type}",
				array( __CLASS__, 'handle_bulk_actions' ),
				10,
				3
			);
			add_action(
				"add_meta_boxes_{$post_type}",
				array( __CLASS__, 'register_classic_meta_box' )
			);
		}

		add_action( 'quick_edit_custom_box', array( __CLASS__, 'add_quick_edit_field' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_bulk_action_notice' ) );
	}

	/**
	 * Enqueue admin assets — Quick Edit JS on list screens, Gutenberg sidebar
	 * panel on block-editor screens. Both gate on supported post types so we
	 * don't load on unrelated admin pages.
	 */
	public static function enqueue_admin_scripts( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		if ( ! in_array( $screen->post_type, self::get_supported_post_types(), true ) ) {
			return;
		}

		// Quick Edit (list view).
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

		// Gutenberg sidebar (block-editor screens).
		if ( $hook === 'post.php' || $hook === 'post-new.php' ) {
			$is_block_editor = method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();
			if ( ! $is_block_editor ) {
				return;
			}
			wp_enqueue_script(
				'def-core-knowledge-exclusion-gutenberg',
				DEF_CORE_PLUGIN_URL . 'assets/js/def-core-knowledge-exclusion-gutenberg.js',
				array(
					'wp-plugins',
					'wp-edit-post',
					'wp-editor',
					'wp-components',
					'wp-data',
					'wp-element',
					'wp-i18n',
				),
				DEF_CORE_VERSION,
				true
			);
			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations(
					'def-core-knowledge-exclusion-gutenberg',
					'digital-employees'
				);
			}
		}
	}

	// =======================================================================
	// LIST COLUMN
	// =======================================================================

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function add_list_column( array $columns ): array {
		$columns[ self::COLUMN_KEY ] = esc_html__( 'DEF', 'digital-employees' );
		return $columns;
	}

	/**
	 * @param string $column
	 * @param int    $post_id
	 */
	public static function render_list_column( $column, $post_id ): void {
		if ( $column !== self::COLUMN_KEY ) {
			return;
		}
		$excluded = self::is_excluded( $post_id );
		$label    = $excluded
			? esc_html__( 'Excluded', 'digital-employees' )
			: esc_html__( '—', 'digital-employees' );
		$title    = $excluded
			? esc_attr__( 'Excluded from Digital Employee knowledge base', 'digital-employees' )
			: esc_attr__( 'Included in Digital Employee knowledge base', 'digital-employees' );
		$style    = $excluded
			? 'color:#b91c1c;font-weight:600;'
			: 'color:#9ca3af;';

		printf(
			'<span class="def-core-exclusion-flag" data-excluded="%d" title="%s" style="%s">%s</span>',
			$excluded ? 1 : 0,
			$title,
			esc_attr( $style ),
			$label
		);
	}

	// =======================================================================
	// QUICK EDIT
	// =======================================================================

	/**
	 * @param string $column_name
	 * @param string $post_type
	 */
	public static function add_quick_edit_field( $column_name, $post_type ): void {
		if ( $column_name !== self::COLUMN_KEY ) {
			return;
		}
		if ( ! in_array( $post_type, self::get_supported_post_types(), true ) ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label class="inline-edit-group">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<input type="checkbox" name="<?php echo esc_attr( self::META_KEY ); ?>" value="1" />
					<span class="checkbox-title"><?php esc_html_e( 'Exclude from Digital Employee knowledge', 'digital-employees' ); ?></span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	// =======================================================================
	// CLASSIC EDITOR META BOX
	// =======================================================================

	/**
	 * @param \WP_Post $post
	 */
	public static function register_classic_meta_box( $post ): void {
		add_meta_box(
			'def_core_knowledge_exclusion',
			esc_html__( 'Digital Employees', 'digital-employees' ),
			array( __CLASS__, 'render_classic_meta_box' ),
			$post->post_type,
			'side',
			'default'
		);
	}

	/**
	 * @param \WP_Post $post
	 */
	public static function render_classic_meta_box( $post ): void {
		$excluded = self::is_excluded( $post->ID );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( self::META_KEY ); ?>" value="1" <?php checked( $excluded ); ?> />
				<?php esc_html_e( 'Exclude from Digital Employee knowledge', 'digital-employees' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'When checked, this item is skipped during knowledge ingestion. If it was previously indexed, it will be removed from the search index on the next sync (Tenant Portal → Knowledge → Sync Now — Full).', 'digital-employees' ); ?>
		</p>
		<?php
	}

	// =======================================================================
	// SAVE HANDLER (Quick Edit + Classic editor share this)
	// =======================================================================

	/**
	 * @param int $post_id
	 */
	public static function save_post( $post_id ): void {
		// Skip autosaves and revisions — they don't carry our nonce.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Nonce check — only proceed when our form was actually submitted.
		// Bulk actions don't carry our nonce; they go through handle_bulk_actions.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_verify_nonce handles its own validation.
		if ( ! wp_verify_nonce( $_POST[ self::NONCE_FIELD ], self::NONCE_ACTION ) ) {
			return;
		}

		// Capability check.
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}

		// Don't allow editing the meta on post types we don't manage.
		$post_type = get_post_type( $post_id );
		if ( ! $post_type || ! in_array( $post_type, self::get_supported_post_types(), true ) ) {
			return;
		}

		$value = isset( $_POST[ self::META_KEY ] ) && $_POST[ self::META_KEY ] === '1';
		update_post_meta( (int) $post_id, self::META_KEY, $value );
	}

	// =======================================================================
	// BULK ACTIONS
	// =======================================================================

	/**
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public static function add_bulk_actions( array $actions ): array {
		$actions[ self::BULK_EXCLUDE ] = esc_html__( 'Exclude from DEF knowledge', 'digital-employees' );
		$actions[ self::BULK_INCLUDE ] = esc_html__( 'Include in DEF knowledge', 'digital-employees' );
		return $actions;
	}

	/**
	 * @param string $redirect_url
	 * @param string $action
	 * @param array<int,int> $post_ids
	 */
	public static function handle_bulk_actions( $redirect_url, $action, $post_ids ): string {
		if ( $action !== self::BULK_EXCLUDE && $action !== self::BULK_INCLUDE ) {
			return $redirect_url;
		}
		$value   = ( $action === self::BULK_EXCLUDE );
		$updated = 0;
		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			$post_type = get_post_type( $post_id );
			if ( ! $post_type || ! in_array( $post_type, self::get_supported_post_types(), true ) ) {
				continue;
			}
			update_post_meta( $post_id, self::META_KEY, $value );
			$updated++;
		}
		return add_query_arg(
			array(
				'def_core_bulk_action' => $action,
				'def_core_bulk_count'  => $updated,
			),
			$redirect_url
		);
	}

	public static function maybe_show_bulk_action_notice(): void {
		if ( empty( $_GET['def_core_bulk_action'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_GET['def_core_bulk_action'] ) );
		$count  = isset( $_GET['def_core_bulk_count'] ) ? (int) $_GET['def_core_bulk_count'] : 0;

		if ( $action !== self::BULK_EXCLUDE && $action !== self::BULK_INCLUDE ) {
			return;
		}
		if ( $count < 1 ) {
			return;
		}

		$message = $action === self::BULK_EXCLUDE
			? sprintf(
				/* translators: %d: number of items. */
				_n( '%d item excluded from Digital Employee knowledge.', '%d items excluded from Digital Employee knowledge.', $count, 'digital-employees' ),
				$count
			)
			: sprintf(
				/* translators: %d: number of items. */
				_n( '%d item included in Digital Employee knowledge.', '%d items included in Digital Employee knowledge.', $count, 'digital-employees' ),
				$count
			);

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s %s</p></div>',
			esc_html( $message ),
			esc_html__( 'Run a Full Sync from the Tenant Portal to apply the change to the search index.', 'digital-employees' )
		);
	}
}
