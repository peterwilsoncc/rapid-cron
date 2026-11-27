<?php
/**
 * Test clearing scheduled hooks.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Tests;

use PWCC\RapidCron\Job;
use WP_UnitTestCase;

/**
 * Test pre_clear_scheduled_hook() and pre_unschedule_hook().
 */
class Test_Clear_Scheduled_Hook extends WP_UnitTestCase {

	const HOOK = 'rapid_cron_test_hook';

	/**
	 * More events than the 100 the clear used to be limited to.
	 */
	const EVENT_COUNT = 105;

	/**
	 * Get all waiting and running jobs for the test hook.
	 *
	 * @return Job[] Jobs for the test hook.
	 */
	private function get_hook_jobs() {
		return Job::get_jobs_by_query(
			array(
				'hook'  => self::HOOK,
				'args'  => null,
				'limit' => 0,
			)
		);
	}

	/**
	 * Test clearing a hook with arguments removes every matching event.
	 */
	public function test_clear_scheduled_hook_clears_all_events() {
		// Space the events out so they aren't duplicates.
		for ( $i = 0; $i < self::EVENT_COUNT; $i++ ) {
			$this->assertTrue( wp_schedule_single_event( time() + $i * HOUR_IN_SECONDS, self::HOOK, array( 'match' ) ), "Event {$i} should be scheduled." );
		}
		wp_schedule_single_event( time(), self::HOOK, array( 'other' ) );

		// Prime the job cache.
		$jobs = $this->get_hook_jobs();
		$ids  = wp_list_pluck( $jobs, 'id' );
		foreach ( $ids as $id ) {
			Job::get( $id );
		}

		$this->assertSame( self::EVENT_COUNT, wp_clear_scheduled_hook( self::HOOK, array( 'match' ) ), 'Every matching event should be cleared.' );
		$this->assertFalse( wp_next_scheduled( self::HOOK, array( 'match' ) ), 'No matching events should remain.' );
		$this->assertNotFalse( wp_next_scheduled( self::HOOK, array( 'other' ) ), 'Events with other arguments should remain.' );

		$remaining = 0;
		foreach ( $ids as $id ) {
			if ( Job::get( $id ) ) {
				++$remaining;
			}
		}
		$this->assertSame( 1, $remaining, 'Only the job with other arguments should remain in the cache.' );
	}

	/**
	 * Test unscheduling a hook removes every event, whatever the arguments.
	 */
	public function test_unschedule_hook_clears_all_events() {
		for ( $i = 0; $i < self::EVENT_COUNT; $i++ ) {
			$this->assertTrue( wp_schedule_single_event( time(), self::HOOK, array( $i ) ), "Event {$i} should be scheduled." );
		}
		wp_schedule_event( time(), 'hourly', self::HOOK );
		wp_schedule_single_event( time(), 'rapid_cron_other_hook' );

		$this->assertSame( self::EVENT_COUNT + 1, wp_unschedule_hook( self::HOOK ), 'Every event for the hook should be cleared.' );
		$this->assertEmpty( $this->get_hook_jobs(), 'No events for the hook should remain.' );
		$this->assertNotFalse( wp_next_scheduled( 'rapid_cron_other_hook' ), 'Events for other hooks should remain.' );
	}

	/**
	 * Test running jobs are cleared, but completed and failed jobs are kept.
	 */
	public function test_clear_scheduled_hook_statuses() {
		wp_schedule_single_event( time(), self::HOOK, array( 'running' ) );
		wp_schedule_single_event( time(), self::HOOK, array( 'completed' ) );
		wp_schedule_single_event( time(), self::HOOK, array( 'failed' ) );

		$jobs = array();
		foreach ( $this->get_hook_jobs() as $job ) {
			$jobs[ $job->args[0] ] = $job;
		}
		$this->assertTrue( $jobs['running']->lock(), 'The running job should lock.' );
		$jobs['completed']->complete();
		$jobs['failed']->fail();

		$this->assertSame( 1, wp_unschedule_hook( self::HOOK ), 'Only the running job should be cleared.' );
		$this->assertNull( Job::get( $jobs['running']->id ), 'The running job should be deleted.' );
		$this->assertInstanceOf( Job::class, Job::get( $jobs['completed']->id ), 'The completed job should be kept.' );
		$this->assertInstanceOf( Job::class, Job::get( $jobs['failed']->id ), 'The failed job should be kept.' );
	}

	/**
	 * Test clearing a hook with no events.
	 */
	public function test_clear_scheduled_hook_without_events() {
		$this->assertSame( 0, wp_clear_scheduled_hook( self::HOOK ), 'Clearing a hook without events should return 0.' );
		$this->assertSame( 0, wp_unschedule_hook( self::HOOK ), 'Unscheduling a hook without events should return 0.' );
	}
}
