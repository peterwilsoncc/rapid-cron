<?php
/**
 * phpcs:ignoreFile WordPress.DB.PreparedSQL.NotPrepared
 */

namespace PWCC\RapidCron;

use WP_CLI;
use WP_CLI_Command;

use function PWCC\RapidCron\Database\get_table_name;

class Command extends WP_CLI_Command {
	/**
	 * Run a job.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID of the job to run.
	 *
	 * @synopsis <id>
	 */
	public function run( $args, $assoc_args ) {
		$job = Job::get( $args[0] );
		if ( empty( $job ) ) {
			WP_CLI::error( 'Invalid job ID' );
		}
		$lock_obtained = $job->lock();
		usleep( 10 );
		if ( ! $lock_obtained ) {
			\WP_CLI::warning( 'Job locked by another instance.', 'rapid-cron' );
			return;
		}

		// Make the current job id available for hooks run by this job
		define( 'RAPID_CRON_JOB_ID', $job->id );

		// Handle SIGTERM calls as we don't want to kill a running job
		if ( function_exists( 'pcntl_signal' ) ) {
			pcntl_signal( SIGTERM, SIG_IGN );
		}

		// Set the wp-cron constant for plugin and theme interactions
		defined( 'DOING_CRON' ) or define( 'DOING_CRON', true );

		/*
		 * Fires scheduled events.
		 *
		 * @ignore
		 *
		 * @param string $hook Name of the hook that was scheduled to be fired.
		 * @param array  $args The arguments to be passed to the hook.
		 */
		try {
			do_action_ref_array( $job->hook, $job->args );
		}
		finally {
			if ( $job->schedule ) {
				wp_reschedule_event( $job->next_run, $job->schedule, $job->hook, $job->args );
				return;
			}
			// Mark Job as completed.
			$job->complete();
		}
	}

	/**
	 * Show logs on completed jobs
	 *
	 * @synopsis [--format=<format>] [--fields=<fields>] [--job=<job-id>] [--hook=<hook>]
	 */
	public function log( $args, $assoc_args ) {

		global $wpdb;

		$log_table = get_table_name('logs' );
		$job_table = get_table_name( 'jobs' );

		$assoc_args = wp_parse_args( $assoc_args, [
			'format'  => 'table',
			'fields'  => 'job,hook,timestamp,status',
			'hook'    => null,
			'job'     => null,
		]);

		$where = [];
		$data  = [];

		if ( $assoc_args['job'] ) {
			$where[] = 'job = %d';
			$data[]  = $assoc_args['job'];
		}

		if ( $assoc_args['hook'] ) {
			$where[] = 'hook = %s';
			$data[] = $assoc_args['hook'];
		}

		$where = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$query = "SELECT $log_table.*, $job_table.hook,$job_table.args FROM $log_table INNER JOIN $job_table ON $log_table.job = $job_table.id $where";

		if ( $data ) {
			$query = $wpdb->prepare( $query, $data );
		}

		$logs = $wpdb->get_results( $query );

		\WP_CLI\Utils\format_items( $assoc_args['format'], $logs, explode( ',', $assoc_args['fields'] ) );
	}

	/**
	 * Show jobs.
	 *
	 * @synopsis [--format=<format>] [--id=<job-id>] [--site=<site-id>] [--hook=<hook>] [--status=<status>] [--limit=<limit>] [--page=<page>] [--order=<order>] [--orderby=<orderby>]
	 */
	public function jobs( $args, $assoc_args ) {

		global $wpdb;

		$log_table = get_table_name('logs' );
		$job_table = get_table_name( 'jobs' );

		$assoc_args = wp_parse_args(
			$assoc_args,
			[
				'format'  => 'table',
				'fields'  => 'id,site,hook,start,next_run,status',
				'id'      => null,
				'site'    => null,
				'hook'    => null,
				'status'  => null,
				'limit'   => 20,
				'page'    => 1,
				'order'   => null,
				'orderby' => null,
			]
		);

		$where    = [];
		$data     = [];
		$_order   = [
			'ASC',
			'DESC',
		];
		$_orderby = [
			'id',
			'site',
			'hook',
			'args',
			'start',
			'next_run',
			'interval',
			'status',
		];
		$order    = 'DESC';
		$orderby  = 'id';

		if ( $assoc_args['id'] ) {
			$where[] = 'id = %d';
			$data[]  = $assoc_args['id'];
		}

		if ( $assoc_args['site'] ) {
			$where[] = 'site = %d';
			$data[]  = $assoc_args['site'];
		}

		if ( $assoc_args['hook'] ) {
			$where[] = 'hook = %s';
			$data[]  = $assoc_args['hook'];
		}

		if ( $assoc_args['status'] ) {
			$where[] = 'status = %s';
			$data[]  = $assoc_args['status'];
		}

		if ( $assoc_args['order'] && in_array( strtoupper( $assoc_args['order'] ), $_order, true ) ) {
			$order = strtoupper( $assoc_args['order'] );
		}

		if ( $assoc_args['orderby'] && in_array( $assoc_args['orderby'], $_orderby, true ) ) {
			$orderby = $assoc_args['orderby'];
		}

		$where = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$limit  = 'LIMIT %d';
		$data[] = absint( $assoc_args['limit'] );
		$offset = 'OFFSET %d';
		$data[] = absint( ( $assoc_args['page'] - 1 ) * $assoc_args['limit'] );

		$query = "SELECT * FROM $job_table $where ORDER BY $orderby $order $limit $offset";

		if ( $data ) {
			$query = $wpdb->prepare( $query, $data );
		}

		$logs = $wpdb->get_results( $query );

		if ( empty( $logs ) ) {
			\WP_CLI::error( 'No Rapid Cron jobs found.' );
		} else {
			\WP_CLI\Utils\format_items( $assoc_args['format'], $logs, explode( ',', $assoc_args['fields'] ) );
		}

	}
}
