<?php
/**
 * ASAE Content Ingestor – YouTube Feed Generator
 *
 * Fetches all videos from a YouTube channel via the YouTube Data API v3
 * and generates a standard Atom XML feed file that can be consumed by
 * the plugin's existing RSS/Atom ingestion pipeline.
 *
 * @package ASAE_Content_Ingestor
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CI_YouTube {

	/** YouTube Data API v3 playlistItems endpoint. */
	const API_ENDPOINT = 'https://www.googleapis.com/youtube/v3/playlistItems';

	/** Maximum results per API request (YouTube cap is 50). */
	const API_PAGE_SIZE = 50;

	/** WP option key for the stored API key. */
	const OPTION_API_KEY = 'asae_ci_youtube_api_key';

	/** WP option key for the stored channel/playlist ID. */
	const OPTION_CHANNEL_ID = 'asae_ci_youtube_channel_id';

	/** Directory inside wp-content/uploads where the feed file is stored. */
	const UPLOAD_SUBDIR = 'asae-ci';

	/** Filename for the generated feed. */
	const FEED_FILENAME = 'youtube-feed.xml';

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Fetches all videos from a YouTube playlist via the Data API v3.
	 *
	 * Paginates automatically until all items have been retrieved.
	 * Uses wp_remote_get() per plugin conventions.
	 *
	 * @param string $playlist_id The YouTube playlist ID (UUxxx format).
	 * @param string $api_key     YouTube Data API v3 key.
	 * @return array|WP_Error     Array of video objects on success, WP_Error on failure.
	 */
	public static function fetch_all_videos( string $playlist_id, string $api_key ) {
		$videos     = [];
		$page_token = '';

		do {
			$url = add_query_arg( [
				'part'       => 'snippet',
				'playlistId' => $playlist_id,
				'maxResults' => self::API_PAGE_SIZE,
				'pageToken'  => $page_token,
				'key'        => $api_key,
			], self::API_ENDPOINT );

			$response = wp_remote_get( $url, [
				'timeout' => 30,
				'headers' => [
					// Google API key HTTP referrer restrictions check this header.
					// Without it, keys restricted to a domain will reject the request.
					'Referer' => home_url( '/' ),
				],
			] );

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'asae_ci_youtube_request_failed',
					sprintf(
						/* translators: %s is the HTTP error message. */
						__( 'YouTube API request failed: %s', 'asae-content-ingestor' ),
						$response->get_error_message()
					)
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $code || ! is_array( $body ) ) {
				$error_msg = $body['error']['message'] ?? __( 'Unknown API error.', 'asae-content-ingestor' );
				return new WP_Error(
					'asae_ci_youtube_api_error',
					sprintf(
						/* translators: 1: HTTP status code, 2: error message. */
						__( 'YouTube API error (HTTP %1$d): %2$s', 'asae-content-ingestor' ),
						$code,
						$error_msg
					)
				);
			}

			$items = $body['items'] ?? [];
			foreach ( $items as $item ) {
				$snippet   = $item['snippet'] ?? [];
				$video_id  = $snippet['resourceId']['videoId'] ?? '';

				// Skip deleted or private videos (no videoId).
				if ( ! $video_id ) {
					continue;
				}

				$videos[] = [
					'id'            => $video_id,
					'title'         => $snippet['title'] ?? '',
					'description'   => $snippet['description'] ?? '',
					'published_at'  => $snippet['publishedAt'] ?? '',
					'thumbnail_url' => $snippet['thumbnails']['high']['url']
									?? $snippet['thumbnails']['default']['url']
									?? '',
					'channel_title' => $snippet['channelTitle'] ?? '',
				];
			}

			$page_token = $body['nextPageToken'] ?? '';

		} while ( $page_token );

		return $videos;
	}

	/**
	 * Generates a valid Atom XML feed string from an array of video objects.
	 *
	 * @param array  $videos        Array of video objects from fetch_all_videos().
	 * @param string $channel_title Channel title for the feed author.
	 * @return string Atom XML string.
	 */
	public static function generate_feed( array $videos, string $channel_title ): string {
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">' . "\n";
		$xml .= '  <title>' . self::xml_escape( $channel_title ?: 'YouTube Channel' ) . '</title>' . "\n";
		$xml .= '  <updated>' . $now . '</updated>' . "\n";
		$xml .= '  <id>urn:asae-ci:youtube-feed</id>' . "\n";
		$xml .= '  <generator>ASAE Content Ingestor</generator>' . "\n";

		foreach ( $videos as $video ) {
			$video_url   = 'https://www.youtube.com/watch?v=' . rawurlencode( $video['id'] );
			$published   = $video['published_at'] ?: $now;
			$title       = $video['title'] ?: '(untitled)';
			$description = $video['description'] ?: '';
			$thumb       = $video['thumbnail_url'] ?: '';
			$author      = $video['channel_title'] ?: $channel_title;

			$xml .= "  <entry>\n";
			$xml .= '    <id>yt:video:' . self::xml_escape( $video['id'] ) . '</id>' . "\n";
			$xml .= '    <title>' . self::xml_escape( $title ) . '</title>' . "\n";
			$xml .= '    <link rel="alternate" href="' . esc_url( $video_url ) . '"/>' . "\n";
			$xml .= '    <published>' . self::xml_escape( $published ) . '</published>' . "\n";
			$xml .= '    <updated>' . self::xml_escape( $published ) . '</updated>' . "\n";
			$xml .= '    <author><name>' . self::xml_escape( $author ) . '</name></author>' . "\n";

			if ( $description ) {
				$xml .= '    <summary type="text">' . self::xml_escape( $description ) . '</summary>' . "\n";
			}

			if ( $thumb ) {
				$xml .= '    <media:thumbnail url="' . esc_url( $thumb ) . '"/>' . "\n";
			}

			$xml .= "  </entry>\n";
		}

		$xml .= '</feed>' . "\n";

		return $xml;
	}

	/**
	 * Saves the generated Atom XML feed to the uploads directory.
	 *
	 * Creates the target directory if it does not exist. Overwrites any
	 * previous feed file (one feed at a time is sufficient).
	 *
	 * @param string $xml The Atom XML string.
	 * @return string|WP_Error Public URL of the saved file, or WP_Error on failure.
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
				__( 'Could not create the upload directory for the YouTube feed.', 'asae-content-ingestor' )
			);
		}

		// Write the feed file atomically (write to temp, then rename).
		$tmp = $file . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $tmp, $xml );

		if ( false === $written ) {
			return new WP_Error(
				'asae_ci_write_error',
				__( 'Could not write the YouTube feed file.', 'asae-content-ingestor' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		rename( $tmp, $file );

		$url = trailingslashit( $upload_dir['baseurl'] ) . self::UPLOAD_SUBDIR . '/' . self::FEED_FILENAME;
		return $url;
	}

	/**
	 * Normalises a channel or playlist ID input.
	 *
	 * Accepts both UCxxx channel IDs and UUxxx playlist IDs.
	 * If a channel ID is provided, automatically swaps UC→UU to produce
	 * the uploads playlist ID.
	 *
	 * @param string $input Channel ID (UCxxx) or playlist ID (UUxxx).
	 * @return string Normalised playlist ID.
	 */
	public static function normalize_playlist_id( string $input ): string {
		$input = trim( $input );

		// Channel ID → uploads playlist ID.
		if ( str_starts_with( $input, 'UC' ) ) {
			return 'UU' . substr( $input, 2 );
		}

		return $input;
	}

	/**
	 * Returns metadata about the currently saved feed file (if any).
	 *
	 * @return array{exists: bool, url: string, count: int, date: string}
	 */
	public static function get_feed_status(): array {
		$upload_dir = wp_upload_dir();
		$file       = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR . '/' . self::FEED_FILENAME;
		$url        = trailingslashit( $upload_dir['baseurl'] ) . self::UPLOAD_SUBDIR . '/' . self::FEED_FILENAME;

		if ( ! file_exists( $file ) ) {
			return [ 'exists' => false, 'url' => '', 'count' => 0, 'date' => '' ];
		}

		// Count <entry> elements to determine video count.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $file );
		$count    = $contents ? substr_count( $contents, '<entry>' ) : 0;
		$modified = gmdate( 'Y-m-d H:i:s', filemtime( $file ) );

		return [
			'exists' => true,
			'url'    => $url,
			'count'  => $count,
			'date'   => $modified,
		];
	}

	// ── Private Helpers ───────────────────────────────────────────────────────

	/**
	 * Escapes a string for safe inclusion in XML text content.
	 *
	 * @param string $str Raw string.
	 * @return string XML-safe string.
	 */
	private static function xml_escape( string $str ): string {
		return htmlspecialchars( $str, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
