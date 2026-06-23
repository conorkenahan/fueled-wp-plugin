/**
 * Block registration entry point.
 *
 * @wordpress/scripts compiles this file as the webpack entry point and outputs
 * build/index.js, which block.json's "editorScript" field points to.
 * WordPress loads that script only inside the block editor, never on the front end.
 */

import { registerBlockType } from '@wordpress/blocks';
import type { BlockConfiguration } from '@wordpress/blocks';

import type { FeaturedContentAttributes } from './types';

// Webpack (via @wordpress/scripts) resolves JSON imports natively.
// Casting to BlockConfiguration keeps title, description, attributes,
// supports, and keywords in sync with block.json without duplicating them.
// The cast is necessary because JSON imports type supports.align as string[]
// rather than the BlockAlignment literal union.
import metadata from '../block.json';

import Edit from './edit';
import save from './save';

registerBlockType< FeaturedContentAttributes >(
	metadata as unknown as BlockConfiguration< FeaturedContentAttributes >,
	{ edit: Edit, save }
);
