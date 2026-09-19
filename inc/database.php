<?php
/**
 * Rapid Cron Database creation
 *
 * @package           RapidCron
 */

namespace PWCC\RapidCron\Database;

const DATABASE_VERSION = 1;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Bootstrap the component.
 */
function bootstrap() {
	if ( ! is_installed() && ! create_tables() ) {
		add_action( 'wp_install', __NAMESPACE__ . '\\bootstrap' );
		return;
	}

	maybe_populate_site_option();
}

/**
 * Get a table name for cron storage.
 *
 * @param string $suffix The table suffix to return.
 * @return string The table name with the WP and plugin prefix prepended.
 */
function get_table_name( string $suffix ): string {
	global $wpdb;
	$prefix = 'rapid_cron';
	$prefix = rtrim( $prefix, '_' ) . '_';

	return "{$wpdb->base_prefix}{$prefix}{$suffix}";
}

/**
 * Is the plugin installed?
 *
 * Used during the plugin's bootstrapping process to create the table. This
 * should return true pretty much all the time.
 *
 * @return boolean
 */
function is_installed() {
	global $wpdb;

	if ( get_site_transient( 'rapid_cron_installed' ) ) {
		return true;
	}

	$table_search = get_table_name( '%' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- is cached via a transient.
	$installed = ( count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_search ) ) ) === 2 );

	if ( $installed ) {
		// Don't check for a week.
		set_site_transient( 'rapid_cron_installed', $installed, WEEK_IN_SECONDS );
	}

	return $installed;
}

/**
 * Create the database tables for the plugin.
 *
 * @return bool Whether the tables exist at the end of the function call.
 */
function create_tables(): bool {
	global $wpdb;

	if ( ! is_blog_installed() ) {
		// Do not create tables before blog is installed.
		return false;
	}

	$jobs_table = get_table_name( 'jobs' );
	$logs_table = get_table_name( 'logs' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			"CREATE TABLE IF NOT EXISTS %i (
				`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				`site` bigint(20) unsigned NOT NULL,

				`hook` varchar(255) NOT NULL,
				`args` longtext NOT NULL,

				`start` datetime NOT NULL,
				`next_run` datetime NOT NULL,
				`interval` int unsigned DEFAULT NULL,
				`status` varchar(255) NOT NULL DEFAULT 'waiting',
				`schedule` varchar(255) DEFAULT NULL,

				PRIMARY KEY (`id`),
				KEY `status` (`status`),
				KEY `site` (`site`),
				KEY `hook` (`hook`)
			) ENGINE=InnoDB {$wpdb->get_charset_collate()};\n",
			$jobs_table
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			"CREATE TABLE IF NOT EXISTS %i (
				`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				`job` bigint(20) NOT NULL,
				`status` varchar(255) NOT NULL DEFAULT '',
				`timestamp` datetime NOT NULL,
				`content` longtext NOT NULL,
				PRIMARY KEY (`id`),
				KEY `job` (`job`),
				KEY `status` (`status`)
			) ENGINE=InnoDB {$wpdb->get_charset_collate()};\n",
			$logs_table
		)
	);

	wp_cache_set( 'installed', true, 'rapid_cron' );
	update_site_option( 'rapid_cron_db_version', DATABASE_VERSION );

	/**
	 * Ensure site meta is populated when running the WP CLI script to
	 * install a network. Using the CLI, WP installs a single site with
	 * wp_install() and then upgrades it to a multiste install immediately.
	 *
	 * Note: This does not work for multisite manual installs.
	 */
	add_filter(
		'populate_network_meta',
		function ( $site_meta ) {
			$site_meta['rapid_cron_db_version'] = DATABASE_VERSION;
			return $site_meta;
		}
	);
	return true;
}

/**
 * Populate the Rapid Cron db version when upgrading to multisite.
 *
 * This ensures the database option is copied from the options table
 * accross to the sitemeta table when WordPress is upgraded from
 * a single site install to a multisite install.
 */
function maybe_populate_site_option() {
	if ( is_multisite() ) {
		return;
	}

	$set_site_meta = function ( $site_meta ) {
		$site_meta['rapid_cron_db_version'] = get_option( 'rapid_cron_db_version' );
		return $site_meta;
	};

	add_filter( 'populate_network_meta', $set_site_meta );
}
