<?php
/**
 * ASAE Content Ingestor – Database Manager
 *
 * Handles creation, upgrades, and removal of custom database tables
 * used by the plugin. All table operations use $wpdb to respect the
 * site's configured database prefix.
 *
 * Tables managed:
 *  - {prefix}asae_ci_jobs         – Active/queued crawl+ingest job data
 *  - {prefix}asae_ci_reports      – Summary record for each completed run
 *  - {prefix}asae_ci_report_items – Per-article rows for each report
 *
 * @package ASAE_Content_Ingestor
 * @since   0.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CI_DB {

	// ── Table Name Helpers ────────────────────────────────────────────────────

	/**
	 * Returns the full table name for the jobs queue.
	 *
	 * @return string
	 */
	public static function jobs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asae_ci_jobs';
	}

	/**
	 * Returns the full table name for run reports.
	 *
	 * @return string
	 */
	public static function reports_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asae_ci_reports';
	}

	/**
	 * Returns the full table name for per-article report rows.
	 *
	 * @return string
	 */
	public static function report_items_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asae_ci_report_items';
	}

	// ── Table Creation ────────────────────────────────────────────────────────

	/**
	 * Creates (or upgrades) all custom database tables.
	 * Called on plugin activation and can be re-called on version upgrades.
	 *
	 * Uses dbDelta() so columns/indexes are only altered when needed;
	 * existing data is never dropped.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Require the dbDelta() function (available only in admin context).
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// ── Jobs table ────────────────────────────────────────────────────────
		// Stores the full state of a crawl+ingest job so it can be resumed
		// across multiple WP Cron invocations.
		$sql_jobs = "CREATE TABLE " . self::jobs_table() . " (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_key       VARCHAR(64)  NOT NULL,
			status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
			run_type      VARCHAR(10)  NOT NULL DEFAULT 'dry',
			source_url    VARCHAR(2048) NOT NULL,
			post_type     VARCHAR(50)  NOT NULL DEFAULT 'post',
			batch_limit   VARCHAR(10)  NOT NULL DEFAULT '50',
			phase         VARCHAR(20)  NOT NULL DEFAULT 'discovery',
			queue_data    LONGTEXT     NOT NULL,
			report_id     BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at    DATETIME     NOT NULL,
			updated_at    DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_key (job_key),
			KEY status (status)
		) $charset_collate;";

		// ── Reports table ─────────────────────────────────────────────────────
		// One row per completed (or dry) run, used for the reports listing page.
		$sql_reports = "CREATE TABLE " . self::reports_table() . " (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			run_date        DATETIME     NOT NULL,
			run_type        VARCHAR(10)  NOT NULL DEFAULT 'active',
			source_url      VARCHAR(2048) NOT NULL,
			post_type       VARCHAR(50)  NOT NULL,
			batch_limit     VARCHAR(10)  NOT NULL,
			status          VARCHAR(20)  NOT NULL DEFAULT 'running',
			total_found     INT(11)      NOT NULL DEFAULT 0,
			total_ingested  INT(11)      NOT NULL DEFAULT 0,
			total_skipped   INT(11)      NOT NULL DEFAULT 0,
			total_failed    INT(11)      NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY run_date (run_date),
			KEY status (status)
		) $charset_collate;";

		// ── Report items table ────────────────────────────────────────────────
		// One row per discovered article within a report.
		$sql_report_items = "CREATE TABLE " . self::report_items_table() . " (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			report_id   BIGINT(20) UNSIGNED NOT NULL,
			source_url  VARCHAR(2048) NOT NULL,
			wp_post_id  BIGINT(20) UNSIGNED DEFAULT NULL,
			post_title  TEXT,
			tags        TEXT,
			item_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			notes       TEXT,
			PRIMARY KEY  (id),
			KEY report_id (report_id),
			KEY item_status (item_status)
		) $charset_collate;";

		dbDelta( $sql_jobs );
		dbDelta( $sql_reports );
		dbDelta( $sql_report_items );

		// Track the DB schema version for future upgrades.
		update_option( 'asae_ci_db_version', ASAE_CI_VERSION );
	}

	// ── Table Removal ─────────────────────────────────────────────────────────

	/**
	 * Drops all custom tables created by this plugin.
	 * Called only from uninstall.php; never from deactivation.
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;

		// Drop in reverse dependency order.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::report_items_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::reports_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::jobs_table() );
		// phpcs:enable
	}
}
