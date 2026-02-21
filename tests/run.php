<?php
/**
 * Test runner for def-core.
 *
 * Usage: php tests/run.php
 *
 * Runs all test-*.php files and reports aggregated results.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

echo "=== def-core test suite ===\n";
echo "PHP " . PHP_VERSION . "\n\n";

$test_dir = __DIR__;
$test_files = glob( $test_dir . '/test-*.php' );

if ( empty( $test_files ) ) {
	echo "No test files found.\n";
	exit( 1 );
}

$total_pass = 0;
$total_fail = 0;
$results    = array();

foreach ( $test_files as $file ) {
	$name = basename( $file );
	echo "Running $name ...\n";

	// Run each test file in a separate process to avoid state leakage.
	$output = array();
	$code   = 0;
	exec( 'php ' . escapeshellarg( $file ) . ' 2>&1', $output, $code );

	$output_text = implode( "\n", $output );
	echo $output_text . "\n\n";

	// Parse pass/fail from last line.
	$last_line = end( $output );
	if ( preg_match( '/(\d+) passed, (\d+) failed/', $last_line, $m ) ) {
		$total_pass += intval( $m[1] );
		$total_fail += intval( $m[2] );
	}

	$results[ $name ] = array(
		'exit_code' => $code,
		'output'    => $output_text,
	);
}

echo "=== TOTAL: $total_pass passed, $total_fail failed ===\n";
exit( $total_fail > 0 ? 1 : 0 );
