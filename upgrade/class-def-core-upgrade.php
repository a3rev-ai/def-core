<?php
/**
 * Upgrade class for the Digital Employee Framework - Core plugin.
 *
 * @package def-core
 * @since 0.2.0
 */

/**
 * Upgrade class for the Digital Employee Framework - Core plugin.
 */
class DEF_Core_Upgrade {

	/**
	 * The plugin path.
	 *
	 * @var string
	 */
	protected $plugin_path = DEF_CORE_PLUGIN_NAME;

	/**
	 * The plugin key.
	 *
	 * @var string
	 */
	protected $plugin_key = DEF_CORE_KEY;

	/**
	 * The plugin version.
	 *
	 * @var string
	 */
	protected $plugin_version = DEF_CORE_VERSION;

	/**
	 * The CloudFront URL.
	 *
	 * @var string
	 */
	protected $cloudfront_url = 'https://db2oxwmn8orjn.cloudfront.net';

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );

		add_action( 'install_plugins_pre_plugin-information', array( $this, 'display_changelog' ) );

		add_filter( 'plugins_api_result', array( $this, 'make_compatibility' ), 11, 3 );

		// Defined this plugin as external so that WordPress don't call to the WordPress.org Plugin Install API.
		add_filter( 'plugins_api', array( $this, 'is_external' ), 11, 3 );
	}

	/**
	 * Check for updates.
	 *
	 * @param object $update_plugins_option The update plugins option.
	 * @return object The update plugins option.
	 */
	public function check_update( $update_plugins_option ) {

		// Don't use this feature if a3rev Dashboard plugin is activated on this customer's website and logged in as a3 member.
		if ( function_exists( 'is_a3_club_membership' ) && is_a3_club_membership() ) {
			return $update_plugins_option;
		}

		$request = wp_remote_get( $this->cloudfront_url . '/' . $this->plugin_key . '/info.json' );

		if ( is_wp_error( $request ) ) {
			return $update_plugins_option;
		}

		$body = wp_remote_retrieve_body( $request );

		$remote_data = json_decode( $body );

		if ( empty( $remote_data ) ) {
			return $update_plugins_option;
		}

		$remote_data = (array) $remote_data;

		if ( ! isset( $remote_data['version'] ) || empty( $remote_data['version'] ) ) {
			return $update_plugins_option;
		}

		if ( version_compare( $this->plugin_version, $remote_data['version'], '<' ) ) {
			global $wp_version;

			if ( empty( $update_plugins_option->response[ $this->plugin_path ] ) ) {
				$update_plugins_option->response[ $this->plugin_path ] = new stdClass();
			}

			$update_plugins_option->response[ $this->plugin_path ]->url            = 'https://www.a3rev.com';
			$update_plugins_option->response[ $this->plugin_path ]->slug           = $this->plugin_key;
			$update_plugins_option->response[ $this->plugin_path ]->plugin         = $this->plugin_path;
			$update_plugins_option->response[ $this->plugin_path ]->package        = '';
			$update_plugins_option->response[ $this->plugin_path ]->new_version    = $remote_data['version'];
			$update_plugins_option->response[ $this->plugin_path ]->upgrade_notice = '';
			$update_plugins_option->response[ $this->plugin_path ]->tested         = $wp_version;
			$update_plugins_option->response[ $this->plugin_path ]->id             = '0';
		}

		return $update_plugins_option;
	}

	/**
	 * Displays current version details on Plugin's page.
	 *
	 * @return void
	 */
	public function display_changelog() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['plugin'] ) && $this->plugin_key !== $_REQUEST['plugin'] ) {
			return;
		}

		$request = wp_remote_get( $this->cloudfront_url . '/' . $this->plugin_key . '/changelog.txt' );

		if ( is_wp_error( $request ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $request );

		if ( ! empty( $body ) ) {
			echo wp_kses_post( wpautop( $body ) );
			exit;
		}
	}

	/**
	 * Make the plugin compatible.
	 *
	 * @param object $info The info object.
	 * @param string $action The action.
	 * @param object $args The arguments.
	 * @return object The info object.
	 */
	public function make_compatibility( $info, $action, $args ) {
		global $wp_version;
		$cur_wp_version = preg_replace( '/-.*$/', '', $wp_version );
		if ( 'plugin_information' === $action ) {
			if ( version_compare( $wp_version, '3.7', '<=' ) ) {
				if ( is_object( $args ) && isset( $args->slug ) && $this->plugin_key === $args->slug ) {
					$info->tested = $wp_version;
				}
			} elseif ( version_compare( $wp_version, '3.7', '>' ) && is_array( $args ) && isset( $args['body']['request'] ) ) {
				$request = maybe_unserialize( $args['body']['request'] );
				if ( isset( $request->slug ) && $this->plugin_key === $request->slug ) {
					$info->tested = $wp_version;
				}
			}
		}
		return $info;
	}

	/**
	 * Check if the plugin is external.
	 *
	 * @param object $external The external object.
	 * @param string $action The action.
	 * @param object $args The arguments.
	 * @return object The external object.
	 */
	public function is_external( $external, $action, $args ) {
		if ( 'plugin_information' === $action ) {
			if ( is_object( $args ) && isset( $args->slug ) && $this->plugin_key === $args->slug ) {
				global $wp_version;
				$external = array(
					'tested' => $wp_version,
				);
				$external = (object) $external;
			}
		}
		return $external;
	}
}

new DEF_Core_Upgrade();
