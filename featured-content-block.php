<?php
/**
 * Plugin Name: Featured Content Block
 * Plugin URI:  https://example.com/featured-content-block
 * Description: A custom Gutenberg block that lets editors curate featured articles
 *              in a card layout, backed by a REST API endpoint with transient caching.
 * Version:     1.0.0
 * Author:      Conor Kenahan
 * License:     GPL-2.0-or-later
 * Text Domain: featured-content-block
 *
 * @package FeaturedContentBlock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class. Responsible for bootstrapping all sub-systems.
 *
 * Follows the single-instance (singleton-lite) pattern so hooks are registered
 * exactly once even if the file is included more than once.
 */
class Featured_Content_Block {

	/**
	 * Plugin version – bump on every release.
	 *
	 * @var string
	 */
	const VERSION = '1.0.0';

	/**
	 * Holds the single instance of this class.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Absolute path to the plugin directory (trailing slash).
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Absolute URL to the plugin directory (trailing slash).
	 *
	 * @var string
	 */
	private string $plugin_url;

	/**
	 * REST API handler instance.
	 *
	 * @var FCB_REST_API
	 */
	private FCB_REST_API $rest_api;

	/**
	 * Block registration handler instance.
	 *
	 * @var FCB_Block_Register
	 */
	private FCB_Block_Register $block_register;

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	/**
	 * Constructor – store paths, load dependencies, and wire up hooks.
	 *
	 * Private so callers must go through ::instance().
	 */
	private function __construct() {
		$this->plugin_dir = plugin_dir_path( __FILE__ );
		$this->plugin_url = plugin_dir_url( __FILE__ );

		// Pull in the two sub-system classes before instantiating them.
		require_once $this->plugin_dir . 'includes/class-rest-api.php';
		require_once $this->plugin_dir . 'includes/class-block-register.php';

		$this->rest_api       = new FCB_REST_API();
		$this->block_register = new FCB_Block_Register( $this->plugin_dir, $this->plugin_url );

		$this->init_hooks();
	}

	/**
	 * Register WordPress action hooks.
	 *
	 * Kept intentionally thin – real work lives in the sub-classes.
	 */
	private function init_hooks(): void {
		// REST endpoint registration runs after rewrite rules are loaded.
		add_action( 'rest_api_init', array( $this->rest_api, 'register_routes' ) );

		// Block registration must run on 'init' so block type data is available
		// before any template/shortcode tries to render a block.
		add_action( 'init', array( $this->block_register, 'register' ) );

		// front-end CSS ("style") and frontend.js ("viewScript") are declared in
		// block.json and enqueued automatically by WordPress when the block is
		// present on a page. No wp_enqueue_scripts hook needed here.

		// Bust the REST transient whenever a post is saved.
		// save_post fires on create, update, and every status transition, so
		// transition_post_status is redundant — and its callback signature
		// ($new_status, $old_status, $post) doesn't match invalidate_cache(int $post_id).
		add_action( 'save_post', array( $this->rest_api, 'invalidate_cache' ) );
	}

	// -------------------------------------------------------------------------
	// Activation / Deactivation
	// -------------------------------------------------------------------------

	/**
	 * Runs on plugin activation.
	 *
	 * Flushes rewrite rules so any REST routes registered by this plugin are
	 * immediately reachable without a manual Settings → Permalinks save.
	 */
	public static function activate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * Cleans up the transient cache so stale data doesn't linger after the
	 * plugin is toggled off and on again.
	 */
	public static function deactivate(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . FCB_REST_API::TRANSIENT_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . FCB_REST_API::TRANSIENT_PREFIX ) . '%'
			)
		);

		flush_rewrite_rules();
	}

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	/**
	 * Return the single shared instance, creating it on first call.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

// ---------------------------------------------------------------------------
// Lifecycle hooks
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, array( 'Featured_Content_Block', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Featured_Content_Block', 'deactivate' ) );

// Boot the plugin after all plugins have loaded so inter-plugin dependencies
// (e.g. a custom post type registered by another plugin) are already in place.
add_action( 'plugins_loaded', array( 'Featured_Content_Block', 'instance' ) );
