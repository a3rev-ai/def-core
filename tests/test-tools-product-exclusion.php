<?php
/**
 * wc_get_products_list() must NOT expose products flagged out of DEF ingestion
 * (`_def_exclude_from_ingestion`). This is the live path the chatbot uses to
 * resolve a name → product ID for add-to-cart; an excluded product must not be
 * resolvable here, not just hidden from the Azure search index.
 *
 * The wc_get_products() stub deliberately IGNORES meta_query and returns every
 * product, so this exercises the in-loop is-excluded guard (the guarantee that
 * holds even if a WC version drops meta_query).
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'DEF_CORE_PLUGIN_DIR' ) ) {
	define( 'DEF_CORE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

require_once __DIR__ . '/wp-stubs.php';

global $_prod_test_products, $_prod_test_excluded_ids;
$_prod_test_products     = array();
$_prod_test_excluded_ids = array();

// Minimal WC product double.
class _Def_Test_Product {
	private $id;
	private $name;
	private $type;
	public function __construct( int $id, string $name, string $type = 'simple' ) {
		$this->id   = $id;
		$this->name = $name;
		$this->type = $type;
	}
	public function get_id() { return $this->id; }
	public function get_name() { return $this->name; }
	public function get_type() { return $this->type; }
	public function is_type( $t ) { return $this->type === $t; }
	public function get_price() { return '10.00'; }
}

if ( ! function_exists( 'WC' ) ) {
	function WC() { return new stdClass(); }
}

// Returns ALL products regardless of meta_query — forces the in-loop guard to
// be what actually filters, so the test proves correctness without relying on
// wc_get_products() honouring meta_query.
if ( ! function_exists( 'wc_get_products' ) ) {
	function wc_get_products( $args ) {
		global $_prod_test_products;
		return $_prod_test_products;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = true ) {
		global $_prod_test_excluded_ids;
		if ( '_def_exclude_from_ingestion' === $key ) {
			return in_array( (int) $id, $_prod_test_excluded_ids, true ) ? '1' : '';
		}
		return '';
	}
}

// Run the cache callback inline.
if ( ! class_exists( 'DEF_Core_Cache' ) ) {
	class DEF_Core_Cache {
		public static function get_or_set( string $key, int $user_id, int $ttl, callable $cb ) {
			return $cb();
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function get_data() { return $this->data; }
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-tools.php';

$pass = 0;
$fail = 0;
$assert = static function ( bool $cond, string $msg ) use ( &$pass, &$fail ): void {
	if ( $cond ) { $pass++; echo "  ✓ {$msg}\n"; } else { $fail++; echo "  ✗ {$msg}\n"; }
};

echo "wc_get_products_list() exclusion filter\n";

$_prod_test_products = array(
	new _Def_Test_Product( 1, 'WooCommerce Predictive Search Free' ),
	new _Def_Test_Product( 2, 'WooCommerce Predictive Search Premium' ),
	new _Def_Test_Product( 3, 'Another Plugin' ),
);
$_prod_test_excluded_ids = array( 2 ); // Premium is excluded from DEF.

$resp = DEF_Core_Tools::wc_get_products_list();
$data = $resp->get_data();
$ids  = array_map( static fn( $p ) => $p['id'], $data['products'] );

$assert( ! in_array( 2, $ids, true ), 'excluded product (ID 2) is NOT returned for add-to-cart resolution' );
$assert( $ids === array( 1, 3 ), 'only the included products (IDs 1, 3) are returned, in order' );
$assert( (int) $data['total_products'] === 2, 'total_products reflects the filtered count' );

// All-excluded → empty list (nothing resolvable).
$_prod_test_excluded_ids = array( 1, 2, 3 );
$resp2 = DEF_Core_Tools::wc_get_products_list();
$assert( array() === $resp2->get_data()['products'], 'all-excluded catalogue resolves to an empty product list' );

// None-excluded → full list (no regression to the normal path).
$_prod_test_excluded_ids = array();
$resp3 = DEF_Core_Tools::wc_get_products_list();
$assert( count( $resp3->get_data()['products'] ) === 3, 'no exclusions → all products returned (no regression)' );

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
