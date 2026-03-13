<?php
/**
 * Plugin Name:       ASAE Content Ingestor
 * Plugin URI:        https://keithmsoares.com
 * Description:       Reads an RSS/Atom feed and ingests linked articles as a chosen WordPress post type, preserving title, body, author, date, images, tags, and metadata. Supports a URL restriction prefix to filter feed links. Designed for migrating legacy ASAE sites into WordPress.
 * Version:           0.6.10
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
define( 'ASAE_CI_VERSION', '0.6.10' );

/** Absolute path to the plugin root directory (with trailing slash). */
define( 'ASAE_CI_PATH', plugin_dir_path( __FILE__ ) );

/** Public URL to the plugin root directory (with trailing slash). */
define( 'ASAE_CI_URL', plugin_dir_url( __FILE__ ) );

/** Unique prefix used for options, hooks, and DB tables. */
define( 'ASAE_CI_PREFIX', 'asae_ci' );

/** WP Cron hook name used for background batch processing. */
define( 'ASAE_CI_CRON_HOOK', 'asae_ci_process_batch' );

/** Number of articles to ingest per background batch. */
define( 'ASAE_CI_INGEST_BATCH_SIZE', 5 );

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
	'class-asae-ci-youtube.php',
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
	// Run a DB schema upgrade when the stored version differs from the current
	// version. dbDelta() is idempotent and safe to call on every load.
	if ( get_option( 'asae_ci_version' ) !== ASAE_CI_VERSION ) {
		ASAE_CI_DB::create_tables();
		update_option( 'asae_ci_version', ASAE_CI_VERSION );
	}

	// Register WP Cron callback for background batch processing.
	add_action( ASAE_CI_CRON_HOOK, [ 'ASAE_CI_Scheduler', 'process_cron_batch' ] );

	// Serve stored author photos as avatars everywhere WP renders get_avatar().
	// This works regardless of whether Simple Local Avatars is installed.
	add_filter( 'pre_get_avatar_data', 'asae_ci_filter_avatar_data', 10, 2 );

	// Initialise the admin UI (menus, AJAX handlers, enqueue hooks).
	if ( is_admin() ) {
		ASAE_CI_Admin::init();
	}
}
add_action( 'plugins_loaded', 'asae_ci_init' );

/**
 * Supplies a stored author photo URL to WordPress's avatar system.
 *
 * Runs on the 'pre_get_avatar_data' filter so it fires for both the admin
 * user profile screen and any theme location that calls get_avatar().
 * Only activates when the user account was created by this plugin and has
 * a photo stored in the '_asae_ci_author_photo_id' user meta key.
 *
 * @param array $args         Avatar data args passed by WP.
 * @param mixed $id_or_email  User ID, WP_User, WP_Post, or email string.
 * @return array Modified args with the stored photo URL when available.
 */
function asae_ci_filter_avatar_data( array $args, $id_or_email ): array {
	// Resolve the argument to a numeric WP user ID.
	$user_id = 0;
	if ( is_int( $id_or_email ) || ( is_string( $id_or_email ) && ctype_digit( $id_or_email ) ) ) {
		$user_id = (int) $id_or_email;
	} elseif ( $id_or_email instanceof WP_User ) {
		$user_id = (int) $id_or_email->ID;
	} elseif ( $id_or_email instanceof WP_Post ) {
		$user_id = (int) $id_or_email->post_author;
	} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );
		if ( $user ) {
			$user_id = (int) $user->ID;
		}
	}

	if ( ! $user_id ) {
		return $args;
	}

	$attachment_id = (int) get_user_meta( $user_id, '_asae_ci_author_photo_id', true );
	if ( ! $attachment_id ) {
		return $args;
	}

	$size    = ! empty( $args['size'] ) ? (int) $args['size'] : 96;
	$img_src = wp_get_attachment_image_src( $attachment_id, [ $size, $size ] );
	if ( $img_src && ! empty( $img_src[0] ) ) {
		$args['url']          = $img_src[0];
		$args['found_avatar'] = true;
	}

	return $args;
}
