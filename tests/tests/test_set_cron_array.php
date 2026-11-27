<?php
/**
 * Test saving the cron array.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Tests;

use PWCC\RapidCron\Job;

/**
 * Test update_cron_array().
 *
 * The return value of _set_cron_array() isn't tested. Rapid Cron cancels the
 * option update after saving the jobs, so it reports failure on success.
 */
class Test_Set_Cron_Array extends TestCase {

	const HOOK = 'rapid_cron_test_hook';

	/**
	 * Core's key for an event's arguments.
	 *
	 * @param array $args Event arguments.
	 * @return string Key.
	 */
	private function args_key( $args = array() ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- matches core's event keys.
		return md5( serialize( $args ) );
	}

	/**
	 * Change the test event in the cron array.
	 *
	 * @param int        $timestamp Event timestamp.
	 * @param array|null $value     New event value, null to remove the event.
	 */
	private function set_event( $timestamp, $value ) {
		$crons = _get_cron_array();

		if ( null === $value ) {
			unset( $crons[ $timestamp ][ self::HOOK ][ $this->args_key() ] );
		} else {
			$crons[ $timestamp ][ self::HOOK ][ $this->args_key() ] = $value;
		}

		_set_cron_array( $crons );
	}

	/**
	 * Register a schedule with the same interval as hourly.
	 */
	private function add_hourly_alias_schedule() {
		add_filter(
			'cron_schedules',
			function ( $schedules ) {
				$schedules['rapid_cron_hourly'] = array(
					'interval' => HOUR_IN_SECONDS,
					'display'  => 'Rapid Cron hourly',
				);
				return $schedules;
			}
		);
	}

	/**
	 * Test events added to the cron array are scheduled.
	 *
	 * @dataProvider data_add_event
	 *
	 * @param string|false $schedule Event schedule, false for a single event.
	 * @param int|null     $interval Event interval, null for a single event.
	 */
	public function test_add_event( $schedule, $interval ) {
		wp_schedule_event( time(), 'daily', 'rapid_cron_other_hook' );
		$timestamp = time() + HOUR_IN_SECONDS;

		$value = array(
			'schedule' => $schedule,
			'args'     => array(),
		);
		if ( $interval ) {
			$value['interval'] = $interval;
		}
		$this->set_event( $timestamp, $value );

		$event = wp_get_scheduled_event( self::HOOK );
		$this->assertNotFalse( $event, 'The event should be scheduled.' );
		$this->assertSame( $timestamp, $event->timestamp, 'The event should be scheduled at the timestamp.' );
		$this->assertSame( $schedule, $event->schedule, 'The event schedule is incorrect.' );
		$this->assertSame( 1, $this->count_events( 'rapid_cron_other_hook' ), 'Other events should be kept.' );
	}

	/**
	 * Data provider for test_add_event.
	 *
	 * @return array[] Test data.
	 */
	public function data_add_event() {
		return array(
			'single event'    => array( false, null ),
			'recurring event' => array( 'hourly', HOUR_IN_SECONDS ),
		);
	}

	/**
	 * Test events removed from the cron array are unscheduled.
	 */
	public function test_remove_event() {
		wp_schedule_event( time(), 'daily', 'rapid_cron_other_hook' );
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_event( $timestamp, 'hourly', self::HOOK );

		$this->set_event( $timestamp, null );

		$this->assertFalse( wp_next_scheduled( self::HOOK ), 'The event should be unscheduled.' );
		$this->assertSame( 1, $this->count_events( 'rapid_cron_other_hook' ), 'Other events should be kept.' );
	}

	/**
	 * Test changing an event in the cron array changes the scheduled event.
	 *
	 * @dataProvider data_change_event
	 *
	 * @param string|false $schedule New event schedule, false for a single event.
	 * @param int|null     $interval New event interval, null for a single event.
	 */
	public function test_change_event( $schedule, $interval ) {
		$this->add_hourly_alias_schedule();
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_event( $timestamp, 'hourly', self::HOOK );

		$value = array(
			'schedule' => $schedule,
			'args'     => array(),
		);
		if ( $interval ) {
			$value['interval'] = $interval;
		}
		$this->set_event( $timestamp, $value );

		$this->assertSame( 1, $this->count_events( self::HOOK ), 'The event should be replaced.' );
		$event = wp_get_scheduled_event( self::HOOK );
		$this->assertSame( $timestamp, $event->timestamp, 'The event should keep its timestamp.' );
		$this->assertSame( $schedule, $event->schedule, 'The event schedule is incorrect.' );
		if ( $interval ) {
			$this->assertEquals( $interval, $event->interval, 'The event interval is incorrect.' );
		} else {
			$this->assertFalse( isset( $event->interval ), 'A single event should not have an interval.' );
		}
	}

	/**
	 * Data provider for test_change_event.
	 *
	 * @return array[] Test data.
	 */
	public function data_change_event() {
		return array(
			'schedule with the same interval' => array( 'rapid_cron_hourly', HOUR_IN_SECONDS ),
			'schedule and interval'           => array( 'daily', DAY_IN_SECONDS ),
			'recurring to single'             => array( false, null ),
		);
	}

	/**
	 * Test events left unchanged in the cron array are kept.
	 */
	public function test_unchanged_events_are_kept() {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		wp_schedule_single_event( time() + DAY_IN_SECONDS, self::HOOK, array( 'single' ) );
		$recurring = wp_get_scheduled_event( self::HOOK );
		$single    = wp_get_scheduled_event( self::HOOK, array( 'single' ) );

		// Add another event to trigger a save.
		$crons = _get_cron_array();
		$crons[ time() + WEEK_IN_SECONDS ]['rapid_cron_other_hook'][ $this->args_key() ] = array(
			'schedule' => false,
			'args'     => array(),
		);
		_set_cron_array( $crons );

		$this->assertSame( 2, $this->count_events( self::HOOK ), 'The unchanged events should be kept.' );
		$this->assertEquals( $recurring, wp_get_scheduled_event( self::HOOK ), 'The recurring event should be unchanged.' );
		$this->assertEquals( $single, wp_get_scheduled_event( self::HOOK, array( 'single' ) ), 'The single event should be unchanged.' );
	}

	/**
	 * Test running jobs removed from the cron array are deleted.
	 *
	 * Core removes an event before running it, so this has no core equivalent.
	 *
	 * @group rapid-cron-internals
	 */
	public function test_remove_running_job() {
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_event( $timestamp, 'hourly', self::HOOK );
		$jobs = Job::get_jobs_by_query( array( 'hook' => self::HOOK ) );
		$this->assertTrue( $jobs[0]->lock(), 'The job should lock.' );

		$this->set_event( $timestamp, null );

		wp_cache_delete( "job::{$jobs[0]->id}", 'rapid-cron-jobs' );
		$this->assertNull( Job::get( $jobs[0]->id ), 'The running job should be deleted.' );
		$this->assertFalse( wp_next_scheduled( self::HOOK ), 'The job should be unscheduled.' );
	}
}
