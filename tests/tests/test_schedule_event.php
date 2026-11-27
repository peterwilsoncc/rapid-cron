<?php
/**
 * Test scheduling events.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Tests;

use PWCC\RapidCron\Job;
use WP_Error;

/**
 * Test pre_schedule_event().
 */
class Test_Schedule_Event extends TestCase {

	const HOOK = 'rapid_cron_test_hook';

	/**
	 * Assert the result of scheduling a duplicate event.
	 *
	 * @param bool|WP_Error $result   Result of scheduling the event.
	 * @param bool          $wp_error Whether a WP_Error was requested.
	 */
	private function assert_duplicate_event( $result, $wp_error ) {
		if ( $wp_error ) {
			$this->assertInstanceOf( WP_Error::class, $result, 'A WP_Error should be returned.' );
			$this->assertSame( 'duplicate_event', $result->get_error_code(), 'The error code is incorrect.' );
		} else {
			$this->assertFalse( $result, 'False should be returned.' );
		}
	}

	/**
	 * Test single events don't replace a recurring event with the same hook and arguments.
	 *
	 * @dataProvider data_wp_error
	 *
	 * @param bool $wp_error Whether to return a WP_Error on failure.
	 */
	public function test_single_event_does_not_replace_recurring_event( $wp_error ) {
		$this->assertTrue( wp_schedule_event( time(), 'hourly', self::HOOK ), 'The recurring event should be scheduled.' );

		$result = wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array(), $wp_error );

		$this->assert_duplicate_event( $result, $wp_error );
		$this->assertSame( 1, $this->count_events( self::HOOK ), 'No single event should be added.' );

		$event = wp_get_scheduled_event( self::HOOK );
		$this->assertSame( 'hourly', $event->schedule, 'The event should keep its schedule.' );
		$this->assertEquals( HOUR_IN_SECONDS, $event->interval, 'The event should keep its interval.' );
	}

	/**
	 * Test single events within ten minutes of a matching single event are duplicates.
	 *
	 * @dataProvider data_wp_error
	 *
	 * @param bool $wp_error Whether to return a WP_Error on failure.
	 */
	public function test_single_event_duplicates_single_event( $wp_error ) {
		$this->assertTrue( wp_schedule_single_event( time(), self::HOOK ), 'The first event should be scheduled.' );

		$result = wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array(), $wp_error );

		$this->assert_duplicate_event( $result, $wp_error );
		$this->assertSame( 1, $this->count_events( self::HOOK ), 'No duplicate event should be added.' );
	}

	/**
	 * Data provider for:
	 * - test_single_event_does_not_replace_recurring_event
	 * - test_single_event_duplicates_single_event
	 *
	 * @return array[] Test data.
	 */
	public function data_wp_error() {
		return array(
			'return false'    => array( false ),
			'return WP_Error' => array( true ),
		);
	}

	/**
	 * Test a duplicate single event doesn't change the cached recurring job.
	 *
	 * The worker loads jobs from the cache, so a cached single job would be
	 * marked completed rather than rescheduled.
	 *
	 * @group rapid-cron-internals
	 */
	public function test_single_event_does_not_change_cached_recurring_job() {
		wp_schedule_event( time(), 'hourly', self::HOOK );
		$jobs = Job::get_jobs_by_query( array( 'hook' => self::HOOK ) );
		$id   = $jobs[0]->id;

		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );

		$job = Job::get( $id );
		$this->assertSame( 'hourly', $job->schedule, 'The cached job should keep its schedule.' );
		$this->assertEquals( HOUR_IN_SECONDS, $job->interval, 'The cached job should keep its interval.' );
	}
}
