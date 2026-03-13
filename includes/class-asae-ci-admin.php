<?php
/**
 * ASAE Content Ingestor – Admin UI Controller
 *
 * Registers all WordPress admin-side features for this plugin:
 *  - A "Content Ingestor" submenu page under the "ASAE" menu (created by asae-explore).
 *  - An Ingestion Reports sub-page.
 *  - Enqueuing of admin CSS and JS assets.
 *  - AJAX handlers for starting jobs, polling progress, and processing batches.
 *
 * All capability checks use 'manage_options' (admin-level WordPress role).
 * All AJAX handlers verify a nonce before performing any action.
 *
 * WCAG 2.2 Level AA compliance notes:
 *  - All form controls have associated <label> elements.
 *  - Colour contrast ratios meet 4.5:1 (normal text) and 3:1 (large text).
 *  - Focus indicators are visible (enforced in admin.css).
 *  - Status/progress updates use aria-live="polite" regions.
 *  - Tables have proper <caption>, <th scope>, and headers.
 *
 * @package ASAE_Content_Ingestor
 * @since   0.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CI_Admin {

	/** Nonce action string for the run form. */
	const NONCE_ACTION = 'asae_ci_run_action';

	/** Nonce action string for AJAX calls. */
	const AJAX_NONCE = 'asae_ci_ajax_nonce';

	/** Nonce action string for the redirect JSON export. */
	const EXPORT_NONCE = 'asae_ci_export_redirects';

	// ── Initialisation ────────────────────────────────────────────────────────

	/**
	 * Registers all admin hooks. Called once from asae_ci_init() when is_admin().
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu',            [ __CLASS__, 'register_menus' ], 20 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

		// AJAX – logged-in admin users only.
		add_action( 'wp_ajax_asae_ci_start_job',          [ __CLASS__, 'ajax_start_job' ] );
		add_action( 'wp_ajax_asae_ci_process_batch',      [ __CLASS__, 'ajax_process_batch' ] );
		add_action( 'wp_ajax_asae_ci_get_progress',       [ __CLASS__, 'ajax_get_progress' ] );
		add_action( 'wp_ajax_asae_ci_delete_report',      [ __CLASS__, 'ajax_delete_report' ] );
		add_action( 'wp_ajax_asae_ci_dismiss_cap_notice', [ __CLASS__, 'ajax_dismiss_cap_notice' ] );
		add_action( 'wp_ajax_asae_ci_apply_categories',   [ __CLASS__, 'ajax_apply_categories' ] );

		// YouTube Feed tab.
		add_action( 'wp_ajax_asae_ci_save_youtube_key',      [ __CLASS__, 'ajax_save_youtube_key' ] );
		add_action( 'wp_ajax_asae_ci_generate_youtube_feed', [ __CLASS__, 'ajax_generate_youtube_feed' ] );
	}

	// ── Menu Registration ─────────────────────────────────────────────────────

	/**
	 * Adds a "Content Ingestor" submenu page under the top-level "ASAE" menu.
	 *
	 * The "ASAE" parent menu is created by the asae-explore plugin. This hook
	 * runs at priority 20 (after the default 10) so the parent is guaranteed
	 * to exist by the time this fires.
	 *
	 * @return void
	 */
	public static function register_menus(): void {
		add_submenu_page(
			'asae',
			__( 'Content Ingestor', 'asae-content-ingestor' ),
			__( 'Content Ingestor', 'asae-content-ingestor' ),
			'manage_options',
			'asae-content-ingestor',
			[ __CLASS__, 'render_main_page' ]
		);
	}

	// ── Asset Enqueuing ───────────────────────────────────────────────────────

	/**
	 * Enqueues admin CSS and JS assets on the plugin's own admin pages only.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		$plugin_pages = [
			'asae_page_asae-content-ingestor',
		];

		if ( ! in_array( $hook_suffix, $plugin_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'asae-ci-admin',
			ASAE_CI_URL . 'assets/css/admin.css',
			[],
			ASAE_CI_VERSION
		);

		wp_enqueue_script(
			'asae-ci-admin',
			ASAE_CI_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			ASAE_CI_VERSION,
			true  // Load in footer.
		);

		// Pass server-side data to the JS.
		wp_localize_script( 'asae-ci-admin', 'asaeCi', [
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::AJAX_NONCE ),
			'strings'   => [
				'startingJob'    => __( 'Starting job…', 'asae-content-ingestor' ),
				'discovering'    => __( 'Reading RSS feed…', 'asae-content-ingestor' ),
				'ingesting'      => __( 'Ingesting content…', 'asae-content-ingestor' ),
				'dryRunning'     => __( 'Running dry preview…', 'asae-content-ingestor' ),
				'completed'      => __( 'Completed.', 'asae-content-ingestor' ),
				'needsReview'    => __( 'Category review required.', 'asae-content-ingestor' ),
				'failed'         => __( 'An error occurred.', 'asae-content-ingestor' ),
				'confirmDelete'  => __( 'Delete this report? This cannot be undone.', 'asae-content-ingestor' ),
				// YouTube tab strings.
				'ytKeySaved'     => __( 'API key saved.', 'asae-content-ingestor' ),
				'ytKeyError'     => __( 'Please enter an API key.', 'asae-content-ingestor' ),
				'ytFetching'     => __( 'Fetching videos…', 'asae-content-ingestor' ),
				'ytChannelError' => __( 'Please enter a channel or playlist ID.', 'asae-content-ingestor' ),
				'ytNoKey'        => __( 'Please save a YouTube API key first.', 'asae-content-ingestor' ),
			],
		] );
	}

	// ── Page Renderers ────────────────────────────────────────────────────────

	/**
	 * Renders the ASAE > Content Ingestor page, with tab routing.
	 *
	 * Tab 'run'     (default) – run configuration form and progress panels.
	 * Tab 'reports'           – ingestion reports listing or report detail.
	 *
	 * All report sub-pages are handled here via the 'tab' and 'report_id'
	 * query params, so no separate submenu entry is needed.
	 *
	 * @return void
	 */
	public static function render_main_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'asae-content-ingestor' ) );
		}

		// Handle JSON redirect export (streams a file download — no view needed).
		if ( isset( $_GET['asae_ci_action'] ) && 'export_redirects' === $_GET['asae_ci_action'] ) {
			check_admin_referer( self::EXPORT_NONCE );
			self::handle_export_redirects();
			exit;
		}

		$active_tab = sanitize_key( $_GET['tab'] ?? 'run' );
		if ( ! in_array( $active_tab, [ 'run', 'reports', 'youtube' ], true ) ) {
			$active_tab = 'run';
		}

		if ( 'reports' === $active_tab ) {
			// Report detail or listing.
			$report_id = isset( $_GET['report_id'] ) ? (int) $_GET['report_id'] : 0;
			if ( $report_id > 0 ) {
				$report = ASAE_CI_Reports::get_report( $report_id );
				if ( ! $report ) {
					wp_die( esc_html__( 'Report not found.', 'asae-content-ingestor' ) );
				}
				$page       = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
				$items_data = ASAE_CI_Reports::get_report_items( $report_id, $page, 50 );
				$view       = ASAE_CI_PATH . 'admin/views/page-report-detail.php';
			} else {
				$page         = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
				$reports_data = ASAE_CI_Reports::get_reports( $page, 20 );
				$view         = ASAE_CI_PATH . 'admin/views/page-reports.php';
			}
		} elseif ( 'youtube' === $active_tab ) {
			// YouTube Feed tab.
			$yt_api_key       = get_option( ASAE_CI_YouTube::OPTION_API_KEY, '' );
			$yt_api_key_saved = (bool) $yt_api_key;
			$yt_api_key_mask  = $yt_api_key ? self::mask_api_key( $yt_api_key ) : '';
			$yt_feed_status   = ASAE_CI_YouTube::get_feed_status();
			$view             = ASAE_CI_PATH . 'admin/views/page-youtube.php';
		} else {
			// Run tab (default).
			$active_tab           = 'run';
			$post_types           = self::get_eligible_post_types();
			$cap_active           = ASAE_CI_Ingester::cap_is_active();
			$cap_notice_dismissed = (bool) get_transient( 'asae_ci_cap_dismissed_' . get_current_user_id() );
			$view                 = ASAE_CI_PATH . 'admin/views/page-main.php';
		}

		if ( file_exists( $view ) ) {
			include $view;
		}
	}

	// ── AJAX Handlers ─────────────────────────────────────────────────────────

	/**
	 * AJAX: Creates and starts a new crawl+ingest job.
	 * Expects POST params: source_url, post_type, batch_limit, run_type, nonce.
	 *
	 * @return void
	 */
	public static function ajax_start_job(): void {
		self::verify_ajax_nonce();
		self::verify_admin_capability();

		$source_url      = esc_url_raw( wp_unslash( $_POST['source_url']      ?? '' ) );
		$url_restriction = esc_url_raw( wp_unslash( $_POST['url_restriction'] ?? '' ) );
		$post_type       = sanitize_key( $_POST['post_type']   ?? 'post' );
		$batch_limit     = sanitize_text_field( $_POST['batch_limit']      ?? '50' );
		$run_type        = sanitize_text_field( $_POST['run_type']          ?? 'dry' );
		$additional_tags = sanitize_text_field( wp_unslash( $_POST['additional_tags'] ?? '' ) );
		$source_type     = sanitize_text_field( $_POST['source_type'] ?? 'replace' );

		if ( empty( $source_url ) ) {
			wp_send_json_error( [ 'message' => __( 'An RSS feed URL is required.', 'asae-content-ingestor' ) ] );
		}

		// Validate batch_limit value.
		if ( ! in_array( $batch_limit, [ '10', '50', '100', 'all' ], true ) ) {
			$batch_limit = '50';
		}

		// Validate run_type value.
		if ( ! in_array( $run_type, [ 'dry', 'active' ], true ) ) {
			$run_type = 'dry';
		}

		// Validate source_type value.
		if ( ! in_array( $source_type, [ 'replace', 'mirror' ], true ) ) {
			$source_type = 'replace';
		}

		$job_key = ASAE_CI_Scheduler::create_job( [
			'source_url'      => $source_url,
			'url_restriction' => $url_restriction ?: null,
			'post_type'       => $post_type,
			'batch_limit'     => $batch_limit,
			'run_type'        => $run_type,
			'additional_tags' => $additional_tags,
			'source_type'     => $source_type,
		] );

		if ( is_wp_error( $job_key ) ) {
			wp_send_json_error( [ 'message' => $job_key->get_error_message() ] );
		}

		wp_send_json_success( [
			'job_key' => $job_key,
			'message' => __( 'Job started. Processing will begin shortly.', 'asae-content-ingestor' ),
		] );
	}

	/**
	 * AJAX: Processes one batch for the given job_key.
	 * Called repeatedly from the admin JS until the job is complete.
	 * Expects POST params: job_key, nonce.
	 *
	 * @return void
	 */
	public static function ajax_process_batch(): void {
		self::verify_ajax_nonce();
		self::verify_admin_capability();

		$job_key = sanitize_text_field( wp_unslash( $_POST['job_key'] ?? '' ) );
		if ( empty( $job_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Job key is required.', 'asae-content-ingestor' ) ] );
		}

		$result = ASAE_CI_Scheduler::process_ajax_batch( $job_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Returns the current progress of a job without processing a batch.
	 * Expects POST params: job_key, nonce.
	 *
	 * @return void
	 */
	public static function ajax_get_progress(): void {
		self::verify_ajax_nonce();
		self::verify_admin_capability();

		$job_key = sanitize_text_field( wp_unslash( $_POST['job_key'] ?? '' ) );
		if ( empty( $job_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Job key is required.', 'asae-content-ingestor' ) ] );
		}

		$job = ASAE_CI_Scheduler::get_job( $job_key );
		if ( ! $job ) {
			wp_send_json_error( [ 'message' => __( 'Job not found.', 'asae-content-ingestor' ) ] );
		}

		$queue_data  = json_decode( $job['queue_data'], true );
		$disc        = $queue_data['discovery'] ?? [];
		$ingest      = $queue_data['ingestion'] ?? [];
		$feed_fetched = (bool) ( $disc['feed_fetched'] ?? false );

		wp_send_json_success( [
			'job_key'         => $job['job_key'],
			'status'          => $job['status'],
			'phase'           => $job['phase'],
			'run_type'        => $job['run_type'],
			'report_id'       => $job['report_id'],
			'crawled'         => $feed_fetched ? 1 : 0,
			'to_crawl'        => $feed_fetched ? 0 : 1,
			'content_found'   => count( $disc['content_urls'] ?? [] ),
			'queue_remaining' => count( $ingest['queue']      ?? [] ),
			'processed'       => (int) ( $ingest['processed'] ?? 0 ),
			'failed'          => (int) ( $ingest['failed']    ?? 0 ),
			'dry_results'     => 'dry' === $job['run_type'] ? ( $queue_data['dry_results'] ?? [] ) : [],
			'is_complete'     => in_array( $job['status'], [ 'completed', 'failed' ], true ),
		] );
	}

	/**
	 * AJAX: Deletes a report record and all its items.
	 * Expects POST params: report_id, nonce.
	 *
	 * @return void
	 */
	public static function ajax_delete_report(): void {
		self::verify_ajax_nonce();
		self::verify_admin_capability();

		$report_id = (int) ( $_POST['report_id'] ?? 0 );
		if ( $report_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid report ID.', 'asae-content-ingestor' ) ] );
		}

		$deleted = ASAE_CI_Reports::delete_report( $report_id );
		if ( $deleted ) {
			wp_send_json_success( [ 'message' => __( 'Report deleted.', 'asae-content-ingestor' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to delete report.', 'asae-content-ingestor' ) ] );
		}
	}

	/**
	 * AJAX: Dismisses the Co-Authors Plus missing notice for 30 days for the current user.
	 * Expects POST params: nonce.
	 *
	 * @return void
	 */
	public static function ajax_dismiss_cap_notice(): void {
		self::verify_ajax_nonce();
		self::verify_admin_capability();
		set_transient( 'asae_ci_cap_dismissed_' . get_current_user_id(), true, 30 * DAY_IN_SECONDS );
		wp_send_json_success();
	}

	/**
	 * AJAX: Applies manually selected categories to draft posts that had no automatic match.
	 * On success, publishes the posts and removes them from the pending_review queue.
	 * If all pending items are resolved the job status is set to 'completed'.
	 *
	 * Expects POST params: job_key, assignments (JSON array of {post_id, term_id}), nonce.
	 *
	 * @return void
	 */
	public static function ajax_apply_categories(): void {
		self::verify_ajax_nonce();
		self::verify_admin_capability();

		$job_key     = sanitize_text_field( wp_unslash( $_POST['job_key']     ?? '' ) );
		$assignments = json_decode( wp_unslash( $_POST['assignments'] ?? '[]' ), true );

		if ( empty( $job_key ) || ! is_array( $assignments ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'asae-content-ingestor' ) ] );
		}

		$job = ASAE_CI_Scheduler::get_job( $job_key );
		if ( ! $job ) {
			wp_send_json_error( [ 'message' => __( 'Job not found.', 'asae-content-ingestor' ) ] );
		}

		$queue_data     = json_decode( $job['queue_data'], true );
		$pending_review = $queue_data['pending_review'] ?? [];
		$post_type      = $job['post_type'] ?? 'post';

		// Determine the category taxonomy for this post type.
		$tax = 'post' === $post_type ? 'category' : '';
		if ( ! $tax ) {
			$taxons = get_object_taxonomies( $post_type, 'objects' );
			foreach ( $taxons as $t ) {
				if ( $t->hierarchical ) {
					$tax = $t->name;
					break;
				}
			}
		}

		// Apply each assignment: set category, publish the draft, remove from pending list.
		$resolved_ids = [];
		foreach ( $assignments as $assignment ) {
			$post_id = (int) ( $assignment['post_id'] ?? 0 );
			$term_id = (int) ( $assignment['term_id'] ?? 0 );

			if ( $post_id <= 0 || $term_id <= 0 || ! $tax ) {
				continue;
			}

			wp_set_object_terms( $post_id, [ $term_id ], $tax, false );
			wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
			delete_post_meta( $post_id, '_asae_ci_needs_category' );
			$resolved_ids[] = $post_id;
		}

		// Remove resolved items from pending_review.
		$queue_data['pending_review'] = array_values( array_filter(
			$pending_review,
			static function ( $item ) use ( $resolved_ids ) {
				return ! in_array( (int) $item['post_id'], $resolved_ids, true );
			}
		) );

		// If all resolved, mark job as completed.
		if ( empty( $queue_data['pending_review'] ) ) {
			ASAE_CI_Scheduler::update_job( $job_key, [
				'status'     => 'completed',
				'queue_data' => wp_json_encode( $queue_data ),
			] );
			if ( $job['report_id'] ) {
				ASAE_CI_Reports::update_report( (int) $job['report_id'], [ 'status' => 'completed' ] );
			}
		} else {
			ASAE_CI_Scheduler::update_job( $job_key, [
				'queue_data' => wp_json_encode( $queue_data ),
			] );
		}

		// Return updated progress snapshot.
		$updated_job = ASAE_CI_Scheduler::get_job( $job_key );
		$result      = $updated_job
			? ASAE_CI_Scheduler::build_progress_response( $updated_job, $queue_data )
			: [ 'is_complete' => true ];

		wp_send_json_success( $result );
	}

	// ── Private Helpers ───────────────────────────────────────────────────────

	/**
	 * Terminates the request with a JSON error if the AJAX nonce is invalid.
	 *
	 * @return void
	 */
	private static function verify_ajax_nonce(): void {
		if ( ! check_ajax_referer( self::AJAX_NONCE, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'asae-content-ingestor' ) ] );
			wp_die();
		}
	}

	/**
	 * Terminates the request with a JSON error if the current user is not an admin.
	 *
	 * @return void
	 */
	private static function verify_admin_capability(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'asae-content-ingestor' ) ] );
			wp_die();
		}
	}

	/**
	 * Streams a Redirection-plugin-compatible JSON file containing all
	 * asaecenter.org source URLs stored in _asae_ci_source_url post meta.
	 *
	 * Each redirect maps the source path to the canonical WP post URL so that
	 * the exported file can be imported into the Redirection plugin on the
	 * asaecenter.org WP site without creating duplicates on the current site.
	 *
	 * @return void  (exits after streaming)
	 */
	private static function handle_export_redirects(): void {
		global $wpdb;

		// Fetch all posts that have a asaecenter.org source URL.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value AS source_url
			 FROM {$wpdb->postmeta} pm
			 WHERE pm.meta_key = '_asae_ci_source_url'
			   AND pm.meta_value LIKE '%asaecenter.org%'
			   AND EXISTS (
			       SELECT 1 FROM {$wpdb->posts} p
			       WHERE p.ID = pm.post_id AND p.post_status IN ('publish','draft')
			   )",
			ARRAY_A
		);
		// phpcs:enable

		$redirects = [];
		foreach ( $rows as $row ) {
			$parsed = wp_parse_url( $row['source_url'] );
			$path   = $parsed['path'] ?? '';
			if ( ! $path ) {
				continue;
			}
			$target = get_permalink( (int) $row['post_id'] );
			if ( ! $target ) {
				continue;
			}
			$redirects[] = [
				'url'         => $path,
				'action_type' => 'url',
				'action_code' => 301,
				'action_data' => $target,
				'regex'       => false,
				'title'       => '',
			];
		}

		$filename = 'asaecenter-redirects-' . gmdate( 'Ymd' ) . '.json';
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-store, no-cache' );

		// phpcs:disable WordPress.Security.EscapeOutput
		echo wp_json_encode( [ 'redirects' => $redirects ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		// phpcs:enable
	}

	/**
	 * Returns an array of public WP post types eligible for ingestion.
	 * Excludes built-in attachment type; keeps all others including custom types.
	 *
	 * @return WP_Post_Type[] Array of WP_Post_Type objects keyed by slug.
	 */
	private static function get_eligible_post_types(): array {
		return get_post_types(
			[
				'public'             => true,
				'publicly_queryable' => true,
			],
			'objects'
		);
	}

	// ── YouTube Feed Tab AJAX Handlers ────────────────────────────────────

	/**
	 * AJAX: Saves the YouTube Data API v3 key.
	 * Expects POST param: yt_api_key.
	 *
	 * @return void
	 */
	public static function ajax_save_youtube_key(): void {
		self::verify_ajax_nonce();
		self::verify_admin_capability();

		$api_key = sanitize_text_field( wp_unslash( $_POST['yt_api_key'] ?? '' ) );

		if ( empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'API key cannot be empty.', 'asae-content-ingestor' ) ] );
		}

		update_option( ASAE_CI_YouTube::OPTION_API_KEY, $api_key, false );

		wp_send_json_success( [
			'message' => __( 'API key saved.', 'asae-content-ingestor' ),
			'mask'    => self::mask_api_key( $api_key ),
		] );
	}

	/**
	 * AJAX: Fetches all videos from a YouTube channel/playlist and generates
	 * an Atom XML feed file. Returns the feed URL, video count, and the full
	 * video list for client-side preview rendering.
	 *
	 * Expects POST param: channel_id.
	 *
	 * @return void
	 */
	public static function ajax_generate_youtube_feed(): void {
		self::verify_ajax_nonce();
		self::verify_admin_capability();

		$channel_id = sanitize_text_field( wp_unslash( $_POST['channel_id'] ?? '' ) );

		if ( empty( $channel_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Channel or playlist ID is required.', 'asae-content-ingestor' ) ] );
		}

		$api_key = get_option( ASAE_CI_YouTube::OPTION_API_KEY, '' );
		if ( empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'No YouTube API key saved. Please save an API key first.', 'asae-content-ingestor' ) ] );
		}

		$playlist_id = ASAE_CI_YouTube::normalize_playlist_id( $channel_id );

		// Fetch all videos from the playlist.
		$videos = ASAE_CI_YouTube::fetch_all_videos( $playlist_id, $api_key );

		if ( is_wp_error( $videos ) ) {
			wp_send_json_error( [ 'message' => $videos->get_error_message() ] );
		}

		if ( empty( $videos ) ) {
			wp_send_json_error( [ 'message' => __( 'No videos found in this channel or playlist.', 'asae-content-ingestor' ) ] );
		}

		// Determine channel title from the first video.
		$channel_title = $videos[0]['channel_title'] ?? '';

		// Generate and save the feed.
		$xml = ASAE_CI_YouTube::generate_feed( $videos, $channel_title );
		$url = ASAE_CI_YouTube::save_feed( $xml );

		if ( is_wp_error( $url ) ) {
			wp_send_json_error( [ 'message' => $url->get_error_message() ] );
		}

		// Build the video list for client-side preview table.
		$video_list = array_map( static function ( $v ) {
			return [
				'title'        => $v['title'],
				'published_at' => $v['published_at'],
				'url'          => 'https://www.youtube.com/watch?v=' . rawurlencode( $v['id'] ),
			];
		}, $videos );

		wp_send_json_success( [
			'feed_url'      => $url,
			'video_count'   => count( $videos ),
			'channel_title' => $channel_title,
			'videos'        => $video_list,
		] );
	}

	/**
	 * Masks an API key for safe display: shows first 4 and last 4 characters.
	 *
	 * @param string $key The full API key.
	 * @return string Masked key (e.g. "AIza••••••••cX9Q").
	 */
	private static function mask_api_key( string $key ): string {
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}
		return substr( $key, 0, 4 ) . str_repeat( '•', $len - 8 ) . substr( $key, -4 );
	}
}
