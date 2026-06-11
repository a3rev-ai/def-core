<?php
/**
 * Class DEF_Core_Content_Drafts_Page
 *
 * Content Agent review queue in wp-admin. Lists pending staged content changes
 * from the DEF backend, shows the per-field diff (current → proposed), and lets a
 * staff user approve (write live), or dismiss. Rendering + the approve/dismiss
 * actions are client-side (def-core-draft-cards.js) against the Staff-AI BFF
 * routes; this page just hosts the container and hands the JS its REST base +
 * nonce.
 *
 * @package digital-employees
 * @since   4.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_Content_Drafts_Page {

	/**
	 * Initialize — register the submenu page.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu_page' ) );
	}

	/**
	 * Register the Content Drafts submenu page under Digital Employees.
	 *
	 * Registered only for users with Staff-AI access (def_staff_access OR
	 * def_management_access) — add_submenu_page takes a single cap string, so the
	 * OR is resolved here and the menu is simply absent for everyone else.
	 */
	public static function add_submenu_page(): void {
		if ( ! DEF_Core_Staff_AI::user_has_staff_ai_access() ) {
			return;
		}
		add_submenu_page(
			'def-core',
			__( 'Content Drafts', 'digital-employees' ),
			__( 'Content Drafts', 'digital-employees' ),
			'read', // visibility already gated above; render double-checks.
			'def-core-content-drafts',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the review-queue page shell + enqueue the draft-cards assets.
	 */
	public static function render_page(): void {
		if ( ! DEF_Core_Staff_AI::user_has_staff_ai_access() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'digital-employees' ) );
		}

		wp_enqueue_style( 'def-core-admin' );
		wp_enqueue_style( 'def-core-draft-cards' );
		wp_enqueue_script( 'def-core-draft-cards' );
		wp_enqueue_script( 'def-core-cluster-targets' );
		// The "Create a post" control creates a brand-new WP draft, so gate it on
		// the post-creation capability (resolved here; the bridge re-checks server-side).
		$post_type   = get_post_type_object( 'post' );
		$create_cap  = ( $post_type && isset( $post_type->cap->create_posts ) ) ? $post_type->cap->create_posts : 'edit_posts';
		$can_create  = current_user_can( $create_cap );

		wp_localize_script(
			'def-core-draft-cards',
			'DefDraftCards',
			array(
				// REST base for the Staff-AI content BFF routes (list / apply / dismiss / create).
				'restBase'  => esc_url_raw( rest_url( DEF_CORE_API_NAME_SPACE . '/staff-ai/content' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'canCreate' => $can_create ? 1 : 0,
			)
		);

		?>
		<div class="wrap def-core-wrap" style="max-width: 1000px;">
			<h1><?php esc_html_e( 'Content Drafts', 'digital-employees' ); ?></h1>
			<h2 class="nav-tab-wrapper def-draft-tabs">
				<a href="#improve" class="nav-tab nav-tab-active" data-def-tab="improve"><?php esc_html_e( 'Improve', 'digital-employees' ); ?></a>
				<a href="#clusters" class="nav-tab" data-def-tab="clusters"><?php esc_html_e( 'Clusters', 'digital-employees' ); ?></a>
				<a href="#create" class="nav-tab" data-def-tab="create"><?php esc_html_e( 'Create', 'digital-employees' ); ?></a>
			</h2>
			<div id="def-tab-improve" class="def-draft-tab-panel">
				<p class="description">
					<?php esc_html_e( 'Improvements the Content Agent has drafted for your existing content. Review each one and approve to apply it, or dismiss it. Nothing is changed on your site until you approve it.', 'digital-employees' ); ?>
				</p>
				<div id="def-draft-cards-root" data-loading="1">
					<p class="def-draft-loading"><?php esc_html_e( 'Loading drafts…', 'digital-employees' ); ?></p>
				</div>
			</div>
			<div id="def-tab-clusters" class="def-draft-tab-panel" style="display:none;">
				<p class="description">
					<?php esc_html_e( 'Build topic clusters around your cornerstone content. Nominate your most important pages and products as cluster targets — realistically 5–20 cornerstones, not every product — curate the keyphrase queue the Content Agent derives for each, and the agent writes the cluster posts from the approved queue. A healthy cluster is the cornerstone plus 6–12 supporting posts.', 'digital-employees' ); ?>
				</p>
				<div id="def-cluster-root" data-loading="1">
					<p class="def-draft-loading"><?php esc_html_e( 'Loading targets…', 'digital-employees' ); ?></p>
				</div>
			</div>
			<div id="def-tab-create" class="def-draft-tab-panel" style="display:none;">
				<p class="description">
					<?php esc_html_e( 'Ask the Content Agent for a one-off post — events, promotions, standalone articles, or a cornerstone to build a cluster on. The draft appears below for review; approve it to create a WordPress draft.', 'digital-employees' ); ?>
				</p>
				<div id="def-draft-create"></div>
				<div id="def-draft-create-cards-root"></div>
			</div>
		</div>
		<?php
	}
}
