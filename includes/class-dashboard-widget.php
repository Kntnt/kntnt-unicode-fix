<?php

namespace KNTNT_UNICODE_FIX;

/**
 * Dashboard Widget Class
 *
 * This class manages the WordPress dashboard widget that provides the user interface
 * for the Unicode Fix plugin. It handles:
 * - Widget registration and capability checks
 * - Rendering the widget interface with scan results
 * - Displaying recently fixed posts
 * - Generating action buttons for scanning and fixing
 * - Managing user feedback and status messages
 */
class Dashboard_Widget {

	/**
	 * Main plugin instance
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Initialize the dashboard widget
	 *
	 * @param Plugin $plugin The main plugin instance
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		add_action( 'wp_dashboard_setup', [ $this, 'add_dashboard_widget' ] );
	}

	/**
	 * Add the dashboard widget to WordPress admin
	 *
	 * Registers the widget with WordPress dashboard only if the current user
	 * has the required capability.
	 *
	 * @return void
	 */
	public function add_dashboard_widget(): void {
		// Only add widget for users with proper capabilities
		if ( ! current_user_can( KNTNT_UNICODE_FIX_CAPABILITY ) ) {
			return;
		}

		wp_add_dashboard_widget( 'kntnt-unicode-fix-widget', __( 'Unicode Fix', 'kntnt-unicode-fix' ), [ $this, 'render_widget' ] );
	}

	/**
	 * Render the complete dashboard widget content
	 *
	 * This method orchestrates the entire widget display including:
	 * - Auto-scanning if no cached data exists
	 * - Displaying recently fixed posts
	 * - Showing scan results and action buttons
	 * - Handling different states (clean, corrupted, etc.)
	 *
	 * @return void
	 */
	public function render_widget(): void {
		$scanner = $this->plugin->get_scanner();
		$corrupt_posts = $scanner->get_corrupt_posts();

		// Auto-scan if no cached data exists
		if ( $corrupt_posts === null ) {
			$corrupt_posts = $scanner->scan_and_cache();
		}

		$recently_fixed = $this->get_recently_fixed_posts();

		// Start widget container
		echo '<div class="kntnt-unicode-fix-widget">';

		// Display widget description and scan button
		$this->render_widget_header();

		// Display recently fixed posts if any exist
		if ( ! empty( $recently_fixed ) ) {
			$this->render_recently_fixed_posts( $recently_fixed );
		}

		// Display scan results
		$this->render_scan_results( $corrupt_posts );

		// Close widget container
		echo '</div>';
	}

	/**
	 * Render the widget header with description and scan button
	 *
	 * @return void
	 */
	private function render_widget_header(): void {
		echo '<p>' . esc_html__( 'This tool scans for corrupt Unicode escape sequences in WordPress block JSON data.', 'kntnt-unicode-fix' ) . '</p>';

		// Generate secure scan URL with nonce
		$scan_url = wp_nonce_url( admin_url( 'index.php?action=kntnt_unicode_scan' ), 'kntnt_unicode_scan_nonce' );

		echo '<a href="' . esc_url( $scan_url ) . '" class="button button-primary">';
		echo esc_html__( 'Scan for corrupt unicodes', 'kntnt-unicode-fix' );
		echo '</a>';
	}

	/**
	 * Render the recently fixed posts section
	 *
	 * Displays a success notice and list of recently fixed posts with their details.
	 *
	 * @param array $recently_fixed Array of recently fixed posts data
	 *
	 * @return void
	 */
	private function render_recently_fixed_posts( array $recently_fixed ): void {
		// Success notice for recently fixed posts
		echo '<div class="notice notice-success inline" style="margin-top: 15px;">';
		echo '<p>' . sprintf( esc_html__( 'Recently fixed %d post(s):', 'kntnt-unicode-fix' ), count( $recently_fixed ) ) . '</p>';
		echo '</div>';

		// List of recently fixed posts
		echo '<div class="kntnt-unicode-post-list" style="margin-top: 15px;">';

		foreach ( $recently_fixed as $fixed_post ) {
			$this->render_fixed_post_item( $fixed_post );
		}

		echo '</div>';
	}

	/**
	 * Render a single fixed post item
	 *
	 * @param array $fixed_post Array containing post_id, errors_fixed, and timestamp
	 *
	 * @return void
	 */
	private function render_fixed_post_item( array $fixed_post ): void {
		$post = get_post( $fixed_post['post_id'] );

		// Skip if post doesn't exist or user can't edit it
		if ( ! $post || ! current_user_can( 'edit_post', $fixed_post['post_id'] ) ) {
			return;
		}

		// Container with green styling for fixed posts
		echo '<div class="kntnt-unicode-post-item" style="margin: 10px 0; padding: 10px; border: 1px solid #46b450; border-radius: 4px; background: #f7fff7;">';

		// Post title and ID
		echo '<strong>' . esc_html( $post->post_title ) . '</strong> (' . $fixed_post['post_id'] . ')';

		// Action buttons container
		echo '<div class="kntnt-unicode-actions" style="margin-top: 8px;">';

		// View button (opens in new tab)
		echo '<a href="' . esc_url( get_permalink( $fixed_post['post_id'] ) ) . '" class="button button-small" target="_blank">';
		echo esc_html__( 'View', 'kntnt-unicode-fix' );
		echo '</a> ';

		// Edit button
		echo '<a href="' . esc_url( get_edit_post_link( $fixed_post['post_id'] ) ) . '" class="button button-small">';
		echo esc_html__( 'Edit', 'kntnt-unicode-fix' );
		echo '</a> ';

		// Status indicator showing number of errors fixed
		echo '<span class="button button-small" style="background: #46b450; color: white; cursor: default;">';
		echo sprintf( esc_html__( 'Fixed (%d errors)', 'kntnt-unicode-fix' ), $fixed_post['errors_fixed'] );
		echo '</span>';

		echo '</div>'; // Close actions
		echo '</div>'; // Close post item
	}

