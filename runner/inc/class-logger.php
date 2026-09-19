<?php
/**
 * Job logger.
 *
 * @package RapidCron.
 *
 * phpcs:disable WordPress.DateTime.RestrictedFunctions.date_date
 */

namespace PWCC\RapidCron\Runner;

use PDO;

/**
 * Job logger.
 */
class Logger {
	/**
	 * Database connection
	 *
	 * @var PDO
	 */
	protected $db;

	/**
	 * Database prefix.
	 *
	 * @var string
	 */
	protected $table_prefix;

	/**
	 * Set up the logger.
	 *
	 * @param PDO    $db           PDO database connection.
	 * @param string $table_prefix Database table prefix.
	 */
	public function __construct( $db, $table_prefix ) {
		$this->db           = $db;
		$this->table_prefix = $table_prefix;
	}

	/**
	 * Log a completed job.
	 *
	 * @param Job    $job The completed job.
	 * @param string $message Completion message.
	 */
	public function log_job_completed( Job $job, $message = '' ) {
		$this->log_run( $job->id, 'completed', $message );
	}

	/**
	 * Log a failed job.
	 *
	 * @param Job    $job The failed job.
	 * @param string $message Failure message.
	 */
	public function log_job_failed( Job $job, $message = '' ) {
		$this->log_run( $job->id, 'failed', $message );
	}

	/**
	 * Log a running job.
	 *
	 * @param int    $job_id The job ID.
	 * @param string $status New job status.
	 * @param string $message Job message.
	 */
	protected function log_run( $job_id, $status, $message = '' ) {
		$query  = "INSERT INTO {$this->table_prefix}rapid_cron_logs (`job`, `status`, `timestamp`, `content`)";
		$query .= ' values( :job, :status, :timestamp, :content )';

		$statement = $this->db->prepare( $query );
		$statement->bindValue( ':job', $job_id );
		$statement->bindValue( ':status', $status );
		$statement->bindValue( ':timestamp', date( MYSQL_DATE_FORMAT ) );
		$statement->bindValue( ':content', $message );
		$statement->execute();
	}
}
