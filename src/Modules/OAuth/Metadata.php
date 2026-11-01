<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

/**
 * RFC 8414 authorization-server metadata and RFC 9728 protected-resource
 * metadata for the agent-brain/v1/mcp resource, and for the role-switching
 * address agent-brain/v1/mcp-roles (#647).
 */
final class Metadata {

	/**
	 * @return array<string, mixed>
	 */
	public static function authorizationServer(): array {
		return [
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => admin_url('admin-post.php?action=' . AuthorizeController::ACTION),
			'token_endpoint'                         => rest_url('agent-brain/v1/oauth/token'),
			'revocation_endpoint'                    => rest_url('agent-brain/v1/oauth/revoke'),
			'response_types_supported'               => ['code'],
			'grant_types_supported'                  => ['authorization_code', 'refresh_token'],
			'code_challenge_methods_supported'       => [Pkce::METHOD_S256],
			'token_endpoint_auth_methods_supported'  => ['none'],
			'scopes_supported'                       => ['mcp'],
			// Clients identify themselves by a Client ID Metadata Document
			// (Cimd); without this flag a client looking for a
			// registration_endpoint concludes it cannot register at all.
			'client_id_metadata_document_supported' => true,
			// #239: RFC 7591 Dynamic Client Registration as the second way
			// in, alongside CIMD (Rest::handleRegister).
			'registration_endpoint'                  => rest_url(Rest::NAMESPACE . Dcr::ROUTE_BASE),
			// RFC 9207: authorization responses carry iss (AuthorizeController).
			'authorization_response_iss_parameter_supported' => true,
		];
	}

	/** The issuer identifier, also sent as iss in authorization responses (RFC 9207). */
	public static function issuer(): string {
		return untrailingslashit(site_url());
	}

	/**
	 * Where a client finds protectedResource() (RFC 9728 section 3): the
	 * path-suffixed well-known URL of the MCP endpoint, advertised in the
	 * MCP 401's WWW-Authenticate challenge and as resource_metadata in the
	 * PCP context document (PCP §3.2 item 2). The bare root path keeps
	 * serving the same document for clients that fall back to it.
	 */
	public static function protectedResourceMetadataUrl(): string {
		return home_url(WellKnown::RESOURCE_PATH . WellKnown::resourcePath());
	}

	/**
	 * Where a client finds the role-switching address's metadata
	 * (protectedResource(true)): the path-suffixed well-known URL of
	 * .../mcp-roles, named in that route's 401 challenge (#647).
	 */
	public static function rolesResourceMetadataUrl(): string {
		return home_url(WellKnown::RESOURCE_PATH . WellKnown::rolesResourcePath());
	}

	/**
	 * @param bool $roles True for the role-switching address's own metadata
	 *                    (WellKnown, at rolesResourceMetadataUrl()).
	 * @return array<string, mixed>
	 */
	public static function protectedResource(bool $roles = false): array {
		// Asked for from a role-switching address (see Challenge), the
		// resource is that address, so clients send it back at consent: the
		// mcp-roles URL with no query (#647), or for the older address the
		// MCP endpoint with ?roles=1.
		if ($roles) {
			$resource = RoleSwitching::rolesUrl();
		} elseif (RoleSwitching::requestHasRolesQuery()) {
			$resource = RoleSwitching::legacyRolesUrl();
		} else {
			$resource = rest_url('agent-brain/v1/mcp');
		}

		return [
			'resource'              => $resource,
			'authorization_servers' => [self::issuer()],
			// PCP §3.2 item 1: the default grant, and bearer tokens in the header only.
			'scopes_supported'         => PcpDiscovery::DEFAULT_SCOPES,
			'bearer_methods_supported' => ['header'],
		];
	}
}
