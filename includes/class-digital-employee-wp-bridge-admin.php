<?php
/**
 * Class Digital_Employee_WP_Bridge_Admin
 *
 * Admin functionality for the Digital Employee — WordPress Bridge plugin.
 *
 * @package digital-employee-wp-bridge
 * @since 0.1.0
 * @version 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Digital_Employee_WP_Bridge_Admin
 *
 * Admin functionality for the Digital Employee — WordPress Bridge plugin.
 *
 * @package digital-employee-wp-bridge
 * @since 0.1.0
 * @version 0.1.0
 */
final class Digital_Employee_WP_Bridge_Admin {
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
			__( 'Digital Employee Bridge', 'digital-employee-wp-bridge' ),
			__( 'Digital Employee Bridge', 'digital-employee-wp-bridge' ),
			'manage_options',
			'digital-employee-wp-bridge',
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
			'de_wp_bridge_settings',
			DE_WP_BRIDGE_OPTION_ALLOWED_ORIGINS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_allowed_origins' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
		register_setting(
			'de_wp_bridge_settings',
			'de_wp_bridge_external_jwks_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_external_jwks_url' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			'de_wp_bridge_settings',
			'de_wp_bridge_external_issuer',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_external_issuer' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			'de_wp_bridge_settings',
			'de_wp_bridge_tools_status',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_tools_status' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
		add_settings_section(
			'de_wp_bridge_main',
			__( 'Session Bridge Settings', 'digital-employee-wp-bridge' ),
			function (): void {
				echo '<p>' . esc_html__( 'Manage which origins (hosts) are allowed to request Signed Context Tokens via postMessage. One origin per line, e.g. https://app.example.com', 'digital-employee-wp-bridge' ) . '</p>';
			},
			'digital-employee-wp-bridge'
		);
		add_settings_section(
			'de_wp_bridge_external_auth',
			__( 'External Authentication (Single Sign-On)', 'digital-employee-wp-bridge' ),
			array( __CLASS__, 'render_external_auth_section' ),
			'digital-employee-wp-bridge'
		);
		add_settings_section(
			'de_wp_bridge_api_tools',
			__( 'API Tools', 'digital-employee-wp-bridge' ),
			array( __CLASS__, 'render_api_tools_section' ),
			'digital-employee-wp-bridge'
		);
		add_settings_field(
			'api_tools',
			__( 'Enable/Disable Tools', 'digital-employee-wp-bridge' ),
			array( __CLASS__, 'render_api_tools_field' ),
			'digital-employee-wp-bridge',
			'de_wp_bridge_api_tools'
		);
		add_settings_field(
			'allowed_origins',
			__( 'Allowed Origins', 'digital-employee-wp-bridge' ),
			array( __CLASS__, 'render_allowed_origins_field' ),
			'digital-employee-wp-bridge',
			'de_wp_bridge_main'
		);
		add_settings_field(
			'external_jwks_url',
			__( 'External JWKS URL', 'digital-employee-wp-bridge' ),
			array( __CLASS__, 'render_external_jwks_field' ),
			'digital-employee-wp-bridge',
			'de_wp_bridge_external_auth'
		);
		add_settings_field(
			'external_issuer',
			__( 'External Issuer URL', 'digital-employee-wp-bridge' ),
			array( __CLASS__, 'render_external_issuer_field' ),
			'digital-employee-wp-bridge',
			'de_wp_bridge_external_auth'
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
		<p><?php esc_html_e( 'Configure this site to accept JWT tokens from another WordPress site (Single Sign-On). Leave these fields empty if this site issues its own tokens.', 'digital-employee-wp-bridge' ); ?></p>
		<p><strong><?php esc_html_e( 'Use Case:', 'digital-employee-wp-bridge' ); ?></strong> <?php esc_html_e( 'If you have a main e-commerce site and a separate support forum site, configure the support site to accept tokens from the main site so users only need to log in once.', 'digital-employee-wp-bridge' ); ?></p>
		<p><em><?php esc_html_e( 'Security Note: Only configure external authentication if you trust the external site and its users.', 'digital-employee-wp-bridge' ); ?></em></p>
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
				'de_wp_bridge_external_jwks_url',
				'invalid_url',
				__( 'External JWKS URL must be a valid HTTP/HTTPS URL.', 'digital-employee-wp-bridge' )
			);
			return '';
		}
		// Clear the JWKS cache when URL changes.
		delete_transient( 'de_wp_bridge_external_jwks_' . md5( $url ) );
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
				'de_wp_bridge_external_issuer',
				'invalid_url',
				__( 'External Issuer URL must be a valid HTTP/HTTPS URL.', 'digital-employee-wp-bridge' )
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
		$origins = get_option( DE_WP_BRIDGE_OPTION_ALLOWED_ORIGINS, array() );
		if ( ! is_array( $origins ) ) {
			$origins = array();
		}
		$text = implode( "\n", array_map( 'esc_url_raw', $origins ) );
		echo '<textarea name="' . esc_attr( DE_WP_BRIDGE_OPTION_ALLOWED_ORIGINS ) . '" rows="6" cols="60" class="large-text code">' . esc_textarea( $text ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Example: https://your-azure-app.azurewebsites.net', 'digital-employee-wp-bridge' ) . '</p>';
	}

