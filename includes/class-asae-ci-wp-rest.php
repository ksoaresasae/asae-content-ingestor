<?php
/**
 * ASAE Content Ingestor – WordPress REST API Feed Generator
 *
 * Fetches all posts from a remote WordPress site via its REST API
 * (wp-json/wp/v2/) and generates a standard Atom XML feed file that
 * can be consumed by the plugin's existing RSS/Atom ingestion pipeline.
 *
 * Also produces a companion JSON sidecar (`wp-rest-authors.json`) with
 * full author metadata (bio, photo, email, website, profile page URL)
 * that the scheduler merges into feed_metadata during ingestion.
 *
 * Authentication is optional: WordPress Application Passwords unlock
 * the /wp/v2/users endpoint for richer author data. Credentials are
 * stored in a 1-hour transient and never saved permanently.
 *
 * @package ASAE_Content_Ingestor
 * @since   0.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CI_WP_REST {

	/** Maximum results per REST API request (WP cap is 100). */
	const API_PAGE_SIZE = 100;

	/** WP option key for the stored remote site URL. */
	const OPTION_SITE_URL = 'asae_ci_wp_rest_site_url';

	/** Transient key for session-only credentials. */
	const TRANSIENT_CREDS = 'asae_ci_wp_rest_creds';

	/** Transient key for accumulated posts during chunked generation. */
	const TRANSIENT_POSTS = 'asae_ci_wp_rest_posts';

	/** Transient key for resolved lookups during chunked generation. */
	const TRANSIENT_LOOKUPS = 'asae_ci_wp_rest_lookups';

	/** Transient key for generation progress. */
	const TRANSIENT_PROGRESS = 'asae_ci_wp_rest_progress';

	/** Directory inside wp-content/uploads where feed files are stored. */
	const UPLOAD_SUBDIR = 'asae-ci';

	/** Filename for the generated feed. */
	const FEED_FILENAME = 'wp-rest-feed.xml';

	/** Filename for the author metadata sidecar. */
	const AUTHORS_FILENAME = 'wp-rest-authors.json';

	/** Internal WP types to exclude from the content type list. */
	const EXCLUDED_TYPES = [
		'attachment',
		'nav_menu_item',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
		'feedback',
		'guest-author',
		'pmb_content',
		'jp_pay_order',
		'jp_pay_product',
	];

	// ── Authentication ───────────────────────────────────────────────────────

	/**
	 * Stores API credentials in a short-lived transient.
	 *
	 * @param string $username     WordPress username on the remote site.
	 * @param string $app_password WordPress Application Password.
	 */
	public static function store_credentials( string $username, string $app_password ): void {
		$token = base64_encode( $username . ':' . $app_password );
		set_transient( self::TRANSIENT_CREDS, $token, HOUR_IN_SECONDS );
	}

	/**
	 * Returns HTTP headers for authenticated requests, or empty array.
	 *
	 * @return array Headers array for wp_remote_get().
	 */
	public static function get_auth_headers(): array {
		$token = get_transient( self::TRANSIENT_CREDS );
		if ( ! $token ) {
			return [];
		}
		return [ 'Authorization' => 'Basic ' . $token ];
	}

	/**
	 * Clears stored credentials.
	 */
	public static function clear_credentials(): void {
		delete_transient( self::TRANSIENT_CREDS );
	}

	/**
	 * Returns true if credentials are currently stored.
	 *
	 * @return bool
	 */
	public static function has_credentials(): bool {
		return (bool) get_transient( self::TRANSIENT_CREDS );
	}

	// ── Content Type Discovery ───────────────────────────────────────────────

	/**
	 * Discovers available post types on a remote WordPress site.
	 *
	 * @param string $site_url Base URL of the WordPress site.
	 * @return array|WP_Error Array of post type objects on success.
	 */
	public static function discover_post_types( string $site_url ) {
		$url = trailingslashit( $site_url ) . 'wp-json/wp/v2/types';

		$response = wp_remote_get( $url, [
			'timeout' => 30,
			'headers' => self::get_auth_headers(),
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'asae_ci_wp_rest_request_failed',
				sprintf( 'REST API request failed: %s', $response->get_error_message() )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'asae_ci_wp_rest_api_error',
				sprintf( 'REST API error (HTTP %d). Is this a WordPress site with the REST API enabled?', $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'asae_ci_wp_rest_invalid', 'Invalid JSON response from REST API.' );
		}

		$types = [];
		foreach ( $body as $slug => $type_data ) {
			if ( in_array( $slug, self::EXCLUDED_TYPES, true ) ) {
				continue;
			}

			$rest_base = $type_data['rest_base'] ?? '';
			if ( empty( $rest_base ) ) {
				continue;
			}

			// Only include types that have a viewable REST namespace.
			$types[ $slug ] = [
				'slug'        => $slug,
				'name'        => $type_data['name'] ?? $slug,
				'rest_base'   => $rest_base,
				'description' => $type_data['description'] ?? '',
			];
		}

		return $types;
	}

	/**
	 * Fetches the total count of items for a given REST base endpoint.
	 *
	 * @param string $site_url  Base URL of the WordPress site.
	 * @param string $rest_base REST base slug (e.g. 'posts', 'deep-dives').
	 * @return int Total count, or 0 if unavailable.
	 */
	public static function fetch_type_count( string $site_url, string $rest_base ): int {
		$url = trailingslashit( $site_url ) . 'wp-json/wp/v2/' . $rest_base . '?per_page=1';

		$response = wp_remote_get( $url, [
			'timeout' => 15,
			'headers' => self::get_auth_headers(),
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return 0;
		}

		$total = wp_remote_retrieve_header( $response, 'x-wp-total' );
		return $total ? (int) $total : 0;
	}

	// ── Post Fetching ────────────────────────────────────────────────────────

	/**
	 * Fetches one page of posts from a REST API endpoint.
	 *
	 * @param string $site_url  Base URL of the WordPress site.
	 * @param string $rest_base REST base slug.
	 * @param int    $page      Page number (1-based).
	 * @return array { posts: array, total_pages: int } or WP_Error.
	 */
	public static function fetch_posts_page( string $site_url, string $rest_base, int $page = 1 ) {
		$url = add_query_arg( [
			'per_page' => self::API_PAGE_SIZE,
			'page'     => $page,
			'_fields'  => 'id,title,link,date,author,categories,tags,excerpt,featured_media,type',
			'orderby'  => 'date',
			'order'    => 'asc',
		], trailingslashit( $site_url ) . 'wp-json/wp/v2/' . $rest_base );

		$response = wp_remote_get( $url, [
			'timeout' => 30,
			'headers' => self::get_auth_headers(),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			// Page beyond total returns 400 — treat as empty.
			if ( 400 === $code ) {
				return [ 'posts' => [], 'total_pages' => $page - 1 ];
			}
			return new WP_Error(
				'asae_ci_wp_rest_fetch_error',
				sprintf( 'REST API error fetching %s page %d (HTTP %d).', $rest_base, $page, $code )
			);
		}

		$total_pages = (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' );
		if ( ! $total_pages ) {
			// Fallback: if fewer than page size, this is the last page.
			$total_pages = count( $body ) < self::API_PAGE_SIZE ? $page : $page + 1;
		}

		return [
			'posts'       => $body,
			'total_pages' => $total_pages,
		];
	}

	// ── Lookup Resolution ────────────────────────────────────────────────────

	/**
	 * Resolves user, category, and tag IDs to names/metadata.
	 *
	 * @param string $site_url Base URL of the WordPress site.
	 * @return array { users: array, categories: array, tags: array }
	 */
	public static function resolve_lookups( string $site_url ): array {
		$lookups = [
			'users'      => self::fetch_all_users( $site_url ),
			'categories' => self::fetch_all_terms( $site_url, 'categories' ),
			'tags'       => self::fetch_all_terms( $site_url, 'tags' ),
		];
		return $lookups;
	}

	/**
	 * Fetches all users from the REST API (requires authentication).
	 *
	 * @param string $site_url Base URL.
	 * @return array [ id => { name, description, avatar_urls, url, email, link } ]
	 */
	private static function fetch_all_users( string $site_url ): array {
		$users = [];
		$page  = 1;

		// Try context=edit first (returns email), fall back to context=view.
		// context=edit requires edit_users capability which many Application
		// Password setups don't grant; context=view works with basic auth and
		// still returns name, description, avatar_urls, url, and link.
		$contexts = [ 'edit', 'view' ];
		$auth     = self::get_auth_headers();

		foreach ( $contexts as $context ) {
			$page  = 1;
			$users = [];

			do {
				$url = add_query_arg( [
					'per_page' => 100,
					'page'     => $page,
					'context'  => $context,
				], trailingslashit( $site_url ) . 'wp-json/wp/v2/users' );

				$response = wp_remote_get( $url, [
					'timeout' => 30,
					'headers' => $auth,
				] );

				if ( is_wp_error( $response ) ) {
					break 2; // Network error — give up entirely.
				}

				$code = wp_remote_retrieve_response_code( $response );

				// 401/403 = permission denied for this context.
				if ( 401 === $code || 403 === $code ) {
					break; // Try next context.
				}

				if ( 200 !== $code ) {
					break;
				}

				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! is_array( $body ) || empty( $body ) ) {
					break;
				}

				foreach ( $body as $user ) {
					$uid = (int) ( $user['id'] ?? 0 );
					if ( ! $uid ) {
						continue;
					}

					// description is a string in context=view, but
					// { raw, rendered } object in context=edit.
					$desc = $user['description'] ?? '';
					if ( is_array( $desc ) ) {
						$desc = $desc['raw'] ?? $desc['rendered'] ?? '';
					}

					$users[ $uid ] = [
						'name'        => $user['name'] ?? '',
						'description' => $desc,
						'avatar_urls' => $user['avatar_urls'] ?? [],
						'url'         => $user['url'] ?? '',
						'email'       => $user['email'] ?? '',
						'link'        => $user['link'] ?? '',
					];
				}

				$total_pages = (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' );
				$page++;

			} while ( $page <= $total_pages );

			// If we got users with this context, we're done.
			if ( ! empty( $users ) ) {
				break;
			}
		}

		return $users;
	}

	/**
	 * Fetches all terms (categories or tags) from the REST API.
	 *
	 * @param string $site_url  Base URL.
	 * @param string $taxonomy  'categories' or 'tags'.
	 * @return array [ id => name ]
	 */
	private static function fetch_all_terms( string $site_url, string $taxonomy ): array {
		$terms = [];
		$page  = 1;

		do {
			$url = add_query_arg( [
				'per_page' => 100,
				'page'     => $page,
				'_fields'  => 'id,name',
			], trailingslashit( $site_url ) . 'wp-json/wp/v2/' . $taxonomy );

			$response = wp_remote_get( $url, [
				'timeout' => 30,
			] );

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				break;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) || empty( $body ) ) {
				break;
			}

			foreach ( $body as $term ) {
				$tid = (int) ( $term['id'] ?? 0 );
				if ( $tid ) {
					$terms[ $tid ] = $term['name'] ?? '';
				}
			}

			$total_pages = (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' );
			$page++;

		} while ( $page <= $total_pages );

		return $terms;
	}

	// ── Feed Generation ──────────────────────────────────────────────────────

	/**
	 * Generates a valid Atom XML feed string from an array of REST API post objects.
	 *
	 * @param array  $posts      Array of post objects from the REST API.
	 * @param array  $lookups    Resolved lookups { users, categories, tags }.
	 * @param string $site_title Title for the feed.
	 * @return string Atom XML string.
	 */
	public static function generate_feed( array $posts, array $lookups, string $site_title ): string {
		$now    = gmdate( 'Y-m-d\TH:i:s\Z' );
		$users  = $lookups['users']      ?? [];
		$cats   = $lookups['categories'] ?? [];
		$tags   = $lookups['tags']       ?? [];

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:asae="urn:asae-ci:feed-extensions">' . "\n";
		$xml .= '  <title>' . self::xml_escape( $site_title ?: 'WordPress Site' ) . '</title>' . "\n";
		$xml .= '  <updated>' . $now . '</updated>' . "\n";
		$xml .= '  <id>urn:asae-ci:wp-rest-feed</id>' . "\n";
		$xml .= '  <generator>ASAE Content Ingestor</generator>' . "\n";

		foreach ( $posts as $post ) {
			$link      = $post['link'] ?? '';
			$title     = html_entity_decode( $post['title']['rendered'] ?? '(untitled)', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$date      = $post['date'] ?? $now;
			$post_type = $post['type'] ?? 'post';
			$author_id = (int) ( $post['author'] ?? 0 );

			// Resolve author name.
			$author_name = '';
			if ( $author_id && isset( $users[ $author_id ] ) ) {
				$author_name = $users[ $author_id ]['name'] ?? '';
			}

			// Resolve categories.
			$cat_ids = $post['categories'] ?? [];
			$cat_names = [];
			foreach ( $cat_ids as $cid ) {
				if ( isset( $cats[ $cid ] ) ) {
					$cat_names[] = $cats[ $cid ];
				}
			}

			// Resolve tags.
			$tag_ids = $post['tags'] ?? [];
			foreach ( $tag_ids as $tid ) {
				if ( isset( $tags[ $tid ] ) ) {
					$cat_names[] = $tags[ $tid ];
				}
			}

			// Excerpt: strip HTML tags and decode entities (the REST API returns
			// HTML-encoded excerpts like &#8217; which would double-encode in XML).
			$excerpt = '';
			if ( ! empty( $post['excerpt']['rendered'] ) ) {
				$excerpt = wp_strip_all_tags( $post['excerpt']['rendered'] );
				$excerpt = html_entity_decode( $excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$excerpt = trim( $excerpt );
			}

			$xml .= "  <entry>\n";
			$xml .= '    <id>' . self::xml_escape( $link ?: 'urn:wp-rest:' . ( $post['id'] ?? 0 ) ) . '</id>' . "\n";
			$xml .= '    <title>' . self::xml_escape( $title ) . '</title>' . "\n";

			if ( $link ) {
				$xml .= '    <link rel="alternate" href="' . esc_url( $link ) . '"/>' . "\n";
			}

			$xml .= '    <published>' . self::xml_escape( $date ) . '</published>' . "\n";
			$xml .= '    <updated>' . self::xml_escape( $date ) . '</updated>' . "\n";

			if ( $author_name ) {
				$xml .= '    <author><name>' . self::xml_escape( $author_name ) . '</name></author>' . "\n";
			}

			foreach ( $cat_names as $cat_name ) {
				$xml .= '    <category term="' . self::xml_escape( $cat_name ) . '"/>' . "\n";
			}

			if ( $excerpt ) {
				$xml .= '    <summary type="text">' . self::xml_escape( $excerpt ) . '</summary>' . "\n";
			}

			// Custom element: source site post type (always included for visibility).
			$xml .= '    <asae:remote-type>' . self::xml_escape( $post_type ) . '</asae:remote-type>' . "\n";

			$xml .= "  </entry>\n";
		}

		$xml .= '</feed>' . "\n";

		return $xml;
	}

	/**
	 * Generates the author metadata sidecar JSON.
	 *
	 * @param array $posts   Array of post objects from the REST API.
	 * @param array $lookups Resolved lookups { users, categories, tags }.
	 * @return array Per-URL author metadata map.
	 */
	public static function generate_author_metadata( array $posts, array $lookups ): array {
		$users    = $lookups['users'] ?? [];
		$metadata = [];

		foreach ( $posts as $post ) {
			$link      = $post['link'] ?? '';
			$author_id = (int) ( $post['author'] ?? 0 );

			if ( empty( $link ) || ! $author_id || ! isset( $users[ $author_id ] ) ) {
				continue;
			}

			$user = $users[ $author_id ];

			// Pick the largest avatar URL (96px preferred).
			$avatar_urls = $user['avatar_urls'] ?? [];
			$photo_url   = $avatar_urls['96'] ?? $avatar_urls['48'] ?? $avatar_urls['24'] ?? '';

			$metadata[ $link ] = [
				'author'           => $user['name'] ?? '',
				'author_bio'       => $user['description'] ?? '',
				'author_photo_url' => $photo_url,
				'author_bio_url'   => $user['link'] ?? '',
				'author_email'     => $user['email'] ?? '',
				'author_url'       => $user['url'] ?? '',
			];
		}

		return $metadata;
	}

	// ── File Operations ──────────────────────────────────────────────────────

	/**
	 * Saves the generated Atom XML feed to the uploads directory.
	 *
	 * @param string $xml The Atom XML string.
	 * @return string|WP_Error Public URL of the saved file, or WP_Error.
	 */
	public static function save_feed( string $xml ) {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'asae_ci_upload_error', $upload_dir['error'] );
		}

		$dir  = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR;
		$file = trailingslashit( $dir ) . self::FEED_FILENAME;

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'asae_ci_dir_error',
				'Could not create the upload directory for the WP REST feed.'
			);
		}

		// Write atomically.
		$tmp = $file . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $tmp, $xml );

		if ( false === $written ) {
			return new WP_Error( 'asae_ci_write_error', 'Could not write the WP REST feed file.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		rename( $tmp, $file );

		$url = trailingslashit( $upload_dir['baseurl'] ) . self::UPLOAD_SUBDIR . '/' . self::FEED_FILENAME;
		return $url;
	}

	/**
	 * Saves the author metadata sidecar JSON file.
	 *
	 * @param array $metadata Per-URL author metadata map.
	 * @return bool True on success.
	 */
	public static function save_author_sidecar( array $metadata ): bool {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR;
		$file       = trailingslashit( $dir ) . self::AUTHORS_FILENAME;

		wp_mkdir_p( $dir );

		$json = wp_json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $file, $json );
	}

	/**
	 * Loads the author metadata sidecar if it exists.
	 *
	 * @return array Per-URL author metadata map, or empty array.
	 */
	public static function load_author_sidecar(): array {
		$upload_dir = wp_upload_dir();
		$file       = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR . '/' . self::AUTHORS_FILENAME;

		if ( ! file_exists( $file ) ) {
			return [];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = file_get_contents( $file );
		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Returns metadata about the currently saved feed file.
	 *
	 * @return array { exists: bool, url: string, count: int, date: string, has_authors: bool }
	 */
	public static function get_feed_status(): array {
		$upload_dir = wp_upload_dir();
		$file       = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR . '/' . self::FEED_FILENAME;
		$url        = trailingslashit( $upload_dir['baseurl'] ) . self::UPLOAD_SUBDIR . '/' . self::FEED_FILENAME;

		if ( ! file_exists( $file ) ) {
			return [ 'exists' => false, 'url' => '', 'count' => 0, 'date' => '', 'has_authors' => false ];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $file );
		$count    = $contents ? substr_count( $contents, '<entry>' ) : 0;
		$modified = gmdate( 'Y-m-d H:i:s', filemtime( $file ) );

		// Check if sidecar exists.
		$authors_file = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR . '/' . self::AUTHORS_FILENAME;
		$has_authors  = file_exists( $authors_file );

		return [
			'exists'      => true,
			'url'         => $url,
			'count'       => $count,
			'date'        => $modified,
			'has_authors' => $has_authors,
		];
	}

	// ── Chunked Generation State ─────────────────────────────────────────────

	/**
	 * Stores accumulated posts and progress in transients.
	 *
	 * @param array $posts         Posts accumulated so far.
	 * @param int   $fetched_pages Pages fetched so far.
	 * @param int   $total_pages   Total pages expected.
	 * @param string $status       'fetching', 'generating', 'done'.
	 */
	public static function save_generation_state( array $posts, int $fetched_pages, int $total_pages, string $status ): void {
		set_transient( self::TRANSIENT_POSTS, $posts, 2 * HOUR_IN_SECONDS );
		set_transient( self::TRANSIENT_PROGRESS, [
			'fetched_pages' => $fetched_pages,
			'total_pages'   => $total_pages,
			'total_posts'   => count( $posts ),
			'status'        => $status,
		], 2 * HOUR_IN_SECONDS );
	}

	/**
	 * Retrieves accumulated posts from transient.
	 *
	 * @return array Posts array, or empty if not set.
	 */
	public static function get_accumulated_posts(): array {
		$posts = get_transient( self::TRANSIENT_POSTS );
		return is_array( $posts ) ? $posts : [];
	}

	/**
	 * Retrieves generation progress.
	 *
	 * @return array { fetched_pages, total_pages, total_posts, status }
	 */
	public static function get_generation_progress(): array {
		$progress = get_transient( self::TRANSIENT_PROGRESS );
		if ( ! is_array( $progress ) ) {
			return [
				'fetched_pages' => 0,
				'total_pages'   => 0,
				'total_posts'   => 0,
				'status'        => 'idle',
			];
		}
		return $progress;
	}

	/**
	 * Clears all generation state transients.
	 */
	public static function clear_generation_state(): void {
		delete_transient( self::TRANSIENT_POSTS );
		delete_transient( self::TRANSIENT_PROGRESS );
		delete_transient( self::TRANSIENT_LOOKUPS );
	}

	/**
	 * Stores resolved lookups in transient for reuse across chunked calls.
	 *
	 * @param array $lookups Resolved lookups.
	 */
	public static function save_lookups( array $lookups ): void {
		set_transient( self::TRANSIENT_LOOKUPS, $lookups, 2 * HOUR_IN_SECONDS );
	}

	/**
	 * Retrieves stored lookups.
	 *
	 * @return array|false Lookups array, or false if not set.
	 */
	public static function get_stored_lookups() {
		return get_transient( self::TRANSIENT_LOOKUPS );
	}

	// ── Private Helpers ──────────────────────────────────────────────────────

	/**
	 * Escapes a string for safe inclusion in XML text content.
	 *
	 * Strips control characters that are invalid in XML 1.0 (everything below
	 * 0x20 except TAB 0x09, LF 0x0A, CR 0x0D) before applying standard XML
	 * entity escaping. Source APIs sometimes include stray BEL (0x07) or other
	 * control chars that cause XML parsers to reject the document.
	 *
	 * @param string $str Raw string.
	 * @return string XML-safe string.
	 */
	private static function xml_escape( string $str ): string {
		// Strip XML-invalid control characters (keep tab, newline, carriage return).
		$str = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $str );
		return htmlspecialchars( $str, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
