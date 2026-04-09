<?php
/**
 * Staff AI application shell template.
 *
 * This is a standalone HTML document (not embedded in wp-admin).
 * It does NOT call wp_head() / wp_footer() by design — assets are
 * loaded via direct <link> and <script> tags.
 *
 * Expected variables (set by render_shell() before inclusion):
 * @var string  $channel   Always 'staff_ai'
 * @var WP_User $user      Current authenticated user
 * @var string  $api_base  REST API base URL
 * @var string  $nonce     WordPress REST nonce
 * @var string  $logo_html Pre-built HTML for header logo
 *
 * @package def-core
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html__( 'Staff AI', 'digital-employees' ); ?> - <?php bloginfo( 'name' ); ?></title>
	<link rel="manifest" href="<?php echo esc_url( home_url( '/staff-ai/manifest.json' ) ); ?>">
	<meta name="theme-color" content="#6366f1">
	<link rel="stylesheet" href="<?php echo esc_url( DEF_CORE_PLUGIN_URL . 'assets/css/staff-ai.css' ); ?>?ver=<?php echo esc_attr( DEF_CORE_VERSION ); ?>">
	<script>
	// Theme init — must run before body renders to prevent flash.
	(function() {
		var saved = localStorage.getItem('staff-ai-theme');
		if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
			document.documentElement.classList.add('dark-theme');
		}
	})();
	</script>
</head>
<body>

	<div id="staff-ai-app"
		data-channel="<?php echo esc_attr( $channel ); ?>"
		data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"
		data-user-email="<?php echo esc_attr( $user->user_email ); ?>"
		data-api-base="<?php echo esc_url( $api_base ); ?>"
		data-nonce="<?php echo esc_attr( $nonce ); ?>">

		<!-- Sidebar overlay for mobile -->
		<div class="sidebar-overlay" id="sidebarOverlay"></div>

		<!-- Sidebar -->
		<aside class="sidebar" id="sidebar">
			<div class="sidebar-header">
				<button type="button" class="new-chat-btn" id="newChatBtn">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<line x1="12" y1="5" x2="12" y2="19"></line>
						<line x1="5" y1="12" x2="19" y2="12"></line>
					</svg>
					<?php echo esc_html__( 'New chat', 'digital-employees' ); ?>
				</button>
			</div>
			<nav class="conversation-list" id="conversationList">
				<div class="conversation-list-placeholder" id="conversationPlaceholder">
					<?php echo esc_html__( 'No conversations yet', 'digital-employees' ); ?>
				</div>
			</nav>
			<div class="sidebar-footer">
				<?php echo esc_html__( 'Powered by DEF', 'digital-employees' ); ?>
			</div>
		</aside>

		<!-- Main chat -->
		<main class="chat-container">
			<header class="chat-header">
				<button type="button" class="menu-toggle" id="menuToggle" aria-label="<?php echo esc_attr__( 'Toggle menu', 'digital-employees' ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<line x1="3" y1="6" x2="21" y2="6"></line>
						<line x1="3" y1="12" x2="21" y2="12"></line>
						<line x1="3" y1="18" x2="21" y2="18"></line>
					</svg>
				</button>
				<div class="header-logo"><?php echo $logo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Logo is escaped in wp_get_attachment_image or esc_html ?></div>
				<span class="readonly-indicator" id="readonlyIndicator"><?php echo esc_html__( 'Read-only (shared)', 'digital-employees' ); ?></span>
				<div class="header-actions">
					<button type="button" class="header-btn" id="exportBtn" disabled><?php echo esc_html__( 'Export', 'digital-employees' ); ?></button>
					<button type="button" class="header-btn" id="shareBtn" disabled><?php echo esc_html__( 'Share', 'digital-employees' ); ?></button>
					<button type="button" class="header-btn header-btn-install" id="installBtn" style="display:none;" title="<?php echo esc_attr__( 'Install app', 'digital-employees' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
							<polyline points="7 10 12 15 17 10"></polyline>
							<line x1="12" y1="15" x2="12" y2="3"></line>
						</svg>
						<?php echo esc_html__( 'Install', 'digital-employees' ); ?>
					</button>
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="header-btn header-btn-icon" target="_blank" rel="noopener" title="<?php echo esc_attr__( 'Go to website', 'digital-employees' ); ?>" aria-label="<?php echo esc_attr__( 'Go to website', 'digital-employees' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
							<polyline points="15 3 21 3 21 9"></polyline>
							<line x1="10" y1="14" x2="21" y2="3"></line>
						</svg>
					</a>
					<a href="<?php echo esc_url( wp_logout_url( wp_login_url( home_url( '/staff-ai/' ) ) ) ); ?>" class="header-btn header-btn-icon" title="<?php echo esc_attr__( 'Log out', 'digital-employees' ); ?>" aria-label="<?php echo esc_attr__( 'Log out', 'digital-employees' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
							<polyline points="16 17 21 12 16 7"></polyline>
							<line x1="21" y1="12" x2="9" y2="12"></line>
						</svg>
					</a>
					<button type="button" class="theme-toggle" id="themeToggle" aria-label="<?php echo esc_attr__( 'Toggle theme', 'digital-employees' ); ?>">
						<svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<circle cx="12" cy="12" r="5"></circle>
							<line x1="12" y1="1" x2="12" y2="3"></line>
							<line x1="12" y1="21" x2="12" y2="23"></line>
							<line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
							<line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
							<line x1="1" y1="12" x2="3" y2="12"></line>
							<line x1="21" y1="12" x2="23" y2="12"></line>
							<line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
							<line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
						</svg>
						<svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
						</svg>
					</button>
					<!-- Mobile overflow menu -->
					<button type="button" class="header-overflow-toggle" id="headerOverflowToggle" aria-label="<?php echo esc_attr__( 'More options', 'digital-employees' ); ?>">
						<svg viewBox="0 0 24 24" fill="currentColor">
							<circle cx="12" cy="5" r="2"></circle>
							<circle cx="12" cy="12" r="2"></circle>
							<circle cx="12" cy="19" r="2"></circle>
						</svg>
					</button>
					<div class="header-overflow-menu" id="headerOverflowMenu">
						<button type="button" id="overflowExport"><?php echo esc_html__( 'Export', 'digital-employees' ); ?></button>
						<button type="button" id="overflowShare"><?php echo esc_html__( 'Share', 'digital-employees' ); ?></button>
						<button type="button" id="overflowCreate">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
								<polyline points="14 2 14 8 20 8"></polyline>
								<line x1="12" y1="11" x2="12" y2="17"></line>
								<line x1="9" y1="14" x2="15" y2="14"></line>
							</svg>
							<?php echo esc_html__( 'Create', 'digital-employees' ); ?>
						</button>
						<button type="button" id="overflowInstall" style="display:none;">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
								<polyline points="7 10 12 15 17 10"></polyline>
								<line x1="12" y1="15" x2="12" y2="3"></line>
							</svg>
							<?php echo esc_html__( 'Install app', 'digital-employees' ); ?>
						</button>
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
								<polyline points="15 3 21 3 21 9"></polyline>
								<line x1="10" y1="14" x2="21" y2="3"></line>
							</svg>
							<?php echo esc_html__( 'Go to website', 'digital-employees' ); ?>
						</a>
						<button type="button" id="overflowThemeToggle">
							<svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<circle cx="12" cy="12" r="5"></circle>
								<line x1="12" y1="1" x2="12" y2="3"></line>
								<line x1="12" y1="21" x2="12" y2="23"></line>
							</svg>
							<svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
							</svg>
							<?php echo esc_html__( 'Toggle theme', 'digital-employees' ); ?>
						</button>
						<a href="<?php echo esc_url( wp_logout_url( wp_login_url( home_url( '/staff-ai/' ) ) ) ); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
								<polyline points="16 17 21 12 16 7"></polyline>
								<line x1="21" y1="12" x2="9" y2="12"></line>
							</svg>
							<?php echo esc_html__( 'Log out', 'digital-employees' ); ?>
						</a>
					</div>
				</div>
			</header>

			<div class="messages-container" id="messagesContainer">
				<div class="messages-list" id="messagesList">
					<div class="welcome-message" id="welcomeMessage">
						<?php
						$first_name   = $user->first_name ?: $user->display_name;
						$display_name = get_option( 'def_core_display_name', get_bloginfo( 'name' ) );
						$role_label   = $user->has_cap( 'def_management_access' )
							? __( 'Management Assistant', 'digital-employees' )
							: __( 'Staff Assistant', 'digital-employees' );
						$woo_active   = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
						$is_manager   = $user->has_cap( 'def_management_access' );
						?>
						<div id="welcomeFull">
							<p><strong><?php printf( esc_html__( 'Hi %s! I\'m your %s %s.', 'digital-employees' ), esc_html( $first_name ), esc_html( $display_name ), esc_html( $role_label ) ); ?></strong></p>
							<p><?php esc_html_e( 'Here\'s what I can help you with:', 'digital-employees' ); ?></p>
							<ul>
								<li><?php esc_html_e( 'Answer questions and explain concepts (technical, business, strategy)', 'digital-employees' ); ?></li>
								<?php if ( $is_manager ) : ?>
								<li><?php esc_html_e( 'Help with planning, decision-making, and management-level analysis', 'digital-employees' ); ?></li>
								<li><?php esc_html_e( 'Access management-level documents and guidance', 'digital-employees' ); ?></li>
								<?php endif; ?>
								<?php if ( $woo_active ) : ?>
								<li><?php esc_html_e( 'Search products and look up details', 'digital-employees' ); ?></li>
								<li><?php esc_html_e( 'Look up customer orders and order status', 'digital-employees' ); ?></li>
								<?php endif; ?>
								<li><?php esc_html_e( 'Answer questions from the knowledge base', 'digital-employees' ); ?></li>
								<li><?php esc_html_e( 'Draft documents such as reports, policies, memos, and proposals', 'digital-employees' ); ?></li>
								<li><?php esc_html_e( 'Create downloadable files (DOCX, PDF, Markdown, spreadsheets, images)', 'digital-employees' ); ?></li>
								<li><?php esc_html_e( 'Summarise documents or extract information from uploaded files', 'digital-employees' ); ?></li>
								<li><?php esc_html_e( 'Help analyse data or structure information for spreadsheets', 'digital-employees' ); ?></li>
								<li><?php esc_html_e( 'Generate images or diagrams from descriptions', 'digital-employees' ); ?></li>
								<li><?php esc_html_e( 'Help write or review code and technical documentation', 'digital-employees' ); ?></li>
								<li><?php esc_html_e( 'Brainstorm ideas, strategies, or solutions', 'digital-employees' ); ?></li>
								<li><?php esc_html_e( 'Keep track of your preferences and project context across conversations', 'digital-employees' ); ?></li>
								<li><?php esc_html_e( 'Share a conversation with your team via email', 'digital-employees' ); ?></li>
							</ul>
							<p><?php esc_html_e( 'What can I help you with?', 'digital-employees' ); ?></p>
						</div>
						<p id="welcomeTip" class="welcome-tip" style="display:none;"></p>
					</div>
				</div>
			</div>

			<div class="info-banner" id="infoBanner"></div>
			<div class="error-banner" id="errorBanner"></div>

			<div class="composer-container" id="composerContainer">
				<!-- Drop overlay -->
				<div class="upload-drop-overlay" id="uploadDropOverlay">
					<div class="upload-drop-overlay-content">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
							<polyline points="17 8 12 3 7 8"></polyline>
							<line x1="12" y1="3" x2="12" y2="15"></line>
						</svg>
						<span><?php echo esc_html__( 'Drop files here', 'digital-employees' ); ?></span>
					</div>
				</div>
				<!-- Hidden file input -->
				<input type="file" id="uploadFileInput" class="sr-only" multiple
					accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,.txt,.md,.csv,.docx,.xlsx" />
				<div class="composer-wrapper">
					<!-- Staged files area -->
					<div class="upload-staged-area" id="uploadStagedArea" style="display: none;"
						aria-live="polite" aria-relevant="additions removals"></div>
					<div class="composer-row">
						<div class="composer">
							<div class="composer-scroll" id="composerScroll">
								<textarea
									class="composer-input"
									id="composerInput"
									placeholder="<?php echo esc_attr__( 'Send a message...', 'digital-employees' ); ?>"
									rows="1"></textarea>
							</div>
							<div class="composer-toolbar">
								<button type="button" class="upload-btn" id="uploadBtn"
									aria-label="<?php echo esc_attr__( 'Attach file', 'digital-employees' ); ?>"
									title="<?php echo esc_attr__( 'Attach file', 'digital-employees' ); ?>">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
										stroke-linecap="round" stroke-linejoin="round">
										<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
									</svg>
								</button>
								<button type="button" class="send-btn" id="sendBtn" disabled aria-label="<?php echo esc_attr__( 'Send message', 'digital-employees' ); ?>">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<line x1="22" y1="2" x2="11" y2="13"></line>
										<polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
									</svg>
								</button>
							</div>
						</div>
						<button type="button" class="create-btn" id="createBtn">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
								<polyline points="14 2 14 8 20 8"></polyline>
								<line x1="12" y1="11" x2="12" y2="17"></line>
								<line x1="9" y1="14" x2="15" y2="14"></line>
							</svg>
							<?php echo esc_html__( 'Create', 'digital-employees' ); ?>
						</button>
					</div>
					<div class="composer-hint">
						<?php echo esc_html__( 'Press Enter to send, Shift+Enter for new line', 'digital-employees' ); ?>
					</div>
				</div>
			</div>
		</main>

		<!-- Share Modal -->
		<div class="modal-overlay" id="shareModal">
			<div class="modal" style="max-width: 520px;">
				<div class="modal-header">
					<span class="modal-title"><?php echo esc_html__( 'Share Conversation', 'digital-employees' ); ?></span>
					<button type="button" class="modal-close" id="shareModalClose">&times;</button>
				</div>
				<!-- Loading state -->
				<div class="share-loading" id="shareLoading">
					<div class="share-loading-spinner"></div>
					<p><?php echo esc_html__( 'Preparing share form...', 'digital-employees' ); ?></p>
				</div>
				<!-- Error state -->
				<div class="share-error" id="shareError" style="display:none;">
					<p id="shareErrorText"></p>
					<button type="button" class="modal-btn modal-btn-secondary" id="shareErrorClose"><?php echo esc_html__( 'Close', 'digital-employees' ); ?></button>
				</div>
				<!-- Form state -->
				<div id="shareFormContent" style="display:none;">
					<div class="modal-body">
						<div class="form-group">
							<label class="form-label"><?php echo esc_html__( 'Share with', 'digital-employees' ); ?></label>
							<div class="token-select" id="shareRecipientTokenSelect">
								<div class="token-select-tokens" id="shareRecipientTokens">
									<span class="token-select-placeholder" id="shareRecipientPlaceholder"><?php echo esc_html__( 'Click to select recipients...', 'digital-employees' ); ?></span>
								</div>
								<div class="token-select-dropdown" id="shareRecipientDropdown"></div>
							</div>
						</div>
						<div class="form-group">
							<label class="form-label"><?php echo esc_html__( 'Subject', 'digital-employees' ); ?></label>
							<input type="text" class="form-input" id="shareSubject" placeholder="<?php echo esc_attr__( 'Brief summary...', 'digital-employees' ); ?>">
						</div>
						<div class="form-group">
							<label class="form-label"><?php echo esc_html__( 'Message', 'digital-employees' ); ?></label>
							<textarea class="form-input share-message-input" id="shareMessage" rows="4" placeholder="<?php echo esc_attr__( 'Summary and context for the recipient...', 'digital-employees' ); ?>"></textarea>
						</div>
						<div class="share-transcript-toggle">
							<label class="share-toggle-label">
								<input type="checkbox" id="shareTranscript" checked>
								<svg class="share-paperclip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
								<?php echo esc_html__( 'Include conversation transcript', 'digital-employees' ); ?>
							</label>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="modal-btn modal-btn-secondary" id="shareCancel"><?php echo esc_html__( 'Cancel', 'digital-employees' ); ?></button>
						<button type="button" class="modal-btn modal-btn-primary" id="shareSend" disabled><?php echo esc_html__( 'Send', 'digital-employees' ); ?></button>
					</div>
				</div>
			</div>
		</div>

		<!-- Create Tool Modal -->
		<div class="modal-overlay" id="createModal">
			<div class="modal" style="max-width: 480px;">
				<div class="modal-header">
					<span class="modal-title"><?php echo esc_html__( 'Create', 'digital-employees' ); ?></span>
					<button type="button" class="modal-close" id="createModalClose">&times;</button>
				</div>
				<div class="modal-body">
					<div class="form-group">
						<label class="form-label"><?php echo esc_html__( 'Type', 'digital-employees' ); ?></label>
						<select class="form-input" id="createToolType">
							<option value="document_creation"><?php echo esc_html__( 'Document', 'digital-employees' ); ?></option>
							<option value="spreadsheet_creation"><?php echo esc_html__( 'Spreadsheet', 'digital-employees' ); ?></option>
							<option value="image_generation"><?php echo esc_html__( 'Image', 'digital-employees' ); ?></option>
						</select>
					</div>
					<div class="form-group" id="createFormatGroup">
						<label class="form-label"><?php echo esc_html__( 'Format', 'digital-employees' ); ?></label>
						<select class="form-input" id="createFormat">
							<option value="docx">DOCX</option>
							<option value="pdf">PDF</option>
							<option value="md">Markdown</option>
						</select>
					</div>
					<div class="form-group">
						<label class="form-label"><?php echo esc_html__( 'Title (optional)', 'digital-employees' ); ?></label>
						<input type="text" class="form-input" id="createTitle" placeholder="<?php echo esc_attr__( 'My Document', 'digital-employees' ); ?>">
					</div>
					<div class="form-group">
						<label class="form-label"><?php echo esc_html__( 'Instructions', 'digital-employees' ); ?> <span style="color: var(--share-error-text);">*</span></label>
						<textarea class="form-input" id="createPrompt" rows="4" placeholder="<?php echo esc_attr__( 'Describe what you want to create...', 'digital-employees' ); ?>"></textarea>
					</div>
					<div class="error-banner" id="createError" style="margin: 0;"></div>
				</div>
				<div class="modal-footer">
					<button type="button" class="modal-btn modal-btn-secondary" id="createCancel"><?php echo esc_html__( 'Cancel', 'digital-employees' ); ?></button>
					<button type="button" class="modal-btn modal-btn-primary" id="createSubmit"><?php echo esc_html__( 'Create', 'digital-employees' ); ?></button>
				</div>
			</div>
		</div>
	</div>

	<script>
	var StaffAIConfig = {
		channel: <?php echo wp_json_encode( $channel ); ?>,
		userId: <?php echo wp_json_encode( (string) $user->ID ); ?>,
		userEmail: <?php echo wp_json_encode( $user->user_email ); ?>,
		apiBase: <?php echo wp_json_encode( $api_base ); ?>,
		nonce: <?php echo wp_json_encode( $nonce ); ?>,
		homeUrl: <?php echo wp_json_encode( home_url( '/' ) ); ?>,
		chatStreamUrl: <?php echo wp_json_encode( rest_url( DEF_CORE_API_NAME_SPACE . '/staff-ai/chat/stream' ) ); ?>,
		userName: <?php echo wp_json_encode( $first_name ); ?>,
		tips: <?php
			$tips = array(
				__( 'Did you know? I can summarise documents or extract information from uploaded files.', 'digital-employees' ),
				__( 'Did you know? You can drag and drop files directly into the chat to share them with me.', 'digital-employees' ),
				__( 'Did you know? I can create professional documents in Word, PDF, or Markdown format.', 'digital-employees' ),
				__( 'Did you know? I can generate spreadsheets from data you describe or provide.', 'digital-employees' ),
				__( 'Did you know? I can generate images and diagrams from your descriptions.', 'digital-employees' ),
				__( 'Did you know? I can help you brainstorm ideas, strategies, or solutions.', 'digital-employees' ),
				__( 'Did you know? I can help write or review code and technical documentation.', 'digital-employees' ),
				__( 'Did you know? I remember your preferences and project context across conversations.', 'digital-employees' ),
				__( 'Did you know? I can search the knowledge base to find answers for you.', 'digital-employees' ),
				__( 'Did you know? You can share any conversation with your team via email using the Share button.', 'digital-employees' ),
				__( 'Did you know? I can help structure processes, workflows, and frameworks.', 'digital-employees' ),
				__( 'Did you know? I can draft reports, policies, memos, and proposals.', 'digital-employees' ),
				__( 'Did you know? If I can\'t help, I\'ll offer to hand the conversation off to a colleague.', 'digital-employees' ),
			);
			if ( $woo_active ) {
				$tips[] = __( 'Did you know? I can search products and look up details for you.', 'digital-employees' );
				$tips[] = __( 'Did you know? I can look up customer orders and check order status.', 'digital-employees' );
			}
			if ( $is_manager ) {
				$tips[] = __( 'Did you know? I can access management-level documents and guidance.', 'digital-employees' );
				$tips[] = __( 'Did you know? I can help with planning, decision-making, and management-level analysis.', 'digital-employees' );
			}
			echo wp_json_encode( $tips );
		?>,
		upload: {
			maxFiles: 5,
			maxSizeBytes: <?php echo DEF_Core_Staff_AI::UPLOAD_MAX_SIZE_BYTES; ?>,
			allowedExtensions: <?php echo wp_json_encode( array(
				'.png', '.jpg', '.jpeg', '.gif', '.webp',
				'.pdf', '.txt', '.md', '.csv', '.docx', '.xlsx',
			) ); ?>
		},
		i18n: {
			failedToConnect: <?php echo wp_json_encode( __( 'Failed to connect to backend service.', 'digital-employees' ) ); ?>,
			checkStatus: <?php echo wp_json_encode( __( 'Check /wp-json/a3-ai/v1/staff-ai/status for diagnostics.', 'digital-employees' ) ); ?>,
			newConversation: <?php echo wp_json_encode( __( 'New conversation', 'digital-employees' ) ); ?>,
			failedToLoad: <?php echo wp_json_encode( __( 'Failed to load conversation.', 'digital-employees' ) ); ?>,
			sharedWith: <?php echo wp_json_encode( __( 'Shared with', 'digital-employees' ) ); ?>,
			shareFailed: <?php echo wp_json_encode( __( 'Share failed', 'digital-employees' ) ); ?>,
			internalHandoff: <?php echo wp_json_encode( __( 'Internal Handoff Suggested', 'digital-employees' ) ); ?>,
			shareHint: <?php echo wp_json_encode( __( 'Use the Share button to hand off this conversation to another team member.', 'digital-employees' ) ); ?>,
			download: <?php echo wp_json_encode( __( 'Download', 'digital-employees' ) ); ?>,
			file: <?php echo wp_json_encode( __( 'File', 'digital-employees' ) ); ?>,
			failedToSend: <?php echo wp_json_encode( __( 'Failed to send message. Please try again.', 'digital-employees' ) ); ?>,
			allRecipientsSelected: <?php echo wp_json_encode( __( 'All recipients selected', 'digital-employees' ) ); ?>,
			failedToPrepareShare: <?php echo wp_json_encode( __( 'Failed to prepare share form.', 'digital-employees' ) ); ?>,
			failedToSendShare: <?php echo wp_json_encode( __( 'Failed to send share email.', 'digital-employees' ) ); ?>,
			instructionsRequired: <?php echo wp_json_encode( __( 'Instructions are required.', 'digital-employees' ) ); ?>,
			dropFilesHere: <?php echo wp_json_encode( __( 'Drop files here', 'digital-employees' ) ); ?>,
			attachFile: <?php echo wp_json_encode( __( 'Attach file', 'digital-employees' ) ); ?>,
			fileTooLarge: <?php echo wp_json_encode( __( 'File exceeds 10MB limit', 'digital-employees' ) ); ?>,
			unsupportedType: <?php echo wp_json_encode( __( 'Unsupported file type', 'digital-employees' ) ); ?>,
			tooManyFiles: <?php echo wp_json_encode( __( 'Maximum 5 files per message', 'digital-employees' ) ); ?>,
			uploadFailed: <?php echo wp_json_encode( __( 'Upload failed', 'digital-employees' ) ); ?>,
			removeFailedFiles: <?php echo wp_json_encode( __( 'Some files failed to upload. Remove failed files and try again.', 'digital-employees' ) ); ?>,
			analyzingFiles: <?php echo wp_json_encode( __( 'Analyzing files...', 'digital-employees' ) ); ?>
		}
	};
	</script>
	<script src="<?php echo esc_url( DEF_CORE_PLUGIN_URL . 'assets/js/vendor/marked.min.js' ); ?>?ver=15.0.12"></script>
	<script src="<?php echo esc_url( DEF_CORE_PLUGIN_URL . 'assets/js/vendor/purify.min.js' ); ?>?ver=3.1.6"></script>
	<script src="<?php echo esc_url( DEF_CORE_PLUGIN_URL . 'assets/js/staff-ai.js' ); ?>?ver=<?php echo esc_attr( DEF_CORE_VERSION ); ?>"></script>
</body>
</html>
