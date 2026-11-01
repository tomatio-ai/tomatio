<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Core\WriteLimiter;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;
use WP_Error;

/**
 * MCP abilities for share links: artifact-share-create / -revoke / -list.
 * Authority: the artifact's lead, a curator, or an ab_manage person; an agent
 * never needs ab_publish. The URL (with the token) is returned once, by create.
 */
final class ShareAbilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::registerOnce(
			AbilityNames::ARTIFACT_SHARE_ANALYTICS,
			[
				'label'               => __( 'Read artifact share analytics', 'agent-brain' ),
				'description'         => __( 'Anonymous per-day view analytics of an artifact\'s share pages (#221): totals plus per-day and per-link breakdowns over the trailing days (default 30, at most 90). Counts only: views aggregate opens of share pages; viewers counts distinct daily-rotated IP hashes, so the same person on different days counts again and no raw IP or other identifier is ever stored or returned. Named-viewer analytics for member links is not part of this ability (follow-up on #219). Authority: the artifact\'s lead, a curator, or an ab_manage person.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'days'        => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'default' => 30, 'description' => 'Trailing window in days; daily aggregates are retained 90 days.' ],
					],
					'required'   => [ 'artifact_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'artifact_id'    => [ 'type' => 'integer' ],
						'days'           => [ 'type' => 'integer' ],
						'retention_days' => [ 'type' => 'integer' ],
						'totals'         => [ 'type' => 'object', 'properties' => [ 'views' => [ 'type' => 'integer' ], 'viewers' => [ 'type' => 'integer' ] ] ],
						'per_day'        => [ 'type' => 'array' ],
						'per_link'       => [ 'type' => 'array' ],
					],
					'required'   => [ 'artifact_id', 'days', 'totals', 'per_day', 'per_link' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return ViewAnalytics::forArtifact( (int) ( $input['artifact_id'] ?? 0 ), self::actorId(), (int) ( $input['days'] ?? 30 ) );
				},
			]
		);

		self::registerOnce(
			AbilityNames::ARTIFACT_SHARE_CREATE,
			[
				'label'               => __( 'Create artifact share link', 'agent-brain' ),
				'description'         => __( 'Mint a time-limited, unlisted share link for an artifact (artifact lead, curator or manager). The URL is returned once and cannot be retrieved later. Default expiry 72 hours; optional IP binding. Links are served from the site\'s own address by default, or from the configured preview host when one is set (it takes precedence). A link minted by an agent with no person acting via it is created pending_approval and a curator or manager must approve it before it opens. kind=link (default) is a plain link that cannot be framed; kind=embed makes a link that the origins on the embed allowlist of this site may show in an iframe (default expiry 24 hours; the response then also carries iframe markup and, for a caller who can read the artifact, a single-use embed_code / embed_url valid for 60 seconds that the embedding page loads in its iframe; pass embed_origin when several origins are allowlisted). With an empty allowlist an embed link cannot be framed anywhere. privacy=private (default) serves only named members and needs viewer identity on for the artifact (it is refused otherwise, with how to fix it): the viewer must exchange a single-use member code for a signed viewer token (REST POST /share-links/{id}/viewer-code) and present it as the viewer_token query parameter; anonymous requests get a uniform 404. privacy=public serves anyone holding the link. A private link is always a members link. viewer_mode=members marks a public link as admitting named viewers; viewer_mode=anonymous (default for public links) never names viewers. ip_bind=fixed binds the link to the IP of the minting client (this caller), so only that network can open it; use first_open for a link meant for a viewer, which binds to the first opener\'s IP instead.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'ttl_hours'   => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 8760 ],
						'ip_bind'     => [ 'type' => 'string', 'enum' => Policy::IP_MODES ],
						'kind'        => [ 'type' => 'string', 'enum' => EmbedPolicy::KINDS, 'default' => EmbedPolicy::KIND_LINK ],
						'embed_origin' => [ 'type' => 'string', 'description' => 'kind=embed only: the allowlisted https origin that will show the iframe (defaults to the only allowlisted origin).' ],
						'viewer_mode'  => [ 'type' => 'string', 'enum' => ShareLinks::VIEWER_MODES, 'default' => ShareLinks::VIEWER_ANONYMOUS, 'description' => 'members lets named brain members exchange a single-use code for a signed viewer token on artifacts with viewer identity enabled; anonymous (default) never names viewers. Forced to members when privacy=private.' ],
						'privacy'      => [ 'type' => 'string', 'enum' => ShareLinks::PRIVACY_MODES, 'default' => ShareLinks::PRIVACY_PRIVATE, 'description' => 'private (default) serves named members only, after the viewer-token exchange; public serves anyone holding the link.' ],
					],
					'required'   => [ 'artifact_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'               => [ 'type' => 'integer' ],
						'url'              => [ 'type' => 'string' ],
						'expires_at'       => [ 'type' => 'string' ],
						'ip_mode'          => [ 'type' => 'string' ],
						'pending_approval' => [ 'type' => 'boolean' ],
						'kind'             => [ 'type' => 'string' ],
						'viewer_mode'      => [ 'type' => 'string' ],
						'privacy'          => [ 'type' => 'string' ],
						'iframe'           => [ 'type' => 'string' ],
						'embed_code'       => [ 'type' => 'string' ],
						'embed_url'        => [ 'type' => 'string' ],
					],
					'required'   => [ 'id', 'url', 'expires_at' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];
					$ttl   = isset( $input['ttl_hours'] ) ? (int) $input['ttl_hours'] * 3600 : null;

					return self::withLimiter(
						static function () use ( $input, $ttl ) {
							// #400: refuse a private link the artifact can never serve.
							$guard = PrivateLinkGuard::check( (int) ( $input['artifact_id'] ?? 0 ), (string) ( $input['privacy'] ?? ShareLinks::PRIVACY_PRIVATE ) );
							if ( $guard instanceof WP_Error ) {
								return $guard;
							}

							$result = ShareLinks::mint( (int) ( $input['artifact_id'] ?? 0 ), self::actorId(), $ttl, isset( $input['ip_bind'] ) ? (string) $input['ip_bind'] : null, null, (string) ( $input['kind'] ?? EmbedPolicy::KIND_LINK ), (string) ( $input['viewer_mode'] ?? ShareLinks::VIEWER_ANONYMOUS ), (string) ( $input['privacy'] ?? ShareLinks::PRIVACY_PRIVATE ) );
							if ( $result instanceof WP_Error ) {
								return $result;
							}

							if ( $result['kind'] === EmbedPolicy::KIND_EMBED ) {
								$result['iframe'] = EmbedPolicy::iframeSnippet( $result['url'], Interactive::isActive( (int) ( $input['artifact_id'] ?? 0 ) ) );
								// Single-use code (60 s) for the cookie exchange, when the caller may read the artifact and an origin is known.
								$origins = EmbedSettings::allowedOrigins();
								$origin  = isset( $input['embed_origin'] ) ? (string) $input['embed_origin'] : ( count( $origins ) === 1 ? $origins[0] : '' );
								$code    = $origin !== '' ? EmbedExchange::issueCode( self::actorId(), (int) $result['id'], $origin ) : null;
								if ( is_array( $code ) ) {
									$result['embed_code'] = $code['code'];
									$result['embed_url']  = $code['url'];
								}
							}
							unset( $result['token'] );

							return $result;
						}
					);
				},
			]
		);

		self::registerOnce(
			AbilityNames::ARTIFACT_SHARE_REVOKE,
			[
				'label'               => __( 'Revoke artifact share link', 'agent-brain' ),
				'description'         => __( 'Revoke a share link by id; takes effect immediately.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [ 'link_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
					'required'   => [ 'link_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'revoked' => [ 'type' => 'boolean' ] ],
					'required'   => [ 'revoked' ],
				],
				'permission_callback' => static function ( $input ): bool {
					$linkId = is_array( $input ) ? (int) ( $input['link_id'] ?? 0 ) : 0;
					if ( $linkId <= 0 ) {
						return self::canUseMcp() && ShareLinks::canManageAny( self::actorId() );
					}
					$row = ShareLinks::find( $linkId );

					return self::canUseMcp() && $row !== null && ShareLinks::canManage( self::actorId(), (int) $row['artifact_id'] );
				},
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return self::withLimiter(
						static function () use ( $input ) {
							$result = ShareLinks::revoke( (int) ( $input['link_id'] ?? 0 ), self::actorId() );

							return $result instanceof WP_Error ? $result : [ 'revoked' => true ];
						}
					);
				},
			]
		);

		self::registerOnce(
			AbilityNames::ARTIFACT_SHARE_LIST,
			[
				'label'               => __( 'List artifact share links', 'agent-brain' ),
				'description'         => __( 'List share links of an artifact with status, expiry and open counts. Never returns tokens.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [ 'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
					'required'   => [ 'artifact_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'links' => [ 'type' => 'array' ] ],
					'required'   => [ 'links' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$input  = is_array( $input ) ? $input : [];
					$result = ShareLinks::listForArtifact( (int) ( $input['artifact_id'] ?? 0 ), self::actorId() );

					return $result instanceof WP_Error ? $result : [ 'links' => $result ];
				},
			]
		);

		// #219: the permanent members address. The address is not a secret
		// (/a/{id} is enumerable); opening it still demands a sign-in plus
		// the artifact read check and the operator's role setting
		// (MembersAddress), and this ability tells callers only what they
		// may themselves open.
		self::registerOnce(
			AbilityNames::ARTIFACT_MEMBERS_ADDRESS_GET,
			[
				'label'               => __( 'Get artifact members address', 'agent-brain' ),
				'description'         => __( 'The artifact\'s permanent members address on the main site (#219): one fixed /a/{artifact_id} URL that brain members bookmark, sign in once, and always see the current approved version at. Older versions are browsable there with ?v= by the lead, curators and managers. Authority: a member the operator\'s members-address role setting admits (artifact read check plus ab_members_address_roles).', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [ 'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
					'required'   => [ 'artifact_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'artifact_id'   => [ 'type' => 'integer' ],
						'url'           => [ 'type' => 'string' ],
						'version_param' => [ 'type' => 'string' ],
					],
					'required'   => [ 'artifact_id', 'url' ],
				],
				'permission_callback' => static function ( $input ): bool {
					if ( ! self::canUseMcp() ) {
						return false;
					}

					$artifactId = is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0;

					// Discovery passes no artifact_id: approximate with a
					// role the operator's setting can never exclude managers
					// from seeing (curator or ab_manage).
					return $artifactId > 0
						? MembersAddress::canSeeAddress( self::actorId(), $artifactId )
						: ( \AgentBrain\Modules\Artifacts\ObjectAccess::userIsCurator( self::actorId() ) || user_can( self::actorId(), Capabilities::CAP_MANAGE ) );
				},
				'execute_callback'    => static function ( $input ) {
					$input      = is_array( $input ) ? $input : [];
					$artifactId = (int) ( $input['artifact_id'] ?? 0 );
					$post       = $artifactId > 0 ? get_post( $artifactId ) : null;
					if ( ! $post instanceof \WP_Post || $post->post_type !== \AgentBrain\Modules\Artifacts\Cpts::ARTIFACT_POST_TYPE ) {
						return new WP_Error( 'ab_not_found', __( 'Artifact not found.', 'agent-brain' ), [ 'status' => 404 ] );
					}

					return [
						'artifact_id'   => $artifactId,
						'url'           => MembersAddress::url( $artifactId ),
						'version_param' => 'v',
					];
				},
			]
		);
	}

	/**
	 * @param mixed $input
	 */
	private static function can( $input ): bool {
		if ( ! self::canUseMcp() ) {
			return false;
		}

		$artifactId = is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0;

		// Discovery passes no artifact_id: approximate with "may manage any".
		return $artifactId > 0
			? ShareLinks::canManage( self::actorId(), $artifactId )
			: ShareLinks::canManageAny( self::actorId() );
	}

	public static function actorId(): int {
		return (int) get_current_user_id();
	}

	public static function canUseMcp(): bool {
		$actorId = self::actorId();

		return $actorId > 0 && user_can( $actorId, Capabilities::CAP_USE_MCP );
	}

	/**
	 * @param callable(): mixed $fn
	 * @return mixed
	 */
	public static function withLimiter( callable $fn ) {
		$limiter  = new WriteLimiter();
		$acquired = $limiter->acquire();

		if ( $acquired instanceof WP_Error ) {
			return $acquired;
		}

		try {
			return $fn();
		} finally {
			$limiter->release();
		}
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public static function registerOnce( string $name, array $args ): void {
		AbilityNames::registerOnce( $name, $args );
	}
}
