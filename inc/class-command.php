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
		$finished = false;

		/*
		 * Finish the job if the process ends before the hook returns.
		 *
		 * `finally` blocks don't run after a fatal error or `exit()`, but
		 * shutdown functions do. A fatal error fails the job; an `exit()`
		 * is treated as completion.
		 */
		register_shutdown_function(
			function () use ( $job, &$finished ) {
				if ( $finished ) {
					return;
				}
				$finished = true;
				$job->finish( self::is_fatal_error( error_get_last() ) );
			}
		);

		try {
			do_action_ref_array( $job->hook, $job->args );
		} catch ( \Throwable $e ) {
			$finished = true;
			$job->finish( true );
			throw $e;
		}

		$finished = true;
		$job->finish( false );
	}

	/**
	 * Recover a job left running by a worker that has exited.
	 *
	 * Recurring jobs are rescheduled and single jobs are marked failed. Jobs
	 * that are not in the running state are left alone, so this is safe to
	 * call after a job has finished normally.
	 *
	 * Only use this when the job's process is no longer running.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID of the job to recover.
	 *
	 * @synopsis <id>
	 */
	public function recover( $args, $assoc_args ) {
		$id = absint( $args[0] );

		// Read the current status from the database, not a cached copy.
		wp_cache_delete( "job::{$id}", 'rapid-cron-jobs' );
		$job = Job::get( $id );
		if ( empty( $job ) ) {
			WP_CLI::error( 'Invalid job ID' );
		}

		if ( 'running' !== $job->status ) {
			WP_CLI::log( sprintf( 'Job %d is %s, nothing to recover.', $job->id, $job->status ) );
			return;
		}

		if ( ! $job->finish( true ) ) {
			WP_CLI::error( sprintf( 'Unable to recover job %d.', $job->id ) );
		}

		// finish() sets the status on this instance when it fails the job.
		WP_CLI::success( sprintf( 'Job %d recovered as %s.', $job->id, 'failed' === $job->status ? 'failed' : 'rescheduled' ) );
	}

	/**
	 * Whether an error from error_get_last() ended the script.
	 *
	 * @param array|null $error Error from error_get_last().
	 * @return bool True if the error was fatal.
	 */
	protected static function is_fatal_error( $error ) {
		if ( empty( $error['type'] ) ) {
			return false;
		}

		$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

		return (bool) ( $error['type'] & $fatal_types );
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
