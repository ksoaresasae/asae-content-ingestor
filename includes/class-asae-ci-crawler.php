<?php
/**
 * ASAE Content Ingestor – Web Crawler
 *
 * Discovers all content URLs within a specified remote folder using a
 * breadth-first search (BFS) strategy. The crawler is strictly constrained
 * to the given folder prefix and will never follow links outside it.
 *
 * Remote HTTP requests are made exclusively via wp_remote_get() to leverage
 * WordPress's built-in HTTP API (with timeout handling, user-agent, etc.).
 *
 * HTML parsing uses PHP's built-in DOMDocument / DOMXPath classes, which are
 * standard in all PHP 8+ environments including shared WordPress hosting.
 *
 * @package ASAE_Content_Ingestor
 * @since   0.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CI_Crawler {

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Performs one BFS batch of URL discovery, starting from the provided
	 * queue state. Returns an updated queue state that can be persisted and
	 * resumed on the next cron invocation.
	 *
	 * Queue state array shape:
	 * {
	 *   to_crawl     : string[]  – URLs waiting to be fetched
	 *   crawled      : string[]  – URLs already fetched (visited set)
	 *   content_urls : string[]  – URLs identified as likely article pages
	 *   folder_prefix: string    – The normalised folder URL (canonical form)
	 *   depth_map    : array     – URL => depth, to enforce max crawl depth
	 *   limit        : int       – Max content URLs to collect (0 = unlimited)
	 * }
	 *
	 * @param array $queue_state Current discovery queue state.
	 * @param int   $batch_size  How many URLs to crawl in this call.
	 * @return array Updated queue state.
	 */
	public static function crawl_batch( array $queue_state, int $batch_size = ASAE_CI_CRAWL_BATCH_SIZE ): array {
		$to_crawl      = $queue_state['to_crawl']      ?? [];
		$crawled       = $queue_state['crawled']       ?? [];
		$content_urls  = $queue_state['content_urls']  ?? [];
		$folder_prefix = $queue_state['folder_prefix'] ?? '';
		$depth_map     = $queue_state['depth_map']     ?? [];
		$limit         = (int) ( $queue_state['limit'] ?? 0 );

		// Nothing to do if the limit is already reached.
		if ( $limit > 0 && count( $content_urls ) >= $limit ) {
			return array_merge( $queue_state, [ 'to_crawl' => [] ] );
		}

		$processed = 0;

		while ( ! empty( $to_crawl ) && $processed < $batch_size ) {
			// Stop early if content limit reached.
			if ( $limit > 0 && count( $content_urls ) >= $limit ) {
				break;
			}

			// Pick the next URL from the front of the queue.
			$current_url = array_shift( $to_crawl );

			// Skip if already visited.
			if ( in_array( $current_url, $crawled, true ) ) {
				continue;
			}

			$depth = $depth_map[ $current_url ] ?? 0;

			// Skip if beyond the maximum crawl depth.
			if ( $depth > ASAE_CI_MAX_CRAWL_DEPTH ) {
				$crawled[] = $current_url;
				continue;
			}

			// Mark as visited before fetching (prevents duplicate queuing).
			$crawled[] = $current_url;
			$processed++;

			// Fetch the remote page.
			$response = self::fetch_url( $current_url );
			if ( is_wp_error( $response ) || empty( $response['body'] ) ) {
				continue;
			}

			$html        = $response['body'];
			$content_type = $response['content_type'] ?? '';

			// Only process HTML responses.
			if ( ! empty( $content_type ) && false === strpos( $content_type, 'text/html' ) ) {
				continue;
			}

			// Extract all links from the page that fall within the folder.
			$found_links = self::extract_links( $html, $current_url, $folder_prefix );

			foreach ( $found_links as $link ) {
				// Add to discovery queue if not yet visited.
				if ( ! in_array( $link, $crawled, true ) && ! in_array( $link, $to_crawl, true ) ) {
					$to_crawl[]          = $link;
					$depth_map[ $link ]  = $depth + 1;
				}
			}

			// Determine whether the current page is a content (article) page
			// or a listing/navigation page.
			if ( self::is_content_page( $current_url, $html ) ) {
				// Avoid duplicate content URLs.
				if ( ! in_array( $current_url, $content_urls, true ) ) {
					$content_urls[] = $current_url;
				}
			}
		}

		return [
			'to_crawl'      => $to_crawl,
			'crawled'       => $crawled,
			'content_urls'  => $content_urls,
			'folder_prefix' => $folder_prefix,
			'depth_map'     => $depth_map,
			'limit'         => $limit,
		];
	}

	/**
	 * Builds the initial queue state for a new crawl job.
	 *
	 * @param string $start_url    The URL to begin crawling from.
	 * @param int    $limit        Maximum content URLs to collect (0 = all).
	 * @return array               Initial queue state.
	 */
	public static function build_initial_queue( string $start_url, int $limit = 0 ): array {
		$folder_prefix = self::normalise_folder_prefix( $start_url );

		return [
			'to_crawl'      => [ $start_url ],
			'crawled'       => [],
			'content_urls'  => [],
			'folder_prefix' => $folder_prefix,
			'depth_map'     => [ $start_url => 0 ],
			'limit'         => $limit,
		];
	}

	/**
	 * Returns true when the discovery phase is complete (no URLs left to crawl,
	 * or the requested content limit has been met).
	 *
	 * @param array $queue_state Current queue state.
	 * @return bool
	 */
	public static function is_discovery_complete( array $queue_state ): bool {
		$to_crawl     = $queue_state['to_crawl']     ?? [];
		$content_urls = $queue_state['content_urls'] ?? [];
		$limit        = (int) ( $queue_state['limit'] ?? 0 );

		if ( empty( $to_crawl ) ) {
			return true;
		}

		if ( $limit > 0 && count( $content_urls ) >= $limit ) {
			return true;
		}

		return false;
	}

	// ── Private Helpers ───────────────────────────────────────────────────────

	/**
	 * Fetches a remote URL using WordPress's built-in HTTP API.
	 *
	 * Returns an associative array with:
	 *  'body'         – The response body text
	 *  'content_type' – Value of the Content-Type header
	 *
	 * Returns WP_Error on failure.
	 *
	 * @param string $url The URL to fetch.
	 * @return array|WP_Error
	 */
	private static function fetch_url( string $url ) {
		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 30,
				'user-agent' => 'ASAE Content Ingestor/' . ASAE_CI_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
				'sslverify'  => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'asae_ci_http_error',
				sprintf( 'HTTP %d returned for %s', $code, $url )
			);
		}

		return [
			'body'         => wp_remote_retrieve_body( $response ),
			'content_type' => wp_remote_retrieve_header( $response, 'content-type' ),
		];
	}

	/**
	 * Parses all <a href> links from an HTML string and returns only those
	 * that fall within the specified folder prefix.
	 *
	 * Uses PHP's built-in DOMDocument for reliable HTML parsing without
	 * any external library dependency.
	 *
	 * @param string $html          HTML source of the page.
	 * @param string $current_url   The URL of the page being parsed (used for
	 *                              resolving relative links).
	 * @param string $folder_prefix The normalised folder prefix to filter against.
	 * @return string[]             Absolute URLs within the folder.
	 */
	private static function extract_links( string $html, string $current_url, string $folder_prefix ): array {
		if ( empty( $html ) || empty( $folder_prefix ) ) {
			return [];
		}

		// Suppress DOMDocument warnings on malformed HTML.
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$links = [];
		$anchors = $dom->getElementsByTagName( 'a' );

		foreach ( $anchors as $anchor ) {
			$href = trim( $anchor->getAttribute( 'href' ) );
			if ( empty( $href ) ) {
				continue;
			}

			// Resolve to an absolute URL.
			$absolute = self::resolve_url( $href, $current_url );
			if ( empty( $absolute ) ) {
				continue;
			}

			// Remove fragment identifiers (#section).
			$absolute = strtok( $absolute, '#' );

			// Remove query strings to avoid crawling duplicate content.
			// (Pagination that uses query strings is a known limitation in v0.0.1.)
			$absolute = strtok( $absolute, '?' );

			// Must be within the folder.
			if ( ! self::is_within_folder( $absolute, $folder_prefix ) ) {
				continue;
			}

			// Exclude common non-content file extensions.
			if ( self::is_asset_url( $absolute ) ) {
				continue;
			}

			$links[] = $absolute;
		}

		return array_unique( $links );
	}

	/**
	 * Heuristically determines whether a page is an article/content page
	 * (as opposed to a listing, category archive, or navigation page).
	 *
	 * The approach is intentionally conservative: when uncertain, the page
	 * is treated as a content page so nothing is silently dropped.
	 *
	 * @param string $url  URL of the page.
	 * @param string $html HTML source of the page.
	 * @return bool
	 */
	private static function is_content_page( string $url, string $html ): bool {
		// A page with very little HTML is unlikely to be an article.
		if ( strlen( $html ) < 500 ) {
			return false;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );

		// Presence of <article> or role="article" is a strong signal.
		$articles = $xpath->query( '//*[self::article or @role="article"]' );
		if ( $articles && $articles->length > 0 ) {
			return true;
		}

		// Check for common article meta tags (Open Graph / Dublin Core).
		$og_type = $xpath->query( '//meta[@property="og:type"]/@content' );
		if ( $og_type && $og_type->length > 0 ) {
			$type_val = strtolower( trim( $og_type->item(0)->nodeValue ) );
			// "article" is the OG type for a news/blog post.
			if ( in_array( $type_val, [ 'article', 'news', 'blog' ], true ) ) {
				return true;
			}
			// "website" typically means a listing or home page.
			if ( 'website' === $type_val ) {
				return false;
			}
		}

		// Check for <time> element with a datetime attribute – common in articles.
		$time_elements = $xpath->query( '//time[@datetime]' );
		if ( $time_elements && $time_elements->length > 0 ) {
			return true;
		}

		// Check for <main> with substantial text content.
		$main_nodes = $xpath->query( '//main | //*[@role="main"]' );
		if ( $main_nodes && $main_nodes->length > 0 ) {
			$main_text = trim( $main_nodes->item(0)->textContent );
			if ( strlen( $main_text ) > 300 ) {
				return true;
			}
		}

		// If nothing heuristic matched, include the page by default to be safe.
		return true;
	}

	/**
	 * Normalises a starting URL into a canonical folder prefix.
	 *
	 * Examples:
	 *  https://example.com/folder   → https://example.com/folder/
	 *  https://example.com/folder/  → https://example.com/folder/
	 *
	 * @param string $url The starting URL.
	 * @return string The normalised folder prefix (always ends with /).
	 */
	public static function normalise_folder_prefix( string $url ): string {
		$url = rtrim( trim( $url ), '/' ) . '/';

		// Strip fragment and query string from the prefix itself.
		$url = strtok( $url, '#' );
		$url = strtok( $url, '?' );

		return $url;
	}

	/**
	 * Checks whether a URL lives within the specified folder prefix.
	 *
	 * @param string $url           The URL to check.
	 * @param string $folder_prefix The normalised folder prefix.
	 * @return bool
	 */
	private static function is_within_folder( string $url, string $folder_prefix ): bool {
		// Case-insensitive comparison to handle servers that differ in casing.
		return 0 === strncasecmp( $url, $folder_prefix, strlen( $folder_prefix ) );
	}

	/**
	 * Returns true if the URL points to a static asset (image, PDF, etc.)
	 * that should not be treated as a crawlable HTML page.
	 *
	 * @param string $url The URL to check.
	 * @return bool
	 */
	private static function is_asset_url( string $url ): bool {
		$path = parse_url( $url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return false;
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$asset_extensions = [
			'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp',
			'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip',
			'tar', 'gz', 'mp3', 'mp4', 'mov', 'avi', 'wmv', 'flv',
			'css', 'js', 'xml', 'json', 'woff', 'woff2', 'ttf', 'eot',
		];
		return in_array( $ext, $asset_extensions, true );
	}

	/**
	 * Resolves a (potentially relative) href value to an absolute URL
	 * using the page's current URL as the base.
	 *
	 * Handles:
	 *  - Already-absolute URLs (https://…)
	 *  - Protocol-relative URLs (//example.com/…)
	 *  - Root-relative URLs (/path/to/page)
	 *  - Relative paths (./page, ../page, page)
	 *
	 * @param string $href        The href attribute value.
	 * @param string $current_url The URL of the page containing the link.
	 * @return string             Absolute URL, or empty string on failure.
	 */
	private static function resolve_url( string $href, string $current_url ): string {
		// Skip non-HTTP protocols (mailto:, tel:, javascript:, #, etc.).
		if ( preg_match( '/^(mailto:|tel:|javascript:|data:|#)/i', $href ) ) {
			return '';
		}

		// Already absolute.
		if ( preg_match( '/^https?:\/\//i', $href ) ) {
			return $href;
		}

		$base = parse_url( $current_url );
		if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return '';
		}

		$base_scheme = $base['scheme'];
		$base_host   = $base['host'];
		$base_port   = ! empty( $base['port'] ) ? ':' . $base['port'] : '';
		$base_origin = $base_scheme . '://' . $base_host . $base_port;

		// Protocol-relative (//example.com/path).
		if ( str_starts_with( $href, '//' ) ) {
			return $base_scheme . ':' . $href;
		}

		// Root-relative (/path/to/page).
		if ( str_starts_with( $href, '/' ) ) {
			return $base_origin . $href;
		}

		// Relative path – resolve against the current URL's directory.
		$base_path = isset( $base['path'] ) ? dirname( $base['path'] ) : '/';
		$combined  = rtrim( $base_path, '/' ) . '/' . $href;

		// Resolve ../ and ./ segments.
		$parts     = explode( '/', $combined );
		$resolved  = [];
		foreach ( $parts as $part ) {
			if ( '.' === $part || '' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $resolved );
			} else {
				$resolved[] = $part;
			}
		}

		return $base_origin . '/' . implode( '/', $resolved );
	}
}
