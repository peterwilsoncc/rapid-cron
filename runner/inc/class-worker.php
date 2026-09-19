<?php
/**
 * Rapid Cron Runner Worker
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Runner;

/**
 * Runner worker class.
 */
class Worker {
	/**
	 * Worker process.
	 *
	 * @var resource|false
	 */
	public $process;

	/**
	 * Pipes.
	 *
	 * @var array
	 */
	public $pipes = array();

	/**
	 * Cavalcade job.
	 *
	 * @var Job
	 */
	public $job;

	/**
	 * Output from job.
	 *
	 * @var string
	 */
	public $output = '';

	/**
	 * Error output from job.
	 *
	 * @var string
	 */
	public $error_output = '';

	/**
	 * Job status.
	 *
	 * @var string|null
	 */
	public $status = null;

	/**
	 * Constructor.
	 *
	 * @param resource $process Worker Process.
	 * @param array    $pipes Pipes.
	 * @param Job      $job The cron job.
	 */
	public function __construct( $process, $pipes, Job $job ) {
		$this->process = $process;
		$this->pipes   = $pipes;
		$this->job     = $job;
	}

	/**
	 * Determine if job is done.
	 *
	 * @return bool Whether the job is complete.
	 */
	public function is_done() {
		if ( isset( $this->status['running'] ) && ! $this->status['running'] ) {
			// Already exited, so don't try and fetch again,
			// exit code is only valid the first time after it exits.
			return ! ( $this->status['running'] );
		}

		$this->status = proc_get_status( $this->process );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		printf( '[%d] Worker status: %s' . PHP_EOL, $this->job->id, print_r( $this->status, true ) );
		return ! ( $this->status['running'] );
	}

	/**
	 * Drain stdout & stderr into properties.
	 *
	 * Draining the pipes is needed to avoid workers hanging when they hit the system pipe buffer limits.
	 */
	public function drain_pipes() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions, Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( $data = fread( $this->pipes[1], 1024 ) ) {
			$this->output .= $data;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions, Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( $data = fread( $this->pipes[2], 1024 ) ) {
			$this->error_output .= $data;
		}
	}

	/**
	 * Shut down the process
	 *
	 * @return bool Did the process run successfully?
	 */
	public function shutdown() {
		printf( '[%d] Worker shutting down...' . PHP_EOL, $this->job->id );

		// Exhaust the streams.
		$this->drain_pipes();
		fclose( $this->pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $this->pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		printf( '[%d] Worker out: %s' . PHP_EOL, $this->job->id, $this->output );
		printf( '[%d] Worker err: %s' . PHP_EOL, $this->job->id, $this->error_output );
		printf( '[%d] Worker ret: %d' . PHP_EOL, $this->job->id, $this->status['exitcode'] );

		// Close the process down too.
		proc_close( $this->process );
		unset( $this->process );

		return ( 0 === $this->status['exitcode'] );
	}
}
