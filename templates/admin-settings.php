<?php
/**
 * Admin settings page template — 6-tab layout.
 * Phase 7 D-I: Foundation tabbed layout with AJAX save.
 * Connection Config Migration: Connection tab removed, status indicator added.
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
	'branding'        => __( 'Branding', 'def-core' ),
	'chat-settings'   => __( 'Chat Settings', 'def-core' ),
	'escalation'      => __( 'Escalation', 'def-core' ),
	'employees-tools' => __( 'Employees & Tools', 'def-core' ),
	'user-roles'      => __( 'User Roles', 'def-core' ),
	'documentation'   => __( 'Documentation', 'def-core' ),
);

$first_tab = 'branding';
?>
<div class="wrap def-core-wrap">
	<h1><?php esc_html_e( 'Digital Employees', 'def-core' ); ?></h1>

	<!-- Toast container -->
	<div id="def-core-toast-container" class="def-core-toast-container" aria-live="polite"></div>

	<!-- Connection Status Indicator (always visible above tabs) -->
	<div class="def-core-connection-status-bar">
		<?php
		$is_connected = ! empty( $conn_api_url ) && $conn_revision > 0;
		$status_class = $is_connected ? 'connected' : 'disconnected';
		$status_label = $is_connected
			? __( 'Connected', 'def-core' )
			: __( 'Not Connected', 'def-core' );
		?>
		<div class="def-core-conn-status <?php echo esc_attr( $status_class ); ?>">
			<span class="def-core-conn-dot"></span>
			<span class="def-core-conn-label"><?php echo esc_html( $status_label ); ?></span>
			<?php if ( $is_connected && ! empty( $conn_last_sync ) ) : ?>
				<span class="def-core-conn-sync">
					<?php
					printf(
						/* translators: %s: human-readable time difference */
						esc_html__( 'Last sync: %s ago', 'def-core' ),
						esc_html( human_time_diff( strtotime( $conn_last_sync ), time() ) )
					);
					?>
				</span>
			<?php endif; ?>
		</div>
		<div class="def-core-conn-actions">
			<button type="button" id="def-core-test-connection" class="button button-small">
				<?php esc_html_e( 'Test Connection', 'def-core' ); ?>
			</button>
			<span id="def-core-connection-result" class="def-core-connection-result"></span>
		</div>
		<?php if ( ! $is_connected ) : ?>
			<p class="def-core-conn-hint">
				<?php esc_html_e( 'Connection config is managed by the DEFHO platform. Contact your platform administrator to provision this site.', 'def-core' ); ?>
			</p>
		<?php endif; ?>
	</div>

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

		<div class="def-core-section">
			<h3><?php esc_html_e( 'Web App Icon', 'def-core' ); ?></h3>
			<hr />

			<div class="def-core-field">
				<label><?php esc_html_e( 'App Icon', 'def-core' ); ?></label>
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
							<?php esc_html_e( 'Select Icon', 'def-core' ); ?>
						</button>
						<button type="button" class="button" id="def-core-remove-app-icon" style="<?php echo $branding['app_icon_id'] ? '' : 'display: none;'; ?>">
							<?php esc_html_e( 'Remove Icon', 'def-core' ); ?>
						</button>
					</div>
				</div>
				<p class="description">
					<?php esc_html_e( 'Upload a square PNG icon (512×512px recommended) for the Staff AI desktop app. If not set, an icon is auto-generated from your site name.', 'def-core' ); ?>
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
