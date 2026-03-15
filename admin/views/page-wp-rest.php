<?php
/**
 * Admin view – WordPress REST API Feed Generator tab.
 *
 * Allows admins to connect to a remote WordPress site via its REST API,
 * discover available content types, and generate an Atom XML feed of all
 * posts for ingestion via the Run tab.
 *
 * Variables available from ASAE_CI_Admin::render_main_page():
 *  $wp_rest_feed_status – array from ASAE_CI_WP_REST::get_feed_status().
 *  $wp_rest_has_creds   – bool, whether credentials are currently stored.
 *  $wp_rest_site_url    – string, saved site URL (or empty).
 *
 * WCAG 2.2 Level AA compliance:
 *  - All form controls have associated <label> elements.
 *  - Password input uses type="password" for masking.
 *  - Progress and result areas use aria-live="polite".
 *  - Action buttons meet minimum 44×44px touch target.
 *
 * @package ASAE_Content_Ingestor
 * @since   0.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}
?>
<div class="wrap asae-ci-wrap" id="asae-ci-wp-rest-app">

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
		   class="nav-tab nav-tab-active"
		   aria-current="page">
			<?php esc_html_e( 'WordPress REST API', 'asae-content-ingestor' ); ?>
		</a>
	</nav>

	<p class="asae-ci-intro">
		<?php esc_html_e( 'Generate a full Atom feed from a remote WordPress site using its REST API. This is useful when the site\'s RSS feed only includes recent entries but you need to ingest all content.', 'asae-content-ingestor' ); ?>
	</p>

	<!-- ── Site Connection Section ──────────────────────────────────────── -->
	<section class="asae-ci-card" aria-labelledby="asae-ci-wpr-connect-heading">
		<h2 id="asae-ci-wpr-connect-heading"><?php esc_html_e( 'Connect to WordPress Site', 'asae-content-ingestor' ); ?></h2>

		<div class="asae-ci-field">
			<label for="asae-ci-wpr-site-url">
				<?php esc_html_e( 'Site URL', 'asae-content-ingestor' ); ?>
				<span class="asae-ci-required" aria-hidden="true">*</span>
			</label>
			<input
				type="url"
				id="asae-ci-wpr-site-url"
				class="regular-text"
				placeholder="https://example.com"
				value="<?php echo esc_attr( $wp_rest_site_url ); ?>"
				required
				aria-required="true"
				aria-describedby="asae-ci-wpr-url-hint"
			/>
			<p id="asae-ci-wpr-url-hint" class="description">
				<?php esc_html_e( 'The base URL of the WordPress site (e.g., https://associationsnow.com).', 'asae-content-ingestor' ); ?>
			</p>
		</div>

		<div class="asae-ci-field">
			<label for="asae-ci-wpr-username">
				<?php esc_html_e( 'Username (optional)', 'asae-content-ingestor' ); ?>
			</label>
			<input
				type="text"
				id="asae-ci-wpr-username"
				class="regular-text"
				placeholder="admin@example.com"
				autocomplete="off"
				aria-describedby="asae-ci-wpr-auth-hint"
			/>
		</div>

		<div class="asae-ci-field">
			<label for="asae-ci-wpr-app-password">
				<?php esc_html_e( 'Application Password (optional)', 'asae-content-ingestor' ); ?>
			</label>
			<input
				type="password"
				id="asae-ci-wpr-app-password"
				class="regular-text"
				placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"
				autocomplete="off"
			/>
			<p id="asae-ci-wpr-auth-hint" class="description">
				<?php esc_html_e( 'Providing credentials unlocks full author data (bio, photo, email, website). Credentials are session-only (1 hour) and never saved permanently. Generate an Application Password in the remote site\'s Users → Profile screen.', 'asae-content-ingestor' ); ?>
			</p>
		</div>

		<?php if ( $wp_rest_has_creds ) : ?>
		<p class="asae-ci-wpr-creds-status" id="asae-ci-wpr-creds-status">
			<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
			<?php esc_html_e( 'Credentials are currently stored (session-only).', 'asae-content-ingestor' ); ?>
			<button type="button" id="asae-ci-wpr-clear-creds-btn" class="button button-link-delete" style="margin-left:.5em;">
				<?php esc_html_e( 'Clear Credentials', 'asae-content-ingestor' ); ?>
			</button>
		</p>
		<?php endif; ?>

		<div class="asae-ci-submit-row">
			<button type="button" id="asae-ci-wpr-discover-btn" class="button button-primary">
				<?php esc_html_e( 'Discover Content Types', 'asae-content-ingestor' ); ?>
			</button>
			<span id="asae-ci-wpr-discover-msg" class="asae-ci-yt-inline-msg" role="status" aria-live="polite"></span>
		</div>
	</section>

	<!-- ── Content Types Section (shown after discovery) ────────────────── -->
	<section
		id="asae-ci-wpr-types-panel"
		class="asae-ci-card asae-ci-hidden"
		aria-labelledby="asae-ci-wpr-types-heading"
	>
		<h2 id="asae-ci-wpr-types-heading"><?php esc_html_e( 'Content Types Found', 'asae-content-ingestor' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Select which content types to include in the generated feed. The source post type is recorded on every entry for reference.', 'asae-content-ingestor' ); ?>
		</p>

		<div id="asae-ci-wpr-types-list" role="group" aria-label="<?php esc_attr_e( 'Content types', 'asae-content-ingestor' ); ?>">
			<!-- Populated dynamically by admin.js -->
		</div>

		<div class="asae-ci-submit-row" style="margin-top:1em;">
			<button type="button" id="asae-ci-wpr-generate-btn" class="button button-primary" disabled>
				<?php esc_html_e( 'Generate Feed', 'asae-content-ingestor' ); ?>
			</button>
			<span id="asae-ci-wpr-generate-msg" class="asae-ci-yt-inline-msg" role="status" aria-live="polite"></span>
		</div>

		<!-- Progress area (shown during generation) -->
		<div id="asae-ci-wpr-progress" class="asae-ci-hidden" aria-live="polite">
			<div class="asae-ci-progress-bar-wrap" role="progressbar"
				 aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
				 id="asae-ci-wpr-progress-bar-wrap"
				 aria-label="<?php esc_attr_e( 'Feed generation progress', 'asae-content-ingestor' ); ?>">
				<div class="asae-ci-progress-bar" id="asae-ci-wpr-progress-bar" style="width:0%"></div>
			</div>
			<p id="asae-ci-wpr-progress-text" class="asae-ci-yt-progress-text"></p>
		</div>
	</section>

	<!-- ── Feed Status (shown when a feed file exists) ─────────────────── -->
	<?php if ( $wp_rest_feed_status['exists'] ) : ?>
	<section class="asae-ci-card" id="asae-ci-wpr-status-section" aria-labelledby="asae-ci-wpr-status-heading">
		<h2 id="asae-ci-wpr-status-heading"><?php esc_html_e( 'Generated Feed', 'asae-content-ingestor' ); ?></h2>

		<table class="asae-ci-summary-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Feed URL', 'asae-content-ingestor' ); ?></th>
					<td>
						<a href="<?php echo esc_url( $wp_rest_feed_status['url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $wp_rest_feed_status['url'] ); ?>
						</a>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Entries', 'asae-content-ingestor' ); ?></th>
					<td>
						<?php echo (int) $wp_rest_feed_status['count']; ?>
						<?php if ( $wp_rest_feed_status['has_authors'] ) : ?>
							<span class="description"><?php esc_html_e( '(includes author sidecar data)', 'asae-content-ingestor' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Generated', 'asae-content-ingestor' ); ?></th>
					<td><?php echo esc_html( $wp_rest_feed_status['date'] ); ?></td>
				</tr>
			</tbody>
		</table>

		<div class="asae-ci-yt-actions" style="margin-top: .75rem;">
			<a
				href="<?php echo esc_url( admin_url( 'admin.php?page=asae-content-ingestor&prefill_feed=' . rawurlencode( $wp_rest_feed_status['url'] ) ) ); ?>"
				class="button button-primary"
			>
				<?php esc_html_e( 'Use in Run Tab', 'asae-content-ingestor' ); ?> &rarr;
			</a>
			<a href="<?php echo esc_url( $wp_rest_feed_status['url'] ); ?>" class="button" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Download Feed XML', 'asae-content-ingestor' ); ?>
			</a>
		</div>
	</section>
	<?php endif; ?>

</div><!-- .asae-ci-wrap -->
