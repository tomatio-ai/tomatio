<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Mcp;

use AgentBrain\Core\Health;
use AgentBrain\Modules\Identity\TransportAuth;

/**
 * Transport-level guard for the MCP HTTP endpoint (binding, Wave 3
 * contract): passed to `AdapterBridge::registerServer()` as the adapter's
 * `transport_permission_callback`.
 *
 * Order matters and both checks run on EVERY request to the route, before
 * any ability executes:
 *   1. `Core\Health::isDegraded()` ⇒ refuse (the adapter's HttpTransport
 *      logs + returns a WP_Error, which check_permission() in HttpTransport
 *      treats as denied — REST then reports 401/403 per WP_REST_Server's own
 *      permission_callback handling of a WP_Error return. To guarantee the
 *      exact 503 the contract calls for, a WP_Error carrying status 503
 *      is returned here so the adapter's fail-closed WP_Error path
 *      surfaces that status rather than a generic 401.)
 *   2. `Identity\TransportAuth::check()` ⇒ 401 unless app-password/OAuth
 *      authenticated (cookie/nonce sessions are always rejected here, even
 *      for an otherwise-logged-in curator).
 */
final class TransportGuard {

	/**
	 * @param \WP_REST_Request<array<string, mixed>>|mixed $request Unused
	 *   directly (both underlying checks are ambient/session-based), kept in
	 *   the signature to match the adapter's transport_permission_callback
	 *   contract, which always passes the request.
	 * @return true|\WP_Error
	 */
	public static function check($request = null) {
		if (Health::isDegraded()) {
			return new \WP_Error(
				'agent_brain_degraded',
				'TomaTío is in a degraded state; the MCP transport is refusing requests until this is resolved.',
				['status' => 503]
			);
		}

		return TransportAuth::check();
	}
}
