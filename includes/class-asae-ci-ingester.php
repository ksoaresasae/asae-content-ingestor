<?php
/**
 * ASAE Content Ingestor – Content Ingester
 *
 * Converts a parsed article data array (produced by ASAE_CI_Parser) into a
 * WordPress post of the specified type. Handles:
 *
 *  - Duplicate detection via the stored source URL meta key.
 *  - Image downloading: inline images are downloaded to the WP media library
 *    and their src attributes are updated in the post content.
 *  - Featured image: the parsed featured image URL is downloaded, attached to
 *    the new post, and set as its thumbnail.
 *  - Tags: all extracted taxonomy labels are created/assigned as WP Tags.
 *  - Original URL: stored in post meta so it can be used as a unique marker and
 *    to avoid re-ingesting the same article in future runs.
 *
 * All remote image downloads use wp_remote_get() and wp_insert_attachment()
 * (WordPress core media functions) – no external libraries required.
 *
 * @package ASAE_Content_Ingestor
 * @since   0.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CI_Ingester {

	/** Meta key used to store the original source URL on ingested posts. */
	const SOURCE_URL_META = '_asae_ci_source_url';

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Ingests a single parsed article into WordPress.
	 *
	 * @param array    $parsed_data Parsed article data from ASAE_CI_Parser::parse().
	 * @param string   $post_type   The WP post type to create (e.g. 'post').
	 * @param string[] $extra_tags  Additional tag names to apply to every item in this batch.
	 * @return int|WP_Error New post ID on success, WP_Error('asae_ci_needs_category') if the
	 *                       post was created as a draft awaiting manual category assignment,
	 *                       or another WP_Error on failure.
	 */
	public static function ingest( array $parsed_data, string $post_type = 'post', array $extra_tags = [], string $source_type = 'replace' ) {
		$source_url = $parsed_data['source_url'] ?? '';

		if ( empty( $source_url ) ) {
			return new WP_Error( 'asae_ci_no_source', 'Source URL is missing from parsed data.' );
		}

		// Abort if a post with this URL already exists.
		if ( self::is_duplicate( $source_url ) ) {
			return new WP_Error( 'asae_ci_duplicate', 'A post with this source URL already exists.' );
		}

		$title   = sanitize_text_field( $parsed_data['title']   ?? '' ) ?: __( '(Untitled)', 'asae-content-ingestor' );
		$content = wp_kses_post( $parsed_data['content'] ?? '' );
		$date    = $parsed_data['date'] ?? '';
		$excerpt = sanitize_textarea_field( $parsed_data['excerpt'] ?? '' );

		// Build the post array.
		$post_arr = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => sanitize_key( $post_type ),
		];

		// Apply explicit excerpt when one was found; otherwise WP auto-generates it.
		if ( $excerpt ) {
			$post_arr['post_excerpt'] = $excerpt;
		}

		// Apply publication date if one was found.
		if ( $date ) {
			$post_arr['post_date']     = $date;
			$post_arr['post_date_gmt'] = get_gmt_from_date( $date );
		}

		// Provision authors – find or create one subscriber user per author name.
		// Only the first author gets the inline bio/photo context from this article.
		$authors    = $parsed_data['authors'] ?? [];
		if ( empty( $authors ) && ! empty( $parsed_data['author'] ) ) {
			$authors = [ $parsed_data['author'] ];
		}
		$author_ids = [];
		foreach ( $authors as $idx => $author_name ) {
			$author_name = sanitize_text_field( $author_name );
			if ( empty( $author_name ) ) {
				continue;
			}
			$bio_url   = 0 === $idx ? ( $parsed_data['author_bio_url']   ?? '' ) : '';
			$bio_text  = 0 === $idx ? ( $parsed_data['author_bio']       ?? '' ) : '';
			$photo_url = 0 === $idx ? ( $parsed_data['author_photo_url'] ?? '' ) : '';
			$aid = self::get_or_create_author_user(
				$author_name,
				$bio_url,
				sanitize_textarea_field( $bio_text ),
				$photo_url
			);
			if ( $aid ) {
				$author_ids[] = $aid;
			}
		}
		if ( ! empty( $author_ids ) ) {
			$post_arr['post_author'] = $author_ids[0];
		}

		// Insert the post into WordPress.
		$post_id = wp_insert_post( $post_arr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Assign all authors via Co-Authors Plus if the plugin is active.
		if ( ! empty( $author_ids ) && self::cap_is_active() ) {
			global $coauthors_plus;
			$coauthor_logins = [];
			foreach ( $author_ids as $aid ) {
				$author_user = get_user_by( 'id', $aid );
				if ( $author_user ) {
					$coauthor_logins[] = $author_user->user_login;
				}
			}
			if ( ! empty( $coauthor_logins ) ) {
				$coauthors_plus->add_coauthors( $post_id, $coauthor_logins, false );
			}
		}

		// Store the source URL as post meta (primary deduplication marker).
		update_post_meta( $post_id, self::SOURCE_URL_META, esc_url_raw( $source_url ) );

		// Merge batch-level extra tags with article tags, deduplicating.
		$tags = array_values( array_unique( array_filter(
			array_merge( $parsed_data['tags'] ?? [], $extra_tags )
		) ) );

		// Assign tags (all taxonomy values map to WP Tags per requirements).
		if ( ! empty( $tags ) ) {
			self::assign_tags( $post_id, $tags, $post_type );
		}

		// Download and replace inline images in the post content.
		// Images are processed here — before the category check — so that
		// posts saved as drafts (pending category review) still get their
		// images imported and their content src attributes updated.
		//
		// Before processing, strip any inline <img> element that is the same
		// visual as the featured image (possibly at a different generated size,
		// e.g. "photo.jpg" vs "photo-800x480.jpg"). Leaving it in would cause
		// the same image to appear twice on the rendered post: once from the
		// WP thumbnail and once inline in the content.
		$featured_url    = $parsed_data['featured_image'] ?? '';
		$inline_images   = $parsed_data['inline_images']  ?? [];
		$body_content    = $content;

		if ( $featured_url ) {
			$feat_base     = self::normalize_image_base( $featured_url );
			$body_content  = self::remove_featured_image_from_content( $body_content, $featured_url );
			// Also remove the variant URL from the inline download list so we
			// don't download it as a second media attachment.
			$inline_images = array_values( array_filter(
				$inline_images,
				fn( $img_url ) => self::normalize_image_base( $img_url ) !== $feat_base
			) );
		}

		$updated_content = self::process_inline_images( $post_id, $body_content, $inline_images );
		if ( $updated_content !== $content ) {
			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => $updated_content,
			] );
		}

		// Download and set the featured image.
		if ( $featured_url ) {
			$attachment_id = self::download_and_attach_image( $featured_url, $post_id, $title );
			if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}
		}

		// Assign one WP category by matching tags then title against existing terms.
		// Category check runs after images so drafts have complete media attached.
		$has_category = self::assign_category( $post_id, $tags, $title, $post_type );
		if ( ! $has_category ) {
			// No category match – publish as draft and flag for admin review.
			wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
			update_post_meta( $post_id, '_asae_ci_needs_category', 1 );
			// Register redirect even for drafts: the post will be published before cutover.
			self::maybe_register_redirect( $post_id, $source_url, $source_type );
			return new WP_Error(
				'asae_ci_needs_category',
				'No matching category found; post saved as draft.',
				[ 'post_id' => $post_id ]
			);
		}

		// Register redirect (or store mirror attribution URL) for this ingested post.
		self::maybe_register_redirect( $post_id, $source_url, $source_type );

		return $post_id;
	}

	/**
	 * Performs a dry-run parse: returns the parsed data without creating any posts.
	 * Used in Dry Run mode to preview what would be ingested.
	 *
	 * @param array    $parsed_data Parsed article data from ASAE_CI_Parser::parse().
	 * @param string   $post_type   The post type that would be used.
	 * @param string[] $extra_tags  Batch-level additional tags.
	 * @return array Dry-run result summary.
	 */
	public static function dry_run_preview( array $parsed_data, string $post_type = 'post', array $extra_tags = [] ): array {
		$source_url   = $parsed_data['source_url'] ?? '';
		$is_duplicate = $source_url ? self::is_duplicate( $source_url ) : false;
		$title        = sanitize_text_field( $parsed_data['title'] ?? '(Untitled)' );

		// Merge batch-level extra tags.
		$tags = array_values( array_unique( array_filter(
			array_merge( $parsed_data['tags'] ?? [], $extra_tags )
		) ) );

		// Preview which category would be matched (read-only – no post exists yet).
		$matched_category = self::find_category_match( $tags, $title, $post_type );

		// Compute the inline image count after removing featured-image duplicates,
		// to match what the active run would actually download.
		$featured_url  = $parsed_data['featured_image'] ?? '';
		$inline_images = $parsed_data['inline_images']  ?? [];
		if ( $featured_url ) {
			$feat_base     = self::normalize_image_base( $featured_url );
			$inline_images = array_values( array_filter(
				$inline_images,
				fn( $img_url ) => self::normalize_image_base( $img_url ) !== $feat_base
			) );
		}

		return [
			'source_url'       => $source_url,
			'post_title'       => $title,
			'post_type'        => $post_type,
			'author'           => sanitize_text_field( $parsed_data['author'] ?? '' ),
			'date'             => $parsed_data['date'] ?? '',
			'tags'             => $tags,
			'has_featured'     => ! empty( $featured_url ),
			'inline_images'    => count( $inline_images ),
			'is_duplicate'     => $is_duplicate,
			'category_match'   => $matched_category,
			'excerpt'          => sanitize_textarea_field( $parsed_data['excerpt'] ?? '' ),
			// Full content HTML included for the dry-run detail popup (wp_kses_post
			// sanitises to the same set of tags used during active ingestion).
			'content_html'     => wp_kses_post( $parsed_data['content'] ?? '' ),
		];
	}

	// ── Duplicate Detection ───────────────────────────────────────────────────

	/**
	 * Checks whether any WP post already has the given source URL stored
	 * in its post meta. This prevents re-ingesting the same article.
	 *
	 * @param string $source_url The original article URL.
	 * @return bool True if a post with this URL already exists.
	 */
	public static function is_duplicate( string $source_url ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta}
				 WHERE meta_key = %s AND meta_value = %s",
				self::SOURCE_URL_META,
				esc_url_raw( $source_url )
			)
		);
		// phpcs:enable

		return (int) $count > 0;
	}

	// ── Image Helpers ─────────────────────────────────────────────────────────

	/**
	 * Normalises an image URL to a base form suitable for deduplication.
	 *
	 * Strips the query string, fragment, and any WordPress-generated size
	 * suffix (e.g. "-800x480" before the extension) so that different size
	 * variants of the same source image compare as equal.
	 *
	 * Examples:
	 *   https://example.com/image-800x480.jpg?v=1  →  https://example.com/image.jpg
	 *   https://example.com/photo.jpg               →  https://example.com/photo.jpg
	 *
	 * @param string $url Absolute image URL.
	 * @return string Normalised base URL.
	 */
	private static function normalize_image_base( string $url ): string {
		$base = strtok( $url, '?#' ); // Strip query string and fragment.
		// Strip WordPress image size suffix: -NNNxNNN before the file extension.
		return (string) preg_replace( '/-\d+x\d+(\.[a-zA-Z]{2,5})$/', '$1', $base );
	}

	/**
	 * Removes <img> elements from a block of HTML whose normalised src matches
	 * the normalised base URL of the featured image.
	 *
	 * WordPress sets the featured image as a post thumbnail displayed outside
	 * the post content; any inline image in the body that is the same photo
	 * (possibly at a different generated size) would result in the image
	 * appearing twice on the rendered page. This method strips such duplicates
	 * from the content HTML before it is stored.
	 *
	 * @param string $content      Post content HTML.
	 * @param string $featured_url Absolute URL of the featured image.
	 * @return string Updated content HTML with duplicate images removed.
	 */
	private static function remove_featured_image_from_content( string $content, string $featured_url ): string {
		if ( empty( $content ) || empty( $featured_url ) ) {
			return $content;
		}

		$feat_base = self::normalize_image_base( $featured_url );
		if ( ! $feat_base ) {
			return $content;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $content . '</div>' );
		libxml_clear_errors();

		$imgs = $dom->getElementsByTagName( 'img' );
		$to_remove = [];
		foreach ( $imgs as $img ) {
			$src = trim( $img->getAttribute( 'src' ) );
			if ( $src && self::normalize_image_base( $src ) === $feat_base ) {
				$to_remove[] = $img;
			}
		}
		foreach ( $to_remove as $img ) {
			// Remove the containing <figure> if the img is its only meaningful child.
			$parent = $img->parentNode;
			if ( $parent && 'figure' === strtolower( $parent->nodeName ) ) {
				$parent->parentNode?->removeChild( $parent );
			} else {
				$parent?->removeChild( $img );
			}
		}

		// Re-serialize only if something was actually removed.
		if ( empty( $to_remove ) ) {
			return $content;
		}

		$wrapper = $dom->getElementsByTagName( 'div' )->item( 0 );
		$inner   = '';
		if ( $wrapper ) {
			foreach ( $wrapper->childNodes as $child ) {
				$inner .= $dom->saveHTML( $child );
			}
		}
		return trim( $inner );
	}

	// ── Image Handling ────────────────────────────────────────────────────────

	/**
	 * Downloads all inline images to the WP media library and replaces their
	 * src attributes in the content HTML with the new local URLs.
	 *
	 * @param int      $post_id       The post the images belong to.
	 * @param string   $content       The post content HTML.
	 * @param string[] $image_urls    Absolute URLs of images found in the content.
	 * @return string Updated content HTML with src attributes replaced.
	 */
	private static function process_inline_images( int $post_id, string $content, array $image_urls ): string {
		if ( empty( $image_urls ) || empty( $content ) ) {
			return $content;
		}

		// Build a map of original URL → new local URL.
		$url_map = [];
		foreach ( $image_urls as $image_url ) {
			if ( isset( $url_map[ $image_url ] ) ) {
				continue; // Already processed.
			}
			$attachment_id = self::download_and_attach_image( $image_url, $post_id );
			if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
				$local_url = wp_get_attachment_url( $attachment_id );
				if ( $local_url ) {
					$url_map[ $image_url ] = $local_url;
				}
			}
		}

		// Replace old src values with new local URLs in the content.
		foreach ( $url_map as $old_url => $new_url ) {
			$content = str_replace(
				[ esc_attr( $old_url ), $old_url ],
				[ esc_attr( $new_url ), $new_url ],
				$content
			);
		}

		return $content;
	}

	/**
	 * Downloads a remote image and imports it into the WordPress media library
	 * as an attachment (child of $post_id).
	 *
	 * Uses WordPress's own media_handle_sideload() (via the media.php include)
	 * for proper thumbnail generation and media library registration.
	 *
	 * @param string $image_url     Absolute URL of the image to download.
	 * @param int    $post_id       Parent post ID for the attachment.
	 * @param string $title         Optional title for the attachment.
	 * @return int|WP_Error  Attachment post ID on success, WP_Error on failure.
	 */
	public static function download_and_attach_image( string $image_url, int $post_id, string $title = '' ) {
		if ( empty( $image_url ) ) {
			return new WP_Error( 'asae_ci_no_image', 'No image URL provided.' );
		}

		// Deduplicate images within a single ingestion run.
		// The same image is sometimes referenced with different query strings
		// (e.g. ?h=440&w=780 vs ?h=200&w=200) — normalise to the base URL so
		// we download each unique image file only once per request lifecycle.
		static $url_cache = [];
		$base_url = strtok( $image_url, '?#' );
		if ( isset( $url_cache[ $base_url ] ) ) {
			return $url_cache[ $base_url ];
		}

		// Load the WP media-handling functions if not already available.
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Download the image to a temporary file using WP's HTTP API.
		$tmp = download_url( $image_url, 60 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// Determine file name from the URL.
		$filename = basename( parse_url( $image_url, PHP_URL_PATH ) ) ?: 'image';

		// Some CMSes (e.g. Sitecore, ASP.NET) serve images through handler
		// scripts (.ashx, .aspx) that have no image file extension.
		// WP's media_handle_sideload() relies on the extension to confirm
		// the MIME type; without it the sideload will fail or be rejected.
		// Detect the real type from the downloaded bytes and rename accordingly.
		if ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)$/i', $filename ) ) {
			$detected_mime = is_callable( 'mime_content_type' ) ? (string) mime_content_type( $tmp ) : '';
			$ext           = self::mime_to_extension( $detected_mime );
			if ( $ext ) {
				$base     = preg_replace( '/\.[^.]+$/', '', $filename );
				$filename = $base . '.' . $ext;
				$new_tmp  = $tmp . '.' . $ext;
				rename( $tmp, $new_tmp );
				$tmp = $new_tmp;
			}
		}

		$file_array = [
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $tmp,
		];

		// Import into the media library.
		$attachment_id = media_handle_sideload( $file_array, $post_id, $title ?: $filename );

		// Clean up the temporary file if something went wrong.
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
		}

		// Cache the result (success or error) so subsequent calls with the
		// same base URL return immediately without re-downloading.
		$url_cache[ $base_url ] = $attachment_id;

		return $attachment_id;
	}

	/**
	 * Maps a MIME type string to a file extension for common image types.
	 *
	 * Used when a downloaded image URL has no standard extension (e.g. .ashx
	 * script handlers) so that the file can be renamed before sideloading.
	 *
	 * @param string $mime_type MIME type, possibly with parameters (e.g. "image/jpeg; charset=...").
	 * @return string Extension without leading dot (e.g. "jpg"), or empty string if unrecognised.
	 */
	private static function mime_to_extension( string $mime_type ): string {
		$base = strtolower( trim( explode( ';', $mime_type )[0] ) );
		$map  = [
			'image/jpeg'    => 'jpg',
			'image/png'     => 'png',
			'image/gif'     => 'gif',
			'image/webp'    => 'webp',
			'image/avif'    => 'avif',
			'image/svg+xml' => 'svg',
		];
		return $map[ $base ] ?? '';
	}

	// ── Taxonomy / Tag Handling ───────────────────────────────────────────────

	/**
	 * Assigns an array of tag label strings to the specified post.
	 * Creates tags that do not yet exist. Per requirements, all taxonomy
	 * values (categories and tags) are applied exclusively as WP Tags.
	 *
	 * For custom post types that do not support the built-in 'post_tag'
	 * taxonomy, the tags are registered against the post type first.
	 *
	 * @param int      $post_id   The post to assign tags to.
	 * @param string[] $tags      Array of tag label strings.
	 * @param string   $post_type The post type (used for taxonomy compatibility).
	 * @return void
	 */
	private static function assign_tags( int $post_id, array $tags, string $post_type ): void {
		if ( empty( $tags ) ) {
			return;
		}

		// Sanitise each tag name.
		$sanitised = array_map( 'sanitize_text_field', $tags );
		$sanitised = array_filter( array_unique( $sanitised ) );

		if ( empty( $sanitised ) ) {
			return;
		}

		$taxonomy = 'post_tag';

		// If the post type doesn't support 'post_tag', register the relationship.
		if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
			register_taxonomy_for_object_type( $taxonomy, $post_type );
		}

		// wp_set_post_tags accepts label strings and creates tags as needed.
		wp_set_post_tags( $post_id, $sanitised, false );
	}

	// ── Category Handling ─────────────────────────────────────────────────────

	/**
	 * Attempts to assign one existing WP category term to a post.
	 *
	 * Matching order:
	 *  1. Case-insensitive comparison of each tag name against all category term names.
	 *  2. Case-insensitive comparison of title words (≥4 chars) against category term names.
	 *
	 * @param int      $post_id   The post to assign a category to.
	 * @param string[] $tags      Merged tag list (article tags + extra_tags).
	 * @param string   $title     Article title used as keyword fallback.
	 * @param string   $post_type Post type (used to identify the correct taxonomy).
	 * @return bool True if a category was matched and assigned; false if no match.
	 */
	private static function assign_category( int $post_id, array $tags, string $title, string $post_type ): bool {
		$match = self::find_category_match( $tags, $title, $post_type );
		if ( null === $match ) {
			return false;
		}

		$tax = self::get_category_taxonomy( $post_type );
		wp_set_object_terms( $post_id, [ (int) $match['term_id'] ], $tax, false );
		return true;
	}

	/**
	 * Finds the best-matching category term for a post without assigning it.
	 * Used by both ingest() (via assign_category) and dry_run_preview() for previews.
	 *
	 * @param string[] $tags      Merged tag list.
	 * @param string   $title     Article title for keyword fallback.
	 * @param string   $post_type Post type.
	 * @return array|null {term_id: int, name: string} or null if no match.
	 */
	private static function find_category_match( array $tags, string $title, string $post_type ): ?array {
		$tax = self::get_category_taxonomy( $post_type );
		if ( ! $tax ) {
			return null;
		}

		$terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		// Build a lookup map: lowercase_name → term.
		$term_map = [];
		foreach ( $terms as $term ) {
			$term_map[ strtolower( $term->name ) ] = $term;
		}

		// Pass 1: tag-name match.
		foreach ( $tags as $tag ) {
			$key = strtolower( trim( $tag ) );
			if ( isset( $term_map[ $key ] ) ) {
				$t = $term_map[ $key ];
				return [ 'term_id' => (int) $t->term_id, 'name' => $t->name ];
			}
		}

		// Pass 2: title keyword match (words ≥ 4 characters).
		$words = preg_split( '/\s+/', $title, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $words as $word ) {
			$clean = strtolower( preg_replace( '/[^a-z0-9]/i', '', $word ) );
			if ( strlen( $clean ) >= 4 && isset( $term_map[ $clean ] ) ) {
				$t = $term_map[ $clean ];
				return [ 'term_id' => (int) $t->term_id, 'name' => $t->name ];
			}
		}

		return null;
	}

	/**
	 * Returns the name of the hierarchical (category-style) taxonomy registered
	 * for the given post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return string Taxonomy name, or '' if none found.
	 */
	private static function get_category_taxonomy( string $post_type ): string {
		if ( 'post' === $post_type ) {
			return 'category';
		}
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		foreach ( $taxonomies as $tax ) {
			if ( $tax->hierarchical ) {
				return $tax->name;
			}
		}
		return '';
	}

	// ── Author Handling ───────────────────────────────────────────────────────

	/**
	 * Splits a full display name into first and last name components.
	 *
	 * Professional designations (CAE, FASAE, PhD, MBA, JD, etc.) are stripped
	 * from the end of the name before splitting so they do not get stored as
	 * the last name. A name may carry more than one designation; they are
	 * removed iteratively until none remain. Short or ambiguous codes (MA, MS)
	 * are deliberately omitted to avoid false-positive matches against real
	 * last-name fragments — if a designation is not on the list, the fallback
	 * of using the trailing word as the last name is acceptable per requirements.
	 *
	 * @param string $name Full display name, possibly including designations.
	 * @return array { first: string, last: string }
	 */
	private static function parse_name_parts( string $name ): array {
		// Designations to strip. Listed as plain strings; preg_quote() is
		// applied when building the pattern. Short 2-letter codes that double
		// as common name parts (MA, MS, etc.) are intentionally excluded.
		$designations = [
			// ASAE / association-industry specific.
			'CAE', 'FASAE',
			// Doctoral and professional degrees.
			'PhD', 'Ph.D.', 'Ph.D', 'DBA', 'EdD', 'Ed.D.', 'PsyD',
			'MD', 'M.D.', 'JD', 'J.D.', 'LLD', 'LL.D.', 'LLM', 'LL.M.',
			'DNP', 'DSW', 'ScD',
			// Graduate degrees (less ambiguous as last-name candidates).
			'MBA', 'MPA', 'MPH', 'MHA',
			// Legal suffix.
			'Esq', 'Esq.',
			// Professional certifications common in the association world.
			'CPA', 'CFA', 'CFP', 'PMP', 'CMP', 'CMM', 'CPM',
			'SPHR', 'PHR', 'SHRM-CP', 'SHRM-SCP',
		];

		// Build a regex alternation from the quoted designation strings.
		$alts    = implode( '|', array_map( fn( $d ) => preg_quote( $d, '/' ), $designations ) );
		// Pattern: optional commas/spaces before the designation at end-of-string.
		// The trailing \.? handles cases where an unlisted period follows.
		$pattern = '/[\s,]+(?:' . $alts . ')\.?$/iu';

		// Strip designations iteratively — a name can carry more than one.
		$clean = trim( $name );
		$prev  = null;
		while ( $prev !== $clean ) {
			$prev  = $clean;
			$clean = rtrim( trim( preg_replace( $pattern, '', $clean ) ), ',' );
		}

		// Safety: if stripping removed everything, use the original name.
		if ( empty( $clean ) ) {
			$clean = $name;
		}

		$parts = preg_split( '/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $parts ) < 2 ) {
			return [ 'first' => $parts[0] ?? trim( $name ), 'last' => '' ];
		}
		$last  = array_pop( $parts );
		$first = implode( ' ', $parts );
		return [ 'first' => $first, 'last' => $last ];
	}

	/**
	 * Finds or creates a WordPress subscriber user for the given author.
	 *
	 * Deduplication strategy (in priority order):
	 *  1. user_login match: 'asae-' + sanitize_title($name) — current format.
	 *  2. user_login match: 'asae-{last}-{first}' — pre-v0.3.6 reversed format.
	 *  3. user_login match: 'asae-ci-' + sanitize_title($name) — pre-v0.3.4 format.
	 *  4. _asae_ci_author_name user-meta match (catch-all fallback).
	 *  5. Create a new subscriber user if no match is found.
	 *
	 * After creating a new user, the method optionally:
	 *  - Fetches the author's bio page (one HTTP request max) for additional data.
	 *  - Downloads and stores the author's photo as a WP media attachment.
	 *
	 * New users are created with the 'subscriber' role only — they have no
	 * publish, edit, delete, or admin capabilities.
	 *
	 * @param string $name      Display name of the author.
	 * @param string $bio_url   URL to the author's bio/profile page (may be empty).
	 * @param string $bio_text  Bio text extracted inline from the article.
	 * @param string $photo_url URL to the author's photo (may be empty).
	 * @return int WP user ID, or 0 on failure / no name.
	 */
	private static function get_or_create_author_user( string $name, string $bio_url, string $bio_text, string $photo_url ): int {
		if ( empty( $name ) ) {
			return 0;
		}

		// Build the login slug: asae-{full-name-in-display-order}.
		// Using the full name in its natural order avoids incorrectly treating
		// professional designations (CAE, FASAE, PhD, etc.) as last names.
		$login      = 'asae-' . sanitize_title( $name );
		$name_parts = self::parse_name_parts( $name );
		$first      = $name_parts['first'];
		$last       = $name_parts['last'];

		// 1. Check by current slug format.
		$user = get_user_by( 'login', $login );

		// 2. Backward-compat: check reversed slug format (pre-v0.3.6 users).
		if ( ! $user ) {
			$slug_parts    = array_filter( [ sanitize_title( $last ), sanitize_title( $first ) ] );
			$reversed_login = 'asae-' . ( ! empty( $slug_parts ) ? implode( '-', $slug_parts ) : sanitize_title( $name ) );
			$user           = get_user_by( 'login', $reversed_login );
		}

		// 3. Backward-compat: check oldest slug format (pre-v0.3.4 users).
		if ( ! $user ) {
			$old_login = 'asae-ci-' . sanitize_title( $name );
			$user      = get_user_by( 'login', $old_login );
		}

		// 4. Fall back to meta-based lookup.
		if ( ! $user ) {
			$found = get_users( [
				'meta_key'   => '_asae_ci_author_name',
				'meta_value' => $name,
				'number'     => 1,
			] );
			if ( ! empty( $found ) ) {
				$user = $found[0];
			}
		}

		// 5. Create a new subscriber user.
		if ( ! $user ) {
			$user_id = wp_insert_user( [
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'display_name' => $name,
				'first_name'   => $first,
				'last_name'    => $last,
				'role'         => 'subscriber',
			] );

			if ( is_wp_error( $user_id ) ) {
				return 0;
			}

			update_user_meta( $user_id, '_asae_ci_author_name', $name );

			// Optionally enrich from bio page (one-level follow, no recursion).
			if ( $bio_url ) {
				update_user_meta( $user_id, '_asae_ci_source_bio_url', esc_url_raw( $bio_url ) );
				$page_data = self::fetch_author_bio_page( $bio_url );
				if ( ! empty( $page_data['bio'] ) && empty( $bio_text ) ) {
					$bio_text = $page_data['bio'];
				}
				if ( ! empty( $page_data['photo_url'] ) && empty( $photo_url ) ) {
					$photo_url = $page_data['photo_url'];
				}
			}

			// Store bio as WP user description.
			if ( $bio_text ) {
				update_user_meta( $user_id, 'description', $bio_text );
			}

			// Download and store author photo.
			if ( $photo_url ) {
				self::set_author_photo( $user_id, $photo_url, $name );
			}

			$user = get_user_by( 'id', $user_id );
		}

		return $user ? (int) $user->ID : 0;
	}

	/**
	 * Fetches an author's bio/profile page and extracts bio text and a photo URL.
	 * Makes at most one HTTP request (no recursive following).
	 *
	 * Extraction priority:
	 *
	 *  Bio  : 1. JSON-LD Person entity "description" field (most structured)
	 *         2. itemprop="description" element text
	 *         3. Explicit bio sub-element classes (author-description,
	 *            wp-block-post-author__bio, user-description, etc.)
	 *         4. First <p> inside a recognised author block container
	 *         5. og:description (fallback — often the site tagline, not a bio)
	 *         6. meta[name="description"]
	 *
	 *  Photo: 1. JSON-LD Person entity "image" field
	 *         2. img[itemprop="image"] or itemprop="image" containing img
	 *         3. <img class="avatar"> excluding default Gravatar placeholder
	 *         4. Recognised avatar/photo sub-element class patterns
	 *         5. Any <img> inside a recognised author block container
	 *         6. og:image (fallback — often the article or site image)
	 *
	 * @param string $bio_url Absolute URL of the author's profile page.
	 * @return array { bio: string, photo_url: string }
	 */
	private static function fetch_author_bio_page( string $bio_url ): array {
		$result = [ 'bio' => '', 'photo_url' => '' ];

		if ( empty( $bio_url ) ) {
			return $result;
		}

		$response = wp_remote_get( $bio_url, [ 'timeout' => 15, 'sslverify' => false ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $result;
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return $result;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );

		// ── Bio extraction ────────────────────────────────────────────────────

		// Priority 1: JSON-LD Person entity "description".
		$ld_scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		if ( $ld_scripts ) {
			foreach ( $ld_scripts as $script ) {
				$json = json_decode( trim( $script->nodeValue ), true );
				if ( ! is_array( $json ) ) {
					continue;
				}
				$person = self::find_jsonld_person( $json );
				if ( $person ) {
					if ( ! empty( $person['description'] ) ) {
						$result['bio'] = sanitize_textarea_field( $person['description'] );
					}
					// Extract photo from JSON-LD while we're here.
					if ( empty( $result['photo_url'] ) ) {
						$img = $person['image'] ?? null;
						if ( is_string( $img ) && $img ) {
							$result['photo_url'] = esc_url_raw( $img );
						} elseif ( is_array( $img ) ) {
							$src = $img['url'] ?? $img['contentUrl'] ?? '';
							if ( $src ) {
								$result['photo_url'] = esc_url_raw( $src );
							}
						}
					}
					if ( $result['bio'] ) {
						break;
					}
				}
			}
		}

		// Priority 2: itemprop="description".
		if ( empty( $result['bio'] ) ) {
			$nodes = $xpath->query( '//*[@itemprop="description"]' );
			if ( $nodes && $nodes->length ) {
				$text = trim( $nodes->item( 0 )->textContent );
				if ( $text ) {
					$result['bio'] = sanitize_textarea_field( $text );
				}
			}
		}

		// Priority 3: explicit bio sub-element class patterns.
		if ( empty( $result['bio'] ) ) {
			$bio_subclasses = [
				'author-description',
				'author__description',
				'wp-block-post-author__bio',
				'user-description',
				'author-bio-text',
				'bio-text',
			];
			foreach ( $bio_subclasses as $cls ) {
				$nodes = $xpath->query( '//*[contains(@class,"' . $cls . '")]' );
				if ( $nodes && $nodes->length ) {
					$text = trim( $nodes->item( 0 )->textContent );
					if ( $text ) {
						$result['bio'] = sanitize_textarea_field( $text );
						break;
					}
				}
			}
		}

		// Priority 4: first <p> inside a recognised author block container.
		if ( empty( $result['bio'] ) ) {
			$block_classes = [
				'author-block', 'author-info', 'author-card', 'author-bio',
				'author-box', 'post-author', 'author-section', 'author-profile',
				'author-widget', 'contributor-box', 'wp-block-post-author',
			];
			foreach ( $block_classes as $cls ) {
				$paras = $xpath->query( '//*[contains(@class,"' . $cls . '")]//p' );
				if ( $paras && $paras->length ) {
					$text = trim( $paras->item( 0 )->textContent );
					if ( $text ) {
						$result['bio'] = sanitize_textarea_field( $text );
						break;
					}
				}
			}
		}

		// Priority 5: og:description (often site tagline — last resort).
		if ( empty( $result['bio'] ) ) {
			$og_desc = $xpath->query( '//meta[@property="og:description"]/@content' );
			if ( $og_desc && $og_desc->length ) {
				$result['bio'] = sanitize_textarea_field( $og_desc->item( 0 )->nodeValue );
			}
		}

		// Priority 6: meta description.
		if ( empty( $result['bio'] ) ) {
			$meta_desc = $xpath->query( '//meta[@name="description"]/@content' );
			if ( $meta_desc && $meta_desc->length ) {
				$result['bio'] = sanitize_textarea_field( $meta_desc->item( 0 )->nodeValue );
			}
		}

		// ── Photo extraction ──────────────────────────────────────────────────

		// Priority 1: JSON-LD already handled above during bio extraction.

		// Priority 2: img[itemprop="image"] or itemprop="image" containing img.
		if ( empty( $result['photo_url'] ) ) {
			$nodes = $xpath->query( '//img[@itemprop="image"]/@src | //*[@itemprop="image"]//img/@src' );
			if ( $nodes && $nodes->length ) {
				$src = trim( $nodes->item( 0 )->nodeValue );
				if ( $src ) {
					$result['photo_url'] = esc_url_raw( $src );
				}
			}
		}

		// Priority 3: <img class="avatar"> — standard WP Gravatar class.
		// Skip the default/fallback Gravatar (all-zero MD5 hash).
		if ( empty( $result['photo_url'] ) ) {
			$avatars = $xpath->query( '//img[contains(@class,"avatar")]/@src' );
			if ( $avatars && $avatars->length ) {
				foreach ( $avatars as $attr ) {
					$src = trim( $attr->nodeValue );
					// Skip default Gravatar placeholder (MD5 of empty string or zeros).
					if ( $src && false === strpos( $src, 'gravatar.com/avatar/00000' )
						&& false === strpos( $src, 'gravatar.com/avatar/d41d' ) ) {
						$result['photo_url'] = esc_url_raw( $src );
						break;
					}
				}
			}
		}

		// Priority 4: recognised avatar/photo sub-element class patterns.
		if ( empty( $result['photo_url'] ) ) {
			$photo_xpaths = [
				'//*[contains(@class,"wp-block-post-author__avatar")]//img/@src',
				'//*[contains(@class,"author-avatar")]//img/@src',
				'//*[contains(@class,"author-photo")]//img/@src',
				'//*[contains(@class,"author-image")]//img/@src',
				'//*[contains(@class,"user-avatar")]//img/@src',
			];
			foreach ( $photo_xpaths as $expr ) {
				$nodes = $xpath->query( $expr );
				if ( $nodes && $nodes->length ) {
					$src = trim( $nodes->item( 0 )->nodeValue );
					if ( $src ) {
						$result['photo_url'] = esc_url_raw( $src );
						break;
					}
				}
			}
		}

		// Priority 5: any <img> inside a recognised author block container.
		if ( empty( $result['photo_url'] ) ) {
			$block_classes = [
				'author-block', 'author-info', 'author-card', 'author-bio',
				'author-box', 'post-author', 'author-section', 'author-profile',
				'author-widget', 'contributor-box', 'wp-block-post-author',
			];
			foreach ( $block_classes as $cls ) {
				$imgs = $xpath->query( '//*[contains(@class,"' . $cls . '")]//img/@src' );
				if ( $imgs && $imgs->length ) {
					$src = trim( $imgs->item( 0 )->nodeValue );
					if ( $src ) {
						$result['photo_url'] = esc_url_raw( $src );
						break;
					}
				}
			}
		}

		// Priority 6: og:image (often the article or site image, not a headshot).
		if ( empty( $result['photo_url'] ) ) {
			$og_img = $xpath->query( '//meta[@property="og:image"]/@content' );
			if ( $og_img && $og_img->length ) {
				$result['photo_url'] = esc_url_raw( $og_img->item( 0 )->nodeValue );
			}
		}

		return $result;
	}

	/**
	 * Recursively searches a decoded JSON-LD object for a Person entity.
	 *
	 * Handles @graph arrays, flat top-level objects, and nested typed entities
	 * (e.g. Yoast/RankMath WebPage → author → Person).
	 *
	 * @param array $data Decoded JSON-LD object.
	 * @return array|null Person object array, or null if not found.
	 */
	private static function find_jsonld_person( array $data ): ?array {
		// Expand @graph arrays.
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$found = self::find_jsonld_person( $item );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		// Direct Person type.
		if ( isset( $data['@type'] ) && $data['@type'] === 'Person' ) {
			return $data;
		}

		// Recurse into nested typed entities (e.g. mainEntity, author, etc.).
		foreach ( $data as $key => $value ) {
			if ( '@graph' === $key ) {
				continue;
			}
			if ( is_array( $value ) && ! empty( $value['@type'] ) ) {
				$found = self::find_jsonld_person( $value );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Downloads a remote author photo and stores it in the WP media library.
	 *
	 * Stores the attachment ID in '_asae_ci_author_photo_id' user meta and sets
	 * 'simple_local_avatar' user meta for Simple Local Avatars plugin compatibility.
	 * Skips the download if a photo was already stored for this user.
	 *
	 * @param int    $user_id   The WP user ID.
	 * @param string $photo_url Absolute URL of the author's photo.
	 * @param string $name      Author display name (used as attachment title).
	 * @return void
	 */
	private static function set_author_photo( int $user_id, string $photo_url, string $name ): void {
		if ( empty( $photo_url ) || ! $user_id ) {
			return;
		}

		// Don't re-download if a photo is already stored.
		if ( get_user_meta( $user_id, '_asae_ci_author_photo_id', true ) ) {
			return;
		}

		// post_id = 0 because the attachment belongs to the user, not a post.
		$attachment_id = self::download_and_attach_image( $photo_url, 0, $name );
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return;
		}

		update_user_meta( $user_id, '_asae_ci_author_photo_id', $attachment_id );

		// Simple Local Avatars integration (v2.x stores the attachment ID, not the URL).
		// The plugin retrieves the URL dynamically via wp_get_attachment_image_src().
		update_user_meta( $user_id, 'simple_local_avatar', [ 'full' => $attachment_id ] );
	}

	// ── Redirect Registration ─────────────────────────────────────────────────

	/**
	 * Handles redirect or attribution logic after a post is successfully ingested.
	 *
	 * 'replace' source type:
	 *  - associationsnow.com URLs → 301 redirect written directly to the
	 *    Redirection plugin's DB table (group: "Associations Now redirects").
	 *  - asaecenter.org URLs → source URL already in _asae_ci_source_url;
	 *    no redirect on this site (exported via the Reports page for import
	 *    on the asaecenter WP site).
	 *  - All other domains → no action (original is being retired but is not
	 *    a domain managed by this WP install).
	 *
	 * 'mirror' source type:
	 *  - Stores the original URL in '_asae_ci_mirror_url' post meta for
	 *    attribution display; no redirect is created.
	 *
	 * @param int    $post_id     The newly created WP post ID.
	 * @param string $source_url  The original article URL.
	 * @param string $source_type 'replace' or 'mirror'.
	 * @return void
	 */
	private static function maybe_register_redirect( int $post_id, string $source_url, string $source_type ): void {
		if ( 'mirror' === $source_type ) {
			update_post_meta( $post_id, '_asae_ci_mirror_url', esc_url_raw( $source_url ) );
			return;
		}

		// 'replace': only auto-populate Redirection for associationsnow.com.
		// asaecenter.org redirects are exported separately for the other WP site.
		$host = strtolower( (string) parse_url( $source_url, PHP_URL_HOST ) );
		$host = ltrim( $host, 'www.' );
		if ( 'associationsnow.com' !== $host ) {
			return;
		}

		global $wpdb;
		$items_table  = $wpdb->prefix . 'redirection_items';
		$groups_table = $wpdb->prefix . 'redirection_groups';

		// Guard: verify the Redirection plugin tables exist before writing.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$table_exists = ( $wpdb->get_var( "SHOW TABLES LIKE '{$items_table}'" ) === $items_table );
		// phpcs:enable
		if ( ! $table_exists ) {
			return; // Redirection plugin not installed – skip silently.
		}

		$source_path = (string) parse_url( $source_url, PHP_URL_PATH );
		if ( empty( $source_path ) ) {
			return;
		}

		// Dedup: skip if a redirect for this path already exists.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$items_table} WHERE url = %s LIMIT 1", $source_path )
		);
		// phpcs:enable
		if ( $existing ) {
			return;
		}

		$group_id   = self::get_or_create_redirection_group( 'Associations Now redirects', $groups_table );
		$target_url = get_permalink( $post_id );
		if ( ! $target_url || ! $group_id ) {
			return;
		}

		self::write_redirection_row( $source_path, $target_url, $group_id, $items_table );
	}

	/**
	 * Finds or creates a redirect group in the Redirection plugin's groups table.
	 *
	 * @param string $group_name  Display name for the group.
	 * @param string $groups_table Full table name including WP prefix.
	 * @return int Group ID, or 0 on failure.
	 */
	private static function get_or_create_redirection_group( string $group_name, string $groups_table ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$group_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$groups_table} WHERE name = %s LIMIT 1", $group_name )
		);

		if ( $group_id ) {
			return (int) $group_id;
		}

		$wpdb->insert(
			$groups_table,
			[
				'name'      => $group_name,
				'status'    => 'enabled',
				'module_id' => 1,   // 1 = WordPress module in Redirection plugin.
				'position'  => 0,
			],
			[ '%s', '%s', '%d', '%d' ]
		);
		// phpcs:enable

		return (int) $wpdb->insert_id;
	}

	/**
	 * Inserts a single 301 redirect row into the Redirection plugin's items table.
	 *
	 * @param string $source_path  URL path being redirected (no host).
	 * @param string $target_url   Full destination URL.
	 * @param int    $group_id     Redirection group ID.
	 * @param string $items_table  Full table name including WP prefix.
	 * @return void
	 */
	private static function write_redirection_row( string $source_path, string $target_url, int $group_id, string $items_table ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$items_table,
			[
				'url'         => $source_path,
				'title'       => '',
				'action_type' => 'url',
				'action_code' => 301,
				'action_data' => esc_url_raw( $target_url ),
				'match_type'  => 'url',
				'regex'       => 0,
				'group_id'    => $group_id,
				'status'      => 'enabled',
				'last_count'  => 0,
				'last_access' => '2000-01-01 00:00:00',
				'position'    => 0,
				'hits'        => 0,
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%d' ]
		);
		// phpcs:enable
	}

	/**
	 * Checks whether the Co-Authors Plus plugin is active and its global
	 * $coauthors_plus object is available for API calls.
	 *
	 * @return bool True if Co-Authors Plus is usable.
	 */
	public static function cap_is_active(): bool {
		global $coauthors_plus;
		return ! empty( $coauthors_plus )
			&& is_object( $coauthors_plus )
			&& ( class_exists( 'CoAuthors_Plus' ) || function_exists( 'get_coauthors' ) );
	}
}
