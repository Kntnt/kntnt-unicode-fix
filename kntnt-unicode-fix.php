<?php
/**
 * Plugin Name:       Kntnt Unicode Fix
 * Description:       Fixes corrupt Unicode escape sequences in WordPress block JSON data
 * Version:           1.0.0
 * Plugin URI:        https://github.com/Kntnt/kntnt-unicode-fix
 * Tested up to:      6.8
 * Requires at least: 6.0
 * Requires PHP:      8.3
 * Author:            TBarregren
 * Author URI:        https://www.kntnt.com/
 * License:           GPL-2.0-or-later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       kntnt-unicode-fix
 * Domain Path:       /languages
 * Network:           false
 *
 * @package Kntnt\UnicodeFixPlugin
 */

namespace KNTNT_UNICODE_FIX;

// Prevent direct access to this file
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants for consistent path and capability management
define( 'KNTNT_UNICODE_FIX_PLUGIN_FILE', __FILE__ );
define( 'KNTNT_UNICODE_FIX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KNTNT_UNICODE_FIX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KNTNT_UNICODE_FIX_PLUGIN_VERSION', '1.0.0' );
define( 'KNTNT_UNICODE_FIX_CAPABILITY', 'kntnt_unicode_fix' );
define( 'KNTNT_UNICODE_FIX_TRANSIENT', 'kntnt-unicode-fix-list' );
define( 'KNTNT_UNICODE_FIX_NOTICE_TRANSIENT', 'kntnt-unicode-fix-notice' );

/**
 * Main Plugin Class
 *
 * This class orchestrates the entire plugin functionality including:
 * - Loading dependencies and initializing components
 * - Handling WordPress hooks and actions
 * - Managing user capabilities
 * - Processing admin actions (scan and fix)
 * - Displaying admin notices
 *
 * Uses singleton pattern to ensure only one instance exists.
 */
class Plugin {

	/**
	 * Single instance of the plugin
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Dashboard widget component
	 *
	 * @var Dashboard_Widget
	 */
	private Dashboard_Widget $dashboard_widget;

	/**
	 * Scanner component for detecting corrupt Unicode sequences
	 *
	 * @var Scanner
	 */
	private Scanner $scanner;

	/**
	 * Fixer component for repairing corrupt Unicode sequences
	 *
	 * @var Fixer
	 */
	private Fixer $fixer;

	/**
	 * Get singleton instance of the plugin
	 *
	 * @return Plugin The plugin instance
	 */
	public static function get_instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton pattern
	 *
	 * Initializes the plugin by loading dependencies and setting up hooks.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Load required class files and initialize component instances
	 *
	 * This method includes all necessary class files and creates instances
	 * of the main plugin components.
	 *
	 * @return void
	 */
	private function load_dependencies(): void {
		// Load component classes
		require_once KNTNT_UNICODE_FIX_PLUGIN_DIR . 'includes/class-dashboard-widget.php';
		require_once KNTNT_UNICODE_FIX_PLUGIN_DIR . 'includes/class-scanner.php';
		require_once KNTNT_UNICODE_FIX_PLUGIN_DIR . 'includes/class-fixer.php';

		// Initialize component instances
		$this->dashboard_widget = new Dashboard_Widget( $this );
		$this->scanner = new Scanner;
		$this->fixer = new Fixer;
	}

	/**
	 * Get the scanner instance
	 *
	 * Provides access to the scanner component for other classes.
	 *
	 * @return Scanner The scanner instance
	 */
	public function get_scanner(): Scanner {
		return $this->scanner;
	}

	/**
	 * Get the fixer instance
	 *
	 * Provides access to the fixer component for other classes.
	 *
	 * @return Fixer The fixer instance
	 */
	public function get_fixer(): Fixer {
		return $this->fixer;
	}

	/**
	 * Initialize WordPress hooks and filters
	 *
	 * Sets up all necessary WordPress hooks for the plugin to function properly.
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		// Core WordPress hooks
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'admin_init', [ $this, 'handle_admin_actions' ] );
		add_action( 'admin_notices', [ $this, 'show_admin_notices' ] );

		// Plugin lifecycle hooks
		register_activation_hook( KNTNT_UNICODE_FIX_PLUGIN_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( KNTNT_UNICODE_FIX_PLUGIN_FILE, [ $this, 'deactivate' ] );
	}

	/**
	 * Load plugin text domain for internationalization
	 *
	 * Loads translation files to support multiple languages.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'kntnt-unicode-fix', false, dirname( plugin_basename( KNTNT_UNICODE_FIX_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Handle admin actions for scanning and fixing posts
	 *
	 * Processes GET requests for scan and fix actions, including:
	 * - Security verification (nonce and capabilities)
	 * - Input validation and sanitization
	 * - Action execution and result handling
	 * - User feedback via admin notices
	 *
	 * @return void
	 */
	public function handle_admin_actions(): void {
		// Check user capabilities before processing any actions
		if ( ! current_user_can( KNTNT_UNICODE_FIX_CAPABILITY ) ) {
			return;
		}

		// Handle scan action
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'kntnt_unicode_scan' ) {
			if ( ! check_admin_referer( 'kntnt_unicode_scan_nonce' ) ) {
				$this->set_admin_notice( 'error', __( 'Security check failed.', 'kntnt-unicode-fix' ) );
				wp_redirect( admin_url( 'index.php' ) );
				exit;
			}

			// Perform the scan and clear any previously fixed posts from display
			$this->scanner->scan_and_cache();
			delete_transient( 'kntnt-unicode-fix-recently-fixed' );

			$this->set_admin_notice( 'success', __( 'Scan completed successfully.', 'kntnt-unicode-fix' ) );
			wp_redirect( admin_url( 'index.php' ) );
			exit;
		}

