<?php
/**
 * Class DEF_Core_Staff_AI
 *
 * Staff AI frontend endpoint handler.
 *
 * @package def-core
 * @since 1.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the /staff-ai endpoint rendering.
 */
final class DEF_Core_Staff_AI {
	/**
	 * The endpoint slug.
	 */
	const ENDPOINT_SLUG = 'staff-ai';

	/**
	 * Initialize the Staff AI endpoint.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_endpoint' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
	}

	/**
	 * Add rewrite rules for /staff-ai endpoint.
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^' . self::ENDPOINT_SLUG . '/?$',
			'index.php?' . self::ENDPOINT_SLUG . '=1',
			'top'
		);
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Existing query vars.
	 * @return array Modified query vars.
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = self::ENDPOINT_SLUG;
		return $vars;
	}

	/**
	 * Handle the /staff-ai endpoint request.
	 */
	public static function handle_endpoint(): void {
		if ( ! get_query_var( self::ENDPOINT_SLUG ) ) {
			return;
		}

		// Authentication gate: redirect to login if not authenticated.
		if ( ! is_user_logged_in() ) {
			$redirect_url = home_url( '/' . self::ENDPOINT_SLUG );
			wp_safe_redirect( wp_login_url( $redirect_url ) );
			exit;
		}

		// Capability gate: check for def_staff_access OR def_management_access.
		if ( ! self::user_has_staff_ai_access() ) {
			self::render_access_denied();
			exit;
		}

		// Render the Staff AI shell.
		self::render_shell();
		exit;
	}

	/**
	 * Check if current user has Staff AI access.
	 *
	 * @return bool True if user has def_staff_access OR def_management_access.
	 */
	public static function user_has_staff_ai_access(): bool {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		return $user->has_cap( 'def_staff_access' ) || $user->has_cap( 'def_management_access' );
	}

