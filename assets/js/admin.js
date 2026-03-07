/**
 * ASAE Content Ingestor – Admin JavaScript
 *
 * Drives the run-configuration form, progress polling, and dry-run result display.
 *
 * Flow:
 *  1. User submits the run form → AJAX call creates a job, returns job_key.
 *  2. JS enters a polling loop: every 2 seconds it calls process_ajax_batch,
 *     which both processes a small batch AND returns updated progress data.
 *  3. Progress bars and counters are updated in real-time.
 *  4. On completion: show the "Run complete" message; for Dry Runs, render
 *     the results table; for Active Runs, provide a link to the report.
 *
 * Dependencies: jQuery (bundled with WordPress admin).
 *
 * Accessibility:
 *  - aria-live="polite" on the progress panel (set in the PHP template).
 *  - aria-valuenow is updated on each progress bar update.
 *  - Error messages are injected into an aria-live="assertive" region.
 *  - The delete confirmation uses a native window.confirm (accessible).
 *
 * @package ASAE_Content_Ingestor
 * @since   0.0.1
 */

/* global asaeCi, jQuery */

( function ( $ ) {
	'use strict';

	// ── Cached DOM references ────────────────────────────────────────────────

	var $form             = $( '#asae-ci-run-form' );
	var $startBtn         = $( '#asae-ci-start-btn' );
	var $formError        = $( '#asae-ci-form-error' );
	var $progressPanel    = $( '#asae-ci-progress-panel' );
	var $phaseLabel       = $( '#asae-ci-phase-label' );
	var $crawledCount     = $( '#asae-ci-crawled-count' );
	var $foundCount       = $( '#asae-ci-found-count' );
	var $processedCount   = $( '#asae-ci-processed-count' );
	var $failedCount      = $( '#asae-ci-failed-count' );
	var $discoveryBar     = $( '#asae-ci-discovery-bar' );
	var $discoveryBarWrap = $( '#asae-ci-discovery-bar-wrap' );
	var $ingestBar        = $( '#asae-ci-ingest-bar' );
	var $ingestBarWrap    = $( '#asae-ci-ingest-bar-wrap' );
	var $completeMsg      = $( '#asae-ci-complete-msg' );
	var $reportLink       = $( '#asae-ci-report-link' );
	var $dryPanel         = $( '#asae-ci-dry-results-panel' );
	var $dryTableWrap     = $( '#asae-ci-dry-table-wrap' );
	var $reviewPanel      = $( '#asae-ci-review-panel' );
	var $reviewTableWrap  = $( '#asae-ci-review-table-wrap' );
	var $reviewApplyRow   = $( '#asae-ci-review-apply-row' );
	var $reviewError      = $( '#asae-ci-review-error' );
	var $applyBtn         = $( '#asae-ci-apply-categories-btn' );
	var $modal            = $( '#asae-ci-preview-modal' );
	var $modalBody        = $( '#asae-ci-modal-body' );

	// ── State ────────────────────────────────────────────────────────────────

	var currentJobKey     = null;
	var pollTimer         = null;
	var isProcessing      = false;
	var pendingReviewData = null; // Stored when job enters needs_review state.
	var dryResultsData    = [];   // Full dry-run result objects for the preview modal.
	var modalTrigger      = null; // Button that opened the modal (for focus restoration).

	// ── Form Submission ──────────────────────────────────────────────────────

	$form.on( 'submit', function ( e ) {
		e.preventDefault();

		var sourceUrl = $.trim( $( '#asae-ci-source-url' ).val() );

		// Basic client-side validation.
		if ( ! sourceUrl ) {
			showFormError( 'Please enter a URL to crawl.' );
			$( '#asae-ci-source-url' ).trigger( 'focus' );
			return;
		}
		if ( ! isValidUrl( sourceUrl ) ) {
			showFormError( 'Please enter a valid URL (starting with http:// or https://).' );
			$( '#asae-ci-source-url' ).trigger( 'focus' );
			return;
		}

		clearFormError();
		setSubmitBusy( true );
		resetProgressUI();

		$.ajax( {
			url    : asaeCi.ajaxUrl,
			method : 'POST',
			data   : {
				action          : 'asae_ci_start_job',
				nonce           : asaeCi.nonce,
				source_url      : sourceUrl,
				url_restriction : $.trim( $( '#asae-ci-url-restriction' ).val() ) || '',
				additional_tags : $.trim( $( '#asae-ci-additional-tags' ).val() ) || '',
				post_type       : $( '#asae-ci-post-type' ).val(),
				batch_limit     : $( 'input[name="batch_limit"]:checked' ).val() || '50',
				run_type        : $( 'input[name="run_type"]:checked' ).val()    || 'dry',
				source_type     : $( 'input[name="source_type"]:checked' ).val() || 'replace',
			},
		} )
		.done( function ( response ) {
			if ( ! response.success ) {
				showFormError( response.data && response.data.message ? response.data.message : 'An unknown error occurred.' );
				setSubmitBusy( false );
				return;
			}

			currentJobKey = response.data.job_key;
			$progressPanel.removeClass( 'asae-ci-hidden' );
			updatePhaseLabel( 'discovering' );
			startPollingLoop();
		} )
		.fail( function () {
			showFormError( 'Network error. Please try again.' );
			setSubmitBusy( false );
		} );
	} );

	// ── Polling Loop ─────────────────────────────────────────────────────────

	/**
	 * Starts the polling/processing loop. Each tick both processes a batch
	 * AND retrieves updated progress data.
	 */
	function startPollingLoop() {
		if ( pollTimer ) {
			clearTimeout( pollTimer );
		}
		processBatch();
	}

	/**
	 * Sends one process_batch AJAX call, updates the UI, and schedules the next
	 * tick unless the job is complete.
	 */
	function processBatch() {
		if ( isProcessing || ! currentJobKey ) {
			return;
		}
		isProcessing = true;

		$.ajax( {
			url    : asaeCi.ajaxUrl,
			method : 'POST',
			data   : {
				action  : 'asae_ci_process_batch',
				nonce   : asaeCi.nonce,
				job_key : currentJobKey,
			},
		} )
		.done( function ( response ) {
			isProcessing = false;

			if ( ! response.success ) {
				handleJobError( response.data && response.data.message ? response.data.message : 'Processing error.' );
				return;
			}

			var data = response.data;
			updateProgressUI( data );

			if ( data.is_needs_review ) {
				clearTimeout( pollTimer );
				updatePhaseLabel( 'needs_review' );
				setSubmitBusy( false );
				setProgressBar( $discoveryBar, $discoveryBarWrap, 100 );
				setProgressBar( $ingestBar, $ingestBarWrap, 100 );
				onNeedsReview( data );
			} else if ( data.is_complete ) {
				onJobComplete( data );
			} else {
				// Schedule next batch after a short delay to avoid hammering the server.
				pollTimer = setTimeout( processBatch, 2000 );
			}
		} )
		.fail( function () {
			isProcessing = false;
			// On network failure, retry after a longer delay.
			pollTimer = setTimeout( processBatch, 5000 );
		} );
	}

	// ── UI Update Helpers ─────────────────────────────────────────────────────

	/**
	 * Refreshes all progress indicators from the server response data object.
	 *
	 * @param {Object} data Progress data from the AJAX response.
	 */
	function updateProgressUI( data ) {
		var phase         = data.phase        || 'discovery';
		var crawled       = data.crawled      || 0;
		var toCrawl       = data.to_crawl     || 0;
		var contentFound  = data.content_found || 0;
		var processed     = data.processed    || 0;
		var failed        = data.failed       || 0;
		var queueRemaining = data.queue_remaining || 0;

		// Update phase label.
		updatePhaseLabel( phase );

		// Update discovery bar and counts.
		$crawledCount.text( crawled );
		$foundCount.text( contentFound );

		var discoveryTotal = crawled + toCrawl;
		var discoveryPct   = discoveryTotal > 0 ? Math.min( 100, Math.round( crawled / discoveryTotal * 100 ) ) : 0;
		if ( 'ingestion' === phase || 'dry' === phase || data.is_complete ) {
			discoveryPct = 100;
		}
		setProgressBar( $discoveryBar, $discoveryBarWrap, discoveryPct );

		// Update ingestion bar and counts.
		$processedCount.text( processed );
		$failedCount.text( failed );

		var ingestTotal = processed + queueRemaining;
		var ingestPct   = ingestTotal > 0 ? Math.min( 100, Math.round( processed / ingestTotal * 100 ) ) : 0;
		setProgressBar( $ingestBar, $ingestBarWrap, ingestPct );
	}

	/**
	 * Sets a progress bar's width and updates its aria-valuenow attribute.
	 *
	 * @param {jQuery} $bar     The bar element.
	 * @param {jQuery} $wrap    The wrapper element (holds aria-valuenow).
	 * @param {number} pct      Percentage (0–100).
	 */
	function setProgressBar( $bar, $wrap, pct ) {
		$bar.css( 'width', pct + '%' );
		$wrap.attr( 'aria-valuenow', pct );
	}

	/**
	 * Updates the phase label text.
	 *
	 * @param {string} phase 'discovery', 'ingestion', 'dry', or 'completed'.
	 */
	function updatePhaseLabel( phase ) {
		var labels = {
			discovery    : asaeCi.strings.discovering,
			ingestion    : asaeCi.strings.ingesting,
			dry          : asaeCi.strings.dryRunning,
			completed    : asaeCi.strings.completed,
			needs_review : asaeCi.strings.needsReview,
		};
		$phaseLabel.text( labels[ phase ] || phase );
	}

	/**
	 * Called when the server reports the job is complete.
	 *
	 * @param {Object} data Final progress data.
	 */
	function onJobComplete( data ) {
		clearTimeout( pollTimer );
		updatePhaseLabel( 'completed' );
		setSubmitBusy( false );

		// Final progress counts.
		setProgressBar( $discoveryBar, $discoveryBarWrap, 100 );
		setProgressBar( $ingestBar, $ingestBarWrap, 100 );

		$completeMsg.removeClass( 'asae-ci-hidden' );

		if ( 'dry' === data.run_type ) {
			// Render dry-run results table.
			renderDryResults( data.dry_results || [] );
		} else {
			// Show link to the full report.
			if ( data.report_id ) {
				var reportUrl = ajaxReportUrl( data.report_id );
				$reportLink.attr( 'href', reportUrl ).removeClass( 'asae-ci-hidden' );
			}
		}
	}

	/**
	 * Handles a terminal job error.
	 *
	 * @param {string} message Error message to display.
	 */
	function handleJobError( message ) {
		clearTimeout( pollTimer );
		setSubmitBusy( false );
		updatePhaseLabel( 'failed' );
		showFormError( message );
	}

	// ── Dry Run Results Table ─────────────────────────────────────────────────

	/**
	 * Renders the dry-run preview results as an accessible HTML table.
	 * Each row includes a "Preview" button that opens the detail modal.
	 *
	 * @param {Array} results Array of dry-run preview objects.
	 */
	function renderDryResults( results ) {
		// Store the full results for the preview modal to reference by index.
		dryResultsData = results || [];

		if ( ! dryResultsData.length ) {
			$dryTableWrap.html( '<p>No articles were found in the specified folder.</p>' );
			$dryPanel.removeClass( 'asae-ci-hidden' );
			return;
		}

		var rows = dryResultsData.map( function ( item, idx ) {
			var statusLabel = item.is_duplicate ? 'Would skip (duplicate)' : 'Would ingest';
			var statusClass = item.is_duplicate ? 'asae-ci-status-warning' : 'asae-ci-status-info';
			var tagsText    = Array.isArray( item.tags ) ? item.tags.join( ', ' ) : ( item.tags || '' );
			var authorText  = item.author || '—';
			// Show only the date portion (YYYY-MM-DD) when a full datetime is present.
			var dateText    = item.date ? item.date.replace( /\s.*$/, '' ) : '—';
			var catText     = item.category_match ? item.category_match : '— (needs review)';
			var catClass    = item.category_match ? '' : 'asae-ci-status-warning';

			return '<tr>' +
				'<td class="asae-ci-url-cell"><a href="' + escAttr( item.source_url ) + '" target="_blank" rel="noopener noreferrer">' + escHtml( item.source_url ) + '</a></td>' +
				'<td>' + escHtml( item.post_title || '(untitled)' ) + '</td>' +
				'<td>' + escHtml( authorText ) + '</td>' +
				'<td>' + escHtml( dateText ) + '</td>' +
				'<td>' + escHtml( tagsText ) + '</td>' +
				'<td><span class="' + ( catClass ? 'asae-ci-status ' + catClass : '' ) + '">' + escHtml( catText ) + '</span></td>' +
				'<td><span class="asae-ci-status ' + statusClass + '">' + escHtml( statusLabel ) + '</span></td>' +
				'<td><button type="button" class="button button-small asae-ci-preview-btn" data-idx="' + idx + '">Preview</button></td>' +
				'</tr>';
		} );

		var html = '<table class="wp-list-table widefat striped asae-ci-dry-table">' +
			'<caption class="screen-reader-text">Dry run preview results</caption>' +
			'<thead><tr>' +
			'<th scope="col">Source URL</th>' +
			'<th scope="col">Title</th>' +
			'<th scope="col">Author</th>' +
			'<th scope="col">Date</th>' +
			'<th scope="col">Tags</th>' +
			'<th scope="col">Category</th>' +
			'<th scope="col">Action</th>' +
			'<th scope="col">Detail</th>' +
			'</tr></thead>' +
			'<tbody>' + rows.join( '' ) + '</tbody>' +
			'</table>';

		$dryTableWrap.html( html );
		$dryPanel.removeClass( 'asae-ci-hidden' );

		// Scroll to the dry results panel.
		$( 'html, body' ).animate( { scrollTop: $dryPanel.offset().top - 40 }, 400 );
	}

	// ── Dry Run Article Preview Modal ─────────────────────────────────────────

	/**
	 * Opens the detail modal for a single dry-run result item.
	 * Populates the modal body with one labelled section per WP field.
	 *
	 * @param {Object} item  One entry from dryResultsData.
	 * @param {Element} triggerEl  The button that was clicked (for focus restoration on close).
	 */
	function openPreviewModal( item, triggerEl ) {
		modalTrigger = triggerEl || null;

		var tagsText   = Array.isArray( item.tags ) ? item.tags.join( ', ' ) : ( item.tags || '—' );
		var dateText   = item.date ? item.date.replace( /\s.*$/, '' ) : '—';
		var catText    = item.category_match || '— (needs review)';
		var excerptTxt = item.excerpt || '—';
		var featText   = item.has_featured ? 'Yes' : 'No';

		// Build a definition list of all WP fields.
		var fields = [
			{ label: 'Source URL',      value: '<a href="' + escAttr( item.source_url ) + '" target="_blank" rel="noopener noreferrer">' + escHtml( item.source_url ) + '</a>' },
			{ label: 'Post Title',      value: escHtml( item.post_title || '(untitled)' ) },
			{ label: 'Author',          value: escHtml( item.author || '—' ) },
			{ label: 'Date',            value: escHtml( dateText ) },
			{ label: 'Tags',            value: escHtml( tagsText ) || '—' },
			{ label: 'Category',        value: escHtml( catText ) },
			{ label: 'Excerpt',         value: escHtml( excerptTxt ) },
			{ label: 'Featured Image',  value: escHtml( featText ) },
		];

		var dlHtml = '<dl class="asae-ci-modal-fields">';
		fields.forEach( function ( f ) {
			dlHtml += '<div class="asae-ci-modal-field"><dt>' + escHtml( f.label ) + '</dt><dd>' + f.value + '</dd></div>';
		} );
		dlHtml += '</dl>';

		// Content preview section with rendered / source toggle.
		var contentHtml = item.content_html || '';
		var contentSection =
			'<div class="asae-ci-modal-field">' +
				'<dt>Post Content</dt>' +
				'<dd>' +
					'<div class="asae-ci-content-preview" id="asae-ci-preview-rendered">' + contentHtml + '</div>' +
					'<pre class="asae-ci-content-source" id="asae-ci-preview-source">' + escHtml( contentHtml ) + '</pre>' +
					'<button type="button" class="button button-small asae-ci-toggle-source" id="asae-ci-toggle-source-btn">View Source</button>' +
				'</dd>' +
			'</div>';

		$modalBody.html(
			'<div class="asae-ci-modal-body-inner">' +
				dlHtml +
				'<dl class="asae-ci-modal-fields">' + contentSection + '</dl>' +
			'</div>'
		);

		// Wire up the source/rendered toggle.
		$( '#asae-ci-toggle-source-btn' ).on( 'click', function () {
			var $btn      = $( this );
			var $rendered = $( '#asae-ci-preview-rendered' );
			var $source   = $( '#asae-ci-preview-source' );
			var showSrc   = $source.hasClass( 'asae-ci-visible' );

			if ( showSrc ) {
				$source.removeClass( 'asae-ci-visible' );
				$rendered.removeClass( 'asae-ci-hidden-view' );
				$btn.text( 'View Source' );
			} else {
				$source.addClass( 'asae-ci-visible' );
				$rendered.addClass( 'asae-ci-hidden-view' );
				$btn.text( 'View Rendered' );
			}
		} );

		$modal.removeClass( 'asae-ci-hidden' );

		// Move focus to the close button for keyboard/screen reader users.
		$( '#asae-ci-modal-close' ).trigger( 'focus' );
	}

	/**
	 * Closes the preview modal and returns focus to the button that opened it.
	 */
	function closePreviewModal() {
		$modal.addClass( 'asae-ci-hidden' );
		$modalBody.html( '' );
		if ( modalTrigger ) {
			$( modalTrigger ).trigger( 'focus' );
			modalTrigger = null;
		}
	}

	// Delegate: open modal when a Preview button in the dry results table is clicked.
	$( document ).on( 'click', '.asae-ci-preview-btn', function () {
		var idx  = parseInt( $( this ).data( 'idx' ), 10 );
		var item = dryResultsData[ idx ];
		if ( item ) {
			openPreviewModal( item, this );
		}
	} );

	// Close modal via close button.
	$( document ).on( 'click', '#asae-ci-modal-close', function () {
		closePreviewModal();
	} );

	// Close modal when clicking the backdrop overlay (outside the modal box).
	$( document ).on( 'click', '#asae-ci-preview-modal', function ( e ) {
		if ( $( e.target ).is( '#asae-ci-preview-modal' ) ) {
			closePreviewModal();
		}
	} );

	// Close modal on Escape key.
	$( document ).on( 'keydown', function ( e ) {
		if ( 27 === e.which && ! $modal.hasClass( 'asae-ci-hidden' ) ) {
			closePreviewModal();
		}
	} );

	// ── Category Review ───────────────────────────────────────────────────────

	/**
	 * Called when the server reports the job is in 'needs_review' status.
	 * Stores review data and renders the review panel.
	 *
	 * @param {Object} data Progress data containing pending_review and review_categories.
	 */
	function onNeedsReview( data ) {
		pendingReviewData = data;
		renderCategoryReview( data.pending_review || [], data.review_categories || [] );
	}

	/**
	 * Renders the category review table: one row per pending draft post, each
	 * with a <select> for the admin to choose a category.
	 *
	 * @param {Array} items      Pending review items [{post_id, post_title, source_url}].
	 * @param {Array} categories Available category terms [{term_id, name}].
	 */
	function renderCategoryReview( items, categories ) {
		if ( ! items || ! items.length ) {
			$reviewPanel.addClass( 'asae-ci-hidden' );
			return;
		}

		// Build the "Apply to All" selector.
		var categoryOptions = '<option value="">— Select a category —</option>';
		categories.forEach( function ( cat ) {
			categoryOptions += '<option value="' + escAttr( String( cat.term_id ) ) + '">' +
				escHtml( cat.name ) + '</option>';
		} );

		var applyAllId = 'asae-ci-apply-all-cat';
		var applyAllHtml =
			'<div style="margin-bottom:0.75em;">' +
				'<label for="' + applyAllId + '"><strong>Apply to all:</strong></label> ' +
				'<select id="' + applyAllId + '">' + categoryOptions + '</select>' +
			'</div>';

		// Build the table rows.
		var rows = items.map( function ( item, idx ) {
			var selectId = 'asae-ci-cat-' + idx;
			return '<tr>' +
				'<td class="asae-ci-url-cell"><a href="' + escAttr( item.source_url ) + '" target="_blank" rel="noopener noreferrer">' + escHtml( item.source_url ) + '</a></td>' +
				'<td>' + escHtml( item.post_title || '(untitled)' ) + '</td>' +
				'<td>' +
					'<label for="' + selectId + '" class="screen-reader-text">' + escHtml( 'Category for ' + ( item.post_title || item.source_url ) ) + '</label>' +
					'<select id="' + selectId + '" data-post-id="' + escAttr( String( item.post_id ) ) + '" class="asae-ci-cat-select">' +
						categoryOptions +
					'</select>' +
				'</td>' +
				'</tr>';
		} );

		var tableHtml =
			applyAllHtml +
			'<table class="wp-list-table widefat striped">' +
				'<caption class="screen-reader-text">Items requiring category assignment</caption>' +
				'<thead><tr>' +
					'<th scope="col">Source URL</th>' +
					'<th scope="col">Title</th>' +
					'<th scope="col">Category</th>' +
				'</tr></thead>' +
				'<tbody>' + rows.join( '' ) + '</tbody>' +
			'</table>';

		$reviewTableWrap.html( tableHtml );
		$reviewApplyRow.removeClass( 'asae-ci-hidden' );
		$reviewError.text( '' );
		$reviewPanel.removeClass( 'asae-ci-hidden' );

		// "Apply to all" propagates the selected category to every row select.
		$( '#' + applyAllId ).on( 'change', function () {
			var val = $( this ).val();
			$( '.asae-ci-cat-select' ).val( val );
		} );

		// Scroll to review panel.
		$( 'html, body' ).animate( { scrollTop: $reviewPanel.offset().top - 40 }, 400 );
	}

	/**
	 * Handles the "Apply Categories & Publish" button click.
	 * Collects the admin's selections and sends them to the server.
	 */
	$applyBtn.on( 'click', function () {
		var assignments = [];
		var allSelected = true;

		$( '.asae-ci-cat-select' ).each( function () {
			var termId  = $.trim( $( this ).val() );
			var postId  = $( this ).data( 'post-id' );
			if ( ! termId ) {
				allSelected = false;
			} else {
				assignments.push( { post_id: postId, term_id: termId } );
			}
		} );

		if ( ! allSelected ) {
			$reviewError.text( 'Please select a category for every item before applying.' );
			return;
		}
		$reviewError.text( '' );

		$applyBtn.prop( 'disabled', true ).text( 'Applying…' );

		$.ajax( {
			url    : asaeCi.ajaxUrl,
			method : 'POST',
			data   : {
				action      : 'asae_ci_apply_categories',
				nonce       : asaeCi.nonce,
				job_key     : currentJobKey,
				assignments : JSON.stringify( assignments ),
			},
		} )
		.done( function ( response ) {
			$applyBtn.prop( 'disabled', false ).text( 'Apply Categories & Publish' );
			if ( ! response.success ) {
				$reviewError.text( response.data && response.data.message ? response.data.message : 'An error occurred.' );
				return;
			}
			var data = response.data;
			if ( data.is_needs_review ) {
				// Some items still unresolved (shouldn't happen if all selected, but handle gracefully).
				onNeedsReview( data );
			} else if ( data.is_complete ) {
				$reviewPanel.addClass( 'asae-ci-hidden' );
				onJobComplete( data );
			}
		} )
		.fail( function () {
			$applyBtn.prop( 'disabled', false ).text( 'Apply Categories & Publish' );
			$reviewError.text( 'Network error. Please try again.' );
		} );
	} );

	// ── Co-Authors Plus Notice Dismiss ────────────────────────────────────────

	$( document ).on( 'click', '#asae-ci-dismiss-cap-notice', function () {
		$.post( asaeCi.ajaxUrl, {
			action : 'asae_ci_dismiss_cap_notice',
			nonce  : asaeCi.nonce,
		} );
		$( '#asae-ci-cap-notice' ).slideUp( 300 );
	} );

	// ── Report Delete (Reports Listing Page) ──────────────────────────────────

	$( document ).on( 'click', '.asae-ci-delete-report', function () {
		var $btn      = $( this );
		var reportId  = $btn.data( 'report-id' );

		if ( ! window.confirm( asaeCi.strings.confirmDelete ) ) {
			return;
		}

		$btn.prop( 'disabled', true ).text( 'Deleting…' );

		$.ajax( {
			url    : asaeCi.ajaxUrl,
			method : 'POST',
			data   : {
				action    : 'asae_ci_delete_report',
				nonce     : asaeCi.nonce,
				report_id : reportId,
			},
		} )
		.done( function ( response ) {
			if ( response.success ) {
				// Remove the table row cleanly.
				$btn.closest( 'tr' ).fadeOut( 300, function () {
					$( this ).remove();
				} );
			} else {
				$btn.prop( 'disabled', false ).text( 'Delete' );
				alert( response.data && response.data.message ? response.data.message : 'Delete failed.' );
			}
		} )
		.fail( function () {
			$btn.prop( 'disabled', false ).text( 'Delete' );
			alert( 'Network error. Please try again.' );
		} );
	} );

	// ── Utility Functions ─────────────────────────────────────────────────────

	/** Shows an error message in the form error region. */
	function showFormError( msg ) {
		$formError.text( msg );
	}

	/** Clears the form error region. */
	function clearFormError() {
		$formError.text( '' );
	}

	/** Enables or disables the submit button and sets its busy state. */
	function setSubmitBusy( busy ) {
		$startBtn.prop( 'disabled', busy );
		$startBtn.text( busy ? asaeCi.strings.startingJob : 'Start Run' );
	}

	/** Resets progress UI to initial hidden state for a fresh run. */
	function resetProgressUI() {
		$progressPanel.removeClass( 'asae-ci-hidden' );
		$dryPanel.addClass( 'asae-ci-hidden' );
		$dryTableWrap.html( '' );
		$reviewPanel.addClass( 'asae-ci-hidden' );
		$reviewTableWrap.html( '' );
		$reviewApplyRow.addClass( 'asae-ci-hidden' );
		$reviewError.text( '' );
		$completeMsg.addClass( 'asae-ci-hidden' );
		$reportLink.addClass( 'asae-ci-hidden' );
		setProgressBar( $discoveryBar, $discoveryBarWrap, 0 );
		setProgressBar( $ingestBar, $ingestBarWrap, 0 );
		$crawledCount.text( '0' );
		$foundCount.text( '0' );
		$processedCount.text( '0' );
		$failedCount.text( '0' );
		pendingReviewData = null;
		dryResultsData    = [];
	}

	/**
	 * Returns the admin URL for a report detail page.
	 *
	 * @param {number} reportId
	 * @returns {string}
	 */
	function ajaxReportUrl( reportId ) {
		return 'tools.php?page=asae-content-ingestor&tab=reports&report_id=' + encodeURIComponent( reportId );
	}

	/**
	 * Basic URL validation.
	 *
	 * @param {string} url
	 * @returns {boolean}
	 */
	function isValidUrl( url ) {
		return /^https?:\/\/.+/i.test( url );
	}

	/**
	 * HTML-escapes a string for safe insertion into the DOM as text.
	 *
	 * @param {string} str
	 * @returns {string}
	 */
	function escHtml( str ) {
		return $( '<span>' ).text( String( str ) ).html();
	}

	/**
	 * Attribute-escapes a string for use in an HTML attribute.
	 *
	 * @param {string} str
	 * @returns {string}
	 */
	function escAttr( str ) {
		return $( '<span>' ).attr( 'data-v', String( str ) ).prop( 'outerHTML' )
		       .replace( /^.*data-v="/, '' ).replace( /".*$/, '' );
	}

} )( jQuery );
