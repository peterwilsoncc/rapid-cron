<?php
/**
 * Runner Cron job Class
 *
 * @package           RapidCron
 */

namespace PWCC\RapidCron\Runner;

use DateInterval;
use DateTime;
use DateTimeZone;
use PDO;

const MYSQL_DATE_FORMAT = 'Y-m-d H:i:s';

/**
 * Runner job class
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
	public $next_run;

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
	 * Database
	 *
	 * @var PDO
	 */
	protected $db;

	/**
	 * Table prefix
	 *
	 * @var string
	 */
	protected $table_prefix;

	/**
	 * Whether blogs table exists.
	 *
	 * @var null|bool
	 */
	protected static $blogs_table_exists;

	/**
	 * Constructor
	 *
	 * @param PDO    $db Database connector thing.
	 * @param string $table_prefix The table prefix.
	 */
	public function __construct( $db, $table_prefix ) {
		$this->db           = $db;
		$this->table_prefix = $table_prefix;
	}

	/**
	 * Site URL for job.
	 *
	 * @return false|string The site URL if defined.
	 */
	public function get_site_url() {

		if ( ! $this->blogs_table_exists() ) {
			return false;
		}

		$query  = "SELECT domain, path FROM {$this->table_prefix}blogs";
		$query .= ' WHERE blog_id = :site';

		$statement = $this->db->prepare( $query );
		$statement->bindValue( ':site', $this->site );
		$statement->execute();

		$data = $statement->fetch( PDO::FETCH_ASSOC );
		$url  = $data['domain'] . $data['path'];
		return $url;
	}

	/**
	 * Whether blog table exists.
	 *
	 * @return bool True if so, false if not.
	 */
	protected function blogs_table_exists() {
		if ( null !== static::$blogs_table_exists ) {
			return static::$blogs_table_exists;
		}

		$query     = "SHOW TABLES LIKE '{$this->table_prefix}blogs'";
		$statement = $this->db->prepare( $query );
		$statement->execute();

		static::$blogs_table_exists = $statement->rowCount() > 0;

		return static::$blogs_table_exists;
	}

	/**
	 * No-op lock function.
	 *
	 * @return true True.
	 */
	public function acquire_lock() {
		return true;
	}

	/**
	 * No-op completion function.
	 */
	public function mark_completed() {
	}

	/**
	 * No-op reschedule function.
	 */
	public function reschedule() {
	}

	/**
	 * No-op failure function.
	 *
	 * @param string $message Failure message.
	 */
	public function mark_failed( $message = '' ) {
		$message = 'message'; // Avoiding sniff.
	}
}
