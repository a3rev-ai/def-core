<?php
/**
 * Digital Employees - Uninstall
 *
 * Removes all plugin options and transients when the plugin is deleted
 * (not deactivated — only on full uninstall via Plugins > Delete).
 *
 * @package digital-employees
 * @since 2.3.0
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Connection config options.
$options = array(
	'def_core_api_key',
	'def_service_auth_secret',
	'def_core_staff_ai_api_url',
	'def_core_conn_config_revision',
	'def_core_conn_last_sync_at',
	'def_core_conn_previous_service_secret',
	'def_core_conn_previous_api_key',
	'def_core_conn_rotation_expires',
	'def_core_external_jwks_url',
	'def_core_external_issuer',
	'def_core_db_version',
);

// Branding options.
$options[] = 'def_core_logo_id';
$options[] = 'def_core_display_name';
$options[] = 'def_core_logo_show_staff_ai';
$options[] = 'def_core_logo_show_customer_chat';
$options[] = 'def_core_logo_max_height';
$options[] = 'def_core_app_icon_id';

// Chat settings.
$options[] = 'def_core_chat_display_mode';
$options[] = 'def_core_chat_drawer_width';
$options[] = 'def_core_chat_button_position';
$options[] = 'def_core_chat_button_color';
$options[] = 'def_core_chat_button_hover_color';
$options[] = 'def_core_chat_button_icon';
$options[] = 'def_core_chat_button_label';
$options[] = 'def_core_chat_button_icon_id';
$options[] = 'def_core_chat_show_floating';
$options[] = 'def_core_chat_ai_notice';
$options[] = 'def_core_chat_privacy_url';

// Escalation settings.
$options[] = 'def_core_escalation_customer';
$options[] = 'def_core_escalation_setup_assistant';

// Tool and key settings.
$options[] = 'def_core_tools_status';
$options[] = 'def_core_allowed_origins';
$options[] = 'def_core_keys';

foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove transients.
delete_transient( 'def_core_encryption_error' );
delete_transient( 'def_core_connection_test' );

// Remove DEF capabilities from all users.
$capabilities = array( 'def_staff_access', 'def_management_access', 'def_admin_access' );
$users = get_users( array( 'fields' => 'ids' ) );
foreach ( $users as $user_id ) {
	$user = new WP_User( $user_id );
	foreach ( $capabilities as $cap ) {
		$user->remove_cap( $cap );
	}
}
