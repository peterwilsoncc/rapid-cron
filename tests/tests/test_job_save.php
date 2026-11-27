<?php
/**
 * Test saving jobs.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Tests;

use PWCC\RapidCron\Job;
use WP_UnitTestCase;

/**
 * Test Job::save() and changing an event's recurrence.
 */
class Test_Job_Save extends WP_UnitTestCase {

	const HOOK = 'rapid_cron_test_hook';

	/**
	 * Get the scheduled job for the test hook.
	 *
	 * @return Job The job.
	 */
	private function get_job() {
		$jobs = Job::get_jobs_by_query( array( 'hook' => self::HOOK ) );
		$this->assertCount( 1, $jobs, 'One test job should be scheduled.' );

		return $jobs[0];
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
	 * Test saving a recurring job without an interval doesn't make it single.
	 */
	public function test_save_does_not_change_recurring_job_to_single() {
		wp_schedule_event( time(), 'hourly', self::HOOK );
		$job = $this->get_job();

		$job->interval = null;
		$job->schedule = false;
		// The stored row doesn't change, so save() reports no rows updated.
		$job->save();

		// The cached job is used by the worker, so check it before the database.
		$cached = Job::get( $job->id );
		$this->assertSame( 'hourly', $cached->schedule, 'The cached job should keep its schedule.' );
		$this->assertEquals( HOUR_IN_SECONDS, $cached->interval, 'The cached job should keep its interval.' );

		$stored = $this->get_fresh_job( $job->id );
		$this->assertSame( 'hourly', $stored->schedule, 'The stored job should keep its schedule.' );
		$this->assertEquals( HOUR_IN_SECONDS, $stored->interval, 'The stored job should keep its interval.' );
	}

	/**
	 * Test a single event becomes recurring when a recurring event is scheduled at the same time.
	 */
	public function test_single_event_changes_to_recurring() {
		$timestamp = time() + HOUR_IN_SECONDS;
		$this->assertTrue( wp_schedule_single_event( $timestamp, self::HOOK ), 'The single event should be scheduled.' );
		$id = $this->get_job()->id;

		$this->assertTrue( wp_schedule_event( $timestamp, 'daily', self::HOOK ), 'The recurring event should be scheduled.' );

		$job = $this->get_job();
		$this->assertSame( $id, $job->id, 'The existing job should be updated.' );

		$this->assertSame( 'daily', Job::get( $id )->schedule, 'The cached job should be recurring.' );

		$stored = $this->get_fresh_job( $id );
		$this->assertSame( 'daily', $stored->schedule, 'The stored job should be recurring.' );
		$this->assertEquals( DAY_IN_SECONDS, $stored->interval, 'The stored job should have the schedule\'s interval.' );
	}
}
