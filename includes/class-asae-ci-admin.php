<?php
/**
 * ASAE Content Ingestor – Admin UI Controller
 *
 * Registers all WordPress admin-side features for this plugin:
 *  - A submenu page under Tools (accessible only to admins).
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

	// ── Initialisation ────────────────────────────────────────────────────────

	/**
	 * Registers all admin hooks. Called once from asae_ci_init() when is_admin().
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu',            [ __CLASS__, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

		// AJAX – logged-in admin users only.
		add_action( 'wp_ajax_asae_ci_start_job',       [ __CLASS__, 'ajax_start_job' ] );
		add_action( 'wp_ajax_asae_ci_process_batch',   [ __CLASS__, 'ajax_process_batch' ] );
		add_action( 'wp_ajax_asae_ci_get_progress',    [ __CLASS__, 'ajax_get_progress' ] );
		add_action( 'wp_ajax_asae_ci_delete_report',   [ __CLASS__, 'ajax_delete_report' ] );
	}

	// ── Menu Registration ─────────────────────────────────────────────────────

	/**
	 * Adds the plugin's pages under the WP Tools menu.
	 *
	 * @return void
	 */
	public static function register_menus(): void {
		add_management_page(
			__( 'ASAE Content Ingestor', 'asae-content-ingestor' ),
			__( 'Content Ingestor', 'asae-content-ingestor' ),
			'manage_options',
			'asae-content-ingestor',
			[ __CLASS__, 'render_main_page' ]
		);

		add_submenu_page(
			'tools.php',
			__( 'Ingestion Reports', 'asae-content-ingestor' ),
			__( 'Ingestor Reports', 'asae-content-ingestor' ),
			'manage_options',
			'asae-ci-reports',
			[ __CLASS__, 'render_reports_page' ]
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
			'tools_page_asae-content-ingestor',
			'tools_page_asae-ci-reports',
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
				'failed'         => __( 'An error occurred.', 'asae-content-ingestor' ),
				'confirmDelete'  => __( 'Delete this report? This cannot be undone.', 'asae-content-ingestor' ),
			],
		] );
	}

	// ── Page Renderers ────────────────────────────────────────────────────────

	/**
	 * Renders the main Tools > Content Ingestor page.
	 *
	 * @return void
	 */
	public static function render_main_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'asae-content-ingestor' ) );
		}
		$view = ASAE_CI_PATH . 'admin/views/page-main.php';
		if ( file_exists( $view ) ) {
			// Make plugin data available to the view template.
			$post_types = self::get_eligible_post_types();
			include $view;
		}
	}

	/**
	 * Renders the Ingestion Reports listing page.
	 *
	 * @return void
	 */
	public static function render_reports_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'asae-content-ingestor' ) );
		}

		// Report detail view.
		$report_id = isset( $_GET['report_id'] ) ? (int) $_GET['report_id'] : 0;
		if ( $report_id > 0 ) {
			$report = ASAE_CI_Reports::get_report( $report_id );
			if ( ! $report ) {
				wp_die( esc_html__( 'Report not found.', 'asae-content-ingestor' ) );
			}
			$page       = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
			$items_data = ASAE_CI_Reports::get_report_items( $report_id, $page, 50 );
			$view = ASAE_CI_PATH . 'admin/views/page-report-detail.php';
			if ( file_exists( $view ) ) {
				include $view;
			}
			return;
		}

		// Reports listing view.
		$page        = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$reports_data = ASAE_CI_Reports::get_reports( $page, 20 );
		$view = ASAE_CI_PATH . 'admin/views/page-reports.php';
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
		$batch_limit     = sanitize_text_field( $_POST['batch_limit'] ?? '50' );
		$run_type        = sanitize_text_field( $_POST['run_type']    ?? 'dry' );

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

		$job_key = ASAE_CI_Scheduler::create_job( [
			'source_url'      => $source_url,
			'url_restriction' => $url_restriction ?: null,
			'post_type'       => $post_type,
			'batch_limit'     => $batch_limit,
			'run_type'        => $run_type,
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
}
