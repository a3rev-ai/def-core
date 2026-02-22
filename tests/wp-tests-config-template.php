<?php
/**
 * WP test library configuration for wp-env.
 *
 * This file is copied into /wordpress-phpunit/wp-tests-config.php
 * inside the tests container during environment setup.
 */
define( 'ABSPATH', '/var/www/html/' );
define( 'DB_NAME', 'tests-wordpress' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'password' );
define( 'DB_HOST', 'tests-mysql' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'localhost' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
