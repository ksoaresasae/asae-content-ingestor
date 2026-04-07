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
	 *   @type string $batch_limit      '10', '50', '100', '1000', or 'all'.
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
		$batch_limit     = in_array( $args['batch_limit'] ?? '50', [ '10', '50', '100', '1000', 'all' ], true )
		                   ? $args['batch_limit'] : '50';

		if ( empty( $source_url ) ) {
			return new WP_Error( 'asae_ci_no_url', 'A source URL is required to create a job.' );
		}

		// Build initial discovery queue state (limit=0: discovery always fetches all
		// feed URLs; batch_limit is enforced during ingestion to find N genuinely new items).
		$initial_queue = ASAE_CI_Crawler::build_initial_queue( $source_url, 0, $url_restriction );

		$additional_tags = sanitize_text_field( $args['additional_tags'] ?? '' );
		$source_type     = in_array( $args['source_type'] ?? 'replace', [ 'replace', 'mirror' ], true )
		                   ? $args['source_type'] : 'replace';
		$content_area_ids = array_values( array_filter( array_map( 'absint', (array) ( $args['content_area_ids'] ?? [] ) ) ) );

		// Initial job queue_data blob.
		$queue_data = [
			'discovery'        => $initial_queue,
			'ingestion'        => [
				'queue'     => [],  // Populated after discovery completes.
				'processed' => 0,
				'failed'    => 0,
			],
			'dry_results'      => [],    // Populated during Dry Run preview.
			'additional_tags'  => $additional_tags, // Batch-level tags applied to every item.
			'pending_review'   => [],    // Items that need manual category assignment.
			'source_type'      => $source_type,     // 'replace' or 'mirror'.
			'content_area_ids' => $content_area_ids, // Applied to every ingested item.
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

	// ── Bulk Assign Content Areas Job ─────────────────────────────────────────

	/**
	 * Creates a new bulk-assign-content-areas job.
	 *
	 * @param array $args {
	 *   @type string $post_type   WP post type to update (e.g. 'post').
	 *   @type string $filter_mode 'all' | 'none' | 'has_any' | 'has_term'.
	 *   @type int    $filter_term Term ID for 'has_term' filter.
	 *   @type int[]  $target_ids  Content Area term IDs to assign (replace mode).
	 * }
	 * @return string|WP_Error
	 */
	public static function create_bulk_assign_areas_job( array $args ) {
		global $wpdb;

		$post_type   = sanitize_key( $args['post_type'] ?? 'post' );
		$filter_mode = in_array( $args['filter_mode'] ?? 'all', [ 'all', 'none', 'has_any', 'has_term' ], true )
			? $args['filter_mode']
			: 'all';
		$filter_term = (int) ( $args['filter_term'] ?? 0 );
		$target_ids  = array_values( array_filter( array_map( 'absint', (array) ( $args['target_ids'] ?? [] ) ) ) );

		if ( empty( $target_ids ) ) {
			return new WP_Error( 'asae_ci_no_targets', 'At least one target Content Area is required.' );
		}

		// Build the post ID list using a tax_query if needed.
		$query_args = [
			'post_type'      => $post_type,
			'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		];

		$tax = ASAE_CI_Admin::CONTENT_AREA_TAXONOMY;
		if ( 'has_any' === $filter_mode ) {
			$query_args['tax_query'] = [
				[
					'taxonomy' => $tax,
					'operator' => 'EXISTS',
				],
			];
		} elseif ( 'none' === $filter_mode ) {
			$query_args['tax_query'] = [
				[
					'taxonomy' => $tax,
					'operator' => 'NOT EXISTS',
				],
			];
		} elseif ( 'has_term' === $filter_mode && $filter_term > 0 ) {
			$query_args['tax_query'] = [
				[
					'taxonomy' => $tax,
					'field'    => 'term_id',
					'terms'    => [ $filter_term ],
				],
			];
		}

		$post_ids = get_posts( $query_args );
		$post_ids = array_map( 'intval', (array) $post_ids );

		$queue_data = [
			'post_type'   => $post_type,
			'filter_mode' => $filter_mode,
			'filter_term' => $filter_term,
			'target_ids'  => $target_ids,
			'queue'       => $post_ids,
			'total'       => count( $post_ids ),
			'processed'   => 0,
			'failed'      => 0,
		];

		$now     = current_time( 'mysql' );
		$job_key = 'job_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			ASAE_CI_DB::jobs_table(),
			[
				'job_key'     => $job_key,
				'status'      => 'running',
				'run_type'    => 'active',
				'source_url'  => 'bulk-assign-areas:' . $post_type,
				'post_type'   => $post_type,
				'batch_limit' => 'all',
				'phase'       => 'bulk_assign_areas',
				'queue_data'  => wp_json_encode( $queue_data ),
				'report_id'   => null,
				'created_at'  => $now,
				'updated_at'  => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
		// phpcs:enable

		if ( ! $inserted ) {
			return new WP_Error( 'asae_ci_db_error', 'Failed to create bulk-assign job record.' );
		}

		// Schedule first cron tick so the job continues even if the browser closes.
		wp_schedule_single_event( time(), ASAE_CI_CRON_HOOK, [ $job_key ] );

		return $job_key;
	}

	/**
	 * Processes one batch of a bulk-assign-content-areas job (called from AJAX poll).
	 *
	 * @param string $job_key
	 * @return array|WP_Error Progress snapshot.
	 */
	public static function process_bulk_assign_areas_batch( string $job_key ) {
		$job = self::get_job( $job_key );
		if ( ! $job ) {
			return new WP_Error( 'asae_ci_no_job', 'Job not found.' );
		}
		if ( 'bulk_assign_areas' !== $job['phase'] ) {
			return self::build_bulk_assign_progress( $job );
		}
		if ( 'running' !== $job['status'] ) {
			return self::build_bulk_assign_progress( $job );
		}

		$queue_data = json_decode( $job['queue_data'], true );
		$queue_data = self::run_bulk_assign_areas_batch( $queue_data, $job );

		$job = self::get_job( $job_key );
		return self::build_bulk_assign_progress( $job, $queue_data );
	}

	/**
	 * Internal: pop N post IDs and assign Content Areas.
	 */
	private static function run_bulk_assign_areas_batch( array $queue_data, array $job ): array {
		$queue      = $queue_data['queue']      ?? [];
		$processed  = (int) ( $queue_data['processed'] ?? 0 );
		$failed     = (int) ( $queue_data['failed']    ?? 0 );
		$target_ids = $queue_data['target_ids'] ?? [];

		// Process up to 50 posts per batch — much faster than ingestion since it's just term assignment.
		$batch_size = 50;
		$count      = 0;

		while ( ! empty( $queue ) && $count < $batch_size ) {
			$post_id = (int) array_shift( $queue );
			$count++;

			if ( $post_id <= 0 ) {
				$failed++;
				continue;
			}

			$result = wp_set_object_terms( $post_id, $target_ids, ASAE_CI_Admin::CONTENT_AREA_TAXONOMY, false );
			if ( is_wp_error( $result ) ) {
				$failed++;
			} else {
				$processed++;
			}
		}

		$queue_data['queue']     = $queue;
		$queue_data['processed'] = $processed;
		$queue_data['failed']    = $failed;

		if ( empty( $queue ) ) {
			self::update_job( $job['job_key'], [
				'status'     => 'completed',
				'queue_data' => wp_json_encode( $queue_data ),
			] );
		} else {
			self::update_job( $job['job_key'], [
				'queue_data' => wp_json_encode( $queue_data ),
			] );
		}

		return $queue_data;
	}

	/**
	 * Builds a progress response for a bulk-assign job.
	 */
	public static function build_bulk_assign_progress( array $job, ?array $queue_data = null ): array {
		if ( null === $queue_data ) {
			$queue_data = json_decode( $job['queue_data'], true );
		}
		$total     = (int) ( $queue_data['total']     ?? 0 );
		$processed = (int) ( $queue_data['processed'] ?? 0 );
		$failed    = (int) ( $queue_data['failed']    ?? 0 );
		$remaining = count( $queue_data['queue'] ?? [] );

		return [
			'job_key'     => $job['job_key'],
			'status'      => $job['status'],
			'phase'       => $job['phase'],
			'total'       => $total,
			'processed'   => $processed,
			'failed'      => $failed,
			'remaining'   => $remaining,
			'is_complete' => 'completed' === $job['status'] || 'failed' === $job['status'],
		];
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
		} elseif ( 'bulk_assign_areas' === $job['phase'] ) {
			$queue_data = self::run_bulk_assign_areas_batch( $queue_data, $job );
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

		// Fetch ALL available URLs from the feed (limit = 0 = unlimited).
		// The batch_limit is enforced during ingestion so we accumulate N genuinely
		// new (non-duplicate) items instead of stopping at the first N feed entries.
		$feed_url        = $disc['feed_url']        ?? '';
		$url_restriction = $disc['url_restriction'] ?? '';

		$urls = ASAE_CI_Crawler::fetch_feed_urls( $feed_url, $url_restriction, 0 );

		if ( is_wp_error( $urls ) ) {
			// Mark the job as failed if the feed could not be fetched.
			self::update_job( $job['job_key'], [ 'status' => 'failed' ] );
			if ( $job['report_id'] ) {
				ASAE_CI_Reports::update_report( (int) $job['report_id'], [ 'status' => 'failed' ] );
			}
			return $queue_data;
		}

		// Extract per-item metadata (author, date, tags) from the feed entries.
		// This is used as fallback when the HTML parser cannot find metadata
		// on the target page (e.g. YouTube video watch pages).
		$feed_metadata = ASAE_CI_Crawler::fetch_feed_metadata( $feed_url, $url_restriction );

		// Merge WP REST API author sidecar if it exists (enriches per-URL
		// metadata with bio, photo, email, website from authenticated users endpoint).
		if ( class_exists( 'ASAE_CI_WP_REST' ) ) {
			$sidecar = ASAE_CI_WP_REST::load_author_sidecar();
			if ( ! empty( $sidecar ) ) {
				foreach ( $sidecar as $sidecar_url => $author_data ) {
					if ( isset( $feed_metadata[ $sidecar_url ] ) ) {
						$feed_metadata[ $sidecar_url ] = array_merge( $feed_metadata[ $sidecar_url ], $author_data );
					} else {
						$feed_metadata[ $sidecar_url ] = $author_data;
					}
				}
			}
		}

		$queue_data['feed_metadata'] = $feed_metadata;

		// Mark the feed as fetched and store the discovered URLs.
		$disc['feed_fetched'] = true;
		$disc['content_urls'] = $urls;
		$queue_data['discovery'] = $disc;

		// Move discovered URLs into the ingestion queue and clear the
		// discovery copy to avoid storing 17K+ URLs twice in the JSON blob.
		// Preserve the count so build_progress_response() can report it.
		$content_urls = $urls;
		$disc['content_urls']       = [];
		$disc['content_urls_count'] = count( $urls );
		$queue_data['discovery']    = $disc;

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
		$ingest         = $queue_data['ingestion']     ?? [];
		$queue          = $ingest['queue']             ?? [];
		$done           = (int) ( $ingest['processed'] ?? 0 );
		$failed         = (int) ( $ingest['failed']    ?? 0 );
		$pending_review = $queue_data['pending_review'] ?? [];
		$feed_metadata  = $queue_data['feed_metadata']  ?? [];
		$limit_int      = self::batch_limit_to_int( $job['batch_limit'], $job['run_type'] );

		// Parse batch-level extra tags from the stored comma-separated string.
		$extra_tags       = array_filter( array_map( 'trim', explode( ',', $queue_data['additional_tags'] ?? '' ) ) );
		$source_type      = $queue_data['source_type'] ?? 'replace';
		$content_area_ids = $queue_data['content_area_ids'] ?? [];

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

			// Merge feed-level metadata as fallback when the HTML parser
			// couldn't extract author, date, or tags (e.g. YouTube pages).
			$parsed = self::merge_feed_metadata( $parsed, $url, $feed_metadata );

			// For YouTube URLs, replace scraped HTML with a proper embed block.
			$parsed = self::maybe_apply_youtube_embed( $parsed, $url );

			// Ingest into WordPress (passing batch-level extra tags and source type).
			$post_id = ASAE_CI_Ingester::ingest( $parsed, $job['post_type'], $extra_tags, $source_type );

			if ( is_wp_error( $post_id ) ) {
				if ( 'asae_ci_needs_category' === $post_id->get_error_code() ) {
					// Post created as draft – needs manual category assignment.
					$pd = $post_id->get_error_data();
					$pending_review[] = [
						'post_id'    => (int) $pd['post_id'],
						'post_title' => sanitize_text_field( $parsed['title'] ?? '' ),
						'source_url' => $url,
					];
					$done++;
					// Apply Content Areas to draft posts as well.
					if ( ! empty( $content_area_ids ) && taxonomy_exists( ASAE_CI_Admin::CONTENT_AREA_TAXONOMY ) ) {
						wp_set_object_terms( (int) $pd['post_id'], $content_area_ids, ASAE_CI_Admin::CONTENT_AREA_TAXONOMY, false );
					}
					self::log_report_item( $job, $url, (int) $pd['post_id'], $parsed['tags'] ?? [],
						'draft', 'Needs category review.',
						$parsed['title'] ?? '', $parsed['author'] ?? '', $parsed['date'] ?? '' );
					if ( $limit_int > 0 && $done >= $limit_int ) {
						$queue = [];
						break;
					}
					continue;
				}

				$status = 'asae_ci_duplicate' === $post_id->get_error_code() ? 'skipped' : 'failed';
				self::log_report_item( $job, $url, null, $parsed['tags'] ?? [], $status,
					$post_id->get_error_message(), $parsed['title'] ?? '',
					$parsed['author'] ?? '', $parsed['date'] ?? '' );
				if ( 'failed' === $status ) {
					$failed++;
				}
			} else {
				$done++;
				// Apply Content Areas to the freshly created post (replace mode).
				if ( ! empty( $content_area_ids ) && taxonomy_exists( ASAE_CI_Admin::CONTENT_AREA_TAXONOMY ) ) {
					wp_set_object_terms( (int) $post_id, $content_area_ids, ASAE_CI_Admin::CONTENT_AREA_TAXONOMY, false );
				}
				self::log_report_item( $job, $url, $post_id, $parsed['tags'] ?? [], 'ingested',
					'', $parsed['title'] ?? '', $parsed['author'] ?? '', $parsed['date'] ?? '' );
				// Stop once we've ingested the requested number of genuinely new items.
				if ( $limit_int > 0 && $done >= $limit_int ) {
					$queue = [];
					break;
				}
			}
		}

		// Update queue state.
		$ingest['queue']          = $queue;
		$ingest['processed']      = $done;
		$ingest['failed']         = $failed;
		$queue_data['ingestion']  = $ingest;
		$queue_data['pending_review'] = $pending_review;

		if ( empty( $queue ) ) {
			// All URLs processed – finalise (may enter needs_review if drafts exist).
			self::finalise_job( $job, $queue_data, $done, $failed, $pending_review );
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
		$ingest        = $queue_data['ingestion']     ?? [];
		$queue         = $ingest['queue']              ?? [];
		$done          = (int) ( $ingest['processed']  ?? 0 );
		$new_count     = (int) ( $ingest['new_count']  ?? 0 ); // Non-duplicate items so far.
		$dry_results   = $queue_data['dry_results']    ?? [];
		$feed_metadata = $queue_data['feed_metadata']  ?? [];

		// Parse batch-level extra tags.
		$extra_tags = array_filter( array_map( 'trim', explode( ',', $queue_data['additional_tags'] ?? '' ) ) );

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

			// Merge feed-level metadata as fallback when the HTML parser
			// couldn't extract author, date, or tags (e.g. YouTube pages).
			$parsed = self::merge_feed_metadata( $parsed, $url, $feed_metadata );

			// For YouTube URLs, replace scraped HTML with a proper embed block.
			$parsed = self::maybe_apply_youtube_embed( $parsed, $url );

			$preview = ASAE_CI_Ingester::dry_run_preview( $parsed, $job['post_type'], $extra_tags );

			$dry_results[] = $preview;
			$done++;
			if ( ! $preview['is_duplicate'] ) {
				$new_count++;
			}

			// Log to the report as a dry item (no post created).
			self::log_report_item( $job, $url, null, $preview['tags'], 'dry',
				$preview['is_duplicate'] ? 'Would be skipped (duplicate).' : '',
				$preview['post_title'], $preview['author'] ?? '', $preview['date'] ?? '' );

			// Stop once we have 20 genuinely new (non-duplicate) preview items.
			if ( $new_count >= 20 ) {
				$queue = [];
				break;
			}
		}

		$ingest['queue']          = $queue;
		$ingest['processed']      = $done;
		$ingest['new_count']      = $new_count;
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
	 * Returns the most recent job that is still in 'running' status.
	 *
	 * Used on page load to detect an interrupted job (e.g. after session
	 * expiry) so the browser can auto-resume its polling loop.
	 *
	 * @return array|null Job data array or null if no running job exists.
	 */
	public static function get_running_job(): ?array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			'SELECT * FROM ' . ASAE_CI_DB::jobs_table() . " WHERE status = 'running' AND phase != 'bulk_assign_areas' ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);
		// phpcs:enable
		return $row ?: null;
	}

	/**
	 * Returns the most recent running bulk-assign-content-areas job, if any.
	 */
	public static function get_running_bulk_assign_job(): ?array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			'SELECT * FROM ' . ASAE_CI_DB::jobs_table() . " WHERE status = 'running' AND phase = 'bulk_assign_areas' ORDER BY id DESC LIMIT 1",
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
	 * Marks the job as completed (or needs_review if drafts await category assignment)
	 * and finalises the associated report.
	 *
	 * @param array $job            Job record.
	 * @param int   $done           Number of items processed (ingested + drafts).
	 * @param int   $failed         Number of failed items.
	 * @param array $pending_review Items that were saved as drafts needing category review.
	 * @return void
	 */
	private static function finalise_job( array $job, array $queue_data, int $done, int $failed, array $pending_review = [] ): void {
		$final_status = empty( $pending_review ) ? 'completed' : 'needs_review';

		// Prune stale data from queue_data to reduce blob size.
		// feed_metadata and ingestion queue are no longer needed after ingestion.
		unset( $queue_data['feed_metadata'] );
		$queue_data['ingestion']['queue'] = [];
		$queue_data['discovery']['content_urls'] = [];

		self::update_job( $job['job_key'], [
			'status'    => $final_status,
			'queue_data' => wp_json_encode( $queue_data ),
		] );

		if ( $job['report_id'] ) {
			ASAE_CI_Reports::update_report( (int) $job['report_id'], [
				'status'          => $final_status,
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
	public static function build_progress_response( array $job, ?array $queue_data = null ): array {
		if ( null === $queue_data ) {
			$queue_data = json_decode( $job['queue_data'], true );
		}

		$disc    = $queue_data['discovery']   ?? [];
		$ingest  = $queue_data['ingestion']   ?? [];
		$dry_res = $queue_data['dry_results'] ?? [];

		// Map RSS feed_fetched (bool) to crawled/to_crawl counts for the UI.
		// The discovery bar advances from 0 → 100% once the feed is read.
		$feed_fetched  = (bool) ( $disc['feed_fetched'] ?? false );
		$content_found = (int) ( $disc['content_urls_count'] ?? count( $disc['content_urls'] ?? [] ) );
		$queue_count   = count( $ingest['queue']      ?? [] );
		$processed     = (int) ( $ingest['processed'] ?? 0 );
		$failed        = (int) ( $ingest['failed']    ?? 0 );

		$pending_review = $queue_data['pending_review'] ?? [];

		// Categories available for review panel (only populated when needs_review).
		$review_categories = [];
		if ( 'needs_review' === $job['status'] && ! empty( $pending_review ) ) {
			$post_type = $job['post_type'] ?? 'post';
			$tax       = 'post' === $post_type ? 'category' : '';
			if ( ! $tax ) {
				$taxons = get_object_taxonomies( $post_type, 'objects' );
				foreach ( $taxons as $t ) {
					if ( $t->hierarchical ) {
						$tax = $t->name;
						break;
					}
				}
			}
			if ( $tax ) {
				$terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$review_categories[] = [ 'term_id' => $term->term_id, 'name' => $term->name ];
					}
				}
			}
		}

		return [
			'job_key'              => $job['job_key'],
			'status'               => $job['status'],
			'phase'                => $job['phase'],
			'run_type'             => $job['run_type'],
			'report_id'            => $job['report_id'],
			'crawled'              => $feed_fetched ? 1 : 0,
			'to_crawl'             => $feed_fetched ? 0 : 1,
			'content_found'        => $content_found,
			'queue_remaining'      => $queue_count,
			'processed'            => $processed,
			'failed'               => $failed,
			'dry_results'          => 'dry' === $job['run_type'] ? $dry_res : [],
			'pending_review_total' => count( $pending_review ),
			'review_categories'    => $review_categories,
			'is_needs_review'      => 'needs_review' === $job['status'],
			'is_complete'          => 'completed' === $job['status'] || 'failed' === $job['status'],
		];
	}

	// ── Feed Metadata Merge ──────────────────────────────────────────────────

	/**
	 * Merges feed-level metadata into parsed article data as fallback values.
	 *
	 * When the HTML parser returns empty author, date, or tags, this method
	 * fills in those fields from the feed entry metadata (if available).
	 * This ensures YouTube and other non-article pages still carry the
	 * metadata that was present in the original feed.
	 *
	 * @param array  $parsed        Parsed article data from ASAE_CI_Parser::parse().
	 * @param string $url           The article URL (used as key into the metadata map).
	 * @param array  $feed_metadata URL → metadata map from the crawler.
	 * @return array Updated parsed data with feed metadata merged in.
	 */
	private static function merge_feed_metadata( array $parsed, string $url, array $feed_metadata ): array {
		if ( empty( $feed_metadata ) || ! isset( $feed_metadata[ $url ] ) ) {
			return $parsed;
		}

		$fm = $feed_metadata[ $url ];

		// Author: use feed value as fallback.
		if ( empty( $parsed['author'] ) && ! empty( $fm['author'] ) ) {
			$parsed['author'] = $fm['author'];
		}

		// Date: use feed value as fallback.
		if ( empty( $parsed['date'] ) && ! empty( $fm['date'] ) ) {
			$parsed['date'] = $fm['date'];
		}

		// Description: carry feed description through for YouTube embed enrichment.
		if ( ! empty( $fm['description'] ) ) {
			$parsed['feed_description'] = $fm['description'];
		}

		// Tags: merge feed tags with any HTML-extracted tags (no duplicates).
		if ( ! empty( $fm['tags'] ) ) {
			$existing     = $parsed['tags'] ?? [];
			$existing_lc  = array_map( 'strtolower', $existing );
			foreach ( $fm['tags'] as $tag ) {
				if ( ! in_array( strtolower( $tag ), $existing_lc, true ) ) {
					$existing[]    = $tag;
					$existing_lc[] = strtolower( $tag );
				}
			}
			$parsed['tags'] = $existing;
		}

		// Author bio URL: use feed value as fallback (e.g. from WP REST sidecar).
		if ( empty( $parsed['author_bio_url'] ) && ! empty( $fm['author_bio_url'] ) ) {
			$parsed['author_bio_url'] = $fm['author_bio_url'];
		}

		// Author bio text: use feed value as fallback.
		if ( empty( $parsed['author_bio'] ) && ! empty( $fm['author_bio'] ) ) {
			$parsed['author_bio'] = $fm['author_bio'];
		}

		// Author photo URL: use feed value as fallback.
		if ( empty( $parsed['author_photo_url'] ) && ! empty( $fm['author_photo_url'] ) ) {
			$parsed['author_photo_url'] = $fm['author_photo_url'];
		}

		return $parsed;
	}

	// ── YouTube Embed Override ───────────────────────────────────────────────

	/**
	 * Detects YouTube video URLs and replaces the parsed content with a
	 * WordPress embed block so the ingested post shows the video player
	 * instead of scraped YouTube page HTML.
	 *
	 * Also uses the video description from the feed as the excerpt when
	 * the HTML parser didn't find a usable excerpt.
	 *
	 * @param array  $parsed Parsed article data.
	 * @param string $url    The source URL being processed.
	 * @return array Updated parsed data.
	 */
	private static function maybe_apply_youtube_embed( array $parsed, string $url ): array {
		// Match youtube.com/watch?v=... and youtu.be/... URLs.
		if ( ! preg_match( '#^https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]+)#', $url, $m ) ) {
			return $parsed;
		}

		$video_url = 'https://www.youtube.com/watch?v=' . $m[1];

		// Replace the content with a WordPress YouTube embed block.
		$embed = '<!-- wp:embed {"url":"' . esc_url( $video_url ) . '","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->'
			. "\n" . '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio">'
			. '<div class="wp-block-embed__wrapper">' . "\n"
			. esc_url( $video_url ) . "\n"
			. '</div></figure>' . "\n"
			. '<!-- /wp:embed -->';

		// Append the video description from the feed below the embed block.
		$description = trim( $parsed['feed_description'] ?? '' );
		if ( $description ) {
			$paragraphs = preg_split( '/\n{2,}/', $description );
			foreach ( $paragraphs as $para ) {
				$para = trim( $para );
				if ( '' === $para ) {
					continue;
				}
				// Convert single newlines to <br> within a paragraph.
				$para   = nl2br( esc_html( $para ), false );
				$embed .= "\n\n" . '<!-- wp:paragraph -->' . "\n"
					. '<p>' . $para . '</p>' . "\n"
					. '<!-- /wp:paragraph -->';
			}
		}

		$parsed['content'] = $embed;

		// Clear inline images — the YouTube page scrape produces irrelevant images.
		$parsed['inline_images'] = [];

		return $parsed;
	}

	// ── Utility ───────────────────────────────────────────────────────────────

	/**
	 * Converts a batch_limit string value to an integer.
	 * 'all' returns 0 (no limit). For Dry Runs the caller enforces a cap of 20.
	 *
	 * @param string $batch_limit '10', '50', '100', or 'all'.
	 * @param string $run_type    'dry' or 'active'.
	 * @return int
	 */
	public static function batch_limit_to_int( string $batch_limit, string $run_type = 'active' ): int {
		if ( 'dry' === $run_type ) {
			return 20;
		}
		if ( 'all' === $batch_limit ) {
			return 0;
		}
		$int = (int) $batch_limit;
		return max( 0, $int );
	}
}
