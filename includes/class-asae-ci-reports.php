<?php
/**
 * ASAE Content Ingestor – Reports Manager
 *
 * CRUD operations for the two custom report tables:
 *  - asae_ci_reports       – One row per run (summary header).
 *  - asae_ci_report_items  – One row per article processed within a run.
 *
 * Reports are retained indefinitely so admins can audit past ingestions.
 * The admin UI lists them in reverse-chronological order and allows
 * paginated browsing of individual report items.
 *
 * @package ASAE_Content_Ingestor
 * @since   0.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CI_Reports {

	// ── Report Record CRUD ────────────────────────────────────────────────────

	/**
	 * Inserts a new report record and returns its ID.
	 *
	 * @param array $data {
	 *   @type string      $run_date         MySQL datetime string.
	 *   @type string      $run_type         'dry' or 'active'.
	 *   @type string      $source_url       The RSS feed URL.
	 *   @type string|null $url_restriction  Optional URL prefix restriction.
	 *   @type string      $post_type        WP post type used.
	 *   @type string      $batch_limit      '10', '50', '100', or 'all'.
	 *   @type string      $status           'running', 'completed', or 'failed'.
	 * }
	 * @return int|WP_Error New report ID or WP_Error on failure.
	 */
	public static function create_report( array $data ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			ASAE_CI_DB::reports_table(),
			[
				'run_date'        => $data['run_date']         ?? current_time( 'mysql' ),
				'run_type'        => $data['run_type']         ?? 'active',
				'source_url'      => $data['source_url']       ?? '',
				'url_restriction' => $data['url_restriction']  ?? null,
				'post_type'       => $data['post_type']        ?? 'post',
				'batch_limit'     => $data['batch_limit']      ?? '50',
				'status'          => $data['status']           ?? 'running',
				'total_found'     => 0,
				'total_ingested'  => 0,
				'total_skipped'   => 0,
				'total_failed'    => 0,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ]
		);
		// phpcs:enable

		if ( ! $inserted ) {
			return new WP_Error( 'asae_ci_db_error', 'Failed to create report record.' );
		}

		return $wpdb->insert_id;
	}

	/**
	 * Updates specified fields on an existing report record.
	 *
	 * @param int   $report_id The report ID.
	 * @param array $data      Field => value pairs to update.
	 * @return bool
	 */
	public static function update_report( int $report_id, array $data ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			ASAE_CI_DB::reports_table(),
			$data,
			[ 'id' => $report_id ]
		);
		// phpcs:enable
		return false !== $result;
	}

	/**
	 * Retrieves a single report record by ID.
	 *
	 * @param int $report_id The report ID.
	 * @return array|null Report data or null if not found.
	 */
	public static function get_report( int $report_id ): ?array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . ASAE_CI_DB::reports_table() . ' WHERE id = %d',
				$report_id
			),
			ARRAY_A
		);
		// phpcs:enable
		return $row ?: null;
	}

	/**
	 * Retrieves a paginated list of all reports, newest first.
	 *
	 * @param int $page     Page number (1-based).
	 * @param int $per_page Items per page.
	 * @return array {
	 *   @type array $items  Array of report row arrays.
	 *   @type int   $total  Total number of report records.
	 * }
	 */
	public static function get_reports( int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$offset = ( max( 1, $page ) - 1 ) * $per_page;
		$table  = ASAE_CI_DB::reports_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY run_date DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable

		return [
			'items' => $items ?: [],
			'total' => $total,
		];
	}

	/**
	 * Deletes a report record and all its associated items.
	 *
	 * @param int $report_id The report ID.
	 * @return bool
	 */
	public static function delete_report( int $report_id ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( ASAE_CI_DB::report_items_table(), [ 'report_id' => $report_id ], [ '%d' ] );
		$result = $wpdb->delete( ASAE_CI_DB::reports_table(), [ 'id' => $report_id ], [ '%d' ] );
		// phpcs:enable

		return false !== $result;
	}

	// ── Report Item CRUD ──────────────────────────────────────────────────────

	/**
	 * Appends one item (article) row to a report.
	 *
	 * @param int   $report_id The parent report ID.
	 * @param array $item {
	 *   @type string      $source_url   Original article URL.
	 *   @type int|null    $wp_post_id   Created post ID (null if not ingested).
	 *   @type string      $post_title   Article title.
	 *   @type string|null $post_author  Author name extracted from the article.
	 *   @type string|null $post_date    Publication date (Y-m-d H:i:s) from the article.
	 *   @type string      $tags         Comma-separated tag list.
	 *   @type string      $item_status  'ingested', 'skipped', 'failed', or 'dry'.
	 *   @type string      $notes        Optional notes.
	 * }
	 * @return int|WP_Error New item ID or WP_Error.
	 */
	public static function add_report_item( int $report_id, array $item ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			ASAE_CI_DB::report_items_table(),
			[
				'report_id'   => $report_id,
				'source_url'  => $item['source_url']  ?? '',
				'wp_post_id'  => $item['wp_post_id']  ?? null,
				'post_title'  => $item['post_title']  ?? '',
				'post_author' => $item['post_author']  ?? null,
				'post_date'   => $item['post_date']    ?? null,
				'tags'        => $item['tags']         ?? '',
				'item_status' => $item['item_status']  ?? 'pending',
				'notes'       => $item['notes']        ?? '',
			],
			[ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		// phpcs:enable

		if ( ! $inserted ) {
			return new WP_Error( 'asae_ci_db_error', 'Failed to insert report item.' );
		}

		// Keep the report's ingested/failed counters in sync.
		self::increment_report_counter( $report_id, $item['item_status'] ?? '' );

		return $wpdb->insert_id;
	}

	/**
	 * Returns a paginated list of items for a given report.
	 *
	 * @param int $report_id The parent report ID.
	 * @param int $page      Page number (1-based).
	 * @param int $per_page  Items per page.
	 * @return array {
	 *   @type array $items  Array of item row arrays.
	 *   @type int   $total  Total number of items for this report.
	 * }
	 */
	public static function get_report_items( int $report_id, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		$offset     = ( max( 1, $page ) - 1 ) * $per_page;
		$items_tbl  = ASAE_CI_DB::report_items_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$items_tbl} WHERE report_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$report_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$items_tbl} WHERE report_id = %d",
				$report_id
			)
		);
		// phpcs:enable

		return [
			'items' => $items ?: [],
			'total' => $total,
		];
	}

	// ── Private Helpers ───────────────────────────────────────────────────────

	/**
	 * Increments the appropriate counter column on the parent report based on
	 * the item's status ('ingested', 'skipped', 'failed', 'dry').
	 *
	 * @param int    $report_id The report ID.
	 * @param string $status    The item status string.
	 * @return void
	 */
	private static function increment_report_counter( int $report_id, string $status ): void {
		global $wpdb;

		$column_map = [
			'ingested' => 'total_ingested',
			'skipped'  => 'total_skipped',
			'failed'   => 'total_failed',
			'dry'      => 'total_found',
		];

		$column = $column_map[ $status ] ?? null;
		if ( ! $column ) {
			return;
		}

		$table = ASAE_CI_DB::reports_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET {$column} = {$column} + 1 WHERE id = %d",
				$report_id
			)
		);
		// phpcs:enable
	}
}
