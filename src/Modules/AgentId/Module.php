<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentId;

use AgentBrain\Core\ModuleInterface;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * Agent ID module (docs/rfc/agent-id-fqdn.md): issues an FQDN to every agent
 * and migrates the agents that existed before it.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'agent-id';
	}

	public function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'user_register', [ $this, 'onUserRegister' ], 20, 1 );
		add_action( 'set_user_role', [ $this, 'onSetUserRole' ], 20, 3 );

		SignInGate::registerHooks();
	}

	/**
	 * A login a person typed (wp-admin, `wp user create`, the REST API) is
	 * kept: scripts and clients use it right after creating the account.
	 * Only paths where nobody types one (proposals, key registration) use
	 * the FQDN's first label (#232).
	 */
	public function onUserRegister( int $userId ): void {
		$this->autoIssue( $userId );
	}

	/**
	 * @param array<int, string> $oldRoles
	 */
	public function onSetUserRole( int $userId, string $role, array $oldRoles ): void {
		unset( $oldRoles );
		if ( $role === Capabilities::ROLE_AGENT ) {
			$this->autoIssue( $userId );
		}
	}

	private function autoIssue( int $userId ): void {
		if ( AgentIssuer::autoIssueDeferred() || ! (bool) apply_filters( 'agent_brain/auto_issue_agent_ids', true ) ) {
			return;
		}

		$user = get_userdata( $userId );
		if ( ! $user instanceof \WP_User || ! in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true ) ) {
			return;
		}

		// People are skipped inside AgentIssuer::issue(), shared by every path.
		( new AgentIssuer() )->issueFromUser( $userId, get_current_user_id() );
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			// Existing agents get an ID; logins are never renamed.
			1 => function (): void {
				if ( ! function_exists( 'get_users' ) ) {
					return;
				}
				$issuer = new AgentIssuer();
				foreach ( AgentIssuer::unissuedAgentIds() as $userId ) {
					$issuer->issueFromUser( $userId, 0 );
				}
			},
			// Agents issued without a timestamp (issued by the first migration on
			// installs that predate stamping) get the migration time.
			2 => function (): void {
				if ( ! function_exists( 'get_users' ) ) {
					return;
				}
				foreach ( get_users( [ 'role' => Capabilities::ROLE_AGENT, 'fields' => 'ID' ] ) as $userId ) {
					$userId = (int) $userId;
					if ( AgentIssuer::fqdnFor( $userId ) !== null && (string) get_user_meta( $userId, AgentMeta::ISSUED_AT, true ) === '' ) {
						update_user_meta( $userId, AgentMeta::ISSUED_AT, gmdate( 'Y-m-d\TH:i:s\Z' ) );
					}
				}
			},
			// Agents without an ID can no longer sign in (#233). The ones the
			// first migration could not name (a login like "QA") get one now,
			// with the fallback name.
			3 => function (): void {
				if ( ! function_exists( 'get_users' ) ) {
					return;
				}
				$issuer = new AgentIssuer();
				foreach ( AgentIssuer::unissuedAgentIds() as $userId ) {
					$issuer->issueFromUser( $userId, 0 );
				}
			},
		];
	}

	public function activate(): void {
		// Nothing beyond migrations.
	}

	public function deactivate(): void {
		// Agent IDs are registry data; keep them.
	}
}
