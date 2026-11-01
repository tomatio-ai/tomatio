import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { store as coreStore, useEntityBlockEditor } from '@wordpress/core-data';
import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';

import SectionPicker from './picker';
import SectionStatus from './status';
import { getAllowedBlocks } from './utils';

const SECTION_POST_TYPE = 'ab_artifact_section';

/**
 * Inline editor for one section post, synced-pattern style: content lives on
 * the child `ab_artifact_section` post and is edited in place here via
 * `useEntityBlockEditor`, the same primitive core/template-part uses.
 *
 * @param {Object} props
 * @param {number} props.sectionId  Section post id (already validated non-empty by the caller).
 * @param {Object} props.blockProps Props from the parent `Edit`'s `useBlockProps()` call.
 */
function SectionEditor({ sectionId, blockProps }) {
	const { record, hasResolved } = useSelect(
		(select) => {
			const { getEntityRecord, hasFinishedResolution } =
				select(coreStore);
			return {
				record: getEntityRecord(
					'postType',
					SECTION_POST_TYPE,
					sectionId
				),
				hasResolved: hasFinishedResolution('getEntityRecord', [
					'postType',
					SECTION_POST_TYPE,
					sectionId,
				]),
			};
		},
		[sectionId]
	);

	const [blocks, onInput, onChange] = useEntityBlockEditor(
		'postType',
		SECTION_POST_TYPE,
		{ id: sectionId }
	);

	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'agent-brain-section__content' },
		{
			value: blocks,
			onInput,
			onChange,
			allowedBlocks: getAllowedBlocks(record?.meta),
			renderAppender: InnerBlocks.ButtonBlockAppender,
		}
	);

	return (
		<>
			{/* Owner, state, lease and version are editor chrome, not content: sidebar, never canvas. */}
			<InspectorControls>
				<PanelBody title={__('Section', 'agent-brain')}>
					<SectionStatus record={record} hasResolved={hasResolved} />
				</PanelBody>
			</InspectorControls>
			<div {...blockProps}>
				<div {...innerBlocksProps} />
			</div>
		</>
	);
}

export default function Edit({ attributes, setAttributes }) {
	const { sectionId } = attributes;
	const blockProps = useBlockProps();

	if (!sectionId) {
		return (
			<div {...blockProps}>
				<SectionPicker
					onPick={(id) => setAttributes({ sectionId: id })}
				/>
			</div>
		);
	}

	return <SectionEditor sectionId={sectionId} blockProps={blockProps} />;
}