	/**
	 * Render the external JWKS URL field.
	 *
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function render_external_jwks_field(): void {
		$url = get_option( 'de_wp_bridge_external_jwks_url', '' );
		?>
		<input type="url" 
				name="de_wp_bridge_external_jwks_url" 
				value="<?php echo esc_attr( $url ); ?>" 
				class="large-text code" 
				placeholder="<?php echo esc_attr( 'https://your-main-site.com/wp-json/' . DE_WP_BRIDGE_API_NAME_SPACE . '/jwks' ); ?>" />
		<p class="description">
			<?php esc_html_e( 'The JWKS (JSON Web Key Set) URL from your main WordPress site.', 'digital-employee-wp-bridge' ); ?><br>
			<strong><?php esc_html_e( 'Example:', 'digital-employee-wp-bridge' ); ?></strong> <code><?php echo esc_html( 'https://your-main-site.com/wp-json/' . DE_WP_BRIDGE_API_NAME_SPACE . '/jwks' ); ?></code><br>
			<em><?php esc_html_e( 'This site will verify tokens using the public keys from this URL.', 'digital-employee-wp-bridge' ); ?></em>
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
		$url = get_option( 'de_wp_bridge_external_issuer', '' );
		?>
		<input type="url" 
				name="de_wp_bridge_external_issuer" 
				value="<?php echo esc_attr( $url ); ?>" 
				class="large-text code" 
				placeholder="https://your-main-site.com" />
		<p class="description">
			<?php esc_html_e( 'The base URL of your main WordPress site (must match the JWT issuer claim).', 'digital-employee-wp-bridge' ); ?><br>
			<strong><?php esc_html_e( 'Example:', 'digital-employee-wp-bridge' ); ?></strong> <code>https://your-main-site.com</code><br>
			<em><?php esc_html_e( 'Tokens with a different issuer will be rejected for security.', 'digital-employee-wp-bridge' ); ?></em>
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
		wp_enqueue_style( 'digital-employee-wp-bridge-admin' );
		wp_enqueue_script( 'digital-employee-wp-bridge-admin' );
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$jwks_url  = esc_url( rest_url( DE_WP_BRIDGE_API_NAME_SPACE . '/jwks' ) );
		$token_url = esc_url( rest_url( DE_WP_BRIDGE_API_NAME_SPACE . '/context-token' ) );

		// Check external auth configuration status.
		$external_jwks_url   = get_option( 'de_wp_bridge_external_jwks_url', '' );
		$external_issuer     = get_option( 'de_wp_bridge_external_issuer', '' );
		$external_configured = ! empty( $external_jwks_url ) && ! empty( $external_issuer );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( __( 'Digital Employee Bridge', 'digital-employee-wp-bridge' ) ); ?></h1>
			
			<?php if ( $external_configured ) : ?>
				<div class="notice notice-success">
					<p>
						<strong><?php esc_html_e( '✓ Single Sign-On Enabled', 'digital-employee-wp-bridge' ); ?></strong><br>
						<?php
						printf(
							/* translators: %s: external site URL */
							esc_html__( 'This site is accepting JWT tokens from: %s', 'digital-employee-wp-bridge' ),
							'<code>' . esc_html( $external_issuer ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<div class="notice notice-info">
					<p>
						<strong><?php esc_html_e( 'Local Authentication Mode', 'digital-employee-wp-bridge' ); ?></strong><br>
						<?php esc_html_e( 'This site issues its own JWT tokens. Configure "External Authentication" below to enable Single Sign-On with another WordPress site.', 'digital-employee-wp-bridge' ); ?>
					</p>
				</div>
			<?php endif; ?>
			
			<form method="post" action="options.php">
				<?php
				settings_fields( 'de_wp_bridge_settings' );
				do_settings_sections( 'digital-employee-wp-bridge' );
				submit_button();
				?>
			</form>
			
			<hr>
			
			<h2><?php echo esc_html( __( 'This Site\'s Endpoints', 'digital-employee-wp-bridge' ) ); ?></h2>
			<p><?php esc_html_e( 'Use these URLs when configuring other sites to accept tokens from this site:', 'digital-employee-wp-bridge' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'JWKS URL:', 'digital-employee-wp-bridge' ); ?></th>
					<td>
						<code><?php echo esc_html( $jwks_url ); ?></code>
						<button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( $jwks_url ); ?>')">
							<?php esc_html_e( 'Copy', 'digital-employee-wp-bridge' ); ?>
						</button>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Issuer URL:', 'digital-employee-wp-bridge' ); ?></th>
					<td>
						<code><?php echo esc_html( rtrim( home_url(), '/' ) ); ?></code>
						<button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( rtrim( home_url(), '/' ) ); ?>')">
							<?php esc_html_e( 'Copy', 'digital-employee-wp-bridge' ); ?>
						</button>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Context Token URL:', 'digital-employee-wp-bridge' ); ?></th>
					<td>
						<code><?php echo esc_html( $token_url ); ?></code>
						<button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( $token_url ); ?>')">
							<?php esc_html_e( 'Copy', 'digital-employee-wp-bridge' ); ?>
						</button>
						<br><em><?php esc_html_e( '(Requires authentication)', 'digital-employee-wp-bridge' ); ?></em>
					</td>
				</tr>
			</table>
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
		<p><?php esc_html_e( 'Enable or disable individual API tools. Core routes (context-token, jwks) are always enabled and cannot be disabled.', 'digital-employee-wp-bridge' ); ?></p>
		<p class="description"><?php esc_html_e( 'Disabled tools will not be registered with WordPress REST API and will not be accessible.', 'digital-employee-wp-bridge' ); ?></p>
		<?php
	}

