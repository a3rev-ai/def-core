<?php
/**
 * PHPUnit bootstrap for def-core WP integration tests.
 *
 * Loads the WP test library from wp-env's test environment,
 * then activates def-core so all hooks fire before tests run.
 *
 * @package def-core
 */

// Composer autoload (for PHPUnit + polyfills).
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

/*
 * wp-env maps the WP test library to /wordpress-phpunit/ inside the container.
 * The WP_TESTS_DIR env var can override this if needed.
 */
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__, 2 ) . '/def-core.php';
	}
);

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
