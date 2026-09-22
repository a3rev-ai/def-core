<?php
/**
 * [def_changelog] — the plugin's own dated changelog, rendered from the bundled readme.txt.
 *
 * @package def-core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders what changed and when, so any site with the plugin can publish it
 * without maintaining a page by hand. Reads the `== Changelog ==` section of
 * the readme.txt that ships in the plugin; never fetches anything.
 */
class DEF_Core_Changelog {

	/**
	 * Shortcode: [def_changelog versions="12"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML, or '' when the changelog cannot be read.
	 */
	public static function render( $atts ): string {
		$atts     = shortcode_atts( array( 'versions' => 12 ), $atts, 'def_changelog' );
		$versions = max( 1, absint( $atts['versions'] ) );
		$releases = array_slice( self::releases(), 0, $versions );
		if ( empty( $releases ) ) {
			return '';
		}

		$format = get_option( 'date_format', 'j F Y' );
		$html   = '<div class="def-changelog">';
		foreach ( $releases as $release ) {
			$heading = $release['version'];
			// A calendar date, not an instant: read and written in UTC so the day
			// never shifts with the site's timezone.
			$time = '' !== $release['date'] ? strtotime( $release['date'] . ' UTC' ) : false;
			if ( false !== $time ) {
				$heading .= ' — ' . wp_date( $format, $time, new DateTimeZone( 'UTC' ) );
			}
			$html .= '<h3>' . esc_html( $heading ) . '</h3><ul>';
			foreach ( $release['entries'] as $entry ) {
				$html .= '<li>' . esc_html( $entry ) . '</li>';
			}
			$html .= '</ul>';
		}
		return $html . '</div>';
	}

	/**
	 * Parse the changelog section of a readme.txt into releases, in file order
	 * (newest first, as the file is kept).
	 *
	 * @param string $readme The readme.txt contents.
	 * @return array<int, array{version: string, date: string, entries: string[]}>
	 */
	public static function parse( string $readme ): array {
		if ( ! preg_match( '/^== Changelog ==\s*$(.*?)(?=^== |\z)/msi', $readme, $section ) ) {
			return array();
		}

		$releases = array();
		$current  = null;
		foreach ( preg_split( '/\R/', $section[1] ) as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^= ([0-9][^\s=]*)(?:\s*-\s*(\d{4}-\d{2}-\d{2}))?[^=]*=$/', $line, $heading ) ) {
				if ( null !== $current ) {
					$releases[] = $current;
				}
				$current = array(
					'version' => $heading[1],
					'date'    => $heading[2] ?? '',
					'entries' => array(),
				);
			} elseif ( null !== $current && '' !== $line && '*' === $line[0] ) {
				$current['entries'][] = trim( ltrim( $line, '*' ) );
			}
		}
		if ( null !== $current ) {
			$releases[] = $current;
		}
		return $releases;
	}

	/**
	 * The bundled readme's releases, parsed once per plugin version.
	 *
	 * @return array<int, array{version: string, date: string, entries: string[]}>
	 */
	private static function releases(): array {
		$key    = 'def_core_changelog_' . DEF_CORE_VERSION;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$path   = DEF_CORE_PLUGIN_DIR . 'readme.txt';
		$readme = '';
		if ( is_readable( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own bundled file, never remote.
			$readme = (string) file_get_contents( $path );
		}
		$releases = self::parse( $readme );
		set_transient( $key, $releases, WEEK_IN_SECONDS );
		return $releases;
	}
}
