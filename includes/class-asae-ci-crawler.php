<?php
/**
 * ASAE Content Ingestor – RSS Feed Crawler
 *
 * Discovers article URLs by reading an RSS or Atom feed.
 *
 * Two parsing strategies are used in sequence:
 *
 *  1. WordPress fetch_feed() / SimplePie – handles the majority of well-formed
 *     RSS 2.0 and Atom feeds. Per-item permalink extraction uses three fallback
 *     strategies so that feeds which trip up SimplePie's primary permalink
 *     logic (e.g. feeds with an empty <id/> element that shadows <link>) are
 *     still handled correctly.
 *
 *  2. Direct XML parsing (wp_remote_get + SimpleXMLElement) – activated when
 *     SimplePie yields no usable URLs. Parses the raw feed XML itself and
 *     supports RSS 2.0 and Atom, including feeds where SimplePie cannot
 *     determine the correct permalink element.
 *
 * No external libraries are required; all dependencies are part of WordPress
 * core (fetch_feed / SimplePie, wp_remote_get, SimpleXMLElement).
 *
 * The RSS feed is treated primarily as a directory of links. Article body
 * content is NOT read from the feed – each discovered link is later fetched
 * individually during the ingestion phase so that the full HTML page is parsed.
 *
 * Feed-level metadata (author, date, categories) can optionally be extracted
 * via fetch_feed_metadata() and used as fallback values when the HTML parser
 * cannot find them on the target page (e.g. YouTube video watch pages).
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
	 * Fetches an RSS or Atom feed and returns article URLs discovered in it.
	 *
	 * Uses WordPress's built-in fetch_feed() (SimplePie) as the primary parser,
	 * with a direct XML fallback for non-standard or partially malformed feeds
	 * such as those that include an empty <id/> element alongside a <link>
	 * element (a pattern seen in some legacy CMS outputs).
	 *
	 * When $url_restriction is non-empty, only links whose URL begins with that
	 * string are returned (case-insensitive prefix match). Pass '' to accept all.
	 *
	 * @param string $feed_url        The URL of the RSS or Atom feed.
	 * @param string $url_restriction Optional URL prefix; only matching links included.
	 * @param int    $limit           Maximum URLs to return (0 = all).
	 * @return string[]|WP_Error      Array of absolute article URLs, or WP_Error.
	 */
	public static function fetch_feed_urls( string $feed_url, string $url_restriction = '', int $limit = 0 ) {
		// ── Strategy 1: SimplePie via fetch_feed() ────────────────────────────

		// Bypass the transient cache so every run fetches fresh feed data.
		add_filter( 'wp_feed_cache_transient_lifetime', '__return_zero' );
		$feed = fetch_feed( $feed_url );
		remove_filter( 'wp_feed_cache_transient_lifetime', '__return_zero' );

		if ( ! is_wp_error( $feed ) && $feed->get_item_quantity() > 0 ) {
			$urls = self::extract_from_simplepie( $feed, $url_restriction, $limit );

			// Only use SimplePie's results if it found at least one usable URL.
			if ( ! empty( $urls ) ) {
				return $urls;
			}
		}

		// ── Strategy 2: Direct XML parsing fallback ───────────────────────────
		// Reached when SimplePie returns 0 items, or when all items produced
		// empty permalinks (e.g. due to an empty <id/> shadowing <link>).
		return self::extract_from_xml( $feed_url, $url_restriction, $limit );
	}

	/**
	 * Extracts per-item metadata (author, date, categories) from an RSS/Atom feed.
	 *
	 * Returns an associative array keyed by article URL, with each value containing
	 * author, date, and tags extracted from the feed entry. This metadata can be
	 * used as fallback when the HTML parser cannot extract these fields from the
	 * target page (e.g. YouTube video watch pages).
	 *
	 * Uses the same two-strategy approach as fetch_feed_urls():
	 *  1. SimplePie via fetch_feed()
	 *  2. Direct XML parsing fallback
	 *
	 * @param string $feed_url        The URL of the RSS or Atom feed.
	 * @param string $url_restriction Optional URL prefix filter.
	 * @return array<string, array{author: string, date: string, tags: string[], description: string}> URL → metadata map.
	 */
	public static function fetch_feed_metadata( string $feed_url, string $url_restriction = '' ): array {
		// ── Strategy 1: SimplePie ─────────────────────────────────────────────
		add_filter( 'wp_feed_cache_transient_lifetime', '__return_zero' );
		$feed = fetch_feed( $feed_url );
		remove_filter( 'wp_feed_cache_transient_lifetime', '__return_zero' );

		if ( ! is_wp_error( $feed ) && $feed->get_item_quantity() > 0 ) {
			$meta = self::metadata_from_simplepie( $feed, $url_restriction );
			if ( ! empty( $meta ) ) {
				return $meta;
			}
		}

		// ── Strategy 2: Direct XML ────────────────────────────────────────────
		return self::metadata_from_xml( $feed_url, $url_restriction );
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
	 * For RSS discovery, this is true as soon as the feed has been fetched.
	 *
	 * @param array $queue_state Current queue state.
	 * @return bool
	 */
	public static function is_discovery_complete( array $queue_state ): bool {
		return (bool) ( $queue_state['feed_fetched'] ?? false );
	}

	// ── Private: SimplePie Extraction ─────────────────────────────────────────

	/**
	 * Extracts article URLs from a successfully parsed SimplePie feed object.
	 *
	 * Uses three strategies in order for each item:
	 *  1. get_permalink() – standard SimplePie method.
	 *  2. get_link(0)     – alternative accessor (may differ for some feed types).
	 *  3. Raw <link> tag  – reads the tag data directly, bypassing SimplePie's
	 *                       permalink resolution logic entirely. This handles
	 *                       feeds with an empty <id/> that confuses SimplePie
	 *                       into returning null from get_permalink().
	 *
	 * @param SimplePie $feed            Parsed SimplePie feed object.
	 * @param string    $url_restriction Optional URL prefix filter.
	 * @param int       $limit           Maximum URLs to collect (0 = all).
	 * @return string[]
	 */
	private static function extract_from_simplepie( $feed, string $url_restriction, int $limit ): array {
		$items = $feed->get_items( 0, $feed->get_item_quantity() );
		$urls  = [];

		foreach ( $items as $item ) {
			$permalink = null;

			// Strategy 1: standard get_permalink().
			$permalink = $item->get_permalink();

			// Strategy 2: get_link() if get_permalink() returned nothing.
			if ( empty( $permalink ) ) {
				$permalink = $item->get_link( 0 );
			}

			// Strategy 3: read the raw <link> tag bytes directly.
			// This bypasses SimplePie's permalink resolution and is reliable
			// even when an empty <id/> element is present in the same item.
			if ( empty( $permalink ) ) {
				// RSS 2.0 <link> lives in the empty/default namespace.
				$tags = $item->get_item_tags( '', 'link' );
				if ( ! empty( $tags[0]['data'] ) ) {
					$permalink = self::normalise_url( $tags[0]['data'] );
				}
			}

			if ( empty( $permalink ) ) {
				continue;
			}

			// Strip any embedded whitespace (newlines, tabs) from the URL.
			$permalink = self::normalise_url( $permalink );

			if ( ! self::passes_restriction( $permalink, $url_restriction ) ) {
				continue;
			}

			$urls[] = $permalink;

			if ( $limit > 0 && count( $urls ) >= $limit ) {
				break;
			}
		}

		return array_unique( $urls );
	}

	// ── Private: Direct XML Extraction ────────────────────────────────────────

	/**
	 * Fetches the feed URL directly with wp_remote_get() and parses it as XML.
	 *
	 * Supports:
	 *  - RSS 2.0  : reads <channel><item><link> text content.
	 *  - Atom     : reads <entry><link rel="alternate" href="..."/> attributes.
	 *
	 * This fallback handles feeds where SimplePie's HTTP client or permalink
	 * resolution fails, including feeds served with unexpected content types
	 * or with non-standard element combinations.
	 *
	 * @param string $feed_url        The feed URL.
	 * @param string $url_restriction Optional URL prefix filter.
	 * @param int    $limit           Maximum URLs to collect (0 = all).
	 * @return string[]|WP_Error
	 */
	private static function extract_from_xml( string $feed_url, string $url_restriction, int $limit ) {
		$response = wp_remote_get(
			$feed_url,
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
				sprintf( 'HTTP %d returned for feed URL: %s', $code, $feed_url )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return new WP_Error( 'asae_ci_empty_response', 'The feed URL returned an empty response.' );
		}

		// Parse the raw XML – suppress warnings for partially malformed feeds.
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		libxml_clear_errors();

		if ( false === $xml ) {
			return new WP_Error( 'asae_ci_xml_error', 'The feed URL did not return a valid XML document.' );
		}

		$root = $xml->getName();
		$urls = [];

		if ( 'rss' === $root ) {
			// ── RSS 2.0 ───────────────────────────────────────────────────────
			// Article URLs are in <channel><item><link> text nodes.
			foreach ( $xml->channel->item ?? [] as $item ) {
				$link = self::normalise_url( (string) $item->link );
				if ( ! empty( $link ) && self::passes_restriction( $link, $url_restriction ) ) {
					$urls[] = $link;
				}
				if ( $limit > 0 && count( $urls ) >= $limit ) {
					break;
				}
			}
		} elseif ( 'feed' === $root ) {
			// ── Atom ──────────────────────────────────────────────────────────
			// Article URLs are in <entry><link rel="alternate" href="..."/>.
			foreach ( $xml->entry ?? [] as $entry ) {
				$link = self::extract_atom_link( $entry );
				if ( ! empty( $link ) && self::passes_restriction( $link, $url_restriction ) ) {
					$urls[] = $link;
				}
				if ( $limit > 0 && count( $urls ) >= $limit ) {
					break;
				}
			}
		}

		return array_unique( $urls );
	}

	// ── Private: Helpers ──────────────────────────────────────────────────────

	/**
	 * Extracts the primary link URL from an Atom <entry> element.
	 *
	 * Prefers <link rel="alternate">, then <link> with no rel attribute,
	 * then <content src="..."> as a last resort.
	 *
	 * @param SimpleXMLElement $entry The Atom <entry> element.
	 * @return string Absolute URL, or empty string if none found.
	 */
	private static function extract_atom_link( SimpleXMLElement $entry ): string {
		foreach ( $entry->link as $link_el ) {
			$rel  = (string) ( $link_el['rel']  ?? '' );
			$href = (string) ( $link_el['href'] ?? '' );
			if ( ( '' === $rel || 'alternate' === $rel ) && ! empty( $href ) ) {
				return self::normalise_url( $href );
			}
		}

		// Some Atom feeds reference the canonical URL via <content src="...">.
		if ( isset( $entry->content['src'] ) ) {
			return self::normalise_url( (string) $entry->content['src'] );
		}

		return '';
	}

	/**
	 * Strips all whitespace (spaces, tabs, newlines) from a URL string.
	 *
	 * Some feeds embed line breaks inside <link> element values, which
	 * produces invalid URLs if not normalised.
	 *
	 * @param string $raw Raw URL-like string.
	 * @return string Cleaned URL.
	 */
	private static function normalise_url( string $raw ): string {
		return preg_replace( '/\s+/', '', trim( $raw ) );
	}

	/**
	 * Returns true if $url passes the optional URL restriction prefix check.
	 *
	 * @param string $url         The URL to test.
	 * @param string $restriction Prefix to require ('' = accept all).
	 * @return bool
	 */
	private static function passes_restriction( string $url, string $restriction ): bool {
		if ( empty( $restriction ) ) {
			return true;
		}
		return 0 === strncasecmp( $url, $restriction, strlen( $restriction ) );
	}

	// ── Private: Feed Metadata Extraction ────────────────────────────────────

	/**
	 * Extracts per-item metadata from a SimplePie feed object.
	 *
	 * @param SimplePie $feed            Parsed SimplePie feed.
	 * @param string    $url_restriction Optional URL prefix filter.
	 * @return array<string, array{author: string, date: string, tags: string[], description: string}>
	 */
	private static function metadata_from_simplepie( $feed, string $url_restriction ): array {
		$items = $feed->get_items( 0, $feed->get_item_quantity() );
		$meta  = [];

		foreach ( $items as $item ) {
			$permalink = $item->get_permalink() ?: $item->get_link( 0 );
			if ( empty( $permalink ) ) {
				$tags = $item->get_item_tags( '', 'link' );
				if ( ! empty( $tags[0]['data'] ) ) {
					$permalink = self::normalise_url( $tags[0]['data'] );
				}
			}
			if ( empty( $permalink ) ) {
				continue;
			}
			$permalink = self::normalise_url( $permalink );

			if ( ! self::passes_restriction( $permalink, $url_restriction ) ) {
				continue;
			}

			// Author: SimplePie get_author().
			$author_name = '';
			$author_obj  = $item->get_author();
			if ( $author_obj ) {
				$author_name = $author_obj->get_name() ?: '';
			}

			// Date: SimplePie get_date() normalised to Y-m-d H:i:s.
			$date     = '';
			$date_raw = $item->get_date( 'U' );
			if ( $date_raw ) {
				$date = gmdate( 'Y-m-d H:i:s', (int) $date_raw );
			}

			// Tags/categories: SimplePie get_categories().
			$tags_arr   = [];
			$categories = $item->get_categories();
			if ( $categories ) {
				foreach ( $categories as $cat ) {
					$label = $cat->get_label() ?: $cat->get_term();
					if ( $label ) {
						$tags_arr[] = trim( $label );
					}
				}
			}

			// Description: SimplePie get_description().
			$description = trim( $item->get_description() ?: '' );

			$meta[ $permalink ] = [
				'author'      => $author_name,
				'date'        => $date,
				'tags'        => array_unique( array_filter( $tags_arr ) ),
				'description' => $description,
			];
		}

		return $meta;
	}

	/**
	 * Extracts per-item metadata from raw feed XML.
	 *
	 * @param string $feed_url        The feed URL.
	 * @param string $url_restriction Optional URL prefix filter.
	 * @return array<string, array{author: string, date: string, tags: string[], description: string}>
	 */
	private static function metadata_from_xml( string $feed_url, string $url_restriction ): array {
		$response = wp_remote_get( $feed_url, [
			'timeout'    => 30,
			'user-agent' => 'ASAE Content Ingestor/' . ASAE_CI_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
			'sslverify'  => true,
		] );

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return [];
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		libxml_clear_errors();

		if ( false === $xml ) {
			return [];
		}

		$root = $xml->getName();
		$meta = [];

		if ( 'rss' === $root ) {
			foreach ( $xml->channel->item ?? [] as $item ) {
				$link = self::normalise_url( (string) $item->link );
				if ( empty( $link ) || ! self::passes_restriction( $link, $url_restriction ) ) {
					continue;
				}

				$author = trim( (string) ( $item->children( 'dc', true )->creator ?? '' ) );
				if ( ! $author ) {
					$author = trim( (string) ( $item->author ?? '' ) );
				}

				$date = '';
				$pub  = (string) ( $item->pubDate ?? '' );
				if ( $pub ) {
					$ts = strtotime( $pub );
					if ( $ts ) {
						$date = gmdate( 'Y-m-d H:i:s', $ts );
					}
				}

				$tags_arr = [];
				foreach ( $item->category ?? [] as $cat ) {
					$label = trim( (string) $cat );
					if ( $label ) {
						$tags_arr[] = $label;
					}
				}

				$description = trim( (string) ( $item->description ?? '' ) );

				$meta[ $link ] = [
					'author'      => $author,
					'date'        => $date,
					'tags'        => array_unique( array_filter( $tags_arr ) ),
					'description' => $description,
				];
			}
		} elseif ( 'feed' === $root ) {
			// Register Atom namespace for XPath-free access.
			$ns = $xml->getNamespaces( true );

			foreach ( $xml->entry ?? [] as $entry ) {
				$link = self::extract_atom_link( $entry );
				if ( empty( $link ) || ! self::passes_restriction( $link, $url_restriction ) ) {
					continue;
				}

				$author = '';
				if ( isset( $entry->author->name ) ) {
					$author = trim( (string) $entry->author->name );
				}

				$date = '';
				$pub  = (string) ( $entry->published ?? $entry->updated ?? '' );
				if ( $pub ) {
					$ts = strtotime( $pub );
					if ( $ts ) {
						$date = gmdate( 'Y-m-d H:i:s', $ts );
					}
				}

				$tags_arr = [];
				foreach ( $entry->category ?? [] as $cat ) {
					$term  = trim( (string) ( $cat['term']  ?? '' ) );
					$label = trim( (string) ( $cat['label'] ?? '' ) );
					if ( $label ) {
						$tags_arr[] = $label;
					} elseif ( $term ) {
						$tags_arr[] = $term;
					}
				}

				$description = trim( (string) ( $entry->summary ?? '' ) );

				$meta[ $link ] = [
					'author'      => $author,
					'date'        => $date,
					'tags'        => array_unique( array_filter( $tags_arr ) ),
					'description' => $description,
				];
			}
		}

		return $meta;
	}
}
