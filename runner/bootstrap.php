<?php
/**
 * Bootstrap runner.
 *
 * @package RapidCron
 */

namespace PWCC\RapidCron\Runner;

define( __NAMESPACE__ . '\\PATH', __DIR__ );

/**
 * Class autoloader.
 *
 * @param string $class_name Class to autoload.
 */
function autoload( $class_name ) {
	if ( strpos( $class_name, __NAMESPACE__ ) !== 0 ) {
		return;
	}

	$file = str_replace( __NAMESPACE__ . '\\', '', $class_name );
	$file = str_replace( '\\', DIRECTORY_SEPARATOR, $file );
	include __DIR__ . '/inc/class-' . strtolower( $file ) . '.php';
}

spl_autoload_register( __NAMESPACE__ . '\\autoload' );