	/**
	 * Render the scan results section
	 *
	 * Shows either a success message (no corruption found) or a list of corrupted posts
	 * with action buttons for each.
	 *
	 * @param array $corrupt_posts Array of post IDs with corrupt Unicode sequences
	 *
	 * @return void
	 */
	private function render_scan_results( array $corrupt_posts ): void {
		if ( empty( $corrupt_posts ) ) {
			$this->render_clean_results();
		}
		else {
			$this->render_corrupt_results( $corrupt_posts );
		}
	}

	/**
	 * Render clean scan results (no corruption found)
	 *
	 * @return void
	 */
	private function render_clean_results(): void {
		echo '<div class="notice notice-success inline" style="margin-top: 15px;">';
		echo '<p>' . esc_html__( 'All posts are clean! No corrupt Unicode sequences found.', 'kntnt-unicode-fix' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render corrupt scan results with list of affected posts
	 *
	 * @param array $corrupt_posts Array of post IDs with corruption
	 *
	 * @return void
	 */
	private function render_corrupt_results( array $corrupt_posts ): void {
		// Error notice showing number of corrupt posts
		echo '<div class="notice notice-error inline" style="margin-top: 15px;">';
		echo '<p>' . sprintf( esc_html__( 'Found %d posts with corrupt Unicode sequences.', 'kntnt-unicode-fix' ), count( $corrupt_posts ) ) . '</p>';
		echo '</div>';

		// List of corrupt posts
		echo '<div class="kntnt-unicode-post-list" style="margin-top: 15px;">';

		foreach ( $corrupt_posts as $post_id ) {
			$this->render_corrupt_post_item( $post_id );
		}

		echo '</div>';
	}

	/**
	 * Render a single corrupt post item with action buttons
	 *
	 * @param int $post_id The ID of the corrupt post
	 *
	 * @return void
	 */
	private function render_corrupt_post_item( int $post_id ): void {
		$post = get_post( $post_id );

		// Skip if post doesn't exist or user can't edit it
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Container with neutral styling for corrupt posts
		echo '<div class="kntnt-unicode-post-item" style="margin: 10px 0; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">';

		// Post title and ID
		echo '<strong>' . esc_html( $post->post_title ) . '</strong> (' . $post_id . ')';

		// Action buttons container
		echo '<div class="kntnt-unicode-actions" style="margin-top: 8px;">';

		// View button (opens in new tab)
		echo '<a href="' . esc_url( get_permalink( $post_id ) ) . '" class="button button-small" target="_blank">';
		echo esc_html__( 'View', 'kntnt-unicode-fix' );
		echo '</a> ';

		// Edit button
		echo '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '" class="button button-small">';
		echo esc_html__( 'Edit', 'kntnt-unicode-fix' );
		echo '</a> ';

		// Fix button with confirmation dialog
		$this->render_fix_button( $post_id );

		echo '</div>'; // Close actions
		echo '</div>'; // Close post item
	}

	/**
	 * Render the fix button with security nonce and confirmation dialog
	 *
	 * @param int $post_id The ID of the post to fix
	 *
	 * @return void
	 */
	private function render_fix_button( int $post_id ): void {
		// Generate secure fix URL with nonce
		$fix_url = wp_nonce_url( admin_url( 'index.php?action=kntnt_unicode_fix&post_id=' . $post_id ), 'kntnt_unicode_fix_' . $post_id );

		// Fix button with JavaScript confirmation
		echo '<a href="' . esc_url( $fix_url ) . '" class="button button-small button-primary" ';
		echo 'onclick="return confirm(\'' . esc_js( __( 'This will create a new revision with fixed Unicode sequences. Continue?', 'kntnt-unicode-fix' ) ) . '\')">';
		echo esc_html__( 'Fix', 'kntnt-unicode-fix' );
		echo '</a>';
	}

	/**
	 * Get recently fixed posts from transient storage
	 *
	 * Retrieves the list of recently fixed posts for display in the widget.
	 *
	 * @return array Array of recently fixed posts or empty array if none exist
	 */
	private function get_recently_fixed_posts(): array {
		$recently_fixed = get_transient( 'kntnt-unicode-fix-recently-fixed' );
		return is_array( $recently_fixed ) ? $recently_fixed : [];
	}

}