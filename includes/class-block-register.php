<?php
/**
 * Block registration for the Featured Content Block.
 *
 * Registers the Gutenberg block via block.json metadata and enqueues the
 * compiled editor/front-end assets. Separating block registration from the
 * main plugin class keeps responsibilities clean and makes unit testing easier.
 *
 * @package FeaturedContentBlock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles block registration and asset enqueueing.
 */
class FCB_Block_Register {

	/**
	 * Block name (must match "name" in block.json).
	 *
	 * @var string
	 */
	const BLOCK_NAME = 'featured-content-block/featured-posts';

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
	 * @param string $plugin_dir Absolute path to the plugin root (trailing slash).
	 * @param string $plugin_url Absolute URL to the plugin root (trailing slash).
	 */
	public function __construct( string $plugin_dir, string $plugin_url ) {
		$this->plugin_dir = $plugin_dir;
		$this->plugin_url = $plugin_url;
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Register the block. Called on the 'init' hook.
	 *
	 * Passing the plugin directory to register_block_type() tells WordPress to
	 * read block.json from that path, which keeps script/style handles and block
	 * metadata in a single source of truth.
	 */
	public function register(): void {
		register_block_type(
			$this->plugin_dir,
			array(
				// Dynamic block pattern: PHP renders the front end on every page
				// load so that post content stays fresh without re-saving pages.
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Server-side render
	// -------------------------------------------------------------------------

	/**
	 * Server-side render callback for the block.
	 *
	 * Invoked by WordPress for every block instance on the front end. Pulls
	 * post data through our own REST endpoint (via rest_do_request) so the
	 * transient caching logic in FCB_REST_API is the single code path for
	 * both internal PHP renders and external API consumers.
	 *
	 * @param array  $attributes Block attributes saved by the editor.
	 * @param string $content    Inner block content (unused — this block has no inner blocks).
	 * @return string            HTML markup for the block.
	 */
	public function render( array $attributes, string $content ): string {
		// Sanitize attribute values regardless of what the editor stored.
		// absint ensures postCount can never produce a negative WP_Query.
		$post_count = absint( $attributes['postCount'] ?? 5 );
		$category   = sanitize_text_field( $attributes['category'] ?? '' );
		$layout     = in_array( $attributes['layout'] ?? 'grid', array( 'grid', 'list' ), true )
			? $attributes['layout']
			: 'grid';
		$show_excerpt = (bool) ( $attributes['showExcerpt'] ?? true );
		$show_date    = (bool) ( $attributes['showDate'] ?? true );

		// Route through our own REST endpoint so the transient cache is shared
		// between server renders and any external fetch calls.
		$request = new WP_REST_Request( 'GET', '/featured-content-block/v1/posts' );
		$request->set_param( 'count', $post_count );
		if ( '' !== $category ) {
			$request->set_param( 'category', $category );
		}

		$response = rest_do_request( $request );

		// Bail gracefully on a REST error rather than outputting a broken block.
		if ( $response->is_error() ) {
			return '';
		}

		$posts = $response->get_data();

		// Nothing to render — return early so no empty wrapper div appears in markup.
		if ( empty( $posts ) ) {
			return '';
		}

		// Build wrapper attributes before ob_start — get_block_wrapper_attributes()
		// is stateful and must be called exactly once per block render. The template
		// echoes it directly onto the <section> tag, so render() must not also wrap.
		$wrapper_attributes = get_block_wrapper_attributes(
			array( 'class' => 'fcb-cards fcb-cards--' . $layout )
		);

		// All three variables ($posts, $attributes, $wrapper_attributes) are in
		// local scope and therefore visible inside the included template file.
		ob_start();
		include $this->plugin_dir . 'templates/block-output.php';
		return (string) ob_get_clean();
	}
}
