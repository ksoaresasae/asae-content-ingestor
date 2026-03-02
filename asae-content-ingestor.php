<?php
/**
 * Plugin Name:       ASAE Content Ingestor
 * Plugin URI:        https://keithmsoares.com
 * Description:       Crawls content within a specified folder of a remote website and ingests it as a chosen WordPress post type, preserving title, body, author, date, images, tags, and metadata. Designed for migrating legacy ASAE sites into WordPress.
 * Version:           0.0.6
 * Author:            Keith M. Soares
 * Author URI:        https://keithmsoares.com
 * License:           CC
 * Text Domain:       asae-content-ingestor
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Plugin Constants ──────────────────────────────────────────────────────────

/** Semantic version string used throughout the codebase and in the UI. */
define( 'ASAE_CI_VERSION', '0.0.6' );

/** Absolute path to the plugin root directory (with trailing slash). */
define( 'ASAE_CI_PATH', plugin_dir_path( __FILE__ ) );

/** Public URL to the plugin root directory (with trailing slash). */
define( 'ASAE_CI_URL', plugin_dir_url( __FILE__ ) );

/** Unique prefix used for options, hooks, and DB tables. */
define( 'ASAE_CI_PREFIX', 'asae_ci' );

/** WP Cron hook name used for background batch processing. */
define( 'ASAE_CI_CRON_HOOK', 'asae_ci_process_batch' );

/** Number of URLs to crawl per background batch during discovery. */
define( 'ASAE_CI_CRAWL_BATCH_SIZE', 10 );

/** Number of articles to ingest per background batch. */
define( 'ASAE_CI_INGEST_BATCH_SIZE', 5 );

/** Max crawl depth to prevent runaway discovery. */
define( 'ASAE_CI_MAX_CRAWL_DEPTH', 20 );

// ── Autoload Classes ──────────────────────────────────────────────────────────

/**
 * Load all plugin class files from the /includes directory.
 * Files are loaded in dependency order.
 */
$asae_ci_classes = [
	'class-asae-ci-db.php',
	'class-asae-ci-crawler.php',
	'class-asae-ci-parser.php',
	'class-asae-ci-ingester.php',
	'class-asae-ci-scheduler.php',
	'class-asae-ci-reports.php',
	'class-asae-ci-admin.php',
];

foreach ( $asae_ci_classes as $class_file ) {
	$path = ASAE_CI_PATH . 'includes/' . $class_file;
	if ( file_exists( $path ) ) {
		require_once $path;
	}
}

// ── Activation / Deactivation / Uninstall Hooks ───────────────────────────────

/**
 * Runs on plugin activation.
 * Creates custom database tables and sets default options.
 */
function asae_ci_activate() {
	ASAE_CI_DB::create_tables();

	// Store the plugin version on activation for future upgrade checks.
	update_option( 'asae_ci_version', ASAE_CI_VERSION );
}
register_activation_hook( __FILE__, 'asae_ci_activate' );

/**
 * Runs on plugin deactivation.
 * Clears any scheduled WP Cron events to avoid orphaned jobs.
 */
function asae_ci_deactivate() {
	// Remove all scheduled cron events for this plugin.
	$timestamp = wp_next_scheduled( ASAE_CI_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, ASAE_CI_CRON_HOOK );
	}
	wp_clear_scheduled_hook( ASAE_CI_CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'asae_ci_deactivate' );

// ── Plugin Bootstrap ──────────────────────────────────────────────────────────

/**
 * Initialises the plugin on the 'plugins_loaded' hook to ensure
 * WordPress core and all other plugins are fully loaded first.
 */
function asae_ci_init() {
	// Register WP Cron callback for background batch processing.
	add_action( ASAE_CI_CRON_HOOK, [ 'ASAE_CI_Scheduler', 'process_cron_batch' ] );

	// Initialise the admin UI (menus, AJAX handlers, enqueue hooks).
	if ( is_admin() ) {
		ASAE_CI_Admin::init();
	}
}
add_action( 'plugins_loaded', 'asae_ci_init' );
