<?php
/**
 * Admin view – Clean Up tab.
 *
 * Provides maintenance operations:
 *  1. Cancel all pending/running jobs.
 *  2. Publish all draft posts (batched).
 *  3. Check & fix publish dates against external source articles.
 *
 * @package ASAE_Content_Ingestor
 * @since   1.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}
?>
<div class="wrap asae-ci-wrap" id="asae-ci-cleanup-app">

	<h1><?php esc_html_e( 'ASAE Content Ingestor', 'asae-content-ingestor' ); ?>
		<span class="asae-ci-version">v<?php echo esc_html( ASAE_CI_VERSION ); ?></span>
	</h1>

	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Content Ingestor navigation', 'asae-content-ingestor' ); ?>">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=asae-content-ingestor' ) ); ?>"
		   class="nav-tab">
			<?php esc_html_e( 'Run', 'asae-content-ingestor' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=asae-content-ingestor&tab=reports' ) ); ?>"
		   class="nav-tab">
			<?php esc_html_e( 'Reports', 'asae-content-ingestor' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=asae-content-ingestor&tab=youtube' ) ); ?>"
		   class="nav-tab">
			<?php esc_html_e( 'YouTube Feed', 'asae-content-ingestor' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=asae-content-ingestor&tab=wp-rest' ) ); ?>"
		   class="nav-tab">
			<?php esc_html_e( 'WordPress REST API', 'asae-content-ingestor' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=asae-content-ingestor&tab=cleanup' ) ); ?>"
		   class="nav-tab nav-tab-active"
		   aria-current="page">
			<?php esc_html_e( 'Clean Up', 'asae-content-ingestor' ); ?>
		</a>
	</nav>

	<!-- ── Section 1: Cancel All Pending Jobs ─────────────────────────────── -->

	<div class="asae-ci-panel" id="asae-ci-cleanup-cancel-section">
		<h2><?php esc_html_e( 'Cancel All Pending Jobs', 'asae-content-ingestor' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Marks all pending, running, failed, and needs-review jobs as completed. This will not delete any ingested posts — it only clears the job queue so stale jobs stop blocking new runs.', 'asae-content-ingestor' ); ?>
		</p>
		<p class="submit">
			<button type="button" class="button button-secondary" id="asae-ci-cancel-all-jobs-btn">
				<?php esc_html_e( 'Cancel All Pending Jobs', 'asae-content-ingestor' ); ?>
			</button>
		</p>
		<div id="asae-ci-cancel-all-jobs-result" class="asae-ci-hidden" aria-live="polite"></div>
	</div>

	<!-- ── Section 2: Posts Per Page ───────────────────────────────────────── -->

	<div class="asae-ci-panel" id="asae-ci-cleanup-perpage-section">
		<h2><?php esc_html_e( 'Posts Per Page (Screen Options)', 'asae-content-ingestor' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Sets how many items the All Posts list table shows per page. If the default (20) or a previous value is too high, the page may time out on large sites. This updates your user-level Screen Options without needing to load the All Posts page.', 'asae-content-ingestor' ); ?>
		</p>
		<?php
		$current_per_page = (int) get_user_meta( get_current_user_id(), 'edit_post_per_page', true );
		if ( ! $current_per_page ) {
			$current_per_page = 20; // WordPress default.
		}
		?>
		<p>
			<label for="asae-ci-perpage-input">
				<?php esc_html_e( 'Posts per page:', 'asae-content-ingestor' ); ?>
			</label>
			<input type="number" id="asae-ci-perpage-input" min="1" max="999" step="1"
				   value="<?php echo esc_attr( $current_per_page ); ?>" style="width:80px;" />
			<button type="button" class="button button-primary" id="asae-ci-perpage-save-btn">
				<?php esc_html_e( 'Save', 'asae-content-ingestor' ); ?>
			</button>
			<span id="asae-ci-perpage-result" style="margin-left:8px;"></span>
		</p>
	</div>

	<!-- ── Section 3: Publish All Drafts ──────────────────────────────────── -->

	<div class="asae-ci-panel" id="asae-ci-cleanup-publish-section">
		<h2><?php esc_html_e( 'Publish All Drafts', 'asae-content-ingestor' ); ?></h2>

		<div class="asae-ci-notice asae-ci-notice-warning" role="alert">
			<p>
				<strong><?php esc_html_e( 'Warning:', 'asae-content-ingestor' ); ?></strong>
				<?php esc_html_e( 'This will publish ALL draft posts that were created by the Content Ingestor. This may include thousands of posts. The operation runs in batches of 50 and cannot be undone easily. Make sure you are ready before proceeding.', 'asae-content-ingestor' ); ?>
			</p>
		</div>

		<p class="submit">
			<button type="button" class="button button-primary" id="asae-ci-publish-all-btn">
				<?php esc_html_e( 'Publish All Drafts', 'asae-content-ingestor' ); ?>
			</button>
		</p>
		<div id="asae-ci-publish-progress" class="asae-ci-hidden" aria-live="polite">
			<div class="asae-ci-progress-bar-wrap">
				<div class="asae-ci-progress-bar" id="asae-ci-publish-bar" style="width:0%"></div>
			</div>
			<p id="asae-ci-publish-status"></p>
		</div>
		<div id="asae-ci-publish-result" class="asae-ci-hidden" aria-live="polite"></div>
	</div>

	<!-- ── Section 3: Check Publish Dates ─────────────────────────────────── -->

	<div class="asae-ci-panel" id="asae-ci-cleanup-dates-section">
		<h2><?php esc_html_e( 'Check Publish Dates', 'asae-content-ingestor' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Loops through published posts in the chosen date range, fetches each external source article, and compares the publish date. If the source has a different date, the WordPress post is updated to match. This runs in small batches (5 posts per request) since each post requires an external HTTP fetch.', 'asae-content-ingestor' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="asae-ci-dates-from"><?php esc_html_e( 'From Date', 'asae-content-ingestor' ); ?></label>
				</th>
				<td>
					<input type="date" id="asae-ci-dates-from" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="asae-ci-dates-to"><?php esc_html_e( 'To Date', 'asae-content-ingestor' ); ?></label>
				</th>
				<td>
					<input type="date" id="asae-ci-dates-to" class="regular-text" />
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="button" class="button button-primary" id="asae-ci-check-dates-btn">
				<?php esc_html_e( 'Check Publish Dates', 'asae-content-ingestor' ); ?>
			</button>
		</p>
		<div id="asae-ci-dates-progress" class="asae-ci-hidden" aria-live="polite">
			<div class="asae-ci-progress-bar-wrap">
				<div class="asae-ci-progress-bar" id="asae-ci-dates-bar" style="width:0%"></div>
			</div>
			<p id="asae-ci-dates-status"></p>
		</div>
		<div id="asae-ci-dates-result" class="asae-ci-hidden" aria-live="polite"></div>
		<table class="wp-list-table widefat fixed striped asae-ci-hidden" id="asae-ci-dates-log">
			<thead>
				<tr>
					<th scope="col" style="width:50px"><?php esc_html_e( 'ID', 'asae-content-ingestor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Title', 'asae-content-ingestor' ); ?></th>
					<th scope="col" style="width:120px"><?php esc_html_e( 'Status', 'asae-content-ingestor' ); ?></th>
					<th scope="col" style="width:160px"><?php esc_html_e( 'Old Date', 'asae-content-ingestor' ); ?></th>
					<th scope="col" style="width:160px"><?php esc_html_e( 'New Date', 'asae-content-ingestor' ); ?></th>
				</tr>
			</thead>
			<tbody id="asae-ci-dates-log-body"></tbody>
		</table>
	</div>

	<!-- ── Section 4: Fix Redirects ───────────────────────────────────────── -->

	<div class="asae-ci-panel" id="asae-ci-cleanup-redirects-section">
		<h2><?php esc_html_e( 'Fix Redirects', 'asae-content-ingestor' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Loops through all published ingested posts and ensures the Redirection plugin\'s stored target URL matches each post\'s current permalink. This is needed after publish dates have been corrected on a site with date-based permalinks. No external fetches are required — this only compares local data. Processes 100 posts per batch.', 'asae-content-ingestor' ); ?>
		</p>
		<p class="submit">
			<button type="button" class="button button-primary" id="asae-ci-fix-redirects-btn">
				<?php esc_html_e( 'Fix Redirects', 'asae-content-ingestor' ); ?>
			</button>
		</p>
		<div id="asae-ci-redirects-progress" class="asae-ci-hidden" aria-live="polite">
			<div class="asae-ci-progress-bar-wrap">
				<div class="asae-ci-progress-bar" id="asae-ci-redirects-bar" style="width:0%"></div>
			</div>
			<p id="asae-ci-redirects-status"></p>
		</div>
		<div id="asae-ci-redirects-result" class="asae-ci-hidden" aria-live="polite"></div>
	</div>

</div>
