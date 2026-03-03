<?php
/**
 * Admin view – Single ingestion report detail page.
 *
 * Variables available from ASAE_CI_Admin::render_reports_page():
 *  $report      – array with the report header row.
 *  $items_data  – array with keys 'items' and 'total'.
 *  $page        – current page number (int).
 *
 * @package ASAE_Content_Ingestor
 * @since   0.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$items    = $items_data['items'] ?? [];
$total    = (int) ( $items_data['total'] ?? 0 );
$per_page = 50;
$pages    = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

$report_date = date_i18n(
	get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
	strtotime( $report['run_date'] )
);
?>
<div class="wrap asae-ci-wrap">

	<h1><?php esc_html_e( 'ASAE Content Ingestor', 'asae-content-ingestor' ); ?>
		<span class="asae-ci-version">v<?php echo esc_html( ASAE_CI_VERSION ); ?></span>
	</h1>

	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Content Ingestor navigation', 'asae-content-ingestor' ); ?>">
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=asae-content-ingestor' ) ); ?>"
		   class="nav-tab">
			<?php esc_html_e( 'Run', 'asae-content-ingestor' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=asae-content-ingestor&tab=reports' ) ); ?>"
		   class="nav-tab nav-tab-active"
		   aria-current="page">
			<?php esc_html_e( 'Reports', 'asae-content-ingestor' ); ?>
		</a>
	</nav>

	<p>
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=asae-content-ingestor&tab=reports' ) ); ?>" class="button">
			&larr; <?php esc_html_e( 'Back to Reports', 'asae-content-ingestor' ); ?>
		</a>
	</p>

	<h2>
		<?php esc_html_e( 'Report Detail', 'asae-content-ingestor' ); ?>
		<span class="asae-ci-version"><?php echo esc_html( $report_date ); ?></span>
	</h2>

	<!-- ── Report Summary Card ───────────────────────────────────────────── -->
	<section class="asae-ci-card" aria-labelledby="asae-ci-report-summary-heading">
		<h2 id="asae-ci-report-summary-heading"><?php esc_html_e( 'Summary', 'asae-content-ingestor' ); ?></h2>

		<table class="asae-ci-summary-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Date', 'asae-content-ingestor' ); ?></th>
					<td><?php echo esc_html( $report_date ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Run Type', 'asae-content-ingestor' ); ?></th>
					<td>
						<span class="asae-ci-badge asae-ci-badge-<?php echo esc_attr( $report['run_type'] ); ?>">
							<?php echo 'dry' === $report['run_type']
								? esc_html__( 'Dry Run', 'asae-content-ingestor' )
								: esc_html__( 'Active Run', 'asae-content-ingestor' ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'RSS Feed URL', 'asae-content-ingestor' ); ?></th>
					<td><a href="<?php echo esc_url( $report['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $report['source_url'] ); ?></a></td>
				</tr>
				<?php if ( ! empty( $report['url_restriction'] ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'URL Restriction', 'asae-content-ingestor' ); ?></th>
					<td><?php echo esc_html( $report['url_restriction'] ); ?></td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Post Type', 'asae-content-ingestor' ); ?></th>
					<td><?php echo esc_html( $report['post_type'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Content Limit', 'asae-content-ingestor' ); ?></th>
					<td><?php echo esc_html( $report['batch_limit'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'asae-content-ingestor' ); ?></th>
					<td>
						<?php
						$status_class = match ( $report['status'] ) {
							'completed' => 'asae-ci-status-ok',
							'running'   => 'asae-ci-status-running',
							default     => 'asae-ci-status-error',
						};
						?>
						<span class="asae-ci-status <?php echo esc_attr( $status_class ); ?>">
							<?php echo esc_html( ucfirst( $report['status'] ) ); ?>
						</span>
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Ingestion counts as a summary row -->
		<div class="asae-ci-counts-row">
			<div class="asae-ci-count-block">
				<span class="asae-ci-count-number"><?php echo (int) $report['total_found']; ?></span>
				<span class="asae-ci-count-label"><?php esc_html_e( 'Found', 'asae-content-ingestor' ); ?></span>
			</div>
			<div class="asae-ci-count-block asae-ci-count-ingested">
				<span class="asae-ci-count-number"><?php echo (int) $report['total_ingested']; ?></span>
				<span class="asae-ci-count-label"><?php esc_html_e( 'Ingested', 'asae-content-ingestor' ); ?></span>
			</div>
			<div class="asae-ci-count-block asae-ci-count-skipped">
				<span class="asae-ci-count-number"><?php echo (int) $report['total_skipped']; ?></span>
				<span class="asae-ci-count-label"><?php esc_html_e( 'Skipped', 'asae-content-ingestor' ); ?></span>
			</div>
			<div class="asae-ci-count-block asae-ci-count-failed">
				<span class="asae-ci-count-number"><?php echo (int) $report['total_failed']; ?></span>
				<span class="asae-ci-count-label"><?php esc_html_e( 'Failed', 'asae-content-ingestor' ); ?></span>
			</div>
		</div>

	</section>

	<!-- ── Items Table ───────────────────────────────────────────────────── -->
	<section class="asae-ci-card" aria-labelledby="asae-ci-items-heading">
		<h2 id="asae-ci-items-heading">
			<?php
			printf(
				/* translators: %d is the total number of items. */
				esc_html__( 'Content Items (%d)', 'asae-content-ingestor' ),
				$total
			);
			?>
		</h2>

		<?php if ( empty( $items ) ) : ?>
			<p><?php esc_html_e( 'No items recorded for this report.', 'asae-content-ingestor' ); ?></p>
		<?php else : ?>

			<?php if ( $pages > 1 ) : ?>
			<div class="asae-ci-pagination" role="navigation" aria-label="<?php esc_attr_e( 'Items pagination (top)', 'asae-content-ingestor' ); ?>">
				<?php for ( $p = 1; $p <= $pages; $p++ ) :
					$page_url = add_query_arg(
						[ 'page' => 'asae-content-ingestor', 'tab' => 'reports', 'report_id' => (int) $report['id'], 'paged' => $p ],
						admin_url( 'tools.php' )
					);
					$is_current = ( $p === (int) $page );
				?>
				<a
					href="<?php echo esc_url( $page_url ); ?>"
					class="button <?php echo $is_current ? 'button-primary' : ''; ?>"
					<?php if ( $is_current ) : ?>aria-current="page"<?php endif; ?>
				><?php echo (int) $p; ?></a>
				<?php endfor; ?>
			</div>
			<?php endif; ?>

			<table class="wp-list-table widefat striped asae-ci-items-table">
				<caption class="screen-reader-text">
					<?php esc_html_e( 'Ingested content items', 'asae-content-ingestor' ); ?>
				</caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Source URL', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Title', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Author', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Date', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tags', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Notes', 'asae-content-ingestor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) :
						$status_class = match ( $item['item_status'] ) {
							'ingested' => 'asae-ci-status-ok',
							'skipped'  => 'asae-ci-status-warning',
							'dry'      => 'asae-ci-status-info',
							default    => 'asae-ci-status-error',
						};

						// If a WP post was created, link the title to it.
						$post_id    = (int) ( $item['wp_post_id'] ?? 0 );
						$post_title = esc_html( $item['post_title'] ?: __( '(untitled)', 'asae-content-ingestor' ) );
						if ( $post_id > 0 && get_post( $post_id ) ) {
							$post_link = sprintf(
								'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
								esc_url( get_permalink( $post_id ) ),
								$post_title
							);
						} else {
							$post_link = $post_title;
						}

						// Format the content date using the site's date format setting.
						$item_date = '';
						if ( ! empty( $item['post_date'] ) ) {
							$item_date = date_i18n( get_option( 'date_format' ), strtotime( $item['post_date'] ) );
						}
					?>
					<tr>
						<td class="asae-ci-url-cell">
							<a href="<?php echo esc_url( $item['source_url'] ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $item['source_url'] ); ?>">
								<?php echo esc_html( $item['source_url'] ); ?>
							</a>
						</td>
						<td><?php echo $post_link; // Post link is already escaped above. ?></td>
						<td><?php echo esc_html( $item['post_author'] ?: '—' ); ?></td>
						<td><?php echo esc_html( $item_date ?: '—' ); ?></td>
						<td class="asae-ci-tags-cell"><?php echo esc_html( $item['tags'] ); ?></td>
						<td>
							<span class="asae-ci-status <?php echo esc_attr( $status_class ); ?>">
								<?php echo esc_html( ucfirst( $item['item_status'] ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $item['notes'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
			<div class="asae-ci-pagination" role="navigation" aria-label="<?php esc_attr_e( 'Items pagination (bottom)', 'asae-content-ingestor' ); ?>">
				<?php for ( $p = 1; $p <= $pages; $p++ ) :
					$page_url = add_query_arg(
						[ 'page' => 'asae-content-ingestor', 'tab' => 'reports', 'report_id' => (int) $report['id'], 'paged' => $p ],
						admin_url( 'tools.php' )
					);
					$is_current = ( $p === (int) $page );
				?>
				<a
					href="<?php echo esc_url( $page_url ); ?>"
					class="button <?php echo $is_current ? 'button-primary' : ''; ?>"
					<?php if ( $is_current ) : ?>aria-current="page"<?php endif; ?>
				><?php echo (int) $p; ?></a>
				<?php endfor; ?>
			</div>
			<?php endif; ?>

		<?php endif; ?>

	</section>

</div><!-- .wrap -->
