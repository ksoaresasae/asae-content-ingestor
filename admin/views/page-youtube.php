<?php
/**
 * Admin view – YouTube Feed Generator tab.
 *
 * Allows admins to enter a YouTube Data API v3 key and channel/playlist ID,
 * fetch all videos, preview them in a table, and generate an Atom XML feed
 * that can be used on the Run tab for ingestion.
 *
 * Variables available from ASAE_CI_Admin::render_main_page():
 *  $yt_api_key_saved – bool, whether an API key is currently stored.
 *  $yt_api_key_mask  – string, masked version of the stored key (or empty).
 *  $yt_feed_status   – array from ASAE_CI_YouTube::get_feed_status().
 *
 * WCAG 2.2 Level AA compliance:
 *  - All form controls have associated <label> elements.
 *  - API key input uses type="password" for masking.
 *  - Progress and result areas use aria-live="polite".
 *  - Action buttons meet minimum 44×44px touch target.
 *  - Tables have proper <caption>, <th scope>, and headers.
 *
 * @package ASAE_Content_Ingestor
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}
?>
<div class="wrap asae-ci-wrap" id="asae-ci-youtube-app">

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
		   class="nav-tab nav-tab-active"
		   aria-current="page">
			<?php esc_html_e( 'YouTube Feed', 'asae-content-ingestor' ); ?>
		</a>
	</nav>

	<p class="asae-ci-intro">
		<?php esc_html_e( 'Generate a full Atom feed from a YouTube channel so all videos can be ingested via the Run tab. YouTube\'s standard RSS feed is limited to 15 items; this tool fetches every video using the YouTube Data API.', 'asae-content-ingestor' ); ?>
	</p>

	<!-- ── API Key Section ───────────────────────────────────────────────── -->
	<section class="asae-ci-card" aria-labelledby="asae-ci-yt-key-heading">
		<h2 id="asae-ci-yt-key-heading"><?php esc_html_e( 'YouTube Data API Key', 'asae-content-ingestor' ); ?></h2>

		<?php if ( $yt_api_key_saved ) : ?>
		<p class="asae-ci-yt-key-status">
			<?php esc_html_e( 'Saved key:', 'asae-content-ingestor' ); ?>
			<code><?php echo esc_html( $yt_api_key_mask ); ?></code>
		</p>
		<?php endif; ?>

		<div class="asae-ci-field">
			<label for="asae-ci-yt-api-key">
				<?php echo $yt_api_key_saved
					? esc_html__( 'Update API Key', 'asae-content-ingestor' )
					: esc_html__( 'API Key', 'asae-content-ingestor' ); ?>
			</label>
			<input
				type="password"
				id="asae-ci-yt-api-key"
				class="regular-text"
				placeholder="AIza..."
				autocomplete="off"
				aria-describedby="asae-ci-yt-key-hint"
			/>
			<p id="asae-ci-yt-key-hint" class="description">
				<?php esc_html_e( 'A YouTube Data API v3 key from the Google Cloud Console. The key is stored securely in the WordPress database.', 'asae-content-ingestor' ); ?>
			</p>
		</div>

		<div class="asae-ci-submit-row">
			<button type="button" id="asae-ci-yt-save-key-btn" class="button">
				<?php esc_html_e( 'Save API Key', 'asae-content-ingestor' ); ?>
			</button>
			<span id="asae-ci-yt-key-msg" class="asae-ci-yt-inline-msg" role="status" aria-live="polite"></span>
		</div>
	</section>

	<!-- ── Feed Generator Section ────────────────────────────────────────── -->
	<section class="asae-ci-card" aria-labelledby="asae-ci-yt-gen-heading">
		<h2 id="asae-ci-yt-gen-heading"><?php esc_html_e( 'Generate Feed', 'asae-content-ingestor' ); ?></h2>

		<?php if ( $yt_channel_id_saved ) : ?>
		<p class="asae-ci-yt-key-status" id="asae-ci-yt-channel-status">
			<?php esc_html_e( 'Saved ID:', 'asae-content-ingestor' ); ?>
			<code><?php echo esc_html( $yt_channel_id_mask ); ?></code>
		</p>
		<?php endif; ?>

		<div class="asae-ci-field">
			<label for="asae-ci-yt-channel-id">
				<?php esc_html_e( 'Channel ID or Uploads Playlist ID', 'asae-content-ingestor' ); ?>
			</label>
			<input
				type="text"
				id="asae-ci-yt-channel-id"
				class="regular-text"
				placeholder="UCxxxxxxxxxxxxxxxxxxxxxxxx"
				<?php if ( $yt_channel_id_saved ) : ?>value="<?php echo esc_attr( $yt_channel_id ); ?>"<?php endif; ?>
				aria-describedby="asae-ci-yt-channel-hint"
			/>
			<p id="asae-ci-yt-channel-hint" class="description">
				<?php esc_html_e( 'Enter a YouTube channel ID (UCxxx) or uploads playlist ID (UUxxx). Channel IDs are automatically converted to the uploads playlist.', 'asae-content-ingestor' ); ?>
			</p>
		</div>

		<div class="asae-ci-submit-row">
			<button type="button" id="asae-ci-yt-generate-btn" class="button button-primary">
				<?php esc_html_e( 'Fetch & Generate Feed', 'asae-content-ingestor' ); ?>
			</button>
			<span id="asae-ci-yt-gen-msg" class="asae-ci-yt-inline-msg" role="status" aria-live="polite"></span>
		</div>

		<!-- Progress area (shown during fetch) -->
		<div id="asae-ci-yt-progress" class="asae-ci-hidden" aria-live="polite">
			<p id="asae-ci-yt-progress-text" class="asae-ci-yt-progress-text">
				<?php esc_html_e( 'Fetching videos…', 'asae-content-ingestor' ); ?>
			</p>
		</div>
	</section>

	<!-- ── Video Preview Table (shown after successful fetch) ────────────── -->
	<section
		id="asae-ci-yt-results-panel"
		class="asae-ci-card asae-ci-hidden"
		aria-labelledby="asae-ci-yt-results-heading"
	>
		<h2 id="asae-ci-yt-results-heading"><?php esc_html_e( 'Videos Found', 'asae-content-ingestor' ); ?></h2>
		<p id="asae-ci-yt-results-summary" class="description"></p>

		<div id="asae-ci-yt-table-wrap">
			<!-- Populated dynamically by admin.js -->
		</div>

		<!-- Pagination controls (client-side) -->
		<div id="asae-ci-yt-pagination" class="asae-ci-pagination asae-ci-hidden" role="navigation"
			 aria-label="<?php esc_attr_e( 'Video list pagination', 'asae-content-ingestor' ); ?>">
		</div>

		<!-- Action buttons -->
		<div class="asae-ci-yt-actions" style="margin-top: 1rem;">
			<a id="asae-ci-yt-use-feed-btn" href="#" class="button button-primary">
				<?php esc_html_e( 'Use in Run Tab', 'asae-content-ingestor' ); ?> &rarr;
			</a>
			<a id="asae-ci-yt-download-feed-btn" href="#" class="button" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Download Feed XML', 'asae-content-ingestor' ); ?>
			</a>
		</div>
	</section>

	<!-- ── Existing Feed Status (shown if a feed file exists on page load) ── -->
	<?php if ( $yt_feed_status['exists'] ) : ?>
	<section class="asae-ci-card" aria-labelledby="asae-ci-yt-status-heading">
		<h2 id="asae-ci-yt-status-heading"><?php esc_html_e( 'Previously Generated Feed', 'asae-content-ingestor' ); ?></h2>

		<table class="asae-ci-summary-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Feed URL', 'asae-content-ingestor' ); ?></th>
					<td>
						<a href="<?php echo esc_url( $yt_feed_status['url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $yt_feed_status['url'] ); ?>
						</a>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Videos', 'asae-content-ingestor' ); ?></th>
					<td><?php echo (int) $yt_feed_status['count']; ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Generated', 'asae-content-ingestor' ); ?></th>
					<td><?php echo esc_html( $yt_feed_status['date'] ); ?></td>
				</tr>
			</tbody>
		</table>

		<div class="asae-ci-yt-actions" style="margin-top: .75rem;">
			<a
				href="<?php echo esc_url( admin_url( 'admin.php?page=asae-content-ingestor&prefill_feed=' . rawurlencode( $yt_feed_status['url'] ) ) ); ?>"
				class="button button-primary"
			>
				<?php esc_html_e( 'Use in Run Tab', 'asae-content-ingestor' ); ?> &rarr;
			</a>
			<a href="<?php echo esc_url( $yt_feed_status['url'] ); ?>" class="button" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Download Feed XML', 'asae-content-ingestor' ); ?>
			</a>
		</div>
	</section>
	<?php endif; ?>

</div><!-- .asae-ci-wrap -->
