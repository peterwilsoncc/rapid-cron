<?php
/**
 * Test rescheduling events.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Tests;

use PWCC\RapidCron\Job;
use WP_Error;

use function PWCC\RapidCron\Database\get_table_name;

/**
 * Test pre_reschedule_event().
 */
class Test_Reschedule_Event extends TestCase {

	const HOOK = 'rapid_cron_test_hook';

	/**
	 * Insert a job directly, as Job::save() can't store every legacy state.
	 *
	 * @param int|null    $interval Job interval.
	 * @param string|null $schedule Job schedule name.
	 * @return int Job ID.
	 */
	private function insert_job( $interval, $schedule ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			get_table_name( 'jobs' ),
			array(
				'site'     => get_current_blog_id(),
				'hook'     => self::HOOK,
				'args'     => serialize( array() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- matches stored format.
				'start'    => gmdate( 'Y-m-d H:i:s' ),
				'next_run' => gmdate( 'Y-m-d H:i:s' ),
				'interval' => $interval,
				'schedule' => $schedule,
			)
		);
		Job::flush_query_cache();

		return $wpdb->insert_id;
	}

	/**
	 * Get a job's stored schedule name, bypassing the Job class.
	 *
	 * @param int $id Job ID.
	 * @return string|null Stored schedule name.
	 */
	private function get_stored_schedule( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var( $wpdb->prepare( 'SELECT schedule FROM %i WHERE id = %d', Job::get_job_table(), $id ) );
	}

	/**
	 * Get a job from the database, bypassing the job cache.
	 *
	 * @param int $id Job ID.
	 * @return Job The job.
	 */
	private function get_fresh_job( $id ) {
		wp_cache_delete( "job::{$id}", 'rapid-cron-jobs' );
		return Job::get( $id );
	}

	/**
	 * Test jobs with an unregistered interval are not rescheduled.
	 *
	 * Stored jobs can have an interval without a schedule name, which core
	 * events can't. Matching core's handling of removed schedules, the fake
	 * schedule they fall back to can't be rescheduled.
	 *
	 * @group rapid-cron-internals
	 */
	public function test_reschedule_job_with_unregistered_interval() {
		$id  = $this->insert_job( 1234, null );
		$job = $this->get_fresh_job( $id );
		$this->assertSame( '__fake_schedule', $job->schedule, 'The schedule name should fall back to the fake schedule.' );

		$this->assertFalse( wp_reschedule_event( $job->next_run, $job->schedule, self::HOOK ), 'Rescheduling should fail.' );

		$this->assertSame( (int) $job->next_run, (int) $this->get_fresh_job( $id )->next_run, 'The job should not be rescheduled.' );
		$this->assertNull( $this->get_stored_schedule( $id ), 'The fake schedule name should not be stored.' );
	}

