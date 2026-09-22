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
 * the readme.txt that ships in the plugin; never fetches anything, never caches.
 */
class DEF_Core_Changelog {

	/**
	 * Shortcode: [def_changelog versions="12"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML, or '' when the changelog cannot be read.
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts( array( 'versions' => 12 ), $atts, 'def_changelog' );
		return self::html( self::parse( self::readme() ), max( 1, absint( $atts['versions'] ) ) );
	}

	/**
	 * The HTML for the newest releases: an h3 per version, its entries as a list.
	 *
	 * @param array<int, array{version: string, date: string, entries: string[]}> $releases Newest first.
	 * @param int                                                                 $versions How many to show, at least 1.
	 * @return string HTML, or '' when there is nothing to show.
	 */
	public static function html( array $releases, int $versions ): string {
		$releases = array_slice( $releases, 0, max( 1, $versions ) );
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
		// Byte-safe split: `\R` without the u flag cuts UTF-8 characters that end
		// in 0x85 (the check mark in release 4.5.0), and with it fails on a bad byte.
		foreach ( preg_split( '/\r\n|\r|\n/', $section[1] ) as $line ) {
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
	 * The readme.txt that ships in the plugin. A 150 KB read and parse costs well
	 * under a millisecond, so there is nothing to cache.
	 */
	private static function readme(): string {
		$path = DEF_CORE_PLUGIN_DIR . 'readme.txt';
		if ( ! is_readable( $path ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own bundled file, never remote.
		return (string) file_get_contents( $path );
	}
}
