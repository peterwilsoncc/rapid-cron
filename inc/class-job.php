<?php
/**
 * Rapid Cron job Class
 *
 * @package           RapidCron
 *
 * phpcs:disable Squiz.Commenting.FunctionComment.ParamCommentFullStop
 */

namespace PWCC\RapidCron;

use WP_Error;
use stdClass;
use function PWCC\RapidCron\Database\get_table_name;

/**
 * Class for storing and updating cron jobs in the table.
 */
class Job {
	/**
	 * Job ID
	 *
	 * @var int
	 */
	public $id;

	/**
	 * Site ID
	 *
	 * @var int
	 */
	public $site;

	/**
	 * Name of hook for job.
	 *
	 * @var string
	 */
	public $hook;

	/**
	 * Job arguments
	 *
	 * @var string
	 */
	public $args;

	/**
	 * Job start (first run) time.
	 *
	 * @var string
	 */
	public $start;

	/**
	 * Job next scheduled time.
	 *
	 * @var string
	 */
	public $nextrun;

	/**
	 * Job interval.
	 *
	 * @var int Frequency the job runs, in seconds.
	 */
	public $interval;

	/**
	 * Job schedule name
	 *
	 * @var string
	 */
	public $schedule;

	/**
	 * Job status
	 *
	 * @var string
	 */
	public $status;

	/**
	 * Constructor.
	 *
	 * @param int|null $id The job ID. Null for unknown/new.
	 * @return void
	 */
	public function __construct( $id = null ) {
		$this->id = $id;
	}

	/**
	 * Has this job been created yet?
	 *
	 * @return boolean
	 */
	public function is_created() {
		return (bool) $this->id;
	}

	/**
	 * Is this a recurring job?
	 *
	 * @return boolean
	 */
	public function is_recurring() {
		return ! empty( $this->interval );
	}

	/**
	 * Save a job to the database.
	 */
	public function save() {
		global $wpdb;

		$data = array(
			'hook'    => $this->hook,
			'site'    => $this->site,
			'start'   => gmdate( DATE_FORMAT, $this->start ),
			'nextrun' => gmdate( DATE_FORMAT, $this->nextrun ),
			'args'    => serialize( $this->args ),
		);

		if ( $this->is_recurring() ) {
			$data['interval'] = $this->interval;
			$data['schedule'] = $this->schedule;
		}

		if ( $this->is_created() ) {
			$where  = array(
				'id' => $this->id,
			);
			$result = $wpdb->update( $this->get_job_table(), $data, $where, $this->row_format( $data ), $this->row_format( $where ) );
		} else {
			$result   = $wpdb->insert( $this->get_job_table(), $data, $this->row_format( $data ) );
			$this->id = $wpdb->insert_id;
		}

		wp_cache_set( "job::{$this->id}", $this, 'rapid-cron-jobs' );
		self::flush_query_cache();
		return (bool) $result;
	}

	/**
	 * Delete a job from the database.
	 *
	 * @param array $options The options used for creating the job.
	 * @return WP_Error|bool Whether the job was deleted. True on success.
	 */
	public function delete( $options = array() ) {
		global $wpdb;
		$wpdb->show_errors();

		$defaults = array(
			'delete_running' => false,
		);
		$options  = wp_parse_args( $options, $defaults );

		if ( 'running' === $this->status && ! $options['delete_running'] ) {
			return new WP_Error( 'rapid_cron.job.delete.still_running', __( 'Cannot delete running jobs', 'rapid-cron' ) );
		}

		$where  = array(
			'id' => $this->id,
		);
		$result = $wpdb->delete( $this->get_job_table(), $where, $this->row_format( $where ) );

		wp_cache_delete( "job::{$this->id}", 'rapid-cron-jobs' );
		self::flush_query_cache();

		return (bool) $result;
	}

	/**
	 * Complete a job in the database.
	 *
	 * @param array $options The options used for creating the job.
	 * @return WP_Error|bool Whether the job was deleted. True on success.
	 */
	public function complete() {
		global $wpdb;
		$wpdb->show_errors();

		$set = array(
			'status' => 'completed',
		);

		$where  = array(
			'id' => $this->id,
		);
		$result = $wpdb->update( $this->get_job_table(), $set, $where, $this->row_format( $set ), $this->row_format( $where ) );

		wp_cache_delete( "job::{$this->id}", 'rapid-cron-jobs' );
		self::flush_query_cache();

		return (bool) $result;
	}

	/**
	 * The jobs table name.
	 *
	 * @return string The table name.
	 */
	public static function get_job_table() {
		return get_table_name( 'jobs' );
	}

	/**
	 * Convert row data to Job instance
	 *
	 * @param stdClass $row Raw job data from the database.
	 * @return Job
	 */
	protected static function to_instance( $row ) {
		$job = new Job( $row->id );

		// Populate the object with row values.
		$job->site     = $row->site;
		$job->hook     = $row->hook;
		$job->args     = unserialize( $row->args );
		$job->start    = mysql2date( 'G', $row->start );
		$job->nextrun  = mysql2date( 'G', $row->nextrun );
		$job->interval = $row->interval;
		$job->status   = $row->status;

		if ( ! $row->interval ) {
			// One off event.
			$job->schedule = false;
		} elseif ( ! empty( $row->schedule ) ) {
			$job->schedule = $row->schedule;
		} else {
			$job->schedule = get_schedule_by_interval( $row->interval );
		}

		wp_cache_set( "job::{$job->id}", $job, 'rapid-cron-jobs' );
		return $job;
	}

