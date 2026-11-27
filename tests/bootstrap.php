<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package RapidCron
 */

$_pwcc_tests_directory = getenv( 'WP_TESTS_DIR' );

if ( ! $_pwcc_tests_directory ) {
	$_pwcc_tests_directory = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_pwcc_tests_phpunit_polyfills_directory = __DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
if ( false !== $_pwcc_tests_phpunit_polyfills_directory ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- test suite constant.
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_pwcc_tests_phpunit_polyfills_directory );
}

if ( ! file_exists( "{$_pwcc_tests_directory}/includes/functions.php" ) ) {
	echo "Could not find {$_pwcc_tests_directory}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_pwcc_tests_directory}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _pwcc_tests_manually_load_plugin() {
	require dirname( __DIR__ ) . '/rapid-cron.php';

	if ( 'core' === getenv( 'RAPID_CRON_TESTS_MODE' ) ) {
		_pwcc_tests_remove_cron_filters();
	}
}

/**
 * Remove Rapid Cron's cron filters to run the tests against core.
 *
 * Enabled by setting the environment variable `RAPID_CRON_TESTS_MODE=core`.
 */
function _pwcc_tests_remove_cron_filters() {
	$namespace = 'PWCC\\RapidCron\\JobStorage\\';
	$filters   = array(
		'pre_update_option_cron'   => 'update_cron_array',
		'pre_option_cron'          => 'get_cron_array',
		'pre_schedule_event'       => 'pre_schedule_event',
		'pre_reschedule_event'     => 'pre_reschedule_event',
		'pre_unschedule_event'     => 'pre_unschedule_event',
		'pre_clear_scheduled_hook' => 'pre_clear_scheduled_hook',
		'pre_unschedule_hook'      => 'pre_unschedule_hook',
		'pre_get_scheduled_event'  => 'pre_get_scheduled_event',
		'pre_get_ready_cron_jobs'  => 'pre_get_ready_cron_jobs',
	);

	foreach ( $filters as $hook => $callback ) {
		if ( ! remove_filter( $hook, $namespace . $callback ) ) {
			echo "Unable to remove the {$hook} filter to run the tests against core." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit( 1 );
		}
	}

	echo 'Running the tests against core, without Rapid Cron\'s cron filters.' . PHP_EOL;
}

tests_add_filter( 'muplugins_loaded', '_pwcc_tests_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_pwcc_tests_directory}/includes/bootstrap.php";

require __DIR__ . '/includes/class-testcase.php';
