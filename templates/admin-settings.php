<?php
/**
 * Admin settings page template — 6-tab layout.
 * Phase 7 D-I: Foundation tabbed layout with AJAX save.
 * Connection Config Migration: Connection moved to last tab with status dot indicator.
 *
 * Template variables set by DEF_Core_Admin::render_settings_page():
 *   $conn_api_url    string  DEF API URL (pushed from DEFHO).
 *   $conn_revision   int     Current connection config revision.
 *   $conn_last_sync  string  ISO 8601 timestamp of last sync.
 *   $tools           array   Registered tools from API registry.
 *   $tools_status    array   Tool enable/disable status.
 *
 * @package def-core
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tabs = array(
	'branding'        => __( 'Branding', 'digital-employees' ),
	'chat-settings'   => __( 'Chat Settings', 'digital-employees' ),
	'escalation'      => __( 'Escalation', 'digital-employees' ),
	'user-roles'      => __( 'User Roles', 'digital-employees' ),
	'connection'      => __( 'Connection', 'digital-employees' ),
);

$first_tab = 'branding';
?>
<div class="wrap def-core-wrap">
	<h1><?php esc_html_e( 'Digital Employees', 'digital-employees' ); ?></h1>

	<!-- Toast container -->
	<div id="def-core-toast-container" class="def-core-toast-container" aria-live="polite"></div>

	<?php
	$is_connected = ! empty( $conn_api_url ) && $conn_revision > 0;
	$status_class = $is_connected ? 'connected' : 'disconnected';
	$status_label = $is_connected
		? __( 'Connected', 'digital-employees' )
		: __( 'Not Connected', 'digital-employees' );
	?>

	<!-- Tab Navigation -->
	<nav class="def-core-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings', 'digital-employees' ); ?>">
		<?php foreach ( $tabs as $tab_id => $tab_label ) : ?>
			<button
				type="button"
				role="tab"
				id="tab-<?php echo esc_attr( $tab_id ); ?>"
				class="def-core-tab<?php echo ( 'connection' === $tab_id ) ? ' def-core-tab-connection' : ''; ?>"
				aria-controls="panel-<?php echo esc_attr( $tab_id ); ?>"
				aria-selected="<?php echo ( $tab_id === $first_tab ) ? 'true' : 'false'; ?>"
				tabindex="<?php echo ( $tab_id === $first_tab ) ? '0' : '-1'; ?>"
			><?php
			if ( 'connection' === $tab_id ) {
				echo '<span class="def-core-conn-dot-tab ' . esc_attr( $status_class ) . '"></span>';
			}
			echo esc_html( $tab_label );
			?></button>
		<?php endforeach; ?>
	</nav>

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
			<h2><?php esc_html_e( 'Logo & Branding', 'digital-employees' ); ?></h2>

			<div class="def-core-field">
				<label><?php esc_html_e( 'Logo', 'digital-employees' ); ?></label>
				<div class="def-core-logo-upload">
					<div id="def-core-logo-preview" class="def-core-logo-preview">
						<?php if ( $logo_url ) : ?>
							<img src="<?php echo esc_url( $logo_url ); ?>" style="max-height: 120px; width: auto;" />
						<?php else : ?>
							<span class="def-core-no-logo"><?php esc_html_e( 'No logo selected', 'digital-employees' ); ?></span>
						<?php endif; ?>
					</div>
					<input type="hidden" id="def_core_logo_id" data-setting="def_core_logo_id" value="<?php echo esc_attr( $branding['logo_id'] ); ?>" />
					<p class="def-core-logo-actions">
						<button type="button" class="button" id="def-core-select-logo">
							<?php esc_html_e( 'Select Logo', 'digital-employees' ); ?>
						</button>
						<button type="button" class="button" id="def-core-remove-logo" style="<?php echo $branding['logo_id'] ? '' : 'display: none;'; ?>">
							<?php esc_html_e( 'Remove Logo', 'digital-employees' ); ?>
						</button>
					</p>
					<p class="description">
						<?php esc_html_e( 'Upload a logo for your Digital Employees. Used in Staff AI and Customer Chat headers.', 'digital-employees' ); ?>
					</p>
				</div>
			</div>

			<div class="def-core-field">
				<label for="def_core_display_name"><?php esc_html_e( 'Display Name', 'digital-employees' ); ?></label>
				<input
					type="text"
					id="def_core_display_name"
					data-setting="def_core_display_name"
					value="<?php echo esc_attr( $branding['display_name'] ); ?>"
					class="regular-text"
					maxlength="100"
				/>
				<p class="description">
					<?php esc_html_e( 'Shown in chat headers when no logo is available. Defaults to your site name.', 'digital-employees' ); ?>
				</p>
			</div>

			<h3><?php esc_html_e( 'Logo Visibility', 'digital-employees' ); ?></h3>

			<div class="def-core-field def-core-checkbox-field">
				<label>
					<input
						type="checkbox"
						id="def_core_logo_show_staff_ai"
						data-setting="def_core_logo_show_staff_ai"
						value="1"
						<?php checked( $branding['logo_show_staff_ai'] ); ?>
					/>
					<?php esc_html_e( 'Show logo in Staff AI chat header', 'digital-employees' ); ?>
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
					<?php esc_html_e( 'Show logo in Customer Chat header', 'digital-employees' ); ?>
				</label>
			</div>

			<div class="def-core-field">
				<label for="def_core_logo_max_height"><?php esc_html_e( 'Logo Max Height (px)', 'digital-employees' ); ?></label>
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
					<?php esc_html_e( 'Maximum display height for the logo. Range: 24–120px.', 'digital-employees' ); ?>
				</p>
			</div>
		</div>

		<div class="def-core-section">
			<h3><?php esc_html_e( 'Web App Icon', 'digital-employees' ); ?></h3>
			<hr />

			<div class="def-core-field">
				<label><?php esc_html_e( 'App Icon', 'digital-employees' ); ?></label>
				<div class="def-core-image-upload" id="def-core-app-icon-upload">
					<?php if ( $branding['app_icon_url'] ) : ?>
						<div class="def-core-image-preview" id="def-core-app-icon-preview">
							<img src="<?php echo esc_url( $branding['app_icon_url'] ); ?>" alt="" style="max-width: 128px; max-height: 128px; border-radius: 16px;" />
						</div>
					<?php else : ?>
						<div class="def-core-image-preview" id="def-core-app-icon-preview" style="display: none;">
							<img src="" alt="" style="max-width: 128px; max-height: 128px; border-radius: 16px;" />
						</div>
					<?php endif; ?>
					<input type="hidden" id="def_core_app_icon_id" data-setting="def_core_app_icon_id" value="<?php echo esc_attr( $branding['app_icon_id'] ); ?>" />
					<div class="def-core-image-buttons">
						<button type="button" class="button" id="def-core-select-app-icon">
							<?php esc_html_e( 'Select Icon', 'digital-employees' ); ?>
						</button>
						<button type="button" class="button" id="def-core-remove-app-icon" style="<?php echo $branding['app_icon_id'] ? '' : 'display: none;'; ?>">
							<?php esc_html_e( 'Remove Icon', 'digital-employees' ); ?>
						</button>
					</div>
				</div>
				<p class="description">
					<?php esc_html_e( 'Upload a square PNG icon (512×512px recommended) for the Staff AI desktop app. If not set, an icon is auto-generated from your site name.', 'digital-employees' ); ?>
				</p>
			</div>
		</div>

		<div class="def-core-save-area">
			<button type="button" class="button button-primary def-core-save-btn" data-tab="branding">
				<?php esc_html_e( 'Save Changes', 'digital-employees' ); ?>
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
			<h2><?php esc_html_e( 'Customer Chat Display', 'digital-employees' ); ?></h2>

			<div class="def-core-field">
				<label><?php esc_html_e( 'Display Mode', 'digital-employees' ); ?></label>
				<div class="def-core-radio-group">
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_display_mode"
							data-setting="def_core_chat_display_mode"
							value="modal"
							<?php checked( $chat_settings['display_mode'], 'modal' ); ?>
						/>
						<strong><?php esc_html_e( 'Modal', 'digital-employees' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Chat opens in a centered overlay window.', 'digital-employees' ); ?></span>
					</label>
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_display_mode"
							data-setting="def_core_chat_display_mode"
							value="drawer"
							<?php checked( $chat_settings['display_mode'], 'drawer' ); ?>
						/>
						<strong><?php esc_html_e( 'Drawer', 'digital-employees' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Chat slides in from the right edge of the screen.', 'digital-employees' ); ?></span>
					</label>
				</div>
			</div>

			<div id="def-core-drawer-options" style="<?php echo 'drawer' === $chat_settings['display_mode'] ? '' : 'display: none;'; ?>">
				<div class="def-core-field">
					<label for="def_core_chat_drawer_width"><?php esc_html_e( 'Drawer Width (px)', 'digital-employees' ); ?></label>
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
						<?php esc_html_e( 'Width of the chat drawer in pixels. Range: 300–600px.', 'digital-employees' ); ?>
					</p>
				</div>
			</div>
		</div>

		<div class="def-core-card">
			<h2><?php esc_html_e( 'Chat Button Appearance', 'digital-employees' ); ?></h2>

			<div class="def-core-field">
				<label><?php esc_html_e( 'Button Position', 'digital-employees' ); ?></label>
				<div class="def-core-radio-group">
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_button_position"
							data-setting="def_core_chat_button_position"
							value="right"
							<?php checked( $button_settings['position'], 'right' ); ?>
						/>
						<strong><?php esc_html_e( 'Right', 'digital-employees' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Bottom-right corner of the page.', 'digital-employees' ); ?></span>
					</label>
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_button_position"
							data-setting="def_core_chat_button_position"
							value="left"
							<?php checked( $button_settings['position'], 'left' ); ?>
						/>
						<strong><?php esc_html_e( 'Left', 'digital-employees' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Bottom-left corner of the page.', 'digital-employees' ); ?></span>
					</label>
				</div>
			</div>

			<div class="def-core-field">
				<label for="def_core_chat_button_color"><?php esc_html_e( 'Button Color', 'digital-employees' ); ?></label>
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
					<?php esc_html_e( 'Background color for the floating chat button.', 'digital-employees' ); ?>
				</p>
			</div>

			<div class="def-core-field">
				<label for="def_core_chat_button_hover_color"><?php esc_html_e( 'Button Hover Color', 'digital-employees' ); ?></label>
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
					<?php esc_html_e( 'Background color when hovering over the chat button. Defaults to the button color if not set.', 'digital-employees' ); ?>
				</p>
			</div>

			<div class="def-core-field">
				<label><?php esc_html_e( 'Button Icon', 'digital-employees' ); ?></label>
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
						<strong><?php esc_html_e( 'Chat bubble', 'digital-employees' ); ?></strong>
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
						<strong><?php esc_html_e( 'Headset', 'digital-employees' ); ?></strong>
					</label>
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_button_icon"
							data-setting="def_core_chat_button_icon"
							value="sparkle"
							<?php checked( $button_settings['icon'], 'sparkle' ); ?>
						/>
						<span class="def-core-icon-preview">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C12.7 6.3 13.2 8.2 15 10C16.8 11.8 18.7 12.3 23 13C18.7 13.7 16.8 14.2 15 16C13.2 17.8 12.7 19.7 12 24C11.3 19.7 10.8 17.8 9 16C7.2 14.2 5.3 13.7 1 13C5.3 12.3 7.2 11.8 9 10C10.8 8.2 11.3 6.3 12 2Z"/><path d="M20 1C20.3 2.6 20.5 3.2 21 3.7C21.5 3.2 21.7 2.6 22 1C21.7 2.6 21.5 3.2 21 3.7C20.5 3.2 20.3 2.6 20 1Z"/><path d="M3 19C3.2 20 3.4 20.4 3.7 20.7C4 20.4 4.2 20 4.4 19C4.2 20 4 20.4 3.7 20.7C3.4 20.4 3.2 20 3 19Z"/></svg>
						</span>
						<strong><?php esc_html_e( 'AI sparkle', 'digital-employees' ); ?></strong>
					</label>
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_button_icon"
							data-setting="def_core_chat_button_icon"
							value="custom"
							<?php checked( $button_settings['icon'], 'custom' ); ?>
						/>
						<strong><?php esc_html_e( 'Custom', 'digital-employees' ); ?></strong>
					</label>
				</div>
			</div>

			<div id="def-core-custom-icon-upload" class="def-core-field" style="<?php echo 'custom' === $button_settings['icon'] ? '' : 'display: none;'; ?>">
				<label><?php esc_html_e( 'Custom Icon', 'digital-employees' ); ?></label>
				<div class="def-core-logo-upload">
					<div id="def-core-icon-preview" class="def-core-logo-preview">
						<?php if ( $button_icon_url ) : ?>
							<img src="<?php echo esc_url( $button_icon_url ); ?>" style="max-height: 48px; width: auto;" />
						<?php else : ?>
							<span class="def-core-no-logo"><?php esc_html_e( 'No icon selected', 'digital-employees' ); ?></span>
						<?php endif; ?>
					</div>
					<input type="hidden" id="def_core_chat_button_icon_id" data-setting="def_core_chat_button_icon_id" value="<?php echo esc_attr( $button_settings['icon_id'] ); ?>" />
					<p class="def-core-logo-actions">
						<button type="button" class="button" id="def-core-select-icon">
							<?php esc_html_e( 'Select Icon', 'digital-employees' ); ?>
						</button>
						<button type="button" class="button" id="def-core-remove-icon" style="<?php echo $button_settings['icon_id'] ? '' : 'display: none;'; ?>">
							<?php esc_html_e( 'Remove Icon', 'digital-employees' ); ?>
						</button>
					</p>
					<p class="description">
						<?php esc_html_e( 'Upload a custom icon for the chat button. Recommended: 48×48px PNG or SVG.', 'digital-employees' ); ?>
					</p>
				</div>
			</div>

			<div class="def-core-field">
				<label><?php esc_html_e( 'Button Label', 'digital-employees' ); ?></label>
				<div class="def-core-radio-group">
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_button_label"
							data-setting="def_core_chat_button_label"
							value="Chat"
							<?php checked( $button_settings['label'], 'Chat' ); ?>
						/>
						<strong><?php esc_html_e( 'Chat', 'digital-employees' ); ?></strong>
					</label>
					<label class="def-core-radio-label">
						<input
							type="radio"
							name="def_core_chat_button_label"
							data-setting="def_core_chat_button_label"
							value="AI"
							<?php checked( $button_settings['label'], 'AI' ); ?>
						/>
						<strong><?php esc_html_e( 'AI', 'digital-employees' ); ?></strong>
					</label>
				</div>
				<p class="description">
					<?php esc_html_e( 'Text label shown on the floating chat button.', 'digital-employees' ); ?>
				</p>
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
					<?php esc_html_e( 'Show floating chat button on frontend', 'digital-employees' ); ?>
				</label>
			</div>

			<div id="def-core-floating-warning" class="def-core-notice def-core-notice-warning" style="<?php echo $button_settings['show_floating'] ? 'display: none;' : ''; ?>">
				<p>
					<?php esc_html_e( 'The floating chat button is hidden. Make sure you\'ve placed the [def_chat_button] shortcode or theme hook, otherwise visitors won\'t be able to open the chat.', 'digital-employees' ); ?>
				</p>
			</div>
		</div>

		<div class="def-core-card">
			<h2><?php esc_html_e( 'AI Disclosure Notice', 'digital-employees' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Show a brief notice in the Customer Chat informing visitors that responses are AI-generated. Recommended for transparency and required by some jurisdictions.', 'digital-employees' ); ?>
			</p>

			<div class="def-core-field def-core-checkbox-field">
				<label>
					<input
						type="checkbox"
						id="def_core_chat_ai_notice"
						data-setting="def_core_chat_ai_notice"
						value="1"
						<?php checked( $chat_settings['ai_notice'] ); ?>
					/>
					<?php esc_html_e( 'Show AI disclosure notice in Customer Chat', 'digital-employees' ); ?>
				</label>
			</div>

			<div class="def-core-field">
				<label for="def_core_chat_privacy_url"><?php esc_html_e( 'Privacy Policy URL', 'digital-employees' ); ?></label>
				<input
					type="url"
					id="def_core_chat_privacy_url"
					data-setting="def_core_chat_privacy_url"
					value="<?php echo esc_url( $chat_settings['privacy_url'] ); ?>"
					class="regular-text"
					placeholder="https://example.com/privacy-policy"
				/>
				<p class="description">
					<?php esc_html_e( 'Link to your privacy policy. If set, a "Privacy Policy" link is shown alongside the AI notice.', 'digital-employees' ); ?>
				</p>
			</div>

			<div id="def-core-ai-notice-preview" class="def-core-notice def-core-notice-info" style="<?php echo $chat_settings['ai_notice'] ? '' : 'display: none;'; ?>">
				<p>
					<strong><?php esc_html_e( 'Preview:', 'digital-employees' ); ?></strong>
					<?php esc_html_e( 'Responses are generated by AI and may not always be accurate.', 'digital-employees' ); ?>
					<?php if ( $chat_settings['privacy_url'] ) : ?>
						<a href="#"><?php esc_html_e( 'Privacy Policy', 'digital-employees' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
		</div>

		<div class="def-core-save-area">
			<button type="button" class="button button-primary def-core-save-btn" data-tab="chat-settings">
				<?php esc_html_e( 'Save Changes', 'digital-employees' ); ?>
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
			<h2><?php esc_html_e( 'Escalation Email Settings', 'digital-employees' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'When a user accepts an AI escalation offer, the request is emailed to the address configured for each channel. If no email is set, the WordPress admin email is used.', 'digital-employees' ); ?>
			</p>

			<?php
			$channels_info = array(
				'customer'        => array(
					'label' => __( 'Customer Chat', 'digital-employees' ),
					'help'  => __( 'Receives escalation emails from anonymous or logged-in customer chat sessions.', 'digital-employees' ),
				),
				'setup_assistant' => array(
					'label' => __( 'Setup Assistant', 'digital-employees' ),
					'help'  => __( "Enter your DEF Partner's email address here for Setup Assistant human escalation.", 'digital-employees' ),
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
						><?php esc_html_e( 'Test Email', 'digital-employees' ); ?></button>
					</div>
					<p class="description"><?php echo esc_html( $channel_info['help'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="def-core-save-area">
			<button type="button" class="button button-primary def-core-save-btn" data-tab="escalation">
				<?php esc_html_e( 'Save Changes', 'digital-employees' ); ?>
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
			<h2><?php esc_html_e( 'User Access', 'digital-employees' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Staff and Management both grant login access to Staff AI but at different document authority levels — Management users can access documents that Staff users cannot. DEF Admin grants access to this settings page.', 'digital-employees' ); ?>
			</p>

			<table class="def-core-roles-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'digital-employees' ); ?></th>
						<th><?php esc_html_e( 'WordPress Role', 'digital-employees' ); ?></th>
						<th class="def-core-role-col"><?php esc_html_e( 'Staff', 'digital-employees' ); ?></th>
						<th class="def-core-role-col"><?php esc_html_e( 'Management', 'digital-employees' ); ?></th>
						<th class="def-core-role-col"><?php esc_html_e( 'DEF Admin', 'digital-employees' ); ?></th>
						<th class="def-core-role-col"><?php esc_html_e( 'Actions', 'digital-employees' ); ?></th>
					</tr>
				</thead>
				<tbody id="def-core-roles-tbody">
					<?php foreach ( $def_users as $u ) :
						$is_last_admin = $u->has_cap( 'def_admin_access' ) && $def_admin_count <= 1;
						$is_locked     = $is_last_admin;
						?>
						<tr data-user-id="<?php echo esc_attr( $u->ID ); ?>">
							<td>
								<?php
								echo get_avatar( $u->ID, 24, '', '', array( 'class' => 'def-core-user-avatar' ) );
								$first = get_user_meta( $u->ID, 'first_name', true );
								$last  = get_user_meta( $u->ID, 'last_name', true );
								$full  = trim( $first . ' ' . $last );
								if ( $full ) {
									echo '<strong>' . esc_html( $full ) . '</strong> ';
									echo '<span class="def-core-user-login">(' . esc_html( $u->user_login ) . ')</span>';
								} else {
									echo '<strong>' . esc_html( $u->display_name ) . '</strong>';
								}
								?>
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
									<span class="def-core-locked-label" title="<?php esc_attr_e( 'At least one DEF Admin is required', 'digital-employees' ); ?>"><?php esc_html_e( 'locked', 'digital-employees' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="def-core-role-col">
								<?php if ( ! $is_locked ) : ?>
									<button type="button" class="def-core-remove-user-btn" data-user-id="<?php echo esc_attr( $u->ID ); ?>" title="<?php esc_attr_e( 'Remove all DEF access', 'digital-employees' ); ?>">&times;</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<!-- Add User search -->
			<div class="def-core-add-user-section">
				<label for="def-core-user-search"><?php esc_html_e( 'Add User', 'digital-employees' ); ?></label>
				<div class="def-core-user-search-wrap">
					<input
						type="text"
						id="def-core-user-search"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'Search by email or name...', 'digital-employees' ); ?>"
						autocomplete="off"
					/>
					<div id="def-core-user-search-results" class="def-core-search-results" hidden></div>
				</div>
			</div>
		</div>

		<div class="def-core-save-area">
			<button type="button" class="button button-primary def-core-save-roles-btn">
				<?php esc_html_e( 'Save User Roles', 'digital-employees' ); ?>
			</button>
			<span class="spinner"></span>
		</div>
	</div>

	<?php // ─── Connection Tab ─────────────────────────────────────────── ?>
	<div
		id="panel-connection"
		role="tabpanel"
		aria-labelledby="tab-connection"
		class="def-core-panel"
		tabindex="0"
		hidden
	>
		<div class="def-core-card">
			<h2><?php esc_html_e( 'Connection Status', 'digital-employees' ); ?></h2>

			<div class="def-core-conn-status-panel <?php echo esc_attr( $status_class ); ?>">
				<div class="def-core-conn-status-row">
					<span class="def-core-conn-dot"></span>
					<span class="def-core-conn-label"><?php echo esc_html( $status_label ); ?></span>
					<?php if ( $is_connected && ! empty( $conn_last_sync ) ) : ?>
						<span class="def-core-conn-sync">
							<?php
							printf(
								/* translators: %s: human-readable time difference */
								esc_html__( 'Last sync: %s ago', 'digital-employees' ),
								esc_html( human_time_diff( strtotime( $conn_last_sync ), current_time( 'timestamp' ) ) )
							);
							?>
						</span>
					<?php endif; ?>
				</div>

				<div class="def-core-conn-actions">
					<button type="button" id="def-core-test-connection" class="button">
						<?php esc_html_e( 'Test Connection', 'digital-employees' ); ?>
					</button>
					<span id="def-core-connection-result" class="def-core-connection-result"></span>
				</div>
			</div>

			<?php if ( ! $is_connected ) : ?>
				<p class="def-core-conn-hint">
					<?php esc_html_e( 'Connection config is managed by the DEF platform. Contact your platform administrator to provision this site.', 'digital-employees' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $is_connected ) : ?>
				<table class="def-core-conn-details">
					<tr>
						<th><?php esc_html_e( 'API URL', 'digital-employees' ); ?></th>
						<td><code><?php echo esc_html( $conn_api_url ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Config Revision', 'digital-employees' ); ?></th>
						<td><?php echo esc_html( $conn_revision ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last Sync', 'digital-employees' ); ?></th>
						<td><?php echo esc_html( $conn_last_sync ); ?> (<?php echo esc_html( human_time_diff( strtotime( $conn_last_sync ), current_time( 'timestamp' ) ) ); ?> ago)</td>
					</tr>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>
