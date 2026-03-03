<?php
/**
 * ASAE Content Ingestor – HTML Content Parser
 *
 * Parses a fetched HTML page and extracts structured article data:
 * title, body content, author, publication date, taxonomies (categories/tags),
 * featured image URL, inline image URLs, and embeds (iframes etc.).
 *
 * All parsing uses PHP's built-in DOMDocument / DOMXPath – no external library.
 * Embeds (YouTube, Vimeo, etc.) are preserved as their original HTML without
 * modification, per the project requirements.
 *
 * All extracted taxonomies (categories AND tags) are returned as a single flat
 * array of tag labels; the caller maps them all to WP Tags.
 *
 * @package ASAE_Content_Ingestor
 * @since   0.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CI_Parser {

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Main entry point. Parses an HTML page and returns a structured data array.
	 *
	 * Returned array shape:
	 * {
	 *   title          : string   – Article title
	 *   content        : string   – Body HTML (cleaned, embeds preserved)
	 *   author         : string   – Author name (empty if not found)
	 *   date           : string   – Publication date in Y-m-d H:i:s (empty if not found)
	 *   tags           : string[] – All taxonomy labels mapped to WP tags
	 *   featured_image : string   – Absolute URL of the featured/OG image (empty if none)
	 *   inline_images  : string[] – All other image URLs found in the content
	 *   source_url     : string   – The source URL passed in (echoed back)
	 * }
	 *
	 * @param string $url  The URL of the page (used to resolve relative paths).
	 * @param string $html The raw HTML source of the page.
	 * @return array Parsed article data.
	 */
	/**
	 * Main entry point. Parses an HTML page and returns a structured data array.
	 *
	 * Returned array shape:
	 * {
	 *   title            : string   – Article title
	 *   content          : string   – Body HTML (cleaned, embeds preserved)
	 *   author           : string   – Author display name (empty if not found)
	 *   author_bio       : string   – Author bio paragraph extracted from the article
	 *   author_bio_url   : string   – URL of the author's full profile page (empty if none)
	 *   author_photo_url : string   – Author photo URL found in the article (empty if none)
	 *   date             : string   – Publication date in Y-m-d H:i:s (empty if not found)
	 *   tags             : string[] – All taxonomy labels mapped to WP tags
	 *   featured_image   : string   – Absolute URL of the featured/OG image (empty if none)
	 *   inline_images    : string[] – All other image URLs found in the content
	 *   source_url       : string   – The source URL passed in (echoed back)
	 * }
	 *
	 * @param string $url  The URL of the page (used to resolve relative paths).
	 * @param string $html The raw HTML source of the page.
	 * @return array Parsed article data.
	 */
	public static function parse( string $url, string $html ): array {
		// Initialise a silent DOMDocument for the full page.
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );

		// Extract each piece of article data.
		$title          = self::extract_title( $dom, $xpath );
		$authors        = self::extract_authors( $dom, $xpath );
		$author_context = self::extract_author_context( $dom, $xpath, $url );
		$date           = self::extract_date( $dom, $xpath );
		$tags           = self::extract_taxonomies( $dom, $xpath );
		$featured_img   = self::extract_featured_image( $dom, $xpath, $url );
		$content_node   = self::find_content_node( $dom, $xpath );

		// Build the body HTML string with embeds preserved and inline images resolved.
		$content = '';
		if ( $content_node ) {
			$content = self::extract_body_html( $content_node, $dom, $url );
		}

		// Collect all image URLs found inside the content body.
		$inline_images = self::extract_inline_image_urls( $content, $url );

		return [
			'title'            => $title,
			'content'          => $content,
			'authors'          => $authors,
			'author'           => implode( ', ', $authors ), // Backward-compat string for reports/dry-run.
			'author_bio'       => $author_context['bio'],
			'author_bio_url'   => $author_context['bio_url'],
			'author_photo_url' => $author_context['photo_url'],
			'date'             => $date,
			'tags'             => $tags,
			'featured_image'   => $featured_img,
			'inline_images'    => $inline_images,
			'source_url'       => $url,
		];
	}

	// ── Title Extraction ──────────────────────────────────────────────────────

	/**
	 * Extracts the article title using a priority-ordered set of strategies.
	 *
	 * Priority:
	 *  1. og:title meta tag
	 *  2. First <h1> inside <article> or <main>
	 *  3. First <h1> on the page
	 *  4. <title> tag text (trimmed of site name)
	 *
	 * @param DOMDocument $dom   Parsed DOM.
	 * @param DOMXPath    $xpath XPath evaluator.
	 * @return string
	 */
	private static function extract_title( DOMDocument $dom, DOMXPath $xpath ): string {
		// 1. Open Graph title.
		$og = $xpath->query( '//meta[@property="og:title"]/@content' );
		if ( $og && $og->length > 0 ) {
			$val = trim( $og->item(0)->nodeValue );
			if ( $val ) {
				return $val;
			}
		}

		// 2. <h1> inside <article> or <main>.
		$h1_article = $xpath->query( '(//article//h1 | //main//h1)[1]' );
		if ( $h1_article && $h1_article->length > 0 ) {
			$val = trim( $h1_article->item(0)->textContent );
			if ( $val ) {
				return $val;
			}
		}

		// 3. First <h1> anywhere.
		$h1 = $dom->getElementsByTagName( 'h1' );
		if ( $h1->length > 0 ) {
			$val = trim( $h1->item(0)->textContent );
			if ( $val ) {
				return $val;
			}
		}

		// 4. Page <title>.
		$title_nodes = $dom->getElementsByTagName( 'title' );
		if ( $title_nodes->length > 0 ) {
			return trim( $title_nodes->item(0)->textContent );
		}

		return '';
	}

	// ── Author Extraction ─────────────────────────────────────────────────────

	/**
	 * Extracts author names using a priority-ordered set of strategies.
	 * Returns an array so that multi-author articles are handled correctly.
	 *
	 * Priority:
	 *  1. meta[name="author"] – split on " and " / commas if needed.
	 *  2. All <a rel="author"> links (one element per author on well-marked pages).
	 *  3. JSON-LD author field – handles string, single object, and array forms.
	 *  4. Common byline class patterns – split on " and " / commas.
	 *
	 * @param DOMDocument $dom   Parsed DOM.
	 * @param DOMXPath    $xpath XPath evaluator.
	 * @return string[] Array of author display-name strings (may be empty).
	 */
	private static function extract_authors( DOMDocument $dom, DOMXPath $xpath ): array {
		// 1. meta[name="author"].
		$meta_author = $xpath->query( '//meta[@name="author"]/@content' );
		if ( $meta_author && $meta_author->length > 0 ) {
			$val   = trim( $meta_author->item(0)->nodeValue );
			$parts = $val ? self::split_author_string( $val ) : [];
			if ( ! empty( $parts ) ) {
				return $parts;
			}
		}

		// 2. <a rel="author"> links – collect all (there may be one per author).
		$rel_authors = $xpath->query( '//a[@rel="author"]' );
		if ( $rel_authors && $rel_authors->length > 0 ) {
			$names = [];
			foreach ( $rel_authors as $a ) {
				$val = trim( $a->textContent );
				if ( $val && strlen( $val ) < 100 ) {
					$names[] = $val;
				}
			}
			if ( ! empty( $names ) ) {
				return array_values( array_unique( $names ) );
			}
		}

		// 3. JSON-LD structured data – handles string, object, and array forms.
		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		if ( $scripts ) {
			foreach ( $scripts as $script ) {
				$json = json_decode( trim( $script->textContent ), true );
				if ( ! is_array( $json ) || ! isset( $json['author'] ) ) {
					continue;
				}
				$field = $json['author'];

				// Array of author objects/strings.
				if ( isset( $field[0] ) ) {
					$names = [];
					foreach ( $field as $a ) {
						if ( is_array( $a ) && ! empty( $a['name'] ) ) {
							$names[] = trim( $a['name'] );
						} elseif ( is_string( $a ) && $a ) {
							$names[] = trim( $a );
						}
					}
					if ( ! empty( $names ) ) {
						return array_values( array_unique( $names ) );
					}
				}

				// Single author object.
				if ( is_array( $field ) && ! empty( $field['name'] ) ) {
					return self::split_author_string( (string) $field['name'] );
				}

				// Single author string.
				if ( is_string( $field ) && $field ) {
					return self::split_author_string( $field );
				}
			}
		}

		// 4. Common byline class patterns.
		$byline_xpaths = [
			'//*[contains(@class,"byline")]',
			'//*[contains(@class,"author")]',
			'//*[contains(@itemprop,"author")]',
		];
		foreach ( $byline_xpaths as $expr ) {
			$nodes = $xpath->query( $expr );
			if ( ! $nodes || $nodes->length === 0 ) {
				continue;
			}
			$val = trim( $nodes->item(0)->textContent );
			$val = preg_replace( '/^(by|author)[:\s]+/i', '', $val );
			$val = trim( $val );
			if ( $val && strlen( $val ) < 200 ) {
				$parts = self::split_author_string( $val );
				if ( ! empty( $parts ) ) {
					return $parts;
				}
			}
		}

		return [];
	}

	/**
	 * Splits an author string that may contain multiple names (e.g. "Jane Doe
	 * and John Smith" or "Jane Doe, John Smith") into individual name strings.
	 *
	 * Comma-splitting is only applied when every resulting sub-string contains
	 * at least two words, which filters out "Smith, Jr." style suffixes.
	 *
	 * @param string $str Raw author string.
	 * @return string[]
	 */
	private static function split_author_string( string $str ): array {
		$str = trim( $str );
		if ( empty( $str ) ) {
			return [];
		}

		// First split on " and " or " & ".
		$parts  = preg_split( '/\s+(?:and|&)\s+/i', $str );
		$result = [];

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( empty( $part ) ) {
				continue;
			}

			// Attempt comma-splitting only when every sub-part has ≥ 2 words
			// (avoids splitting "Smith, Jr." or "Smith, MD").
			$comma_parts = array_map( 'trim', explode( ',', $part ) );
			if ( count( $comma_parts ) > 1 ) {
				$all_multi_word = true;
				foreach ( $comma_parts as $cp ) {
					if ( count( preg_split( '/\s+/', trim( $cp ), -1, PREG_SPLIT_NO_EMPTY ) ) < 2 ) {
						$all_multi_word = false;
						break;
					}
				}
				if ( $all_multi_word ) {
					foreach ( $comma_parts as $cp ) {
						$cp = trim( $cp );
						if ( $cp ) {
							$result[] = $cp;
						}
					}
					continue;
				}
			}

			$result[] = $part;
		}

		return array_values( array_filter( $result ) );
	}

	// ── Author Context Extraction ─────────────────────────────────────────────

	/**
	 * Extracts supplementary author context from an article page:
	 * the author bio paragraph, a link to the author's full profile page (if
	 * present), and the author photo URL (if present).
	 *
	 * Looks in:
	 *  1. <a rel="author"> — profile link on or near the byline.
	 *  2. An element with class "author-block" or "author-info" — a common
	 *     pattern where CMSes embed an author card at the end of an article.
	 *     Within that block it looks for the first <p> (bio text), the first
	 *     <img> (photo), and any <a> whose href looks like a profile page.
	 *
	 * @param DOMDocument $dom      Parsed DOM.
	 * @param DOMXPath    $xpath    XPath evaluator.
	 * @param string      $page_url Source page URL (for resolving relative paths).
	 * @return array { bio: string, bio_url: string, photo_url: string }
	 */
	private static function extract_author_context( DOMDocument $dom, DOMXPath $xpath, string $page_url ): array {
		$bio       = '';
		$bio_url   = '';
		$photo_url = '';

		// 1. <a rel="author"> — the most semantic signal for a profile link.
		$rel_author = $xpath->query( '//a[@rel="author"]/@href' );
		if ( $rel_author && $rel_author->length > 0 ) {
			$href = trim( $rel_author->item(0)->nodeValue );
			if ( $href ) {
				$bio_url = self::resolve_author_url( $href, $page_url );
			}
		}

		// 2. Author block element — common CMS pattern for an inline author card.
		$block_xpaths = [
			'//*[contains(@class,"author-block")]',
			'//*[contains(@class,"author-info")]',
			'//*[contains(@class,"author-card")]',
			'//*[contains(@class,"author-bio")]',
		];

		foreach ( $block_xpaths as $expr ) {
			$blocks = $xpath->query( $expr );
			if ( ! $blocks || $blocks->length === 0 ) {
				continue;
			}
			$block = $blocks->item(0);

			// Bio text: first <p> inside the block.
			$paras = $xpath->query( './/p', $block );
			if ( $paras && $paras->length > 0 ) {
				$val = trim( $paras->item(0)->textContent );
				if ( $val ) {
					$bio = $val;
				}
			}

			// Photo: first <img> inside the block.
			if ( ! $photo_url ) {
				$imgs = $xpath->query( './/img/@src', $block );
				if ( $imgs && $imgs->length > 0 ) {
					$src = trim( $imgs->item(0)->nodeValue );
					if ( $src ) {
						$photo_url = self::resolve_image_url( $src, $page_url );
					}
				}
			}

			// Profile link: any <a> with a non-anchor, non-search href.
			if ( ! $bio_url ) {
				$links = $xpath->query( './/a/@href', $block );
				if ( $links ) {
					foreach ( $links as $href_node ) {
						$href = trim( $href_node->nodeValue );
						// Skip empty, anchor-only, or search result links.
						if ( $href && '#' !== $href[0] && false === strpos( $href, '/search' ) ) {
							$bio_url = self::resolve_author_url( $href, $page_url );
							break;
						}
					}
				}
			}

			break; // Use the first matching author block only.
		}

		return [
			'bio'       => $bio,
			'bio_url'   => $bio_url,
			'photo_url' => $photo_url,
		];
	}

	/**
	 * Resolves an author profile href to an absolute URL.
	 * Identical logic to resolve_image_url() but without the data: guard,
	 * since author links are navigation URLs not image sources.
	 *
	 * @param string $href     The raw href value.
	 * @param string $page_url The page URL used as the base.
	 * @return string Absolute URL or empty string.
	 */
	private static function resolve_author_url( string $href, string $page_url ): string {
		if ( empty( $href ) ) {
			return '';
		}
		if ( preg_match( '/^https?:\/\//i', $href ) ) {
			return $href;
		}
		$base = parse_url( $page_url );
		if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return '';
		}
		$origin = $base['scheme'] . '://' . $base['host'];
		if ( ! empty( $base['port'] ) ) {
			$origin .= ':' . $base['port'];
		}
		if ( str_starts_with( $href, '//' ) ) {
			return $base['scheme'] . ':' . $href;
		}
		if ( str_starts_with( $href, '/' ) ) {
			return $origin . $href;
		}
		$base_path = isset( $base['path'] ) ? dirname( $base['path'] ) : '/';
		return $origin . rtrim( $base_path, '/' ) . '/' . $href;
	}

	// ── Date Extraction ───────────────────────────────────────────────────────

	/**
	 * Extracts the publication date using a priority-ordered set of strategies.
	 * Always returns a MySQL datetime string (Y-m-d H:i:s) or an empty string.
	 *
	 * @param DOMDocument $dom   Parsed DOM.
	 * @param DOMXPath    $xpath XPath evaluator.
	 * @return string Date in Y-m-d H:i:s format, or empty string.
	 */
	private static function extract_date( DOMDocument $dom, DOMXPath $xpath ): string {
		// 1. article:published_time meta (Open Graph for articles).
		$og_date = $xpath->query( '//meta[@property="article:published_time"]/@content' );
		if ( $og_date && $og_date->length > 0 ) {
			$ts = strtotime( trim( $og_date->item(0)->nodeValue ) );
			if ( $ts ) {
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		// 2. <time datetime="..."> element.
		$time_el = $xpath->query( '//time[@datetime]/@datetime' );
		if ( $time_el && $time_el->length > 0 ) {
			$ts = strtotime( trim( $time_el->item(0)->nodeValue ) );
			if ( $ts ) {
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		// 3. JSON-LD datePublished.
		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		if ( $scripts ) {
			foreach ( $scripts as $script ) {
				$json = json_decode( trim( $script->textContent ), true );
				if ( is_array( $json ) && isset( $json['datePublished'] ) ) {
					$ts = strtotime( $json['datePublished'] );
					if ( $ts ) {
						return gmdate( 'Y-m-d H:i:s', $ts );
					}
				}
			}
		}

		// 4. Dublin Core date meta.
		$dc_date = $xpath->query( '//meta[@name="DC.date" or @name="dc.date"]/@content' );
		if ( $dc_date && $dc_date->length > 0 ) {
			$ts = strtotime( trim( $dc_date->item(0)->nodeValue ) );
			if ( $ts ) {
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		// 5. Non-standard <date> element (plain text content).
		// Used by some CMS platforms (e.g. ASAE) that render the publication
		// date as <date>February 26, 2026</date> without a machine-readable
		// attribute. PHP's strtotime() handles natural-language date strings.
		$date_el = $xpath->query( '//date' );
		if ( $date_el && $date_el->length > 0 ) {
			foreach ( $date_el as $el ) {
				$val = trim( $el->textContent );
				if ( $val ) {
					$ts = strtotime( $val );
					if ( $ts ) {
						return gmdate( 'Y-m-d H:i:s', $ts );
					}
				}
			}
		}

		// 6. <time> element without a datetime attribute (text content fallback).
		// Handles sites that use <time> as a semantic wrapper but omit the
		// machine-readable datetime attribute.
		$time_text = $xpath->query( '//time[not(@datetime)]' );
		if ( $time_text && $time_text->length > 0 ) {
			foreach ( $time_text as $el ) {
				$val = trim( $el->textContent );
				if ( $val ) {
					$ts = strtotime( $val );
					if ( $ts ) {
						return gmdate( 'Y-m-d H:i:s', $ts );
					}
				}
			}
		}

		return '';
	}

	// ── Taxonomy Extraction ───────────────────────────────────────────────────

	/**
	 * Extracts all taxonomy labels (categories and tags) from the page.
	 * Per project requirements, all values are returned as a flat list and
	 * will be applied exclusively as WP Tags during ingestion.
	 *
	 * @param DOMDocument $dom   Parsed DOM.
	 * @param DOMXPath    $xpath XPath evaluator.
	 * @return string[] Flat array of tag/category label strings.
	 */
	private static function extract_taxonomies( DOMDocument $dom, DOMXPath $xpath ): array {
		$terms = [];

		// 1. Open Graph article:tag and article:section.
		foreach ( [ 'article:tag', 'article:section' ] as $property ) {
			$nodes = $xpath->query( '//meta[@property="' . $property . '"]/@content' );
			if ( $nodes ) {
				foreach ( $nodes as $node ) {
					$val = trim( $node->nodeValue );
					if ( $val ) {
						$terms[] = $val;
					}
				}
			}
		}

		// 2. <a rel="tag"> links.
		$rel_tags = $xpath->query( '//a[@rel="tag"]' );
		if ( $rel_tags ) {
			foreach ( $rel_tags as $tag ) {
				$val = trim( $tag->textContent );
				if ( $val ) {
					$terms[] = $val;
				}
			}
		}

		// 3. <a rel="category tag"> links.
		$rel_cat = $xpath->query( '//a[contains(@rel,"category")]' );
		if ( $rel_cat ) {
			foreach ( $rel_cat as $cat ) {
				$val = trim( $cat->textContent );
				if ( $val ) {
					$terms[] = $val;
				}
			}
		}

		// 4. JSON-LD keywords / articleSection.
		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		if ( $scripts ) {
			foreach ( $scripts as $script ) {
				$json = json_decode( trim( $script->textContent ), true );
				if ( is_array( $json ) ) {
					foreach ( [ 'keywords', 'articleSection' ] as $field ) {
						if ( isset( $json[ $field ] ) ) {
							$val = $json[ $field ];
							if ( is_string( $val ) ) {
								// Keywords are often comma-separated.
								$parts = array_map( 'trim', explode( ',', $val ) );
								$terms = array_merge( $terms, array_filter( $parts ) );
							} elseif ( is_array( $val ) ) {
								$terms = array_merge( $terms, array_filter( array_map( 'trim', $val ) ) );
							}
						}
					}
				}
			}
		}

		// 5. Common taxonomy list elements.
		$tag_list_xpaths = [
			'//*[contains(@class,"tags")]//a',
			'//*[contains(@class,"categories")]//a',
			'//*[contains(@class,"tag-list")]//a',
			'//*[@itemprop="keywords"]',
		];
		foreach ( $tag_list_xpaths as $expr ) {
			$nodes = $xpath->query( $expr );
			if ( $nodes ) {
				foreach ( $nodes as $node ) {
					$val = trim( $node->textContent );
					if ( $val && strlen( $val ) < 100 ) {
						$terms[] = $val;
					}
				}
			}
		}

		// Deduplicate and return.
		return array_values( array_unique( array_filter( $terms ) ) );
	}

	// ── Featured Image Extraction ─────────────────────────────────────────────

	/**
	 * Extracts the featured (primary) image URL using a priority-ordered approach.
	 *
	 * @param DOMDocument $dom      Parsed DOM.
	 * @param DOMXPath    $xpath    XPath evaluator.
	 * @param string      $page_url Source page URL (for resolving relative paths).
	 * @return string Absolute URL of the featured image, or empty string.
	 */
	private static function extract_featured_image( DOMDocument $dom, DOMXPath $xpath, string $page_url ): string {
		// 1. og:image meta tag – most reliable.
		$og_image = $xpath->query( '//meta[@property="og:image"]/@content' );
		if ( $og_image && $og_image->length > 0 ) {
			$src = trim( $og_image->item(0)->nodeValue );
			if ( $src ) {
				return self::resolve_image_url( $src, $page_url );
			}
		}

		// 2. twitter:image meta.
		$tw_image = $xpath->query( '//meta[@name="twitter:image"]/@content' );
		if ( $tw_image && $tw_image->length > 0 ) {
			$src = trim( $tw_image->item(0)->nodeValue );
			if ( $src ) {
				return self::resolve_image_url( $src, $page_url );
			}
		}

		// 3. JSON-LD "image" field.
		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		if ( $scripts ) {
			foreach ( $scripts as $script ) {
				$json = json_decode( trim( $script->textContent ), true );
				if ( is_array( $json ) && isset( $json['image'] ) ) {
					$img = $json['image'];
					$src = is_array( $img ) ? ( $img['url'] ?? '' ) : (string) $img;
					if ( $src ) {
						return self::resolve_image_url( $src, $page_url );
					}
				}
			}
		}

		// 4. First <img> inside known content containers (mirrors find_content_node candidates).
		$content_imgs = $xpath->query(
			'(//article//img | //figure//img
			| //*[@itemprop="articleBody"]//img
			| //*[contains(@class,"article-body")]//img
			| //*[contains(@class,"entry-content")]//img
			| //*[contains(@class,"post-content")]//img
			| //*[contains(@class,"content-body")]//img
			| //*[contains(@class,"story-body")]//img)[1]'
		);
		if ( $content_imgs && $content_imgs->length > 0 ) {
			$src = trim( $content_imgs->item(0)->getAttribute( 'src' ) );
			if ( $src ) {
				return self::resolve_image_url( $src, $page_url );
			}
		}

		return '';
	}

	// ── Content Body Extraction ───────────────────────────────────────────────

	/**
	 * Finds the best candidate node for the article's main body content.
	 *
	 * Returns the DOMNode or null if none found.
	 *
	 * @param DOMDocument $dom   Parsed DOM.
	 * @param DOMXPath    $xpath XPath evaluator.
	 * @return DOMNode|null
	 */
	private static function find_content_node( DOMDocument $dom, DOMXPath $xpath ): ?DOMNode {
		// Priority list of content container XPath expressions.
		$candidates = [
			'//article',
			'//*[@role="article"]',
			'//*[@itemprop="articleBody"]',
			'//main',
			'//*[@role="main"]',
			'//*[contains(@class,"article-body")]',
			'//*[contains(@class,"entry-content")]',
			'//*[contains(@class,"post-content")]',
			'//*[contains(@class,"content-body")]',
			'//*[contains(@class,"story-body")]',
		];

		foreach ( $candidates as $expr ) {
			$nodes = $xpath->query( $expr );
			if ( $nodes && $nodes->length > 0 ) {
				return $nodes->item(0);
			}
		}

		// Fall back to <body> if no semantic container found.
		$body = $dom->getElementsByTagName( 'body' );
		if ( $body->length > 0 ) {
			return $body->item(0);
		}

		return null;
	}

	/**
	 * Serialises a content DOMNode back to an HTML string.
	 *
	 * - Embeds (<iframe>, <video>, <embed>, <object>) are preserved verbatim.
	 * - Relative image src attributes are resolved to absolute URLs.
	 * - Script, style, and chrome elements are stripped.
	 * - Inline author bio blocks are stripped (extracted separately to user meta).
	 *
	 * @param DOMNode     $node     The content container node.
	 * @param DOMDocument $dom      The owning document (needed for saveHTML).
	 * @param string      $page_url The source page URL for resolving relative paths.
	 * @return string Cleaned HTML string.
	 */
	private static function extract_body_html( DOMNode $node, DOMDocument $dom, string $page_url ): string {
		// Build a clean working document with a known wrapper.
		// We import the CHILDREN of the content node (not the node itself) so
		// that block-level container tags (<body>, <article>, <main>, etc.)
		// cannot escape the wrapper when libxml re-parses the HTML.
		$work = new DOMDocument();
		libxml_use_internal_errors( true );
		$work->loadHTML( '<?xml encoding="UTF-8"><html><body><div id="asae-ci-wrap"></div></body></html>' );
		libxml_clear_errors();

		$work_xpath = new DOMXPath( $work );
		$wrappers   = $work_xpath->query( '//div[@id="asae-ci-wrap"]' );
		if ( ! $wrappers || ! $wrappers->length ) {
			return '';
		}
		$wrapper = $wrappers->item( 0 );

		// childNodes is a live NodeList; snapshot to a static array before iterating.
		$children = [];
		foreach ( $node->childNodes as $child ) {
			$children[] = $child;
		}
		foreach ( $children as $child ) {
			$imported = $work->importNode( $child, true );
			$wrapper->appendChild( $imported );
		}

		// XPath expressions for elements that must not appear in post content.
		// Author bio blocks are stripped here; their text has already been
		// captured by extract_author_context() for storage in user meta.
		$removal_xpaths = [
			'//script',
			'//style',
			'//nav',
			'//header',
			'//footer',
			'//aside',
			'//form',
			'//*[contains(@class,"author-block")]',
			'//*[contains(@class,"author-info")]',
			'//*[contains(@class,"author-card")]',
			'//*[contains(@class,"author-bio")]',
		];

		foreach ( $removal_xpaths as $expr ) {
			$nodes_to_remove = [];
			$found = $work_xpath->query( $expr );
			if ( $found ) {
				foreach ( $found as $n ) {
					$nodes_to_remove[] = $n;
				}
			}
			foreach ( $nodes_to_remove as $n ) {
				if ( $n->parentNode ) {
					$n->parentNode->removeChild( $n );
				}
			}
		}

		// Resolve relative image src and srcset attributes.
		$imgs = $work->getElementsByTagName( 'img' );
		foreach ( $imgs as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( $src && ! preg_match( '/^https?:\/\//i', $src ) ) {
				$img->setAttribute( 'src', self::resolve_image_url( $src, $page_url ) );
			}
			$srcset = $img->getAttribute( 'srcset' );
			if ( $srcset ) {
				$img->setAttribute( 'srcset', self::resolve_srcset( $srcset, $page_url ) );
			}
		}

		// Return innerHTML of the wrapper div (excludes the wrapper tag itself).
		$inner = '';
		foreach ( $wrapper->childNodes as $child ) {
			$inner .= $work->saveHTML( $child );
		}

		return trim( $inner );
	}

	// ── Inline Image URL Extraction ───────────────────────────────────────────

	/**
	 * Finds all image URLs referenced in a block of HTML content.
	 * Used to build the list of images that need to be downloaded and re-hosted.
	 *
	 * @param string $html     HTML content string.
	 * @param string $page_url Source page URL for resolving relative paths.
	 * @return string[] Array of absolute image URLs.
	 */
	private static function extract_inline_image_urls( string $html, string $page_url ): array {
		if ( empty( $html ) ) {
			return [];
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>' );
		libxml_clear_errors();

		$urls = [];
		$imgs = $dom->getElementsByTagName( 'img' );
		foreach ( $imgs as $img ) {
			$src = trim( $img->getAttribute( 'src' ) );
			if ( $src ) {
				$absolute = self::resolve_image_url( $src, $page_url );
				if ( $absolute ) {
					$urls[] = $absolute;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	// ── URL Helpers ───────────────────────────────────────────────────────────

	/**
	 * Resolves a (potentially relative) image src to an absolute URL.
	 *
	 * Images are allowed to come from outside the crawled folder,
	 * per project requirements.
	 *
	 * @param string $src      The src attribute value.
	 * @param string $page_url The page URL to use as the base.
	 * @return string Absolute URL or empty string.
	 */
	private static function resolve_image_url( string $src, string $page_url ): string {
		if ( empty( $src ) || str_starts_with( $src, 'data:' ) ) {
			return '';
		}

		if ( preg_match( '/^https?:\/\//i', $src ) ) {
			return $src;
		}

		$base = parse_url( $page_url );
		if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return '';
		}

		$origin = $base['scheme'] . '://' . $base['host'];
		if ( ! empty( $base['port'] ) ) {
			$origin .= ':' . $base['port'];
		}

		if ( str_starts_with( $src, '//' ) ) {
			return $base['scheme'] . ':' . $src;
		}

		if ( str_starts_with( $src, '/' ) ) {
			return $origin . $src;
		}

		// Relative to current page directory.
		$base_path = isset( $base['path'] ) ? dirname( $base['path'] ) : '/';
		return $origin . rtrim( $base_path, '/' ) . '/' . $src;
	}

	/**
	 * Resolves all URLs within an HTML srcset attribute to absolute form.
	 *
	 * @param string $srcset   The srcset attribute value.
	 * @param string $page_url The page URL to use as the base.
	 * @return string Updated srcset value.
	 */
	private static function resolve_srcset( string $srcset, string $page_url ): string {
		$parts = explode( ',', $srcset );
		$resolved = [];
		foreach ( $parts as $part ) {
			$part    = trim( $part );
			$tokens  = preg_split( '/\s+/', $part, 2 );
			$url     = self::resolve_image_url( $tokens[0] ?? '', $page_url );
			$desc    = $tokens[1] ?? '';
			$resolved[] = trim( $url . ( $desc ? ' ' . $desc : '' ) );
		}
		return implode( ', ', $resolved );
	}
}
