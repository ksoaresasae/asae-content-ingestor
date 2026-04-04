<?php
/**
 * Admin view – Ingestion Reports listing page.
 *
 * Variables available from ASAE_CI_Admin::render_reports_page():
 *  $reports_data – array with keys 'items' and 'total'.
 *  $page         – current page number (int).
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

$reports   = $reports_data['items'] ?? [];
$total     = (int) ( $reports_data['total'] ?? 0 );
$per_page  = 20;
$pages     = $total > 0 ? (int) ceil( $total / $per_page ) : 1;
?>
<div class="wrap asae-ci-wrap">

	<h1><?php esc_html_e( 'ASAE Content Ingestor', 'asae-content-ingestor' ); ?>
		<span class="asae-ci-version">v<?php echo esc_html( ASAE_CI_VERSION ); ?></span>
	</h1>

	<?php ASAE_CI_Admin::render_nav_tabs( 'reports' ); ?>

	<?php
	// Build the nonce-protected export URL.
	$export_url = wp_nonce_url(
		add_query_arg(
			[ 'page' => 'asae-content-ingestor', 'tab' => 'reports', 'asae_ci_action' => 'export_redirects' ],
			admin_url( 'admin.php' )
		),
		ASAE_CI_Admin::EXPORT_NONCE
	);
	?>
	<div style="margin: .75rem 0 1rem;">
		<a
			href="<?php echo esc_url( $export_url ); ?>"
			class="button"
			aria-describedby="asae-ci-export-hint"
		>
			<?php esc_html_e( 'Export ASAEcenter.org Redirects (JSON)', 'asae-content-ingestor' ); ?>
		</a>
		<button
			type="button"
			id="asae-ci-clear-redirects-btn"
			class="button"
			style="margin-left:.5rem; color:#b32d2e;"
		>
			<?php esc_html_e( 'Clear ASAEcenter.org Redirect Data', 'asae-content-ingestor' ); ?>
		</button>
		<p id="asae-ci-export-hint" class="description" style="margin-top:.35rem;">
			<?php esc_html_e( 'Downloads a Redirection-plugin-compatible JSON file of all ingested asaecenter.org URLs for import on the ASAE Center WP site.', 'asae-content-ingestor' ); ?>
		</p>
		<p id="asae-ci-clear-redirects-msg" class="description" style="margin-top:.25rem;" aria-live="polite"></p>
	</div>

	<?php if ( empty( $reports ) ) : ?>
		<div class="asae-ci-card">
			<p><?php esc_html_e( 'No reports found. Run an Active Run to generate reports.', 'asae-content-ingestor' ); ?></p>
		</div>
	<?php else : ?>

		<div class="asae-ci-card">

			<table class="wp-list-table widefat striped asae-ci-reports-table">
				<caption class="screen-reader-text">
					<?php esc_html_e( 'Ingestion run reports', 'asae-content-ingestor' ); ?>
				</caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Run Type', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Source URL', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Post Type', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Limit', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Found', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Ingested', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Skipped', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Failed', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'asae-content-ingestor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'asae-content-ingestor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $reports as $report ) :
						$report_url = add_query_arg(
							[ 'page' => 'asae-content-ingestor', 'tab' => 'reports', 'report_id' => (int) $report['id'] ],
							admin_url( 'admin.php' )
						);
						$status_class = match ( $report['status'] ) {
							'completed' => 'asae-ci-status-ok',
							'running'   => 'asae-ci-status-running',
							default     => 'asae-ci-status-error',
						};
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $report_url ); ?>">
								<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $report['run_date'] ) ) ); ?>
							</a>
						</td>
						<td>
							<span class="asae-ci-badge asae-ci-badge-<?php echo esc_attr( $report['run_type'] ); ?>">
								<?php echo 'dry' === $report['run_type']
									? esc_html__( 'Dry', 'asae-content-ingestor' )
									: esc_html__( 'Active', 'asae-content-ingestor' ); ?>
							</span>
						</td>
						<td class="asae-ci-url-cell">
							<span title="<?php echo esc_attr( $report['source_url'] ); ?>">
								<?php echo esc_html( $report['source_url'] ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $report['post_type'] ); ?></td>
						<td><?php echo esc_html( $report['batch_limit'] ); ?></td>
						<td><?php echo (int) $report['total_found']; ?></td>
						<td><?php echo (int) $report['total_ingested']; ?></td>
						<td><?php echo (int) $report['total_skipped']; ?></td>
						<td><?php echo (int) $report['total_failed']; ?></td>
						<td>
							<span class="asae-ci-status <?php echo esc_attr( $status_class ); ?>">
								<?php echo esc_html( ucfirst( $report['status'] ) ); ?>
							</span>
						</td>
						<td>
							<a href="<?php echo esc_url( $report_url ); ?>" class="button button-small">
								<?php esc_html_e( 'View', 'asae-content-ingestor' ); ?>
							</a>
							<button
								type="button"
								class="button button-small asae-ci-delete-report"
								data-report-id="<?php echo (int) $report['id']; ?>"
								aria-label="<?php echo esc_attr( sprintf(
									/* translators: %s is the report date. */
									__( 'Delete report from %s', 'asae-content-ingestor' ),
									date_i18n( get_option( 'date_format' ), strtotime( $report['run_date'] ) )
								) ); ?>"
							>
								<?php esc_html_e( 'Delete', 'asae-content-ingestor' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
			<div class="asae-ci-pagination" role="navigation" aria-label="<?php esc_attr_e( 'Reports pagination', 'asae-content-ingestor' ); ?>">
				<?php for ( $p = 1; $p <= $pages; $p++ ) :
					$page_url = add_query_arg( [ 'page' => 'asae-content-ingestor', 'tab' => 'reports', 'paged' => $p ], admin_url( 'admin.php' ) );
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

		</div><!-- .asae-ci-card -->

	<?php endif; ?>

</div><!-- .wrap -->
