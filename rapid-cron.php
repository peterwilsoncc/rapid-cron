<?php
/**
 * Rapid Cron
 *
 * @package           RapidCron
 * @author            Peter Wilson
 * @copyright         YYYY Peter Wilson
 * @license           MIT
 *
 * @wordpress-plugin
 * Plugin Name: Rapid Cron
 * Description: Rapid Cron
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Peter Wilson
 * Author URI: https://peterwilson.cc
 * License: MIT
 * Text Domain: rapid-cron
 */

namespace PWCC\RapidCron;

const DATE_FORMAT = 'Y-m-d H:i:s';

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/inc/namespace.php';
require_once __DIR__ . '/inc/class-job.php';
require_once __DIR__ . '/inc/database.php';
require_once __DIR__ . '/inc/job-storage.php';

bootstrap();
