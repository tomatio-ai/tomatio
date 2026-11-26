import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';

registerBlockType(metadata, {
	edit: Edit,
	// Dynamic block: the Artifacts PHP module renders it server-side.
	save: () => null,
});
