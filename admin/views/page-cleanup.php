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

	<?php ASAE_CI_Admin::render_nav_tabs( 'cleanup' ); ?>

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

	<!-- ── Section 5: Assign Sponsors ─────────────────────────────────────── -->

	<div class="asae-ci-panel" id="asae-ci-cleanup-sponsors-section">
		<h2><?php esc_html_e( 'Assign Sponsors', 'asae-content-ingestor' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Fetches each sponsor\'s listing page from associationsnow.com, extracts the sponsor name and logo, creates a Sponsor taxonomy term, and assigns it to all matching ingested posts. Processes one sponsor per request (67 total). Safe to re-run — existing sponsors are skipped.', 'asae-content-ingestor' ); ?>
		</p>
		<p class="submit">
			<button type="button" class="button button-primary" id="asae-ci-assign-sponsors-btn">
				<?php esc_html_e( 'Assign Sponsors', 'asae-content-ingestor' ); ?>
			</button>
		</p>
		<div id="asae-ci-sponsors-progress" class="asae-ci-hidden" aria-live="polite">
			<div class="asae-ci-progress-bar-wrap">
				<div class="asae-ci-progress-bar" id="asae-ci-sponsors-bar" style="width:0%"></div>
			</div>
			<p id="asae-ci-sponsors-status"></p>
		</div>
		<div id="asae-ci-sponsors-result" class="asae-ci-hidden" aria-live="polite"></div>
		<table class="wp-list-table widefat fixed striped asae-ci-hidden" id="asae-ci-sponsors-log">
			<thead>
				<tr>
					<th scope="col" style="width:40px">#</th>
					<th scope="col"><?php esc_html_e( 'Sponsor', 'asae-content-ingestor' ); ?></th>
					<th scope="col" style="width:60px"><?php esc_html_e( 'Logo', 'asae-content-ingestor' ); ?></th>
					<th scope="col" style="width:100px"><?php esc_html_e( 'Articles', 'asae-content-ingestor' ); ?></th>
					<th scope="col" style="width:100px"><?php esc_html_e( 'Matched', 'asae-content-ingestor' ); ?></th>
					<th scope="col" style="width:100px"><?php esc_html_e( 'Assigned', 'asae-content-ingestor' ); ?></th>
					<th scope="col" style="width:100px"><?php esc_html_e( 'Status', 'asae-content-ingestor' ); ?></th>
				</tr>
			</thead>
			<tbody id="asae-ci-sponsors-log-body"></tbody>
		</table>
	</div>

	<!-- ── Section 7: Bulk Assign Content Areas ───────────────────────────── -->

	<?php $pw_active = ASAE_CI_Admin::is_publishing_workflow_active(); ?>
	<div class="asae-ci-panel" id="asae-ci-bulk-areas-section">
		<h2><?php esc_html_e( 'Bulk Assign Content Areas', 'asae-content-ingestor' ); ?></h2>
		<?php if ( ! $pw_active ) : ?>
			<p class="description" style="color:#b32d2e;">
				<strong><?php esc_html_e( 'Disabled — ASAE Publishing Workflow plugin is not active.', 'asae-content-ingestor' ); ?></strong>
				<?php esc_html_e( 'Activate the ASAE Publishing Workflow plugin to enable this feature.', 'asae-content-ingestor' ); ?>
			</p>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'Assign one or more Content Areas to many existing posts at once. This replaces any previously assigned Content Areas on each affected post.', 'asae-content-ingestor' ); ?>
			</p>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="asae-ci-bulk-areas-post-type"><?php esc_html_e( 'Post Type', 'asae-content-ingestor' ); ?></label>
				</th>
				<td>
					<select id="asae-ci-bulk-areas-post-type"<?php disabled( ! $pw_active ); ?>>
						<?php
						$pts = ASAE_CI_Admin::get_eligible_post_types();
						foreach ( $pts as $slug => $pt_obj ) :
							?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $pt_obj->label ); ?> (<?php echo esc_html( $slug ); ?>)</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Filter', 'asae-content-ingestor' ); ?></th>
				<td>
					<label>
						<input type="radio" name="asae-ci-bulk-areas-filter" value="all" checked<?php disabled( ! $pw_active ); ?> />
						<?php esc_html_e( 'All posts of this type', 'asae-content-ingestor' ); ?>
					</label><br>
					<label>
						<input type="radio" name="asae-ci-bulk-areas-filter" value="none"<?php disabled( ! $pw_active ); ?> />
						<?php esc_html_e( 'Only posts with NO Content Area assigned', 'asae-content-ingestor' ); ?>
					</label><br>
					<label>
						<input type="radio" name="asae-ci-bulk-areas-filter" value="has_any"<?php disabled( ! $pw_active ); ?> />
						<?php esc_html_e( 'Only posts that already have ANY Content Area', 'asae-content-ingestor' ); ?>
					</label><br>
					<label>
						<input type="radio" name="asae-ci-bulk-areas-filter" value="has_term"<?php disabled( ! $pw_active ); ?> />
						<?php esc_html_e( 'Only posts that have this specific Content Area:', 'asae-content-ingestor' ); ?>
					</label>
					<select id="asae-ci-bulk-areas-filter-term" style="margin-left:8px;"<?php disabled( ! $pw_active ); ?>>
						<option value="0">— <?php esc_html_e( 'select', 'asae-content-ingestor' ); ?> —</option>
						<?php
						if ( $pw_active ) {
							$filter_terms = get_terms( [
								'taxonomy'   => ASAE_CI_Admin::CONTENT_AREA_TAXONOMY,
								'hide_empty' => false,
								'orderby'    => 'name',
							] );
							if ( ! is_wp_error( $filter_terms ) ) {
								foreach ( $filter_terms as $t ) {
									echo '<option value="' . esc_attr( $t->term_id ) . '">' . esc_html( $t->name ) . '</option>';
								}
							}
						}
						?>
					</select>
				</td>
			</tr>
		</table>

		<?php ASAE_CI_Admin::render_content_areas_picker( 'asae_ci_bulk_areas_target', 'cleanup-bulk', ! $pw_active ); ?>

		<p>
			<label class="asae-ci-toggle-label">
				<input type="checkbox" id="asae-ci-bulk-areas-keep-alive"<?php disabled( ! $pw_active ); ?> />
				<?php esc_html_e( 'Keep session alive during run', 'asae-content-ingestor' ); ?>
			</label>
		</p>

		<p>
			<button type="button" id="asae-ci-bulk-areas-start-btn" class="button button-primary"<?php disabled( ! $pw_active ); ?>>
				<?php esc_html_e( 'Start Bulk Assignment', 'asae-content-ingestor' ); ?>
			</button>
			<button type="button" id="asae-ci-bulk-areas-cancel-btn" class="button asae-ci-hidden">
				<?php esc_html_e( 'Cancel Job', 'asae-content-ingestor' ); ?>
			</button>
		</p>

		<div id="asae-ci-bulk-areas-resume-banner" class="asae-ci-hidden" style="padding:10px;background:#fff3cd;border:1px solid #ffc107;border-radius:3px;margin:10px 0;">
			<strong><?php esc_html_e( 'A previous bulk-assign job is still running.', 'asae-content-ingestor' ); ?></strong>
			<button type="button" id="asae-ci-bulk-areas-resume-btn" class="button button-primary" style="margin-left:8px;"><?php esc_html_e( 'Resume', 'asae-content-ingestor' ); ?></button>
		</div>

		<div id="asae-ci-bulk-areas-progress" class="asae-ci-hidden" aria-live="polite" style="margin-top:12px;">
			<p>
				<strong><?php esc_html_e( 'Status:', 'asae-content-ingestor' ); ?></strong>
				<span id="asae-ci-bulk-areas-status"><?php esc_html_e( 'Starting…', 'asae-content-ingestor' ); ?></span>
			</p>
			<div class="asae-ci-progress-bar-wrap" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="asae-ci-bulk-areas-bar-wrap">
				<div class="asae-ci-progress-bar" id="asae-ci-bulk-areas-bar" style="width:0%"></div>
			</div>
			<p>
				<span id="asae-ci-bulk-areas-processed">0</span> /
				<span id="asae-ci-bulk-areas-total">0</span> <?php esc_html_e( 'processed,', 'asae-content-ingestor' ); ?>
				<span id="asae-ci-bulk-areas-failed">0</span> <?php esc_html_e( 'failed.', 'asae-content-ingestor' ); ?>
			</p>
		</div>
		<div id="asae-ci-bulk-areas-result" class="asae-ci-hidden" aria-live="polite"></div>
	</div>

</div>
