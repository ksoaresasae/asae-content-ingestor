<?php
/**
 * Settings tab – plugin update checker and configuration.
 *
 * @package ASAE_Content_Ingestor
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}
?>
<div class="wrap asae-ci-wrap" id="asae-ci-settings-app">

	<h1><?php esc_html_e( 'ASAE Content Ingestor', 'asae-content-ingestor' ); ?>
		<span class="asae-ci-version">v<?php echo esc_html( ASAE_CI_VERSION ); ?></span>
	</h1>

	<?php ASAE_CI_Admin::render_nav_tabs( 'settings' ); ?>

	<!-- ── Plugin Updates ─────────────────────────────────────────────────── -->

	<div class="asae-ci-panel">
		<h2><?php esc_html_e( 'Plugin Updates', 'asae-content-ingestor' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'This plugin checks GitHub for new releases automatically. Use the button below to check immediately.', 'asae-content-ingestor' ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Current Version:', 'asae-content-ingestor' ); ?></strong>
			<?php echo esc_html( ASAE_CI_VERSION ); ?>
		</p>
		<p>
			<button type="button" id="asae-ci-check-updates-btn" class="button">
				<?php esc_html_e( 'Check for Updates Now', 'asae-content-ingestor' ); ?>
			</button>
			<span id="asae-ci-update-check-result" style="margin-left:10px;"></span>
		</p>
	</div>

</div>
