<?php
/**
 * Base test case.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Tests;

use PWCC\RapidCron\Job;
use WP_UnitTestCase;

/**
 * Base test case for Rapid Cron.
 *
 * Tests should verify behaviour with core's cron functions where possible so
 * the suite can also run against core for comparison. To do so, set the
 * environment variable `RAPID_CRON_TESTS_MODE=core`, which removes Rapid
 * Cron's cron filters.
 *
 * Tests of Rapid Cron's internals, which core doesn't have, belong to the
 * `rapid-cron-internals` group and are skipped when running against core.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Group for tests of Rapid Cron's internals.
	 */
	const INTERNALS_GROUP = 'rapid-cron-internals';

	/**
	 * Skip tests of Rapid Cron's internals when running against core.
	 */
	public function set_up() {
		parent::set_up();

		if ( self::is_core_mode() && in_array( self::INTERNALS_GROUP, $this->getGroups(), true ) ) {
			$this->markTestSkipped( 'Tests Rapid Cron internals, which core does not have.' );
		}
	}

	/**
	 * Whether the tests are running against core.
	 *
	 * @return bool True when running against core, false when running against Rapid Cron.
	 */
	public static function is_core_mode() {
		return 'core' === getenv( 'RAPID_CRON_TESTS_MODE' );
	}

	/**
	 * Count the scheduled events for a hook, using core's cron array.
	 *
	 * @param string     $hook Hook to count.
	 * @param array|null $args Arguments to match, null for any arguments.
	 * @return int Number of events.
	 */
	protected function count_events( $hook, $args = null ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- matches core's event keys.
		$key   = is_array( $args ) ? md5( serialize( $args ) ) : null;
		$count = 0;

		foreach ( (array) _get_cron_array() as $hooks ) {
			if ( empty( $hooks[ $hook ] ) ) {
				continue;
			}

			if ( null === $key ) {
				$count += count( $hooks[ $hook ] );
			} elseif ( isset( $hooks[ $hook ][ $key ] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Run the next scheduled event for a hook.
	 *
	 * Against core, this follows wp-cron.php: recurring events are rescheduled
	 * and the event is unscheduled before the hook runs. Against Rapid Cron,
	 * this follows the worker: the job is locked, the hook runs and the job is
	 * finished.
	 *
	 * @param string   $hook     Hook to run.
	 * @param callable $callback Callback to fire on the hook.
	 */
	protected function run_next_event( $hook, $callback ) {
		$event = wp_get_scheduled_event( $hook );
		$this->assertNotFalse( $event, 'An event should be scheduled to run.' );

		add_action( $hook, $callback, 10, 99 );

		if ( self::is_core_mode() ) {
			if ( $event->schedule ) {
				wp_reschedule_event( $event->timestamp, $event->schedule, $hook, $event->args );
			}
			wp_unschedule_event( $event->timestamp, $hook, $event->args );
			do_action_ref_array( $hook, $event->args );
			return;
		}

		$jobs = Job::get_jobs_by_query(
			array(
				'hook'      => $hook,
				'timestamp' => $event->timestamp,
				'args'      => $event->args,
			)
		);
		$this->assertNotEmpty( $jobs, 'The job should exist.' );
		$this->assertTrue( $jobs[0]->lock(), 'The job should lock.' );

		// Load the job as the worker does.
		$job = Job::get( $jobs[0]->id );
		do_action_ref_array( $hook, $job->args );
		$job->finish();
	}

	/**
	 * Make saving cron events fail.
	 *
	 * Against core, the cron option update is ignored. Against Rapid Cron,
	 * writes to the jobs table fail.
	 *
	 * @return callable Function to call to restore saving.
	 */
	protected function break_cron_storage() {
		global $wpdb;

		if ( self::is_core_mode() ) {
			$ignore_update = function ( $value, $old_value ) {
				return $old_value;
			};
			add_filter( 'pre_update_option_cron', $ignore_update, 10, 2 );

			return function () use ( $ignore_update ) {
				remove_filter( 'pre_update_option_cron', $ignore_update, 10 );
			};
		}

		$table       = Job::get_job_table();
		$break_write = function ( $query ) use ( $table ) {
			if ( preg_match( "/^(INSERT INTO|UPDATE|DELETE FROM) `{$table}`/", $query ) ) {
				return "DELETE FROM `{$table}_does_not_exist` WHERE 1 = 0";
			}
			return $query;
		};
		add_filter( 'query', $break_write );
		$suppress = $wpdb->suppress_errors( true );

		return function () use ( $break_write, $suppress, $wpdb ) {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $break_write );
		};
	}
}