		// Handle fix action
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'kntnt_unicode_fix' && isset( $_GET['post_id'] ) ) {
			$this->handle_fix_action( intval( $_GET['post_id'] ) );
		}
	}

	/**
	 * Handle the fix action for a specific post
	 *
	 * Processes the fix request with comprehensive validation and error handling.
	 *
	 * @param int $post_id The ID of the post to fix
	 *
	 * @return void
	 */
	private function handle_fix_action( int $post_id ): void {
		// Validate post ID
		if ( $post_id <= 0 ) {
			$this->set_admin_notice( 'error', __( 'Invalid post ID.', 'kntnt-unicode-fix' ) );
			wp_redirect( admin_url( 'index.php' ) );
			exit;
		}

		// Check if post exists
		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->set_admin_notice( 'error', __( 'Post not found.', 'kntnt-unicode-fix' ) );
			wp_redirect( admin_url( 'index.php' ) );
			exit;
		}

		// Check edit permissions for the specific post
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			$this->set_admin_notice( 'error', __( 'You do not have permission to edit this post.', 'kntnt-unicode-fix' ) );
			wp_redirect( admin_url( 'index.php' ) );
			exit;
		}

		// Verify nonce for security
		if ( ! check_admin_referer( 'kntnt_unicode_fix_' . $post_id ) ) {
			$this->set_admin_notice( 'error', __( 'Security check failed.', 'kntnt-unicode-fix' ) );
			wp_redirect( admin_url( 'index.php' ) );
			exit;
		}

		// Attempt to fix the post
		$result = $this->fixer->fix_post( $post_id );

		if ( $result ) {
			$post_title = get_the_title( $post_id );
			$this->set_admin_notice( 'success', sprintf( __( 'Post "%s" has been fixed and a new revision created.', 'kntnt-unicode-fix' ), $post_title ) );
			// Remove the fixed post from the cached list of corrupt posts
			$this->scanner->remove_from_cache( $post_id );
		}
		else {
			$this->set_admin_notice( 'error', __( 'Failed to fix the post.', 'kntnt-unicode-fix' ) );
		}

		wp_redirect( admin_url( 'index.php' ) );
		exit;
	}

	/**
	 * Display admin notices to the user
	 *
	 * Shows success or error messages stored in transients, then cleans them up.
	 *
	 * @return void
	 */
	public function show_admin_notices(): void {
		// Only show notices to users who can use the plugin
		if ( ! current_user_can( KNTNT_UNICODE_FIX_CAPABILITY ) ) {
			return;
		}

		// Retrieve and display notice if it exists
		$notice = get_transient( KNTNT_UNICODE_FIX_NOTICE_TRANSIENT );
		if ( $notice && is_array( $notice ) ) {
			$class = $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible">';
			echo '<p>' . esc_html( $notice['message'] ) . '</p>';
			echo '</div>';

			// Clean up the notice after displaying
			delete_transient( KNTNT_UNICODE_FIX_NOTICE_TRANSIENT );
		}
	}

	/**
	 * Set an admin notice to be displayed
	 *
	 * Stores a notice message in a transient for display on the next page load.
	 *
	 * @param string $type    The notice type ('success' or 'error')
	 * @param string $message The message to display
	 *
	 * @return void
	 */
	private function set_admin_notice( string $type, string $message ): void {
		set_transient( KNTNT_UNICODE_FIX_NOTICE_TRANSIENT, [
			'type' => $type,
			'message' => $message,
		], 30 ); // Store for 30 seconds
	}

	/**
	 * Plugin activation hook
	 *
	 * Performs setup tasks when the plugin is activated:
	 * - Grants capability to administrators
	 * - Clears any existing transients
	 * - Performs version checks if needed
	 *
	 * @return void
	 */
	public function activate(): void {
		// Grant capability to administrator role
		$admin_role = get_role( 'administrator' );
		if ( $admin_role && ! $admin_role->has_cap( KNTNT_UNICODE_FIX_CAPABILITY ) ) {
			$admin_role->add_cap( KNTNT_UNICODE_FIX_CAPABILITY );
		}

		// Clear all plugin transients on activation
		$this->clear_all_transients();

		// Flush rewrite rules if needed (not required for this plugin)
		// flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation hook
	 *
	 * Performs cleanup tasks when the plugin is deactivated:
	 * - Removes capabilities from all roles
	 * - Clears all transients
	 * - Preserves user data (doesn't delete posts or settings)
	 *
	 * @return void
	 */
	public function deactivate(): void {
		// Remove capability from all roles
		$roles = wp_roles();
		foreach ( $roles->roles as $role_name => $role_info ) {
			$role = get_role( $role_name );
			if ( $role && $role->has_cap( KNTNT_UNICODE_FIX_CAPABILITY ) ) {
				$role->remove_cap( KNTNT_UNICODE_FIX_CAPABILITY );
			}
		}

		// Clear all plugin transients
		$this->clear_all_transients();

		// Flush rewrite rules if needed (not required for this plugin)
		// flush_rewrite_rules();
	}

	/**
	 * Clear all plugin-related transients
	 *
	 * Removes all cached data created by the plugin.
	 *
	 * @return void
	 */
	private function clear_all_transients(): void {
		delete_transient( KNTNT_UNICODE_FIX_TRANSIENT );
		delete_transient( KNTNT_UNICODE_FIX_NOTICE_TRANSIENT );
		delete_transient( 'kntnt-unicode-fix-recently-fixed' );
	}

	/**
	 * Prevent cloning of the singleton instance
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization of the singleton instance
	 *
	 * @return void
	 */
	private function __wakeup() {}

}

// Initialize the plugin
Plugin::get_instance();