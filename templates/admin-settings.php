<?php
/**
 * Admin settings page template — 7-tab layout.
 * Phase 7 D-I: Foundation tabbed layout with AJAX save.
 *
 * Template variables set by DEF_Core_Admin::render_settings_page():
 *   $settings       array  Current settings values.
 *   $tools          array  Registered tools from API registry.
 *   $tools_status   array  Tool enable/disable status.
 *   $urls           array  Endpoint reference URLs (jwks, issuer, token).
 *   $sso_configured bool   Whether external SSO is configured.
 *
 * @package def-core
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tabs = array(
	'connection'      => __( 'Connection', 'def-core' ),
	'branding'        => __( 'Branding', 'def-core' ),
	'chat-settings'   => __( 'Chat Settings', 'def-core' ),
	'escalation'      => __( 'Escalation', 'def-core' ),
	'employees-tools' => __( 'Employees & Tools', 'def-core' ),
	'user-roles'      => __( 'User Roles', 'def-core' ),
	'documentation'   => __( 'Documentation', 'def-core' ),
);

$first_tab = 'connection';
?>
<div class="wrap def-core-wrap">
	<h1><?php esc_html_e( 'Digital Employees', 'def-core' ); ?></h1>

	<!-- Toast container -->
	<div id="def-core-toast-container" class="def-core-toast-container" aria-live="polite"></div>

	<!-- Tab Navigation -->
	<nav class="def-core-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings', 'def-core' ); ?>">
		<?php foreach ( $tabs as $tab_id => $tab_label ) : ?>
			<button
				type="button"
				role="tab"
				id="tab-<?php echo esc_attr( $tab_id ); ?>"
				class="def-core-tab"
				aria-controls="panel-<?php echo esc_attr( $tab_id ); ?>"
				aria-selected="<?php echo ( $tab_id === $first_tab ) ? 'true' : 'false'; ?>"
				tabindex="<?php echo ( $tab_id === $first_tab ) ? '0' : '-1'; ?>"
			><?php echo esc_html( $tab_label ); ?></button>
		<?php endforeach; ?>
	</nav>

	<?php // ─── Connection Tab ─────────────────────────────────────────── ?>
	<div
		id="panel-connection"
		role="tabpanel"
		aria-labelledby="tab-connection"
		class="def-core-panel"
		tabindex="0"
	>
		<?php // Section A — DEF API Connection ?>
		<div class="def-core-card">
			<h2><?php esc_html_e( 'DEF API Connection', 'def-core' ); ?></h2>

			<div class="def-core-field">
				<label for="def_core_staff_ai_api_url"><?php esc_html_e( 'API URL', 'def-core' ); ?></label>
				<input
					type="url"
					id="def_core_staff_ai_api_url"
					data-setting="def_core_staff_ai_api_url"
					value="<?php echo esc_attr( $settings['api_url'] ); ?>"
					class="large-text code"
					placeholder="https://your-def-api.example.com"
					autocomplete="off"
				/>
				<p class="description">
					<?php esc_html_e( 'The base URL of the Digital Employee Framework Python API.', 'def-core' ); ?>
				</p>
			</div>

			<div class="def-core-field">
				<label for="def_core_api_key"><?php esc_html_e( 'API Key', 'def-core' ); ?></label>
				<div class="def-core-password-wrap">
					<input
						type="password"
						id="def_core_api_key"
						data-setting="def_core_api_key"
						value="<?php echo esc_attr( $settings['api_key'] ); ?>"
						class="large-text code"
						placeholder="<?php esc_attr_e( 'Enter API key', 'def-core' ); ?>"
						autocomplete="new-password"
					/>
					<button type="button" class="button def-core-password-toggle" aria-label="<?php esc_attr_e( 'Show API key', 'def-core' ); ?>">
						<span class="dashicons dashicons-visibility"></span>
					</button>
				</div>
				<p class="description">
					<?php esc_html_e( 'Used for outbound API authentication and inbound HMAC verification.', 'def-core' ); ?>
				</p>
			</div>

			<div class="def-core-field">
				<div class="def-core-connection-test">
					<button type="button" id="def-core-test-connection" class="button">
						<?php esc_html_e( 'Test Connection', 'def-core' ); ?>
					</button>
					<span id="def-core-connection-result" class="def-core-connection-result"></span>
				</div>
			</div>
		</div>

		<?php // Section B — Session Bridge ?>
		<div class="def-core-card">
			<h2><?php esc_html_e( 'Session Bridge — Allowed Origins', 'def-core' ); ?></h2>

			<div class="def-core-field">
				<label for="def_core_allowed_origins"><?php esc_html_e( 'Allowed Origins', 'def-core' ); ?></label>
				<?php
				$origins_text = '';
				if ( is_array( $settings['allowed_origins'] ) ) {
					$origins_text = implode( "\n", array_map( 'esc_url_raw', $settings['allowed_origins'] ) );
				}
				?>
				<textarea
					id="def_core_allowed_origins"
					data-setting="def_core_allowed_origins"
					rows="4"
					class="large-text code"
				><?php echo esc_textarea( $origins_text ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'One origin per line. Example: https://your-azure-app.azurewebsites.net', 'def-core' ); ?>
				</p>
			</div>
		</div>

		<?php // Section C — External Auth (SSO) ?>
		<div class="def-core-card">
			<h2><?php esc_html_e( 'External Authentication (SSO)', 'def-core' ); ?></h2>

			<?php if ( $sso_configured ) : ?>
				<div class="def-core-notice def-core-notice-success">
					<p>
						<strong><?php esc_html_e( 'Single Sign-On Enabled', 'def-core' ); ?></strong><br>
						<?php
						printf(
							/* translators: %s: external site URL */
							esc_html__( 'Accepting JWT tokens from: %s', 'def-core' ),
							'<code>' . esc_html( $settings['external_issuer'] ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<div class="def-core-notice def-core-notice-info">
					<p>
						<strong><?php esc_html_e( 'Local Authentication Mode', 'def-core' ); ?></strong><br>
						<?php esc_html_e( 'Configure the fields below to enable Single Sign-On with another WordPress site.', 'def-core' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="def-core-field">
				<label for="def_core_external_jwks_url"><?php esc_html_e( 'External JWKS URL', 'def-core' ); ?></label>
				<input
					type="url"
					id="def_core_external_jwks_url"
					data-setting="def_core_external_jwks_url"
					value="<?php echo esc_attr( $settings['external_jwks'] ); ?>"
					class="large-text code"
					placeholder="<?php echo esc_attr( 'https://your-main-site.com/wp-json/' . DEF_CORE_API_NAME_SPACE . '/jwks' ); ?>"
				/>
				<p class="description">
					<?php esc_html_e( 'The JWKS URL from your main WordPress site.', 'def-core' ); ?>
				</p>
			</div>

			<div class="def-core-field">
				<label for="def_core_external_issuer"><?php esc_html_e( 'External Issuer URL', 'def-core' ); ?></label>
				<input
					type="url"
					id="def_core_external_issuer"
					data-setting="def_core_external_issuer"
					value="<?php echo esc_attr( $settings['external_issuer'] ); ?>"
					class="large-text code"
					placeholder="https://your-main-site.com"
				/>
				<p class="description">
					<?php esc_html_e( 'The base URL of your main WordPress site (must match the JWT issuer claim).', 'def-core' ); ?>
				</p>
			</div>

			<h3><?php esc_html_e( 'This Site\'s Endpoints', 'def-core' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Use these URLs when configuring other sites to accept tokens from this site:', 'def-core' ); ?></p>
			<table class="def-core-endpoints-table">
				<tr>
					<th><?php esc_html_e( 'JWKS URL', 'def-core' ); ?></th>
					<td>
						<code><?php echo esc_html( $urls['jwks'] ); ?></code>
						<button type="button" class="button button-small def-core-copy-btn" data-copy="<?php echo esc_attr( $urls['jwks'] ); ?>">
							<?php esc_html_e( 'Copy', 'def-core' ); ?>
						</button>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Issuer URL', 'def-core' ); ?></th>
					<td>
						<code><?php echo esc_html( $urls['issuer'] ); ?></code>
						<button type="button" class="button button-small def-core-copy-btn" data-copy="<?php echo esc_attr( $urls['issuer'] ); ?>">
							<?php esc_html_e( 'Copy', 'def-core' ); ?>
						</button>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Context Token', 'def-core' ); ?></th>
					<td>
						<code><?php echo esc_html( $urls['token'] ); ?></code>
						<button type="button" class="button button-small def-core-copy-btn" data-copy="<?php echo esc_attr( $urls['token'] ); ?>">
							<?php esc_html_e( 'Copy', 'def-core' ); ?>
						</button>
						<em class="description"><?php esc_html_e( '(Requires authentication)', 'def-core' ); ?></em>
					</td>
				</tr>
			</table>
		</div>

		<?php // Section D — Service Auth ?>
		<div class="def-core-card">
			<h2><?php esc_html_e( 'Service Authentication', 'def-core' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Service-to-service authentication for the Python backend. Required for anonymous customer escalation.', 'def-core' ); ?>
			</p>

			<div class="def-core-field">
				<label for="def_service_auth_secret"><?php esc_html_e( 'Service Auth Secret', 'def-core' ); ?></label>
				<input
					type="text"
					id="def_service_auth_secret"
					value="<?php echo esc_attr( $settings['service_secret'] ); ?>"
					class="large-text code"
					readonly
					onclick="this.select();"
				/>
				<p class="def-core-service-auth-actions">
					<button type="button" class="button button-small def-core-copy-btn" data-copy="<?php echo esc_attr( $settings['service_secret'] ); ?>" id="def-core-copy-secret-btn">
						<?php esc_html_e( 'Copy', 'def-core' ); ?>
					</button>
					<button type="button" class="button button-small" id="def-core-regenerate-secret-btn">
						<?php esc_html_e( 'Generate New Secret', 'def-core' ); ?>
					</button>
				</p>
				<p class="description">
					<?php esc_html_e( 'Copy this value to your Python app\'s .env file:', 'def-core' ); ?><br>
					<code id="def-core-secret-env-line">DEF_SERVICE_AUTH_SECRET=<?php echo esc_html( $settings['service_secret'] ); ?></code>
				</p>
				<p class="description">
					<em><?php esc_html_e( 'Warning: Generating a new secret will invalidate the current one. Update your Python app immediately.', 'def-core' ); ?></em>
				</p>
			</div>
		</div>

		<div class="def-core-save-area">
			<button type="button" class="button button-primary def-core-save-btn" data-tab="connection">
				<?php esc_html_e( 'Save Changes', 'def-core' ); ?>
			</button>
			<span class="spinner"></span>
		</div>
	</div>

	<?php // ─── Branding Tab ──────────────────────────────────────────── ?>
	<div
		id="panel-branding"
		role="tabpanel"
		aria-labelledby="tab-branding"
		class="def-core-panel"
		tabindex="0"
		hidden
	>
		<div class="def-core-card">
			<h2><?php esc_html_e( 'Logo & Branding', 'def-core' ); ?></h2>

			<div class="def-core-field">
				<label><?php esc_html_e( 'Logo', 'def-core' ); ?></label>
				<div class="def-core-logo-upload">
					<div id="def-core-logo-preview" class="def-core-logo-preview">
						<?php if ( $logo_url ) : ?>
							<img src="<?php echo esc_url( $logo_url ); ?>" style="max-height: 120px; width: auto;" />
						<?php else : ?>
							<span class="def-core-no-logo"><?php esc_html_e( 'No logo selected', 'def-core' ); ?></span>
						<?php endif; ?>
					</div>
					<input type="hidden" id="def_core_logo_id" data-setting="def_core_logo_id" value="<?php echo esc_attr( $branding['logo_id'] ); ?>" />
					<p class="def-core-logo-actions">
						<button type="button" class="button" id="def-core-select-logo">
							<?php esc_html_e( 'Select Logo', 'def-core' ); ?>
						</button>
						<button type="button" class="button" id="def-core-remove-logo" style="<?php echo $branding['logo_id'] ? '' : 'display: none;'; ?>">
							<?php esc_html_e( 'Remove Logo', 'def-core' ); ?>
						</button>
					</p>
					<p class="description">
						<?php esc_html_e( 'Upload a logo for your Digital Employees. Used in Staff AI and Customer Chat headers.', 'def-core' ); ?>
					</p>
				</div>
			</div>

			<div class="def-core-field">
				<label for="def_core_display_name"><?php esc_html_e( 'Display Name', 'def-core' ); ?></label>
				<input
					type="text"
					id="def_core_display_name"
					data-setting="def_core_display_name"
					value="<?php echo esc_attr( $branding['display_name'] ); ?>"
					class="regular-text"
					maxlength="100"
				/>
				<p class="description">
					<?php esc_html_e( 'Shown in chat headers when no logo is available. Defaults to your site name.', 'def-core' ); ?>
				</p>
			</div>

			<h3><?php esc_html_e( 'Logo Visibility', 'def-core' ); ?></h3>

			<div class="def-core-field def-core-checkbox-field">
				<label>
					<input
						type="checkbox"
						id="def_core_logo_show_staff_ai"
						data-setting="def_core_logo_show_staff_ai"
						value="1"
						<?php checked( $branding['logo_show_staff_ai'] ); ?>
					/>
					<?php esc_html_e( 'Show logo in Staff AI chat header', 'def-core' ); ?>
				</label>
			</div>

			<div class="def-core-field def-core-checkbox-field">
				<label>
					<input
						type="checkbox"
						id="def_core_logo_show_customer_chat"
						data-setting="def_core_logo_show_customer_chat"
						value="1"
						<?php checked( $branding['logo_show_customer_chat'] ); ?>
					/>
					<?php esc_html_e( 'Show logo in Customer Chat header', 'def-core' ); ?>
				</label>
			</div>

			<div class="def-core-field">
				<label for="def_core_logo_max_height"><?php esc_html_e( 'Logo Max Height (px)', 'def-core' ); ?></label>
				<input
					type="number"
					id="def_core_logo_max_height"
					data-setting="def_core_logo_max_height"
					value="<?php echo esc_attr( $branding['logo_max_height'] ); ?>"
					min="24"
					max="120"
					class="small-text"
				/>
				<p class="description">
					<?php esc_html_e( 'Maximum display height for the logo. Range: 24–120px.', 'def-core' ); ?>
				</p>
			</div>
		</div>

		<div class="def-core-save-area">
			<button type="button" class="button button-primary def-core-save-btn" data-tab="branding">
				<?php esc_html_e( 'Save Changes', 'def-core' ); ?>
			</button>
			<span class="spinner"></span>
		</div>
	</div>

	<?php // ─── Chat Settings Tab ────────────────────────────────────────── ?>
	<div
		id="panel-chat-settings"
		role="tabpanel"
		aria-labelledby="tab-chat-settings"
		class="def-core-panel"
		tabindex="0"
		hidden
	>
		<div class="def-core-card">
			<h2><?php esc_html_e( 'Customer Chat Display', 'def-core' ); ?></h2>

			<div class="def-core-field">
				<label><?php esc_html_e( 'Display Mode', 'def-core' ); ?></label>
				<div class="def-core-radio-group">
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_display_mode"
							data-setting="def_core_chat_display_mode"
							value="modal"
							<?php checked( $chat_settings['display_mode'], 'modal' ); ?>
						/>
						<strong><?php esc_html_e( 'Modal', 'def-core' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Chat opens in a centered overlay window.', 'def-core' ); ?></span>
					</label>
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_display_mode"
							data-setting="def_core_chat_display_mode"
							value="drawer"
							<?php checked( $chat_settings['display_mode'], 'drawer' ); ?>
						/>
						<strong><?php esc_html_e( 'Drawer', 'def-core' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Chat slides in from the right edge of the screen.', 'def-core' ); ?></span>
					</label>
				</div>
			</div>

			<div id="def-core-drawer-options" style="<?php echo 'drawer' === $chat_settings['display_mode'] ? '' : 'display: none;'; ?>">
				<div class="def-core-field">
					<label for="def_core_chat_drawer_width"><?php esc_html_e( 'Drawer Width (px)', 'def-core' ); ?></label>
					<input
						type="number"
						id="def_core_chat_drawer_width"
						data-setting="def_core_chat_drawer_width"
						value="<?php echo esc_attr( $chat_settings['drawer_width'] ); ?>"
						min="300"
						max="600"
						class="small-text"
					/>
					<p class="description">
						<?php esc_html_e( 'Width of the chat drawer in pixels. Range: 300–600px.', 'def-core' ); ?>
					</p>
				</div>
			</div>
		</div>

		<div class="def-core-card">
			<h2><?php esc_html_e( 'Chat Button Appearance', 'def-core' ); ?></h2>

			<div class="def-core-field">
				<label><?php esc_html_e( 'Button Position', 'def-core' ); ?></label>
				<div class="def-core-radio-group">
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_button_position"
							data-setting="def_core_chat_button_position"
							value="right"
							<?php checked( $button_settings['position'], 'right' ); ?>
						/>
						<strong><?php esc_html_e( 'Right', 'def-core' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Bottom-right corner of the page.', 'def-core' ); ?></span>
					</label>
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_button_position"
							data-setting="def_core_chat_button_position"
							value="left"
							<?php checked( $button_settings['position'], 'left' ); ?>
						/>
						<strong><?php esc_html_e( 'Left', 'def-core' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Bottom-left corner of the page.', 'def-core' ); ?></span>
					</label>
				</div>
			</div>

			<div class="def-core-field">
				<label for="def_core_chat_button_color"><?php esc_html_e( 'Button Color', 'def-core' ); ?></label>
				<div class="def-core-color-field">
					<input
						type="color"
						id="def_core_chat_button_color"
						data-setting="def_core_chat_button_color"
						value="<?php echo esc_attr( $button_settings['color'] ); ?>"
					/>
					<span class="def-core-color-value"><?php echo esc_html( $button_settings['color'] ); ?></span>
				</div>
				<p class="description">
					<?php esc_html_e( 'Background color for the floating chat button.', 'def-core' ); ?>
				</p>
			</div>

			<div class="def-core-field">
				<label for="def_core_chat_button_hover_color"><?php esc_html_e( 'Button Hover Color', 'def-core' ); ?></label>
				<div class="def-core-color-field">
					<input
						type="color"
						id="def_core_chat_button_hover_color"
						data-setting="def_core_chat_button_hover_color"
						value="<?php echo esc_attr( $button_settings['hover_color'] ? $button_settings['hover_color'] : $button_settings['color'] ); ?>"
					/>
					<span class="def-core-color-value"><?php echo esc_html( $button_settings['hover_color'] ? $button_settings['hover_color'] : $button_settings['color'] ); ?></span>
				</div>
				<p class="description">
					<?php esc_html_e( 'Background color when hovering over the chat button. Defaults to the button color if not set.', 'def-core' ); ?>
				</p>
			</div>

			<div class="def-core-field">
				<label><?php esc_html_e( 'Button Icon', 'def-core' ); ?></label>
				<div class="def-core-radio-group def-core-icon-radios">
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_button_icon"
							data-setting="def_core_chat_button_icon"
							value="chat"
							<?php checked( $button_settings['icon'], 'chat' ); ?>
						/>
						<span class="def-core-icon-preview">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
						</span>
						<strong><?php esc_html_e( 'Chat bubble', 'def-core' ); ?></strong>
					</label>
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_button_icon"
							data-setting="def_core_chat_button_icon"
							value="headset"
							<?php checked( $button_settings['icon'], 'headset' ); ?>
						/>
						<span class="def-core-icon-preview">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>
						</span>
						<strong><?php esc_html_e( 'Headset', 'def-core' ); ?></strong>
					</label>
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_button_icon"
							data-setting="def_core_chat_button_icon"
							value="custom"
							<?php checked( $button_settings['icon'], 'custom' ); ?>
						/>
						<strong><?php esc_html_e( 'Custom', 'def-core' ); ?></strong>
					</label>
				</div>
			</div>

			<div id="def-core-custom-icon-upload" class="def-core-field" style="<?php echo 'custom' === $button_settings['icon'] ? '' : 'display: none;'; ?>">
				<label><?php esc_html_e( 'Custom Icon', 'def-core' ); ?></label>
				<div class="def-core-logo-upload">
					<div id="def-core-icon-preview" class="def-core-logo-preview">
						<?php if ( $button_icon_url ) : ?>
							<img src="<?php echo esc_url( $button_icon_url ); ?>" style="max-height: 48px; width: auto;" />
						<?php else : ?>
							<span class="def-core-no-logo"><?php esc_html_e( 'No icon selected', 'def-core' ); ?></span>
						<?php endif; ?>
					</div>
					<input type="hidden" id="def_core_chat_button_icon_id" data-setting="def_core_chat_button_icon_id" value="<?php echo esc_attr( $button_settings['icon_id'] ); ?>" />
					<p class="def-core-logo-actions">
						<button type="button" class="button" id="def-core-select-icon">
							<?php esc_html_e( 'Select Icon', 'def-core' ); ?>
						</button>
						<button type="button" class="button" id="def-core-remove-icon" style="<?php echo $button_settings['icon_id'] ? '' : 'display: none;'; ?>">
							<?php esc_html_e( 'Remove Icon', 'def-core' ); ?>
						</button>
					</p>
					<p class="description">
						<?php esc_html_e( 'Upload a custom icon for the chat button. Recommended: 48×48px PNG or SVG.', 'def-core' ); ?>
					</p>
				</div>
			</div>

			<div class="def-core-field def-core-checkbox-field">
				<label>
					<input
						type="checkbox"
						id="def_core_chat_show_floating"
						data-setting="def_core_chat_show_floating"
						value="1"
						<?php checked( $button_settings['show_floating'] ); ?>
					/>
					<?php esc_html_e( 'Show floating chat button on frontend', 'def-core' ); ?>
				</label>
			</div>

			<div id="def-core-floating-warning" class="def-core-notice def-core-notice-warning" style="<?php echo $button_settings['show_floating'] ? 'display: none;' : ''; ?>">
				<p>
					<?php esc_html_e( 'The floating chat button is hidden. Make sure you\'ve placed the [def_chat_button] shortcode or theme hook, otherwise visitors won\'t be able to open the chat.', 'def-core' ); ?>
				</p>
			</div>
		</div>

		<div class="def-core-save-area">
			<button type="button" class="button button-primary def-core-save-btn" data-tab="chat-settings">
				<?php esc_html_e( 'Save Changes', 'def-core' ); ?>
			</button>
			<span class="spinner"></span>
		</div>
	</div>

	<?php // ─── Escalation Tab ───────────────────────────────────────────── ?>
	<div
		id="panel-escalation"
		role="tabpanel"
		aria-labelledby="tab-escalation"
		class="def-core-panel"
		tabindex="0"
		hidden
	>
		<div class="def-core-card">
			<h2><?php esc_html_e( 'Escalation Email Settings', 'def-core' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'When a user accepts an AI escalation offer, the request is emailed to the address configured for each channel. If no email is set, the WordPress admin email is used.', 'def-core' ); ?>
			</p>

			<?php
			$channels_info = array(
				'customer'        => array(
					'label' => __( 'Customer Chat', 'def-core' ),
					'help'  => __( 'Receives escalation emails from anonymous or logged-in customer chat sessions.', 'def-core' ),
				),
				'setup_assistant' => array(
					'label' => __( 'Setup Assistant', 'def-core' ),
					'help'  => __( "Enter your DEF Partner's email address here for Setup Assistant human escalation.", 'def-core' ),
				),
			);
			?>

			<?php foreach ( $channels_info as $channel_id => $channel_info ) : ?>
				<div class="def-core-field">
					<label for="escalation_<?php echo esc_attr( $channel_id ); ?>">
						<?php echo esc_html( $channel_info['label'] ); ?>
					</label>
					<div class="def-core-escalation-row">
						<input
							type="email"
							id="escalation_<?php echo esc_attr( $channel_id ); ?>"
							data-setting="escalation_<?php echo esc_attr( $channel_id ); ?>"
							value="<?php echo esc_attr( $escalation[ $channel_id ] ); ?>"
							class="regular-text"
							placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
						/>
						<button
							type="button"
							class="button button-small def-core-test-email-btn"
							data-channel="<?php echo esc_attr( $channel_id ); ?>"
						><?php esc_html_e( 'Test Email', 'def-core' ); ?></button>
					</div>
					<p class="description"><?php echo esc_html( $channel_info['help'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="def-core-save-area">
			<button type="button" class="button button-primary def-core-save-btn" data-tab="escalation">
				<?php esc_html_e( 'Save Changes', 'def-core' ); ?>
			</button>
			<span class="spinner"></span>
		</div>
	</div>

	<?php // ─── User Roles Tab ───────────────────────────────────────────── ?>
	<div
		id="panel-user-roles"
		role="tabpanel"
		aria-labelledby="tab-user-roles"
		class="def-core-panel"
		tabindex="0"
		hidden
	>
		<div class="def-core-card">
			<h2><?php esc_html_e( 'User Access', 'def-core' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Staff and Management both grant login access to Staff AI but at different document authority levels — Management users can access documents that Staff users cannot. DEF Admin grants access to this settings page.', 'def-core' ); ?>
			</p>

			<table class="def-core-roles-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'def-core' ); ?></th>
						<th><?php esc_html_e( 'WordPress Role', 'def-core' ); ?></th>
						<th class="def-core-role-col"><?php esc_html_e( 'Staff', 'def-core' ); ?></th>
						<th class="def-core-role-col"><?php esc_html_e( 'Management', 'def-core' ); ?></th>
						<th class="def-core-role-col"><?php esc_html_e( 'DEF Admin', 'def-core' ); ?></th>
						<th class="def-core-role-col"><?php esc_html_e( 'Actions', 'def-core' ); ?></th>
					</tr>
				</thead>
				<tbody id="def-core-roles-tbody">
					<?php foreach ( $def_users as $u ) :
						$is_last_admin = $u->has_cap( 'def_admin_access' ) && $def_admin_count <= 1;
						$is_locked     = $is_last_admin;
						?>
						<tr data-user-id="<?php echo esc_attr( $u->ID ); ?>">
							<td>
								<?php echo get_avatar( $u->ID, 24, '', '', array( 'class' => 'def-core-user-avatar' ) ); ?>
								<?php echo esc_html( $u->display_name ); ?>
								<span class="def-core-user-email"><?php echo esc_html( $u->user_email ); ?></span>
							</td>
							<td><?php echo esc_html( implode( ', ', array_map( 'ucfirst', $u->roles ) ) ); ?></td>
							<td class="def-core-role-col">
								<input
									type="checkbox"
									class="def-core-role-cb"
									data-user="<?php echo esc_attr( $u->ID ); ?>"
									data-cap="def_staff_access"
									<?php checked( $u->has_cap( 'def_staff_access' ) ); ?>
								/>
							</td>
							<td class="def-core-role-col">
								<input
									type="checkbox"
									class="def-core-role-cb"
									data-user="<?php echo esc_attr( $u->ID ); ?>"
									data-cap="def_management_access"
									<?php checked( $u->has_cap( 'def_management_access' ) ); ?>
								/>
							</td>
							<td class="def-core-role-col">
								<input
									type="checkbox"
									class="def-core-role-cb"
									data-user="<?php echo esc_attr( $u->ID ); ?>"
									data-cap="def_admin_access"
									<?php checked( $u->has_cap( 'def_admin_access' ) ); ?>
									<?php disabled( $is_locked ); ?>
								/>
								<?php if ( $is_locked ) : ?>
									<span class="def-core-locked-label" title="<?php esc_attr_e( 'At least one DEF Admin is required', 'def-core' ); ?>"><?php esc_html_e( 'locked', 'def-core' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="def-core-role-col">
								<?php if ( ! $is_locked ) : ?>
									<button type="button" class="def-core-remove-user-btn" data-user-id="<?php echo esc_attr( $u->ID ); ?>" title="<?php esc_attr_e( 'Remove all DEF access', 'def-core' ); ?>">&times;</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<!-- Add User search -->
			<div class="def-core-add-user-section">
				<label for="def-core-user-search"><?php esc_html_e( 'Add User', 'def-core' ); ?></label>
				<div class="def-core-user-search-wrap">
					<input
						type="text"
						id="def-core-user-search"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'Search by email or name...', 'def-core' ); ?>"
						autocomplete="off"
					/>
					<div id="def-core-user-search-results" class="def-core-search-results" hidden></div>
				</div>
			</div>
		</div>

		<div class="def-core-save-area">
			<button type="button" class="button button-primary def-core-save-roles-btn">
				<?php esc_html_e( 'Save User Roles', 'def-core' ); ?>
			</button>
			<span class="spinner"></span>
		</div>
	</div>

	<?php // ─── Employees & Tools Tab ──────────────────────────────────── ?>
	<div
		id="panel-employees-tools"
		role="tabpanel"
		aria-labelledby="tab-employees-tools"
		class="def-core-panel"
		tabindex="0"
		hidden
	>
		<div class="def-core-card">
			<h2><?php esc_html_e( 'API Tools', 'def-core' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Enable or disable individual API tools. Core routes (context-token, jwks) are always enabled.', 'def-core' ); ?>
			</p>

			<?php if ( ! empty( $tools ) ) : ?>
				<table class="def-core-tools-table">
					<tbody>
						<?php foreach ( $tools as $route => $tool ) :
							$tool_id    = 'tool_' . md5( $route );
							$status     = isset( $tools_status[ $route ] ) ? (int) $tools_status[ $route ] : 1;
							$is_enabled = 1 === $status;
							$is_core    = ! empty( $tool['is_core'] );
							?>
							<tr class="<?php echo $is_enabled ? 'def-core-enabled' : 'def-core-disabled'; ?>">
								<th scope="row">
									<label for="<?php echo esc_attr( $tool_id ); ?>">
										<?php echo esc_html( $tool['name'] ); ?>
									</label>
								</th>
								<td>
									<span class="def-core-toggle-switch <?php echo $is_core ? 'def-core-toggle-disabled' : ''; ?>">
										<input
											type="checkbox"
											id="<?php echo esc_attr( $tool_id ); ?>"
											class="def-core-tool-toggle"
											data-route="<?php echo esc_attr( $route ); ?>"
											value="1"
											<?php checked( $is_enabled, true ); ?>
											<?php disabled( $is_core, true ); ?>
										/>
										<span class="def-core-slider"></span>
									</span>
									<code class="def-core-route"><?php echo esc_html( $tool['route'] ); ?></code>
									<span class="def-core-methods"><?php echo esc_html( implode( ', ', $tool['methods'] ) ); ?></span>
									<?php if ( ! empty( $tool['module'] ) ) : ?>
										<span class="def-core-module-badge"><?php echo esc_html( $tool['module'] ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No API tools registered.', 'def-core' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="def-core-save-area">
			<button type="button" class="button button-primary def-core-save-btn" data-tab="employees-tools">
				<?php esc_html_e( 'Save Changes', 'def-core' ); ?>
			</button>
			<span class="spinner"></span>
		</div>
	</div>

	<?php // ─── Documentation Tab ──────────────────────────────────────── ?>
	<div
		id="panel-documentation"
		role="tabpanel"
		aria-labelledby="tab-documentation"
		class="def-core-panel"
		tabindex="0"
		hidden
	>
		<div class="def-core-card">
			<h2><?php esc_html_e( 'Chatbot Widget Integration Guide', 'def-core' ); ?></h2>
			<p><?php esc_html_e( 'Learn how to integrate the Digital Employee chatbot popup widget into your WordPress site.', 'def-core' ); ?></p>
		</div>

		<div class="def-core-card def-core-widget-guide">
			<h3><?php esc_html_e( 'Quick Start', 'def-core' ); ?></h3>
			<p><?php esc_html_e( 'The chatbot widget is an embeddable JavaScript file that creates a floating chat popup on your website. Add it to your theme.', 'def-core' ); ?></p>

			<h4><?php esc_html_e( 'Direct Script Tag in Theme', 'def-core' ); ?></h4>
			<p><?php esc_html_e( 'Add this to your theme\'s header.php or footer.php (before closing </body> tag):', 'def-core' ); ?></p>
			<pre><code><?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- documentation example
			$script_example = '<script
    src="https://a3revai.azurewebsites.net/widget/popup.js"
    data-chat-url="https://a3revai.azurewebsites.net/v2"
    data-position="right"
    data-open="false"
    async>
</script>';
			echo esc_html( $script_example );
			?></code></pre>

			<h3><?php esc_html_e( 'Configuration Options', 'def-core' ); ?></h3>
			<p><?php esc_html_e( 'The widget supports the following data attributes:', 'def-core' ); ?></p>

			<div class="widget-attribute">
				<strong>data-chat-url</strong><br>
				<?php esc_html_e( 'The URL of the Digital Employee Framework interface to load in the iframe.', 'def-core' ); ?><br>
				<em><?php esc_html_e( 'Default:', 'def-core' ); ?> <code>https://a3revai.azurewebsites.net/v2</code></em>
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
					<span class="widget-guide-arrow">&#9654;</span>
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
					<span class="widget-guide-arrow">&#9654;</span>
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
					<span class="widget-guide-arrow">&#9654;</span>
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
					<span class="widget-guide-arrow">&#9654;</span>
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
	</div>
</div>
