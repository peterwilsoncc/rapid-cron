<?php
/**
 * Test unscheduling events.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Tests;

use PWCC\RapidCron\Job;
use WP_Error;
use WP_UnitTestCase;

/**
 * Test pre_unschedule_event().
 */
class Test_Unschedule_Event extends WP_UnitTestCase {

	const HOOK = 'rapid_cron_test_hook';

	/**
	 * Get the scheduled job for the test hook.
	 *
	 * @return Job The job.
	 */
	private function get_job() {
		$jobs = Job::get_jobs_by_query( array( 'hook' => self::HOOK ) );
		$this->assertNotEmpty( $jobs, 'The test job should be scheduled.' );

		return $jobs[0];
	}

	/**
	 * Get the scheduled job for the test hook, locked as if it were running.
	 *
	 * @return Job The running job.
	 */
	private function get_running_job() {
		$job = $this->get_job();
		$this->assertTrue( $job->lock(), 'The test job should lock.' );

		return $this->get_fresh_job( $job->id );
	}

	/**
	 * Get a job from the database, bypassing the job cache.
	 *
	 * @param int $id Job ID.
	 * @return Job|null The job, or null if it doesn't exist.
	 */
	private function get_fresh_job( $id ) {
		wp_cache_delete( "job::{$id}", 'rapid-cron-jobs' );
		return Job::get( $id );
	}

	/**
	 * Test waiting jobs are unscheduled.
	 */
	public function test_unschedule_waiting_job() {
		wp_schedule_event( time(), 'hourly', self::HOOK );
		$job = $this->get_job();

		$this->assertTrue( wp_unschedule_event( $job->next_run, self::HOOK ), 'Unscheduling should succeed.' );
		$this->assertNull( $this->get_fresh_job( $job->id ), 'The job should be deleted.' );
		$this->assertFalse( wp_next_scheduled( self::HOOK ), 'The job should not be reported as scheduled.' );
	}

	/**
	 * Test running jobs that unschedule themselves are not rescheduled.
	 *
	 * @dataProvider data_unschedule_running_job
	 *
	 * @param bool $recurring Whether the job is recurring.
	 */
	public function test_unschedule_running_job( $recurring ) {
		if ( $recurring ) {
			wp_schedule_event( time(), 'hourly', self::HOOK );
		} else {
			wp_schedule_single_event( time(), self::HOOK );
		}
		$job = $this->get_running_job();

		// Unschedule from within the job, as a hook callback would.
		$this->assertTrue( wp_unschedule_event( wp_next_scheduled( self::HOOK ), self::HOOK ), 'Unscheduling should succeed.' );
		$this->assertNull( $this->get_fresh_job( $job->id ), 'The job should be deleted.' );

		$job->finish();

		$this->assertNull( $this->get_fresh_job( $job->id ), 'The job should not be recreated when it finishes.' );
		$this->assertFalse( wp_next_scheduled( self::HOOK ), 'The job should not be rescheduled.' );
	}

	/**
	 * Data provider for test_unschedule_running_job.
	 *
	 * @return array[] Test data.
	 */
	public function data_unschedule_running_job() {
		return array(
			'recurring job' => array( true ),
			'single job'    => array( false ),
		);
	}

	/**
	 * Test failing to delete a job is reported.
	 *
	 * @dataProvider data_unschedule_event_delete_fails
	 *
	 * @param bool $wp_error Whether to return a WP_Error on failure.
	 */
	public function test_unschedule_event_delete_fails( $wp_error ) {
		global $wpdb;

		wp_schedule_event( time(), 'hourly', self::HOOK );
		$job   = $this->get_job();
		$table = Job::get_job_table();

		// Break the delete query so it fails.
		$break_delete = function ( $query ) use ( $table ) {
			if ( 0 === strpos( $query, "DELETE FROM `{$table}`" ) ) {
				return "DELETE FROM `{$table}_does_not_exist` WHERE 1 = 0";
			}
			return $query;
		};
		add_filter( 'query', $break_delete );
		$suppress = $wpdb->suppress_errors( true );

		$result = wp_unschedule_event( $job->next_run, self::HOOK, array(), $wp_error );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_delete );

		if ( $wp_error ) {
			$this->assertInstanceOf( WP_Error::class, $result, 'A WP_Error should be returned.' );
			$this->assertSame( 'could_not_set', $result->get_error_code(), 'The error code is incorrect.' );
		} else {
			$this->assertFalse( $result, 'False should be returned.' );
		}
		$this->assertInstanceOf( Job::class, $this->get_fresh_job( $job->id ), 'The job should not be deleted.' );
	}

	/**
	 * Data provider for test_unschedule_event_delete_fails.
	 *
	 * @return array[] Test data.
	 */
	public function data_unschedule_event_delete_fails() {
		return array(
			'return false'    => array( false ),
			'return WP_Error' => array( true ),
		);
	}
}
