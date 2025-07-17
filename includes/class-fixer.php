<?php

namespace KNTNT_UNICODE_FIX;

/**
 * Fixer Class
 *
 * Handles the detection and correction of malformed Unicode escape sequences
 * within WordPress block editor content.
 *
 * This class works by parsing post content into a block structure, recursively
 * fixing string attributes, and then saving the post using the standard
 * WordPress API to ensure proper revision creation and hook execution.
 *
 * The fixer specifically targets Unicode sequences where the backslash has been
 * stripped, converting patterns like 'u00e5' back to the correct '\u00e5' format.
 */
class Fixer {

	/**
	 * Regex pattern to find corrupt Unicode escape sequences
	 *
	 * Matches a 'u' followed by 4 hex characters that is NOT preceded by a backslash.
	 * This identifies malformed Unicode sequences like 'u00e5' instead of '\u00e5'.
	 *
	 * The pattern uses negative lookbehind to ensure we don't match already
	 * correctly formatted sequences.
	 *
	 * @var string
	 */
	private const UNICODE_REGEX = '/(?<!\\\\)u([0-9A-Fa-f]{4})/';

	/**
	 * Finds and fixes a post's content and creates a new revision
	 *
	 * This is the main public method that orchestrates the entire fixing process:
	 * 1. Retrieves the post and validates it exists
	 * 2. Processes the content through the block parser
	 * 3. Compares original and fixed content
	 * 4. Updates the post if changes were made
	 * 5. Creates revision and updates tracking data
	 *
	 * @param int $post_id The ID of the post to fix
	 *
	 * @return bool True on success or if no changes were needed, false on failure
	 */
	public function fix_post( int $post_id ): bool {
		$post_to_update = get_post( $post_id );

		// Validate post exists
		if ( ! $post_to_update ) {
			return false;
		}

		$original_content = $post_to_update->post_content;
		$fixed_content = $this->fix_content_via_block_parser( $original_content );

		// If the content is unchanged, there's nothing more to do
		if ( $fixed_content === $original_content ) {
			return true;
		}

		// Count errors for tracking purposes
		$errors_fixed = $this->count_unicode_errors( $original_content );

		// Prepare post data for update
		$post_data = [
			'ID' => $post_id,
			'post_content' => $fixed_content,
		];

		// Use the standard WordPress API to update the post
		// This correctly handles revisions, hooks, and caching
		$result = wp_update_post( $post_data, true );

		// Check if update was successful
		if ( is_wp_error( $result ) || $result === 0 ) {
			return false;
		}

		// Add to recently fixed list for user feedback
		$this->add_to_recently_fixed( $post_id, $errors_fixed );

		return true;
	}

	/**
	 * Fixes content by parsing it into blocks and correcting attributes
	 *
	 * This method uses WordPress's native block parser to safely handle
	 * the block structure while fixing Unicode sequences in attributes.
	 *
	 * @param string $content The raw post_content string
	 *
	 * @return string The fixed post_content string
	 */
	private function fix_content_via_block_parser( string $content ): string {
		// Parse content into block structure
		$blocks = parse_blocks( $content );

		// Recursively fix all blocks and their nested blocks
		$fixed_blocks = $this->fix_blocks_recursive( $blocks );

		// Convert blocks back to HTML/comment format
		return serialize_blocks( $fixed_blocks );
	}

