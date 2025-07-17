<?php

namespace KNTNT_UNICODE_FIX;

/**
 * Scanner Class
 *
 * This class handles the detection of corrupt Unicode escape sequences in WordPress
 * block editor content. It provides functionality to:
 * - Scan all published posts for corrupt Unicode sequences
 * - Cache scan results for performance
 * - Manage cached scan data
 * - Filter posts and post types to scan
 *
 * The scanner looks for malformed Unicode sequences in block JSON data where
 * backslashes have been stripped from valid Unicode escape sequences.
 */
class Scanner {

	/**
	 * Regex pattern to match WordPress block comments with JSON data
	 *
	 * This pattern captures the JSON portion of block comments like:
	 * <!-- wp:paragraph {"textColor":"primary"} -->
	 *
	 * The pattern uses recursive matching to handle nested braces correctly.
	 *
	 * @var string
	 */
	private const BLOCK_REGEX = '/<!--\s*wp:[^{]*+(\{(?:[^{}]++|(?1))*+\})/';

	/**
	 * Regex pattern to detect corrupt Unicode escape sequences
	 *
	 * This pattern matches:
	 * - 'u' followed by exactly 4 hexadecimal characters
	 * - NOT preceded by a backslash (negative lookbehind)
	 * - Followed by common JSON delimiters or end of string
	 *
	 * Examples of matches:
	 * - 'u00e5' (corrupt, should be '\u00e5')
	 * - 'u0026' (corrupt, should be '\u0026')
	 *
	 * @var string
	 */
	private const UNICODE_REGEX = '/(?<!\\\\)u([0-9A-Fa-f]{4})(?=[\s",:}\]\/\t\n\r-]|$|u[0-9A-Fa-f])/';

	/**
	 * Get cached corrupt posts from transient storage
	 *
	 * Retrieves the list of corrupt post IDs from WordPress transient storage.
	 * Returns null if no cached data exists, indicating a scan is needed.
	 *
	 * @return array|null Array of corrupt post IDs or null if not cached
	 */
	public function get_corrupt_posts(): ?array {
		$transient = get_transient( KNTNT_UNICODE_FIX_TRANSIENT );
		return $transient === false ? null : $transient;
	}

	/**
	 * Scan all posts and cache the results
	 *
	 * Performs a complete scan of all eligible posts and stores the results
	 * in transient storage for 12 hours to improve performance.
	 *
	 * @return array Array of post IDs with corrupt Unicode sequences
	 */
	public function scan_and_cache(): array {
		$corrupt_posts = $this->scan_posts();

		// Cache results for 12 hours to improve performance
		set_transient( KNTNT_UNICODE_FIX_TRANSIENT, $corrupt_posts, 12 * HOUR_IN_SECONDS );

		return $corrupt_posts;
	}

	/**
	 * Remove a specific post from the cached corrupt posts list
	 *
	 * Updates the cached list by removing a post ID, typically after
	 * the post has been successfully fixed.
	 *
	 * @param int $post_id The ID of the post to remove from cache
	 *
	 * @return void
	 */
	public function remove_from_cache( int $post_id ): void {
		$corrupt_posts = get_transient( KNTNT_UNICODE_FIX_TRANSIENT );

		if ( is_array( $corrupt_posts ) ) {
			// Remove the post ID from the array and update cache
			$corrupt_posts = array_diff( $corrupt_posts, [ $post_id ] );
			set_transient( KNTNT_UNICODE_FIX_TRANSIENT, $corrupt_posts, 12 * HOUR_IN_SECONDS );
		}
	}

	/**
	 * Perform the actual scanning of posts
	 *
	 * This is the main scanning logic that:
	 * 1. Gets the list of post types to scan
	 * 2. Gets the list of post IDs to scan
	 * 3. Checks each post for corrupt Unicode sequences
	 * 4. Returns an array of corrupt post IDs
	 *
	 * @return array Array of post IDs with corrupt Unicode sequences
	 */
	private function scan_posts(): array {
		$post_types = $this->get_post_types_to_scan();
		$post_ids = $this->get_post_ids_to_scan( $post_types );
		$corrupt_posts = [];

		foreach ( $post_ids as $post_id ) {
			if ( $this->post_has_corrupt_unicode( $post_id ) ) {
				$corrupt_posts[] = $post_id;
			}
		}

		return $corrupt_posts;
	}

	/**
	 * Get the list of post types to scan
	 *
	 * By default, scans all public post types. Can be filtered using
	 * the 'kntnt-unicode-fix-post-types' filter.
	 *
	 * @return array Array of post type names to scan
	 */
	private function get_post_types_to_scan(): array {
		// Get all public post types by default
		$post_types = get_post_types( [ 'public' => true ] );

		/**
		 * Filter the post types to scan
		 *
		 * Allows developers to customize which post types are scanned.
		 *
		 * @param array $post_types Array of post type names
		 *
		 * @return array Filtered array of post type names
		 */
		return apply_filters( 'kntnt-unicode-fix-post-types', $post_types );
	}

	/**
	 * Get the list of post IDs to scan
	 *
	 * Retrieves all published posts of the specified post types.
	 * Can be filtered using the 'kntnt-unicode-fix-post-ids' filter.
	 *
	 * @param array $post_types Array of post type names to scan
	 *
	 * @return array Array of post IDs to scan
	 */
	private function get_post_ids_to_scan( array $post_types ): array {
		// Get all published posts of the specified types
		$post_ids = get_posts( [
			'post_type' => $post_types,
			'post_status' => 'publish',
			'numberposts' => - 1, // Get all posts
			'fields' => 'ids',   // Only return IDs for performance
		] );

		/**
		 * Filter the post IDs to scan
		 *
		 * Allows developers to customize which specific posts are scanned.
		 * Useful for limiting scans to specific posts or ranges.
		 *
		 * @param array $post_ids Array of post IDs
		 *
		 * @return array Filtered array of post IDs
		 */
		return apply_filters( 'kntnt-unicode-fix-post-ids', $post_ids );
	}

