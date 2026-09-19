<?php
/**
 * Cron Runner
 *
 * @package RapidCron
 *
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
 */

namespace PWCC\RapidCron\Runner;

use Exception;
use PDO;

const LOOP_INTERVAL = 1.5;

/**
 * Cron Runner
 */
class Runner {
	/**
	 * Runner options
	 *
	 * @var array
	 */
	public $options = array();

	/**
	 * Hook system for the Runner.
	 *
	 * @var Hooks
	 */
	public $hooks;

	/**
	 * Database
	 *
	 * @var PDO
	 */
	protected $db;

	/**
	 * Workers.
	 *
	 * @var array
	 */
	protected $workers = array();

	/**
	 * WordPress path.
	 *
	 * @var string
	 */
	protected $wp_path;

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	protected $table_prefix;

	/**
	 * Instance of the runner.
	 *
	 * @var self
	 */
	protected static $instance;

	/**
	 * Constructor.
	 *
	 * @param array $options Runner options.
	 */
	public function __construct( $options = array() ) {
		$defaults      = array(
			'max_workers' => 1,
		);
		$this->options = array_merge( $defaults, $options );
		$this->hooks   = new Hooks();
	}

	/**
	 * Get the singleton instance of the Runner.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( empty( static::$instance ) ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Bootstrap the runner.
	 *
	 * @throws Exception Throws if wp-config path doesn't exist.
	 *
	 * @param string $wp_path Path to WP.
	 */
	public function bootstrap( $wp_path = '.' ) {
		// Check some requirements first.
		if ( ! function_exists( 'pcntl_signal' ) ) {
			throw new Exception( 'pcntl extension is required' );
		}

		$config_path = realpath( $wp_path . '/wp-config.php' );
		if ( ! file_exists( $config_path ) ) {
			$config_path = realpath( $wp_path . '/../wp-config.php' );
			if ( ! file_exists( $config_path ) ) {
				throw new Exception( sprintf( 'Could not find config file at %s', realpath( $wp_path ) . '/wp-config.php or next level up.' ) );
			}
		}

		$this->wp_path = realpath( $wp_path );

		// Load WP config.
		define( 'ABSPATH', dirname( __DIR__ ) . '/fakewp/' );
		if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
			$_SERVER['HTTP_HOST'] = 'cavalcade.example';
		}

		include $config_path;
		$this->table_prefix = isset( $table_prefix ) ? $table_prefix : 'wp_';

		/**
		 * Filter the table prefix from the configuration.
		 *
		 * @param string $table_prefix Table prefix to use for Cavalcade.
		 */
		$this->table_prefix = $this->hooks->run( 'Runner.bootstrap.table_prefix', $this->table_prefix );