	/**
	 * Test registered schedule names are stored when rescheduling.
	 *
	 * Stored jobs can have an interval without a schedule name, which core
	 * events can't.
	 *
	 * @group rapid-cron-internals
	 */
	public function test_reschedule_job_stores_registered_schedule() {
		$id  = $this->insert_job( HOUR_IN_SECONDS, null );
		$job = $this->get_fresh_job( $id );

		$this->assertTrue( wp_reschedule_event( $job->next_run, 'hourly', self::HOOK ), 'Rescheduling should succeed.' );

		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, (int) $this->get_fresh_job( $id )->next_run, 2, 'The job should be rescheduled an hour ahead.' );
		$this->assertSame( 'hourly', $this->get_stored_schedule( $id ), 'The registered schedule name should be stored.' );
	}

	/**
	 * Schedule the test event with a schedule that is then removed.
	 */
	private function schedule_event_with_removed_schedule() {
		$add_schedule = function ( $schedules ) {
			$schedules['rapid_cron_test'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'Rapid Cron test',
			);
			return $schedules;
		};

		add_filter( 'cron_schedules', $add_schedule ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- five minute interval.
		$this->assertTrue( wp_schedule_event( time(), 'rapid_cron_test', self::HOOK ), 'The event should be scheduled.' );
		remove_filter( 'cron_schedules', $add_schedule );
	}

	/**
	 * Test events whose schedule has been removed are not rescheduled.
	 *
	 * @dataProvider data_reschedule_event_with_removed_schedule
	 *
	 * @param bool $wp_error Whether to return a WP_Error on failure.
	 */
	public function test_reschedule_event_with_removed_schedule( $wp_error ) {
		$this->schedule_event_with_removed_schedule();
		$timestamp = wp_next_scheduled( self::HOOK );

		$result = wp_reschedule_event( $timestamp, 'rapid_cron_test', self::HOOK, array(), $wp_error );

		if ( $wp_error ) {
			$this->assertInstanceOf( WP_Error::class, $result, 'A WP_Error should be returned.' );
			$this->assertSame( 'invalid_schedule', $result->get_error_code(), 'The error code is incorrect.' );
		} else {
			$this->assertFalse( $result, 'False should be returned.' );
		}
		$this->assertSame( $timestamp, wp_next_scheduled( self::HOOK ), 'The event should not be rescheduled.' );
		$this->assertSame( 1, $this->count_events( self::HOOK ), 'No event should be added.' );
	}

	/**
	 * Data provider for test_reschedule_event_with_removed_schedule.
	 *
	 * @return array[] Test data.
	 */
	public function data_reschedule_event_with_removed_schedule() {
		return array(
			'return false'    => array( false ),
			'return WP_Error' => array( true ),
		);
	}

	/**
	 * Test events whose schedule has been removed stop once they run.
	 */
	public function test_running_event_with_removed_schedule_stops() {
		$this->schedule_event_with_removed_schedule();

		$this->run_next_event( self::HOOK, '__return_null' );

		$this->assertFalse( wp_next_scheduled( self::HOOK ), 'The event should not be rescheduled.' );
	}

	/**
	 * Test recurring events are rescheduled using their schedule's interval.
	 */
	public function test_reschedule_recurring_event() {
		wp_schedule_event( time(), 'hourly', self::HOOK );
		$timestamp = wp_next_scheduled( self::HOOK );

		$this->assertTrue( wp_reschedule_event( $timestamp, 'hourly', self::HOOK ), 'Rescheduling should succeed.' );

		// Core adds a new event, so remove the old one as wp-cron.php does.
		wp_unschedule_event( $timestamp, self::HOOK );

		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, wp_next_scheduled( self::HOOK ), 2, 'The event should be rescheduled an hour ahead.' );
		$this->assertSame( 'hourly', wp_get_scheduled_event( self::HOOK )->schedule, 'The event should keep its schedule.' );
		$this->assertSame( 1, $this->count_events( self::HOOK ), 'Only the rescheduled event should remain.' );
	}

	/**
	 * Test single events are not rescheduled.
	 *
	 * @dataProvider data_reschedule_single_event
	 *
	 * @param bool $wp_error Whether to return a WP_Error on failure.
	 */
	public function test_reschedule_single_event( $wp_error ) {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::HOOK );
		$timestamp = wp_next_scheduled( self::HOOK );

		$result = wp_reschedule_event( $timestamp, 'not_a_schedule', self::HOOK, array(), $wp_error );

		if ( $wp_error ) {
			$this->assertInstanceOf( WP_Error::class, $result, 'A WP_Error should be returned.' );
			$this->assertSame( 'invalid_schedule', $result->get_error_code(), 'The error code is incorrect.' );
		} else {
			$this->assertFalse( $result, 'False should be returned.' );
		}
		$this->assertSame( $timestamp, wp_next_scheduled( self::HOOK ), 'The event should not be rescheduled.' );
	}

	/**
	 * Data provider for test_reschedule_single_event.
	 *
	 * @return array[] Test data.
	 */
	public function data_reschedule_single_event() {
		return array(
			'return false'    => array( false ),
			'return WP_Error' => array( true ),
		);
	}
}
