<?php
/**
 * Bootstrap for Zorderz INTEGRATION tests: invariants that need the WordPress test harness
 * (roles, users, user meta, $wpdb). Requires the WordPress PHPUnit test library, installed by
 * bin/install-wp-tests.sh (see the CI integration job).
 *
 * @package Zorderz\Tests
 */

// Load Composer's autoloader so the Yoast PHPUnit Polyfills library (which the WordPress test
// bootstrap requires) is available before we hand off to the WP Core bootstrap. Also point WP
// at the Polyfills explicitly, so it is found whether or not autoloading has already run.
$_zdz_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $_zdz_autoload ) ) {
	require_once $_zdz_autoload;
}
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load only the classes under test (Zorderz is a theme; we do not boot the whole theme here).
 */
function _zorderz_load_classes_for_tests() {
	$inc = dirname( __DIR__ ) . '/zorderz/inc/';
	require_once $inc . 'class-zdz-data-permissions.php';
	require_once $inc . 'class-zdz-kpi-metrics.php';
}
tests_add_filter( 'muplugins_loaded', '_zorderz_load_classes_for_tests' );

require $_tests_dir . '/includes/bootstrap.php';