		// Connect to the database!
		$this->connect_to_db();
	}

	/**
	 * Run a job.
	 */
	public function run() {
		$running = array();

		// Handle SIGTERM calls.
		pcntl_signal( SIGTERM, array( $this, 'terminate' ) );
		pcntl_signal( SIGINT, array( $this, 'terminate' ) );
		pcntl_signal( SIGQUIT, array( $this, 'terminate' ) );

		/**
		 * Action before starting to run.
		 */
		$this->hooks->run( 'Runner.run.before' );

		while ( true ) {
			// Check for any signals we've received.
			pcntl_signal_dispatch();

			/**
			 * Action at the start of every loop iteration.
			 *
			 * @param Runner $this Instance of the Cavalcade Runner.
			 */
			$this->hooks->run( 'Runner.run.loop_start', $this );

			// Check the running workers.
			$this->check_workers();

			// Do we have workers to spare?
			if ( count( $this->workers ) === $this->options['max_workers'] ) {
				// At maximum workers, wait a cycle.
				printf( '[  ] Out of workers' . PHP_EOL );
				sleep( LOOP_INTERVAL );
				continue;
			}

			// Find any new jobs, or wait for one.
			$job = $this->get_next_job();
			if ( empty( $job ) ) {
				// No job to run, try again in the specified interval.
				sleep( LOOP_INTERVAL );
				continue;
			}

			// Spawn worker.
			try {
				$this->run_job( $job );
			} catch ( Exception $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- not real WP.
				trigger_error( sprintf( 'Unable to run job due to exception: %s', $e->getMessage() ), E_USER_WARNING );
				$job->mark_failed( $e->getMessage() );
				break;
			}

			// Go again!
		}

		$this->terminate( SIGTERM );
	}

	/**
	 * Terminal the worker.
	 *
	 * @throws SignalInterrupt Interrupt.
	 *
	 * @param int $signal Terminate signal.
	 */
	public function terminate( $signal ) {
		/**
		 * Action before terminating workers.
		 *
		 * Use this to change the cleanup process.
		 *
		 * @param int $signal Signal received that caused termination.
		 */
		$this->hooks->run( 'Runner.terminate.will_terminate', $signal );

		printf( 'Cavalcade received terminate signal (%s), shutting down %d worker(s)...' . PHP_EOL, $signal, count( $this->workers ) );
		// Wait and clean up.
		while ( ! empty( $this->workers ) ) {
			$this->check_workers();
			usleep( 100000 );
		}

		/**
		 * Action after terminating workers.
		 *
		 * Use this to run final shutdown commands while still connected to the database.
		 *
		 * @param int $signal Signal received that caused termination.
		 */
		$this->hooks->run( 'Runner.terminate.terminated', $signal );

		unset( $this->db );

		throw new SignalInterrupt( 'Terminated by signal', $signal );
	}

	/**
	 * Get the faux WP path.
	 *
	 * @return string Path to WP.
	 */
	public function get_wp_path() {
		return $this->wp_path;
	}

	/**
	 * Connect to database.
	 */
	protected function connect_to_db() {
		$charset = defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8';

		// Check if we're passed a Unix socket (`:/tmp/socket` or `localhost:/tmp/socket`).
		if ( preg_match( '#^[^:]*:(/.+)$#', DB_HOST, $matches ) ) {
			$dsn = sprintf( 'mysql:unix_socket=%s;dbname=%s;charset=%s', $matches[1], DB_NAME, $charset );
		} else {
			$dsn = sprintf( 'mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, $charset );
		}

		/**
		 * Filter for PDO DSN.
		 *
		 * @param string $dsn DSN passed to PDO.
		 * @param string $host Database host from config.
		 * @param string $name Database name from config.
		 * @param string $charset Character set from config, or default of 'utf8'
		 */
		$dsn = $this->hooks->run( 'Runner.connect_to_db.dsn', $dsn, DB_HOST, DB_NAME, $charset );

		/**
		 * Filter for PDO options.
		 *
		 * @param array $options Options to pass to PDO.
		 * @param string $dsn DSN for the connection.
		 * @param string $user User for the connection
		 * @param string $password Password for the connection.
		 */
		$options  = $this->hooks->run( 'Runner.connect_to_db.options', array(), $dsn, DB_USER, DB_PASSWORD );
		$this->db = new PDO( $dsn, DB_USER, DB_PASSWORD, $options );

		// Set it up just how we like it.
		$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->db->setAttribute( PDO::ATTR_EMULATE_PREPARES, false );
		$this->db->exec( 'SET time_zone = "+00:00"' );

		/**
		 * Action after connecting to the database.
		 *
		 * Use the PDO object to set additional attributes as needed.
		 *
		 * @param PDO $db PDO database connection.
		 */
		$this->hooks->run( 'Runner.connect_to_db.connected', $this->db );
	}

	/**
	 * Get next job to run.
	 *
	 * @return \stdClass|null Next job to run.
	 */
	protected function get_next_job() {
		$query  = "SELECT * FROM {$this->table_prefix}rapid_cron_jobs";
		$query .= ' WHERE next_run < NOW() AND status = "waiting"';
		$query .= ' ORDER BY next_run ASC';
		$query .= ' LIMIT 1';

		/**
		 * Filter for the next job query.
		 *
		 * @param string $query Database query for the next job.
		 */
		$query = $this->hooks->run( 'Runner.get_next_job.query', $query );

		$statement = $this->db->prepare( $query );
		$statement->execute();

		$data = $statement->fetchObject( __NAMESPACE__ . '\\Job', array( $this->db, $this->table_prefix ) );
		/**
		 * Filter for the next job.
		 *
		 * @param Job $data Next job to be run.
		 */
		return $this->hooks->run( 'Runner.get_next_job.job', $data );
	}

	/**
	 * Run a job.
	 *
	 * @throws Exception Process unable to run.
	 *
	 * @param Job $job Job object.
	 */
	protected function run_job( $job ) {
		// Mark the job as started.
		$has_lock = $job->acquire_lock();
		if ( ! $has_lock ) {
			// Couldn't get lock, looks like another supervisor already started.
			return;
		}

		$command = $this->get_job_command( $job );

		$cwd = $this->wp_path;
		printf( '[%d] Running %s (%s %s)' . PHP_EOL, $job->id, $command, $job->hook, $job->args );

		$spec = array(
			// We're intentionally avoiding adding a stdin pipe
			// stdin 0 => null.

			// stdout.
			1 => array( 'pipe', 'w' ),

			// stderr.
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open( $command, $spec, $pipes, $cwd );

		if ( ! is_resource( $process ) ) {
			// Set the job to failed as we don't know if the process was able to run the job.
			throw new Exception( 'Unable to proc_open.' );
		}

		// Disable blocking to allow partial stream reads before EOF.
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$worker          = new Worker( $process, $pipes, $job );
		$this->workers[] = $worker;

		printf( '[%d] Started worker' . PHP_EOL, $job->id );

		/**
		 * Action after starting a new worker.
		 *
		 * @param Worker $worker Worker that started.
		 * @param Job $job Job that the worker is processing.
		 */
		$this->hooks->run( 'Runner.run_job.started', $worker, $job );
	}

	/**
	 * Get the job command for WP-CLI.
	 *
	 * @param Job $job The job to get the command.
	 * @return string The command.
	 */
	protected function get_job_command( $job ) {
		$site_url = $job->get_site_url();

		$command = sprintf(
			'wp rapid-cron run %d',
			$job->id
		);

		if ( $site_url ) {
			$command .= sprintf(
				' --url=%s',
				escapeshellarg( $site_url )
			);
		}

		/**
		 * Filter for the command to be run for the job.
		 *
		 * @param string $command Full shell command to be run to start the job.
		 * @param Job $job Job to be run.
		 */
		return $this->hooks->run( 'Runner.get_job_command.command', $command, $job );
	}

	/**
	 * Check for workers.
	 *
	 * @return mixed Workers.
	 */
	protected function check_workers() {
		if ( empty( $this->workers ) ) {
			return true;
		}

		$pipes_stdout = array();
		$pipes_stderr = array();
		foreach ( $this->workers as $id => $worker ) {
			$pipes_stdout[ $id ] = $worker->pipes[1];
			$pipes_stderr[ $id ] = $worker->pipes[2];
		}

		// Grab all the pipes ready to close.
		$a = null;
		$b = null; // Dummy vars for reference passing.

		$changed_stdout = stream_select( $pipes_stdout, $a, $b, 0 );
		if ( false === $changed_stdout ) {
			// An error occurred!
			return false;
		}

		$changed_stderr = stream_select( $pipes_stderr, $a, $b, 0 );
		if ( false === $changed_stderr ) {
			// An error occurred!
			return false;
		}

		if ( 0 === $changed_stdout && 0 === $changed_stderr ) {
			// No change, try again.
			return true;
		}

		// List of Workers with a changed state.
		$changed_workers = array_unique( array_merge( array_keys( $pipes_stdout ), array_keys( $pipes_stderr ) ) );

		/**
		 * Filter for using a custom Logger implementation, instead of the
		 * default one.
		 *
		 * @param object $logger Logger implementation that will be used.
		 */
		$logger = $this->hooks->run(
			'Runner.check_workers.logger',
			new Logger( $this->db, $this->table_prefix )
		);

		// Clean up all of the finished workers.
		foreach ( $changed_workers as $id ) {
			$worker = $this->workers[ $id ];
			$worker->drain_pipes();
			if ( ! $worker->is_done() ) {
				// Process hasn't exited yet, keep rocking on.
				continue;
			}

			if ( ! $worker->shutdown() ) {
				$worker->job->mark_failed();
				$logger->log_job_failed( $worker->job, 'Failed to shutdown worker.' );

				/**
				 * Action after a job has failed.
				 *
				 * @param Worker $worker Worker that ran the job.
				 * @param Job $job Job that failed.
				 * @param Logger $logger Logger for the job.
				 */
				$this->hooks->run( 'Runner.check_workers.job_failed', $worker, $worker->job, $logger );
			} else {
				$worker->job->mark_completed();
				$logger->log_job_completed( $worker->job );

				/**
				 * Action after a job has failed.
				 *
				 * @param Worker $worker Worker that ran the job.
				 * @param Job $job Job that completed.
				 * @param Logger $logger Logger for the job.
				 */
				$this->hooks->run( 'Runner.check_workers.job_completed', $worker, $worker->job, $logger );
			}

			unset( $this->workers[ $id ] );
		}
	}
}
