<?php
/**
 * Test scheduling events.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Tests;

use PWCC\RapidCron\Job;
use WP_Error;
use WP_UnitTestCase;

/**
 * Test pre_schedule_event().
 */
class Test_Schedule_Event extends WP_UnitTestCase {

	const HOOK = 'rapid_cron_test_hook';

	/**
	 * Get all waiting and running jobs for the test hook.
	 *
	 * @return Job[] Jobs for the test hook.
	 */
	private function get_hook_jobs() {
		return Job::get_jobs_by_query(
			array(
				'hook'  => self::HOOK,
				'limit' => 0,
			)
		);
	}

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
		$jobs = $this->get_hook_jobs();
		$this->assertCount( 1, $jobs, 'One job should be scheduled.' );
		$id = $jobs[0]->id;

		$result = wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array(), $wp_error );

		$this->assert_duplicate_event( $result, $wp_error );
		$this->assertCount( 1, $this->get_hook_jobs(), 'No single event should be added.' );

		// The cached job is used by the worker, so check it before the database.
		$job = Job::get( $id );
		$this->assertSame( 'hourly', $job->schedule, 'The cached job should keep its schedule.' );
		$this->assertEquals( HOUR_IN_SECONDS, $job->interval, 'The cached job should keep its interval.' );

		wp_cache_delete( "job::{$id}", 'rapid-cron-jobs' );
		$job = Job::get( $id );
		$this->assertSame( 'hourly', $job->schedule, 'The stored job should keep its schedule.' );
		$this->assertEquals( HOUR_IN_SECONDS, $job->interval, 'The stored job should keep its interval.' );
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
		$this->assertCount( 1, $this->get_hook_jobs(), 'No duplicate event should be added.' );
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
}
