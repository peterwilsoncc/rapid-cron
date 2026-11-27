<?php
/**
 * Test unscheduling events.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Tests;

use PWCC\RapidCron\Job;
use WP_Error;

/**
 * Test pre_unschedule_event().
 */
class Test_Unschedule_Event extends TestCase {

	const HOOK = 'rapid_cron_test_hook';

	/**
	 * Schedule the test event.
	 *
	 * @param bool $recurring Whether the event is recurring.
	 */
	private function schedule_event( $recurring ) {
		if ( $recurring ) {
			$this->assertTrue( wp_schedule_event( time(), 'hourly', self::HOOK ), 'The recurring event should be scheduled.' );
		} else {
			$this->assertTrue( wp_schedule_single_event( time(), self::HOOK ), 'The single event should be scheduled.' );
		}
	}

	/**
	 * Test events waiting to run are unscheduled.
	 */
	public function test_unschedule_waiting_event() {
		wp_schedule_event( time(), 'hourly', self::HOOK );

		$this->assertTrue( wp_unschedule_event( wp_next_scheduled( self::HOOK ), self::HOOK ), 'Unscheduling should succeed.' );
		$this->assertFalse( wp_next_scheduled( self::HOOK ), 'The event should not be scheduled.' );
	}

	/**
	 * Test running events that unschedule themselves are not rescheduled.
	 *
	 * @dataProvider data_recurring
	 *
	 * @param bool $recurring Whether the event is recurring.
	 */
	public function test_running_event_unschedules_itself( $recurring ) {
		$this->schedule_event( $recurring );

		$this->run_next_event(
			self::HOOK,
			function () {
				$timestamp = wp_next_scheduled( self::HOOK );
				if ( $timestamp ) {
					wp_unschedule_event( $timestamp, self::HOOK );
				}
			}
		);

		$this->assertFalse( wp_next_scheduled( self::HOOK ), 'The event should not be rescheduled.' );
	}

	/**
	 * Test running jobs that unschedule themselves are deleted.
	 *
	 * Core removes an event before running it, so this has no core equivalent.
	 *
	 * @group rapid-cron-internals
	 *
	 * @dataProvider data_recurring
	 *
	 * @param bool $recurring Whether the job is recurring.
	 */
	public function test_running_job_unscheduling_itself_is_deleted( $recurring ) {
		$this->schedule_event( $recurring );
		$jobs = Job::get_jobs_by_query( array( 'hook' => self::HOOK ) );
		$this->assertTrue( $jobs[0]->lock(), 'The job should lock.' );

		$this->assertTrue( wp_unschedule_event( wp_next_scheduled( self::HOOK ), self::HOOK ), 'Unscheduling should succeed.' );

		wp_cache_delete( "job::{$jobs[0]->id}", 'rapid-cron-jobs' );
		$this->assertNull( Job::get( $jobs[0]->id ), 'The job should be deleted.' );
	}

	/**
	 * Data provider for:
	 * - test_running_event_unschedules_itself
	 * - test_running_job_unscheduling_itself_is_deleted
	 *
	 * @return array[] Test data.
	 */
	public function data_recurring() {
		return array(
			'recurring event' => array( true ),
			'single event'    => array( false ),
		);
	}

	/**
	 * Test failing to save the change is reported.
	 *
	 * @dataProvider data_unschedule_event_save_fails
	 *
	 * @param bool $wp_error Whether to return a WP_Error on failure.
	 */
	public function test_unschedule_event_save_fails( $wp_error ) {
		wp_schedule_event( time(), 'hourly', self::HOOK );
		$timestamp = wp_next_scheduled( self::HOOK );

		$restore = $this->break_cron_storage();
		$result  = wp_unschedule_event( $timestamp, self::HOOK, array(), $wp_error );
		$restore();

		if ( $wp_error ) {
			$this->assertInstanceOf( WP_Error::class, $result, 'A WP_Error should be returned.' );
			$this->assertSame( 'could_not_set', $result->get_error_code(), 'The error code is incorrect.' );
		} else {
			$this->assertFalse( $result, 'False should be returned.' );
		}
		$this->assertSame( $timestamp, wp_next_scheduled( self::HOOK ), 'The event should not be unscheduled.' );
	}

	/**
	 * Data provider for test_unschedule_event_save_fails.
	 *
	 * @return array[] Test data.
	 */
	public function data_unschedule_event_save_fails() {
		return array(
			'return false'    => array( false ),
			'return WP_Error' => array( true ),
		);
	}
}
