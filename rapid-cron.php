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
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Author: Peter Wilson
 * Author URI: https://peterwilson.cc
 * License: MIT
 * Text Domain: rapid-cron
 */

namespace PWCC\RapidCron;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/inc/namespace.php';

bootstrap();