	/**
	 * Convert list of data to Job instances
	 *
	 * @param stdClass[] $rows Raw mapping rows.
	 * @return Job[]
	 */
	protected static function to_instances( $rows ) {
		return array_map( array( get_called_class(), 'to_instance' ), $rows );
	}

	/**
	 * Get job by job ID
	 *
	 * @param int|Job $job Job ID or instance.
	 * @return Job|WP_Error|null Job on success, WP_Error if error occurred, or null if no job found.
	 */
	public static function get( $job ) {
		global $wpdb;

		if ( $job instanceof Job ) {
			return $job;
		}

		$job = absint( $job );

		$cached_job = wp_cache_get( "job::{$job}", 'rapid-cron-jobs' );
		if ( $cached_job ) {
			return $cached_job;
		}

		$suppress = $wpdb->suppress_errors();
		$job      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', static::get_job_table(), $job ) );
		$wpdb->suppress_errors( $suppress );

		if ( ! $job ) {
			return null;
		}

		return static::to_instance( $job );
	}

	/**
	 * Get jobs by site ID
	 *
	 * @param int|stdClass $site Site ID, or site object from {@see get_blog_details}.
	 * @param bool         $include_completed Should we include completed jobs?
	 * @param bool         $include_failed Should we include failed jobs?
	 * @param bool         $exclude_future Should we exclude future (not ready) jobs?
	 * @return Job[]|WP_Error Jobs on success, error otherwise.
	 */
	public static function get_by_site( $site, $include_completed = false, $include_failed = false, $exclude_future = false ) {

		// Allow passing a site object in.
		if ( is_object( $site ) && isset( $site->blog_id ) ) {
			$site = $site->blog_id;
		}

		if ( ! is_numeric( $site ) ) {
			return new WP_Error( 'rapid_cron.job.invalid_site_id' );
		}

		$args = array(
			'site'     => $site,
			'args'     => null,
			'statuses' => array( 'waiting', 'running' ),
			'limit'    => 0,
			'__raw'    => true,
		);

		if ( $include_completed ) {
			$args['statuses'][] = 'completed';
		}
		if ( $include_failed ) {
			$args['statuses'][] = 'failed';
		}
		if ( $exclude_future ) {
			$args['timestamp'] = 'past';
		}

		$results = static::get_jobs_by_query( $args );

		if ( empty( $results ) ) {
			return array();
		}

		return static::to_instances( $results );
	}

