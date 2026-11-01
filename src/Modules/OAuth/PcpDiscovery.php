<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

/**
 * PCP v0.3 discovery (SPEC §2): the context document at
 * /.well-known/pcp.json that tells PCP clients what this brain is (a team
 * context), where its MCP endpoint and protected resource metadata are, and
 * who can read its data. One site is one context.
 *
 * Hosts adjust the document with the agent_brain/pcp_context_document
 * filter, for example controller_type "human" for a brain one person runs.
 */
final class PcpDiscovery {

	public const PATH = '/.well-known/pcp.json';

	/**
	 * The default grant for a new client in a team context (PCP §7.2),
	 * advertised in the protected resource metadata and the 401 challenge.
	 * Tokens still carry the plugin's own "mcp" scope until PCP scope
	 * strings are adopted; the authorization endpoint accepts and ignores
	 * the scope a client asks for, so advertising these breaks nothing.
	 */
	public const DEFAULT_SCOPES = ['memory:read', 'memory:propose'];

	/**
	 * @return array<string, mixed>
	 */
	public static function contextDocument(): array {
		$document = [
			'pcp_version'       => '0.3',
			'kind'              => 'team',
			'controller_type'   => 'org',
			'mcp_endpoint'      => rest_url('agent-brain/v1/mcp'),
			'resource_metadata' => Metadata::protectedResourceMetadataUrl(),
			'profile'           => 'none',
			// Scopes are PCP partitions with per-agent access lists; the
			// curator queue is PCP review.
			'features'          => ['partitions', 'review'],
			// true: a full PCP export and import of our own format exist and
			// the export → restore → export round trip passes in CI (#514).
			'export'            => true,
			// Plaintext in the site's own database: the host can read it.
			'encryption'        => [
				'key_custody' => 'host',
				'readable_by' => ['principals', 'host'],
			],
		];

		/**
		 * Filters the PCP context document served at /.well-known/pcp.json.
		 *
		 * @param array<string, mixed> $document
		 */
		return (array) apply_filters('agent_brain/pcp_context_document', $document);
	}
}
