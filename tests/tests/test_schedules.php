<?php
/**
 * Test schedule helpers.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Tests;

use PWCC\RapidCron\Job;
use WP_UnitTestCase;

use function PWCC\RapidCron\Database\get_table_name;
use function PWCC\RapidCron\get_schedule_by_interval;
use function PWCC\RapidCron\get_schedules_by_interval;

/**
 * Test schedule helpers.
 */
class Test_Schedules extends WP_UnitTestCase {

	/**
	 * Test schedules are indexed by interval.
	 */
	public function test_get_schedules_by_interval() {
		$schedules = get_schedules_by_interval();

		$this->assertSame( 'hourly', $schedules[ HOUR_IN_SECONDS ], 'The hourly schedule should be indexed by its interval.' );
		$this->assertSame( 'daily', $schedules[ DAY_IN_SECONDS ], 'The daily schedule should be indexed by its interval.' );
		$this->assertSame( 'weekly', $schedules[ WEEK_IN_SECONDS ], 'The weekly schedule should be indexed by its interval.' );
	}

	/**
	 * Test getting a schedule name from an interval.
	 *
	 * @dataProvider data_get_schedule_by_interval
	 *
	 * @param mixed  $interval Interval to look up.
	 * @param string $expected Expected schedule name.
	 */
	public function test_get_schedule_by_interval( $interval, $expected ) {
		$this->assertSame( $expected, get_schedule_by_interval( $interval ) );
	}

	/**
	 * Data provider for test_get_schedule_by_interval.
	 *
	 * @return array[] Test data.
	 */
	public function data_get_schedule_by_interval() {
		return array(
			'hourly interval'       => array( HOUR_IN_SECONDS, 'hourly' ),
			'daily interval'        => array( DAY_IN_SECONDS, 'daily' ),
			'interval as string'    => array( (string) HOUR_IN_SECONDS, 'hourly' ),
			'unregistered interval' => array( 1234, '__fake_schedule' ),
			'null interval'         => array( null, '__fake_schedule' ),
			'zero interval'         => array( 0, '__fake_schedule' ),
		);
	}

	/**
	 * Test jobs stored without a schedule name get one from their interval.
	 */
	public function test_job_without_schedule_name_uses_interval() {
		global $wpdb;

		// Insert directly, as Job::save() always stores the schedule name.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			get_table_name( 'jobs' ),
			array(
				'site'     => get_current_blog_id(),
				'hook'     => 'rapid_cron_test_hook',
				'args'     => serialize( array() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- matches stored format.
				'start'    => gmdate( 'Y-m-d H:i:s' ),
				'next_run' => gmdate( 'Y-m-d H:i:s' ),
				'interval' => DAY_IN_SECONDS,
				'schedule' => null,
			)
		);

		$job = Job::get( $wpdb->insert_id );

		$this->assertInstanceOf( Job::class, $job, 'The job should be retrieved.' );
		$this->assertSame( 'daily', $job->schedule, 'The schedule name should be derived from the interval.' );
	}
}
