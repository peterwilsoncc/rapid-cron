<?php
/**
 * Test saving jobs.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Tests;

use PWCC\RapidCron\Job;

/**
 * Test Job::save() and changing an event's recurrence.
 */
class Test_Job_Save extends TestCase {

	const HOOK = 'rapid_cron_test_hook';

	/**
	 * Test a recurring event can't be changed to a single event.
	 */
	public function test_recurring_event_does_not_change_to_single() {
		$timestamp = time() + HOUR_IN_SECONDS;
		$this->assertTrue( wp_schedule_event( $timestamp, 'hourly', self::HOOK ), 'The recurring event should be scheduled.' );

		$this->assertFalse( wp_schedule_single_event( $timestamp, self::HOOK ), 'A single event at the same time should be a duplicate.' );

		$event = wp_get_scheduled_event( self::HOOK, array(), $timestamp );
		$this->assertSame( 'hourly', $event->schedule, 'The event should keep its schedule.' );
		$this->assertEquals( HOUR_IN_SECONDS, $event->interval, 'The event should keep its interval.' );
	}

	/**
	 * Test a single event becomes recurring when a recurring event is scheduled at the same time.
	 */
	public function test_single_event_changes_to_recurring() {
		$timestamp = time() + HOUR_IN_SECONDS;
		$this->assertTrue( wp_schedule_single_event( $timestamp, self::HOOK ), 'The single event should be scheduled.' );

		$this->assertTrue( wp_schedule_event( $timestamp, 'daily', self::HOOK ), 'The recurring event should be scheduled.' );

		$this->assertSame( 1, $this->count_events( self::HOOK ), 'The existing event should be replaced.' );
		$event = wp_get_scheduled_event( self::HOOK, array(), $timestamp );
		$this->assertSame( 'daily', $event->schedule, 'The event should be recurring.' );
		$this->assertEquals( DAY_IN_SECONDS, $event->interval, 'The event should have the schedule\'s interval.' );
	}

	/**
	 * Test saving a recurring job without an interval doesn't make it single.
	 *
	 * Core has no equivalent of saving a job directly.
	 *
	 * @group rapid-cron-internals
	 */
	public function test_save_does_not_change_recurring_job_to_single() {
		wp_schedule_event( time(), 'hourly', self::HOOK );
		$jobs = Job::get_jobs_by_query( array( 'hook' => self::HOOK ) );
		$job  = $jobs[0];

		$job->interval = null;
		$job->schedule = false;
		// The stored row doesn't change, so save() reports no rows updated.
		$job->save();

		// The cached job is used by the worker, so check it before the database.
		$cached = Job::get( $job->id );
		$this->assertSame( 'hourly', $cached->schedule, 'The cached job should keep its schedule.' );
		$this->assertEquals( HOUR_IN_SECONDS, $cached->interval, 'The cached job should keep its interval.' );

		$event = wp_get_scheduled_event( self::HOOK );
		$this->assertSame( 'hourly', $event->schedule, 'The stored job should keep its schedule.' );
		$this->assertEquals( HOUR_IN_SECONDS, $event->interval, 'The stored job should keep its interval.' );
	}

	/**
	 * Test a single job changed to recurring is cached as recurring.
	 *
	 * @group rapid-cron-internals
	 */
	public function test_single_job_changed_to_recurring_is_cached() {
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, self::HOOK );
		$jobs = Job::get_jobs_by_query( array( 'hook' => self::HOOK ) );
		$id   = $jobs[0]->id;

		wp_schedule_event( $timestamp, 'daily', self::HOOK );

		$this->assertSame( 'daily', Job::get( $id )->schedule, 'The cached job should be recurring.' );
	}
}
