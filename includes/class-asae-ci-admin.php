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
		add_action( 'wp_ajax_asae_ci_apply_categories',      [ __CLASS__, 'ajax_apply_categories' ] );
		add_action( 'wp_ajax_asae_ci_fetch_review_page',     [ __CLASS__, 'ajax_fetch_review_page' ] );
		add_action( 'wp_ajax_asae_ci_apply_category_to_all', [ __CLASS__, 'ajax_apply_category_to_all' ] );
		add_action( 'wp_ajax_asae_ci_clear_redirects',       [ __CLASS__, 'ajax_clear_redirects' ] );
		add_action( 'wp_ajax_asae_ci_cancel_job',            [ __CLASS__, 'ajax_cancel_job' ] );

		// YouTube Feed tab.
		add_action( 'wp_ajax_asae_ci_save_youtube_key',        [ __CLASS__, 'ajax_save_youtube_key' ] );
		add_action( 'wp_ajax_asae_ci_save_youtube_channel_id', [ __CLASS__, 'ajax_save_youtube_channel_id' ] );
		add_action( 'wp_ajax_asae_ci_generate_youtube_feed',   [ __CLASS__, 'ajax_generate_youtube_feed' ] );

		// WordPress REST API Feed tab.
		add_action( 'wp_ajax_asae_ci_wp_rest_discover_types',    [ __CLASS__, 'ajax_wp_rest_discover_types' ] );
		add_action( 'wp_ajax_asae_ci_wp_rest_generate_feed',     [ __CLASS__, 'ajax_wp_rest_generate_feed' ] );
		add_action( 'wp_ajax_asae_ci_wp_rest_clear_creds',       [ __CLASS__, 'ajax_wp_rest_clear_creds' ] );
		add_action( 'wp_ajax_asae_ci_wp_rest_feed_status',       [ __CLASS__, 'ajax_wp_rest_feed_status' ] );
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

		// Detect any running job so the JS can auto-resume polling on page load.
		$running_job     = ASAE_CI_Scheduler::get_running_job();
		$running_job_key = $running_job ? $running_job['job_key'] : '';

		// Pass server-side data to the JS.
		wp_localize_script( 'asae-ci-admin', 'asaeCi', [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( self::AJAX_NONCE ),
			'runningJobKey' => $running_job_key,
			'strings'       => [
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
		if ( ! in_array( $active_tab, [ 'run', 'reports', 'youtube', 'wp-rest' ], true ) ) {
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
			$yt_api_key          = get_option( ASAE_CI_YouTube::OPTION_API_KEY, '' );
			$yt_api_key_saved    = (bool) $yt_api_key;
			$yt_api_key_mask     = $yt_api_key ? self::mask_api_key( $yt_api_key ) : '';
			$yt_channel_id       = get_option( ASAE_CI_YouTube::OPTION_CHANNEL_ID, '' );
			$yt_channel_id_saved = (bool) $yt_channel_id;
			$yt_channel_id_mask  = $yt_channel_id ? self::mask_api_key( $yt_channel_id ) : '';
			$yt_feed_status      = ASAE_CI_YouTube::get_feed_status();
			$view             = ASAE_CI_PATH . 'admin/views/page-youtube.php';
		} elseif ( 'wp-rest' === $active_tab ) {
			// WordPress REST API Feed tab.
			$wp_rest_feed_status = ASAE_CI_WP_REST::get_feed_status();
			$wp_rest_has_creds   = ASAE_CI_WP_REST::has_credentials();
			$wp_rest_site_url    = get_option( ASAE_CI_WP_REST::OPTION_SITE_URL, '' );
			$view                = ASAE_CI_PATH . 'admin/views/page-wp-rest.php';
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
		if ( ! in_array( $batch_limit, [ '10', '50', '100', '1000', 'all' ], true ) ) {
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
	 * AJAX: Cancels a running job, marking it as completed at its current point.
	 * Expects POST params: job_key, nonce.
	 *
	 * @return void
	 */
	public static function ajax_cancel_job(): void {
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

		if ( 'running' !== $job['status'] ) {
			wp_send_json_success( [ 'message' => __( 'Job is already stopped.', 'asae-content-ingestor' ) ] );
			return;
		}

		// Mark job as completed at its current progress.
		ASAE_CI_Scheduler::update_job( $job_key, [ 'status' => 'completed' ] );

		// Finalise the associated report with current counts.
		if ( $job['report_id'] ) {
			$queue_data = json_decode( $job['queue_data'], true );
			$ingest     = $queue_data['ingestion'] ?? [];
			ASAE_CI_Reports::update_report( (int) $job['report_id'], [
				'status'         => 'completed',
				'total_ingested' => (int) ( $ingest['processed'] ?? 0 ),
				'total_failed'   => (int) ( $ingest['failed']    ?? 0 ),
			] );
		}

		// Clear any scheduled cron events for this job.
		wp_clear_scheduled_hook( ASAE_CI_CRON_HOOK, [ $job_key ] );

		wp_send_json_success( [ 'message' => __( 'Job cancelled.', 'asae-content-ingestor' ) ] );
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
	 * On success, publishes the posts and clears their _asae_ci_needs_category meta.
	 * If no pending items remain the job status is set to 'completed'.
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

		$post_type = $job['post_type'] ?? 'post';
		$tax       = self::get_category_taxonomy( $post_type );

		// Apply each assignment: set category, publish the draft, clear meta flag.
		foreach ( $assignments as $assignment ) {
			$post_id = (int) ( $assignment['post_id'] ?? 0 );
			$term_id = (int) ( $assignment['term_id'] ?? 0 );

			if ( $post_id <= 0 || $term_id <= 0 || ! $tax ) {
				continue;
			}

			wp_set_object_terms( $post_id, [ $term_id ], $tax, false );
			wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
			delete_post_meta( $post_id, '_asae_ci_needs_category' );
		}

		// Check if any pending items remain (direct meta query — avoids loading queue_data).
		$remaining = self::count_pending_review_posts( $post_type );

		if ( 0 === $remaining ) {
			ASAE_CI_Scheduler::update_job( $job_key, [ 'status' => 'completed' ] );
			if ( $job['report_id'] ) {
				ASAE_CI_Reports::update_report( (int) $job['report_id'], [ 'status' => 'completed' ] );
			}
		}

		// Return a lightweight progress snapshot (no queue_data decode needed).
		wp_send_json_success( [
			'job_key'              => $job['job_key'],
			'status'               => 0 === $remaining ? 'completed' : $job['status'],
			'pending_review_total' => $remaining,
			'is_needs_review'      => $remaining > 0,
			'is_complete'          => 0 === $remaining,
			'run_type'             => $job['run_type'],
			'report_id'            => $job['report_id'],
		] );
	}

	// ── Paginated Category Review ────────────────────────────────────────────

	/**
	 * Returns a single page of pending review items for the category review UI.
	 *
	 * Queries posts with _asae_ci_needs_category meta directly (no queue_data
	 * decode needed), which keeps memory usage constant regardless of job size.
	 *
	 * Accepts: job_key, page (default 1), per_page (default 100), search (optional).
	 * Returns: { items: [...], total: N, page: N, pages: N, categories: [...] }
	 *
	 * @return void
	 */
	public static function ajax_fetch_review_page(): void {
		self::verify_ajax_nonce();
		self::verify_admin_capability();

		$job_key  = sanitize_text_field( wp_unslash( $_POST['job_key']  ?? '' ) );
		$page     = max( 1, (int) ( $_POST['page']     ?? 1 ) );
		$per_page = max( 1, min( 200, (int) ( $_POST['per_page'] ?? 100 ) ) );
		$search   = sanitize_text_field( wp_unslash( $_POST['search']   ?? '' ) );

		if ( empty( $job_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'asae-content-ingestor' ) ] );
		}

		$job = ASAE_CI_Scheduler::get_job( $job_key );
		if ( ! $job ) {
			wp_send_json_error( [ 'message' => __( 'Job not found.', 'asae-content-ingestor' ) ] );
		}

		$post_type = $job['post_type'] ?? 'post';

		// Query posts with the needs-category meta flag directly from the DB.
		$query_args = [
			'post_type'      => $post_type,
			'post_status'    => 'draft',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_key'       => '_asae_ci_needs_category',
			'meta_value'     => '1',
			'fields'         => 'ids',
		];

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$query = new \WP_Query( $query_args );
		$total = (int) $query->found_posts;
		$pages = max( 1, (int) $query->max_num_pages );
		$page  = min( $page, $pages );

		$items = [];
		foreach ( $query->posts as $post_id ) {
			$post = get_post( $post_id );
			$items[] = [
				'post_id'    => $post_id,
				'post_title' => $post ? $post->post_title : '',
				'source_url' => get_post_meta( $post_id, '_asae_ci_source_url', true ) ?: '',
			];
		}

		// Build category list.
		$tax        = self::get_category_taxonomy( $post_type );
		$categories = [];
		if ( $tax ) {
			$terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$categories[] = [ 'term_id' => $term->term_id, 'name' => $term->name ];
				}
			}
		}

		wp_send_json_success( [
			'items'      => $items,
			'total'      => $total,
			'page'       => $page,
			'pages'      => $pages,
			'categories' => $categories,
		] );
	}

	/**
	 * Applies a single category to a batch of pending review items.
	 *
	 * Queries posts with _asae_ci_needs_category meta directly (no queue_data
	 * decode needed). Processes up to 50 items per call to stay within PHP time
	 * limits. The JS client calls this repeatedly until `remaining` reaches 0.
	 *
	 * Returns: { applied: N, remaining: N, total: N, status: 'processing'|'completed' }
	 *
	 * @return void
	 */
	public static function ajax_apply_category_to_all(): void {
		self::verify_ajax_nonce();
		self::verify_admin_capability();

		$job_key = sanitize_text_field( wp_unslash( $_POST['job_key'] ?? '' ) );
		$term_id = (int) ( $_POST['term_id'] ?? 0 );

		if ( empty( $job_key ) || $term_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'asae-content-ingestor' ) ] );
		}

		$job = ASAE_CI_Scheduler::get_job( $job_key );
		if ( ! $job ) {
			wp_send_json_error( [ 'message' => __( 'Job not found.', 'asae-content-ingestor' ) ] );
		}

		$post_type = $job['post_type'] ?? 'post';
		$tax       = self::get_category_taxonomy( $post_type );

		if ( ! $tax ) {
			wp_send_json_error( [ 'message' => __( 'No category taxonomy found.', 'asae-content-ingestor' ) ] );
		}

		// Fetch a batch of post IDs directly from meta (avoids loading queue_data).
		$batch_size = 50;
		$post_ids   = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'draft',
			'posts_per_page' => $batch_size,
			'meta_key'       => '_asae_ci_needs_category',
			'meta_value'     => '1',
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );

		$applied = 0;
		foreach ( $post_ids as $post_id ) {
			wp_set_object_terms( $post_id, [ $term_id ], $tax, false );
			wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
			delete_post_meta( $post_id, '_asae_ci_needs_category' );
			$applied++;
		}

		$remaining = self::count_pending_review_posts( $post_type );

		if ( 0 === $remaining ) {
			ASAE_CI_Scheduler::update_job( $job_key, [ 'status' => 'completed' ] );
			if ( $job['report_id'] ) {
				ASAE_CI_Reports::update_report( (int) $job['report_id'], [ 'status' => 'completed' ] );
			}
		}

		wp_send_json_success( [
			'applied'   => $applied,
			'remaining' => $remaining,
			'total'     => $applied + $remaining,
			'status'    => 0 === $remaining ? 'completed' : 'processing',
		] );
	}

	// ── Review Helper Methods ────────────────────────────────────────────────

	/**
	 * Returns the hierarchical taxonomy name for a given post type.
	 *
	 * @param string $post_type WP post type slug.
	 * @return string Taxonomy name, or empty string if none found.
	 */
	private static function get_category_taxonomy( string $post_type ): string {
		if ( 'post' === $post_type ) {
			return 'category';
		}
		$taxons = get_object_taxonomies( $post_type, 'objects' );
		foreach ( $taxons as $t ) {
			if ( $t->hierarchical ) {
				return $t->name;
			}
		}
		return '';
	}

	/**
	 * Counts posts with the _asae_ci_needs_category meta flag.
	 *
	 * Uses a direct DB query for speed on large datasets.
	 *
	 * @param string $post_type WP post type slug.
	 * @return int Number of draft posts awaiting category assignment.
	 */
	private static function count_pending_review_posts( string $post_type ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_type = %s
			   AND p.post_status = 'draft'
			   AND pm.meta_key = '_asae_ci_needs_category'
			   AND pm.meta_value = '1'",
			$post_type
		) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	// ── Redirect Data Management ─────────────────────────────────────────────

	/**
	 * Clears all stored ASAEcenter.org redirect/source URL post meta.
	 *
	 * Removes _asae_ci_source_url meta entries that contain 'asaecenter.org',
	 * allowing the redirect JSON export to start fresh.
	 *
	 * @return void
	 */
	public static function ajax_clear_redirects(): void {
		self::verify_ajax_nonce();
		self::verify_admin_capability();

		global $wpdb;

		// Count matching rows first for the response.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
			 WHERE meta_key = '_asae_ci_source_url'
			   AND meta_value LIKE '%asaecenter.org%'"
		);

		// Delete all asaecenter.org source URL meta entries.
		$wpdb->query(
			"DELETE FROM {$wpdb->postmeta}
			 WHERE meta_key = '_asae_ci_source_url'
			   AND meta_value LIKE '%asaecenter.org%'"
		);
		// phpcs:enable

		wp_send_json_success( [
			'cleared' => $count,
			'message' => sprintf(
				/* translators: %d: number of redirect entries cleared */
				__( 'Cleared %d ASAEcenter.org redirect entries.', 'asae-content-ingestor' ),
				$count
			),
		] );
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
	 * AJAX: Saves the YouTube channel/playlist ID.
	 * Expects POST param: channel_id.
	 *
	 * @return void
	 */
	public static function ajax_save_youtube_channel_id(): void {
		self::verify_ajax_nonce();
		self::verify_admin_capability();

		$channel_id = sanitize_text_field( wp_unslash( $_POST['channel_id'] ?? '' ) );

		if ( empty( $channel_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Channel or playlist ID cannot be empty.', 'asae-content-ingestor' ) ] );
		}

		update_option( ASAE_CI_YouTube::OPTION_CHANNEL_ID, $channel_id, false );

		wp_send_json_success( [
			'message' => __( 'Channel ID saved.', 'asae-content-ingestor' ),
			'mask'    => self::mask_api_key( $channel_id ),
		] );
	}

	/**
	 * AJAX: Fetches all videos from the saved YouTube channel/playlist and
	 * generates an Atom XML feed file. Returns the feed URL, video count,
	 * and the full video list for client-side preview rendering.
	 *
	 * Uses the previously saved channel/playlist ID.
	 *
	 * @return void
	 */
	public static function ajax_generate_youtube_feed(): void {
		self::verify_ajax_nonce();
		self::verify_admin_capability();

		$channel_id = get_option( ASAE_CI_YouTube::OPTION_CHANNEL_ID, '' );

		if ( empty( $channel_id ) ) {
			wp_send_json_error( [ 'message' => __( 'No channel or playlist ID saved. Please save one first.', 'asae-content-ingestor' ) ] );
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

	// ── WordPress REST API Feed Tab AJAX Handlers ────────────────────────────

	/**
	 * AJAX: Discovers content types on a remote WordPress site.
	 *
	 * Accepts: site_url, username (optional), app_password (optional).
	 * Returns: array of types with slug, name, rest_base, count.
	 */
	public static function ajax_wp_rest_discover_types(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$site_url = esc_url_raw( wp_unslash( $_POST['site_url'] ?? '' ) );
		if ( empty( $site_url ) ) {
			wp_send_json_error( 'Site URL is required.' );
		}

		// Store credentials if provided.
		$username     = sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) );
		$app_password = sanitize_text_field( wp_unslash( $_POST['app_password'] ?? '' ) );
		if ( $username && $app_password ) {
			ASAE_CI_WP_REST::store_credentials( $username, $app_password );
		}

		// Save site URL for future page loads.
		update_option( ASAE_CI_WP_REST::OPTION_SITE_URL, $site_url, false );

		// Discover types.
		$types = ASAE_CI_WP_REST::discover_post_types( $site_url );
		if ( is_wp_error( $types ) ) {
			wp_send_json_error( $types->get_error_message() );
		}

		// Fetch counts for each type.
		$result = [];
		foreach ( $types as $slug => $type ) {
			$count    = ASAE_CI_WP_REST::fetch_type_count( $site_url, $type['rest_base'] );
			$result[] = [
				'slug'      => $slug,
				'name'      => $type['name'],
				'rest_base' => $type['rest_base'],
				'count'     => $count,
			];
		}

		wp_send_json_success( [
			'types'    => $result,
			'has_auth' => ASAE_CI_WP_REST::has_credentials(),
		] );
	}

	/**
	 * AJAX: Generates the WP REST feed one page at a time (chunked).
	 *
	 * Called repeatedly by JS until status is 'done'.
	 * Accepts: site_url, post_types[] (on first call), page (1-based).
	 */
	public static function ajax_wp_rest_generate_feed(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$site_url = esc_url_raw( wp_unslash( $_POST['site_url'] ?? '' ) );
		$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );

		if ( empty( $site_url ) ) {
			wp_send_json_error( 'Site URL is required.' );
		}

		// On the first call: resolve lookups and initialise state.
		if ( 1 === $page ) {
			$post_types_raw = $_POST['post_types'] ?? [];
			if ( ! is_array( $post_types_raw ) || empty( $post_types_raw ) ) {
				wp_send_json_error( 'Select at least one content type.' );
			}

			// Sanitize and store selected types.
			$selected_types = [];
			foreach ( $post_types_raw as $type_data ) {
				$selected_types[] = [
					'slug'      => sanitize_key( $type_data['slug'] ?? '' ),
					'rest_base' => sanitize_key( $type_data['rest_base'] ?? '' ),
				];
			}

			// Resolve lookups once.
			$lookups = ASAE_CI_WP_REST::resolve_lookups( $site_url );
			ASAE_CI_WP_REST::save_lookups( $lookups );

			// Clear previous accumulated state.
			ASAE_CI_WP_REST::clear_generation_state();
			ASAE_CI_WP_REST::save_lookups( $lookups );

			// Store selected types in transient for subsequent calls.
			set_transient( 'asae_ci_wp_rest_selected_types', $selected_types, 2 * HOUR_IN_SECONDS );

			// Determine total pages across all selected types.
			$total_pages = 0;
			$type_pages  = [];
			foreach ( $selected_types as $type ) {
				$count = ASAE_CI_WP_REST::fetch_type_count( $site_url, $type['rest_base'] );
				$pages = max( 1, (int) ceil( $count / ASAE_CI_WP_REST::API_PAGE_SIZE ) );
				$type_pages[] = [
					'slug'      => $type['slug'],
					'rest_base' => $type['rest_base'],
					'pages'     => $pages,
				];
				$total_pages += $pages;
			}

			set_transient( 'asae_ci_wp_rest_type_pages', $type_pages, 2 * HOUR_IN_SECONDS );
			ASAE_CI_WP_REST::save_generation_state( [], 0, $total_pages, 'fetching' );
		}

		// Load state.
		$accumulated = ASAE_CI_WP_REST::get_accumulated_posts();
		$type_pages  = get_transient( 'asae_ci_wp_rest_type_pages' );
		$progress    = ASAE_CI_WP_REST::get_generation_progress();

		if ( ! is_array( $type_pages ) || empty( $type_pages ) ) {
			wp_send_json_error( 'Generation state lost. Please start over.' );
		}

		// Determine which type and page to fetch based on the global page counter.
		$global_page   = $page;
		$pages_before  = 0;

		foreach ( $type_pages as $tp ) {
			if ( $global_page <= $pages_before + $tp['pages'] ) {
				// This is the type and local page to fetch.
				$local_page = $global_page - $pages_before;
				$result     = ASAE_CI_WP_REST::fetch_posts_page( $site_url, $tp['rest_base'], $local_page );

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( $result->get_error_message() );
				}

				$accumulated = array_merge( $accumulated, $result['posts'] );
				break;
			}
			$pages_before += $tp['pages'];
		}

		$total_pages = (int) $progress['total_pages'];

		// Check if we've fetched all pages.
		if ( $global_page >= $total_pages ) {
			// All pages fetched — generate feed and save.
			ASAE_CI_WP_REST::save_generation_state( $accumulated, $global_page, $total_pages, 'generating' );

			$lookups = ASAE_CI_WP_REST::get_stored_lookups();
			if ( ! is_array( $lookups ) ) {
				$lookups = ASAE_CI_WP_REST::resolve_lookups( $site_url );
			}

			$feed_xml = ASAE_CI_WP_REST::generate_feed( $accumulated, $lookups, $site_url );
			$feed_url = ASAE_CI_WP_REST::save_feed( $feed_xml );

			if ( is_wp_error( $feed_url ) ) {
				wp_send_json_error( $feed_url->get_error_message() );
			}

			// Generate and save author sidecar.
			$author_meta = ASAE_CI_WP_REST::generate_author_metadata( $accumulated, $lookups );
			ASAE_CI_WP_REST::save_author_sidecar( $author_meta );

			// Clean up.
			ASAE_CI_WP_REST::clear_generation_state();
			delete_transient( 'asae_ci_wp_rest_type_pages' );
			delete_transient( 'asae_ci_wp_rest_selected_types' );

			wp_send_json_success( [
				'status'      => 'done',
				'feed_url'    => $feed_url,
				'total_posts' => count( $accumulated ),
				'page'        => $global_page,
				'total_pages' => $total_pages,
				'has_authors' => ! empty( $author_meta ),
			] );
		} else {
			// More pages to fetch — save state and continue.
			ASAE_CI_WP_REST::save_generation_state( $accumulated, $global_page, $total_pages, 'fetching' );

			wp_send_json_success( [
				'status'      => 'fetching',
				'total_posts' => count( $accumulated ),
				'page'        => $global_page,
				'total_pages' => $total_pages,
			] );
		}
	}

	/**
	 * AJAX: Clears WP REST API credentials.
	 */
	public static function ajax_wp_rest_clear_creds(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		ASAE_CI_WP_REST::clear_credentials();
		wp_send_json_success();
	}

	/**
	 * AJAX: Returns the current WP REST feed status.
	 */
	public static function ajax_wp_rest_feed_status(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		wp_send_json_success( ASAE_CI_WP_REST::get_feed_status() );
	}
}
