/**
 * TypeScript interfaces for the Featured Content Block.
 *
 * All types are derived from two sources of truth:
 *   - block.json  → FeaturedContentAttributes
 *   - FCB_REST_API::get_featured_posts() return shape → FeaturedPost
 *
 * Keep FeaturedContentAttributes in sync with block.json `attributes`.
 * Keep FeaturedPost in sync with the array built in class-rest-api.php.
 */

// ---------------------------------------------------------------------------
// Block attributes  (mirrors the `attributes` object in block.json)
// ---------------------------------------------------------------------------

export type FeaturedContentAttributes = {
	/** Number of posts to display. Min 1, max 20. */
	postCount: number;
	/** Category slug to filter by. Empty string means all categories. */
	category: string;
	/** Card layout variant. */
	layout: 'grid' | 'list';
	/** Whether to render the post excerpt inside each card. */
	showExcerpt: boolean;
	/** Whether to render the publication date inside each card. */
	showDate: boolean;
}

// ---------------------------------------------------------------------------
// REST API response shapes  (mirrors FCB_REST_API::get_featured_posts)
// ---------------------------------------------------------------------------

/**
 * A single post item returned by /wp-json/featured-content-block/v1/posts.
 *
 * Property names are snake_case to match the JSON keys returned by the PHP
 * endpoint — no client-side transform is applied.
 */
export interface FeaturedPost {
	id: number;
	title: string;
	excerpt: string;
	permalink: string;
	/** Null when the post has no featured image set. */
	featured_image_url: string | null;
	author: string;
	/** ISO 8601 date string (e.g. "2024-03-15T10:30:00+00:00"). */
	date: string;
}

/**
 * The endpoint returns a flat array of posts directly — there is no
 * pagination envelope because no_found_rows is set in WP_Query.
 */
export type FeaturedPostsResponse = FeaturedPost[];

// ---------------------------------------------------------------------------
// Component prop types
// ---------------------------------------------------------------------------

/** Props for the PostCard presentational component in edit.tsx. */
export interface PostCardProps {
	post: FeaturedPost;
	layout: FeaturedContentAttributes['layout'];
	showExcerpt: boolean;
	showDate: boolean;
}

/** Props for the CategorySelector control in the Inspector sidebar. */
export interface CategorySelectorProps {
	/** Current category slug value from block attributes. */
	value: string;
	onChange: ( slug: string ) => void;
}
