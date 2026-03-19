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

	// ── Prefill Feed URL from YouTube Tab ────────────────────────────────────

	// When arriving from the YouTube tab via "Use in Run Tab →", auto-fill the source URL.
	( function () {
		var params = new URLSearchParams( window.location.search );
		var prefill = params.get( 'prefill_feed' );
		if ( prefill && $( '#asae-ci-source-url' ).length ) {
			$( '#asae-ci-source-url' ).val( prefill );
		}
	} )();

	// ── Resume / Cancel Banner for Running Jobs ─────────────────────────────
	// If the server detected a job still in 'running' status (e.g. after a
	// session timeout or page reload), show a banner giving the admin the
	// choice to resume or cancel the job.

	var $resumeBanner = $( '#asae-ci-resume-banner' );
	var $resumeBtn    = $( '#asae-ci-resume-btn' );
	var $cancelBtn    = $( '#asae-ci-cancel-btn' );
	var $resumeDetail = $( '#asae-ci-resume-detail' );

	if ( asaeCi.runningJobKey ) {
		$resumeDetail.text( ' Job key: ' + asaeCi.runningJobKey );
		$resumeBanner.removeClass( 'asae-ci-hidden' );
	}

	$resumeBtn.on( 'click', function () {
		$resumeBanner.addClass( 'asae-ci-hidden' );
		currentJobKey = asaeCi.runningJobKey;
		$progressPanel.removeClass( 'asae-ci-hidden' );
		setSubmitBusy( true );
		updatePhaseLabel( 'ingesting' );
		startPollingLoop();
	} );

	$cancelBtn.on( 'click', function () {
		$cancelBtn.prop( 'disabled', true ).text( 'Cancelling…' );
		$.ajax( {
			url    : asaeCi.ajaxUrl,
			method : 'POST',
			data   : {
				action  : 'asae_ci_cancel_job',
				nonce   : asaeCi.nonce,
				job_key : asaeCi.runningJobKey,
			},
		} )
		.done( function ( response ) {
			$resumeBanner.addClass( 'asae-ci-hidden' );
			if ( response.success ) {
				showFormError( '' );
				$formError.css( 'color', '#1d7444' ).text( 'Job cancelled successfully.' );
			} else {
				showFormError( response.data && response.data.message ? response.data.message : 'Could not cancel job.' );
			}
		} )
		.fail( function () {
			showFormError( 'Network error while cancelling job.' );
		} )
		.always( function () {
			$cancelBtn.prop( 'disabled', false ).text( 'Cancel Job' );
		} );
	} );

	// ── Keep-Alive Toggle ────────────────────────────────────────────────────
	// Uses the Screen Wake Lock API to prevent the screen from sleeping and
	// fires a lightweight heartbeat request to keep the WP session alive.

	var $keepAlive       = $( '#asae-ci-keep-alive' );
	var $keepAliveStatus = $( '#asae-ci-keep-alive-status' );
	var wakeLock         = null;
	var heartbeatTimer   = null;

	$keepAlive.on( 'change', function () {
		if ( this.checked ) {
			activateKeepAlive();
		} else {
			deactivateKeepAlive();
		}
	} );

	function activateKeepAlive() {
		// Screen Wake Lock (prevents display sleep).
		if ( 'wakeLock' in navigator ) {
			navigator.wakeLock.request( 'screen' ).then( function ( lock ) {
				wakeLock = lock;
				wakeLock.addEventListener( 'release', function () {
					wakeLock = null;
					updateKeepAliveStatus();
				} );
				updateKeepAliveStatus();
			} ).catch( function () {
				updateKeepAliveStatus();
			} );
		}

		// WP session heartbeat — ping every 60 seconds to refresh the session.
		if ( ! heartbeatTimer ) {
			heartbeatTimer = setInterval( function () {
				$.post( asaeCi.ajaxUrl, {
					action : 'heartbeat',
					_nonce : asaeCi.nonce,
					data   : {},
				} );
			}, 60000 );
		}
		updateKeepAliveStatus();
	}

	function deactivateKeepAlive() {
		if ( wakeLock ) {
			wakeLock.release();
			wakeLock = null;
		}
		if ( heartbeatTimer ) {
			clearInterval( heartbeatTimer );
			heartbeatTimer = null;
		}
		updateKeepAliveStatus();
	}

	function updateKeepAliveStatus() {
		if ( ! $keepAlive.is( ':checked' ) ) {
			$keepAliveStatus.text( '' );
			return;
		}
		var parts = [];
		if ( wakeLock ) {
			parts.push( 'screen lock active' );
		} else if ( 'wakeLock' in navigator ) {
			parts.push( 'screen lock unavailable' );
		} else {
			parts.push( 'screen lock not supported' );
		}
		if ( heartbeatTimer ) {
			parts.push( 'session heartbeat active' );
		}
		$keepAliveStatus.text( parts.join( ', ' ) );
	}

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
			var catMatch    = item.category_match;
			var catText     = catMatch ? ( catMatch.name || catMatch ) : '— (needs review)';
			var catClass    = catMatch ? '' : 'asae-ci-status-warning';

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
		var catMatch   = item.category_match;
		var catText    = catMatch ? ( catMatch.name || catMatch ) : '— (needs review)';
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

	// ── Category Review (paginated, server-side) ────────────────────────────

	var reviewCurrentPage  = 1;
	var reviewPerPage      = 100;
	var reviewSearchTerm   = '';
	var reviewAssignments  = {};   // { postId: termId } – persists across pages.
	var reviewTotal        = 0;    // Total pending items (from server).
	var reviewCategories   = [];   // Cached category list.
	var reviewSearchTimer  = null; // Debounce timer for search input.

	var $reviewToolbar    = $( '#asae-ci-review-toolbar' );
	var $reviewBulkRow    = $( '#asae-ci-review-bulk-row' );
	var $reviewPagination = $( '#asae-ci-review-pagination' );
	var $reviewProgress   = $( '#asae-ci-review-progress' );
	var $applyProgress    = $( '#asae-ci-apply-progress' );
	var $applyAllBtn      = $( '#asae-ci-apply-all-btn' );
	var $bulkCatSelect    = $( '#asae-ci-review-bulk-cat' );

	/**
	 * Called when the server reports the job is in 'needs_review' status.
	 * Fetches the first page of review items from a dedicated endpoint.
	 *
	 * @param {Object} data Progress data (contains pending_review_total).
	 */
	function onNeedsReview( data ) {
		pendingReviewData = data;
		reviewTotal = data.pending_review_total || 0;
		reviewCurrentPage = 1;
		reviewSearchTerm = '';
		$( '#asae-ci-review-search' ).val( '' );

		fetchReviewPage();
	}

	/**
	 * Fetches a single page of pending review items from the server.
	 */
	function fetchReviewPage() {
		$.ajax( {
			url    : asaeCi.ajaxUrl,
			method : 'POST',
			data   : {
				action   : 'asae_ci_fetch_review_page',
				nonce    : asaeCi.nonce,
				job_key  : currentJobKey,
				page     : reviewCurrentPage,
				per_page : reviewPerPage,
				search   : reviewSearchTerm,
			},
		} )
		.done( function ( response ) {
			if ( ! response.success ) {
				$reviewError.text( response.data && response.data.message ? response.data.message : 'Failed to load review items.' );
				return;
			}

			var d = response.data;
			reviewTotal      = d.total;
			reviewCategories = d.categories || [];
			reviewCurrentPage = d.page;

			renderCategoryReview( d.items, d.categories, d.total, d.page, d.pages );
		} )
		.fail( function () {
			$reviewError.text( 'Network error loading review items.' );
		} );
	}

	/**
	 * Renders the category review table for the current page of items.
	 *
	 * @param {Array}  items      Page of review items.
	 * @param {Array}  categories Available categories.
	 * @param {number} total      Total pending items (filtered).
	 * @param {number} page       Current page number.
	 * @param {number} pages      Total pages.
	 */
	function renderCategoryReview( items, categories, total, page, pages ) {
		if ( ! total ) {
			$reviewPanel.addClass( 'asae-ci-hidden' );
			return;
		}

		// Build category <option> HTML.
		var categoryOptions = '<option value="">\u2014 Select \u2014</option>';
		categories.forEach( function ( cat ) {
			categoryOptions += '<option value="' + escAttr( String( cat.term_id ) ) + '">' +
				escHtml( cat.name ) + '</option>';
		} );

		// Update progress counter.
		var assigned = Object.keys( reviewAssignments ).length;
		$reviewProgress.text( assigned + ' of ' + total + ' categorized' );

		// Populate bulk category dropdown.
		$bulkCatSelect.html( categoryOptions );

		// Build table rows.
		var rows = items.map( function ( item, idx ) {
			var selectId = 'asae-ci-cat-' + idx;
			var postId   = String( item.post_id );
			var saved    = reviewAssignments[ postId ] || '';

			// Build select with saved value pre-selected.
			var opts = '<option value="">\u2014 Select \u2014</option>';
			categories.forEach( function ( cat ) {
				var sel = ( String( cat.term_id ) === saved ) ? ' selected' : '';
				opts += '<option value="' + escAttr( String( cat.term_id ) ) + '"' + sel + '>' +
					escHtml( cat.name ) + '</option>';
			} );

			return '<tr>' +
				'<td class="asae-ci-url-cell"><a href="' + escAttr( item.source_url ) + '" target="_blank" rel="noopener noreferrer">' + escHtml( item.source_url ) + '</a></td>' +
				'<td>' + escHtml( item.post_title || '(untitled)' ) + '</td>' +
				'<td>' +
					'<label for="' + selectId + '" class="screen-reader-text">' + escHtml( 'Category for ' + ( item.post_title || item.source_url ) ) + '</label>' +
					'<select id="' + selectId + '" data-post-id="' + escAttr( postId ) + '" class="asae-ci-cat-select">' +
						opts +
					'</select>' +
				'</td>' +
				'</tr>';
		} );

		var tableHtml =
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

		// Render pagination.
		if ( pages > 1 ) {
			var pagBtns = '';
			// Show prev button.
			if ( page > 1 ) {
				pagBtns += '<button type="button" class="button asae-ci-review-page-btn" data-page="' + ( page - 1 ) + '">&laquo; Prev</button>';
			}
			// Show page number buttons (max 7 around current page).
			var startP = Math.max( 1, page - 3 );
			var endP   = Math.min( pages, page + 3 );
			for ( var p = startP; p <= endP; p++ ) {
				var active = ( p === page ) ? ' button-primary' : '';
				var aria   = ( p === page ) ? ' aria-current="page"' : '';
				pagBtns += '<button type="button" class="button asae-ci-review-page-btn' + active + '" data-page="' + p + '"' + aria + '>' + p + '</button>';
			}
			// Show next button.
			if ( page < pages ) {
				pagBtns += '<button type="button" class="button asae-ci-review-page-btn" data-page="' + ( page + 1 ) + '">Next &raquo;</button>';
			}
			$reviewPagination.html( pagBtns ).removeClass( 'asae-ci-hidden' );
		} else {
			$reviewPagination.addClass( 'asae-ci-hidden' );
		}

		// Enable the "Apply to ALL" button once a bulk category is chosen.
		$applyAllBtn.prop( 'disabled', ! $bulkCatSelect.val() );
		$applyAllBtn.text( 'Apply to ALL ' + total + ' items' );

		// Show all UI sections.
		$reviewToolbar.removeClass( 'asae-ci-hidden' );
		$reviewBulkRow.removeClass( 'asae-ci-hidden' );
		$reviewApplyRow.removeClass( 'asae-ci-hidden' );
		$reviewError.text( '' );
		$applyProgress.text( '' );
		$reviewPanel.removeClass( 'asae-ci-hidden' );

		// Scroll on first load only.
		if ( page === 1 && ! reviewSearchTerm ) {
			$( 'html, body' ).animate( { scrollTop: $reviewPanel.offset().top - 40 }, 400 );
		}
	}

	/**
	 * Saves the current page's dropdown selections into reviewAssignments.
	 */
	function saveCurrentPageSelections() {
		$( '.asae-ci-cat-select' ).each( function () {
			var val    = $.trim( $( this ).val() );
			var postId = String( $( this ).data( 'post-id' ) );
			if ( val ) {
				reviewAssignments[ postId ] = val;
			} else {
				delete reviewAssignments[ postId ];
			}
		} );
	}

	/**
	 * Updates the progress counter text.
	 */
	function updateReviewProgress() {
		var assigned = Object.keys( reviewAssignments ).length;
		$reviewProgress.text( assigned + ' of ' + reviewTotal + ' categorized' );
	}

	// ── Review Event Handlers ────────────────────────────────────────────────

	// Pagination clicks.
	$( document ).on( 'click', '.asae-ci-review-page-btn', function () {
		saveCurrentPageSelections();
		reviewCurrentPage = parseInt( $( this ).data( 'page' ), 10 ) || 1;
		fetchReviewPage();
		$( 'html, body' ).animate( { scrollTop: $reviewTableWrap.offset().top - 40 }, 200 );
	} );

	// Search input (debounced 300ms).
	$( document ).on( 'input', '#asae-ci-review-search', function () {
		var val = $.trim( $( this ).val() );
		clearTimeout( reviewSearchTimer );
		reviewSearchTimer = setTimeout( function () {
			saveCurrentPageSelections();
			reviewSearchTerm  = val;
			reviewCurrentPage = 1;
			fetchReviewPage();
		}, 300 );
	} );

	// Individual select change – immediately save to reviewAssignments.
	$( document ).on( 'change', '.asae-ci-cat-select', function () {
		var val    = $.trim( $( this ).val() );
		var postId = String( $( this ).data( 'post-id' ) );
		if ( val ) {
			reviewAssignments[ postId ] = val;
		} else {
			delete reviewAssignments[ postId ];
		}
		updateReviewProgress();
	} );

	// Bulk "Apply to all visible" – sets current page dropdowns.
	$bulkCatSelect.on( 'change', function () {
		var val = $( this ).val();
		$applyAllBtn.prop( 'disabled', ! val );
		if ( val ) {
			$( '.asae-ci-cat-select' ).val( val ).trigger( 'change' );
		}
	} );

	// "Apply to ALL N items" button – chunked server-side bulk apply.
	$applyAllBtn.on( 'click', function () {
		var termId = $bulkCatSelect.val();
		if ( ! termId ) {
			$reviewError.text( 'Please select a category first.' );
			return;
		}

		if ( ! window.confirm( 'Apply this category to ALL ' + reviewTotal + ' pending items and publish them?' ) ) {
			return;
		}

		$applyAllBtn.prop( 'disabled', true );
		$applyBtn.prop( 'disabled', true );
		$reviewError.text( '' );

		var totalApplied = 0;
		var originalTotal = reviewTotal;

		function applyNextBatch() {
			$applyProgress.text( 'Publishing\u2026 ' + totalApplied + ' of ' + originalTotal + ' done' );

			$.ajax( {
				url    : asaeCi.ajaxUrl,
				method : 'POST',
				data   : {
					action  : 'asae_ci_apply_category_to_all',
					nonce   : asaeCi.nonce,
					job_key : currentJobKey,
					term_id : termId,
				},
			} )
			.done( function ( response ) {
				if ( ! response.success ) {
					$applyAllBtn.prop( 'disabled', false ).text( 'Apply to ALL ' + reviewTotal + ' items' );
					$applyBtn.prop( 'disabled', false );
					$applyProgress.text( '' );
					$reviewError.text( response.data && response.data.message ? response.data.message : 'An error occurred.' );
					return;
				}

				var d = response.data;
				totalApplied += d.applied;

				if ( 'completed' === d.status ) {
					reviewAssignments = {};
					$reviewPanel.addClass( 'asae-ci-hidden' );
					$applyProgress.text( 'Applied category to ' + totalApplied + ' items.' );
					$applyAllBtn.prop( 'disabled', false );
					$applyBtn.prop( 'disabled', false );
					// Refresh job state to show completion.
					$.ajax( {
						url    : asaeCi.ajaxUrl,
						method : 'POST',
						data   : {
							action  : 'asae_ci_get_progress',
							nonce   : asaeCi.nonce,
							job_key : currentJobKey,
						},
					} ).done( function ( resp ) {
						if ( resp.success ) {
							onJobComplete( resp.data );
						}
					} );
				} else {
					// More items remain — send next batch.
					$applyProgress.text( 'Publishing\u2026 ' + totalApplied + ' of ' + originalTotal + ' done' );
					applyNextBatch();
				}
			} )
			.fail( function () {
				$applyAllBtn.prop( 'disabled', false ).text( 'Apply to ALL ' + reviewTotal + ' items' );
				$applyBtn.prop( 'disabled', false );
				$applyProgress.text( '' );
				$reviewError.text( 'Network error after ' + totalApplied + ' items. Click again to resume.' );
			} );
		}

		applyNextBatch();
	} );

	// "Apply Categories & Publish" – batched per-item assignments.
	$applyBtn.on( 'click', function () {
		saveCurrentPageSelections();

		// Build assignments array from all saved selections.
		var assignments = [];
		var keys = Object.keys( reviewAssignments );
		for ( var i = 0; i < keys.length; i++ ) {
			assignments.push( { post_id: parseInt( keys[ i ], 10 ), term_id: parseInt( reviewAssignments[ keys[ i ] ], 10 ) } );
		}

		if ( ! assignments.length ) {
			$reviewError.text( 'No categories selected. Please select at least one category before applying.' );
			return;
		}
		$reviewError.text( '' );

		// Split into chunks of 50.
		var chunkSize = 50;
		var chunks    = [];
		for ( var c = 0; c < assignments.length; c += chunkSize ) {
			chunks.push( assignments.slice( c, c + chunkSize ) );
		}

		$applyBtn.prop( 'disabled', true );
		$applyAllBtn.prop( 'disabled', true );

		function sendChunk( idx ) {
			if ( idx >= chunks.length ) {
				// All chunks sent. Clear applied assignments and refresh.
				reviewAssignments = {};
				$applyProgress.text( '' );
				$applyBtn.prop( 'disabled', false ).text( 'Apply Categories & Publish' );
				$applyAllBtn.prop( 'disabled', false );
				reviewCurrentPage = 1;
				fetchReviewPage();
				return;
			}

			$applyProgress.text( 'Applying batch ' + ( idx + 1 ) + ' of ' + chunks.length + '\u2026' );

			$.ajax( {
				url    : asaeCi.ajaxUrl,
				method : 'POST',
				data   : {
					action      : 'asae_ci_apply_categories',
					nonce       : asaeCi.nonce,
					job_key     : currentJobKey,
					assignments : JSON.stringify( chunks[ idx ] ),
				},
			} )
			.done( function ( response ) {
				if ( ! response.success ) {
					$applyBtn.prop( 'disabled', false ).text( 'Apply Categories & Publish' );
					$applyAllBtn.prop( 'disabled', false );
					$applyProgress.text( '' );
					$reviewError.text( response.data && response.data.message ? response.data.message : 'Error applying batch ' + ( idx + 1 ) + '.' );
					return;
				}

				var data = response.data;
				if ( data.is_complete ) {
					reviewAssignments = {};
					$applyProgress.text( '' );
					$applyBtn.prop( 'disabled', false ).text( 'Apply Categories & Publish' );
					$reviewPanel.addClass( 'asae-ci-hidden' );
					onJobComplete( data );
					return;
				}

				// Continue with next chunk.
				sendChunk( idx + 1 );
			} )
			.fail( function () {
				$applyBtn.prop( 'disabled', false ).text( 'Apply Categories & Publish' );
				$applyAllBtn.prop( 'disabled', false );
				$applyProgress.text( '' );
				$reviewError.text( 'Network error on batch ' + ( idx + 1 ) + '. Please try again.' );
			} );
		}

		sendChunk( 0 );
	} );

	// ── Co-Authors Plus Notice Dismiss ────────────────────────────────────────

	$( document ).on( 'click', '#asae-ci-dismiss-cap-notice', function () {
		$.post( asaeCi.ajaxUrl, {
			action : 'asae_ci_dismiss_cap_notice',
			nonce  : asaeCi.nonce,
		} );
		$( '#asae-ci-cap-notice' ).slideUp( 300 );
	} );

	// ── Clear ASAEcenter.org Redirect Data ───────────────────────────────────

	$( document ).on( 'click', '#asae-ci-clear-redirects-btn', function () {
		if ( ! window.confirm( 'Clear ALL stored ASAEcenter.org redirect/source URL data? This cannot be undone. Make sure you have exported the JSON first if needed.' ) ) {
			return;
		}

		var $btn = $( this );
		var $msg = $( '#asae-ci-clear-redirects-msg' );

		$btn.prop( 'disabled', true ).text( 'Clearing\u2026' );
		$msg.text( '' );

		$.ajax( {
			url    : asaeCi.ajaxUrl,
			method : 'POST',
			data   : {
				action : 'asae_ci_clear_redirects',
				nonce  : asaeCi.nonce,
			},
		} )
		.done( function ( response ) {
			$btn.prop( 'disabled', false ).text( 'Clear ASAEcenter.org Redirect Data' );
			if ( response.success ) {
				$msg.text( response.data.message );
			} else {
				$msg.text( response.data && response.data.message ? response.data.message : 'An error occurred.' );
			}
		} )
		.fail( function () {
			$btn.prop( 'disabled', false ).text( 'Clear ASAEcenter.org Redirect Data' );
			$msg.text( 'Network error. Please try again.' );
		} );
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
		return 'admin.php?page=asae-content-ingestor&tab=reports&report_id=' + encodeURIComponent( reportId );
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

	// ── YouTube Feed Tab ─────────────────────────────────────────────────────

	// Only initialise YouTube handlers when the YouTube tab is active.
	if ( $( '#asae-ci-youtube-app' ).length ) {
		var $ytKeyInput   = $( '#asae-ci-yt-api-key' );
		var $ytSaveKeyBtn = $( '#asae-ci-yt-save-key-btn' );
		var $ytKeyMsg     = $( '#asae-ci-yt-key-msg' );
		var $ytChannelId  = $( '#asae-ci-yt-channel-id' );
		var $ytGenBtn     = $( '#asae-ci-yt-generate-btn' );
		var $ytGenMsg     = $( '#asae-ci-yt-gen-msg' );
		var $ytProgress   = $( '#asae-ci-yt-progress' );
		var $ytProgressTx = $( '#asae-ci-yt-progress-text' );
		var $ytResults    = $( '#asae-ci-yt-results-panel' );
		var $ytSummary    = $( '#asae-ci-yt-results-summary' );
		var $ytTableWrap  = $( '#asae-ci-yt-table-wrap' );
		var $ytPagination = $( '#asae-ci-yt-pagination' );
		var $ytUseFeedBtn = $( '#asae-ci-yt-use-feed-btn' );
		var $ytDownloadBtn = $( '#asae-ci-yt-download-feed-btn' );

		var ytVideos      = [];  // Full video list from the server.
		var ytPerPage     = 50;
		var ytCurrentPage = 1;

		// ── Save API Key ─────────────────────────────────────────────────

		$ytSaveKeyBtn.on( 'click', function () {
			var key = $.trim( $ytKeyInput.val() );
			if ( ! key ) {
				$ytKeyMsg.text( asaeCi.strings.ytKeyError ).removeClass( 'asae-ci-yt-msg-ok' ).addClass( 'asae-ci-yt-msg-err' );
				$ytKeyInput.trigger( 'focus' );
				return;
			}

			$ytSaveKeyBtn.prop( 'disabled', true ).text( 'Saving…' );
			$ytKeyMsg.text( '' );

			$.ajax( {
				url    : asaeCi.ajaxUrl,
				method : 'POST',
				data   : {
					action     : 'asae_ci_save_youtube_key',
					nonce      : asaeCi.nonce,
					yt_api_key : key,
				},
			} )
			.done( function ( response ) {
				$ytSaveKeyBtn.prop( 'disabled', false ).text( 'Save API Key' );
				if ( response.success ) {
					$ytKeyMsg.text( asaeCi.strings.ytKeySaved ).removeClass( 'asae-ci-yt-msg-err' ).addClass( 'asae-ci-yt-msg-ok' );
					$ytKeyInput.val( '' );
					// Update the displayed mask.
					var $status = $( '.asae-ci-yt-key-status' );
					if ( $status.length ) {
						$status.find( 'code' ).text( response.data.mask );
					} else {
						$( '#asae-ci-yt-key-heading' ).after(
							'<p class="asae-ci-yt-key-status">' + escHtml( 'Saved key:' ) + ' <code>' + escHtml( response.data.mask ) + '</code></p>'
						);
					}
				} else {
					$ytKeyMsg.text( response.data.message || 'Error saving key.' ).removeClass( 'asae-ci-yt-msg-ok' ).addClass( 'asae-ci-yt-msg-err' );
				}
			} )
			.fail( function () {
				$ytSaveKeyBtn.prop( 'disabled', false ).text( 'Save API Key' );
				$ytKeyMsg.text( 'Network error.' ).removeClass( 'asae-ci-yt-msg-ok' ).addClass( 'asae-ci-yt-msg-err' );
			} );
		} );

		// ── Save Channel ID ─────────────────────────────────────────────

		var $ytSaveChBtn = $( '#asae-ci-yt-save-channel-btn' );
		var $ytChMsg     = $( '#asae-ci-yt-channel-msg' );

		$ytSaveChBtn.on( 'click', function () {
			var channelId = $.trim( $ytChannelId.val() );
			if ( ! channelId ) {
				$ytChMsg.text( asaeCi.strings.ytChannelError ).removeClass( 'asae-ci-yt-msg-ok' ).addClass( 'asae-ci-yt-msg-err' );
				$ytChannelId.trigger( 'focus' );
				return;
			}

			$ytSaveChBtn.prop( 'disabled', true ).text( 'Saving\u2026' );
			$ytChMsg.text( '' );

			$.ajax( {
				url    : asaeCi.ajaxUrl,
				method : 'POST',
				data   : {
					action     : 'asae_ci_save_youtube_channel_id',
					nonce      : asaeCi.nonce,
					channel_id : channelId,
				},
			} )
			.done( function ( response ) {
				$ytSaveChBtn.prop( 'disabled', false ).text( 'Save Channel ID' );
				if ( response.success ) {
					$ytChMsg.text( response.data.message || 'Saved.' ).removeClass( 'asae-ci-yt-msg-err' ).addClass( 'asae-ci-yt-msg-ok' );
					$ytChannelId.val( '' );
					// Update the displayed mask.
					var $chStatus = $( '#asae-ci-yt-channel-status' );
					if ( $chStatus.length ) {
						$chStatus.find( 'code' ).text( response.data.mask );
					} else {
						$( '#asae-ci-yt-channel-heading' ).after(
							'<p class="asae-ci-yt-key-status" id="asae-ci-yt-channel-status">' + escHtml( 'Saved ID:' ) + ' <code>' + escHtml( response.data.mask ) + '</code></p>'
						);
					}
				} else {
					$ytChMsg.text( response.data.message || 'Error saving.' ).removeClass( 'asae-ci-yt-msg-ok' ).addClass( 'asae-ci-yt-msg-err' );
				}
			} )
			.fail( function () {
				$ytSaveChBtn.prop( 'disabled', false ).text( 'Save Channel ID' );
				$ytChMsg.text( 'Network error.' ).removeClass( 'asae-ci-yt-msg-ok' ).addClass( 'asae-ci-yt-msg-err' );
			} );
		} );

		// ── Generate Feed ────────────────────────────────────────────────

		$ytGenBtn.on( 'click', function () {
			$ytGenBtn.prop( 'disabled', true );
			$ytGenMsg.text( '' );
			$ytProgress.removeClass( 'asae-ci-hidden' );
			$ytProgressTx.text( asaeCi.strings.ytFetching );
			$ytResults.addClass( 'asae-ci-hidden' );

			$.ajax( {
				url     : asaeCi.ajaxUrl,
				method  : 'POST',
				timeout : 300000, // 5 minutes — large channels may take time.
				data    : {
					action : 'asae_ci_generate_youtube_feed',
					nonce  : asaeCi.nonce,
				},
			} )
			.done( function ( response ) {
				$ytGenBtn.prop( 'disabled', false );
				$ytProgress.addClass( 'asae-ci-hidden' );

				if ( ! response.success ) {
					$ytGenMsg.text( response.data.message || 'Error.' ).removeClass( 'asae-ci-yt-msg-ok' ).addClass( 'asae-ci-yt-msg-err' );
					return;
				}

				var data = response.data;
				$ytGenMsg.text( 'Done! Found ' + data.video_count + ' videos.' ).removeClass( 'asae-ci-yt-msg-err' ).addClass( 'asae-ci-yt-msg-ok' );

				// Store videos and render preview table.
				ytVideos      = data.videos || [];
				ytCurrentPage = 1;

				$ytSummary.text( 'Found ' + data.video_count + ' videos from ' + ( data.channel_title || 'YouTube channel' ) + '.' );
				renderYtVideoTable();

				// Set action button URLs.
				var feedUrl = data.feed_url;
				$ytUseFeedBtn.attr( 'href', 'admin.php?page=asae-content-ingestor&prefill_feed=' + encodeURIComponent( feedUrl ) );
				$ytDownloadBtn.attr( 'href', feedUrl );

				$ytResults.removeClass( 'asae-ci-hidden' );
				$( 'html, body' ).animate( { scrollTop: $ytResults.offset().top - 40 }, 400 );
			} )
			.fail( function () {
				$ytGenBtn.prop( 'disabled', false );
				$ytProgress.addClass( 'asae-ci-hidden' );
				$ytGenMsg.text( 'Network error or timeout. Please try again.' ).removeClass( 'asae-ci-yt-msg-ok' ).addClass( 'asae-ci-yt-msg-err' );
			} );
		} );

		// ── Video Preview Table (client-side pagination) ─────────────────

		function renderYtVideoTable() {
			if ( ! ytVideos.length ) {
				$ytTableWrap.html( '<p>No videos found.</p>' );
				$ytPagination.addClass( 'asae-ci-hidden' );
				return;
			}

			var totalPages = Math.ceil( ytVideos.length / ytPerPage );
			var start      = ( ytCurrentPage - 1 ) * ytPerPage;
			var pageItems  = ytVideos.slice( start, start + ytPerPage );

			var rows = pageItems.map( function ( v, idx ) {
				var num      = start + idx + 1;
				var dateText = v.published_at ? v.published_at.replace( /T.*$/, '' ) : '—';
				return '<tr>' +
					'<td>' + num + '</td>' +
					'<td>' + escHtml( v.title || '(untitled)' ) + '</td>' +
					'<td>' + escHtml( dateText ) + '</td>' +
					'<td class="asae-ci-url-cell"><a href="' + escAttr( v.url ) + '" target="_blank" rel="noopener noreferrer">' + escHtml( v.url ) + '</a></td>' +
					'</tr>';
			} );

			var html = '<table class="wp-list-table widefat striped asae-ci-yt-video-table">' +
				'<caption class="screen-reader-text">YouTube video list</caption>' +
				'<thead><tr>' +
				'<th scope="col">#</th>' +
				'<th scope="col">Title</th>' +
				'<th scope="col">Published</th>' +
				'<th scope="col">URL</th>' +
				'</tr></thead>' +
				'<tbody>' + rows.join( '' ) + '</tbody>' +
				'</table>';

			$ytTableWrap.html( html );

			// Render pagination.
			if ( totalPages > 1 ) {
				var pagBtns = '';
				for ( var p = 1; p <= totalPages; p++ ) {
					var active = ( p === ytCurrentPage ) ? ' button-primary' : '';
					var aria   = ( p === ytCurrentPage ) ? ' aria-current="page"' : '';
					pagBtns += '<button type="button" class="button asae-ci-yt-page-btn' + active + '" data-page="' + p + '"' + aria + '>' + p + '</button>';
				}
				$ytPagination.html( pagBtns ).removeClass( 'asae-ci-hidden' );
			} else {
				$ytPagination.addClass( 'asae-ci-hidden' );
			}
		}

		// Pagination button clicks.
		$( document ).on( 'click', '.asae-ci-yt-page-btn', function () {
			ytCurrentPage = parseInt( $( this ).data( 'page' ), 10 ) || 1;
			renderYtVideoTable();
			$( 'html, body' ).animate( { scrollTop: $ytTableWrap.offset().top - 40 }, 200 );
		} );
	}

	// ── WordPress REST API Feed Tab ──────────────────────────────────────────

	// Only initialise WP REST handlers when the tab is active.
	if ( $( '#asae-ci-wp-rest-app' ).length ) {

		var $wprSiteUrl      = $( '#asae-ci-wpr-site-url' );
		var $wprUsername      = $( '#asae-ci-wpr-username' );
		var $wprAppPassword  = $( '#asae-ci-wpr-app-password' );
		var $wprDiscoverBtn  = $( '#asae-ci-wpr-discover-btn' );
		var $wprDiscoverMsg  = $( '#asae-ci-wpr-discover-msg' );
		var $wprTypesPanel   = $( '#asae-ci-wpr-types-panel' );
		var $wprTypesList    = $( '#asae-ci-wpr-types-list' );
		var $wprGenerateBtn  = $( '#asae-ci-wpr-generate-btn' );
		var $wprGenerateMsg  = $( '#asae-ci-wpr-generate-msg' );
		var $wprProgress     = $( '#asae-ci-wpr-progress' );
		var $wprProgressBar  = $( '#asae-ci-wpr-progress-bar' );
		var $wprProgressText = $( '#asae-ci-wpr-progress-text' );

		var wprDiscoveredTypes = [];

		// ── Discover Content Types ───────────────────────────────────────────

		$wprDiscoverBtn.on( 'click', function () {
			var siteUrl = $wprSiteUrl.val().trim();
			if ( ! siteUrl ) {
				$wprDiscoverMsg.text( 'Please enter a site URL.' ).css( 'color', '#d63638' );
				return;
			}

			$wprDiscoverBtn.prop( 'disabled', true );
			$wprDiscoverMsg.text( 'Discovering content types…' ).css( 'color', '' );
			$wprTypesPanel.addClass( 'asae-ci-hidden' );

			$.post( asaeCi.ajaxUrl, {
				action:       'asae_ci_wp_rest_discover_types',
				nonce:        asaeCi.nonce,
				site_url:     siteUrl,
				username:     $wprUsername.val().trim(),
				app_password: $wprAppPassword.val().trim(),
			} )
			.done( function ( resp ) {
				$wprDiscoverBtn.prop( 'disabled', false );

				if ( ! resp.success ) {
					$wprDiscoverMsg.text( resp.data || 'Discovery failed.' ).css( 'color', '#d63638' );
					return;
				}

				wprDiscoveredTypes = resp.data.types || [];

				if ( wprDiscoveredTypes.length === 0 ) {
					$wprDiscoverMsg.text( 'No content types found.' ).css( 'color', '#d63638' );
					return;
				}

				var authNote = resp.data.has_auth
					? ' Authenticated — full author data available.'
					: ' No credentials — author data limited to Yoast schema.';
				$wprDiscoverMsg.text( 'Found ' + wprDiscoveredTypes.length + ' content types.' + authNote ).css( 'color', '#46b450' );

				// Render checkboxes.
				var html = '';
				for ( var i = 0; i < wprDiscoveredTypes.length; i++ ) {
					var t = wprDiscoveredTypes[ i ];
					var checked = ( t.slug === 'post' ) ? ' checked' : '';
					html += '<label class="asae-ci-wpr-type-label" style="display:block;margin:.4em 0;">';
					html += '<input type="checkbox" class="asae-ci-wpr-type-cb" data-idx="' + i + '"' + checked + ' /> ';
					html += '<strong>' + $( '<span>' ).text( t.name ).html() + '</strong>';
					html += ' <code>(' + $( '<span>' ).text( t.slug ).html() + ')</code>';
					html += ' — ' + ( t.count > 0 ? t.count.toLocaleString() + ' items' : 'count unavailable' );
					html += '</label>';
				}
				$wprTypesList.html( html );
				$wprTypesPanel.removeClass( 'asae-ci-hidden' );
				updateGenerateBtn();
			} )
			.fail( function () {
				$wprDiscoverBtn.prop( 'disabled', false );
				$wprDiscoverMsg.text( 'Request failed. Check the URL and try again.' ).css( 'color', '#d63638' );
			} );
		} );

		// Enable/disable Generate button based on checkbox selection.
		function updateGenerateBtn() {
			var anyChecked = $wprTypesList.find( '.asae-ci-wpr-type-cb:checked' ).length > 0;
			$wprGenerateBtn.prop( 'disabled', ! anyChecked );
		}

		$( document ).on( 'change', '.asae-ci-wpr-type-cb', updateGenerateBtn );

		// ── Generate Feed (chunked) ──────────────────────────────────────────

		$wprGenerateBtn.on( 'click', function () {
			var siteUrl = $wprSiteUrl.val().trim();
			if ( ! siteUrl ) {
				return;
			}

			// Collect selected types.
			var selectedTypes = [];
			$wprTypesList.find( '.asae-ci-wpr-type-cb:checked' ).each( function () {
				var idx = parseInt( $( this ).data( 'idx' ), 10 );
				if ( wprDiscoveredTypes[ idx ] ) {
					selectedTypes.push( {
						slug:      wprDiscoveredTypes[ idx ].slug,
						rest_base: wprDiscoveredTypes[ idx ].rest_base,
					} );
				}
			} );

			if ( selectedTypes.length === 0 ) {
				return;
			}

			$wprGenerateBtn.prop( 'disabled', true );
			$wprGenerateMsg.text( '' );
			$wprProgress.removeClass( 'asae-ci-hidden' );
			$wprProgressBar.css( 'width', '0%' );
			$wprProgressText.text( 'Starting feed generation…' );

			// Chunked fetch: page 1 sends post_types, subsequent pages just send page number.
			var currentPage = 1;

			function fetchNextPage() {
				var postData = {
					action:   'asae_ci_wp_rest_generate_feed',
					nonce:    asaeCi.nonce,
					site_url: siteUrl,
					page:     currentPage,
				};

				// Only send post_types on the first call.
				if ( currentPage === 1 ) {
					postData.post_types = selectedTypes;
				}

				$.post( asaeCi.ajaxUrl, postData )
				.done( function ( resp ) {
					if ( ! resp.success ) {
						$wprGenerateBtn.prop( 'disabled', false );
						$wprGenerateMsg.text( resp.data || 'Generation failed.' ).css( 'color', '#d63638' );
						$wprProgress.addClass( 'asae-ci-hidden' );
						return;
					}

					var d = resp.data;
					var pct = d.total_pages > 0 ? Math.round( ( d.page / d.total_pages ) * 100 ) : 0;
					$wprProgressBar.css( 'width', pct + '%' );
					$( '#asae-ci-wpr-progress-bar-wrap' ).attr( 'aria-valuenow', pct );
					$wprProgressText.text(
						'Fetching page ' + d.page + ' of ' + d.total_pages +
						' (' + d.total_posts.toLocaleString() + ' posts)…'
					);

					if ( d.status === 'done' ) {
						// Hide progress bar and show completion message inline.
						$wprProgress.addClass( 'asae-ci-hidden' );
						$wprGenerateMsg.text(
							'Feed generated: ' + d.total_posts.toLocaleString() + ' entries.' +
							( d.has_authors ? ' Author sidecar saved.' : '' )
						).css( 'color', '#46b450' );

						// Update the persistent "Generated Feed" status section.
						var $statusSection = $( '#asae-ci-wpr-status-section' );
						$( '#asae-ci-wpr-status-url' ).html( '<a href="' + escAttr( d.feed_url ) + '" target="_blank" rel="noopener noreferrer">' + escHtml( d.feed_url ) + '</a>' );
						$( '#asae-ci-wpr-status-count' ).html(
							d.total_posts.toLocaleString() +
							( d.has_authors ? ' <span class="description">(includes author sidecar data)</span>' : '' )
						);
						$( '#asae-ci-wpr-status-date' ).text( 'Just now' );
						$( '#asae-ci-wpr-status-actions' ).html(
							'<a href="' + escAttr( window.location.pathname + '?page=asae-content-ingestor&prefill_feed=' + encodeURIComponent( d.feed_url ) ) + '" class="button button-primary">Use in Run Tab &rarr;</a> ' +
							'<a href="' + escAttr( d.feed_url ) + '" class="button" target="_blank" rel="noopener noreferrer">Download Feed XML</a>'
						);
						$statusSection.removeClass( 'asae-ci-hidden' );

						// Scroll to the status section.
						$( 'html, body' ).animate( { scrollTop: $statusSection.offset().top - 40 }, 300 );

						$wprGenerateBtn.prop( 'disabled', false );
					} else {
						currentPage++;
						fetchNextPage();
					}
				} )
				.fail( function () {
					$wprGenerateBtn.prop( 'disabled', false );
					$wprGenerateMsg.text( 'Request failed.' ).css( 'color', '#d63638' );
					$wprProgress.addClass( 'asae-ci-hidden' );
				} );
			}

			fetchNextPage();
		} );

		// ── Clear Credentials ────────────────────────────────────────────────

		$( '#asae-ci-wpr-clear-creds-btn' ).on( 'click', function () {
			$.post( asaeCi.ajaxUrl, {
				action: 'asae_ci_wp_rest_clear_creds',
				nonce:  asaeCi.nonce,
			} ).done( function () {
				$( '#asae-ci-wpr-creds-status' ).html(
					'<span style="color:#d63638;">Credentials cleared.</span>'
				);
			} );
		} );
	}

	// ── Clean Up Tab ──────────────────────────────────────────────────────────

	if ( $( '#asae-ci-cleanup-app' ).length ) {

		// Helper to escape HTML in strings.
		function escHtmlCleanup( s ) {
			var el = document.createElement( 'span' );
			el.textContent = s || '';
			return el.innerHTML;
		}

		// ── Posts Per Page ───────────────────────────────────────────────────

		$( '#asae-ci-perpage-save-btn' ).on( 'click', function () {
			var $btn    = $( this ).prop( 'disabled', true );
			var $result = $( '#asae-ci-perpage-result' );
			var val     = parseInt( $( '#asae-ci-perpage-input' ).val(), 10 ) || 20;

			$.post( asaeCi.ajaxUrl, {
				action:   'asae_ci_set_posts_per_page',
				nonce:    asaeCi.nonce,
				per_page: val,
			} ).done( function ( resp ) {
				if ( resp.success ) {
					$result.html( '<span style="color:#00a32a;">Saved! All Posts will now show ' + resp.data.per_page + ' per page.</span>' );
				} else {
					$result.html( '<span style="color:#d63638;">' + escHtmlCleanup( resp.data ) + '</span>' );
				}
				$btn.prop( 'disabled', false );
			} ).fail( function () {
				$result.html( '<span style="color:#d63638;">Request failed.</span>' );
				$btn.prop( 'disabled', false );
			} );
		} );

		// ── Cancel All Pending Jobs ──────────────────────────────────────────

		$( '#asae-ci-cancel-all-jobs-btn' ).on( 'click', function () {
			if ( ! confirm( 'Are you sure you want to cancel ALL pending, running, and failed jobs? This cannot be undone.' ) ) {
				return;
			}

			var $btn    = $( this ).prop( 'disabled', true );
			var $result = $( '#asae-ci-cancel-all-jobs-result' );

			$.post( asaeCi.ajaxUrl, {
				action: 'asae_ci_cancel_all_jobs',
				nonce:  asaeCi.nonce,
			} ).done( function ( resp ) {
				if ( resp.success ) {
					$result.html( '<div class="notice notice-success inline"><p>' + resp.data.cancelled + ' job(s) cancelled.</p></div>' )
						.removeClass( 'asae-ci-hidden' );
				} else {
					$result.html( '<div class="notice notice-error inline"><p>' + escHtmlCleanup( resp.data ) + '</p></div>' )
						.removeClass( 'asae-ci-hidden' );
				}
				$btn.prop( 'disabled', false );
			} ).fail( function () {
				$result.html( '<div class="notice notice-error inline"><p>Request failed.</p></div>' )
					.removeClass( 'asae-ci-hidden' );
				$btn.prop( 'disabled', false );
			} );
		} );

		// ── Publish All Drafts ───────────────────────────────────────────────

		$( '#asae-ci-publish-all-btn' ).on( 'click', function () {
			if ( ! confirm( 'WARNING: This will publish ALL draft posts created by the Content Ingestor. This may be thousands of posts. Are you sure?' ) ) {
				return;
			}
			if ( ! confirm( 'Final confirmation: This action is difficult to reverse. Proceed?' ) ) {
				return;
			}

			var $btn      = $( this ).prop( 'disabled', true );
			var $progress = $( '#asae-ci-publish-progress' ).removeClass( 'asae-ci-hidden' );
			var $bar      = $( '#asae-ci-publish-bar' );
			var $status   = $( '#asae-ci-publish-status' );
			var $result   = $( '#asae-ci-publish-result' );
			var totalPublished = 0;

			function publishBatch() {
				$.post( asaeCi.ajaxUrl, {
					action: 'asae_ci_publish_all_drafts',
					nonce:  asaeCi.nonce,
				} ).done( function ( resp ) {
					if ( ! resp.success ) {
						$result.html( '<div class="notice notice-error inline"><p>' + escHtmlCleanup( resp.data ) + '</p></div>' )
							.removeClass( 'asae-ci-hidden' );
						$btn.prop( 'disabled', false );
						return;
					}

					totalPublished += resp.data.published;
					var remaining   = resp.data.remaining;
					var total       = totalPublished + remaining;
					var pct         = total > 0 ? Math.round( ( totalPublished / total ) * 100 ) : 100;

					$bar.css( 'width', pct + '%' );
					$status.text( 'Published ' + totalPublished.toLocaleString() + ' of ' + total.toLocaleString() + ' drafts…' );

					if ( remaining > 0 && resp.data.published > 0 ) {
						publishBatch();
					} else {
						$progress.addClass( 'asae-ci-hidden' );
						$result.html( '<div class="notice notice-success inline"><p>Done! ' + totalPublished.toLocaleString() + ' post(s) published.</p></div>' )
							.removeClass( 'asae-ci-hidden' );
						$btn.prop( 'disabled', false );
					}
				} ).fail( function () {
					$status.text( 'Network error after ' + totalPublished.toLocaleString() + ' items. Click the button again to resume.' );
					$btn.prop( 'disabled', false );
				} );
			}

			publishBatch();
		} );

		// ── Check Publish Dates ──────────────────────────────────────────────

		$( '#asae-ci-check-dates-btn' ).on( 'click', function () {
			var dateFrom = $( '#asae-ci-dates-from' ).val();
			var dateTo   = $( '#asae-ci-dates-to' ).val();

			if ( ! dateFrom || ! dateTo ) {
				alert( 'Please enter both a From and To date.' );
				return;
			}

			if ( ! confirm( 'This will fetch each external source URL in the date range and compare publish dates. Posts with different dates will be updated. Continue?' ) ) {
				return;
			}

			var $btn      = $( this ).prop( 'disabled', true );
			var $progress = $( '#asae-ci-dates-progress' ).removeClass( 'asae-ci-hidden' );
			var $bar      = $( '#asae-ci-dates-bar' );
			var $status   = $( '#asae-ci-dates-status' );
			var $result   = $( '#asae-ci-dates-result' );
			var $log      = $( '#asae-ci-dates-log' ).removeClass( 'asae-ci-hidden' );
			var $logBody  = $( '#asae-ci-dates-log-body' ).empty();

			var offset       = 0;
			var totalChecked = 0;
			var totalUpdated = 0;
			var totalErrors  = 0;
			var grandTotal   = 0;

			function checkBatch() {
				$.post( asaeCi.ajaxUrl, {
					action:    'asae_ci_check_publish_dates',
					nonce:     asaeCi.nonce,
					date_from: dateFrom,
					date_to:   dateTo,
					offset:    offset,
				} ).done( function ( resp ) {
					if ( ! resp.success ) {
						$result.html( '<div class="notice notice-error inline"><p>' + escHtmlCleanup( resp.data ) + '</p></div>' )
							.removeClass( 'asae-ci-hidden' );
						$btn.prop( 'disabled', false );
						return;
					}

					var d = resp.data;
					grandTotal    = d.total;
					totalChecked += d.checked;
					totalUpdated += d.updated;
					totalErrors  += d.errors;
					offset        = d.offset;

					var pct = grandTotal > 0 ? Math.round( ( totalChecked / grandTotal ) * 100 ) : 100;
					$bar.css( 'width', pct + '%' );
					$status.text( 'Checked ' + totalChecked.toLocaleString() + ' of ' + grandTotal.toLocaleString() + ' posts…' );

					// Append detail rows.
					$.each( d.details, function ( _, item ) {
						var statusLabel = '';
						if ( item.status === 'updated' ) {
							statusLabel = '<span style="color:#00a32a;font-weight:600;">Updated</span>';
						} else if ( item.status === 'match' ) {
							statusLabel = '<span style="color:#666;">Match</span>';
						} else if ( item.status === 'fetch_error' ) {
							statusLabel = '<span style="color:#d63638;">Fetch Error</span>';
						} else if ( item.status === 'no_date' ) {
							statusLabel = '<span style="color:#dba617;">No Date Found</span>';
						}

						$logBody.append(
							'<tr>' +
								'<td>' + item.post_id + '</td>' +
								'<td>' + escHtmlCleanup( item.title ) + '</td>' +
								'<td>' + statusLabel + '</td>' +
								'<td>' + escHtmlCleanup( item.old_date || '—' ) + '</td>' +
								'<td>' + escHtmlCleanup( item.new_date || '—' ) + '</td>' +
							'</tr>'
						);
					} );

					if ( ! d.done ) {
						checkBatch();
					} else {
						$progress.addClass( 'asae-ci-hidden' );
						$result.html(
							'<div class="notice notice-success inline"><p>Done! Checked ' +
							totalChecked.toLocaleString() + ' posts. ' +
							totalUpdated.toLocaleString() + ' updated, ' +
							totalErrors.toLocaleString() + ' errors.</p></div>'
						).removeClass( 'asae-ci-hidden' );
						$btn.prop( 'disabled', false );
					}
				} ).fail( function () {
					$status.text( 'Network error after ' + totalChecked.toLocaleString() + ' posts. Click again to resume from where it stopped.' );
					$btn.prop( 'disabled', false );
				} );
			}

			checkBatch();
		} );

		// ── Fix Redirects ────────────────────────────────────────────────────

		$( '#asae-ci-fix-redirects-btn' ).on( 'click', function () {
			if ( ! confirm( 'This will compare every ingested post\'s current permalink against the Redirection plugin target and update any mismatches. Continue?' ) ) {
				return;
			}

			var $btn      = $( this ).prop( 'disabled', true );
			var $progress = $( '#asae-ci-redirects-progress' ).removeClass( 'asae-ci-hidden' );
			var $bar      = $( '#asae-ci-redirects-bar' );
			var $status   = $( '#asae-ci-redirects-status' );
			var $result   = $( '#asae-ci-redirects-result' );

			var offset      = 0;
			var totalFixed   = 0;
			var totalChecked = 0;
			var grandTotal   = 0;

			function fixBatch() {
				$.post( asaeCi.ajaxUrl, {
					action: 'asae_ci_fix_redirects',
					nonce:  asaeCi.nonce,
					offset: offset,
				} ).done( function ( resp ) {
					if ( ! resp.success ) {
						$result.html( '<div class="notice notice-error inline"><p>' + escHtmlCleanup( resp.data ) + '</p></div>' )
							.removeClass( 'asae-ci-hidden' );
						$btn.prop( 'disabled', false );
						return;
					}

					var d = resp.data;
					grandTotal    = d.total;
					totalChecked += d.checked;
					totalFixed   += d.fixed;
					offset        = d.offset;

					var pct = grandTotal > 0 ? Math.round( ( totalChecked / grandTotal ) * 100 ) : 100;
					$bar.css( 'width', pct + '%' );
					$status.text( 'Checked ' + totalChecked.toLocaleString() + ' of ' + grandTotal.toLocaleString() + ' posts… (' + totalFixed.toLocaleString() + ' fixed)' );

					if ( ! d.done ) {
						fixBatch();
					} else {
						$progress.addClass( 'asae-ci-hidden' );
						$result.html(
							'<div class="notice notice-success inline"><p>Done! Checked ' +
							totalChecked.toLocaleString() + ' posts. ' +
							totalFixed.toLocaleString() + ' redirect(s) updated.</p></div>'
						).removeClass( 'asae-ci-hidden' );
						$btn.prop( 'disabled', false );
					}
				} ).fail( function () {
					$status.text( 'Network error after ' + totalChecked.toLocaleString() + ' posts. Click again to resume.' );
					$btn.prop( 'disabled', false );
				} );
			}

			fixBatch();
		} );

		// ── Assign Sponsors ──────────────────────────────────────────────────

		$( '#asae-ci-assign-sponsors-btn' ).on( 'click', function () {
			var slugs = asaeCi.sponsorSlugs || [];
			if ( ! slugs.length ) {
				alert( 'No sponsor slugs found.' );
				return;
			}
			if ( ! confirm( 'This will fetch ' + slugs.length + ' sponsor listing pages from associationsnow.com and assign sponsor terms to matching posts. Continue?' ) ) {
				return;
			}

			var $btn      = $( this ).prop( 'disabled', true );
			var $progress = $( '#asae-ci-sponsors-progress' ).removeClass( 'asae-ci-hidden' );
			var $bar      = $( '#asae-ci-sponsors-bar' );
			var $status   = $( '#asae-ci-sponsors-status' );
			var $result   = $( '#asae-ci-sponsors-result' );
			$( '#asae-ci-sponsors-log' ).removeClass( 'asae-ci-hidden' );
			var $logBody  = $( '#asae-ci-sponsors-log-body' ).empty();

			var index          = 0;
			var totalMatched   = 0;
			var totalAssigned  = 0;
			var totalErrors    = 0;

			function processSponsor() {
				if ( index >= slugs.length ) {
					$progress.addClass( 'asae-ci-hidden' );
					$bar.css( 'width', '100%' );
					$result.html(
						'<div class="notice notice-success inline"><p>Done! Processed ' +
						slugs.length + ' sponsors. ' +
						totalMatched.toLocaleString() + ' posts matched, ' +
						totalAssigned.toLocaleString() + ' assigned, ' +
						totalErrors + ' errors.</p></div>'
					).removeClass( 'asae-ci-hidden' );
					$btn.prop( 'disabled', false );
					return;
				}

				var slug = slugs[ index ];
				var pct  = Math.round( ( index / slugs.length ) * 100 );
				$bar.css( 'width', pct + '%' );
				$status.text( 'Processing ' + ( index + 1 ) + ' of ' + slugs.length + ': ' + slug + '…' );

				$.post( asaeCi.ajaxUrl, {
					action: 'asae_ci_assign_sponsors',
					nonce:  asaeCi.nonce,
					slug:   slug,
				} ).done( function ( resp ) {
					index++;

					if ( ! resp.success ) {
						totalErrors++;
						$logBody.append(
							'<tr>' +
								'<td>' + index + '</td>' +
								'<td>' + escHtmlCleanup( slug ) + '</td>' +
								'<td>—</td><td>—</td><td>—</td><td>—</td>' +
								'<td><span style="color:#d63638;">Error</span></td>' +
							'</tr>'
						);
						processSponsor();
						return;
					}

					var d = resp.data;
					totalMatched  += d.posts_matched;
					totalAssigned += d.posts_assigned;

					var statusLabel = '';
					if ( d.status === 'skipped' ) {
						statusLabel = '<span style="color:#666;">Skipped</span>';
					} else if ( d.status === 'fetch_error' ) {
						statusLabel = '<span style="color:#d63638;">Fetch Error</span>';
						totalErrors++;
					} else {
						statusLabel = '<span style="color:#00a32a;">OK</span>';
					}

					$logBody.append(
						'<tr>' +
							'<td>' + index + '</td>' +
							'<td>' + escHtmlCleanup( d.name ) + '</td>' +
							'<td>' + ( d.logo_attached ? '&#10003;' : '—' ) + '</td>' +
							'<td>' + d.articles_found + '</td>' +
							'<td>' + d.posts_matched + '</td>' +
							'<td>' + d.posts_assigned + '</td>' +
							'<td>' + statusLabel + '</td>' +
						'</tr>'
					);

					processSponsor();
				} ).fail( function () {
					totalErrors++;
					index++;
					$logBody.append(
						'<tr>' +
							'<td>' + index + '</td>' +
							'<td>' + escHtmlCleanup( slug ) + '</td>' +
							'<td>—</td><td>—</td><td>—</td><td>—</td>' +
							'<td><span style="color:#d63638;">Network Error</span></td>' +
						'</tr>'
					);
					processSponsor();
				} );
			}

			processSponsor();
		} );
	}

} )( jQuery );
