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

		// Clean Up tab.
		add_action( 'wp_ajax_asae_ci_cancel_all_jobs',       [ __CLASS__, 'ajax_cancel_all_jobs' ] );
		add_action( 'wp_ajax_asae_ci_publish_all_drafts',    [ __CLASS__, 'ajax_publish_all_drafts' ] );
		add_action( 'wp_ajax_asae_ci_check_publish_dates',   [ __CLASS__, 'ajax_check_publish_dates' ] );
		add_action( 'wp_ajax_asae_ci_fix_redirects',         [ __CLASS__, 'ajax_fix_redirects' ] );
		add_action( 'wp_ajax_asae_ci_set_posts_per_page',    [ __CLASS__, 'ajax_set_posts_per_page' ] );
		add_action( 'wp_ajax_asae_ci_assign_sponsors',       [ __CLASS__, 'ajax_assign_sponsors' ] );

		// One to One tab.
		add_action( 'wp_ajax_asae_ci_one_to_one_validate_slug', [ __CLASS__, 'ajax_one_to_one_validate_slug' ] );
		add_action( 'wp_ajax_asae_ci_one_to_one_run',           [ __CLASS__, 'ajax_one_to_one_run' ] );

		// Settings tab.
		add_action( 'wp_ajax_asae_ci_check_updates', [ __CLASS__, 'ajax_check_updates' ] );

		// Content Areas (ASAE Publishing Workflow integration).
		add_action( 'wp_ajax_asae_ci_create_content_area',  [ __CLASS__, 'ajax_create_content_area' ] );
		add_action( 'wp_ajax_asae_ci_bulk_assign_areas_start',    [ __CLASS__, 'ajax_bulk_assign_areas_start' ] );
		add_action( 'wp_ajax_asae_ci_bulk_assign_areas_process',  [ __CLASS__, 'ajax_bulk_assign_areas_process' ] );
		add_action( 'wp_ajax_asae_ci_bulk_assign_areas_progress', [ __CLASS__, 'ajax_bulk_assign_areas_progress' ] );

		// Sponsor taxonomy term meta fields.
		add_action( 'sponsor_edit_form_fields', [ __CLASS__, 'sponsor_edit_fields' ], 10, 2 );
		add_action( 'sponsor_add_form_fields',  [ __CLASS__, 'sponsor_add_fields' ] );
		add_action( 'edited_sponsor',           [ __CLASS__, 'sponsor_save_fields' ] );
		add_action( 'created_sponsor',          [ __CLASS__, 'sponsor_save_fields' ] );

		// Show logo thumbnail in the Sponsors list table.
		add_filter( 'manage_edit-sponsor_columns',  [ __CLASS__, 'sponsor_columns' ] );
		add_filter( 'manage_sponsor_custom_column',  [ __CLASS__, 'sponsor_column_content' ], 10, 3 );
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

		// Enqueue the media library on sponsor taxonomy screens for logo picker.
		if ( in_array( $hook_suffix, [ 'edit-tags.php', 'term.php' ], true ) ) {
			$screen = get_current_screen();
			if ( $screen && 'sponsor' === $screen->taxonomy ) {
				wp_enqueue_media();
			}
		}

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
			'sponsorSlugs'  => self::get_sponsor_slugs(),
			'pluginsUrl'    => admin_url( 'plugins.php' ),
			'runningBulkAssignJobKey' => ( $bulk_job = ASAE_CI_Scheduler::get_running_bulk_assign_job() ) ? $bulk_job['job_key'] : '',
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
		if ( ! in_array( $active_tab, [ 'run', 'one-to-one', 'reports', 'youtube', 'wp-rest', 'cleanup', 'settings' ], true ) ) {
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
		} elseif ( 'cleanup' === $active_tab ) {
			// Clean Up tab.
			$view = ASAE_CI_PATH . 'admin/views/page-cleanup.php';
		} elseif ( 'one-to-one' === $active_tab ) {
			// One to One tab.
			$view = ASAE_CI_PATH . 'admin/views/page-one-to-one.php';
		} elseif ( 'settings' === $active_tab ) {
			// Settings tab.
			$view = ASAE_CI_PATH . 'admin/views/page-settings.php';
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

	/**
	 * Renders the shared navigation tab bar.
	 *
	 * @param string $active The currently active tab key.
	 */
	public static function render_nav_tabs( string $active ): void {
		$tabs = [
			'run'        => __( 'General Run', 'asae-content-ingestor' ),
			'one-to-one' => __( 'One to One', 'asae-content-ingestor' ),
			'youtube'    => __( 'YouTube Feed', 'asae-content-ingestor' ),
			'wp-rest'    => __( 'WordPress REST API', 'asae-content-ingestor' ),
			'cleanup'    => __( 'Clean Up', 'asae-content-ingestor' ),
			'reports'    => __( 'Reports', 'asae-content-ingestor' ),
			'settings'   => __( 'Settings', 'asae-content-ingestor' ),
		];

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Content Ingestor navigation', 'asae-content-ingestor' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$url   = 'run' === $key
				? admin_url( 'admin.php?page=asae-content-ingestor' )
				: admin_url( 'admin.php?page=asae-content-ingestor&tab=' . $key );
			$class = 'nav-tab' . ( $key === $active ? ' nav-tab-active' : '' );
			$aria  = $key === $active ? ' aria-current="page"' : '';
			echo '<a href="' . esc_url( $url ) . '" class="' . $class . '"' . $aria . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	// ── Content Areas (Publishing Workflow integration) ───────────────────────

	/** Taxonomy slug used by ASAE Publishing Workflow. */
	const CONTENT_AREA_TAXONOMY = 'asae_content_area';

	/**
	 * Returns true if the ASAE Publishing Workflow plugin is active and the
	 * Content Areas taxonomy is registered.
	 */
	public static function is_publishing_workflow_active(): bool {
		return taxonomy_exists( self::CONTENT_AREA_TAXONOMY );
	}

	/**
	 * Renders a Content Areas picker (multi-select + add new).
	 *
	 * @param string $field_name HTML name attribute (the field is always multi-select).
	 * @param string $instance   Unique instance suffix so multiple pickers on the same
	 *                           page get unique element IDs.
	 * @param bool   $disabled   Render the picker in a visually-disabled state with a
	 *                           "feature unavailable" notice (used on Clean Up tab when
	 *                           Publishing Workflow is not active).
	 */
	public static function render_content_areas_picker( string $field_name, string $instance = 'default', bool $disabled = false ): void {
		$active = self::is_publishing_workflow_active();
		if ( ! $active && ! $disabled ) {
			// Hidden when feature unavailable on the ingestion tabs.
			return;
		}

		$select_id   = 'asae-ci-ca-' . sanitize_html_class( $instance );
		$add_name_id = $select_id . '-new-name';
		$add_par_id  = $select_id . '-new-parent';
		$add_btn_id  = $select_id . '-add-btn';
		$add_msg_id  = $select_id . '-add-msg';

		$terms = $active ? get_terms( [
			'taxonomy'   => self::CONTENT_AREA_TAXONOMY,
			'hide_empty' => false,
			'orderby'    => 'name',
		] ) : [];

		$disabled_attr = $disabled || ! $active ? ' disabled' : '';
		?>
		<div class="asae-ci-field asae-ci-content-areas-picker" data-instance="<?php echo esc_attr( $instance ); ?>">
			<label for="<?php echo esc_attr( $select_id ); ?>">
				<?php esc_html_e( 'Content Areas', 'asae-content-ingestor' ); ?>
				<?php if ( $disabled || ! $active ) : ?>
					<em style="color:#666;font-weight:normal;">
						(<?php esc_html_e( 'disabled — ASAE Publishing Workflow plugin not active', 'asae-content-ingestor' ); ?>)
					</em>
				<?php endif; ?>
			</label>
			<select
				id="<?php echo esc_attr( $select_id ); ?>"
				name="<?php echo esc_attr( $field_name ); ?>[]"
				multiple
				size="6"
				class="asae-ci-ca-select"
				style="min-width:300px;"
				<?php echo $disabled_attr; ?>
			>
				<?php
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					self::render_content_area_options( $terms, 0 );
				}
				?>
			</select>
			<p class="description">
				<?php esc_html_e( 'Hold Ctrl/Cmd to select multiple. These will be assigned to every item processed in this run, replacing any previously assigned Content Areas.', 'asae-content-ingestor' ); ?>
			</p>

			<div class="asae-ci-ca-add" style="margin-top:8px;padding:8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;">
				<strong><?php esc_html_e( 'Add a new Content Area', 'asae-content-ingestor' ); ?></strong><br>
				<input
					type="text"
					id="<?php echo esc_attr( $add_name_id ); ?>"
					placeholder="<?php esc_attr_e( 'New Content Area name', 'asae-content-ingestor' ); ?>"
					style="margin-right:6px;"
					<?php echo $disabled_attr; ?>
				/>
				<select id="<?php echo esc_attr( $add_par_id ); ?>"<?php echo $disabled_attr; ?>>
					<option value="0"><?php esc_html_e( '(no parent)', 'asae-content-ingestor' ); ?></option>
					<?php
					if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
						self::render_content_area_options( $terms, 0, true );
					}
					?>
				</select>
				<button type="button" class="button asae-ci-ca-add-btn" id="<?php echo esc_attr( $add_btn_id ); ?>" data-target="<?php echo esc_attr( $select_id ); ?>" data-name-input="<?php echo esc_attr( $add_name_id ); ?>" data-parent-input="<?php echo esc_attr( $add_par_id ); ?>" data-msg="<?php echo esc_attr( $add_msg_id ); ?>"<?php echo $disabled_attr; ?>>
					<?php esc_html_e( 'Add', 'asae-content-ingestor' ); ?>
				</button>
				<span id="<?php echo esc_attr( $add_msg_id ); ?>" style="margin-left:8px;"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Recursive helper to render hierarchical <option> rows for the picker.
	 *
	 * @param array $terms     Flat list of WP_Term objects.
	 * @param int   $parent_id Current parent term_id to render children of.
	 * @param bool  $for_parent_dropdown True when rendering options for the "parent" select.
	 */
	public static function render_content_area_options( array $terms, int $parent_id, bool $for_parent_dropdown = false, int $depth = 0 ): void {
		foreach ( $terms as $term ) {
			if ( (int) $term->parent !== $parent_id ) {
				continue;
			}
			$indent = str_repeat( '— ', $depth );
			echo '<option value="' . esc_attr( $term->term_id ) . '">' . esc_html( $indent . $term->name ) . '</option>';
			self::render_content_area_options( $terms, (int) $term->term_id, $for_parent_dropdown, $depth + 1 );
		}
	}

	// ── AJAX Handlers ──────────��────────────────────────────��─────────────────

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
		$content_area_ids = array_values( array_filter( array_map( 'absint', (array) ( $_POST['content_area_ids'] ?? [] ) ) ) );

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
			'source_url'       => $source_url,
			'url_restriction'  => $url_restriction ?: null,
			'post_type'        => $post_type,
			'batch_limit'      => $batch_limit,
			'run_type'         => $run_type,
			'additional_tags'  => $additional_tags,
			'source_type'      => $source_type,
			'content_area_ids' => $content_area_ids,
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
	public static function get_eligible_post_types(): array {
		$types = get_post_types( [ 'public' => true ], 'objects' );
		unset( $types['attachment'] );
		return $types;
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

	// ── Clean Up Tab AJAX Handlers ───────────────────────────────────────────

	/**
	 * AJAX: Cancel all non-completed jobs.
	 * Sets every pending/running/needs_review/failed job to 'completed'.
	 */
	public static function ajax_cancel_all_jobs(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'asae_ci_jobs';

		$affected = $wpdb->query(
			"UPDATE {$table} SET status = 'completed' WHERE status IN ('pending', 'running', 'needs_review', 'failed')"
		);

		// Clear any scheduled cron events.
		wp_clear_scheduled_hook( ASAE_CI_CRON_HOOK );

		wp_send_json_success( [
			'cancelled' => (int) $affected,
		] );
	}

	/**
	 * AJAX: Publish a batch of draft posts.
	 * Uses direct $wpdb to avoid expensive wp_update_post hooks (Co-Authors Plus,
	 * Yoast, Jetpack, etc.) that can cause PHP timeouts on large sites.
	 * Processes up to 100 drafts per call. Returns the remaining count so JS can loop.
	 */
	public static function ajax_publish_all_drafts(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		global $wpdb;
		$batch_size = 100;

		// Direct DB query — avoids WP_Query overhead on large sites.
		$draft_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_status = 'draft'
			   AND pm.meta_key = '_asae_ci_source_url'
			 ORDER BY p.ID ASC
			 LIMIT %d",
			$batch_size
		) );

		$published = 0;
		foreach ( $draft_ids as $post_id ) {
			$post_id = (int) $post_id;
			// Direct status update — bypasses all save_post / transition hooks
			// that cause timeouts on plugin-heavy sites.
			$wpdb->update(
				$wpdb->posts,
				[ 'post_status' => 'publish' ],
				[ 'ID' => $post_id ],
				[ '%s' ],
				[ '%d' ]
			);
			delete_post_meta( $post_id, '_asae_ci_needs_category' );
			clean_post_cache( $post_id );
			$published++;
		}

		// Count remaining drafts with our source meta.
		$remaining = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_status = 'draft'
			   AND pm.meta_key = '_asae_ci_source_url'"
		);

		wp_send_json_success( [
			'published' => $published,
			'remaining' => $remaining,
		] );
	}

	/**
	 * AJAX: Check publish dates for a batch of posts against their external sources.
	 *
	 * Accepts: date_from, date_to (Y-m-d), offset (int).
	 * Fetches up to 5 posts per call, parses each source URL for its date,
	 * and updates the WP post_date if different.
	 */
	public static function ajax_check_publish_dates(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
		$date_to   = sanitize_text_field( $_POST['date_to'] ?? '' );
		$offset    = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
		$batch     = 5; // Small batch — each requires an HTTP fetch + parse.

		if ( ! $date_from || ! $date_to ) {
			wp_send_json_error( 'Date range required.' );
		}

		// Count total posts in range (on first call only, offset === 0).
		global $wpdb;
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_status = 'publish'
			   AND pm.meta_key = '_asae_ci_source_url'
			   AND p.post_date >= %s
			   AND p.post_date < %s",
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		) );

		// Fetch the batch.
		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_date, pm.meta_value AS source_url
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_status = 'publish'
			   AND pm.meta_key = '_asae_ci_source_url'
			   AND p.post_date >= %s
			   AND p.post_date < %s
			 ORDER BY p.ID ASC
			 LIMIT %d OFFSET %d",
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59',
			$batch,
			$offset
		) );

		$checked = 0;
		$updated = 0;
		$errors  = 0;
		$details = [];

		foreach ( $posts as $row ) {
			$checked++;
			$source_url = $row->source_url;

			// Fetch the external page.
			$response = wp_remote_get( $source_url, [
				'timeout'    => 15,
				'user-agent' => 'ASAE-Content-Ingestor/' . ASAE_CI_VERSION,
			] );

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				$errors++;
				$details[] = [
					'post_id' => (int) $row->ID,
					'status'  => 'fetch_error',
					'title'   => get_the_title( (int) $row->ID ),
				];
				continue;
			}

			$html   = wp_remote_retrieve_body( $response );
			$parsed = ASAE_CI_Parser::parse( $source_url, $html );

			if ( empty( $parsed['date'] ) ) {
				$details[] = [
					'post_id' => (int) $row->ID,
					'status'  => 'no_date',
					'title'   => get_the_title( (int) $row->ID ),
				];
				continue;
			}

			$source_date = $parsed['date']; // Y-m-d H:i:s
			$wp_date     = $row->post_date;

			// Compare dates (ignore seconds — compare down to the minute).
			if ( substr( $source_date, 0, 16 ) !== substr( $wp_date, 0, 16 ) ) {
				$post_id = (int) $row->ID;

				wp_update_post( [
					'ID'            => $post_id,
					'post_date'     => $source_date,
					'post_date_gmt' => get_gmt_from_date( $source_date ),
					'edit_date'     => true,
				] );

				$updated++;
				$details[] = [
					'post_id'  => $post_id,
					'status'   => 'updated',
					'title'    => get_the_title( $post_id ),
					'old_date' => $wp_date,
					'new_date' => $source_date,
				];
			} else {
				$details[] = [
					'post_id' => (int) $row->ID,
					'status'  => 'match',
					'title'   => get_the_title( (int) $row->ID ),
				];
			}
		}

		wp_send_json_success( [
			'checked'    => $checked,
			'updated'    => $updated,
			'errors'     => $errors,
			'details'    => $details,
			'total'      => $total,
			'offset'     => $offset + $checked,
			'done'       => ( $offset + $checked ) >= $total,
		] );
	}

	/**
	 * AJAX: Fix redirect target URLs for ingested posts.
	 *
	 * Loops through posts with _asae_ci_source_url meta in batches and ensures
	 * the Redirection plugin's stored target URL matches the current permalink.
	 * This is needed because date-based permalinks change when publish dates
	 * are corrected, but can also be run independently at any time.
	 *
	 * Accepts: offset (int). Processes 100 posts per call.
	 */
	public static function ajax_fix_redirects(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		global $wpdb;
		$items_table  = $wpdb->prefix . 'redirection_items';
		$table_exists = ( $wpdb->get_var( "SHOW TABLES LIKE '{$items_table}'" ) === $items_table );
		if ( ! $table_exists ) {
			wp_send_json_error( 'Redirection plugin tables not found.' );
		}

		$offset    = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
		$batch     = 100;

		// Total posts with source URLs.
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_status = 'publish'
			   AND pm.meta_key = '_asae_ci_source_url'"
		);

		// Fetch the batch.
		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, pm.meta_value AS source_url
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_status = 'publish'
			   AND pm.meta_key = '_asae_ci_source_url'
			 ORDER BY p.ID ASC
			 LIMIT %d OFFSET %d",
			$batch,
			$offset
		) );

		$checked = 0;
		$fixed   = 0;
		$skipped = 0;

		foreach ( $posts as $row ) {
			$checked++;
			$post_id     = (int) $row->ID;
			$source_url  = $row->source_url;
			$source_path = (string) parse_url( $source_url, PHP_URL_PATH );

			if ( ! $source_path ) {
				$skipped++;
				continue;
			}

			// Look up the current redirect target.
			$current_target = $wpdb->get_var( $wpdb->prepare(
				"SELECT action_data FROM {$items_table} WHERE url = %s LIMIT 1",
				$source_path
			) );

			if ( null === $current_target ) {
				$skipped++; // No redirect row for this source path.
				continue;
			}

			$correct_target = get_permalink( $post_id );
			if ( ! $correct_target ) {
				$skipped++;
				continue;
			}

			if ( $current_target !== $correct_target ) {
				$wpdb->update(
					$items_table,
					[ 'action_data' => esc_url_raw( $correct_target ) ],
					[ 'url' => $source_path ],
					[ '%s' ],
					[ '%s' ]
				);
				$fixed++;
			}
		}

		wp_send_json_success( [
			'checked' => $checked,
			'fixed'   => $fixed,
			'skipped' => $skipped,
			'total'   => $total,
			'offset'  => $offset + $checked,
			'done'    => ( $offset + $checked ) >= $total,
		] );
	}

	/**
	 * AJAX: Set the posts-per-page screen option for the All Posts list table.
	 */
	public static function ajax_set_posts_per_page(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$per_page = max( 1, min( 999, (int) ( $_POST['per_page'] ?? 20 ) ) );
		$user_id  = get_current_user_id();

		update_user_meta( $user_id, 'edit_post_per_page', $per_page );

		wp_send_json_success( [ 'per_page' => $per_page ] );
	}

	// ── Sponsor Assignment ───────────────────────────────────────────────────

	/**
	 * Reads sponsor slugs from the data file in the plugin root.
	 *
	 * @return string[]
	 */
	private static function get_sponsor_slugs(): array {
		$file = ASAE_CI_PATH . 'sponsors.gitignore_global';
		if ( ! file_exists( $file ) ) {
			return [];
		}
		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		return array_values( array_filter( array_map( 'trim', $lines ) ) );
	}

	/**
	 * AJAX: Process a single sponsor slug.
	 *
	 * Fetches the sponsor listing page from associationsnow.com, extracts the
	 * sponsor name/logo/article URLs, creates (or reuses) a taxonomy term, and
	 * assigns the term to all matching locally-ingested posts.
	 */
	public static function ajax_assign_sponsors(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$slug = sanitize_key( $_POST['slug'] ?? '' );
		if ( ! $slug ) {
			wp_send_json_error( 'No sponsor slug provided.' );
		}

		global $wpdb;

		// 1. Check if term already exists.
		$existing = term_exists( $slug, 'sponsor' );
		$term_id  = $existing ? (int) ( is_array( $existing ) ? $existing['term_id'] : $existing ) : 0;

		// Always re-process to update name/logo from article sponsor-meta.

		// 2. Fetch listing page.
		$url      = 'https://associationsnow.com/?taxonomy=sponsors&term=' . rawurlencode( $slug );
		$response = wp_remote_get( $url, [
			'timeout'    => 20,
			'user-agent' => 'ASAE-Content-Ingestor/' . ASAE_CI_VERSION,
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Still create the term with a fallback name so we can assign manually later.
			if ( ! $term_id ) {
				$fallback_name = ucwords( str_replace( '-', ' ', $slug ) );
				$result  = wp_insert_term( $fallback_name, 'sponsor', [ 'slug' => $slug ] );
				$term_id = is_array( $result ) ? (int) $result['term_id'] : 0;
			}
			wp_send_json_success( [
				'slug'           => $slug,
				'name'           => $term_id ? get_term( $term_id, 'sponsor' )->name : $slug,
				'term_id'        => $term_id,
				'logo_attached'  => false,
				'articles_found' => 0,
				'posts_matched'  => 0,
				'posts_assigned' => 0,
				'status'         => 'fetch_error',
			] );
		}

		$html = wp_remote_retrieve_body( $response );

		// 3. Parse the listing page for article URLs only.
		$parsed = self::parse_sponsor_listing( $html, $slug );

		// 4. Fetch the FIRST article to get real sponsor name + logo from div.sponsor-meta.
		$sponsor_name = ucwords( str_replace( '-', ' ', $slug ) ); // fallback
		$logo_url     = '';

		if ( ! empty( $parsed['article_urls'] ) ) {
			$article_url = $parsed['article_urls'][0];
			$art_resp    = wp_remote_get( $article_url, [
				'timeout'    => 20,
				'user-agent' => 'ASAE-Content-Ingestor/' . ASAE_CI_VERSION,
			] );

			if ( ! is_wp_error( $art_resp ) && 200 === wp_remote_retrieve_response_code( $art_resp ) ) {
				$art_html   = wp_remote_retrieve_body( $art_resp );
				$art_parsed = self::parse_sponsor_meta( $art_html, $slug );
				if ( $art_parsed['name'] ) {
					$sponsor_name = $art_parsed['name'];
				}
				if ( $art_parsed['logo_url'] ) {
					$logo_url = $art_parsed['logo_url'];
				}
			}
		}

		// 5. Create or update term.
		if ( ! $term_id ) {
			$result = wp_insert_term( $sponsor_name, 'sponsor', [ 'slug' => $slug ] );
			if ( is_wp_error( $result ) ) {
				// Term exists (race condition or slug mismatch) — get existing.
				$term_id = (int) $result->get_error_data();
			} else {
				$term_id = (int) $result['term_id'];
			}
		}
		// Always update name to the parsed value.
		if ( $term_id ) {
			wp_update_term( $term_id, 'sponsor', [ 'name' => $sponsor_name ] );
		}

		// 6. Download logo (always replace to fix bad logos from first run).
		$logo_attached = false;
		if ( $logo_url && $term_id ) {
			// Delete old logo attachment if it exists.
			$old_logo = (int) get_term_meta( $term_id, 'sponsor_logo', true );
			if ( $old_logo && get_post( $old_logo ) ) {
				wp_delete_attachment( $old_logo, true );
			}

			$attachment_id = ASAE_CI_Ingester::download_and_attach_image(
				$logo_url,
				0,
				$sponsor_name . ' Logo'
			);
			if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
				update_term_meta( $term_id, 'sponsor_logo', (int) $attachment_id );
				$logo_attached = true;
			}
		}

		// 6. Match article URLs against ingested posts.
		$articles_found = count( $parsed['article_urls'] );
		$posts_matched  = 0;
		$posts_assigned = 0;

		if ( $articles_found > 0 && $term_id ) {
			// Normalise URLs for matching (strip trailing slashes, force https).
			$normalised = array_map( function ( $u ) {
				$u = untrailingslashit( $u );
				$u = str_replace( 'http://', 'https://', $u );
				return $u;
			}, $parsed['article_urls'] );

			// Build placeholders for IN clause.
			// Also try with trailing slash variants for better matching.
			$all_variants = [];
			foreach ( $normalised as $n ) {
				$all_variants[] = $n;
				$all_variants[] = trailingslashit( $n );
			}
			$placeholders = implode( ', ', array_fill( 0, count( $all_variants ), '%s' ) );

			$matched_posts = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta}
					 WHERE meta_key = '_asae_ci_source_url'
					   AND meta_value IN ({$placeholders})",
					...$all_variants
				)
			);

			$posts_matched = count( $matched_posts );

			foreach ( $matched_posts as $post_id ) {
				$post_id = (int) $post_id;
				if ( ! has_term( $term_id, 'sponsor', $post_id ) ) {
					wp_set_object_terms( $post_id, [ $term_id ], 'sponsor', true );
					$posts_assigned++;
				}
			}
		}

		wp_send_json_success( [
			'slug'           => $slug,
			'name'           => $sponsor_name,
			'term_id'        => $term_id,
			'logo_attached'  => $logo_attached,
			'articles_found' => $articles_found,
			'posts_matched'  => $posts_matched,
			'posts_assigned' => $posts_assigned,
			'status'         => 'processed',
		] );
	}

	// ── One to One Tab AJAX Handlers ──────────────────────────────────────────

	/**
	 * AJAX: Validates that a slug is available for the given post type.
	 */
	public static function ajax_one_to_one_validate_slug(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$slug      = sanitize_title( $_POST['slug'] ?? '' );
		$post_type = sanitize_key( $_POST['post_type'] ?? 'post' );

		if ( ! $slug ) {
			wp_send_json_error( 'Empty slug.' );
		}

		// Check if a post/page with this slug already exists.
		global $wpdb;
		$exists = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_status IN ('publish','draft','pending','private') LIMIT 1",
			$slug,
			$post_type
		) );

		wp_send_json_success( [
			'slug'      => $slug,
			'available' => ! $exists,
		] );
	}

	/**
	 * AJAX: Runs a single One-to-One ingestion with verbose step-by-step output.
	 *
	 * Returns a structured response with a log array of every step taken.
	 */
	public static function ajax_one_to_one_run(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$source_url     = esc_url_raw( wp_unslash( $_POST['source_url'] ?? '' ) );
		$post_type      = sanitize_key( $_POST['post_type'] ?? 'post' );
		$custom_title   = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$custom_slug    = sanitize_title( $_POST['slug'] ?? '' );
		$desired_status   = sanitize_key( $_POST['status'] ?? 'draft' );
		$parent_id        = (int) ( $_POST['parent'] ?? 0 );
		$content_area_ids = array_values( array_filter( array_map( 'absint', (array) ( $_POST['content_area_ids'] ?? [] ) ) ) );

		$log = [];

		if ( ! $source_url ) {
			wp_send_json_error( [ 'log' => [ 'ERROR: No source URL provided.' ] ] );
		}

		// Step 1: Fetch the source page.
		$log[] = 'Fetching source URL: ' . $source_url;

		$response = wp_remote_get( $source_url, [
			'timeout'    => 30,
			'user-agent' => 'ASAE-Content-Ingestor/' . ASAE_CI_VERSION,
		] );

		if ( is_wp_error( $response ) ) {
			$log[] = 'ERROR: Failed to fetch URL — ' . $response->get_error_message();
			wp_send_json_error( [ 'log' => $log ] );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$log[] = 'HTTP response: ' . $http_code;

		if ( 200 !== $http_code ) {
			$log[] = 'ERROR: Non-200 response code. Aborting.';
			wp_send_json_error( [ 'log' => $log ] );
		}

		$html = wp_remote_retrieve_body( $response );
		$log[] = 'Received ' . number_format( strlen( $html ) ) . ' bytes of HTML.';

		// Step 2: Parse the HTML.
		$log[] = 'Parsing HTML for article data...';
		$parsed = ASAE_CI_Parser::parse( $source_url, $html );

		$log[] = 'Title: ' . ( $parsed['title'] ?: '(none found)' );
		$log[] = 'Date: ' . ( $parsed['date'] ?: '(none found)' );

		// Report authors.
		$authors = $parsed['authors'] ?? [];
		if ( empty( $authors ) && ! empty( $parsed['author'] ) ) {
			$authors = [ $parsed['author'] ];
		}
		$log[] = 'Authors: ' . ( $authors ? implode( ', ', $authors ) : '(none found)' );
		$log[] = 'Tags: ' . ( ! empty( $parsed['tags'] ) ? implode( ', ', $parsed['tags'] ) : '(none found)' );
		$log[] = 'Featured image: ' . ( $parsed['featured_image'] ?: '(none found)' );
		$log[] = 'Inline images: ' . count( $parsed['inline_images'] ?? [] );
		$log[] = 'Excerpt: ' . ( $parsed['excerpt'] ? mb_substr( $parsed['excerpt'], 0, 80 ) . '...' : '(none found)' );

		// Step 3: Check for duplicate.
		$log[] = 'Checking for duplicate source URL...';
		if ( ASAE_CI_Ingester::is_duplicate( $source_url ) ) {
			$log[] = 'WARNING: A post with this source URL already exists.';
			wp_send_json_error( [ 'log' => $log ] );
		}
		$log[] = 'No duplicate found.';

		// Step 4: Override title/slug if provided.
		if ( $custom_title ) {
			$parsed['title'] = $custom_title;
			$log[] = 'Overriding title with: ' . $custom_title;
		}

		$title = sanitize_text_field( $parsed['title'] ?? '' ) ?: __( '(Untitled)', 'asae-content-ingestor' );
		$log[] = 'Final title: ' . $title;

		// Step 5: Build the post.
		$log[] = 'Creating ' . $post_type . '...';

		$content = wp_kses_post( $parsed['content'] ?? '' );
		$date    = $parsed['date'] ?? '';
		$excerpt = sanitize_textarea_field( $parsed['excerpt'] ?? '' );

		$post_arr = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $desired_status,
			'post_type'    => $post_type,
		];

		if ( $custom_slug ) {
			$post_arr['post_name'] = $custom_slug;
			$log[] = 'Using custom slug: ' . $custom_slug;
		}

		if ( $parent_id > 0 ) {
			$post_arr['post_parent'] = $parent_id;
			$parent_title = get_the_title( $parent_id );
			$log[] = 'Setting parent page: ' . $parent_title . ' (ID: ' . $parent_id . ')';
		}

		if ( $excerpt ) {
			$post_arr['post_excerpt'] = $excerpt;
		}

		// Apply publication date if one was found (regardless of draft/publish choice).
		if ( $date ) {
			$post_arr['post_date']     = $date;
			$post_arr['post_date_gmt'] = get_gmt_from_date( $date );
			$log[] = 'Setting publish date to: ' . $date;
		}

		// Step 6: Process authors.
		$author_ids = [];
		foreach ( $authors as $idx => $author_name ) {
			$author_name = sanitize_text_field( $author_name );
			if ( empty( $author_name ) ) {
				continue;
			}
			$log[] = 'Processing author: ' . $author_name;
			$bio_url   = 0 === $idx ? ( $parsed['author_bio_url']   ?? '' ) : '';
			$bio_text  = 0 === $idx ? ( $parsed['author_bio']       ?? '' ) : '';
			$photo_url = 0 === $idx ? ( $parsed['author_photo_url'] ?? '' ) : '';
			$aid = ASAE_CI_Ingester::get_or_create_author_user(
				$author_name,
				$bio_url,
				sanitize_textarea_field( $bio_text ),
				$photo_url
			);
			if ( $aid ) {
				$author_ids[] = $aid;
				$log[] = 'Author resolved to user ID: ' . $aid;
			}
		}
		if ( ! empty( $author_ids ) ) {
			$post_arr['post_author'] = $author_ids[0];
		}

		// Step 7: Insert the post.
		$post_id = wp_insert_post( $post_arr, true );
		if ( is_wp_error( $post_id ) ) {
			$log[] = 'ERROR: Failed to create post — ' . $post_id->get_error_message();
			wp_send_json_error( [ 'log' => $log ] );
		}
		$log[] = 'Created ' . $post_type . ' ID: ' . $post_id;

		// Co-Authors Plus integration.
		if ( ! empty( $author_ids ) && ASAE_CI_Ingester::cap_is_active() ) {
			global $coauthors_plus;
			$coauthor_logins = [];
			foreach ( $author_ids as $aid ) {
				$author_user = get_user_by( 'id', $aid );
				if ( $author_user ) {
					$coauthor_logins[] = $author_user->user_login;
				}
			}
			if ( ! empty( $coauthor_logins ) ) {
				$coauthors_plus->add_coauthors( $post_id, $coauthor_logins, false );
				$log[] = 'Assigned Co-Authors Plus: ' . implode( ', ', $coauthor_logins );
			}
		}

		// Step 8: Store source URL meta.
		update_post_meta( $post_id, '_asae_ci_source_url', esc_url_raw( $source_url ) );
		$log[] = 'Stored source URL as post meta.';

		// Step 8b: Assign Content Areas if requested and Publishing Workflow is active.
		if ( ! empty( $content_area_ids ) && self::is_publishing_workflow_active() ) {
			wp_set_object_terms( $post_id, $content_area_ids, self::CONTENT_AREA_TAXONOMY, false );
			$log[] = 'Assigned ' . count( $content_area_ids ) . ' Content Area(s).';
		}

		// Step 9: Assign tags (only for post types that support them).
		$tags = array_values( array_unique( array_filter( $parsed['tags'] ?? [] ) ) );
		if ( ! empty( $tags ) && is_object_in_taxonomy( $post_type, 'post_tag' ) ) {
			wp_set_object_terms( $post_id, $tags, 'post_tag', true );
			$log[] = 'Assigned ' . count( $tags ) . ' tags: ' . implode( ', ', $tags );
		} elseif ( ! empty( $tags ) ) {
			$log[] = 'Skipping tags — ' . $post_type . ' does not support tags.';
		}

		// Step 10: Process images.
		$featured_url  = $parsed['featured_image'] ?? '';
		$inline_images = $parsed['inline_images']  ?? [];
		$body_content  = $content;

		if ( $featured_url ) {
			$feat_base     = ASAE_CI_Ingester::normalize_image_base( $featured_url );
			$body_content  = ASAE_CI_Ingester::remove_featured_image_from_content( $body_content, $featured_url );
			$inline_images = array_values( array_filter(
				$inline_images,
				fn( $img_url ) => ASAE_CI_Ingester::normalize_image_base( $img_url ) !== $feat_base
			) );
		}

		if ( ! empty( $inline_images ) ) {
			$log[] = 'Downloading ' . count( $inline_images ) . ' inline images...';
			$updated_content = ASAE_CI_Ingester::process_inline_images( $post_id, $body_content, $inline_images );
			if ( $updated_content !== $content ) {
				wp_update_post( [
					'ID'           => $post_id,
					'post_content' => $updated_content,
				] );
				$log[] = 'Inline images downloaded and content updated.';
			}
		}

		if ( $featured_url ) {
			$log[] = 'Downloading featured image: ' . $featured_url;
			$attachment_id = ASAE_CI_Ingester::download_and_attach_image( $featured_url, $post_id, $title );
			if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
				set_post_thumbnail( $post_id, $attachment_id );
				$log[] = 'Featured image set (attachment ID: ' . $attachment_id . ').';
			} else {
				$log[] = 'WARNING: Failed to download featured image.';
			}
		}

		// Step 11: Assign category (only for post types that support categories).
		if ( is_object_in_taxonomy( $post_type, 'category' ) ) {
			$log[] = 'Looking for matching category...';
			$has_category = ASAE_CI_Ingester::assign_category( $post_id, $tags, $title, $post_type );
			if ( $has_category ) {
				$log[] = 'Category assigned.';
			} else {
				$log[] = 'No matching category found — flagged for review.';
				update_post_meta( $post_id, '_asae_ci_needs_category', 1 );
			}
		} else {
			$log[] = 'Skipping category — ' . $post_type . ' does not support categories.';
		}

		// Step 12: Check for sponsor.
		$log[] = 'Checking for sponsor metadata...';
		$sponsor_parsed = self::parse_sponsor_meta( $html, '' );
		if ( $sponsor_parsed['name'] ) {
			$sponsor_slug = sanitize_title( $sponsor_parsed['name'] );
			$log[] = 'Sponsor found: ' . $sponsor_parsed['name'];
			$existing = term_exists( $sponsor_slug, 'sponsor' );
			if ( $existing ) {
				$sponsor_term_id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
			} else {
				$result = wp_insert_term( $sponsor_parsed['name'], 'sponsor', [ 'slug' => $sponsor_slug ] );
				$sponsor_term_id = is_array( $result ) ? (int) $result['term_id'] : 0;
				if ( $sponsor_term_id ) {
					$log[] = 'Created new sponsor term: ' . $sponsor_parsed['name'];
				}
			}
			if ( $sponsor_term_id ) {
				wp_set_object_terms( $post_id, [ $sponsor_term_id ], 'sponsor', true );
				$log[] = 'Sponsor term assigned to post.';

				// Download sponsor logo if we have one and the term doesn't already.
				if ( $sponsor_parsed['logo_url'] && ! get_term_meta( $sponsor_term_id, 'sponsor_logo', true ) ) {
					$logo_id = ASAE_CI_Ingester::download_and_attach_image(
						$sponsor_parsed['logo_url'],
						0,
						$sponsor_parsed['name'] . ' Logo'
					);
					if ( $logo_id && ! is_wp_error( $logo_id ) ) {
						update_term_meta( $sponsor_term_id, 'sponsor_logo', (int) $logo_id );
						$log[] = 'Sponsor logo downloaded and saved.';
					}
				}
			}
		} else {
			$log[] = 'No sponsor metadata found.';
		}

		// Step 13: Register redirect.
		$log[] = 'Registering redirect from source URL...';
		ASAE_CI_Ingester::maybe_register_redirect( $post_id, $source_url, 'replace' );
		$log[] = 'Redirect registered.';

		// Done.
		$edit_url = get_edit_post_link( $post_id, 'raw' );
		$view_url = get_permalink( $post_id );
		$log[] = 'DONE: ' . ucfirst( $post_type ) . ' created successfully.';

		wp_send_json_success( [
			'log'      => $log,
			'post_id'  => $post_id,
			'edit_url' => $edit_url,
			'view_url' => $view_url,
		] );
	}

	// ── Settings Tab AJAX Handlers ────────────────────────────────────────────

	/**
	 * AJAX: Clears cached GitHub release data and checks for plugin updates.
	 */
	public static function ajax_check_updates(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		// Clear cached release data.
		delete_transient( 'asae_ci_github_release' );
		delete_site_transient( 'update_plugins' );

		// Fetch fresh release data.
		$updater = new ASAE_CI_GitHub_Updater();
		$release = $updater->get_latest_release();

		// Trigger WordPress update check.
		wp_update_plugins();

		$latest_version = $release && isset( $release->tag_name )
			? ltrim( $release->tag_name, 'vV' )
			: null;

		wp_send_json_success( [
			'current_version' => ASAE_CI_VERSION,
			'latest_version'  => $latest_version,
			'update_available' => $latest_version && version_compare( $latest_version, ASAE_CI_VERSION, '>' ),
		] );
	}

	// ── Content Areas AJAX ────────────────────────────────────────────────────

	/**
	 * AJAX: Creates a new Content Area term.
	 */
	public static function ajax_create_content_area(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		if ( ! self::is_publishing_workflow_active() ) {
			wp_send_json_error( [ 'message' => 'ASAE Publishing Workflow plugin is not active.' ] );
		}

		$name      = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$parent_id = (int) ( $_POST['parent'] ?? 0 );

		if ( '' === $name ) {
			wp_send_json_error( [ 'message' => 'Name is required.' ] );
		}

		$args = [];
		if ( $parent_id > 0 ) {
			$args['parent'] = $parent_id;
		}

		$result = wp_insert_term( $name, self::CONTENT_AREA_TAXONOMY, $args );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$term_id = (int) $result['term_id'];
		$term    = get_term( $term_id, self::CONTENT_AREA_TAXONOMY );
		// Compute depth for indentation in the picker.
		$depth = 0;
		$cur   = $term;
		while ( $cur && (int) $cur->parent > 0 ) {
			$depth++;
			$cur = get_term( $cur->parent, self::CONTENT_AREA_TAXONOMY );
		}

		wp_send_json_success( [
			'term_id' => $term_id,
			'name'    => $term->name,
			'depth'   => $depth,
		] );
	}

	// ── Bulk Assign Content Areas (Clean Up) AJAX ─────────────────────────────

	/**
	 * AJAX: Starts a bulk-assign-content-areas job.
	 */
	public static function ajax_bulk_assign_areas_start(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}
		if ( ! self::is_publishing_workflow_active() ) {
			wp_send_json_error( [ 'message' => 'ASAE Publishing Workflow plugin is not active.' ] );
		}

		$post_type    = sanitize_key( $_POST['post_type'] ?? 'post' );
		$filter_mode  = sanitize_key( $_POST['filter_mode'] ?? 'all' ); // all|none|has_any|has_term
		$filter_term  = (int) ( $_POST['filter_term'] ?? 0 );
		$target_ids   = array_filter( array_map( 'absint', (array) ( $_POST['target_ids'] ?? [] ) ) );

		if ( empty( $target_ids ) ) {
			wp_send_json_error( [ 'message' => 'Select at least one target Content Area.' ] );
		}

		$job_key = ASAE_CI_Scheduler::create_bulk_assign_areas_job( [
			'post_type'   => $post_type,
			'filter_mode' => $filter_mode,
			'filter_term' => $filter_term,
			'target_ids'  => array_values( $target_ids ),
		] );

		if ( is_wp_error( $job_key ) ) {
			wp_send_json_error( [ 'message' => $job_key->get_error_message() ] );
		}

		wp_send_json_success( [ 'job_key' => $job_key ] );
	}

	/**
	 * AJAX: Processes one batch of a bulk-assign job and returns progress.
	 */
	public static function ajax_bulk_assign_areas_process(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$job_key = sanitize_text_field( wp_unslash( $_POST['job_key'] ?? '' ) );
		if ( '' === $job_key ) {
			wp_send_json_error( [ 'message' => 'Missing job_key.' ] );
		}

		$result = ASAE_CI_Scheduler::process_bulk_assign_areas_batch( $job_key );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Returns the progress snapshot of a bulk-assign job without processing.
	 */
	public static function ajax_bulk_assign_areas_progress(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$job_key = sanitize_text_field( wp_unslash( $_POST['job_key'] ?? '' ) );
		if ( '' === $job_key ) {
			wp_send_json_error( [ 'message' => 'Missing job_key.' ] );
		}

		$job = ASAE_CI_Scheduler::get_job( $job_key );
		if ( ! $job ) {
			wp_send_json_error( [ 'message' => 'Job not found.' ] );
		}
		wp_send_json_success( ASAE_CI_Scheduler::build_bulk_assign_progress( $job ) );
	}

	// ── Sponsor Taxonomy Term Meta Fields ─────────────────────────────────────

	/**
	 * Renders custom fields on the Edit Sponsor term screen.
	 *
	 * @param WP_Term $term     Current term object.
	 * @param string  $taxonomy Taxonomy slug.
	 */
	public static function sponsor_edit_fields( $term, $taxonomy ): void {
		$logo_id = (int) get_term_meta( $term->term_id, 'sponsor_logo', true );
		$logo_html = '';
		if ( $logo_id ) {
			$img = wp_get_attachment_image( $logo_id, [ 150, 150 ] );
			if ( $img ) {
				$logo_html = $img;
			}
		}
		?>
		<tr class="form-field">
			<th scope="row"><label for="sponsor_logo"><?php esc_html_e( 'Logo', 'asae-content-ingestor' ); ?></label></th>
			<td>
				<div id="sponsor-logo-preview" style="margin-bottom:8px;"><?php echo $logo_html; ?></div>
				<input type="hidden" name="sponsor_logo" id="sponsor_logo" value="<?php echo esc_attr( $logo_id ); ?>" />
				<button type="button" class="button" id="sponsor-logo-select"><?php esc_html_e( 'Select Logo', 'asae-content-ingestor' ); ?></button>
				<button type="button" class="button" id="sponsor-logo-remove" <?php echo $logo_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove Logo', 'asae-content-ingestor' ); ?></button>
				<p class="description"><?php esc_html_e( 'Sponsor logo image from the Media Library.', 'asae-content-ingestor' ); ?></p>
				<script>
				jQuery(function($){
					var frame;
					$('#sponsor-logo-select').on('click',function(e){
						e.preventDefault();
						if(frame){frame.open();return;}
						frame=wp.media({title:'<?php echo esc_js( __( 'Select Sponsor Logo', 'asae-content-ingestor' ) ); ?>',button:{text:'<?php echo esc_js( __( 'Use as Logo', 'asae-content-ingestor' ) ); ?>'},multiple:false});
						frame.on('select',function(){
							var a=frame.state().get('selection').first().toJSON();
							$('#sponsor_logo').val(a.id);
							var url=a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail.url:a.url;
							$('#sponsor-logo-preview').html('<img src="'+url+'" style="max-width:150px;max-height:150px;">');
							$('#sponsor-logo-remove').show();
						});
						frame.open();
					});
					$('#sponsor-logo-remove').on('click',function(e){
						e.preventDefault();
						$('#sponsor_logo').val('');
						$('#sponsor-logo-preview').html('');
						$(this).hide();
					});
				});
				</script>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders custom fields on the Add New Sponsor form.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function sponsor_add_fields( $taxonomy ): void {
		?>
		<div class="form-field">
			<label for="sponsor_logo"><?php esc_html_e( 'Logo', 'asae-content-ingestor' ); ?></label>
			<div id="sponsor-logo-preview" style="margin-bottom:8px;"></div>
			<input type="hidden" name="sponsor_logo" id="sponsor_logo" value="" />
			<button type="button" class="button" id="sponsor-logo-select"><?php esc_html_e( 'Select Logo', 'asae-content-ingestor' ); ?></button>
			<button type="button" class="button" id="sponsor-logo-remove" style="display:none;"><?php esc_html_e( 'Remove Logo', 'asae-content-ingestor' ); ?></button>
			<p class="description"><?php esc_html_e( 'Sponsor logo image from the Media Library.', 'asae-content-ingestor' ); ?></p>
			<script>
			jQuery(function($){
				var frame;
				$('#sponsor-logo-select').on('click',function(e){
					e.preventDefault();
					if(frame){frame.open();return;}
					frame=wp.media({title:'<?php echo esc_js( __( 'Select Sponsor Logo', 'asae-content-ingestor' ) ); ?>',button:{text:'<?php echo esc_js( __( 'Use as Logo', 'asae-content-ingestor' ) ); ?>'},multiple:false});
					frame.on('select',function(){
						var a=frame.state().get('selection').first().toJSON();
						$('#sponsor_logo').val(a.id);
						var url=a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail.url:a.url;
						$('#sponsor-logo-preview').html('<img src="'+url+'" style="max-width:150px;max-height:150px;">');
						$('#sponsor-logo-remove').show();
					});
					frame.open();
				});
				$('#sponsor-logo-remove').on('click',function(e){
					e.preventDefault();
					$('#sponsor_logo').val('');
					$('#sponsor-logo-preview').html('');
					$(this).hide();
				});
			});
			</script>
		</div>
		<?php
	}

	/**
	 * Saves the sponsor logo term meta when a term is created or edited.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function sponsor_save_fields( $term_id ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['sponsor_logo'] ) ) {
			$logo_id = (int) $_POST['sponsor_logo'];
			if ( $logo_id ) {
				update_term_meta( $term_id, 'sponsor_logo', $logo_id );
			} else {
				delete_term_meta( $term_id, 'sponsor_logo' );
			}
		}
	}

	/**
	 * Adds a Logo column to the Sponsors list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function sponsor_columns( $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			if ( 'name' === $key ) {
				$new['sponsor_logo'] = __( 'Logo', 'asae-content-ingestor' );
			}
			$new[ $key ] = $label;
		}
		return $new;
	}

	/**
	 * Renders the Logo column content in the Sponsors list table.
	 *
	 * @param string $content     Column content.
	 * @param string $column_name Column name.
	 * @param int    $term_id     Term ID.
	 * @return string Modified content.
	 */
	public static function sponsor_column_content( $content, $column_name, $term_id ): string {
		if ( 'sponsor_logo' === $column_name ) {
			$logo_id = (int) get_term_meta( $term_id, 'sponsor_logo', true );
			if ( $logo_id ) {
				$img = wp_get_attachment_image( $logo_id, [ 40, 40 ], false, [ 'style' => 'max-width:40px;max-height:40px;' ] );
				return $img ?: '&mdash;';
			}
			return '&mdash;';
		}
		return $content;
	}

	/**
	 * Parses a sponsor listing page from associationsnow.com.
	 *
	 * Extracts the sponsor display name, logo URL, description, and article URLs.
	 *
	 * @param string $html Raw HTML of the listing page.
	 * @param string $slug Sponsor slug (used as fallback for the name).
	 * @return array { name: string, logo_url: string, description: string, article_urls: string[] }
	 */
	private static function parse_sponsor_listing( string $html, string $slug ): array {
		$fallback_name = ucwords( str_replace( '-', ' ', $slug ) );

		$result = [
			'name'         => $fallback_name,
			'logo_url'     => '',
			'description'  => '',
			'article_urls' => [],
		];

		if ( empty( $html ) ) {
			return $result;
		}

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();

		$xpath = new \DOMXPath( $dom );

		// ── Sponsor name: look for the page heading ──
		// Try h1 first, then fallback to the <title> tag.
		$h1_nodes = $xpath->query( '//h1' );
		if ( $h1_nodes && $h1_nodes->length > 0 ) {
			$h1_text = trim( $h1_nodes->item( 0 )->textContent );
			if ( $h1_text && strlen( $h1_text ) < 200 ) {
				$result['name'] = $h1_text;
			}
		}

		// ── Sponsor logo: look for img in the header area ──
		// Strategy 1: img with sponsor name in alt text.
		$imgs = $xpath->query( '//img' );
		if ( $imgs ) {
			foreach ( $imgs as $img ) {
				$alt = strtolower( $img->getAttribute( 'alt' ) ?? '' );
				$src = $img->getAttribute( 'src' ) ?? '';
				if ( ! $src ) {
					continue;
				}
				// Match if alt contains the slug words or "logo".
				$slug_words = explode( '-', $slug );
				$match      = false;
				foreach ( $slug_words as $word ) {
					if ( strlen( $word ) >= 3 && stripos( $alt, $word ) !== false ) {
						$match = true;
						break;
					}
				}
				if ( $match || stripos( $alt, 'logo' ) !== false ) {
					// Prefer wp-content/uploads images over external/ad images.
					if ( strpos( $src, 'wp-content/uploads' ) !== false ) {
						$result['logo_url'] = $src;
						break;
					}
					if ( ! $result['logo_url'] ) {
						$result['logo_url'] = $src;
					}
				}
			}
		}

		// ── Article URLs: links matching the article URL pattern ──
		$links = $xpath->query( '//a[@href]' );
		if ( $links ) {
			foreach ( $links as $link ) {
				$href = $link->getAttribute( 'href' );
				// Match associationsnow.com article URLs: /YYYY/MM/slug/
				if ( preg_match( '#associationsnow\.com/\d{4}/\d{2}/[^/]+#', $href ) ) {
					$result['article_urls'][] = $href;
				}
			}
			$result['article_urls'] = array_unique( $result['article_urls'] );
		}

		return $result;
	}

	/**
	 * Parses a single article page for the sponsor name and logo from div.sponsor-meta.
	 *
	 * Expected HTML structure:
	 *   <div class="sponsor-meta">
	 *     <span><img src="logo.png" ...></span>
	 *     <span>Sponsored By Sponsor Name</span>
	 *   </div>
	 *
	 * @param string $html Raw article HTML.
	 * @param string $slug Sponsor slug (fallback).
	 * @return array { name: string, logo_url: string }
	 */
	private static function parse_sponsor_meta( string $html, string $slug ): array {
		$result = [
			'name'     => '',
			'logo_url' => '',
		];

		if ( empty( $html ) ) {
			return $result;
		}

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();

		$xpath = new \DOMXPath( $dom );

		// Find the div.sponsor-meta element.
		$sponsor_divs = $xpath->query( '//div[contains(@class, "sponsor-meta")]' );
		if ( ! $sponsor_divs || 0 === $sponsor_divs->length ) {
			return $result;
		}

		$sponsor_div = $sponsor_divs->item( 0 );

		// Get all spans inside the sponsor-meta div.
		$spans = $xpath->query( './/span', $sponsor_div );
		if ( ! $spans ) {
			return $result;
		}

		foreach ( $spans as $span ) {
			// Check for logo image.
			$imgs = $xpath->query( './/img', $span );
			if ( $imgs && $imgs->length > 0 ) {
				$src = $imgs->item( 0 )->getAttribute( 'src' );
				if ( $src ) {
					$result['logo_url'] = $src;
				}
				continue;
			}

			// Check for sponsor name text.
			$text = trim( $span->textContent );
			if ( $text ) {
				// Strip "Sponsored By" prefix (case-insensitive).
				$name = preg_replace( '/^sponsored\s+by\s+/i', '', $text );
				$name = trim( $name );
				if ( $name ) {
					$result['name'] = $name;
				}
			}
		}

		return $result;
	}
}
