<?php
/**
 * Class DEF_Core_Blocks
 *
 * Block-safe content edit bridge for the Content Agent (Adapter G — Gutenberg).
 *
 * The Content Agent must NEVER hand the LLM serialized block markup to rewrite —
 * doing so corrupts block delimiters / attribute JSON / inner markup (see the
 * Quick View incident). Instead:
 *   GET  /content/blocks?item_id=        → an editable-text MANIFEST (each editable
 *        node's inner content + a per-node hash; custom/unknown blocks are locked).
 *   POST /content/blocks/{id}/apply      → apply text PATCHES surgically: the block
 *        wrapper + attributes are preserved byte-for-byte, only the inner text (with
 *        a whitelisted inline subset) is replaced, then parse→validate→serialize on
 *        THIS WordPress (its own block versions ⇒ zero version-skew).
 *
 * Theme/builder-agnostic: only Gutenberg content is body-editable here. Classic /
 * Elementor / Divi / unknown return body_editable=false ⇒ DEF does metadata only.
 *
 * Auth mirrors the SEO-meta bridge: HMAC-signed request + X-DEF-User, then
 * wp_set_current_user + a capability check in the handler (read = def_staff_access;
 * write = the user's own edit_post — same governance as the live content write).
 *
 * @package def-core
 * @since 4.3.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_Blocks {

	/**
	 * v1 editable core blocks: each is a single wrapper element whose inner content
	 * (text + the inline whitelist) is editable. Wrapper + attrs are preserved
	 * verbatim. (quote/button deferred — nested/multi-element wrappers.)
	 */
	private const EDITABLE_TEXT_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/list-item' );

	/** Core container blocks we recurse THROUGH to find nested editable text. */
	private const CORE_CONTAINERS = array( 'core/group', 'core/columns', 'core/column', 'core/list' );

	/**
	 * Single-wrapper matcher: `(open-tag)(inner)(close-tag)`. The open tag (incl. all
	 * attributes) and close tag are preserved verbatim; only group 3 is editable.
	 * `s` = dot matches newlines; `i` = case-insensitive tag names.
	 */
	private const WRAPPER_RE = '/^(\s*<(p|h[1-6]|li)\b[^>]*>)(.*)(<\/\2>\s*)$/is';

	/**
	 * Inline tags the LLM may keep/move inside an editable node. Everything else in
	 * a returned patch is stripped by wp_kses before it is spliced in. Note: any
	 * non-whitelisted inline markup already present in a node (e.g. <code>, <sup>)
	 * is also dropped IF that node is edited (the manifest exposes the stripped form
	 * and base_sha is taken over it); untouched nodes are never altered.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function inline_allowed(): array {
		return array(
			'a'      => array( 'href' => true, 'title' => true, 'target' => true, 'rel' => true ),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'br'     => array(),
		);
	}

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	public static function register_rest_routes(): void {
		register_rest_route( 'def-core/v1', '/content/blocks', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_manifest' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );
		register_rest_route( 'def-core/v1', '/content/blocks/(?P<item_id>\d+)/apply', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_apply' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );
	}

	// ---------------------------------------------------------------- auth ----

	/**
	 * HMAC + X-DEF-User existence (mirrors the SEO-meta / site-tools bridges; the
	 * capability check + user context are set in the handlers, not here).
	 *
	 * @return true|\WP_Error
	 */
	public static function permission_check( \WP_REST_Request $request ) {
		$hmac = \A3Rev\DefCore\DEF_Core_HMAC_Auth::verify_request( $request );
		if ( is_wp_error( $hmac ) ) {
			return $hmac;
		}
		$user_id = self::req_user_id( $request );
		if ( $user_id < 1 ) {
			return new \WP_Error( 'rest_not_logged_in', 'User ID required.', array( 'status' => 401 ) );
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! $user->exists() ) {
			return new \WP_Error( 'rest_user_not_found', 'User not found.', array( 'status' => 401 ) );
		}
		return true;
	}

	private static function req_user_id( \WP_REST_Request $request ): int {
		return isset( $_SERVER['HTTP_X_DEF_USER'] )
			? intval( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_USER'] ) ) ) : 0;
	}

	/**
	 * Set the acting user and require a DEF capability. Returns true|\WP_Error.
	 */
	private static function set_user_or_forbid( int $user_id, string $cap ) {
		wp_set_current_user( $user_id );
		if ( ! current_user_can( $cap ) ) {
			return new \WP_Error( 'rest_forbidden', 'Insufficient capability.', array( 'status' => 403 ) );
		}
		return true;
	}

	// -------------------------------------------------- format detection ----

	/**
	 * Which editor/builder produced this body. Only `gutenberg` is body-editable in
	 * v1; everything else ⇒ metadata-only on the DEF side.
	 */
	private static function detect_format( int $post_id, string $content ): string {
		if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true )
			|| '' !== (string) get_post_meta( $post_id, '_elementor_data', true ) ) {
			return 'elementor';
		}
		if ( false !== strpos( $content, '[et_pb_' ) ) {
			return 'divi';
		}
		if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
			return 'gutenberg';
		}
		return '' === trim( $content ) ? 'empty' : 'classic';
	}

	// ------------------------------------------------------- GET manifest ----

	/**
	 * GET /content/blocks?item_id= — the editable-text manifest.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_manifest( \WP_REST_Request $request ) {
		$original = get_current_user_id();
		$gate     = self::set_user_or_forbid( self::req_user_id( $request ), 'def_staff_access' );
		if ( is_wp_error( $gate ) ) {
			wp_set_current_user( $original );
			return $gate;
		}
		$item_id = (int) $request->get_param( 'item_id' );
		$post    = $item_id > 0 ? get_post( $item_id ) : null;
		if ( ! $post ) {
			wp_set_current_user( $original );
			return new \WP_Error( 'not_found', 'Item not found.', array( 'status' => 404 ) );
		}
		// Per-post authorization: the manifest exposes the full body, so require the
		// user's own edit cap (not just the global staff tier) — the agent only ever
		// manifests items it is allowed to edit.
		if ( ! current_user_can( 'edit_post', $item_id ) ) {
			wp_set_current_user( $original );
			return new \WP_Error( 'rest_forbidden', 'Cannot read this item.', array( 'status' => 403 ) );
		}
		$content = (string) $post->post_content;
		$format  = self::detect_format( $item_id, $content );
		if ( 'gutenberg' !== $format ) {
			wp_set_current_user( $original );
			return new \WP_REST_Response( array(
				'format'        => $format,
				'adapter'       => null,
				'body_editable' => false,
				'reason'        => 'no safe body adapter for format: ' . $format,
				'nodes'         => array(),
				'locked'        => array(),
			), 200 );
		}

		$blocks = parse_blocks( $content );
		$nodes  = array();
		$locked = array();
		self::walk( $blocks, '', $nodes, $locked );

		wp_set_current_user( $original );
		return new \WP_REST_Response( array(
			'format'        => 'gutenberg',
			'adapter'       => 'G',
			'body_editable' => true,
			'nodes'         => $nodes,
			'locked'        => $locked,
			'block_count'   => self::count_named( $blocks ),
			'fingerprint'   => self::fingerprint( $blocks ),
		), 200 );
	}

	/**
	 * Walk the parsed tree, collecting editable leaf nodes and locked block paths.
	 * Recurses through core containers only; custom/unknown blocks are locked whole
	 * (no recursion — their subtree is never exposed).
	 *
	 * @param array<int,array> $blocks
	 * @param array<int,array> $nodes  (out)
	 * @param array<int,string> $locked (out)
	 */
	private static function walk( array $blocks, string $prefix, array &$nodes, array &$locked ): void {
		foreach ( $blocks as $i => $b ) {
			$name = $b['blockName'] ?? null;
			if ( null === $name ) {
				continue; // freeform whitespace between blocks — not a real block
			}
			$path = '' === $prefix ? (string) $i : $prefix . '.' . $i;

			if ( in_array( $name, self::EDITABLE_TEXT_BLOCKS, true ) ) {
				// An editable text block must be a LEAF. A core/list-item with a nested
				// sub-list has innerBlocks (and a multi-part innerContent with null
				// markers); editing it as one string would drop the sub-list. Lock it.
				if ( ! empty( $b['innerBlocks'] ) ) {
					$locked[] = $path . ' (' . $name . ' — has nested blocks)';
					continue;
				}
				$inner = self::extract_inner( (string) ( $b['innerHTML'] ?? '' ) );
				if ( null !== $inner ) {
					$nodes[] = array(
						'path'       => $path,
						'block'      => $name,
						'field'      => 'inner_html',
						'inner_html' => $inner,
						'base_sha'   => self::sha( $inner ),
					);
					continue;
				}
				// Couldn't isolate a single wrapper → fail safe: lock it.
				$locked[] = $path . ' (' . $name . ' — unparsed)';
				continue;
			}

			if ( 'core/image' === $name ) {
				$alt       = self::image_alt( $b );
				$nodes[]   = array(
					'path'     => $path,
					'block'    => $name,
					'field'    => 'alt',
					'alt'      => $alt,
					'base_sha' => self::sha( $alt ),
				);
				continue;
			}

			if ( in_array( $name, self::CORE_CONTAINERS, true ) && ! empty( $b['innerBlocks'] ) ) {
				self::walk( $b['innerBlocks'], $path, $nodes, $locked );
				continue;
			}

			// Custom / unknown / non-editable block → locked, no recursion.
			$locked[] = $path . ' (' . $name . ')';
		}
	}

	// --------------------------------------------------------- POST apply ----

	/**
	 * POST /content/blocks/{id}/apply — apply text patches surgically.
	 *
	 * Body: { expected_fingerprint, patches: [{path, inner_html|alt, base_sha}] }
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_apply( \WP_REST_Request $request ) {
		$original = get_current_user_id();
		$item_id  = (int) $request->get_param( 'item_id' );
		$gate     = self::set_user_or_forbid( self::req_user_id( $request ), 'def_staff_access' );
		if ( is_wp_error( $gate ) ) {
			wp_set_current_user( $original );
			return $gate;
		}
		$post = $item_id > 0 ? get_post( $item_id ) : null;
		if ( ! $post ) {
			wp_set_current_user( $original );
			return new \WP_Error( 'not_found', 'Item not found.', array( 'status' => 404 ) );
		}
		// Writes content → require the user's own edit capability (same as SEO write).
		if ( ! current_user_can( 'edit_post', $item_id ) ) {
			wp_set_current_user( $original );
			return new \WP_Error( 'rest_forbidden', 'Cannot edit this item.', array( 'status' => 403 ) );
		}

		$body                 = $request->get_json_params();
		$expected_fingerprint = isset( $body['fingerprint'] ) ? (string) $body['fingerprint'] : '';
		$patches              = isset( $body['patches'] ) && is_array( $body['patches'] ) ? $body['patches'] : array();
		if ( ! $patches ) {
			wp_set_current_user( $original );
			return new \WP_REST_Response( array( 'status' => 'invalid', 'reason' => 'no patches' ), 200 );
		}

		$content = (string) $post->post_content;
		if ( 'gutenberg' !== self::detect_format( $item_id, $content ) ) {
			wp_set_current_user( $original );
			return new \WP_REST_Response( array( 'status' => 'invalid', 'reason' => 'not gutenberg content' ), 200 );
		}
		$blocks            = parse_blocks( $content );
		$source_fingerprint = self::fingerprint( $blocks );

		// Structure drift (whole-doc) — Bug A guard.
		if ( '' === $expected_fingerprint || $expected_fingerprint !== $source_fingerprint ) {
			wp_set_current_user( $original );
			return new \WP_REST_Response( array( 'status' => 'stale', 'reason' => 'structure changed since stage' ), 200 );
		}

		// Apply each patch into the addressed node only.
		$stale_paths = array();
		foreach ( $patches as $p ) {
			$path     = isset( $p['path'] ) ? (string) $p['path'] : '';
			$base_sha = isset( $p['base_sha'] ) ? (string) $p['base_sha'] : '';
			$node     = self::node_at( $blocks, $path );
			if ( null === $node ) {
				wp_set_current_user( $original );
				return new \WP_REST_Response( array( 'status' => 'invalid', 'reason' => 'unknown path: ' . $path ), 200 );
			}
			$name = $node['blockName'] ?? null;

			if ( 'core/image' === $name ) {
				$live_alt = self::image_alt( $node );
				if ( $base_sha !== self::sha( $live_alt ) ) {
					$stale_paths[] = $path;
					continue;
				}
				$new_alt = isset( $p['alt'] ) ? sanitize_text_field( (string) $p['alt'] ) : $live_alt;
				self::set_node( $blocks, $path, self::splice_image_alt( $node, $new_alt ) );
				continue;
			}

			if ( ! in_array( $name, self::EDITABLE_TEXT_BLOCKS, true ) ) {
				wp_set_current_user( $original );
				return new \WP_REST_Response( array( 'status' => 'invalid', 'reason' => 'non-editable path: ' . $path ), 200 );
			}
			$live_inner = self::extract_inner( (string) ( $node['innerHTML'] ?? '' ) );
			if ( null === $live_inner || $base_sha !== self::sha( $live_inner ) ) {
				$stale_paths[] = $path; // per-node text drift — Bug B guard on the body
				continue;
			}
			$clean   = wp_kses( (string) ( $p['inner_html'] ?? '' ), self::inline_allowed() );
			// Link preservation: every href in the source node must survive (gate #5).
			if ( ! self::links_preserved( $live_inner, $clean ) ) {
				wp_set_current_user( $original );
				return new \WP_REST_Response( array( 'status' => 'invalid', 'reason' => 'link lost at path: ' . $path ), 200 );
			}
			$spliced = self::splice_inner( (string) ( $node['innerHTML'] ?? '' ), $clean );
			if ( null === $spliced ) {
				wp_set_current_user( $original );
				return new \WP_REST_Response( array( 'status' => 'invalid', 'reason' => 'splice failed at path: ' . $path ), 200 );
			}
			$patched              = $node;
			$patched['innerHTML'] = $spliced;
			$patched['innerContent'] = array( $spliced );
			self::set_node( $blocks, $path, $patched );
		}

		if ( $stale_paths ) {
			wp_set_current_user( $original );
			return new \WP_REST_Response( array( 'status' => 'stale', 'stale_paths' => array_values( array_unique( $stale_paths ) ) ), 200 );
		}

		$candidate = serialize_blocks( $blocks );

		// Validation gate (fail-closed) — structure must be unchanged except at edits.
		$reparsed = parse_blocks( $candidate );
		if ( self::fingerprint( $reparsed ) !== $source_fingerprint
			|| self::count_named( $reparsed ) !== self::count_named( $blocks ) ) {
			wp_set_current_user( $original );
			return new \WP_REST_Response( array( 'status' => 'invalid', 'reason' => 'structure equivalence failed' ), 200 );
		}

		$update = wp_update_post( array( 'ID' => $item_id, 'post_content' => $candidate ), true );
		wp_set_current_user( $original );
		if ( is_wp_error( $update ) ) {
			return new \WP_REST_Response( array( 'status' => 'apply_failed', 'reason' => $update->get_error_code() ), 200 );
		}
		return new \WP_REST_Response( array(
			'status'             => 'applied',
			'block_count'        => self::count_named( $blocks ),
			'patched_paths'      => array_map( static function ( $p ) { return (string) ( $p['path'] ?? '' ); }, $patches ),
		), 200 );
	}

	// ------------------------------------------------------- tree helpers ----

	/** Resolve a dot-path ("0", "5.1") to its block array (by reference-safe copy). */
	private static function node_at( array $blocks, string $path ) {
		$idx = self::path_indices( $path );
		if ( null === $idx ) {
			return null;
		}
		$cur = $blocks;
		$node = null;
		foreach ( $idx as $depth => $i ) {
			if ( ! isset( $cur[ $i ] ) ) {
				return null;
			}
			$node = $cur[ $i ];
			$cur  = $node['innerBlocks'] ?? array();
		}
		return $node;
	}

	/** Replace the block at a dot-path with $new (writes back through nesting). */
	private static function set_node( array &$blocks, string $path, array $new ): bool {
		$idx = self::path_indices( $path );
		if ( null === $idx ) {
			return false;
		}
		$ref = &$blocks;
		$last = array_pop( $idx );
		foreach ( $idx as $i ) {
			if ( ! isset( $ref[ $i ]['innerBlocks'] ) ) {
				return false;
			}
			$ref = &$ref[ $i ]['innerBlocks'];
		}
		if ( ! isset( $ref[ $last ] ) ) {
			return false;
		}
		$ref[ $last ] = $new;
		return true;
	}

	/** "5.1" → [5,1]; rejects anything but digits/dots. */
	private static function path_indices( string $path ): ?array {
		if ( '' === $path || ! preg_match( '/^\d+(\.\d+)*$/D', $path ) ) {
			return null;
		}
		return array_map( 'intval', explode( '.', $path ) );
	}

	/** Count named blocks (recursively), ignoring freeform whitespace nodes. */
	private static function count_named( array $blocks ): int {
		$n = 0;
		foreach ( $blocks as $b ) {
			if ( null !== ( $b['blockName'] ?? null ) ) {
				$n++;
				if ( ! empty( $b['innerBlocks'] ) ) {
					$n += self::count_named( $b['innerBlocks'] );
				}
			}
		}
		return $n;
	}

	/**
	 * Structural fingerprint: block names + non-editable attrs + nesting + order.
	 * Excludes editable text (block innerHTML) and the image `alt` attr, so our own
	 * text/alt edits don't trip it — only true structure drift does.
	 */
	private static function fingerprint( array $blocks ): string {
		return self::sha( wp_json_encode( self::skeleton( $blocks ) ) );
	}

	private static function skeleton( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $b ) {
			$name = $b['blockName'] ?? null;
			if ( null === $name ) {
				continue;
			}
			$attrs = $b['attrs'] ?? array();
			if ( 'core/image' === $name && is_array( $attrs ) ) {
				unset( $attrs['alt'] ); // alt is editable — keep it out of the structure hash
			}
			$out[] = array(
				'n' => $name,
				'a' => $attrs,
				'c' => empty( $b['innerBlocks'] ) ? array() : self::skeleton( $b['innerBlocks'] ),
			);
		}
		return $out;
	}

	// -------------------------------------------------- inner-html helpers ----

	/** Extract the editable inner content of a single-wrapper block; null if not parseable. */
	private static function extract_inner( string $inner_html ): ?string {
		if ( ! preg_match( self::WRAPPER_RE, $inner_html, $m ) ) {
			return null;
		}
		return wp_kses( $m[3], self::inline_allowed() );
	}

	/** Splice new inner content into a single-wrapper block, preserving wrapper+attrs verbatim. */
	private static function splice_inner( string $inner_html, string $new_inner ): ?string {
		if ( ! preg_match( self::WRAPPER_RE, $inner_html, $m ) ) {
			return null;
		}
		return $m[1] . $new_inner . $m[4];
	}

	/** Image alt — prefer the block attr, fall back to the <img> tag. */
	private static function image_alt( array $block ): string {
		if ( isset( $block['attrs']['alt'] ) && is_string( $block['attrs']['alt'] ) ) {
			return $block['attrs']['alt'];
		}
		if ( preg_match( '/<img\b[^>]*\balt="([^"]*)"/i', (string) ( $block['innerHTML'] ?? '' ), $m ) ) {
			return $m[1];
		}
		return '';
	}

	/** Set the alt on both the block attr and the <img> tag. */
	private static function splice_image_alt( array $block, string $new_alt ): array {
		$block['attrs']        = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$block['attrs']['alt'] = $new_alt;
		$esc                   = esc_attr( $new_alt );
		$html                  = (string) ( $block['innerHTML'] ?? '' );
		if ( preg_match( '/<img\b[^>]*\balt="[^"]*"/i', $html ) ) {
			$html = preg_replace( '/(<img\b[^>]*\balt=")[^"]*(")/i', '${1}' . $esc . '${2}', $html, 1 );
		} else {
			$html = preg_replace( '/<img\b/i', '<img alt="' . $esc . '"', $html, 1 );
		}
		$block['innerHTML']    = $html;
		$block['innerContent'] = array( $html );
		return $block;
	}

	/**
	 * The set of links must be IDENTICAL across the edit: every source href must
	 * survive (no link loss) AND no new href may appear (an LLM/garbled patch must
	 * not be able to inject an off-domain link into live published content). Only
	 * the surrounding text may change.
	 */
	private static function links_preserved( string $source, string $patched ): bool {
		$src = array_values( array_unique( self::hrefs( $source ) ) );
		$pat = array_values( array_unique( self::hrefs( $patched ) ) );
		sort( $src );
		sort( $pat );
		return $src === $pat;
	}

	/** @return array<int,string> */
	private static function hrefs( string $html ): array {
		preg_match_all( '/<a\b[^>]*\bhref="([^"]*)"/i', $html, $m );
		return $m[1];
	}

	private static function sha( string $s ): string {
		return hash( 'sha256', $s );
	}
}