	/**
	 * Render the access denied page.
	 */
	private static function render_access_denied(): void {
		http_response_code( 403 );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html__( 'Access Denied', 'def-core' ); ?> - <?php bloginfo( 'name' ); ?></title>
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			background: #f0f0f1;
			color: #3c434a;
			display: flex;
			align-items: center;
			justify-content: center;
			min-height: 100vh;
			padding: 20px;
		}
		.access-denied {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			padding: 40px;
			max-width: 400px;
			text-align: center;
			box-shadow: 0 1px 3px rgba(0,0,0,.04);
		}
		.access-denied h1 {
			font-size: 1.5em;
			margin-bottom: 16px;
			color: #1d2327;
		}
		.access-denied p {
			color: #50575e;
			line-height: 1.6;
		}
	</style>
</head>
<body>
	<div class="access-denied">
		<h1><?php echo esc_html__( 'Access Denied', 'def-core' ); ?></h1>
		<p><?php echo esc_html__( 'You do not have permission to access Staff AI.', 'def-core' ); ?></p>
	</div>
</body>
</html>
		<?php
	}

	/**
	 * Render the Staff AI shell.
	 */
	private static function render_shell(): void {
		$user    = wp_get_current_user();
		$channel = 'staff_ai';

		// Determine assistant type based on capability.
		$assistant_type = $user->has_cap( 'def_management_access' )
			? 'management'
			: 'staff';

		$assistant_label = ( 'management' === $assistant_type )
			? __( 'Management Knowledge Assistant', 'def-core' )
			: __( 'Staff Knowledge Assistant', 'def-core' );

		// REST API data for JS.
		$rest_url = rest_url( DEF_CORE_API_NAME_SPACE . '/context-token' );
		$nonce    = wp_create_nonce( 'wp_rest' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $assistant_label ); ?> - <?php bloginfo( 'name' ); ?></title>
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
		html, body {
			height: 100%;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			background: #343541;
			color: #ececf1;
		}
		#staff-ai-app {
			display: flex;
			height: 100vh;
			overflow: hidden;
		}
		/* Sidebar */
		.sidebar {
			width: 260px;
			background: #202123;
			display: flex;
			flex-direction: column;
			flex-shrink: 0;
			transition: transform 0.2s ease;
		}
		.sidebar-header {
			padding: 12px;
			border-bottom: 1px solid rgba(255,255,255,0.1);
		}
		.new-chat-btn {
			width: 100%;
			padding: 12px 16px;
			background: transparent;
			border: 1px solid rgba(255,255,255,0.2);
			border-radius: 6px;
			color: #fff;
			font-size: 14px;
			cursor: pointer;
			display: flex;
			align-items: center;
			gap: 8px;
			transition: background 0.15s;
		}
		.new-chat-btn:hover { background: rgba(255,255,255,0.1); }
		.new-chat-btn svg { width: 16px; height: 16px; }
		.conversation-list {
			flex: 1;
			overflow-y: auto;
			padding: 8px;
		}
		.conversation-list-placeholder {
			padding: 16px;
			color: rgba(255,255,255,0.5);
			font-size: 13px;
			text-align: center;
		}
		.sidebar-footer {
			padding: 12px;
			border-top: 1px solid rgba(255,255,255,0.1);
			font-size: 11px;
			color: rgba(255,255,255,0.5);
			text-align: center;
		}
		/* Main chat area */
		.chat-container {
			flex: 1;
			display: flex;
			flex-direction: column;
			min-width: 0;
		}
		.chat-header {
			padding: 12px 20px;
			background: #343541;
			border-bottom: 1px solid rgba(255,255,255,0.1);
			display: flex;
			align-items: center;
			gap: 12px;
		}
		.menu-toggle {
			display: none;
			background: none;
			border: none;
			color: #fff;
			cursor: pointer;
			padding: 4px;
		}
		.menu-toggle svg { width: 20px; height: 20px; }
		.assistant-label {
			font-size: 14px;
			font-weight: 500;
			color: rgba(255,255,255,0.9);
		}
		/* Messages area */
		.messages-container {
			flex: 1;
			overflow-y: auto;
			padding: 0;
		}
		.messages-list {
			max-width: 768px;
			margin: 0 auto;
			padding: 20px;
		}
		.message {
			padding: 20px 0;
			display: flex;
			gap: 16px;
		}
		.message + .message { border-top: 1px solid rgba(255,255,255,0.1); }
		.message-avatar {
			width: 30px;
			height: 30px;
			border-radius: 4px;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 12px;
			font-weight: 600;
			flex-shrink: 0;
		}
		.message-user .message-avatar { background: #5436da; color: #fff; }
		.message-assistant .message-avatar { background: #19c37d; color: #fff; }
		.message-content {
			flex: 1;
			line-height: 1.6;
			white-space: pre-wrap;
			word-break: break-word;
		}
		.welcome-message {
			text-align: center;
			padding: 60px 20px;
			color: rgba(255,255,255,0.6);
		}
		.welcome-message h2 {
			font-size: 28px;
			font-weight: 600;
			color: #fff;
			margin-bottom: 8px;
		}
		.typing-indicator {
			display: flex;
			gap: 4px;
			padding: 8px 0;
		}
		.typing-indicator span {
			width: 8px;
			height: 8px;
			background: rgba(255,255,255,0.4);
			border-radius: 50%;
			animation: typing 1.4s infinite ease-in-out;
		}
		.typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
		.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
		@keyframes typing {
			0%, 60%, 100% { transform: translateY(0); }
			30% { transform: translateY(-4px); }
		}
		/* Composer */
		.composer-container {
			padding: 16px 20px 24px;
			background: #343541;
		}
		.composer-wrapper {
			max-width: 768px;
			margin: 0 auto;
		}
		.composer {
			display: flex;
			align-items: flex-end;
			background: #40414f;
			border: 1px solid rgba(255,255,255,0.1);
			border-radius: 12px;
			padding: 12px 16px;
			gap: 12px;
		}
		.composer:focus-within { border-color: rgba(255,255,255,0.3); }
		.composer-input {
			flex: 1;
			background: transparent;
			border: none;
			color: #fff;
			font-size: 15px;
			line-height: 1.5;
			resize: none;
			min-height: 24px;
			max-height: 200px;
			outline: none;
			font-family: inherit;
		}
		.composer-input::placeholder { color: rgba(255,255,255,0.4); }
		.send-btn {
			background: #19c37d;
			border: none;
			border-radius: 6px;
			color: #fff;
			width: 32px;
			height: 32px;
			cursor: pointer;
			display: flex;
			align-items: center;
			justify-content: center;
			transition: background 0.15s, opacity 0.15s;
			flex-shrink: 0;
		}
		.send-btn:hover { background: #1a9d6a; }
		.send-btn:disabled { opacity: 0.5; cursor: not-allowed; }
		.send-btn svg { width: 16px; height: 16px; }
		.composer-hint {
			text-align: center;
			font-size: 11px;
			color: rgba(255,255,255,0.4);
			margin-top: 8px;
		}
		/* Error message */
		.error-banner {
			background: rgba(239,68,68,0.1);
			border: 1px solid rgba(239,68,68,0.3);
			color: #fca5a5;
			padding: 12px 16px;
			margin: 0 20px 16px;
			border-radius: 8px;
			font-size: 13px;
			display: none;
		}
		.error-banner.visible { display: block; }
		/* Responsive */
		@media (max-width: 768px) {
			.sidebar {
				position: fixed;
				left: 0;
				top: 0;
				bottom: 0;
				z-index: 100;
				transform: translateX(-100%);
			}
			.sidebar.open { transform: translateX(0); }
			.sidebar-overlay {
				display: none;
				position: fixed;
				inset: 0;
				background: rgba(0,0,0,0.5);
				z-index: 99;
			}
			.sidebar-overlay.visible { display: block; }
			.menu-toggle { display: flex; }
		}
	</style>
</head>
<body>
	<div id="staff-ai-app"
		data-channel="<?php echo esc_attr( $channel ); ?>"
		data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"
		data-assistant-type="<?php echo esc_attr( $assistant_type ); ?>"
		data-rest-url="<?php echo esc_url( $rest_url ); ?>"
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
				<div class="conversation-list-placeholder">
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
				<span class="assistant-label"><?php echo esc_html( $assistant_label ); ?></span>
			</header>

			<div class="messages-container" id="messagesContainer">
				<div class="messages-list" id="messagesList">
					<div class="welcome-message" id="welcomeMessage">
						<h2><?php echo esc_html( $assistant_label ); ?></h2>
						<p><?php echo esc_html__( 'How can I help you today?', 'def-core' ); ?></p>
					</div>
				</div>
			</div>

			<div class="error-banner" id="errorBanner"></div>

			<div class="composer-container">
				<div class="composer-wrapper">
					<div class="composer">
						<textarea
							class="composer-input"
							id="composerInput"
							placeholder="<?php echo esc_attr__( 'Send a message...', 'def-core' ); ?>"
							rows="1"
						></textarea>
						<button type="button" class="send-btn" id="sendBtn" disabled aria-label="<?php echo esc_attr__( 'Send message', 'def-core' ); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<line x1="22" y1="2" x2="11" y2="13"></line>
								<polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
							</svg>
						</button>
					</div>
					<div class="composer-hint">
						<?php echo esc_html__( 'Press Enter to send, Shift+Enter for new line', 'def-core' ); ?>
					</div>
				</div>
			</div>
		</main>
	</div>

	<script>
	(function() {
		'use strict';

		const app = document.getElementById('staff-ai-app');
		const channel = app.dataset.channel;
		const userId = app.dataset.userId;
		const assistantType = app.dataset.assistantType;
		const restUrl = app.dataset.restUrl;
		const nonce = app.dataset.nonce;

		// Elements
		const sidebar = document.getElementById('sidebar');
		const sidebarOverlay = document.getElementById('sidebarOverlay');
		const menuToggle = document.getElementById('menuToggle');
		const newChatBtn = document.getElementById('newChatBtn');
		const messagesContainer = document.getElementById('messagesContainer');
		const messagesList = document.getElementById('messagesList');
		const welcomeMessage = document.getElementById('welcomeMessage');
		const errorBanner = document.getElementById('errorBanner');
		const composerInput = document.getElementById('composerInput');
		const sendBtn = document.getElementById('sendBtn');

		// State
		let messages = [];
		let isLoading = false;

		// Sidebar toggle (mobile)
		function toggleSidebar() {
			sidebar.classList.toggle('open');
			sidebarOverlay.classList.toggle('visible');
		}

		menuToggle.addEventListener('click', toggleSidebar);
		sidebarOverlay.addEventListener('click', toggleSidebar);

		// New chat
		newChatBtn.addEventListener('click', function() {
			messages = [];
			renderMessages();
			composerInput.value = '';
			updateSendButton();
			hideError();
			if (window.innerWidth <= 768) {
				toggleSidebar();
			}
		});

		// Auto-resize textarea
		function autoResize() {
			composerInput.style.height = 'auto';
			composerInput.style.height = Math.min(composerInput.scrollHeight, 200) + 'px';
		}

		composerInput.addEventListener('input', function() {
			autoResize();
			updateSendButton();
		});

		// Update send button state
		function updateSendButton() {
			sendBtn.disabled = !composerInput.value.trim() || isLoading;
		}

		// Keyboard handler: Enter to send, Shift+Enter for newline
		composerInput.addEventListener('keydown', function(e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				if (!sendBtn.disabled) {
					sendMessage();
				}
			}
		});

		sendBtn.addEventListener('click', sendMessage);

		// Show error
		function showError(msg) {
			errorBanner.textContent = msg;
			errorBanner.classList.add('visible');
		}

		// Hide error
		function hideError() {
			errorBanner.classList.remove('visible');
		}

		// Render messages
		function renderMessages() {
			if (messages.length === 0) {
				welcomeMessage.style.display = 'block';
				// Clear any message elements
				const msgElements = messagesList.querySelectorAll('.message');
				msgElements.forEach(el => el.remove());
				return;
			}

			welcomeMessage.style.display = 'none';

			// Clear and re-render
			const msgElements = messagesList.querySelectorAll('.message');
			msgElements.forEach(el => el.remove());

			messages.forEach(function(msg) {
				const div = document.createElement('div');
				div.className = 'message message-' + msg.role;

				const avatar = document.createElement('div');
				avatar.className = 'message-avatar';
				avatar.textContent = msg.role === 'user' ? 'U' : 'AI';

				const content = document.createElement('div');
				content.className = 'message-content';

				if (msg.isTyping) {
					const indicator = document.createElement('div');
					indicator.className = 'typing-indicator';
					indicator.innerHTML = '<span></span><span></span><span></span>';
					content.appendChild(indicator);
				} else {
					content.textContent = msg.content;
				}

				div.appendChild(avatar);
				div.appendChild(content);
				messagesList.appendChild(div);
			});

			// Scroll to bottom
			messagesContainer.scrollTop = messagesContainer.scrollHeight;
		}

		// Send message
		async function sendMessage() {
			const text = composerInput.value.trim();
			if (!text || isLoading) return;

			hideError();

			// Add user message
			messages.push({ role: 'user', content: text });
			renderMessages();

			// Clear input
			composerInput.value = '';
			autoResize();
			updateSendButton();

			// Add typing indicator
			messages.push({ role: 'assistant', content: '', isTyping: true });
			renderMessages();

			isLoading = true;
			updateSendButton();

			try {
				// Simulate send/receive (backend wiring is Loop 4)
				// For now, acknowledge the message was sent
				await new Promise(resolve => setTimeout(resolve, 1500));

				// Remove typing indicator and add response
				messages.pop();
				messages.push({
					role: 'assistant',
					content: '<?php echo esc_js( __( 'Thank you for your message. The backend integration will be connected in a future update.', 'def-core' ) ); ?>'
				});
				renderMessages();
			} catch (err) {
				// Remove typing indicator
				messages.pop();
				renderMessages();
				showError('<?php echo esc_js( __( 'Failed to send message. Please try again.', 'def-core' ) ); ?>');
			} finally {
				isLoading = false;
				updateSendButton();
			}
		}

		// Focus input on load
		composerInput.focus();
	})();
	</script>
</body>
</html>
		<?php
	}

	/**
	 * Flush rewrite rules on activation.
	 */
	public static function on_activate(): void {
		self::add_rewrite_rules();
		flush_rewrite_rules();
	}
}
