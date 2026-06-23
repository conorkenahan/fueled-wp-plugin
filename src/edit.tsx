/**
 * Gutenberg editor component for the Featured Content Block.
 *
 * Data fetching uses the WordPress data store exclusively via useSelect —
 * no useState, no useEffect, no fetch/apiFetch. The core store handles
 * request de-duplication, in-memory caching, and resolution tracking so
 * the preview updates automatically when attributes change.
 */

import { __ } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import { Fragment } from '@wordpress/element';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import type { BlockEditProps } from '@wordpress/blocks';

// FeaturedContentAttributes is the shared contract between PHP (block.json),
// the save function, and this editor component.
import type { FeaturedContentAttributes } from './types';

// ---------------------------------------------------------------------------
// Local types for the WordPress core data store
//
// The core store returns a richer, HTML-containing shape that differs from
// FeaturedPost in types.ts (which mirrors our custom REST endpoint's plain-
// text output). We keep these types local to this file rather than polluting
// the shared types module with editor-only concerns.
// ---------------------------------------------------------------------------

/** WordPress post record as returned by getEntityRecords with _embed: true. */
interface WPPost {
	id: number;
	title: { rendered: string };
	excerpt: { rendered: string };
	link: string;
	date: string;
	_embedded?: {
		// _embed key is a literal string with a colon — bracket notation required.
		'wp:featuredmedia'?: Array<{ source_url: string; alt_text: string }>;
		author?: Array<{ name: string }>;
	};
}

interface WPCategory {
	id: number;
	name: string;
	slug: string;
}

/** Typed subset of core store selectors used in this component. */
interface CoreStore {
	getEntityRecords: < T >(
		kind: string,
		name: string,
		query?: Record< string, unknown >
	) => T[] | null;
	hasFinishedResolution: (
		selectorName: string,
		args: unknown[]
	) => boolean;
}

// ---------------------------------------------------------------------------
// PostCard — editor canvas preview
// ---------------------------------------------------------------------------

interface EditorPostCardProps {
	post: WPPost;
	showExcerpt: boolean;
	showDate: boolean;
}

/**
 * Renders a single post card inside the editor canvas.
 *
 * Mirrors the structure of templates/block-output.php so what the editor
 * shows matches what the front end renders. Differences: dates use the
 * browser locale (JS), and excerpt HTML tags are stripped client-side
 * to match what wp_trim_words produces server-side.
 */
function PostCard( { post, showExcerpt, showDate }: EditorPostCardProps ): JSX.Element {
	const thumbnail = post._embedded?.[ 'wp:featuredmedia' ]?.[ 0 ];
	const titleText = decodeEntities( post.title.rendered );

	// Excerpt from getEntityRecords is HTML (e.g. "<p>…</p>"). Strip tags so
	// the preview matches the plain-text output of wp_trim_words in PHP.
	const excerptText = decodeEntities(
		post.excerpt.rendered.replace( /<[^>]+>/g, '' ).trim()
	);

	return (
		<article className="fcb-card">
			{ thumbnail && (
				<figure className="fcb-card__figure">
					{ /* Thumbnail link is aria-hidden — the h3 anchor is the
					     focusable element, matching the front-end template. */ }
					<a href={ post.link } tabIndex={ -1 } aria-hidden="true">
						<img
							className="fcb-card__image"
							src={ thumbnail.source_url }
							alt={ thumbnail.alt_text || titleText }
							loading="lazy"
						/>
					</a>
				</figure>
			) }

			<header className="fcb-card__header">
				<h3 className="fcb-card__title">
					<a className="fcb-card__link" href={ post.link }>
						{ titleText }
					</a>
				</h3>

				{ showDate && (
					<time className="fcb-card__date" dateTime={ post.date }>
						{ new Date( post.date ).toLocaleDateString() }
					</time>
				) }
			</header>

			{ showExcerpt && excerptText && (
				<p className="fcb-card__excerpt">{ excerptText }</p>
			) }
		</article>
	);
}

// ---------------------------------------------------------------------------
// Edit
// ---------------------------------------------------------------------------

