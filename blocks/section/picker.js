import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Placeholder, TextControl, Button } from '@wordpress/components';

import { parseSectionIdInput } from './utils';

/**
 * Placeholder shown while the block has no `sectionId` attribute yet: lets
 * the curator pick which artifact section this block should render.
 *
 * @param {Object}               props
 * @param {(id: number) => void} props.onPick Called with the parsed section id once chosen.
 */
export default function SectionPicker({ onPick }) {
	const [value, setValue] = useState('');
	const sectionId = parseSectionIdInput(value);

	return (
		<Placeholder
			icon="text-page"
			label={__('TomaTío Section', 'agent-brain')}
			instructions={__(
				'Enter the section ID to edit its content inline.',
				'agent-brain'
			)}
		>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="number"
				min={1}
				value={value}
				onChange={setValue}
				label={__('Section ID', 'agent-brain')}
				hideLabelFromVision
				placeholder={__('Section ID', 'agent-brain')}
			/>
			<Button
				variant="primary"
				disabled={!sectionId}
				onClick={() => onPick(sectionId)}
			>
				{__('Use section', 'agent-brain')}
			</Button>
		</Placeholder>
	);
}
