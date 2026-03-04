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
	 *   title            : string   – Article title (sitewide suffix stripped)
	 *   content          : string   – Body HTML (cleaned, embeds preserved)
	 *   excerpt          : string   – Explicit excerpt / summary (empty if not found)
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
		$excerpt        = self::extract_excerpt( $dom, $xpath );
		$authors        = self::extract_authors( $dom, $xpath );
		$author_context = self::extract_author_context( $dom, $xpath, $url );
		$date           = self::extract_date( $dom, $xpath );
		$tags           = self::extract_taxonomies( $dom, $xpath );
		$featured_img   = self::extract_featured_image( $dom, $xpath, $url );
		$content_node   = self::find_content_node( $dom, $xpath );

		// Refine: if the selected node is a broad element (article/main/body),
		// try to zoom in to the actual prose body sub-container.
		if ( $content_node ) {
			$content_node = self::refine_content_node( $content_node, $xpath );
		}

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
			'excerpt'          => $excerpt,
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
	 * Extracts the article title using a priority-ordered set of strategies,
	 * then strips any sitewide suffix before returning.
	 *
	 * Priority:
	 *  1. og:title meta tag
	 *  2. First <h1> inside <article> or <main>
	 *  3. First <h1> on the page
	 *  4. <title> tag text
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
				return self::clean_title_suffix( $val );
			}
		}

		// 2. <h1> inside <article> or <main>.
		$h1_article = $xpath->query( '(//article//h1 | //main//h1)[1]' );
		if ( $h1_article && $h1_article->length > 0 ) {
			$val = trim( $h1_article->item(0)->textContent );
			if ( $val ) {
				return self::clean_title_suffix( $val );
			}
		}

		// 3. First <h1> anywhere.
		$h1 = $dom->getElementsByTagName( 'h1' );
		if ( $h1->length > 0 ) {
			$val = trim( $h1->item(0)->textContent );
			if ( $val ) {
				return self::clean_title_suffix( $val );
			}
		}

		// 4. Page <title>.
		$title_nodes = $dom->getElementsByTagName( 'title' );
		if ( $title_nodes->length > 0 ) {
			return self::clean_title_suffix( trim( $title_nodes->item(0)->textContent ) );
		}

		return '';
	}

	/**
	 * Strips a sitewide suffix from a title string.
	 *
	 * Many CMSes append the site name (or a section hierarchy) to the end of
	 * every page title, separated by " | ", " – " (en-dash), or " - ". Since
	 * the plugin stores the article title separately as the WP post title, the
	 * trailing site identifier is redundant and should be removed.
	 *
	 * Rules applied in priority order:
	 *  1. Split on " | " — take only the first segment.
	 *     e.g. "Article Title | Section | Site Name" → "Article Title"
	 *  2. Split on " – " (en-dash) — take only the first segment.
	 *  3. Strip a trailing " - Suffix" only when the suffix is ≤ 40 chars and
	 *     ≤ 4 words (avoids stripping "Year-End Review - Why It Matters").
	 *
	 * This function is idempotent: a title with none of these separators is
	 * returned unchanged, so it is safe to call on any title source.
	 *
	 * @param string $title Raw title string.
	 * @return string Cleaned title.
	 */
	private static function clean_title_suffix( string $title ): string {
		// Rule 1: pipe separator.
		if ( str_contains( $title, ' | ' ) ) {
			return trim( explode( ' | ', $title )[0] );
		}

		// Rule 2: en-dash separator.
		if ( str_contains( $title, ' – ' ) ) {
			return trim( explode( ' – ', $title )[0] );
		}

		// Rule 3: hyphen separator — only strip a short (≤ 4-word) trailing segment.
		if ( preg_match( '/^(.+) - (.{1,40})$/', $title, $m ) ) {
			$suffix_words = preg_split( '/\s+/', trim( $m[2] ), -1, PREG_SPLIT_NO_EMPTY );
			if ( count( $suffix_words ) <= 4 ) {
				return trim( $m[1] );
			}
		}

		return $title;
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
	 * Three sources are checked in priority order:
	 *
	 *  1. JSON-LD structured data — the most reliable source when present.
	 *     The article's LD+JSON often carries an author object with a URL,
	 *     description, and image. find_jsonld_author() handles @graph arrays
	 *     and nested entity shapes (Yoast, RankMath, etc.).
	 *
	 *  2. <a rel="author"> — semantic profile link on or near the byline.
	 *     Used as a fallback source for the bio_url only.
	 *
	 *  3. Inline author block element — CMS-rendered author card at the end
	 *     of the article. An expanded list of class patterns covers standard
	 *     WP themes, Gutenberg blocks, and custom BEM-named components.
	 *     Within the block:
	 *      - Bio: looks for an explicit description sub-element first, then
	 *        the first <p> as a fallback.
	 *      - Photo: prefers <img class="avatar"> (WP Gravatar standard) over
	 *        any first <img>.
	 *      - Profile link: any non-anchor, non-search <a> href.
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

		// 1. JSON-LD — most structured source when present on the page.
		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		if ( $scripts ) {
			foreach ( $scripts as $script ) {
				$json = json_decode( trim( $script->textContent ), true );
				if ( ! is_array( $json ) ) {
					continue;
				}
				$author_obj = self::find_jsonld_author( $json );
				if ( $author_obj ) {
					if ( empty( $bio_url ) && ! empty( $author_obj['url'] ) ) {
						$bio_url = self::resolve_author_url( (string) $author_obj['url'], $page_url );
					}
					if ( empty( $bio ) && ! empty( $author_obj['description'] ) ) {
						$bio = sanitize_textarea_field( (string) $author_obj['description'] );
					}
					if ( empty( $photo_url ) ) {
						$img = $author_obj['image'] ?? null;
						if ( is_string( $img ) && $img ) {
							$photo_url = self::resolve_image_url( $img, $page_url );
						} elseif ( is_array( $img ) ) {
							// Handles both {url:...} and {contentUrl:...} shapes.
							$src = (string) ( $img['url'] ?? $img['contentUrl'] ?? '' );
							if ( $src ) {
								$photo_url = self::resolve_image_url( $src, $page_url );
							}
						}
					}
					// All three fields found — no need to check more scripts.
					if ( $bio_url && $bio && $photo_url ) {
						break;
					}
				}
			}
		}

		// 2. <a rel="author"> — semantic profile link for bio_url.
		if ( empty( $bio_url ) ) {
			$rel_author = $xpath->query( '//a[@rel="author"]/@href' );
			if ( $rel_author && $rel_author->length > 0 ) {
				$href = trim( $rel_author->item(0)->nodeValue );
				if ( $href ) {
					$bio_url = self::resolve_author_url( $href, $page_url );
				}
			}
		}

		// 3. Inline author block element — expanded class list for broader
		//    theme coverage (standard WP themes, Gutenberg, BEM components).
		$block_xpaths = [
			'//*[contains(@class,"author-block")]',
			'//*[contains(@class,"author-info")]',
			'//*[contains(@class,"author-card")]',
			'//*[contains(@class,"author-bio")]',
			'//*[contains(@class,"author-box")]',
			'//*[contains(@class,"post-author")]',
			'//*[contains(@class,"entry-author")]',
			'//*[contains(@class,"author-section")]',
			'//*[contains(@class,"author-profile")]',
			'//*[contains(@class,"author-widget")]',
			'//*[contains(@class,"contributor-box")]',
			'//*[contains(@class,"wp-block-post-author")]',
			// Schema.org microdata author containers.
			'//*[@itemprop="author"]',
		];

		foreach ( $block_xpaths as $expr ) {
			$blocks = $xpath->query( $expr );
			if ( ! $blocks || $blocks->length === 0 ) {
				continue;
			}
			$block = $blocks->item(0);

			// Bio text: prefer an explicit description sub-element over
			// the first generic <p> to avoid picking up the author's name
			// or job title as the bio.
			if ( empty( $bio ) ) {
				$desc_classes = [
					'author-description', 'author__description',
					'wp-block-post-author__bio', 'user-description',
				];
				foreach ( $desc_classes as $cls ) {
					$desc_el = $xpath->query( './/*[contains(@class,"' . $cls . '")]', $block );
					if ( $desc_el && $desc_el->length > 0 ) {
						$val = trim( $desc_el->item(0)->textContent );
						if ( $val && strlen( $val ) > 20 ) {
							$bio = sanitize_textarea_field( $val );
							break;
						}
					}
				}
				// Schema.org itemprop="description" inside the author block.
				if ( empty( $bio ) ) {
					$itemprop_desc = $xpath->query( './/*[@itemprop="description"]', $block );
					if ( $itemprop_desc && $itemprop_desc->length > 0 ) {
						$val = trim( $itemprop_desc->item(0)->textContent );
						if ( $val && strlen( $val ) > 20 ) {
							$bio = sanitize_textarea_field( $val );
						}
					}
				}
				// Fallback: first <p> inside the block.
				if ( empty( $bio ) ) {
					$paras = $xpath->query( './/p', $block );
					if ( $paras && $paras->length > 0 ) {
						$val = trim( $paras->item(0)->textContent );
						if ( $val ) {
							$bio = sanitize_textarea_field( $val );
						}
					}
				}
			}

			// Photo: check itemprop="image", then class="avatar", then any img.
			if ( empty( $photo_url ) ) {
				// Schema.org itemprop="image" — most explicit signal.
				$itemprop_img = $xpath->query( './/img[@itemprop="image"]/@src', $block );
				if ( ! $itemprop_img || ! $itemprop_img->length ) {
					$itemprop_img = $xpath->query( './/*[@itemprop="image"]//img/@src', $block );
				}
				if ( $itemprop_img && $itemprop_img->length > 0 ) {
					$src = trim( $itemprop_img->item(0)->nodeValue );
					if ( $src ) {
						$photo_url = self::resolve_image_url( $src, $page_url );
					}
				}
			}
			if ( empty( $photo_url ) ) {
				// <img class="avatar"> — standard WP Gravatar class (real avatar, not placeholder).
				$avatar_imgs = $xpath->query( './/img[contains(@class,"avatar")]/@src', $block );
				if ( $avatar_imgs && $avatar_imgs->length > 0 ) {
					$src = trim( $avatar_imgs->item(0)->nodeValue );
					if ( $src ) {
						$photo_url = self::resolve_image_url( $src, $page_url );
					}
				}
			}
			if ( empty( $photo_url ) ) {
				// Any img inside the block as a last resort.
				$imgs = $xpath->query( './/img/@src', $block );
				if ( $imgs && $imgs->length > 0 ) {
					$src = trim( $imgs->item(0)->nodeValue );
					if ( $src ) {
						$photo_url = self::resolve_image_url( $src, $page_url );
					}
				}
			}

			// Profile link: any <a> with a non-anchor, non-search href.
			if ( empty( $bio_url ) ) {
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
	 * Recursively searches a decoded JSON-LD structure for an author entity
	 * and returns its data array.
	 *
	 * Handles three common shapes produced by Yoast SEO, RankMath, and other
	 * structured-data plugins:
	 *  - Root object with "author": {"@type":"Person", ...}
	 *  - @graph array containing an Article node with an "author" sub-object
	 *  - Nested entities (e.g. WebPage → mainEntity → Article → author)
	 *
	 * Returns the first author object found (multi-author articles are handled
	 * by returning the first element of an author array).
	 *
	 * @param array $data Decoded JSON-LD data (may be a single entity or @graph).
	 * @return array|null Author data array (may contain url, description, image), or null.
	 */
	private static function find_jsonld_author( array $data ): ?array {
		// Expand @graph arrays — Yoast and RankMath emit all entities here.
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$found = self::find_jsonld_author( $item );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		// Direct author field on this object (Article, NewsArticle, etc.).
		if ( isset( $data['author'] ) ) {
			$a = $data['author'];
			// Single author object: {"author": {"@type": "Person", ...}}
			if ( is_array( $a ) && ! empty( $a['@type'] ) ) {
				return $a;
			}
			// Array of author objects: {"author": [{"@type": "Person", ...}, ...]}
			if ( is_array( $a ) && isset( $a[0] ) && is_array( $a[0] ) ) {
				return $a[0];
			}
		}

		// Recurse into nested typed entities (e.g. WebPage → mainEntity).
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, [ '@graph', 'author' ], true ) ) {
				continue; // Already handled above.
			}
			if ( is_array( $value ) && ! empty( $value['@type'] ) ) {
				$found = self::find_jsonld_author( $value );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
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

	// ── Excerpt Extraction ────────────────────────────────────────────────────

	/**
	 * Extracts an explicit article excerpt / summary from the page.
	 *
	 * When a source CMS provides a hand-written summary it is always preferable
	 * to WordPress's auto-excerpt (first N characters of content). This method
	 * looks for such a summary in priority order:
	 *
	 *  1. First element whose class contains "excerpt" — explicit summary block.
	 *  2. og:description meta — set by most CMSes specifically as an article
	 *     summary shown in social-media previews.
	 *  3. meta[name="description"] — general page description fallback.
	 *
	 * Returns an empty string if no explicit excerpt is found, in which case
	 * WordPress will generate its own auto-excerpt from the post content.
	 *
	 * @param DOMDocument $dom   Parsed DOM.
	 * @param DOMXPath    $xpath XPath evaluator.
	 * @return string Plain-text excerpt, or empty string.
	 */
	private static function extract_excerpt( DOMDocument $dom, DOMXPath $xpath ): string {
		// 1. Explicit excerpt element on the page.
		$el = $xpath->query( '//*[contains(@class,"excerpt")]' );
		if ( $el && $el->length > 0 ) {
			$val = trim( $el->item(0)->textContent );
			if ( $val ) {
				return sanitize_textarea_field( $val );
			}
		}

		// 2. og:description meta tag.
		$og_desc = $xpath->query( '//meta[@property="og:description"]/@content' );
		if ( $og_desc && $og_desc->length > 0 ) {
			$val = trim( $og_desc->item(0)->nodeValue );
			if ( $val ) {
				return sanitize_textarea_field( $val );
			}
		}

		// 3. Standard meta description.
		$meta_desc = $xpath->query( '//meta[@name="description"]/@content' );
		if ( $meta_desc && $meta_desc->length > 0 ) {
			$val = trim( $meta_desc->item(0)->nodeValue );
			if ( $val ) {
				return sanitize_textarea_field( $val );
			}
		}

		return '';
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

		// 4. JSON-LD keywords / articleSection — handled recursively so that
		//    @graph arrays and nested Article/NewsArticle entities are all
		//    searched, regardless of how the CMS structures its output.
		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		if ( $scripts ) {
			foreach ( $scripts as $script ) {
				$json = json_decode( trim( $script->textContent ), true );
				if ( is_array( $json ) ) {
					self::collect_jsonld_taxonomy_values( $json, $terms );
				}
			}
		}

		// 5. Common taxonomy list elements.
		// The first set of XPaths targets <a> link children (traditional WP tag/cat links).
		// The second set targets non-link badge elements (<div>, <span>) used by some
		// themes to display category labels without making them hyperlinks.
		$tag_list_xpaths = [
			// Link-based patterns.
			'//*[contains(@class,"tags")]//a',
			'//*[contains(@class,"categories")]//a',
			'//*[contains(@class,"tag-list")]//a',
			'//*[@itemprop="keywords"]',
			// Non-link badge patterns (direct children only — avoids over-capturing).
			'//*[contains(@class,"article-categories")]/*[not(descendant-or-self::a)]',
			'//*[contains(@class,"article-tags")]/*[not(descendant-or-self::a)]',
			'//*[contains(@class,"post-categories")]/*[not(descendant-or-self::a)]',
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

	/**
	 * Recursively collects taxonomy values (keywords, articleSection) from a
	 * decoded JSON-LD data structure.
	 *
	 * Handles three common JSON-LD shapes:
	 *  - Root object: {"@type":"Article","articleSection":["A","B"]}
	 *  - @graph array: {"@graph":[{"@type":"Article","articleSection":["A","B"]},...]}
	 *  - Nested entity: {"@type":"WebPage","mainEntity":{"@type":"Article",...}}
	 *
	 * @param mixed  $data  Decoded JSON (array or scalar).
	 * @param array  $terms Accumulator array — values are appended in place.
	 * @return void
	 */
	private static function collect_jsonld_taxonomy_values( mixed $data, array &$terms ): void {
		if ( ! is_array( $data ) ) {
			return;
		}

		// Expand @graph arrays first.
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $item ) {
				self::collect_jsonld_taxonomy_values( $item, $terms );
			}
		}

		// Collect taxonomy values at this level.
		foreach ( [ 'keywords', 'articleSection' ] as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$val = $data[ $field ];
				if ( is_string( $val ) ) {
					// keywords are often comma-separated.
					$parts = array_map( 'trim', explode( ',', $val ) );
					$terms = array_merge( $terms, array_filter( $parts ) );
				} elseif ( is_array( $val ) ) {
					$terms = array_merge( $terms, array_filter( array_map( 'trim', $val ) ) );
				}
			}
		}

		// Recurse into nested entity objects (e.g. mainEntity, hasPart, etc.).
		// Only recurse into values that look like typed entities (have @type)
		// to avoid descending into primitive arrays like articleSection itself.
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, [ '@graph', 'keywords', 'articleSection' ], true ) ) {
				continue; // Already handled above.
			}
			if ( is_array( $value ) && isset( $value['@type'] ) ) {
				self::collect_jsonld_taxonomy_values( $value, $terms );
			}
		}
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
			| //*[@role="main"]//img
			| //*[contains(@class,"article-body")]//img
			| //*[contains(@class,"entry-content")]//img
			| //*[contains(@class,"post-content")]//img
			| //*[contains(@class,"content-body")]//img
			| //*[contains(@class,"story-body")]//img
			| //*[contains(@class,"rich-text")]//img)[1]'
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
	 * Priority order is designed to prefer the most semantically unambiguous
	 * signals first. In particular, role="main" and itemprop="articleBody"
	 * outrank the bare <article> element, which CMS templates sometimes use
	 * for non-content blocks such as author bio cards.  Likewise, role="main"
	 * is tried before //main because some pages include multiple <main>
	 * elements (e.g. one inside a navigation mega-menu) and the ARIA role is
	 * the clearest signal for the true content container.
	 *
	 * @param DOMDocument $dom   Parsed DOM.
	 * @param DOMXPath    $xpath XPath evaluator.
	 * @return DOMNode|null
	 */
	private static function find_content_node( DOMDocument $dom, DOMXPath $xpath ): ?DOMNode {
		// Priority list of content container XPath expressions.
		$candidates = [
			'//*[@itemprop="articleBody"]',
			'//*[@role="main"]',
			'//*[contains(@class,"article-body")]',
			'//*[contains(@class,"entry-content")]',
			'//*[contains(@class,"post-content")]',
			'//*[contains(@class,"content-body")]',
			'//*[contains(@class,"story-body")]',
			'//*[contains(@class,"rich-text")]',
			'//article',
			'//*[@role="article"]',
			'//main',
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
	 * Attempts to zoom in from a broad semantic container (article / main / body)
	 * to the specific sub-element that holds the article's prose body.
	 *
	 * Many WordPress themes wrap the full article — header, body, and author bio —
	 * inside a single <article> element, using theme-specific class names for the
	 * sub-sections. Rather than trying to enumerate every possible class name,
	 * this method uses two strategies in order:
	 *
	 *  1. Known content class patterns — the same names already in find_content_node()
	 *     plus a few additional WP-common patterns. Catches entry-content, post-body, etc.
	 *
	 *  2. Paragraph-count heuristic — finds the first descendant div or section
	 *     that has ≥ 3 direct <p> children. Article header blocks (category label,
	 *     h1, byline) have no direct <p> children; author bio blocks typically have
	 *     1–2 direct <p> children; the prose body usually has 3+. This approach is
	 *     class-name-agnostic and works with fully custom themes.
	 *
	 *  3. Relaxed paragraph heuristic — same as above but with ≥ 2 direct <p>
	 *     children, used as a second-pass fallback for shorter articles.
	 *
	 * If none of the strategies find a narrower match, the original node is returned
	 * unchanged — so this method is always safe to call.
	 *
	 * @param DOMNode  $node  The content node found by find_content_node().
	 * @param DOMXPath $xpath XPath evaluator for the full page DOM.
	 * @return DOMNode The same node or a more specific descendant.
	 */
	private static function refine_content_node( DOMNode $node, DOMXPath $xpath ): DOMNode {
		// Only refine when we landed on a broad container; specific selections
		// (e.g. itemprop="articleBody", entry-content) don't need refinement.
		if ( ! in_array( strtolower( $node->nodeName ), [ 'article', 'main', 'body' ], true ) ) {
			return $node;
		}

		// 1. Known content-body class patterns (relative to the broad container).
		$sub_patterns = [
			'.//*[contains(@class,"entry-content")]',
			'.//*[contains(@class,"post-content")]',
			'.//*[contains(@class,"article-content")]',
			'.//*[contains(@class,"article-body")]',
			'.//*[contains(@class,"post-body")]',
			'.//*[contains(@class,"entry-body")]',
			'.//*[contains(@class,"content-body")]',
			'.//*[contains(@class,"single-content")]',
			'.//*[contains(@class,"story-body")]',
			'.//*[contains(@class,"rich-text")]',
			'.//*[@itemprop="articleBody"]',
		];

		foreach ( $sub_patterns as $expr ) {
			$found = $xpath->query( $expr, $node );
			if ( $found && $found->length > 0 ) {
				return $found->item( 0 );
			}
		}

		// 2. Paragraph-count heuristic: first div/section with ≥ 3 direct <p> children.
		//    The article prose body is the element most likely to satisfy this threshold;
		//    header and bio blocks typically have 0–2 direct <p> children.
		$prose = $xpath->query( './/div[count(p)>=3]|.//section[count(p)>=3]', $node );
		if ( $prose && $prose->length > 0 ) {
			$candidate = $prose->item( 0 );
			// Sanity: must contain a meaningful amount of text.
			if ( strlen( $candidate->textContent ) > 200 ) {
				return $candidate;
			}
		}

		// 3. Relaxed threshold (≥ 2 direct <p> children) for shorter articles.
		$prose2 = $xpath->query( './/div[count(p)>=2]|.//section[count(p)>=2]', $node );
		if ( $prose2 && $prose2->length > 0 ) {
			$candidate = $prose2->item( 0 );
			if ( strlen( $candidate->textContent ) > 200 ) {
				return $candidate;
			}
		}

		return $node;
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
		// Comments and ad containers are stripped unconditionally because
		// they carry no article content and would pollute the post body.
		$removal_xpaths = [
			'//script',
			'//style',
			'//nav',
			// Strip only the article's own lead header (direct child of the
			// content wrapper). Section-level <header> elements nested deeper
			// inside the article body are preserved. The global site header is
			// already outside the content node and never imported.
			'//div[@id="asae-ci-wrap"]/header',
			// Strip every h1 inside the content body. The article title is
			// stored separately as the WP post_title field, so any h1 in the
			// body is always redundant. This applies regardless of nesting depth
			// (some themes wrap the h1 inside a header div rather than placing
			// it as a direct child of the article element).
			'//h1',
			'//footer',
			'//aside',
			'//form',
			// Author attribution blocks (data captured separately to user meta).
			'//*[contains(@class,"author-block")]',
			'//*[contains(@class,"author-info")]',
			'//*[contains(@class,"author-card")]',
			'//*[contains(@class,"author-bio")]',
			'//*[contains(@class,"author-box")]',
			'//*[contains(@class,"post-author")]',
			'//*[contains(@class,"entry-author")]',
			'//*[contains(@class,"author-section")]',
			'//*[contains(@class,"author-profile")]',
			'//*[contains(@class,"author-widget")]',
			'//*[contains(@class,"contributor-box")]',
			'//*[contains(@class,"wp-block-post-author")]',
			'//*[@itemprop="author"]',
			// Article-level header containers (category label + title + byline).
			// Many WP themes group these into a header div; removing the container
			// catches all three at once even when individual elements use custom classes.
			'//*[contains(@class,"entry-header")]',
			'//*[contains(@class,"post-header")]',
			'//*[contains(@class,"article-header")]',
			// Article-level metadata chrome (author/date byline, categories line).
			// These appear as direct or shallow children of the content node on
			// many WordPress themes and are redundant to the WP post meta fields.
			'//*[contains(@class,"entry-meta")]',
			'//*[contains(@class,"post-meta")]',
			'//*[contains(@class,"article-meta")]',
			'//*[contains(@class,"byline")]',
			// Inline category/tag label elements (often rendered as a styled link
			// or badge above the article title).
			'//*[contains(@class,"cat-links")]',
			'//*[contains(@class,"entry-categories")]',
			// <noscript> fallback blocks — always browser/JS chrome, never article content.
			// This catches Disqus "Please enable JavaScript" notices and similar
			// embedded service fallbacks that appear as siblings of their script tags.
			'//noscript',
			// Comment systems.
			'//*[@id="disqus_thread"]',
			'//*[contains(@class,"disqus")]',
			'//*[contains(@class,"comments-area")]',
			'//*[contains(@class,"comment-section")]',
			// Advertisement containers (Google DFP / GPT and generic).
			'//*[contains(@id,"div-gpt-ad")]',
			'//*[contains(@class,"advertisement")]',
			'//*[contains(@class,"leaderboard")]',
			'//*[contains(@class,"sharedaddy")]',
			// Social sharing widgets — AddToAny, Jetpack, and generic patterns.
			'//*[contains(@class,"addtoany_content")]',
			'//*[contains(@class,"a2a_kit")]',
			'//*[contains(@class,"share-buttons")]',
			'//*[contains(@class,"social-share")]',
			'//*[contains(@class,"entry-share")]',
			'//*[contains(@class,"post-share")]',
			// "Read These Next" / related-post link lists embedded in articles.
			'//*[contains(@class,"link-list")]',
			'//*[contains(@class,"related-posts")]',
			'//*[contains(@class,"jp-relatedposts")]',
			// Inline tag/taxonomy display blocks — data already captured by
			// extract_taxonomies() and stored as WP tags; redundant in body.
			'//*[contains(@class,"tags")]',
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
