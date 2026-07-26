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
