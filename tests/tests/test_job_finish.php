<?php
/**
 * Test finishing jobs.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Tests;

use PWCC\RapidCron\Job;
use WP_UnitTestCase;

/**
 * Test Job::finish().
 */
class Test_Job_Finish extends WP_UnitTestCase {

	const HOOK = 'rapid_cron_test_hook';

	/**
	 * Get the scheduled job for the test hook, locked as if it were running.
	 *
	 * @return Job The running job.
	 */
	private function get_running_job() {
		$jobs = Job::get_jobs_by_query( array( 'hook' => self::HOOK ) );
		$this->assertNotEmpty( $jobs, 'The test job should be scheduled.' );

		$job = $jobs[0];
		$this->assertTrue( $job->lock(), 'The test job should lock.' );

		return $this->get_fresh_job( $job->id );
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
	 * Test single jobs are marked completed or failed.
	 *
	 * @dataProvider data_finish_single_job
	 *
	 * @param bool   $failed          Whether the run failed.
	 * @param string $expected_status Expected job status.
	 */
	public function test_finish_single_job( $failed, $expected_status ) {
		wp_schedule_single_event( time(), self::HOOK );
		$job = $this->get_running_job();

		$this->assertTrue( $job->finish( $failed ), 'Finishing the job should succeed.' );
		$this->assertSame( $expected_status, $this->get_fresh_job( $job->id )->status, 'The job status is incorrect.' );
		$this->assertFalse( wp_next_scheduled( self::HOOK ), 'The finished job should not be reported as scheduled.' );
	}

	/**
	 * Data provider for test_finish_single_job.
	 *
	 * @return array[] Test data.
	 */
	public function data_finish_single_job() {
		return array(
			'successful run' => array( false, 'completed' ),
			'failed run'     => array( true, 'failed' ),
		);
	}

	/**
	 * Test recurring jobs are rescheduled whether or not the run failed.
	 *
	 * @dataProvider data_finish_recurring_job
	 *
	 * @param bool $failed Whether the run failed.
	 */
	public function test_finish_recurring_job( $failed ) {
		wp_schedule_event( time(), 'hourly', self::HOOK );
		$job = $this->get_running_job();

		$this->assertTrue( $job->finish( $failed ), 'Finishing the job should succeed.' );

		$job = $this->get_fresh_job( $job->id );
		$this->assertSame( 'waiting', $job->status, 'The job should be waiting.' );
		$this->assertGreaterThan( time(), (int) $job->next_run, 'The job should be scheduled in the future.' );
		$this->assertSame( (int) $job->next_run, wp_next_scheduled( self::HOOK ), 'The rescheduled job should be reported as scheduled.' );
	}

	/**
	 * Data provider for test_finish_recurring_job.
	 *
	 * @return array[] Test data.
	 */
	public function data_finish_recurring_job() {
		return array(
			'successful run' => array( false ),
			'failed run'     => array( true ),
		);
	}

	/**
	 * Test recurring jobs that can't be rescheduled are marked failed.
	 */
	public function test_finish_recurring_job_with_missing_schedule() {
		$add_schedule = function ( $schedules ) {
			$schedules['rapid_cron_test'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'Rapid Cron test',
			);
			return $schedules;
		};

		add_filter( 'cron_schedules', $add_schedule ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- five minute interval.
		wp_schedule_event( time(), 'rapid_cron_test', self::HOOK );
		$job = $this->get_running_job();
		remove_filter( 'cron_schedules', $add_schedule );

		$this->assertTrue( $job->finish(), 'Failing the job should succeed.' );
		$this->assertSame( 'failed', $this->get_fresh_job( $job->id )->status, 'The job should be failed.' );
		$this->assertFalse( wp_next_scheduled( self::HOOK ), 'The failed job should not be reported as scheduled.' );
	}
}
