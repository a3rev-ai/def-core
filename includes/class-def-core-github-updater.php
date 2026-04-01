<?php
/**
 * Class DEF_Core_GitHub_Updater
 *
 * Checks GitHub Releases for plugin updates and integrates with the WordPress
 * native update system. Supports the admin "Enable auto-updates" toggle.
 *
 * Usage (in any DEF plugin):
 *   new DEF_Core_GitHub_Updater( [
 *       'file'    => __FILE__,                        // Main plugin file path
 *       'repo'    => 'a3rev-ai/def-core',             // GitHub owner/repo
 *       'slug'    => 'digital-employees',              // Plugin folder name
 *       'asset'   => 'digital-employees.zip',          // Release asset filename
 *       'version' => DEF_CORE_VERSION,                 // Current installed version
 *   ] );
 *
 * @package def-core
 * @since 1.9.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub-based plugin updater for the WordPress update system.
 */
final class DEF_Core_GitHub_Updater {

	/**
	 * GitHub owner/repo (e.g. "a3rev-ai/def-core").
	 *
	 * @var string
	 */
	private string $repo;

	/**
	 * Plugin basename (e.g. "def-core/def-core.php").
	 *
	 * @var string
	 */
	private string $basename;

	/**
	 * Plugin slug / folder name (e.g. "digital-employees").
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Release asset filename to download (e.g. "digital-employees.zip").
	 *
	 * @var string
	 */
	private string $asset;

	/**
	 * Currently installed version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Transient key for caching the GitHub API response.
	 *
	 * @var string
	 */
	private string $cache_key;

	/**
	 * Constructor.
	 *
	 * @param array $config {
	 *     @type string $file    Main plugin file (__FILE__).
	 *     @type string $repo    GitHub owner/repo.
	 *     @type string $slug    Plugin folder name (used in zip).
	 *     @type string $asset   Release asset filename.
	 *     @type string $version Current installed version.
	 * }
	 */
	public function __construct( array $config ) {
		$this->repo      = $config['repo'];
		$this->basename  = plugin_basename( $config['file'] );
		$this->slug      = $config['slug'];
		$this->asset     = $config['asset'];
		$this->version   = $config['version'];
		$this->cache_key = 'def_gh_update_' . sanitize_key( $this->slug );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );
	}

	/**
	 * Check GitHub for a newer release and inject into the WordPress update transient.
	 *
	 * @param object $transient The update_plugins transient.
	 * @return object Modified transient.
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->get_remote_info();
		if ( ! $remote ) {
			return $transient;
		}

		if ( version_compare( $this->version, $remote['version'], '<' ) ) {
			$transient->response[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $remote['version'],
				'url'         => 'https://github.com/' . $this->repo,
				'package'     => $remote['download_url'],
				'tested'      => $remote['tested'] ?? '',
				'requires'    => $remote['requires'] ?? '6.2',
			);
		} else {
			// Tell WordPress there's no update (prevents wp.org lookup for non-wp.org plugins).
			$transient->no_update[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $this->version,
				'url'         => 'https://github.com/' . $this->repo,
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin information for the WordPress "View details" modal.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The API action.
	 * @param object             $args   The query args.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$remote = $this->get_remote_info();
		if ( ! $remote ) {
			return $result;
		}

		return (object) array(
			'name'          => $remote['name'],
			'slug'          => $this->slug,
			'version'       => $remote['version'],
			'author'        => '<a href="https://a3rev.com/">a3rev</a>',
			'homepage'      => 'https://github.com/' . $this->repo,
			'requires'      => $remote['requires'] ?? '6.2',
			'tested'        => $remote['tested'] ?? '',
			'downloaded'    => 0,
			'last_updated'  => $remote['published'] ?? '',
			'sections'      => array(
				'description' => $remote['description'] ?? '',
				'changelog'   => $remote['changelog'] ?? '',
			),
			'download_link' => $remote['download_url'],
			'banners'       => array(),
		);
	}

	/**
	 * After install, rename the extracted folder to match the plugin slug.
	 *
	 * GitHub zips extract to "repo-name-main/" or "repo-name-v1.0.0/" — WordPress
	 * expects the folder to match the plugin slug.
	 *
	 * @param bool  $response   Install response.
	 * @param array $hook_extra Extra args (plugin basename, type).
	 * @param array $result     Install result (destination, etc.).
	 * @return bool|WP_Error
	 */
	public function post_install( $response, $hook_extra, $result ) {
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $response;
		}

		global $wp_filesystem;

		$install_dir = $result['destination'];
		$proper_dir  = WP_PLUGIN_DIR . '/' . dirname( $this->basename );

		// If already in the right place, nothing to do.
		if ( $install_dir === $proper_dir ) {
			return $response;
		}

		$wp_filesystem->move( $install_dir, $proper_dir );

		// Re-activate if it was active before the update.
		if ( is_plugin_active( $this->basename ) ) {
			activate_plugin( $this->basename );
		}

		return $response;
	}

	/**
	 * Fetch the latest release info from GitHub (cached for 12 hours).
	 *
	 * @return array|null {
	 *     @type string $version      Tag name without 'v' prefix.
	 *     @type string $download_url Direct URL to the zip asset.
	 *     @type string $name         Release title / plugin name.
	 *     @type string $description  Release body (markdown).
	 *     @type string $changelog    Release body formatted as changelog.
	 *     @type string $published    Publication date.
	 *     @type string $requires     Minimum WP version.
	 *     @type string $tested       Tested up to WP version.
	 * }
	 */
	private function get_remote_info(): ?array {
		$cached = get_transient( $this->cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/vnd.github.v3+json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure for 1 hour to avoid hammering the API.
			set_transient( $this->cache_key, null, HOUR_IN_SECONDS );
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $release['tag_name'] ) ) {
			return null;
		}

		// Find the zip asset.
		$download_url = '';
		if ( ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( $asset['name'] === $this->asset ) {
					$download_url = $asset['browser_download_url'];
					break;
				}
			}
		}

		if ( empty( $download_url ) ) {
			return null;
		}

		// Parse "Tested up to" and "Requires at least" from readme.txt via GitHub API.
		$requires = '6.2';
		$tested   = '';
		$readme   = wp_remote_get(
			'https://raw.githubusercontent.com/' . $this->repo . '/' . $release['tag_name'] . '/readme.txt',
			array( 'timeout' => 5 )
		);
		if ( ! is_wp_error( $readme ) && 200 === wp_remote_retrieve_response_code( $readme ) ) {
			$readme_body = wp_remote_retrieve_body( $readme );
			if ( preg_match( '/Tested up to:\s*(\S+)/i', $readme_body, $m ) ) {
				$tested = $m[1];
			}
			if ( preg_match( '/Requires at least:\s*(\S+)/i', $readme_body, $m ) ) {
				$requires = $m[1];
			}
		}

		$info = array(
			'version'      => ltrim( $release['tag_name'], 'v' ),
			'download_url' => $download_url,
			'name'         => $release['name'] ?? $this->slug,
			'description'  => $release['body'] ?? '',
			'changelog'    => '<pre>' . esc_html( $release['body'] ?? '' ) . '</pre>',
			'published'    => $release['published_at'] ?? '',
			'requires'     => $requires,
			'tested'       => $tested,
		);

		// Cache for 12 hours.
		set_transient( $this->cache_key, $info, 12 * HOUR_IN_SECONDS );

		return $info;
	}
}
