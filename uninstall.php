<?php
/**
 * ASAE Content Ingestor – Uninstall Script
 *
 * Runs automatically when a site administrator deletes the plugin via the
 * WP Plugins screen. Permanently removes all plugin data:
 *
 *  - Custom database tables (jobs, reports, report_items).
 *  - All wp_options entries created by the plugin.
 *  - All scheduled WP Cron events registered by the plugin.
 *
 * NOTE: Post meta (_asae_ci_source_url) on ingested posts is intentionally
 * NOT removed here because the ingested content itself remains in WordPress.
 * Removing the source-URL markers would prevent duplicate detection if the
 * plugin is reinstalled later.
 *
 * @package ASAE_Content_Ingestor
 * @since   0.0.1
 */

// WordPress sets this constant before calling uninstall.php.
// Abort immediately if called directly (not via WordPress uninstall flow).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ── Load dependencies ────────────────────────────────────────────────────────

// We can't rely on plugins_loaded having fired, so we load what we need directly.
$plugin_dir = plugin_dir_path( __FILE__ );
require_once $plugin_dir . 'includes/class-asae-ci-db.php';

// The ASAE_CI_CRON_HOOK constant may not be set yet (main file not loaded),
// so define it locally for the uninstall context.
if ( ! defined( 'ASAE_CI_CRON_HOOK' ) ) {
	define( 'ASAE_CI_CRON_HOOK', 'asae_ci_process_batch' );
}

// ── Clear WP Cron events ─────────────────────────────────────────────────────

wp_clear_scheduled_hook( ASAE_CI_CRON_HOOK );

// ── Remove wp_options entries ─────────────────────────────────────────────────

$options_to_delete = [
	'asae_ci_version',
	'asae_ci_db_version',
	'asae_ci_youtube_api_key',
	'asae_ci_youtube_channel_id',
];

foreach ( $options_to_delete as $option ) {
	delete_option( $option );
}

// ── Drop custom database tables ───────────────────────────────────────────────

ASAE_CI_DB::drop_tables();
