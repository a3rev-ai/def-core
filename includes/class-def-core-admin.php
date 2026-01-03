<?php
/**
 * Class DEF_Core_Admin
 *
 * Admin functionality for the Digital Employee Framework - Core plugin.
 *
 * @package def-core
 * @since 0.1.0
 * @version 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DEF_Core_Admin
 *
 * Admin functionality for the Digital Employee Framework - Core plugin.
 *
 * @package def-core
 * @since 0.1.0
 * @version 0.1.0
 */
final class DEF_Core_Admin {
	/**
	 * Initialize the admin functionality.
	 *
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Add the settings page.
	 *
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function add_settings_page(): void {
		add_options_page(
			__( 'Digital Employees', 'def-core' ),
			__( 'Digital Employees', 'def-core' ),
			'manage_options',
			'def-core',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register the settings.
	 *
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function register_settings(): void {
		register_setting(
			'def_core_settings',
			DEF_CORE_OPTION_ALLOWED_ORIGINS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_allowed_origins' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
		register_setting(
			'def_core_settings',
			'def_core_external_jwks_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_external_jwks_url' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			'def_core_settings',
			'def_core_external_issuer',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_external_issuer' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			'def_core_settings',
			'def_core_tools_status',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_tools_status' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
		add_settings_section(
			'def_core_main',
			__( 'Session Bridge Settings', 'def-core' ),
			function (): void {
				echo '<p>' . esc_html__( 'Manage which origins (hosts) are allowed to request Signed Context Tokens via postMessage. One origin per line, e.g. https://app.example.com', 'def-core' ) . '</p>';
			},
			'def-core'
		);
		add_settings_section(
			'def_core_external_auth',
			__( 'External Authentication (Single Sign-On)', 'def-core' ),
			array( __CLASS__, 'render_external_auth_section' ),
			'def-core'
		);
		add_settings_section(
			'def_core_api_tools',
			__( 'API Tools', 'def-core' ),
			array( __CLASS__, 'render_api_tools_section' ),
			'def-core'
		);
		add_settings_field(
			'api_tools',
			__( 'Enable/Disable Tools', 'def-core' ),
			array( __CLASS__, 'render_api_tools_field' ),
			'def-core',
			'def_core_api_tools'
		);
		add_settings_field(
			'allowed_origins',
			__( 'Allowed Origins', 'def-core' ),
			array( __CLASS__, 'render_allowed_origins_field' ),
			'def-core',
			'def_core_main'
		);
		add_settings_field(
			'external_jwks_url',
			__( 'External JWKS URL', 'def-core' ),
			array( __CLASS__, 'render_external_jwks_field' ),
			'def-core',
			'def_core_external_auth'
		);
		add_settings_field(
			'external_issuer',
			__( 'External Issuer URL', 'def-core' ),
			array( __CLASS__, 'render_external_issuer_field' ),
			'def-core',
			'def_core_external_auth'
		);
	}

	/**
	 * Sanitize the allowed origins.
	 *
	 * @param string|array $value The value to sanitize.
	 * @return array The sanitized value.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function sanitize_allowed_origins( $value ) {
		if ( is_string( $value ) ) {
			$lines = preg_split( "/\r\n|\n|\r/", $value );
		} elseif ( is_array( $value ) ) {
			$lines = $value;
		} else {
			$lines = array();
		}
		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			// Accept only http/https origins; strip trailing slash.
			if ( 0 === strpos( $line, 'http://' ) || 0 === strpos( $line, 'https://' ) ) {
				$line  = rtrim( $line, '/' );
				$out[] = $line;
			}
		}
		$out = array_values( array_unique( $out ) );
		return $out;
	}

	/**
	 * Render the external auth section description.
	 *
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function render_external_auth_section(): void {
		?>
		<p><?php esc_html_e( 'Configure this site to accept JWT tokens from another WordPress site (Single Sign-On). Leave these fields empty if this site issues its own tokens.', 'def-core' ); ?></p>
		<p><strong><?php esc_html_e( 'Use Case:', 'def-core' ); ?></strong> <?php esc_html_e( 'If you have a main e-commerce site and a separate support forum site, configure the support site to accept tokens from the main site so users only need to log in once.', 'def-core' ); ?></p>
		<p><em><?php esc_html_e( 'Security Note: Only configure external authentication if you trust the external site and its users.', 'def-core' ); ?></em></p>
		<?php
	}

	/**
	 * Sanitize external JWKS URL.
	 *
	 * @param string $value The value to sanitize.
	 * @return string The sanitized value.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function sanitize_external_jwks_url( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		// Must be a valid URL.
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( '' === $url ) {
			add_settings_error(
				'def_core_external_jwks_url',
				'invalid_url',
				__( 'External JWKS URL must be a valid HTTP/HTTPS URL.', 'def-core' )
			);
			return '';
		}
		// Clear the JWKS cache when URL changes.
		delete_transient( 'def_core_external_jwks_' . md5( $url ) );
		return $url;
	}

	/**
	 * Sanitize external issuer URL.
	 *
	 * @param string $value The value to sanitize.
	 * @return string The sanitized value.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function sanitize_external_issuer( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		// Must be a valid URL.
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( '' === $url ) {
			add_settings_error(
				'def_core_external_issuer',
				'invalid_url',
				__( 'External Issuer URL must be a valid HTTP/HTTPS URL.', 'def-core' )
			);
			return '';
		}
		// Remove trailing slash for consistency.
		return rtrim( $url, '/' );
	}

	/**
	 * Sanitize tools status array.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return array<string, int> The sanitized value (1=enabled, 0=disabled).
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public static function sanitize_tools_status( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$sanitized = array();
		foreach ( $value as $key => $status ) {
			$key = sanitize_text_field( (string) $key );
			if ( ! empty( $key ) ) {
				// Convert to int: 1 for enabled, 0 for disabled.
				$sanitized[ $key ] = (int) $status;
			}
		}
		return $sanitized;
	}

	/**
	 * Render the allowed origins field.
	 *
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function render_allowed_origins_field(): void {
		$origins = get_option( DEF_CORE_OPTION_ALLOWED_ORIGINS, array() );
		if ( ! is_array( $origins ) ) {
			$origins = array();
		}
		$text = implode( "\n", array_map( 'esc_url_raw', $origins ) );
		echo '<textarea name="' . esc_attr( DEF_CORE_OPTION_ALLOWED_ORIGINS ) . '" rows="6" cols="60" class="large-text code">' . esc_textarea( $text ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Example: https://your-azure-app.azurewebsites.net', 'def-core' ) . '</p>';
	}

	/**
	 * Render the external JWKS URL field.
	 *
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function render_external_jwks_field(): void {
		$url = get_option( 'def_core_external_jwks_url', '' );
		?>
		<input type="url" 
				name="def_core_external_jwks_url" 
				value="<?php echo esc_attr( $url ); ?>" 
				class="large-text code" 
				placeholder="<?php echo esc_attr( 'https://your-main-site.com/wp-json/' . DEF_CORE_API_NAME_SPACE . '/jwks' ); ?>" />
		<p class="description">
			<?php esc_html_e( 'The JWKS (JSON Web Key Set) URL from your main WordPress site.', 'def-core' ); ?><br>
			<strong><?php esc_html_e( 'Example:', 'def-core' ); ?></strong> <code><?php echo esc_html( 'https://your-main-site.com/wp-json/' . DEF_CORE_API_NAME_SPACE . '/jwks' ); ?></code><br>
			<em><?php esc_html_e( 'This site will verify tokens using the public keys from this URL.', 'def-core' ); ?></em>
		</p>
		<?php
	}

	/**
	 * Render the external issuer URL field.
	 *
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function render_external_issuer_field(): void {
		$url = get_option( 'def_core_external_issuer', '' );
		?>
		<input type="url" 
				name="def_core_external_issuer" 
				value="<?php echo esc_attr( $url ); ?>" 
				class="large-text code" 
				placeholder="https://your-main-site.com" />
		<p class="description">
			<?php esc_html_e( 'The base URL of your main WordPress site (must match the JWT issuer claim).', 'def-core' ); ?><br>
			<strong><?php esc_html_e( 'Example:', 'def-core' ); ?></strong> <code>https://your-main-site.com</code><br>
			<em><?php esc_html_e( 'Tokens with a different issuer will be rejected for security.', 'def-core' ); ?></em>
		</p>
		<?php
	}

	/**
	 * Render the settings page.
	 *
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function render_settings_page(): void {
		// Enqueue admin assets.
		wp_enqueue_style( 'def-core-admin' );
		wp_enqueue_script( 'def-core-admin' );
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$jwks_url  = esc_url( rest_url( DEF_CORE_API_NAME_SPACE . '/jwks' ) );
		$token_url = esc_url( rest_url( DEF_CORE_API_NAME_SPACE . '/context-token' ) );

		// Check external auth configuration status.
		$external_jwks_url   = get_option( 'def_core_external_jwks_url', '' );
		$external_issuer     = get_option( 'def_core_external_issuer', '' );
		$external_configured = ! empty( $external_jwks_url ) && ! empty( $external_issuer );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( __( 'Digital Employee Bridge', 'def-core' ) ); ?></h1>
			
			<?php if ( $external_configured ) : ?>
				<div class="notice notice-success">
					<p>
						<strong><?php esc_html_e( '✓ Single Sign-On Enabled', 'def-core' ); ?></strong><br>
						<?php
						printf(
							/* translators: %s: external site URL */
							esc_html__( 'This site is accepting JWT tokens from: %s', 'def-core' ),
							'<code>' . esc_html( $external_issuer ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<div class="notice notice-info">
					<p>
						<strong><?php esc_html_e( 'Local Authentication Mode', 'def-core' ); ?></strong><br>
						<?php esc_html_e( 'This site issues its own JWT tokens. Configure "External Authentication" below to enable Single Sign-On with another WordPress site.', 'def-core' ); ?>
					</p>
				</div>
			<?php endif; ?>
			
			<form method="post" action="options.php">
				<?php
				settings_fields( 'def_core_settings' );
				do_settings_sections( 'def-core' );
				submit_button();
				?>
			</form>
			
			<hr>
			
			<h2><?php echo esc_html( __( 'This Site\'s Endpoints', 'def-core' ) ); ?></h2>
			<p><?php esc_html_e( 'Use these URLs when configuring other sites to accept tokens from this site:', 'def-core' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'JWKS URL:', 'def-core' ); ?></th>
					<td>
						<code><?php echo esc_html( $jwks_url ); ?></code>
						<button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( $jwks_url ); ?>')">
							<?php esc_html_e( 'Copy', 'def-core' ); ?>
						</button>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Issuer URL:', 'def-core' ); ?></th>
					<td>
						<code><?php echo esc_html( rtrim( home_url(), '/' ) ); ?></code>
						<button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( rtrim( home_url(), '/' ) ); ?>')">
							<?php esc_html_e( 'Copy', 'def-core' ); ?>
						</button>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Context Token URL:', 'def-core' ); ?></th>
					<td>
						<code><?php echo esc_html( $token_url ); ?></code>
						<button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( $token_url ); ?>')">
							<?php esc_html_e( 'Copy', 'def-core' ); ?>
						</button>
						<br><em><?php esc_html_e( '(Requires authentication)', 'def-core' ); ?></em>
					</td>
				</tr>
			</table>
			
			<hr>
			
			<h2><?php echo esc_html( __( 'Chatbot Widget Integration Guide', 'def-core' ) ); ?></h2>
			<?php self::render_widget_guide_section(); ?>
			<?php self::render_widget_guide_field(); ?>
		</div>
		<?php
	}

