/**
 * Pure helpers for the brain/section edit component. Kept free of React and
 * WordPress package imports so they are trivial to unit test.
 */

/**
 * Parse a section-picker text input into a positive integer id, or null if
 * the value isn't a usable section id.
 *
 * @param {string|number} value Raw input value.
 * @return {number|null} Parsed section id, or null when invalid.
 */
export function parseSectionIdInput(value) {
	const id = Number(value);
	if (!Number.isInteger(id) || id <= 0) {
		return null;
	}
	return id;
}

/**
 * Derive the section's lease state from the REST-computed lease holder
 * (`ab_lease_holder`, resolved server-side from `_edit_lock`) and the
 * `ab_lease_expires` meta. Deliberately keyed on the *holder*, not the
 * section's long-term `ab_section_owner`: a curator may claim a lease on
 * an owner's behalf (see Lease::currentHolder's PHP docblock), so the two
 * can differ. Kept free of React/WP imports (see file docblock) -- label
 * text formatting (which needs the holder's display name and a localized
 * time) lives in status.js.
 *
 * @param {number|string|null|undefined} holderId     `ab_lease_holder` REST field (user id), or null/0 if unclaimed.
 * @param {number|string|null|undefined} leaseExpires `ab_lease_expires` meta (unix seconds).
 * @param {number}                       nowSeconds   Current time in unix seconds (injected for testability).
 * @return {{state: ('unclaimed'|'claimed'|'expired'), expiresAt: number}} Lease state and expiry (0 when not applicable).
 */
export function getLeaseState(
	holderId,
	leaseExpires,
	nowSeconds = Date.now() / 1000
) {
	if (!holderId) {
		return { state: 'unclaimed', expiresAt: 0 };
	}

	const expiresAt = Number(leaseExpires) || 0;

	if (expiresAt > nowSeconds) {
		return { state: 'claimed', expiresAt };
	}

	return { state: 'expired', expiresAt };
}

/**
 * Resolve the inner-blocks allow-list from a section's `ab_allowed_blocks`
 * meta, falling back to "no restriction" when the meta is missing or empty.
 *
 * @param {Object|null|undefined} meta Section entity meta.
 * @return {string[]|undefined} Allowed block names, or undefined for no restriction.
 */
export function getAllowedBlocks(meta) {
	const allowed = meta?.ab_allowed_blocks;
	if (Array.isArray(allowed) && allowed.length > 0) {
		return allowed;
	}
	return undefined;
}

/**
 * Show an agent's FQDN (`claude-code-a3f2c819.agnt.brain.example.org`) as its
 * plain name (`claude-code`). Anything that is not an agent FQDN, such as a
 * person's display name, is returned unchanged.
 *
 * @param {string|null|undefined} name User display name.
 * @return {string} Friendly name, or an empty string when there is no name.
 */
export function friendlyAgentName(name) {
	if (!name) {
		return '';
	}
	const match =
		/^([a-z][a-z0-9-]{1,30}[a-z0-9])-[0-9a-f]{8}\.[a-z][a-z0-9-]+\.[a-z0-9.-]+$/.exec(
			name
		);
	return match ? match[1] : name;
}