	/**
	 * Check if a specific post has corrupt Unicode sequences
	 *
	 * This method performs several checks:
	 * 1. Verifies the post exists and has content
	 * 2. Checks if the post contains block editor content
	 * 3. Extracts JSON blocks from the content
	 * 4. Searches for corrupt Unicode sequences in the JSON
	 *
	 * @param int $post_id The ID of the post to check
	 *
	 * @return bool True if the post has corrupt Unicode sequences, false otherwise
	 */
	private function post_has_corrupt_unicode( int $post_id ): bool {
		$post = get_post( $post_id );

		// Skip if post doesn't exist or has no content
		if ( ! $post || empty( $post->post_content ) ) {
			return false;
		}

		// Skip if post doesn't contain block editor content
		if ( strpos( $post->post_content, '<!-- wp:' ) === false ) {
			return false;
		}

		// Extract all JSON blocks from the post content
		preg_match_all( self::BLOCK_REGEX, $post->post_content, $matches );

		// Skip if no JSON blocks found
		if ( empty( $matches[1] ) ) {
			return false;
		}

		// Check each JSON block for corrupt Unicode sequences
		foreach ( $matches[1] as $json_block ) {
			// Only check blocks that contain 'u' character for performance
			if ( strpos( $json_block, 'u' ) !== false && preg_match( self::UNICODE_REGEX, $json_block ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Count the total number of corrupt Unicode sequences in a post
	 *
	 * This method is useful for reporting and debugging purposes.
	 *
	 * @param int $post_id The ID of the post to check
	 *
	 * @return int Number of corrupt Unicode sequences found
	 */
	public function count_corrupt_sequences( int $post_id ): int {
		$post = get_post( $post_id );

		if ( ! $post || empty( $post->post_content ) ) {
			return 0;
		}

		// Extract all JSON blocks from the post content
		preg_match_all( self::BLOCK_REGEX, $post->post_content, $matches );

		if ( empty( $matches[1] ) ) {
			return 0;
		}

		$count = 0;
		// Count corrupt sequences in each JSON block
		foreach ( $matches[1] as $json_block ) {
			preg_match_all( self::UNICODE_REGEX, $json_block, $sequence_matches );
			$count += count( $sequence_matches[0] );
		}

		return $count;
	}

	/**
	 * Clear all cached scan data
	 *
	 * Removes all cached scan results, forcing a fresh scan on next request.
	 * Useful for development or when scan logic is updated.
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		delete_transient( KNTNT_UNICODE_FIX_TRANSIENT );
	}

	/**
	 * Get scan statistics
	 *
	 * Provides detailed statistics about the current scan state.
	 *
	 * @return array Statistics array with scan information
	 */
	public function get_scan_statistics(): array {
		$corrupt_posts = $this->get_corrupt_posts();
		$post_types = $this->get_post_types_to_scan();
		$all_post_ids = $this->get_post_ids_to_scan( $post_types );

		$stats = [
			'total_posts_scanned' => count( $all_post_ids ),
			'corrupt_posts_found' => is_array( $corrupt_posts ) ? count( $corrupt_posts ) : 0,
			'clean_posts' => count( $all_post_ids ) - ( is_array( $corrupt_posts ) ? count( $corrupt_posts ) : 0 ),
			'post_types_scanned' => $post_types,
			'cache_exists' => $corrupt_posts !== null,
			'last_scan_time' => $this->get_last_scan_time(),
		];

		return $stats;
	}

	/**
	 * Get the timestamp of the last scan
	 *
	 * @return int|null Timestamp of last scan or null if never scanned
	 */
	private function get_last_scan_time(): ?int {
		// Since we don't store scan time separately, we approximate it
		// by checking if the transient exists and estimating based on its expiration
		$corrupt_posts = get_transient( KNTNT_UNICODE_FIX_TRANSIENT );

		if ( $corrupt_posts === false ) {
			return null;
		}

		// This is an approximation - in a production version, you might want to
		// store the actual scan time separately
		return time() - ( 12 * HOUR_IN_SECONDS - wp_cache_get_last_changed( 'transient' ) );
	}

	/**
	 * Validate that scanning is safe to perform
	 *
	 * Checks system resources and conditions before starting a scan.
	 *
	 * @return bool True if scanning is safe, false otherwise
	 */
	public function is_scan_safe(): bool {
		// Check if we have enough memory (approximate check)
		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$memory_used = memory_get_usage( true );
		$memory_available = $memory_limit - $memory_used;

		// Require at least 64MB available memory
		if ( $memory_available < 64 * 1024 * 1024 ) {
			return false;
		}

		// Check if we're not in a critical time (like during maintenance)
		if ( wp_is_maintenance_mode() ) {
			return false;
		}

		// Check if another scan is already running (basic check)
		if ( wp_cache_get( 'kntnt_unicode_scan_running' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Set scan as running to prevent concurrent scans
	 *
	 * @return void
	 */
	public function set_scan_running(): void {
		wp_cache_set( 'kntnt_unicode_scan_running', true, '', 300 ); // 5 minutes
	}

	/**
	 * Clear the scan running flag
	 *
	 * @return void
	 */
	public function clear_scan_running(): void {
		wp_cache_delete( 'kntnt_unicode_scan_running' );
	}

}