/**
 * Save component for the Featured Content Block.
 *
 * Returns null because all rendering is handled server-side by
 * FCB_Block_Register::render(). WordPress stores only the block delimiter
 * comment and attributes in post_content; the HTML is generated fresh on
 * each page load so featured post lists stay current without re-saving pages.
 */

import type { BlockSaveProps } from '@wordpress/blocks';
import type { FeaturedContentAttributes } from './types';

export default function save( _props: BlockSaveProps<FeaturedContentAttributes> ): null {
	return null;
}
