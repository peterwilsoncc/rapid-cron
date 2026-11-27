<?php
/**
 * Rapid Cron
 *
 * @package           RapidCron
 */

namespace PWCC\RapidCron;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

const PLUGIN_VERSION = '1.0.0';

/**
 * Bootstrap the plugin.
 */
function bootstrap() {
	register_cache_groups();
	register_cli_commands();
	Database\bootstrap();
	JobStorage\bootstrap();
}

/**
 * Register the cache groups
 */
function register_cache_groups() {
	wp_cache_add_global_groups( array( 'rapid-cron', 'rapid-cron-jobs' ) );
}

/**
 * Register the WP-CLI command
 */
function register_cli_commands() {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		return;
	}

	require __DIR__ . '/class-command.php';
	\WP_CLI::add_command( 'rapid-cron', __NAMESPACE__ . '\\Command' );
}

/**
 * Get the WP Cron schedule names by interval.
 *
 * This is used as a fallback when Rapid Cron does not have the
 * schedule name stored in the database to make a best guess at
 * the schedule's name.
 *
 * Interval collisions caused by two plugins registering the same
 * interval with different names are unified into a single name.
 *
 * @return array Cron schedule names indexed by interval.
 */
function get_schedules_by_interval() {
	$schedules = array();

	foreach ( wp_get_schedules() as $name => $schedule ) {
		$schedules[ (int) $schedule['interval'] ] = $name;
	}

	return $schedules;
}

/**
 * Helper function to get a schedule name from a specific interval.
 *
 * @param int $interval Cron schedule interval.
 * @return string Cron schedule name.
 */
function get_schedule_by_interval( $interval = null ) {
	if ( empty( $interval ) ) {
		return '__fake_schedule';
	}

	$schedules = get_schedules_by_interval();

	if ( ! empty( $schedules[ (int) $interval ] ) ) {
		return $schedules[ (int) $interval ];
	}

	return '__fake_schedule';
}
