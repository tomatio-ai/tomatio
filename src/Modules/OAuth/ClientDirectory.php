<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

/**
 * Resolves a client_id to a normalized client document no matter how the
 * client registered (#239): an opaque `dcr_…` id goes through Dcr (dynamic
 * client registration), anything else is treated as a CIMD URL and fetched
 * through Cimd. Both return the same shape — client_id, client_name,
 * client_uri, redirect_uris — so AuthorizeController's consent flow and
 * exact redirect-URI matching are shared between the two registration
 * styles.
 */
final class ClientDirectory {

	/**
	 * @return array{client_id:string, client_name:string, client_uri:string, redirect_uris:array<int, string>}|\WP_Error
	 */
	public static function fetch(string $clientId) {
		$dcr = Dcr::resolve($clientId);
		if ($dcr !== null) {
			return $dcr;
		}

		return Cimd::fetch($clientId);
	}
}
