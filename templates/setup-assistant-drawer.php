<?php
/**
 * Setup Assistant drawer template.
 *
 * Renders the right-side slide-in chat panel and trigger button.
 * Included by DEF_Core_Admin::render_settings_page().
 *
 * @package def-core
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'def_admin_access' ) ) {
	return;
}
?>

<!-- Setup Assistant trigger button -->
<button
	type="button"
	id="def-setup-assistant-trigger"
	class="def-sa-trigger"
	aria-expanded="false"
	aria-controls="def-setup-assistant-drawer"
	title="<?php esc_attr_e( 'Setup Assistant', 'def-core' ); ?>"
>
	<span class="dashicons dashicons-admin-comments"></span>
	<span class="def-sa-trigger-label"><?php esc_html_e( 'Setup Assistant', 'def-core' ); ?></span>
</button>

<!-- Drawer container -->
<div
	id="def-setup-assistant-drawer"
	class="def-sa-drawer"
	aria-hidden="true"
	role="dialog"
	aria-label="<?php esc_attr_e( 'Setup Assistant', 'def-core' ); ?>"
>
	<!-- Backdrop -->
	<div class="def-sa-backdrop"></div>

	<!-- Panel -->
	<div class="def-sa-panel" role="document">
		<!-- Header -->
		<div class="def-sa-header">
			<h2 class="def-sa-title"><?php esc_html_e( 'Setup Assistant', 'def-core' ); ?></h2>
			<div class="def-sa-header-actions">
				<button type="button" class="def-sa-clear" title="<?php esc_attr_e( 'Clear conversation', 'def-core' ); ?>">
					<span class="dashicons dashicons-trash"></span>
				</button>
				<button type="button" class="def-sa-close" aria-label="<?php esc_attr_e( 'Close Setup Assistant', 'def-core' ); ?>">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</div>
		</div>

		<!-- Messages area -->
		<div class="def-sa-messages" role="log" aria-live="polite" aria-relevant="additions"></div>

		<!-- Composer footer -->
		<div class="def-sa-composer">
			<textarea
				class="def-sa-input"
				placeholder="<?php esc_attr_e( 'Ask about your setup...', 'def-core' ); ?>"
				rows="1"
				aria-label="<?php esc_attr_e( 'Message', 'def-core' ); ?>"
			></textarea>
			<button type="button" class="def-sa-send" aria-label="<?php esc_attr_e( 'Send message', 'def-core' ); ?>">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
			</button>
		</div>
	</div>
</div>