	/**
	 * Render the API tools section description.
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public static function render_api_tools_section(): void {
		?>
		<p><?php esc_html_e( 'Enable or disable individual API tools. Core routes (context-token, jwks) are always enabled and cannot be disabled.', 'def-core' ); ?></p>
		<p class="description"><?php esc_html_e( 'Disabled tools will not be registered with WordPress REST API and will not be accessible.', 'def-core' ); ?></p>
		<?php
	}

	/**
	 * Render the API tools field.
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public static function render_api_tools_field(): void {
		$registry     = DEF_Core_API_Registry::instance();
		$tools        = $registry->get_tools_with_status();
		$tools_status = get_option( 'def_core_tools_status', array() );
		if ( ! is_array( $tools_status ) ) {
			$tools_status = array();
		}
		?>
		<div class="def-core-api-tools">
			<?php if ( ! empty( $tools ) ) : ?>
				<table class="form-table def-core-tools-table">
					<tbody>
						<?php foreach ( $tools as $route => $tool ) : ?>
							<?php
							$tool_id = 'tool_' . md5( $route );
							// If tool is not in status list, default to enabled (1).
							$status     = isset( $tools_status[ $route ] ) ? (int) $tools_status[ $route ] : 1;
							$is_enabled = 1 === $status;
							?>
							<tr>
								<th scope="row">
									<label for="<?php echo esc_attr( $tool_id ); ?>">
										<?php echo esc_html( $tool['name'] ); ?>
									</label>
								</th>
								<td>
									<span class="def-core-toggle-switch <?php echo $tool['is_core'] ? 'def-core-toggle-disabled' : ''; ?>">
										<?php if ( ! $is_enabled ) : ?>
											<input 
												type="hidden" 
												name="def_core_tools_status[<?php echo esc_attr( $route ); ?>]"
												value="0"
											>
										<?php endif; ?>
										<input 
											type="checkbox" 
											id="<?php echo esc_attr( $tool_id ); ?>" 
											name="def_core_tools_status[<?php echo esc_attr( $route ); ?>]"
											value="1"
											<?php checked( $is_enabled, true ); ?>
										>
										<span class="def-core-slider"></span>
									</span>
									<code class="def-core-route"><?php echo esc_html( $tool['route'] ); ?></code>
									<span class="def-core-methods"><?php echo esc_html( implode( ', ', $tool['methods'] ) ); ?></span>
									<?php if ( ! empty( $tool['addon'] ) ) : ?>
										<span class="def-core-addon-badge"><?php echo esc_html( $tool['addon'] ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No API tools registered yet.', 'def-core' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the widget guide section description.
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public static function render_widget_guide_section(): void {
		?>
		<p><?php esc_html_e( 'Learn how to integrate the Digital Employee chatbot popup widget into your WordPress site.', 'def-core' ); ?></p>
		<?php
	}

	/**
	 * Render the widget guide field.
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public static function render_widget_guide_field(): void {
		?>
		<div class="def-core-widget-guide">
			<h3><?php esc_html_e( 'Quick Start', 'def-core' ); ?></h3>
			<p><?php esc_html_e( 'The chatbot widget is an embeddable JavaScript file that creates a floating chat popup on your website. Add it to your theme.', 'def-core' ); ?></p>

			<h4><?php esc_html_e( 'Direct Script Tag in Theme', 'def-core' ); ?></h4>
			<p><?php esc_html_e( 'Add this to your theme\'s header.php or footer.php (before closing </body> tag):', 'def-core' ); ?></p>
			<pre><code><?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- This is example documentation code, not an actual script enqueue.
			$script_example = '<script 
    src="https://a3revai.azurewebsites.net/widget/popup.js" 
    data-chat-url="https://a3revai.azurewebsites.net/"
    data-position="right"
    data-open="false"
    async>
</script>';
			echo esc_html( $script_example );
			?>
			</code></pre>

			<h3><?php esc_html_e( 'Configuration Options', 'def-core' ); ?></h3>
			<p><?php esc_html_e( 'The widget supports the following data attributes:', 'def-core' ); ?></p>

			<div class="widget-attribute">
				<strong>data-chat-url</strong><br>
				<?php esc_html_e( 'The URL of the Digital Employee Framework interface to load in the iframe.', 'def-core' ); ?><br>
				<em><?php esc_html_e( 'Default:', 'def-core' ); ?> <code>https://a3revai.azurewebsites.net/</code></em>
			</div>

			<div class="widget-attribute">
				<strong>data-position</strong><br>
				<?php esc_html_e( 'Controls where the chat button appears on the screen.', 'def-core' ); ?><br>
				<em><?php esc_html_e( 'Options:', 'def-core' ); ?> <code>"right"</code> <?php esc_html_e( '(default) or', 'def-core' ); ?> <code>"left"</code></em>
			</div>

			<div class="widget-attribute">
				<strong>data-open</strong><br>
				<?php esc_html_e( '"true" to start opened (only applies on first load, default: false)', 'def-core' ); ?><br>
				<em><?php esc_html_e( 'Note: Once a user closes the popup, it stays hidden for 24 hours.', 'def-core' ); ?></em>
			</div>

			<div class="widget-guide-section">
				<h3 class="widget-guide-toggle">
					<span class="widget-guide-arrow">▶</span>
					<?php esc_html_e( 'Integration with WordPress Bridge Plugin', 'def-core' ); ?>
				</h3>
				<div class="widget-guide-content" style="display: none;">
					<p><?php esc_html_e( 'If you\'re using this bridge plugin, you can leverage the JWT context token for authenticated access:', 'def-core' ); ?></p>
					<div class="example-box">
						<p><strong><?php esc_html_e( 'Example:', 'def-core' ); ?></strong></p>
						<p><?php esc_html_e( 'The widget will automatically use the WordPress authentication context when loaded on pages where users are logged in. The Digital Employee Framework will receive the user\'s WordPress identity through the bridge plugin\'s context token endpoint.', 'def-core' ); ?></p>
					</div>
				</div>
			</div>

			<div class="widget-guide-section">
				<h3 class="widget-guide-toggle">
					<span class="widget-guide-arrow">▶</span>
					<?php esc_html_e( 'Widget Behavior', 'def-core' ); ?>
				</h3>
				<div class="widget-guide-content" style="display: none;">
					<ul>
						<li><?php esc_html_e( 'The widget uses localStorage to remember user preferences', 'def-core' ); ?></li>
						<li><?php esc_html_e( 'When a user closes the popup, it stays hidden for 24 hours', 'def-core' ); ?></li>
						<li><?php esc_html_e( 'The data-open="true" attribute only applies if there\'s no saved user preference', 'def-core' ); ?></li>
						<li><?php esc_html_e( 'Includes ARIA attributes for accessibility and keyboard support (ESC key closes popup)', 'def-core' ); ?></li>
						<li><?php esc_html_e( 'The widget automatically loads its CSS file - no additional CSS enqueuing required', 'def-core' ); ?></li>
					</ul>
				</div>
			</div>

			<div class="widget-guide-section">
				<h3 class="widget-guide-toggle">
					<span class="widget-guide-arrow">▶</span>
					<?php esc_html_e( 'Troubleshooting', 'def-core' ); ?>
				</h3>
				<div class="widget-guide-content" style="display: none;">
					<p><strong><?php esc_html_e( 'Widget not appearing?', 'def-core' ); ?></strong></p>
					<ul>
						<li><?php esc_html_e( 'Check browser console for JavaScript errors', 'def-core' ); ?></li>
						<li><?php esc_html_e( 'Verify script URL is accessible and correct', 'def-core' ); ?></li>
						<li><?php esc_html_e( 'Check for conflicts with other scripts or themes', 'def-core' ); ?></li>
					</ul>

					<p><strong><?php esc_html_e( 'Widget appears but chat doesn\'t load?', 'def-core' ); ?></strong></p>
					<ul>
						<li><?php esc_html_e( 'Verify data-chat-url points to a valid Digital Employee Framework instance', 'def-core' ); ?></li>
						<li><?php esc_html_e( 'Check iframe permissions - ensure the chat URL allows embedding', 'def-core' ); ?></li>
						<li><?php esc_html_e( 'Check CORS settings on the chat URL server', 'def-core' ); ?></li>
					</ul>
				</div>
			</div>

			<div class="widget-guide-section">
				<h3 class="widget-guide-toggle">
					<span class="widget-guide-arrow">▶</span>
					<?php esc_html_e( 'Security Considerations', 'def-core' ); ?>
				</h3>
				<div class="widget-guide-content" style="display: none;">
					<ul>
						<li><?php esc_html_e( 'Always escape URLs when outputting them in HTML', 'def-core' ); ?></li>
						<li><?php esc_html_e( 'Validate user permissions before loading the widget', 'def-core' ); ?></li>
						<li><?php esc_html_e( 'Use HTTPS for both widget and chat URLs', 'def-core' ); ?></li>
						<li><?php esc_html_e( 'Consider CORS policies if loading from different domains', 'def-core' ); ?></li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}
}
