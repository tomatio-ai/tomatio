import { __, sprintf } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { Notice, Spinner } from '@wordpress/components';

import { friendlyAgentName, getLeaseState } from './utils';

/**
 * Resolves a user id to a friendly display name (an agent's FQDN shown as its
 * plain name) via core-data's `getUser`,
 * falling back to `user #<id>` while the request is in flight or if the
 * user record isn't resolvable (e.g. no permission) -- never a blank or
 * misleading label (fail loud, L26).
 *
 * @param {number} userId User id, or falsy for "no user".
 * @return {string|null} Display name, `user #<id>` fallback, or null for a falsy userId.
 */
function useDisplayName(userId) {
	const user = useSelect(
		(select) => (userId ? select(coreStore).getUser(userId) : null),
		[userId]
	);

	if (!userId) {
		return null;
	}

	return (
		friendlyAgentName(user?.name) ||
		sprintf(
			/* translators: %d: WordPress user id. */
			__('user #%d', 'agent-brain'),
			userId
		)
	);
}

function formatLeaseTime(expiresAtSeconds) {
	return new Date(expiresAtSeconds * 1000).toLocaleTimeString([], {
		hour: '2-digit',
		minute: '2-digit',
	});
}

/**
 * Read-only strip showing a section's owner, lease/lock state, and version
 * as three separate, clearly-separated chips (Owner: X · <lease state> ·
 * vN) -- see StatusFields.php for where owner/version/lease/lease-holder
 * come from over REST.
 *
 * @param {Object}      props
 * @param {Object|null} props.record      Section entity record (or null while loading).
 * @param {boolean}     props.hasResolved Whether the entity record request has finished.
 */
export default function SectionStatus({ record, hasResolved }) {
	const owner = record?.meta?.ab_section_owner || 0;
	const version = record?.meta?.ab_version;
	const leaseExpires = record?.meta?.ab_lease_expires;
	const leaseHolder = record?.ab_lease_holder || 0;
	const sectionState = record?.meta?.ab_section_state || 'drafting';

	const ownerName = useDisplayName(owner);
	const holderName = useDisplayName(leaseHolder);

	if (!hasResolved) {
		return <Spinner />;
	}

	if (!record) {
		return (
			<Notice status="error" isDismissible={false}>
				{__('Section not found.', 'agent-brain')}
			</Notice>
		);
	}

	const ownerLabel = owner ? ownerName : __('none', 'agent-brain');

	const stateLabels = {
		drafting: __('Drafting', 'agent-brain'),
		submitted: __('Submitted', 'agent-brain'),
		approved: __('Approved', 'agent-brain'),
		changes_requested: __('Changes requested', 'agent-brain'),
	};
	const stateLabel = stateLabels[sectionState] || sectionState;

	const { state, expiresAt } = getLeaseState(leaseHolder, leaseExpires);
	let leaseLabel;
	if (state === 'claimed') {
		leaseLabel = sprintf(
			/* translators: 1: claimant display name, 2: lease expiry time. */
			__('Claimed by %1$s until %2$s', 'agent-brain'),
			holderName,
			formatLeaseTime(expiresAt)
		);
	} else if (state === 'expired') {
		leaseLabel = __('Lease expired', 'agent-brain');
	} else {
		leaseLabel = __('Unclaimed', 'agent-brain');
	}

	return (
		<div className="agent-brain-section__status">
			<span className="agent-brain-section__owner">
				{sprintf(
					/* translators: %s: display name of the section owner, or "none". */
					__('Owner: %s', 'agent-brain'),
					ownerLabel
				)}
			</span>
			<span className="agent-brain-section__separator" aria-hidden="true">
				{' · '}
			</span>
			<span className="agent-brain-section__state">{stateLabel}</span>
			<span className="agent-brain-section__separator" aria-hidden="true">
				{' · '}
			</span>
			<span className="agent-brain-section__lease">{leaseLabel}</span>
			<span className="agent-brain-section__separator" aria-hidden="true">
				{' · '}
			</span>
			<span className="agent-brain-section__version">
				{sprintf(
					/* translators: %d: section version number. */
					__('v%d', 'agent-brain'),
					version || 1
				)}
			</span>
		</div>
	);
}
