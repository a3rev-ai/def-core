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
		wp_localize_script(
			'def-core-draft-cards',
			'DefDraftCards',
			array(
				// REST base for the Staff-AI content BFF routes (list / apply / dismiss).
				'restBase' => esc_url_raw( rest_url( DEF_CORE_API_NAME_SPACE . '/staff-ai/content' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);

		?>
		<div class="wrap def-core-wrap" style="max-width: 1000px;">
			<h1><?php esc_html_e( 'Content Drafts', 'digital-employees' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Improvements the Content Agent has drafted for your products. Review each one and approve to publish it live, or dismiss it. Nothing is changed on your site until you approve it.', 'digital-employees' ); ?>
			</p>
			<div id="def-draft-cards-root" data-loading="1">
				<p class="def-draft-loading"><?php esc_html_e( 'Loading drafts…', 'digital-employees' ); ?></p>
			</div>
		</div>
		<?php
	}
}
