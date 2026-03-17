<?php
/**
 * Admin view – Main ASAE > Content Ingestor page.
 *
 * Variables available from ASAE_CI_Admin::render_main_page():
 *  $post_types – array of WP_Post_Type objects.
 *
 * @package ASAE_Content_Ingestor
 * @since   0.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Security: only admins should reach this view (also enforced by the controller).
if ( ! current_user_can( 'manage_options' ) ) {
	return;
}
?>
<div class="wrap asae-ci-wrap" id="asae-ci-app">

	<h1><?php esc_html_e( 'ASAE Content Ingestor', 'asae-content-ingestor' ); ?>
		<span class="asae-ci-version">v<?php echo esc_html( ASAE_CI_VERSION ); ?></span>
	</h1>

	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Content Ingestor navigation', 'asae-content-ingestor' ); ?>">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=asae-content-ingestor' ) ); ?>"
		   class="nav-tab nav-tab-active"
		   aria-current="page">
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
		   class="nav-tab">
			<?php esc_html_e( 'Clean Up', 'asae-content-ingestor' ); ?>
		</a>
	</nav>

	<?php if ( ! $cap_active && ! $cap_notice_dismissed ) : ?>
	<div class="asae-ci-notice asae-ci-notice-warning" id="asae-ci-cap-notice" role="alert">
		<p>
			<strong><?php esc_html_e( 'Co-Authors Plus not detected.', 'asae-content-ingestor' ); ?></strong>
			<?php esc_html_e( 'Author user accounts will still be created, but authors cannot be attributed to posts until Co-Authors Plus is installed and activated on this site.', 'asae-content-ingestor' ); ?>
		</p>
		<button type="button" class="button" id="asae-ci-dismiss-cap-notice">
			<?php esc_html_e( 'Dismiss for 30 days', 'asae-content-ingestor' ); ?>
		</button>
	</div>
	<?php endif; ?>

	<p class="asae-ci-intro">
		<?php esc_html_e( 'Read an RSS feed and ingest its linked articles into WordPress. Use Dry Run to preview content before committing an Active Run.', 'asae-content-ingestor' ); ?>
	</p>

	<!-- ── Run Configuration Form ─────────────────────────────────────────── -->
	<section class="asae-ci-card" aria-labelledby="asae-ci-run-heading">
		<h2 id="asae-ci-run-heading"><?php esc_html_e( 'Configure &amp; Start Run', 'asae-content-ingestor' ); ?></h2>

		<?php
		// Display any server-side messages passed via query string after redirect.
		if ( isset( $_GET['asae_notice'] ) ) {
			$notice_type = 'error' === sanitize_key( $_GET['asae_notice'] ) ? 'error' : 'success';
			$notice_msg  = sanitize_text_field( wp_unslash( $_GET['asae_msg'] ?? '' ) );
			if ( $notice_msg ) {
				echo '<div class="notice notice-' . esc_attr( $notice_type ) . ' is-dismissible" role="alert"><p>' . esc_html( $notice_msg ) . '</p></div>';
			}
		}
		?>

		<form id="asae-ci-run-form" novalidate>

			<!-- RSS Feed URL -->
			<div class="asae-ci-field">
				<label for="asae-ci-source-url">
					<?php esc_html_e( 'RSS Feed URL', 'asae-content-ingestor' ); ?>
					<span class="asae-ci-required" aria-hidden="true">*</span>
				</label>
				<input
					type="url"
					id="asae-ci-source-url"
					name="source_url"
					class="regular-text"
					placeholder="https://example.com/feed"
					required
					aria-required="true"
					aria-describedby="asae-ci-url-hint"
				/>
				<p id="asae-ci-url-hint" class="description">
					<?php esc_html_e( 'The URL of the RSS or Atom feed to read. Article links in the feed will be discovered and ingested.', 'asae-content-ingestor' ); ?>
				</p>
			</div>

			<!-- URL Restriction (Optional) -->
			<div class="asae-ci-field">
				<label for="asae-ci-url-restriction">
					<?php esc_html_e( 'URL Restriction (Optional)', 'asae-content-ingestor' ); ?>
				</label>
				<input
					type="url"
					id="asae-ci-url-restriction"
					name="url_restriction"
					class="regular-text"
					placeholder="https://example.com/articles/"
					aria-describedby="asae-ci-restriction-hint"
				/>
				<p id="asae-ci-restriction-hint" class="description">
					<?php esc_html_e( 'Only ingest feed links whose URL begins with this prefix. Leave blank to ingest every link in the feed.', 'asae-content-ingestor' ); ?>
				</p>
			</div>

			<!-- Additional Tags (Optional) -->
			<div class="asae-ci-field">
				<label for="asae-ci-additional-tags">
					<?php esc_html_e( 'Additional Tags (Optional)', 'asae-content-ingestor' ); ?>
				</label>
				<input
					type="text"
					id="asae-ci-additional-tags"
					name="additional_tags"
					class="regular-text"
					placeholder="tag1, tag2, tag3"
					aria-describedby="asae-ci-tags-hint"
				/>
				<p id="asae-ci-tags-hint" class="description">
					<?php esc_html_e( 'Comma-separated tags applied to every item ingested in this run, in addition to each item\'s own extracted tags.', 'asae-content-ingestor' ); ?>
				</p>
			</div>

			<!-- Post Type -->
			<div class="asae-ci-field">
				<label for="asae-ci-post-type">
					<?php esc_html_e( 'WordPress Post Type', 'asae-content-ingestor' ); ?>
				</label>
				<select id="asae-ci-post-type" name="post_type">
					<?php foreach ( $post_types as $type_slug => $type_obj ) : ?>
						<?php if ( 'attachment' === $type_slug ) : continue; endif; ?>
						<option value="<?php echo esc_attr( $type_slug ); ?>">
							<?php echo esc_html( $type_obj->labels->singular_name ?? $type_slug ); ?>
							(<?php echo esc_html( $type_slug ); ?>)
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					<?php esc_html_e( 'Each discovered article will be created as a post of this type.', 'asae-content-ingestor' ); ?>
				</p>
			</div>

			<!-- Batch Limit -->
			<fieldset class="asae-ci-field">
				<legend><?php esc_html_e( 'Content Limit', 'asae-content-ingestor' ); ?></legend>
				<p class="description">
					<?php esc_html_e( 'Maximum number of items to process in this run. Dry Runs are always capped at 20.', 'asae-content-ingestor' ); ?>
				</p>
				<div class="asae-ci-radio-group" role="group" aria-labelledby="asae-ci-limit-legend">
					<?php
					$limits = [
						'10'   => __( '10 items', 'asae-content-ingestor' ),
						'50'   => __( '50 items', 'asae-content-ingestor' ),
						'100'  => __( '100 items', 'asae-content-ingestor' ),
						'1000' => __( '1,000 items', 'asae-content-ingestor' ),
						'all'  => __( 'All available', 'asae-content-ingestor' ),
					];
					foreach ( $limits as $val => $label ) :
						$checked = ( '50' === $val ) ? ' checked' : '';
					?>
					<label class="asae-ci-radio-label">
						<input
							type="radio"
							name="batch_limit"
							value="<?php echo esc_attr( $val ); ?>"
							<?php echo $checked; // Already sanitised above. ?>
						/>
						<?php echo esc_html( $label ); ?>
					</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<!-- Run Type -->
			<fieldset class="asae-ci-field">
				<legend><?php esc_html_e( 'Run Type', 'asae-content-ingestor' ); ?></legend>
				<div class="asae-ci-radio-group" role="group" aria-labelledby="asae-ci-runtype-legend">

					<label class="asae-ci-radio-label">
						<input type="radio" name="run_type" value="dry" checked />
						<?php esc_html_e( 'Dry Run', 'asae-content-ingestor' ); ?>
					</label>
					<p class="asae-ci-radio-desc">
						<?php esc_html_e( 'Preview up to 20 items that would be ingested. No content is created in WordPress. Each row includes a detail popup showing the exact content that would be stored.', 'asae-content-ingestor' ); ?>
					</p>

					<label class="asae-ci-radio-label">
						<input type="radio" name="run_type" value="active" />
						<?php esc_html_e( 'Active Run', 'asae-content-ingestor' ); ?>
					</label>
					<p class="asae-ci-radio-desc">
						<?php esc_html_e( 'Ingest discovered content into WordPress. A detailed report will be saved on completion.', 'asae-content-ingestor' ); ?>
					</p>

				</div>
			</fieldset>

			<!-- Source Type -->
			<fieldset class="asae-ci-field" aria-describedby="asae-ci-source-type-hint">
				<legend><?php esc_html_e( 'Source Type', 'asae-content-ingestor' ); ?></legend>
				<p id="asae-ci-source-type-hint" class="description">
					<?php esc_html_e( 'How should the original source content be treated after ingestion?', 'asae-content-ingestor' ); ?>
				</p>
				<div class="asae-ci-radio-group" role="group">

					<label class="asae-ci-radio-label">
						<input type="radio" name="source_type" value="replace" checked />
						<?php esc_html_e( 'Replace', 'asae-content-ingestor' ); ?>
					</label>
					<p class="asae-ci-radio-desc">
						<?php esc_html_e( 'This site replaces the source. A 301 redirect will be created for each ingested URL (associationsnow.com URLs are registered automatically; asaecenter.org URLs can be exported from the Reports tab for import on the ASAE Center WP site).', 'asae-content-ingestor' ); ?>
					</p>

					<label class="asae-ci-radio-label">
						<input type="radio" name="source_type" value="mirror" />
						<?php esc_html_e( 'Mirror', 'asae-content-ingestor' ); ?>
					</label>
					<p class="asae-ci-radio-desc">
						<?php esc_html_e( 'The original content continues to live at the source (e.g. YouTube, podcast sites). No redirect will be created; the source URL will be stored as an attribution link in post meta.', 'asae-content-ingestor' ); ?>
					</p>

				</div>
			</fieldset>

			<!-- Keep-Alive Toggle -->
			<div class="asae-ci-field">
				<label class="asae-ci-toggle-label" for="asae-ci-keep-alive">
					<input type="checkbox" id="asae-ci-keep-alive" />
					<?php esc_html_e( 'Keep session alive during run', 'asae-content-ingestor' ); ?>
				</label>
				<p class="description" id="asae-ci-keep-alive-hint">
					<?php esc_html_e( 'Prevents screen sleep and session timeout during long ingestion runs. Recommended for large imports (1,000+ items).', 'asae-content-ingestor' ); ?>
				</p>
				<span id="asae-ci-keep-alive-status" class="asae-ci-keep-alive-status"></span>
			</div>

			<!-- Submit -->
			<div class="asae-ci-field asae-ci-submit-row">
				<?php wp_nonce_field( ASAE_CI_Admin::NONCE_ACTION, '_wpnonce', true ); ?>
				<button
					type="submit"
					id="asae-ci-start-btn"
					class="button button-primary"
				>
					<?php esc_html_e( 'Start Run', 'asae-content-ingestor' ); ?>
				</button>
				<span id="asae-ci-form-error" class="asae-ci-error-msg" role="alert" aria-live="assertive"></span>
			</div>

		</form>
	</section>

	<!-- ── Resume / Cancel Banner (shown when a running job is detected) ── -->
	<div
		id="asae-ci-resume-banner"
		class="asae-ci-card asae-ci-resume-banner asae-ci-hidden"
		role="alert"
	>
		<p id="asae-ci-resume-text">
			<strong><?php esc_html_e( 'A running job was found.', 'asae-content-ingestor' ); ?></strong>
			<span id="asae-ci-resume-detail"></span>
		</p>
		<div class="asae-ci-resume-actions">
			<button type="button" id="asae-ci-resume-btn" class="button button-primary">
				<?php esc_html_e( 'Resume', 'asae-content-ingestor' ); ?>
			</button>
			<button type="button" id="asae-ci-cancel-btn" class="button">
				<?php esc_html_e( 'Cancel Job', 'asae-content-ingestor' ); ?>
			</button>
		</div>
	</div>

	<!-- ── Progress Panel (shown during a job run) ────────────────────────── -->
	<section
		id="asae-ci-progress-panel"
		class="asae-ci-card asae-ci-hidden"
		aria-labelledby="asae-ci-progress-heading"
		aria-live="polite"
	>
		<h2 id="asae-ci-progress-heading"><?php esc_html_e( 'Run Progress', 'asae-content-ingestor' ); ?></h2>

		<div class="asae-ci-status-line">
			<strong><?php esc_html_e( 'Phase:', 'asae-content-ingestor' ); ?></strong>
			<span id="asae-ci-phase-label"><?php esc_html_e( 'Starting…', 'asae-content-ingestor' ); ?></span>
		</div>

		<!-- Discovery progress bar -->
		<div class="asae-ci-progress-section" id="asae-ci-discovery-section">
			<p class="asae-ci-progress-label">
				<?php esc_html_e( 'RSS Feed:', 'asae-content-ingestor' ); ?>
				<span id="asae-ci-found-count">0</span>
				<?php esc_html_e( 'articles found.', 'asae-content-ingestor' ); ?>
				<?php /* Hidden span keeps the JS target intact without displaying. */ ?>
				<span id="asae-ci-crawled-count" aria-hidden="true" style="display:none">0</span>
			</p>
			<div
				class="asae-ci-progress-bar-wrap"
				role="progressbar"
				aria-valuemin="0"
				aria-valuemax="100"
				aria-valuenow="0"
				aria-label="<?php esc_attr_e( 'Discovery progress', 'asae-content-ingestor' ); ?>"
				id="asae-ci-discovery-bar-wrap"
			>
				<div class="asae-ci-progress-bar" id="asae-ci-discovery-bar" style="width:0%"></div>
			</div>
		</div>

		<!-- Ingestion progress bar -->
		<div class="asae-ci-progress-section" id="asae-ci-ingest-section">
			<p class="asae-ci-progress-label">
				<?php esc_html_e( 'Ingestion:', 'asae-content-ingestor' ); ?>
				<span id="asae-ci-processed-count">0</span>
				<?php esc_html_e( 'processed,', 'asae-content-ingestor' ); ?>
				<span id="asae-ci-failed-count">0</span>
				<?php esc_html_e( 'failed.', 'asae-content-ingestor' ); ?>
			</p>
			<div
				class="asae-ci-progress-bar-wrap"
				role="progressbar"
				aria-valuemin="0"
				aria-valuemax="100"
				aria-valuenow="0"
				aria-label="<?php esc_attr_e( 'Ingestion progress', 'asae-content-ingestor' ); ?>"
				id="asae-ci-ingest-bar-wrap"
			>
				<div class="asae-ci-progress-bar" id="asae-ci-ingest-bar" style="width:0%"></div>
			</div>
		</div>

		<p id="asae-ci-complete-msg" class="asae-ci-hidden asae-ci-complete-notice">
			<?php esc_html_e( 'Run complete!', 'asae-content-ingestor' ); ?>
			<a id="asae-ci-report-link" href="#" class="asae-ci-hidden">
				<?php esc_html_e( 'View full report →', 'asae-content-ingestor' ); ?>
			</a>
		</p>

	</section>

	<!-- ── Dry Run Results (shown after a Dry Run completes) ─────────────── -->
	<section
		id="asae-ci-dry-results-panel"
		class="asae-ci-card asae-ci-hidden"
		aria-labelledby="asae-ci-dry-heading"
	>
		<h2 id="asae-ci-dry-heading"><?php esc_html_e( 'Dry Run Preview', 'asae-content-ingestor' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The following articles were found and would be ingested during an Active Run. No content was created.', 'asae-content-ingestor' ); ?>
		</p>

		<div id="asae-ci-dry-table-wrap">
			<!-- Populated dynamically by admin.js -->
		</div>

	</section>

	<!-- ── Category Review Panel (shown when posts need manual category assignment) -->
	<section
		id="asae-ci-review-panel"
		class="asae-ci-card asae-ci-hidden"
		aria-labelledby="asae-ci-review-heading"
	>
		<h2 id="asae-ci-review-heading"><?php esc_html_e( 'Category Review Required', 'asae-content-ingestor' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The following items could not be automatically matched to an existing category. Select a category for each item and click Apply to publish them. You can also apply one category to all items at once.', 'asae-content-ingestor' ); ?>
		</p>

		<!-- Toolbar: progress counter, search, bulk actions -->
		<div id="asae-ci-review-toolbar" class="asae-ci-review-toolbar asae-ci-hidden">
			<div class="asae-ci-review-toolbar-left">
				<span id="asae-ci-review-progress" aria-live="polite"></span>
			</div>
			<div class="asae-ci-review-toolbar-right">
				<label for="asae-ci-review-search" class="screen-reader-text">
					<?php esc_html_e( 'Filter by title', 'asae-content-ingestor' ); ?>
				</label>
				<input
					type="search"
					id="asae-ci-review-search"
					class="asae-ci-review-search"
					placeholder="<?php esc_attr_e( 'Filter by title\u2026', 'asae-content-ingestor' ); ?>"
				>
			</div>
		</div>

		<!-- Bulk apply-to-all row -->
		<div id="asae-ci-review-bulk-row" class="asae-ci-review-bulk-row asae-ci-hidden">
			<label for="asae-ci-review-bulk-cat">
				<?php esc_html_e( 'Apply one category to all visible:', 'asae-content-ingestor' ); ?>
			</label>
			<select id="asae-ci-review-bulk-cat" class="asae-ci-field">
				<option value=""><?php esc_html_e( '— Select —', 'asae-content-ingestor' ); ?></option>
			</select>
			<span class="asae-ci-review-bulk-separator"><?php esc_html_e( 'or', 'asae-content-ingestor' ); ?></span>
			<button type="button" id="asae-ci-apply-all-btn" class="button" disabled>
				<?php esc_html_e( 'Apply to ALL items', 'asae-content-ingestor' ); ?>
			</button>
		</div>

		<!-- Table content (populated by JS) -->
		<div id="asae-ci-review-table-wrap"></div>

		<!-- Pagination -->
		<div id="asae-ci-review-pagination" class="asae-ci-pagination asae-ci-hidden"
			 role="navigation" aria-label="<?php esc_attr_e( 'Review list pagination', 'asae-content-ingestor' ); ?>">
		</div>

		<!-- Apply & progress row -->
		<div id="asae-ci-review-apply-row" class="asae-ci-hidden" style="margin-top:1em;">
			<button type="button" id="asae-ci-apply-categories-btn" class="button button-primary">
				<?php esc_html_e( 'Apply Categories &amp; Publish', 'asae-content-ingestor' ); ?>
			</button>
			<span id="asae-ci-apply-progress"></span>
			<span id="asae-ci-review-error" class="asae-ci-error-msg" role="alert" aria-live="assertive"></span>
		</div>
	</section>



	<!-- ── Dry Run Article Preview Modal ─────────────────────────────────── -->
	<!--
		Hidden by default. Opened by JS when the admin clicks a "Preview"
		button in the dry-run results table. Shows the exact content that
		would be stored in each WordPress field for a given article.
	-->
	<div
		id="asae-ci-preview-modal"
		class="asae-ci-modal-overlay asae-ci-hidden"
		role="dialog"
		aria-modal="true"
		aria-labelledby="asae-ci-modal-title"
	>
		<div class="asae-ci-modal" role="document">
			<div class="asae-ci-modal-header">
				<h2 id="asae-ci-modal-title"><?php esc_html_e( 'Article Preview', 'asae-content-ingestor' ); ?></h2>
				<button
					type="button"
					id="asae-ci-modal-close"
					class="asae-ci-modal-close button"
					aria-label="<?php esc_attr_e( 'Close preview', 'asae-content-ingestor' ); ?>"
				>&times;</button>
			</div>
			<div id="asae-ci-modal-body" class="asae-ci-modal-body">
				<!-- Populated dynamically by admin.js -->
			</div>
		</div>
	</div>

</div><!-- .asae-ci-wrap -->
