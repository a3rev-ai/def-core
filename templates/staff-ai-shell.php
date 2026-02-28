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
	<title><?php echo esc_html__( 'Staff AI', 'def-core' ); ?> - <?php bloginfo( 'name' ); ?></title>
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
					<?php echo esc_html__( 'New chat', 'def-core' ); ?>
				</button>
			</div>
			<nav class="conversation-list" id="conversationList">
				<div class="conversation-list-placeholder" id="conversationPlaceholder">
					<?php echo esc_html__( 'No conversations yet', 'def-core' ); ?>
				</div>
			</nav>
			<div class="sidebar-footer">
				<?php echo esc_html__( 'Powered by DEF', 'def-core' ); ?>
			</div>
		</aside>

		<!-- Main chat -->
		<main class="chat-container">
			<header class="chat-header">
				<button type="button" class="menu-toggle" id="menuToggle" aria-label="<?php echo esc_attr__( 'Toggle menu', 'def-core' ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<line x1="3" y1="6" x2="21" y2="6"></line>
						<line x1="3" y1="12" x2="21" y2="12"></line>
						<line x1="3" y1="18" x2="21" y2="18"></line>
					</svg>
				</button>
				<div class="header-logo"><?php echo $logo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Logo is escaped in wp_get_attachment_image or esc_html ?></div>
				<span class="readonly-indicator" id="readonlyIndicator"><?php echo esc_html__( 'Read-only (shared)', 'def-core' ); ?></span>
				<div class="header-actions">
					<button type="button" class="header-btn" id="exportBtn" disabled><?php echo esc_html__( 'Export', 'def-core' ); ?></button>
					<button type="button" class="header-btn" id="shareBtn" disabled><?php echo esc_html__( 'Share', 'def-core' ); ?></button>
					<button type="button" class="theme-toggle" id="themeToggle" aria-label="<?php echo esc_attr__( 'Toggle theme', 'def-core' ); ?>">
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
				</div>
			</header>

			<div class="messages-container" id="messagesContainer">
				<div class="messages-list" id="messagesList">
					<div class="welcome-message" id="welcomeMessage">
						<p><?php echo esc_html__( 'How can I help you today?', 'def-core' ); ?></p>
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
						<span><?php echo esc_html__( 'Drop files here', 'def-core' ); ?></span>
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
							<button type="button" class="upload-btn" id="uploadBtn"
								aria-label="<?php echo esc_attr__( 'Attach file', 'def-core' ); ?>"
								title="<?php echo esc_attr__( 'Attach file', 'def-core' ); ?>">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
									stroke-linecap="round" stroke-linejoin="round">
									<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
								</svg>
							</button>
							<textarea
								class="composer-input"
								id="composerInput"
								placeholder="<?php echo esc_attr__( 'Send a message...', 'def-core' ); ?>"
								rows="1"></textarea>
							<button type="button" class="send-btn" id="sendBtn" disabled aria-label="<?php echo esc_attr__( 'Send message', 'def-core' ); ?>">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
									<line x1="22" y1="2" x2="11" y2="13"></line>
									<polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
								</svg>
							</button>
						</div>
						<button type="button" class="create-btn" id="createBtn">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
								<polyline points="14 2 14 8 20 8"></polyline>
								<line x1="12" y1="11" x2="12" y2="17"></line>
								<line x1="9" y1="14" x2="15" y2="14"></line>
							</svg>
							<?php echo esc_html__( 'Create', 'def-core' ); ?>
						</button>
					</div>
					<div class="composer-hint">
						<?php echo esc_html__( 'Press Enter to send, Shift+Enter for new line', 'def-core' ); ?>
					</div>
				</div>
			</div>
		</main>

		<!-- Share Modal -->
		<div class="modal-overlay" id="shareModal">
			<div class="modal" style="max-width: 520px;">
				<div class="modal-header">
					<span class="modal-title"><?php echo esc_html__( 'Share Conversation', 'def-core' ); ?></span>
					<button type="button" class="modal-close" id="shareModalClose">&times;</button>
				</div>
				<!-- Loading state -->
				<div class="share-loading" id="shareLoading">
					<div class="share-loading-spinner"></div>
					<p><?php echo esc_html__( 'Preparing share form...', 'def-core' ); ?></p>
				</div>
				<!-- Error state -->
				<div class="share-error" id="shareError" style="display:none;">
					<p id="shareErrorText"></p>
					<button type="button" class="modal-btn modal-btn-secondary" id="shareErrorClose"><?php echo esc_html__( 'Close', 'def-core' ); ?></button>
				</div>
				<!-- Form state -->
				<div id="shareFormContent" style="display:none;">
					<div class="modal-body">
						<div class="form-group">
							<label class="form-label"><?php echo esc_html__( 'Share with', 'def-core' ); ?></label>
							<div class="token-select" id="shareRecipientTokenSelect">
								<div class="token-select-tokens" id="shareRecipientTokens">
									<span class="token-select-placeholder" id="shareRecipientPlaceholder"><?php echo esc_html__( 'Click to select recipients...', 'def-core' ); ?></span>
								</div>
								<div class="token-select-dropdown" id="shareRecipientDropdown"></div>
							</div>
						</div>
						<div class="form-group">
							<label class="form-label"><?php echo esc_html__( 'Subject', 'def-core' ); ?></label>
							<input type="text" class="form-input" id="shareSubject" placeholder="<?php echo esc_attr__( 'Brief summary...', 'def-core' ); ?>">
						</div>
						<div class="form-group">
							<label class="form-label"><?php echo esc_html__( 'Message', 'def-core' ); ?></label>
							<textarea class="form-input share-message-input" id="shareMessage" rows="4" placeholder="<?php echo esc_attr__( 'Summary and context for the recipient...', 'def-core' ); ?>"></textarea>
						</div>
						<div class="share-transcript-toggle">
							<label class="share-toggle-label">
								<input type="checkbox" id="shareTranscript" checked>
								<svg class="share-paperclip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
								<?php echo esc_html__( 'Include conversation transcript', 'def-core' ); ?>
							</label>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="modal-btn modal-btn-secondary" id="shareCancel"><?php echo esc_html__( 'Cancel', 'def-core' ); ?></button>
						<button type="button" class="modal-btn modal-btn-primary" id="shareSend" disabled><?php echo esc_html__( 'Send', 'def-core' ); ?></button>
					</div>
				</div>
			</div>
		</div>

		<!-- Create Tool Modal -->
		<div class="modal-overlay" id="createModal">
			<div class="modal" style="max-width: 480px;">
				<div class="modal-header">
					<span class="modal-title"><?php echo esc_html__( 'Create', 'def-core' ); ?></span>
					<button type="button" class="modal-close" id="createModalClose">&times;</button>
				</div>
				<div class="modal-body">
					<div class="form-group">
						<label class="form-label"><?php echo esc_html__( 'Type', 'def-core' ); ?></label>
						<select class="form-input" id="createToolType">
							<option value="document_creation"><?php echo esc_html__( 'Document', 'def-core' ); ?></option>
							<option value="spreadsheet_creation"><?php echo esc_html__( 'Spreadsheet', 'def-core' ); ?></option>
							<option value="image_generation"><?php echo esc_html__( 'Image', 'def-core' ); ?></option>
						</select>
					</div>
					<div class="form-group" id="createFormatGroup">
						<label class="form-label"><?php echo esc_html__( 'Format', 'def-core' ); ?></label>
						<select class="form-input" id="createFormat">
							<option value="docx">DOCX</option>
							<option value="pdf">PDF</option>
							<option value="md">Markdown</option>
						</select>
					</div>
					<div class="form-group">
						<label class="form-label"><?php echo esc_html__( 'Title (optional)', 'def-core' ); ?></label>
						<input type="text" class="form-input" id="createTitle" placeholder="<?php echo esc_attr__( 'My Document', 'def-core' ); ?>">
					</div>
					<div class="form-group">
						<label class="form-label"><?php echo esc_html__( 'Instructions', 'def-core' ); ?> <span style="color: var(--share-error-text);">*</span></label>
						<textarea class="form-input" id="createPrompt" rows="4" placeholder="<?php echo esc_attr__( 'Describe what you want to create...', 'def-core' ); ?>"></textarea>
					</div>
					<div class="error-banner" id="createError" style="margin: 0;"></div>
				</div>
				<div class="modal-footer">
					<button type="button" class="modal-btn modal-btn-secondary" id="createCancel"><?php echo esc_html__( 'Cancel', 'def-core' ); ?></button>
					<button type="button" class="modal-btn modal-btn-primary" id="createSubmit"><?php echo esc_html__( 'Create', 'def-core' ); ?></button>
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
		upload: {
			maxFiles: 5,
			maxSizeBytes: <?php echo DEF_Core_Staff_AI::UPLOAD_MAX_SIZE_BYTES; ?>,
			allowedExtensions: <?php echo wp_json_encode( array(
				'.png', '.jpg', '.jpeg', '.gif', '.webp',
				'.pdf', '.txt', '.md', '.csv', '.docx', '.xlsx',
			) ); ?>
		},
		i18n: {
			failedToConnect: <?php echo wp_json_encode( __( 'Failed to connect to backend service.', 'def-core' ) ); ?>,
			checkStatus: <?php echo wp_json_encode( __( 'Check /wp-json/a3-ai/v1/staff-ai/status for diagnostics.', 'def-core' ) ); ?>,
			newConversation: <?php echo wp_json_encode( __( 'New conversation', 'def-core' ) ); ?>,
			failedToLoad: <?php echo wp_json_encode( __( 'Failed to load conversation.', 'def-core' ) ); ?>,
			sharedWith: <?php echo wp_json_encode( __( 'Shared with', 'def-core' ) ); ?>,
			shareFailed: <?php echo wp_json_encode( __( 'Share failed', 'def-core' ) ); ?>,
			internalHandoff: <?php echo wp_json_encode( __( 'Internal Handoff Suggested', 'def-core' ) ); ?>,
			shareHint: <?php echo wp_json_encode( __( 'Use the Share button to hand off this conversation to another team member.', 'def-core' ) ); ?>,
			download: <?php echo wp_json_encode( __( 'Download', 'def-core' ) ); ?>,
			file: <?php echo wp_json_encode( __( 'File', 'def-core' ) ); ?>,
			failedToSend: <?php echo wp_json_encode( __( 'Failed to send message. Please try again.', 'def-core' ) ); ?>,
			allRecipientsSelected: <?php echo wp_json_encode( __( 'All recipients selected', 'def-core' ) ); ?>,
			failedToPrepareShare: <?php echo wp_json_encode( __( 'Failed to prepare share form.', 'def-core' ) ); ?>,
			failedToSendShare: <?php echo wp_json_encode( __( 'Failed to send share email.', 'def-core' ) ); ?>,
			instructionsRequired: <?php echo wp_json_encode( __( 'Instructions are required.', 'def-core' ) ); ?>,
			dropFilesHere: <?php echo wp_json_encode( __( 'Drop files here', 'def-core' ) ); ?>,
			attachFile: <?php echo wp_json_encode( __( 'Attach file', 'def-core' ) ); ?>,
			fileTooLarge: <?php echo wp_json_encode( __( 'File exceeds 10MB limit', 'def-core' ) ); ?>,
			unsupportedType: <?php echo wp_json_encode( __( 'Unsupported file type', 'def-core' ) ); ?>,
			tooManyFiles: <?php echo wp_json_encode( __( 'Maximum 5 files per message', 'def-core' ) ); ?>,
			uploadFailed: <?php echo wp_json_encode( __( 'Upload failed', 'def-core' ) ); ?>,
			removeFailedFiles: <?php echo wp_json_encode( __( 'Some files failed to upload. Remove failed files and try again.', 'def-core' ) ); ?>,
			analyzingFiles: <?php echo wp_json_encode( __( 'Analyzing files...', 'def-core' ) ); ?>
		}
	};
	</script>
	<script src="<?php echo esc_url( DEF_CORE_PLUGIN_URL . 'assets/js/staff-ai.js' ); ?>?ver=<?php echo esc_attr( DEF_CORE_VERSION ); ?>"></script>
</body>
</html>
