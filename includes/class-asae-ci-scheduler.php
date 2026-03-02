<?php
/**
 * ASAE Content Ingestor – Job Scheduler
 *
 * Manages the two-phase crawl + ingest job lifecycle:
 *
 *  Phase 1 – DISCOVERY: The crawler finds all article URLs within the target folder.
 *  Phase 2 – INGESTION: Each discovered URL is fetched, parsed, and ingested into WP.
 *
 * For Active Runs, processing runs in batches via WP Cron so that large sites can be
 * handled without PHP time-limit issues. Admins can also drive processing manually
 * via the admin AJAX endpoint (useful for keeping the browser's progress view live).
 *
 * Job state is persisted in a custom DB table (asae_ci_jobs) to survive page loads.
 *
 * @package ASAE_Content_Ingestor
 * @since   0.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CI_Scheduler {

	// ── Job Creation ──────────────────────────────────────────────────────────

	/**
	 * Creates a new job record in the database and (for Active Runs) schedules
	 * the first WP Cron event to kick off background processing.
	 *
	 * @param array $args {
	 *   @type string $source_url       RSS feed URL to read (required).
	 *   @type string $url_restriction  Optional URL prefix to filter feed links.
	 *   @type string $post_type        WP post type for ingested content.
	 *   @type string $run_type         'dry' or 'active'.
	 *   @type string $batch_limit      '10', '50', '100', or 'all'.
	 * }
	 * @return string|WP_Error Unique job key on success, WP_Error on failure.
	 */
	public static function create_job( array $args ) {
		global $wpdb;

		$source_url      = esc_url_raw( $args['source_url']      ?? '' );
		$url_restriction = esc_url_raw( $args['url_restriction'] ?? '' );
		$post_type       = sanitize_key( $args['post_type']       ?? 'post' );
		$run_type        = in_array( $args['run_type'] ?? 'dry', [ 'dry', 'active' ], true )
		                   ? $args['run_type'] : 'dry';
		$batch_limit     = in_array( $args['batch_limit'] ?? '50', [ '10', '50', '100', 'all' ], true )
		                   ? $args['batch_limit'] : '50';

		if ( empty( $source_url ) ) {
			return new WP_Error( 'asae_ci_no_url', 'A source URL is required to create a job.' );
		}

		// Build the integer limit for the crawler (0 = unlimited).
		$limit_int = self::batch_limit_to_int( $batch_limit, $run_type );

		// For Dry Runs, always cap at 50.
		if ( 'dry' === $run_type ) {
			$limit_int = 50;
		}

		// Build initial discovery queue state (includes url_restriction).
		$initial_queue = ASAE_CI_Crawler::build_initial_queue( $source_url, $limit_int, $url_restriction );

		// Initial job queue_data blob.
		$queue_data = [
			'discovery'   => $initial_queue,
			'ingestion'   => [
				'queue'     => [],  // Populated after discovery completes.
				'processed' => 0,
				'failed'    => 0,
			],
			'dry_results' => [],    // Populated during Dry Run preview.
		];

		$now     = current_time( 'mysql' );
		$job_key = 'job_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			ASAE_CI_DB::jobs_table(),
			[
				'job_key'     => $job_key,
				'status'      => 'running',
				'run_type'    => $run_type,
				'source_url'  => $source_url,
				'post_type'   => $post_type,
				'batch_limit' => $batch_limit,
				'phase'       => 'discovery',
				'queue_data'  => wp_json_encode( $queue_data ),
				'report_id'   => null,
				'created_at'  => $now,
				'updated_at'  => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
		// phpcs:enable

		if ( ! $inserted ) {
			return new WP_Error( 'asae_ci_db_error', 'Failed to create job record.' );
		}

		$job_id = $wpdb->insert_id;

		// Create a report record so items can be logged as they are processed.
		$report_id = ASAE_CI_Reports::create_report( [
			'run_date'        => $now,
			'run_type'        => $run_type,
			'source_url'      => $source_url,
			'url_restriction' => $url_restriction ?: null,
			'post_type'       => $post_type,
			'batch_limit'     => $batch_limit,
			'status'          => 'running',
		] );

		if ( $report_id && ! is_wp_error( $report_id ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				ASAE_CI_DB::jobs_table(),
				[ 'report_id' => $report_id ],
				[ 'id' => $job_id ],
				[ '%d' ],
				[ '%d' ]
			);
			// phpcs:enable
		}

		// For Active Runs, schedule the first cron batch immediately.
		if ( 'active' === $run_type ) {
			wp_schedule_single_event( time(), ASAE_CI_CRON_HOOK, [ $job_key ] );
		}

		return $job_key;
	}

	// ── Batch Processing (Admin AJAX – drives progress while browser is open) ─

	/**
	 * Processes a single batch for the given job and returns a progress snapshot.
	 * Called repeatedly from the admin JS polling loop.
	 *
	 * @param string $job_key The unique job key.
	 * @return array|WP_Error Progress data or WP_Error.
	 */
	public static function process_ajax_batch( string $job_key ) {
		$job = self::get_job( $job_key );
		if ( ! $job ) {
			return new WP_Error( 'asae_ci_no_job', 'Job not found.' );
		}

		if ( 'running' !== $job['status'] ) {
			return self::build_progress_response( $job );
		}

		$queue_data = json_decode( $job['queue_data'], true );

		if ( 'discovery' === $job['phase'] ) {
			$queue_data = self::run_discovery_batch( $queue_data, $job );
		} elseif ( 'ingestion' === $job['phase'] ) {
			$queue_data = self::run_ingestion_batch( $queue_data, $job );
		} elseif ( 'dry' === $job['phase'] ) {
			$queue_data = self::run_dry_batch( $queue_data, $job );
		}

		// Refresh job data after updates.
		$job = self::get_job( $job_key );

		return self::build_progress_response( $job, $queue_data );
	}

	// ── Batch Processing (WP Cron – background, no browser required) ──────────

	/**
	 * WP Cron callback. Processes one batch and reschedules itself if more work remains.
	 *
	 * @param string $job_key The unique job key.
	 * @return void
	 */
	public static function process_cron_batch( string $job_key ): void {
		$job = self::get_job( $job_key );
		if ( ! $job || 'running' !== $job['status'] ) {
			return;
		}

		$queue_data = json_decode( $job['queue_data'], true );

		if ( 'discovery' === $job['phase'] ) {
			$queue_data = self::run_discovery_batch( $queue_data, $job );
		} elseif ( 'ingestion' === $job['phase'] ) {
			$queue_data = self::run_ingestion_batch( $queue_data, $job );
		}

		// Refresh and reschedule if still running.
		$job = self::get_job( $job_key );
		if ( $job && 'running' === $job['status'] ) {
			wp_schedule_single_event( time() + 5, ASAE_CI_CRON_HOOK, [ $job_key ] );
		}
	}

	// ── Internal Phase Runners ────────────────────────────────────────────────

	/**
	 * Runs the RSS feed discovery step and immediately advances the job phase
	 * to 'ingestion' (or 'dry') once the feed has been read.
	 *
	 * Unlike the former BFS crawler, RSS discovery completes in a single
	 * fetch_feed() call, so this method finishes discovery entirely before
	 * returning.
	 *
	 * @param array $queue_data Full queue data from the job record.
	 * @param array $job        Current job record.
	 * @return array Updated queue_data.
	 */
	private static function run_discovery_batch( array $queue_data, array $job ): array {
		$disc = $queue_data['discovery'] ?? [];

		// Fetch all URLs from the RSS/Atom feed in a single request.
		$feed_url        = $disc['feed_url']        ?? '';
		$url_restriction = $disc['url_restriction'] ?? '';
		$limit_int       = self::batch_limit_to_int( $job['batch_limit'], $job['run_type'] );
		if ( 'dry' === $job['run_type'] ) {
			$limit_int = 50;
		}

		$urls = ASAE_CI_Crawler::fetch_feed_urls( $feed_url, $url_restriction, $limit_int );

		if ( is_wp_error( $urls ) ) {
			// Mark the job as failed if the feed could not be fetched.
			self::update_job( $job['job_key'], [ 'status' => 'failed' ] );
			if ( $job['report_id'] ) {
				ASAE_CI_Reports::update_report( (int) $job['report_id'], [ 'status' => 'failed' ] );
			}
			return $queue_data;
		}

		// Mark the feed as fetched and store the discovered URLs.
		$disc['feed_fetched'] = true;
		$disc['content_urls'] = $urls;
		$queue_data['discovery'] = $disc;

		// Move discovered URLs into the ingestion queue.
		$content_urls = $urls;

		if ( 'active' === $job['run_type'] ) {
			$queue_data['ingestion']['queue']     = $content_urls;
			$queue_data['ingestion']['processed'] = 0;
			$queue_data['ingestion']['failed']    = 0;
			self::update_job( $job['job_key'], [
				'phase'      => 'ingestion',
				'queue_data' => wp_json_encode( $queue_data ),
			] );

			// Update report total_found count.
			if ( $job['report_id'] ) {
				ASAE_CI_Reports::update_report( (int) $job['report_id'], [
					'total_found' => count( $content_urls ),
				] );
			}
		} else {
			// Dry run: switch to dry phase for preview generation.
			$queue_data['ingestion']['queue']     = $content_urls;
			$queue_data['ingestion']['processed'] = 0;
			self::update_job( $job['job_key'], [
				'phase'      => 'dry',
				'queue_data' => wp_json_encode( $queue_data ),
			] );
		}

		return $queue_data;
	}

	/**
	 * Runs one batch of content ingestion (Active Run).
	 *
	 * @param array $queue_data Full queue data.
	 * @param array $job        Current job record.
	 * @return array Updated queue_data.
	 */
	private static function run_ingestion_batch( array $queue_data, array $job ): array {
		$ingest = $queue_data['ingestion'] ?? [];
		$queue  = $ingest['queue']   ?? [];
		$done   = (int) ( $ingest['processed'] ?? 0 );
		$failed = (int) ( $ingest['failed']    ?? 0 );

		$count = 0;

		while ( ! empty( $queue ) && $count < ASAE_CI_INGEST_BATCH_SIZE ) {
			$url = array_shift( $queue );
			$count++;

			// Fetch the page.
			$response = wp_remote_get( $url, [
				'timeout'    => 30,
				'user-agent' => 'ASAE Content Ingestor/' . ASAE_CI_VERSION,
			] );

			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 ) {
				$failed++;
				self::log_report_item( $job, $url, null, [], 'failed',
					is_wp_error( $response ) ? $response->get_error_message() : 'HTTP error' );
				continue;
			}

			$html = wp_remote_retrieve_body( $response );
			if ( empty( $html ) ) {
				$failed++;
				self::log_report_item( $job, $url, null, [], 'failed', 'Empty response body.' );
				continue;
			}

			// Parse the article.
			$parsed = ASAE_CI_Parser::parse( $url, $html );

			// Ingest into WordPress.
			$post_id = ASAE_CI_Ingester::ingest( $parsed, $job['post_type'] );

			if ( is_wp_error( $post_id ) ) {
				$status = 'duplicate' === $post_id->get_error_code() ? 'skipped' : 'failed';
				self::log_report_item( $job, $url, null, $parsed['tags'] ?? [], $status,
					$post_id->get_error_message(), $parsed['title'] ?? '',
					$parsed['author'] ?? '', $parsed['date'] ?? '' );
				if ( 'failed' === $status ) {
					$failed++;
				}
			} else {
				$done++;
				self::log_report_item( $job, $url, $post_id, $parsed['tags'] ?? [], 'ingested',
					'', $parsed['title'] ?? '', $parsed['author'] ?? '', $parsed['date'] ?? '' );
			}
		}

		// Update queue state.
		$ingest['queue']     = $queue;
		$ingest['processed'] = $done;
		$ingest['failed']    = $failed;
		$queue_data['ingestion'] = $ingest;

		if ( empty( $queue ) ) {
			// Ingestion is complete – finalise the job.
			self::finalise_job( $job, $done, $failed );
		} else {
			self::update_job( $job['job_key'], [
				'queue_data' => wp_json_encode( $queue_data ),
			] );
		}

		return $queue_data;
	}

	/**
	 * Runs one batch of Dry Run preview generation.
	 * Fetches and parses articles without creating any WP posts.
	 *
	 * @param array $queue_data Full queue data.
	 * @param array $job        Current job record.
	 * @return array Updated queue_data.
	 */
	private static function run_dry_batch( array $queue_data, array $job ): array {
		$ingest      = $queue_data['ingestion']   ?? [];
		$queue       = $ingest['queue']            ?? [];
		$done        = (int) ( $ingest['processed'] ?? 0 );
		$dry_results = $queue_data['dry_results']  ?? [];

		$count = 0;

		while ( ! empty( $queue ) && $count < ASAE_CI_INGEST_BATCH_SIZE ) {
			$url = array_shift( $queue );
			$count++;

			$response = wp_remote_get( $url, [
				'timeout'    => 30,
				'user-agent' => 'ASAE Content Ingestor/' . ASAE_CI_VERSION,
			] );

			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 ) {
				$dry_results[] = [
					'source_url' => $url,
					'post_title' => '(Error fetching page)',
					'tags'       => [],
					'status'     => 'error',
				];
				$done++;
				continue;
			}

			$html   = wp_remote_retrieve_body( $response );
			$parsed = ASAE_CI_Parser::parse( $url, $html );
			$preview = ASAE_CI_Ingester::dry_run_preview( $parsed, $job['post_type'] );

			$dry_results[] = $preview;
			$done++;

			// Log to the report as a dry item (no post created).
			self::log_report_item( $job, $url, null, $preview['tags'], 'dry',
				$preview['is_duplicate'] ? 'Would be skipped (duplicate).' : '',
				$preview['post_title'], $preview['author'] ?? '', $preview['date'] ?? '' );
		}

		$ingest['queue']          = $queue;
		$ingest['processed']      = $done;
		$queue_data['ingestion']  = $ingest;
		$queue_data['dry_results'] = $dry_results;

		if ( empty( $queue ) ) {
			// Dry run complete.
			self::update_job( $job['job_key'], [
				'status'     => 'completed',
				'queue_data' => wp_json_encode( $queue_data ),
			] );
			if ( $job['report_id'] ) {
				ASAE_CI_Reports::update_report( (int) $job['report_id'], [
					'status'       => 'completed',
					'total_found'  => count( $dry_results ),
					'total_ingested' => 0,
				] );
			}
		} else {
			self::update_job( $job['job_key'], [
				'queue_data' => wp_json_encode( $queue_data ),
			] );
		}

		return $queue_data;
	}

	// ── Job Helpers ───────────────────────────────────────────────────────────

	/**
	 * Retrieves a job record by its unique key.
	 *
	 * @param string $job_key The job key.
	 * @return array|null Job data array or null if not found.
	 */
	public static function get_job( string $job_key ): ?array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . ASAE_CI_DB::jobs_table() . ' WHERE job_key = %s',
				$job_key
			),
			ARRAY_A
		);
		// phpcs:enable
		return $row ?: null;
	}

	/**
	 * Updates specified fields on a job record.
	 *
	 * @param string $job_key The job key.
	 * @param array  $data    Field => value pairs to update.
	 * @return void
	 */
	public static function update_job( string $job_key, array $data ): void {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			ASAE_CI_DB::jobs_table(),
			$data,
			[ 'job_key' => $job_key ]
		);
		// phpcs:enable
	}

	/**
	 * Marks the job as completed and finalises the associated report.
	 *
	 * @param array $job    Job record.
	 * @param int   $done   Number of successfully ingested items.
	 * @param int   $failed Number of failed items.
	 * @return void
	 */
	private static function finalise_job( array $job, int $done, int $failed ): void {
		self::update_job( $job['job_key'], [ 'status' => 'completed' ] );

		if ( $job['report_id'] ) {
			ASAE_CI_Reports::update_report( (int) $job['report_id'], [
				'status'          => 'completed',
				'total_ingested'  => $done,
				'total_failed'    => $failed,
			] );
		}
	}

	/**
	 * Logs a processed item to the report table.
	 *
	 * @param array    $job         Current job record.
	 * @param string   $source_url  The article URL.
	 * @param int|null $wp_post_id  The WP post ID (null if not ingested).
	 * @param array    $tags        Tags array.
	 * @param string   $status      'ingested', 'skipped', 'failed', or 'dry'.
	 * @param string   $notes       Optional notes for the report.
	 * @param string   $post_title  Article title.
	 * @param string   $post_author Author name as extracted from the article.
	 * @param string   $post_date   Publication date (Y-m-d H:i:s) from the article.
	 * @return void
	 */
	private static function log_report_item( array $job, string $source_url, ?int $wp_post_id,
	                                          array $tags, string $status, string $notes = '',
	                                          string $post_title = '', string $post_author = '',
	                                          string $post_date = '' ): void {
		if ( empty( $job['report_id'] ) ) {
			return;
		}

		ASAE_CI_Reports::add_report_item( (int) $job['report_id'], [
			'source_url'  => $source_url,
			'wp_post_id'  => $wp_post_id,
			'post_title'  => $post_title,
			'post_author' => $post_author ?: null,
			'post_date'   => $post_date   ?: null,
			'tags'        => implode( ', ', $tags ),
			'item_status' => $status,
			'notes'       => $notes,
		] );
	}

	// ── Progress Response Builder ─────────────────────────────────────────────

	/**
	 * Builds a normalised progress array suitable for returning in an AJAX response.
	 *
	 * @param array      $job        Current job record.
	 * @param array|null $queue_data Optional fresh queue_data (to avoid re-decoding).
	 * @return array
	 */
	private static function build_progress_response( array $job, ?array $queue_data = null ): array {
		if ( null === $queue_data ) {
			$queue_data = json_decode( $job['queue_data'], true );
		}

		$disc    = $queue_data['discovery']   ?? [];
		$ingest  = $queue_data['ingestion']   ?? [];
		$dry_res = $queue_data['dry_results'] ?? [];

		// Map RSS feed_fetched (bool) to crawled/to_crawl counts for the UI.
		// The discovery bar advances from 0 → 100% once the feed is read.
		$feed_fetched  = (bool) ( $disc['feed_fetched'] ?? false );
		$content_found = count( $disc['content_urls'] ?? [] );
		$queue_count   = count( $ingest['queue']      ?? [] );
		$processed     = (int) ( $ingest['processed'] ?? 0 );
		$failed        = (int) ( $ingest['failed']    ?? 0 );

		return [
			'job_key'         => $job['job_key'],
			'status'          => $job['status'],
			'phase'           => $job['phase'],
			'run_type'        => $job['run_type'],
			'report_id'       => $job['report_id'],
			'crawled'         => $feed_fetched ? 1 : 0,
			'to_crawl'        => $feed_fetched ? 0 : 1,
			'content_found'   => $content_found,
			'queue_remaining' => $queue_count,
			'processed'       => $processed,
			'failed'          => $failed,
			'dry_results'     => 'dry' === $job['run_type'] ? $dry_res : [],
			'is_complete'     => 'completed' === $job['status'] || 'failed' === $job['status'],
		];
	}

	// ── Utility ───────────────────────────────────────────────────────────────

	/**
	 * Converts a batch_limit string value to an integer.
	 * 'all' returns 0 (no limit). For Dry Runs the caller enforces a cap of 50.
	 *
	 * @param string $batch_limit '10', '50', '100', or 'all'.
	 * @param string $run_type    'dry' or 'active'.
	 * @return int
	 */
	public static function batch_limit_to_int( string $batch_limit, string $run_type = 'active' ): int {
		if ( 'dry' === $run_type ) {
			return 50;
		}
		if ( 'all' === $batch_limit ) {
			return 0;
		}
		$int = (int) $batch_limit;
		return max( 0, $int );
	}
}
