<?php
/**
 * Admin view – Main Tools > Content Ingestor page.
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
					<?php esc_html_e( 'Maximum number of items to process in this run. Dry Runs are always capped at 50.', 'asae-content-ingestor' ); ?>
				</p>
				<div class="asae-ci-radio-group" role="group" aria-labelledby="asae-ci-limit-legend">
					<?php
					$limits = [
						'10'  => __( '10 items', 'asae-content-ingestor' ),
						'50'  => __( '50 items', 'asae-content-ingestor' ),
						'100' => __( '100 items', 'asae-content-ingestor' ),
						'all' => __( 'All available', 'asae-content-ingestor' ),
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
						<?php esc_html_e( 'Preview up to 50 items that would be ingested. No content is created in WordPress.', 'asae-content-ingestor' ); ?>
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

	<!-- ── Recent Reports Quick Link ─────────────────────────────────────── -->
	<section class="asae-ci-card" aria-labelledby="asae-ci-recent-heading">
		<h2 id="asae-ci-recent-heading"><?php esc_html_e( 'Ingestion Reports', 'asae-content-ingestor' ); ?></h2>
		<p>
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=asae-ci-reports' ) ); ?>" class="button">
				<?php esc_html_e( 'View All Reports →', 'asae-content-ingestor' ); ?>
			</a>
		</p>
	</section>

</div><!-- .asae-ci-wrap -->
