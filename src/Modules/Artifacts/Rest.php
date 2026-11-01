<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

/**
 * REST collection-query filtering for ab_artifact / ab_artifact_section
 * (Artifact fix wave, 2026-09-27). Single-item read/edit/delete already flow
 * through ObjectAccess::mapMetaCap because both CPTs are map_meta_cap=true;
 * core's REST posts controller does NOT narrow the *collection* query
 * (`GET /wp/v2/ab-artifacts`, no id) the same way, so without this a
 * non-curator's list request would still be handed every private post of
 * that type before per-item visibility is considered. This filter restricts
 * the underlying WP_Query for non-curators to only the artifacts they lead
 * / sections they own; curators are left unrestricted.
 */
class Rest {

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function filterArtifactQuery( array $args, \WP_REST_Request $request ): array {
		// Every ab_artifact is created 'private' and agents are never
		// granted the WP-core-derived 'read_private_posts' capability (only
		// ab_manage_artifacts holders get it, via ObjectAccess::userHasCap),
		// so the REST controller's own capability-gated post_status default
		// would otherwise exclude every row before this filter even runs. Force it back in here; the real
		// access boundary is the narrowing below plus core's per-item
		// check_read_permission(), which still calls our map_meta_cap filter.
		$args['post_status'] = 'private';

		$userId = get_current_user_id();

		if ( ObjectAccess::userIsCurator( $userId ) ) {
			return $args;
		}

		// No curator cap (including anonymous, userId 0, which can never
		// match a real ab_lead_agent value): fail closed to "leads nothing"
		// rather than falling through to an unfiltered query.
		$metaQuery          = is_array( $args['meta_query'] ?? null ) ? $args['meta_query'] : [];
		$metaQuery[]         = [
			'key'     => Cpts::META_LEAD_AGENT,
			'value'   => $userId,
			'compare' => '=',
		];
		$args['meta_query'] = $metaQuery;

		return $args;
	}

	/**
	 * Sections a non-curator may list: ones they own, plus every section of
	 * an artifact they lead (a lead can see the whole outline they're
	 * responsible for, not only sections assigned to themselves). Combining
	 * "owned OR under a led artifact" isn't expressible as a single
	 * meta_query (it spans two different post types' ownership), so this
	 * resolves both id sets first and narrows the collection query to their
	 * union via post__in.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function filterSectionQuery( array $args, \WP_REST_Request $request ): array {
		// See filterArtifactQuery()'s docblock note on post_status.
		$args['post_status'] = 'private';

		$userId = get_current_user_id();

		if ( ObjectAccess::userIsCurator( $userId ) ) {
			return $args;
		}

		$ledArtifactIds = $userId > 0 ? get_posts( [
			'post_type'   => Cpts::ARTIFACT_POST_TYPE,
			'post_status' => 'any',
			'fields'      => 'ids',
			'numberposts' => -1,
			'meta_key'    => Cpts::META_LEAD_AGENT,
			'meta_value'  => (string) $userId,
		] ) : [];

		$ownedSectionIds = $userId > 0 ? get_posts( [
			'post_type'   => Cpts::SECTION_POST_TYPE,
			'post_status' => 'any',
			'fields'      => 'ids',
			'numberposts' => -1,
			'meta_key'    => Cpts::META_SECTION_OWNER,
			'meta_value'  => (string) $userId,
		] ) : [];

		$childSectionIds = $ledArtifactIds !== [] ? get_posts( [
			'post_type'       => Cpts::SECTION_POST_TYPE,
			'post_status'     => 'any',
			'fields'          => 'ids',
			'numberposts'     => -1,
			'post_parent__in' => $ledArtifactIds,
		] ) : [];

		$allowedIds = array_values( array_unique( array_map( 'intval', array_merge( $ownedSectionIds, $childSectionIds ) ) ) );

		// Empty allowlist must still yield an empty result set, never an
		// unfiltered one — 0 is not a valid post id, so post__in => [0]
		// deterministically matches nothing.
		$args['post__in'] = $allowedIds === [] ? [ 0 ] : $allowedIds;

		return $args;
	}

	/**
	 * ART-11: refuse every create/update through core's posts controller for
	 * both post types. That path (cookie sessions and application passwords;
	 * OAuth bearers are already refused off the MCP route) would skip
	 * BlockSanitizer, the expected_version compare-and-set, the edit lease
	 * and the approved-section lock, all of which live in ArtifactService.
	 * Reads, and wp-admin's classic artifact screen, are unaffected.
	 *
	 * @param \stdClass|\WP_Error $preparedPost
	 * @return \stdClass|\WP_Error
	 */
	public static function refuseCoreWrites( $preparedPost, \WP_REST_Request $request ) {
		return new \WP_Error(
			'ab_rest_write_refused',
			__( 'Artifacts and their sections cannot be changed through the core REST posts API; use the TomaTío tools.', 'agent-brain' ),
			[ 'status' => 403 ]
		);
	}
}
