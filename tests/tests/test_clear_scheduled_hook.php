<?php
/**
 * Test clearing scheduled hooks.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Tests;

use PWCC\RapidCron\Job;

/**
 * Test pre_clear_scheduled_hook() and pre_unschedule_hook().
 */
class Test_Clear_Scheduled_Hook extends TestCase {

	const HOOK = 'rapid_cron_test_hook';

	/**
	 * More events than the 100 the clear used to be limited to.
	 */
	const EVENT_COUNT = 105;

	/**
	 * Schedule more than 100 single events with the same arguments.
	 *
	 * The events are spaced out so they aren't duplicates.
	 *
	 * @param array $args Event arguments.
	 */
	private function schedule_matching_events( $args ) {
		for ( $i = 0; $i < self::EVENT_COUNT; $i++ ) {
			$this->assertTrue( wp_schedule_single_event( time() + $i * HOUR_IN_SECONDS, self::HOOK, $args ), "Event {$i} should be scheduled." );
		}
	}

	/**
	 * Test clearing a hook with arguments removes every matching event.
	 */
	public function test_clear_scheduled_hook_clears_all_events() {
		$this->schedule_matching_events( array( 'match' ) );
		wp_schedule_single_event( time(), self::HOOK, array( 'other' ) );

		$this->assertSame( self::EVENT_COUNT, wp_clear_scheduled_hook( self::HOOK, array( 'match' ) ), 'Every matching event should be cleared.' );
		$this->assertSame( 0, $this->count_events( self::HOOK, array( 'match' ) ), 'No matching events should remain.' );
		$this->assertSame( 1, $this->count_events( self::HOOK, array( 'other' ) ), 'Events with other arguments should remain.' );
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
		$this->assertSame( 0, $this->count_events( self::HOOK ), 'No events for the hook should remain.' );
		$this->assertSame( 1, $this->count_events( 'rapid_cron_other_hook' ), 'Events for other hooks should remain.' );
	}

	/**
	 * Test clearing a hook with no events.
	 */
	public function test_clear_scheduled_hook_without_events() {
		$this->assertSame( 0, wp_clear_scheduled_hook( self::HOOK ), 'Clearing a hook without events should return 0.' );
		$this->assertSame( 0, wp_unschedule_hook( self::HOOK ), 'Unscheduling a hook without events should return 0.' );
	}

	/**
	 * Test clearing a hook clears the cached jobs.
	 *
	 * @group rapid-cron-internals
	 */
	public function test_clear_scheduled_hook_clears_job_cache() {
		$this->schedule_matching_events( array( 'match' ) );
		wp_schedule_single_event( time(), self::HOOK, array( 'other' ) );

		// Prime the job cache.
		$ids = wp_list_pluck(
			Job::get_jobs_by_query(
				array(
					'hook'  => self::HOOK,
					'args'  => null,
					'limit' => 0,
				)
			),
			'id'
		);
		foreach ( $ids as $id ) {
			Job::get( $id );
		}

		wp_clear_scheduled_hook( self::HOOK, array( 'match' ) );

		$this->assertCount( 1, array_filter( array_map( array( Job::class, 'get' ), $ids ) ), 'Only the job with other arguments should remain in the cache.' );
	}

	/**
	 * Test running jobs are cleared, but completed and failed jobs are kept.
	 *
	 * Core has no completed or failed events.
	 *
	 * @group rapid-cron-internals
	 */
	public function test_clear_scheduled_hook_statuses() {
		wp_schedule_single_event( time(), self::HOOK, array( 'running' ) );
		wp_schedule_single_event( time(), self::HOOK, array( 'completed' ) );
		wp_schedule_single_event( time(), self::HOOK, array( 'failed' ) );

		$jobs  = array();
		$query = array(
			'hook'  => self::HOOK,
			'args'  => null,
			'limit' => 0,
		);
		foreach ( Job::get_jobs_by_query( $query ) as $job ) {
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
}
