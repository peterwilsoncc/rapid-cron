<?php
/**
 * Test rescheduling events.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Tests;

use PWCC\RapidCron\Job;
use WP_Error;
use WP_UnitTestCase;

use function PWCC\RapidCron\Database\get_table_name;

/**
 * Test pre_reschedule_event().
 */
class Test_Reschedule_Event extends WP_UnitTestCase {

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
	 * Test jobs with an unregistered interval are rescheduled using it.
	 */
	public function test_reschedule_job_with_unregistered_interval() {
		$id  = $this->insert_job( 1234, null );
		$job = $this->get_fresh_job( $id );
		$this->assertSame( '__fake_schedule', $job->schedule, 'The schedule name should fall back to the fake schedule.' );

		$this->assertTrue( wp_reschedule_event( $job->next_run, $job->schedule, self::HOOK ), 'Rescheduling should succeed.' );

		// The cached job is used by the worker, so it must still be treated as recurring.
		$this->assertSame( '__fake_schedule', Job::get( $id )->schedule, 'The cached job should keep the fake schedule.' );

		$job = $this->get_fresh_job( $id );
		$this->assertSame( '__fake_schedule', $job->schedule, 'The loaded job should fall back to the fake schedule.' );
		$this->assertEqualsWithDelta( time() + 1234, (int) $job->next_run, 2, 'The job should be rescheduled using its stored interval.' );
		$this->assertNull( $this->get_stored_schedule( $id ), 'The fake schedule name should not be stored.' );
	}

	/**
	 * Test registered schedule names are stored when rescheduling.
	 */
	public function test_reschedule_job_stores_registered_schedule() {
		$id  = $this->insert_job( HOUR_IN_SECONDS, null );
		$job = $this->get_fresh_job( $id );

		$this->assertTrue( wp_reschedule_event( $job->next_run, 'hourly', self::HOOK ), 'Rescheduling should succeed.' );

		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, (int) $this->get_fresh_job( $id )->next_run, 2, 'The job should be rescheduled an hour ahead.' );
		$this->assertSame( 'hourly', $this->get_stored_schedule( $id ), 'The registered schedule name should be stored.' );
	}

	/**
	 * Test events without an interval are not rescheduled.
	 *
	 * @dataProvider data_reschedule_event_without_interval
	 *
	 * @param bool $wp_error Whether to return a WP_Error on failure.
	 */
	public function test_reschedule_event_without_interval( $wp_error ) {
		$id  = $this->insert_job( null, null );
		$job = $this->get_fresh_job( $id );

		$event = (object) array(
			'hook'      => self::HOOK,
			'timestamp' => $job->next_run,
			'schedule'  => 'not_a_schedule',
			'args'      => array(),
			'interval'  => 0,
		);

		$result = apply_filters( 'pre_reschedule_event', null, $event, $wp_error );

		if ( $wp_error ) {
			$this->assertInstanceOf( WP_Error::class, $result, 'A WP_Error should be returned.' );
			$this->assertSame( 'invalid_schedule', $result->get_error_code(), 'The error code is incorrect.' );
		} else {
			$this->assertFalse( $result, 'False should be returned.' );
		}
		$this->assertSame( (int) $job->next_run, (int) $this->get_fresh_job( $id )->next_run, 'The job should not be rescheduled.' );
	}

	/**
	 * Data provider for test_reschedule_event_without_interval.
	 *
	 * @return array[] Test data.
	 */
	public function data_reschedule_event_without_interval() {
		return array(
			'return false'    => array( false ),
			'return WP_Error' => array( true ),
		);
	}
}