	/**
	 * Render the API tools field.
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public static function render_api_tools_field(): void {
		$registry     = Digital_Employee_WP_Bridge_API_Registry::instance();
		$tools        = $registry->get_tools_with_status();
		$tools_status = get_option( 'de_wp_bridge_tools_status', array() );
		if ( ! is_array( $tools_status ) ) {
			$tools_status = array();
		}
		?>
		<div class="de-wp-bridge-api-tools">
			<?php if ( ! empty( $tools ) ) : ?>
				<table class="form-table de-wp-bridge-tools-table">
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
									<span class="de-wp-bridge-toggle-switch <?php echo $tool['is_core'] ? 'de-wp-bridge-toggle-disabled' : ''; ?>">
										<?php if ( ! $is_enabled ) : ?>
											<input 
												type="hidden" 
												name="de_wp_bridge_tools_status[<?php echo esc_attr( $route ); ?>]"
												value="0"
											>
										<?php endif; ?>
										<input 
											type="checkbox" 
											id="<?php echo esc_attr( $tool_id ); ?>" 
											name="de_wp_bridge_tools_status[<?php echo esc_attr( $route ); ?>]"
											value="1"
											<?php checked( $is_enabled, true ); ?>
										>
										<span class="de-wp-bridge-slider"></span>
									</span>
									<code class="de-wp-bridge-route"><?php echo esc_html( $tool['route'] ); ?></code>
									<span class="de-wp-bridge-methods"><?php echo esc_html( implode( ', ', $tool['methods'] ) ); ?></span>
									<?php if ( ! empty( $tool['addon'] ) ) : ?>
										<span class="de-wp-bridge-addon-badge"><?php echo esc_html( $tool['addon'] ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No API tools registered yet.', 'digital-employee-wp-bridge' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
