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
	public static function ingest( array $parsed_data, string $post_type = 'post', array $extra_tags = [] ) {
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

		// Build the post array.
		$post_arr = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => sanitize_key( $post_type ),
		];

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

		// Assign one WP category by matching tags then title against existing terms.
		$has_category = self::assign_category( $post_id, $tags, $title, $post_type );
		if ( ! $has_category ) {
			// No category match – publish as draft and flag for admin review.
			wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
			update_post_meta( $post_id, '_asae_ci_needs_category', 1 );
			return new WP_Error(
				'asae_ci_needs_category',
				'No matching category found; post saved as draft.',
				[ 'post_id' => $post_id ]
			);
		}

		// Download and replace inline images in the post content.
		$updated_content = self::process_inline_images( $post_id, $content, $parsed_data['inline_images'] ?? [] );
		if ( $updated_content !== $content ) {
			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => $updated_content,
			] );
		}

		// Download and set the featured image.
		$featured_url = $parsed_data['featured_image'] ?? '';
		if ( $featured_url ) {
			$attachment_id = self::download_and_attach_image( $featured_url, $post_id, $title );
			if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}
		}

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

		return [
			'source_url'       => $source_url,
			'post_title'       => $title,
			'post_type'        => $post_type,
			'author'           => sanitize_text_field( $parsed_data['author'] ?? '' ),
			'date'             => $parsed_data['date'] ?? '',
			'tags'             => $tags,
			'has_featured'     => ! empty( $parsed_data['featured_image'] ),
			'inline_images'    => count( $parsed_data['inline_images'] ?? [] ),
			'is_duplicate'     => $is_duplicate,
			'category_match'   => $matched_category,
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
	private static function download_and_attach_image( string $image_url, int $post_id, string $title = '' ) {
		if ( empty( $image_url ) ) {
			return new WP_Error( 'asae_ci_no_image', 'No image URL provided.' );
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
	 *  Bio  : og:description → meta[name="description"]
	 *  Photo: og:image → <img> inside known author-block class patterns
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
		@$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		$xpath = new DOMXPath( $dom );

		// Bio: og:description first, then meta description.
		$og_desc = $xpath->query( '//meta[@property="og:description"]/@content' );
		if ( $og_desc && $og_desc->length ) {
			$result['bio'] = sanitize_textarea_field( $og_desc->item( 0 )->nodeValue );
		} else {
			$meta_desc = $xpath->query( '//meta[@name="description"]/@content' );
			if ( $meta_desc && $meta_desc->length ) {
				$result['bio'] = sanitize_textarea_field( $meta_desc->item( 0 )->nodeValue );
			}
		}

		// Photo: og:image first.
		$og_img = $xpath->query( '//meta[@property="og:image"]/@content' );
		if ( $og_img && $og_img->length ) {
			$result['photo_url'] = esc_url_raw( $og_img->item( 0 )->nodeValue );
		} else {
			// Fall back to image inside known author block class patterns.
			$block_classes = [ 'author-block', 'author-info', 'author-card', 'author-bio' ];
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

		return $result;
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

		// Simple Local Avatars integration.
		$local_url = wp_get_attachment_url( $attachment_id );
		if ( $local_url ) {
			update_user_meta( $user_id, 'simple_local_avatar', [ 'full' => $local_url ] );
		}
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