export default function Edit( {
	attributes,
	setAttributes,
}: BlockEditProps< FeaturedContentAttributes > ): JSX.Element {
	const { postCount, category, layout, showExcerpt, showDate } = attributes;

	// useBlockProps must be called unconditionally (rules of hooks).
	// It merges core editor classes (alignment, selection outline, block gap)
	// with our layout class so the canvas matches the front end.
	const blockProps = useBlockProps( {
		className: `fcb-cards fcb-cards--${ layout }`,
	} );

	// -----------------------------------------------------------------------
	// Data: categories
	//
	// Loaded once — the list doesn't change during an editing session.
	// per_page: -1 fetches all categories in a single request; add pagination
	// only if the site has hundreds of terms.
	// -----------------------------------------------------------------------
	const { categories, isCategoriesLoaded } = useSelect( ( select ) => {
		const store = select( 'core' ) as unknown as CoreStore;
		const catQuery = { per_page: -1 };
		return {
			categories: store.getEntityRecords< WPCategory >(
				'taxonomy',
				'category',
				catQuery
			),
			// hasFinishedResolution tells us definitively whether the resolver
			// completed for these exact args — checking for null alone is
			// ambiguous because null is also the value before any fetch starts.
			isCategoriesLoaded: store.hasFinishedResolution(
				'getEntityRecords',
				[ 'taxonomy', 'category', catQuery ]
			),
		};
	}, [] );

	// Resolve the saved category slug to a numeric term ID.
	// The core store's posts endpoint filters by ID, not slug.
	// undefined while categories are still loading.
	const resolvedCategoryId = categories?.find(
		( cat ) => cat.slug === category
	)?.id;

	// -----------------------------------------------------------------------
	// Data: posts
	//
	// Depends on resolvedCategoryId so the dep array re-triggers this selector
	// once categories finish loading and the ID becomes available.
	//
	// When a category is selected but categories haven't loaded yet we mark
	// isLoading=true to avoid a flash of unfiltered results before the ID
	// resolves.
	// -----------------------------------------------------------------------
	const { posts, isPostsLoading } = useSelect(
		( select ) => {
			const store = select( 'core' ) as unknown as CoreStore;

			const query: Record< string, unknown > = {
				per_page: postCount,
				// _embed fetches featured media + author in one request,
				// preventing N+1 thumbnail URL lookups.
				_embed: true,
			};

			if ( category && resolvedCategoryId !== undefined ) {
				query.categories = [ resolvedCategoryId ];
			}

			return {
				posts: store.getEntityRecords< WPPost >(
					'postType',
					'post',
					query
				),
				isPostsLoading: ! store.hasFinishedResolution(
					'getEntityRecords',
					[ 'postType', 'post', query ]
				),
			};
		},
		// resolvedCategoryId is in deps so a slug→ID resolution triggers a re-fetch.
		[ postCount, category, resolvedCategoryId ]
	);

	// Show a spinner while either data dependency is still in flight.
	const isLoading =
		isPostsLoading || ( !! category && ! isCategoriesLoaded );

	// -----------------------------------------------------------------------
	// Dropdown options for the category selector
	// -----------------------------------------------------------------------
	const categoryOptions = [
		{ label: __( 'All categories', 'featured-content-block' ), value: '' },
		...( categories?.map( ( cat ) => ( {
			label: cat.name,
			value: cat.slug,
		} ) ) ?? [] ),
	];

	// -----------------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------------
	return (
		// Fragment lets us return InspectorControls + the canvas element without
		// adding an extra wrapper node to the DOM. InspectorControls is portal-
		// rendered into the sidebar — it does not appear inside blockProps.
		<Fragment>
			<InspectorControls>
				<PanelBody
					title={ __( 'Display Settings', 'featured-content-block' ) }
					initialOpen={ true }
				>
					<RangeControl
						label={ __( 'Number of posts', 'featured-content-block' ) }
						value={ postCount }
						onChange={ ( value ) =>
							setAttributes( { postCount: value ?? 5 } )
						}
						min={ 1 }
						max={ 20 }
					/>

					<SelectControl
						label={ __( 'Category', 'featured-content-block' ) }
						value={ category }
						// Options populate once isCategoriesLoaded; before that
						// only "All categories" is shown.
						options={ categoryOptions }
						onChange={ ( value ) =>
							setAttributes( { category: value } )
						}
					/>

					<SelectControl
						label={ __( 'Layout', 'featured-content-block' ) }
						value={ layout }
						options={ [
							{
								label: __( 'Grid', 'featured-content-block' ),
								value: 'grid',
							},
							{
								label: __( 'List', 'featured-content-block' ),
								value: 'list',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( {
								layout: value as FeaturedContentAttributes[ 'layout' ],
							} )
						}
					/>

					<ToggleControl
						label={ __( 'Show excerpt', 'featured-content-block' ) }
						checked={ showExcerpt }
						onChange={ ( value ) =>
							setAttributes( { showExcerpt: value } )
						}
					/>

					<ToggleControl
						label={ __( 'Show date', 'featured-content-block' ) }
						checked={ showDate }
						onChange={ ( value ) =>
							setAttributes( { showDate: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ isLoading && (
					<div className="fcb-editor-loading">
						{ /* Spinner is a core WP component — matches the loading
						     indicator used throughout the block editor UI. */ }
						<Spinner />
					</div>
				) }

				{ ! isLoading && ( ! posts || posts.length === 0 ) && (
					<p className="fcb-editor-empty">
						{ __(
							'No posts found. Try adjusting the count or category.',
							'featured-content-block'
						) }
					</p>
				) }

				{ ! isLoading &&
					posts?.map( ( post ) => (
						<PostCard
							key={ post.id }
							post={ post }
							showExcerpt={ showExcerpt }
							showDate={ showDate }
						/>
					) ) }
			</div>
		</Fragment>
	);
}