	/**
	 * Query jobs database.
	 *
	 * Returns an array of Job instances for the current site based
	 * on the parameters.
	 *
	 * @todo: allow searching within time window for duplicate events.
	 *
	 * @param array|\stdClass $args Job arguments. {
	 *     @var string          $hook      Jobs hook to return. Optional.
	 *     @var int|string|null $timestamp Timestamp to search for. Optional.
	 *                                       String shortcuts `future`: > NOW(); `past`: <= NOW()
	 *     @var array           $args      Cron job arguments.
	 *     @var int|object      $site      Site to query. Default current site.
	 *     @var array           $statuses  Job statuses to query. Default to waiting and running.
	 *     @var int             $limit     Max number of jobs to return. Default 1.
	 *     @var string          $order     ASC or DESC. Default ASC.
	 * }
	 * @return Job[]|WP_Error Jobs on success, error otherwise.
	 */
	public static function get_jobs_by_query( $args = array() ) {
		global $wpdb;
		$args    = (array) $args;
		$results = array();

		$defaults = array(
			'timestamp' => null,
			'hook'      => null,
			'args'      => array(),
			'site'      => get_current_blog_id(),
			'statuses'  => array( 'waiting', 'running' ),
			'limit'     => 1,
			'order'     => 'ASC',
			'__raw'     => false,
		);

		$args = wp_parse_args( $args, $defaults );

		/**
		 * Filters the get_jobs_by_query() arguments.
		 *
		 * An example use case would be to enforce limits on the number of results
		 * returned if you run into performance problems.
		 *
		 * @param array $args {
		 *     @param string           $hook      Jobs hook to return. Optional.
		 *     @param int|string|array $timestamp Timestamp to search for. Optional.
		 *                                        String shortcuts `future`: > NOW(); `past`: <= NOW()
		 *                                        Array of 2 time stamps will search between those dates.
		 *     @param array            $args      Cron job arguments.
		 *     @param int|object       $site      Site to query. Default current site.
		 *     @param array            $statuses  Job statuses to query. Default to waiting and running.
		 *                                        Possible values are 'waiting', 'running', 'completed' and 'failed'.
		 *     @param int              $limit     Max number of jobs to return. Default 1.
		 *     @param string           $order     ASC or DESC. Default ASC.
		 *     @param bool             $__raw     If true return the raw array of data rather than Job objects.
		 * }
		 */
		$args = apply_filters( 'rapid_cron.get_jobs_by_query.args', $args );

		// Allow passing a site object in.
		if ( is_object( $args['site'] ) && isset( $args['site']->blog_id ) ) {
			$args['site'] = $args['site']->blog_id;
		}

		if ( ! is_numeric( $args['site'] ) ) {
			return new WP_Error( 'rapid_cron.job.invalid_site_id' );
		}

		if ( ! empty( $args['hook'] ) && ! is_string( $args['hook'] ) ) {
			return new WP_Error( 'rapid_cron.job.invalid_hook_name' );
		}

		if ( ! is_array( $args['args'] ) && ! is_null( $args['args'] ) ) {
			return new WP_Error( 'rapid_cron.job.invalid_event_arguments' );
		}

		if ( ! is_numeric( $args['limit'] ) ) {
			return new WP_Error( 'rapid_cron.job.invalid_limit' );
		}

		$args['limit'] = absint( $args['limit'] );

		// Find all scheduled events for this site.
		$table = static::get_job_table();

		$sql          = "SELECT * FROM `{$table}` WHERE site = %d";
		$sql_params[] = $args['site'];

		if ( is_string( $args['hook'] ) ) {
			$sql         .= ' AND hook = %s';
			$sql_params[] = $args['hook'];
		}

		if ( ! is_null( $args['args'] ) ) {
			$sql         .= ' AND args = %s';
			$sql_params[] = serialize( $args['args'] );
		}

		// Timestamp 'future' shortcut.
		if ( 'future' === $args['timestamp'] ) {
			$sql         .= ' AND nextrun > %s';
			$sql_params[] = date( DATE_FORMAT );
		}

		// Timestamp past shortcut.
		if ( 'past' === $args['timestamp'] ) {
			$sql         .= ' AND nextrun <= %s';
			$sql_params[] = date( DATE_FORMAT );
		}

		// Timestamp array range.
		if ( is_array( $args['timestamp'] ) && count( $args['timestamp'] ) === 2 ) {
			$sql         .= ' AND nextrun BETWEEN %s AND %s';
			$sql_params[] = date( DATE_FORMAT, (int) $args['timestamp'][0] );
			$sql_params[] = date( DATE_FORMAT, (int) $args['timestamp'][1] );
		}

		// Default integer timestamp.
		if ( is_int( $args['timestamp'] ) ) {
			$sql         .= ' AND nextrun = %s';
			$sql_params[] = date( DATE_FORMAT, (int) $args['timestamp'] );
		}

		$sql       .= ' AND status IN(' . implode( ',', array_fill( 0, count( $args['statuses'] ), '%s' ) ) . ')';
		$sql_params = array_merge( $sql_params, $args['statuses'] );

		$sql .= ' ORDER BY nextrun';
		if ( 'DESC' === $args['order'] ) {
			$sql .= ' DESC';
		} else {
			$sql .= ' ASC';
		}

		if ( $args['limit'] > 0 ) {
			$sql         .= ' LIMIT %d';
			$sql_params[] = $args['limit'];
		}

		// Cache results.
		$last_changed = wp_cache_get_last_changed( 'rapid-cron-jobs' );
		$query_hash   = sha1( serialize( array( $sql, $sql_params ) ) );
		$results      = wp_cache_get_salted(
			"jobs::{$query_hash}",
			'rapid-cron-jobs',
			$last_changed
		);

		if ( false === $results ) {
			$results = $wpdb->get_results( $wpdb->prepare( $sql, $sql_params ) );
			wp_cache_set_salted(
				"jobs::{$query_hash}",
				$results,
				'rapid-cron-jobs',
				$last_changed
			);
		}

		if ( true === $args['__raw'] ) {
			return $results;
		}

		return static::to_instances( $results );
	}

	/**
	 * Invalidates existing query cache keys by updating last changed time.
	 */
	public static function flush_query_cache() {
		wp_cache_set( 'last_changed', microtime(), 'rapid-cron-jobs' );
	}

	/**
	 * Get the (printf-style) format for a given column.
	 *
	 * @param string $column Column to retrieve format for.
	 * @return string Format specifier. Defaults to '%s'
	 */
	protected static function column_format( $column ) {
		$columns = array(
			'id'       => '%d',
			'site'     => '%d',
			'hook'     => '%s',
			'args'     => '%s',
			'start'    => '%s',
			'nextrun'  => '%s',
			'interval' => '%d',
			'schedule' => '%s',
			'status'   => '%s',
		);

		if ( isset( $columns[ $column ] ) ) {
			return $columns[ $column ];
		}

		return '%s';
	}

	/**
	 * Get the (printf-style) formats for an entire row.
	 *
	 * @param array $row Map of field to value.
	 * @return array List of formats for fields in the row. Order matches the input order.
	 */
	protected static function row_format( $row ) {
		$format = array();
		foreach ( $row as $field => $value ) {
			$format[] = static::column_format( $field );
		}
		return $format;
	}
}