	/**
	 * Recursively processes an array of blocks to fix their attributes
	 *
	 * This function traverses the entire block tree, including any nested
	 * `innerBlocks`, ensuring all Unicode sequences are corrected throughout
	 * the content structure.
	 *
	 * @param array $blocks An array of blocks from parse_blocks()
	 *
	 * @return array The updated array of blocks with fixed attributes
	 */
	private function fix_blocks_recursive( array $blocks ): array {
		foreach ( $blocks as $i => $block ) {
			// Process block attributes if they exist
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$blocks[ $i ]['attrs'] = $this->fix_attributes_recursive( $block['attrs'] );
			}

			// Process nested blocks recursively
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = $this->fix_blocks_recursive( $block['innerBlocks'] );
			}
		}

		return $blocks;
	}

	/**
	 * Recursively processes a block's attributes array to fix string values
	 *
	 * This method handles the deep traversal of block attributes, which can
	 * contain nested arrays and objects. It applies the Unicode fix to all
	 * string values while preserving the original structure.
	 *
	 * @param array $attrs A block's 'attrs' array
	 *
	 * @return array The updated attributes array with fixed Unicode sequences
	 */
	private function fix_attributes_recursive( array $attrs ): array {
		foreach ( $attrs as $key => $value ) {
			if ( is_string( $value ) ) {
				// Apply the Unicode fix to the string attribute
				// The replacement adds a literal backslash for proper JSON escaping
				$attrs[ $key ] = preg_replace( self::UNICODE_REGEX, '\\\\u$1', $value );
			}
			elseif ( is_array( $value ) ) {
				// If an attribute is itself an array (e.g., in a Gallery block),
				// continue the recursion to process nested values
				$attrs[ $key ] = $this->fix_attributes_recursive( $value );
			}
			// Note: We don't process objects or other data types as they
			// shouldn't contain Unicode sequences in this context
		}

		return $attrs;
	}

	/**
	 * Counts the number of malformed Unicode sequences in a content string
	 *
	 * This method provides statistics for reporting purposes and helps
	 * track the extent of corruption in a post.
	 *
	 * @param string $content The raw post_content string to analyze
	 *
	 * @return int The total number of corrupt Unicode sequences found
	 */
	private function count_unicode_errors( string $content ): int {
		preg_match_all( self::UNICODE_REGEX, $content, $matches );
		return count( $matches[0] );
	}

	/**
	 * Adds the fixed post to a transient list for dashboard display
	 *
	 * This provides administrators with immediate feedback about recent
	 * fixes and helps track the plugin's activity.
	 *
	 * @param int $post_id      The ID of the post that was fixed
	 * @param int $errors_fixed The number of Unicode errors that were corrected
	 *
	 * @return void
	 */
	private function add_to_recently_fixed( int $post_id, int $errors_fixed ): void {
		// Get existing recently fixed posts or initialize empty array
		$recently_fixed = get_transient( 'kntnt-unicode-fix-recently-fixed' ) ?: [];

		// Add new fix to the beginning of the array
		array_unshift( $recently_fixed, [
			'post_id' => $post_id,
			'errors_fixed' => $errors_fixed,
			'timestamp' => time(),
		] );

		// Keep only the 5 most recent entries to prevent the list from growing too large
		// Store for one hour to provide timely feedback without cluttering the interface
		set_transient( 'kntnt-unicode-fix-recently-fixed', array_slice( $recently_fixed, 0, 5 ), HOUR_IN_SECONDS );
	}

	/**
	 * Validates that a post can be safely fixed
	 *
	 * Performs pre-flight checks to ensure the post is in a state
	 * that can be safely modified.
	 *
	 * @param int $post_id The ID of the post to validate
	 *
	 * @return bool True if the post can be fixed, false otherwise
	 */
	public function can_fix_post( int $post_id ): bool {
		$post = get_post( $post_id );

		// Check if post exists
		if ( ! $post ) {
			return false;
		}

		// Check if post has content
		if ( empty( $post->post_content ) ) {
			return false;
		}

		// Check if post contains block editor content
		if ( strpos( $post->post_content, '<!-- wp:' ) === false ) {
			return false;
		}

		// Check if current user can edit the post
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Preview what changes would be made to a post without actually saving
	 *
	 * Useful for debugging or providing users with a preview of changes.
	 *
	 * @param int $post_id The ID of the post to preview
	 *
	 * @return array Array containing original_content, fixed_content, and errors_count
	 */
	public function preview_fix( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return [
				'original_content' => '',
				'fixed_content' => '',
				'errors_count' => 0,
			];
		}

		$original_content = $post->post_content;
		$fixed_content = $this->fix_content_via_block_parser( $original_content );
		$errors_count = $this->count_unicode_errors( $original_content );

		return [
			'original_content' => $original_content,
			'fixed_content' => $fixed_content,
			'errors_count' => $errors_count,
		];
	}

	/**
	 * Batch fix multiple posts at once
	 *
	 * Processes multiple posts in a single operation with progress tracking.
	 *
	 * @param array $post_ids Array of post IDs to fix
	 *
	 * @return array Results array with success/failure counts and details
	 */
	public function batch_fix_posts( array $post_ids ): array {
		$results = [
			'success_count' => 0,
			'failure_count' => 0,
			'skipped_count' => 0,
			'total_errors_fixed' => 0,
			'details' => [],
		];

		foreach ( $post_ids as $post_id ) {
			$post_id = intval( $post_id );

			if ( ! $this->can_fix_post( $post_id ) ) {
				$results['skipped_count'] ++;
				$results['details'][ $post_id ] = 'skipped';
				continue;
			}

			$post = get_post( $post_id );
			$errors_before = $this->count_unicode_errors( $post->post_content );

			if ( $this->fix_post( $post_id ) ) {
				$results['success_count'] ++;
				$results['total_errors_fixed'] += $errors_before;
				$results['details'][ $post_id ] = 'success';
			}
			else {
				$results['failure_count'] ++;
				$results['details'][ $post_id ] = 'failed';
			}
		}

		return $results;
	}

}