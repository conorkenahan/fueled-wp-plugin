<?php
/**
 * REST API handler for the Featured Content Block.
 *
 * Registers a custom `/wp-json/featured-content-block/v1/posts` endpoint that
 * returns curated post data. Responses are stored in a transient so repeated
 * requests avoid redundant database queries.
 *
 * @package FeaturedContentBlock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles custom REST endpoint registration and transient caching.
 */
class FCB_REST_API {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'featured-content-block/v1';

	/**
	 * Route path for the featured posts endpoint.
	 *
	 * @var string
	 */
	const ROUTE = '/posts';

	/**
	 * Prefix used for all transient keys created by this class.
	 *
	 * A prefix (rather than a single key) lets us cache each unique param
	 * combination independently and bulk-delete them all on invalidation.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'fcb_';

	/**
	 * Backwards-compatible single transient key (used by deactivation cleanup).
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'fcb_featured_posts';

	/**
	 * How long (seconds) a transient lives before expiring.
	 *
	 * @var int
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Register the REST route. Called on the 'rest_api_init' hook.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_featured_posts' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					// Number of posts to return. Capped at 20 to prevent heavy queries.
					'count'    => array(
						'description'       => __( 'Number of posts to return.', 'featured-content-block' ),
						'type'              => 'integer',
						'default'           => 5,
						'minimum'           => 1,
						'maximum'           => 20,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					// Optional WP category slug to filter results.
					'category' => array(
						'description'       => __( 'Category slug to filter posts by.', 'featured-content-block' ),
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permissions
	// -------------------------------------------------------------------------

	/**
	 * Allow public read access to this endpoint.
	 *
	 * Featured posts are published, public content — no authentication needed.
	 * Returning true unconditionally is intentional and not a security gap.
	 *
	 * @param WP_REST_Request $request Incoming request object.
	 * @return true
	 */
	public function permissions_check( WP_REST_Request $request ): bool {
		return true;
	}

	// -------------------------------------------------------------------------
	// Callback
	// -------------------------------------------------------------------------

	/**
	 * Main endpoint callback. Returns featured posts, pulling from cache when possible.
	 *
	 * @param WP_REST_Request $request Incoming request object.
	 * @return WP_REST_Response
	 */
	public function get_featured_posts( WP_REST_Request $request ): WP_REST_Response {
		$count    = (int) $request->get_param( 'count' );
		$category = (string) $request->get_param( 'category' );

		// Build a unique cache key for this exact param combination so a request
		// for 3 posts in "news" never collides with one for 5 posts in "events".
		$cache_key = self::TRANSIENT_PREFIX . md5( wp_json_encode( array( $count, $category ) ) );

		// Return cached data immediately if it exists — avoids any DB hit.
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}

		// Build query args. no_found_rows skips the COUNT(*) query that powers
		// pagination, which we don't need here and would double the DB cost.
		$query_args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $count,
			'no_found_rows'  => true,
		);

		// Add a category filter only when a slug was provided.
		if ( '' !== $category ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'category',
					'field'    => 'slug',
					'terms'    => $category,
				),
			);
		}

		$query = new WP_Query( $query_args );
		$posts = array();

		foreach ( $query->posts as $post ) {
			// Resolve the author display name once per post — get_the_author_meta
			// accepts a post ID directly so we don't need to set up the global.
			$author_name = get_the_author_meta( 'display_name', (int) $post->post_author );

			// get_the_post_thumbnail_url returns false when no thumbnail is set;
			// normalise to null so JSON consumers get a consistent type.
			$thumbnail_url = get_the_post_thumbnail_url( $post->ID, 'medium' );

			$posts[] = array(
				'id'                 => $post->ID,
				'title'              => get_the_title( $post->ID ),
				'excerpt'            => wp_trim_words( get_the_excerpt( $post->ID ), 20, '…' ),
				'permalink'          => get_permalink( $post->ID ),
				'featured_image_url' => $thumbnail_url ? $thumbnail_url : null,
				'author'             => $author_name,
				'date'               => get_the_date( 'c', $post->ID ), // ISO 8601
			);
		}

		// Cache the shaped array so subsequent identical requests skip the query.
		set_transient( $cache_key, $posts, self::CACHE_TTL );

		return rest_ensure_response( $posts );
	}

	// -------------------------------------------------------------------------
	// Cache invalidation
	// -------------------------------------------------------------------------

	/**
	 * Delete all fcb_ transients when a post is saved or changes status.
	 *
	 * We bulk-delete by prefix (via a direct $wpdb query on the options table)
	 * rather than tracking individual keys, because any post change could affect
	 * any cached query combination.
	 *
	 * Hooked onto 'save_post' and 'transition_post_status' in the main plugin class.
	 *
	 * @param int $post_id The post ID being saved (or 0 on status transitions).
	 */
	public function invalidate_cache( int $post_id ): void {
		// Ignore autosaves — they don't change published content.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Only bust the cache for publicly visible post types so that saving a
		// nav menu item or ACF field group doesn't trigger a needless purge.
		$post_type = get_post_type( $post_id );
		if ( $post_type ) {
			$post_type_obj = get_post_type_object( $post_type );
			if ( ! $post_type_obj || ! $post_type_obj->public ) {
				return;
			}
		}

		global $wpdb;

		// Transients are stored in wp_options as '_transient_{key}'.
		// LIKE 'prefix%' on the option_name column is the standard bulk-delete
		// approach; the options table has an index on option_name so this is fast.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::TRANSIENT_PREFIX ) . '%'
			)
		);
	}
}
