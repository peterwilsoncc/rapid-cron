<?php
/**
 * Log Clean Up.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\CleanUp;

const COMPLETED_CLEANUP_HOOK = 'pwcc.rapid-cron.clean-up.completed';
const FAILED_CLEANUP_HOOK    = 'pwcc.rapid-cron.clean-up.failed';

/**
 * Bootstrap clean up jobs.
 *
 * Runs on the `plugins_loaded` action.
 */
function bootstrap() {
	schedule_db_cleanup();

	add_action( COMPLETED_CLEANUP_HOOK, __NAMESPACE__ . '\\cleanup_completed_jobs' );
	add_action( FAILED_CLEANUP_HOOK, __NAMESPACE__ . '\\cleanup_failed_jobs' );
}
// Bootstrap slowly to wait for Rapid Cron to be loaded.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

/**
 * Schedule clean up for completed jobs in the Database.
 */
function schedule_db_cleanup() {
	if ( ! is_admin() ) {
		return;
	}

	if ( ! wp_next_scheduled( COMPLETED_CLEANUP_HOOK ) ) {
		wp_schedule_event( time(), 'daily', COMPLETED_CLEANUP_HOOK );
	}

	if ( ! wp_next_scheduled( FAILED_CLEANUP_HOOK ) ) {
		wp_schedule_event( time(), 'daily', FAILED_CLEANUP_HOOK );
	}
}

/**
 * Cleanup log of completed jobs older than three days.
 *
 * Runs on the hook defined by the constant COMPLETED_CLEANUP_HOOK.
 */
function cleanup_completed_jobs() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->base_prefix}rapid_cron_jobs
		WHERE status='completed' AND next_run < NOW() - INTERVAL 3 DAY"
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE from {$wpdb->base_prefix}rapid_cron_logs
		 WHERE status='completed'
		   AND timestamp < NOW() - INTERVAL 3 DAY"
	);

	wp_cache_set( 'last_changed', microtime(), 'rapid-cron-jobs' );
}

/**
 * Cleanup log of failed jobs older than three months.
 *
 * Runs on the hook defined by the constant FAILED_CLEANUP_HOOK.
 */
function cleanup_failed_jobs() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->base_prefix}rapid_cron_jobs
		WHERE status='failed' AND next_run < NOW() - INTERVAL 3 MONTH"
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE from {$wpdb->base_prefix}rapid_cron_logs
		 WHERE status='failed'
		   AND timestamp < NOW() - INTERVAL 3 MONTH"
	);

	wp_cache_set( 'last_changed', microtime(), 'rapid-cron-jobs' );
}
