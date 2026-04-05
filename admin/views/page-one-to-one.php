<?php
/**
 * One to One tab – ingest a single URL into a new Page or Post.
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

$post_types = ASAE_CI_Admin::get_eligible_post_types();
?>
<div class="wrap asae-ci-wrap" id="asae-ci-one-to-one-app">

	<h1><?php esc_html_e( 'ASAE Content Ingestor', 'asae-content-ingestor' ); ?>
		<span class="asae-ci-version">v<?php echo esc_html( ASAE_CI_VERSION ); ?></span>
	</h1>

	<?php ASAE_CI_Admin::render_nav_tabs( 'one-to-one' ); ?>

	<p class="asae-ci-intro">
		<?php esc_html_e( 'Ingest a single URL into a new WordPress Page or Post. The source page will be fetched, parsed for content, metadata, images, and sponsors — exactly as the General Run tab processes articles.', 'asae-content-ingestor' ); ?>
	</p>

	<div class="asae-ci-panel">
		<h2><?php esc_html_e( 'Source', 'asae-content-ingestor' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="oto-source-url"><?php esc_html_e( 'Source URL', 'asae-content-ingestor' ); ?></label>
				</th>
				<td>
					<input type="url" id="oto-source-url" class="regular-text" placeholder="https://example.com/article-to-ingest" required />
					<p class="description"><?php esc_html_e( 'The full URL of the page to ingest.', 'asae-content-ingestor' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="oto-post-type"><?php esc_html_e( 'Create as', 'asae-content-ingestor' ); ?></label>
				</th>
				<td>
					<select id="oto-post-type">
						<?php foreach ( $post_types as $slug => $pt_obj ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $pt_obj->label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr id="oto-parent-row" style="display:none;">
				<th scope="row">
					<label for="oto-parent"><?php esc_html_e( 'Parent', 'asae-content-ingestor' ); ?></label>
				</th>
				<td>
					<?php
					wp_dropdown_pages( [
						'name'              => 'oto-parent',
						'id'                => 'oto-parent',
						'show_option_none'  => __( '(no parent)', 'asae-content-ingestor' ),
						'option_none_value' => '0',
						'sort_column'       => 'menu_order, post_title',
					] );
					?>
					<p class="description"><?php esc_html_e( 'Optional. Select a parent page to nest this page under.', 'asae-content-ingestor' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="oto-title"><?php esc_html_e( 'Title', 'asae-content-ingestor' ); ?></label>
				</th>
				<td>
					<input type="text" id="oto-title" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to use the source page title', 'asae-content-ingestor' ); ?>" />
					<p class="description"><?php esc_html_e( 'Override the title. Leave blank to use the title from the source page.', 'asae-content-ingestor' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="oto-slug"><?php esc_html_e( 'Slug', 'asae-content-ingestor' ); ?></label>
				</th>
				<td>
					<input type="text" id="oto-slug" class="regular-text" readonly />
					<span id="oto-slug-status" style="margin-left:8px;"></span>
					<p class="description"><?php esc_html_e( 'Auto-generated from the title. Will be verified before running.', 'asae-content-ingestor' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Post Status', 'asae-content-ingestor' ); ?></th>
				<td>
					<label>
						<input type="radio" name="oto-status" value="draft" checked />
						<?php esc_html_e( 'Draft', 'asae-content-ingestor' ); ?>
					</label>
					&nbsp;&nbsp;
					<label>
						<input type="radio" name="oto-status" value="publish" />
						<?php esc_html_e( 'Publish', 'asae-content-ingestor' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'If a publish date is found in the source, it will be used regardless of this setting.', 'asae-content-ingestor' ); ?></p>
				</td>
			</tr>
		</table>

		<p>
			<button type="button" id="oto-run-btn" class="button button-primary" disabled>
				<?php esc_html_e( 'Run Ingestion', 'asae-content-ingestor' ); ?>
			</button>
		</p>
	</div>

	<!-- ── Verbose Log Output ─────────────────────────────────────────────── -->

	<div class="asae-ci-panel asae-ci-hidden" id="oto-log-panel">
		<h2><?php esc_html_e( 'Ingestion Log', 'asae-content-ingestor' ); ?></h2>
		<div id="oto-log" style="background:#1d2327;color:#c3c4c7;padding:12px 16px;font-family:monospace;font-size:13px;line-height:1.6;max-height:500px;overflow-y:auto;border-radius:4px;" aria-live="polite"></div>
	</div>

	<!-- ── Result ─────────────────────────────────────────────────────────── -->

	<div class="asae-ci-panel asae-ci-hidden" id="oto-result-panel">
		<h2><?php esc_html_e( 'Result', 'asae-content-ingestor' ); ?></h2>
		<div id="oto-result"></div>
	</div>

</div>
