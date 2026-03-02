<?php
/**
 * ASAE Content Ingestor – RSS Feed Crawler
 *
 * Discovers all content URLs by reading an RSS or Atom feed using WordPress's
 * built-in fetch_feed() function (which wraps SimplePie). No external libraries
 * are required.
 *
 * An optional URL restriction prefix can be provided so that only links whose
 * full address begins with that prefix are collected, limiting ingestion to a
 * specific section of the source site (e.g. only /articles/ URLs).
 *
 * @package ASAE_Content_Ingestor
 * @since   0.0.1 (BFS web crawler)
 * @since   0.1.0 (Rewritten to RSS feed-based discovery)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CI_Crawler {

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Fetches an RSS or Atom feed and returns all article URLs it contains.
	 *
	 * When $url_restriction is non-empty, only links whose full address begins
	 * with that string are returned (case-insensitive prefix match). Pass an
	 * empty string to accept every link found in the feed.
	 *
	 * Uses WordPress's built-in fetch_feed() (SimplePie) so no external
	 * dependency is introduced.
	 *
	 * @param string $feed_url        The URL of the RSS or Atom feed.
	 * @param string $url_restriction Optional URL prefix; only links starting
	 *                                with this string are included.
	 * @param int    $limit           Maximum URLs to return (0 = all).
	 * @return string[]|WP_Error      Array of absolute article URLs, or WP_Error on failure.
	 */
	public static function fetch_feed_urls( string $feed_url, string $url_restriction = '', int $limit = 0 ) {
		// Bypass the transient cache so every run fetches fresh feed data.
		add_filter( 'wp_feed_cache_transient_lifetime', '__return_zero' );

		$feed = fetch_feed( $feed_url );

		remove_filter( 'wp_feed_cache_transient_lifetime', '__return_zero' );

		if ( is_wp_error( $feed ) ) {
			return $feed;
		}

		$item_count = $feed->get_item_quantity();
		if ( 0 === $item_count ) {
			return [];
		}

		$urls  = [];
		$items = $feed->get_items( 0, $item_count );

		foreach ( $items as $item ) {
			$permalink = $item->get_permalink();
			if ( empty( $permalink ) ) {
				continue;
			}

			// Apply the optional URL prefix restriction (case-insensitive).
			if ( ! empty( $url_restriction ) ) {
				if ( 0 !== strncasecmp( $permalink, $url_restriction, strlen( $url_restriction ) ) ) {
					continue;
				}
			}

			$urls[] = $permalink;

			// Stop collecting once the requested limit is reached.
			if ( $limit > 0 && count( $urls ) >= $limit ) {
				break;
			}
		}

		return array_unique( $urls );
	}

	/**
	 * Builds the initial discovery queue state for a new RSS-based job.
	 *
	 * Queue state array shape:
	 * {
	 *   feed_url        : string    – The RSS/Atom feed URL.
	 *   url_restriction : string    – Optional URL prefix filter ('' = none).
	 *   feed_fetched    : bool      – True once the feed has been read.
	 *   content_urls    : string[]  – Article URLs collected from the feed.
	 *   limit           : int       – Max URLs to collect (0 = unlimited).
	 * }
	 *
	 * @param string $feed_url         The RSS feed URL.
	 * @param int    $limit            Maximum content URLs to collect (0 = all).
	 * @param string $url_restriction  Optional URL prefix restriction.
	 * @return array                   Initial queue state.
	 */
	public static function build_initial_queue( string $feed_url, int $limit = 0, string $url_restriction = '' ): array {
		return [
			'feed_url'        => $feed_url,
			'url_restriction' => $url_restriction,
			'feed_fetched'    => false,
			'content_urls'    => [],
			'limit'           => $limit,
		];
	}

	/**
	 * Returns true when the discovery phase is complete.
	 * For RSS discovery, this is true as soon as the feed has been fetched
	 * (the entire discovery operation completes in a single request).
	 *
	 * @param array $queue_state Current queue state.
	 * @return bool
	 */
	public static function is_discovery_complete( array $queue_state ): bool {
		return (bool) ( $queue_state['feed_fetched'] ?? false );
	}
}
