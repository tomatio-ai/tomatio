<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentRegistration;

use AgentBrain\Modules\Admin\ConnectPage;
use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\Fqdn;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Portability\Principals;

/**
 * Turns a valid registration key into an agent (RFC section 6.3). The key's
 * ceiling is the only source of class and scopes; the caller supplies at most
 * a name. The single-use gate is one atomic claim of the record; claim, agent
 * creation and the spent mark run in ONE database transaction (#236), so any
 * failure rolls back everything — including the `agent.issue` audit row the
 * issuer writes mid-registration — and the key is not burned (bad-name errors
 * never claim at all, but count toward the lockout). The old compensating
 * undo survives only as outer safety for side effects a rollback cannot
 * reach; after a rollback it has nothing left to delete.
 */
final class Registrar {

	/**
	 * Pure: the agent_name to use. A name fixed by the person always wins;
	 * otherwise the caller's, trimmed. Null when none. Case is NOT folded: a
	 * name with uppercase letters is rejected by Fqdn::isValidName() with the
	 * a-z message, so the rule the agent was told is the rule that applies.
	 */
	public static function chooseName( ?string $fixed, ?string $requested ): ?string {
		if ( $fixed !== null && $fixed !== '' ) {
			return $fixed;
		}

		if ( $requested === null ) {
			return null;
		}

		$name = trim( $requested );

		return $name === '' ? null : $name;
	}

	/**
	 * @param array<string, mixed> $record A pending row from RegistrationStore.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function register( array $record, string $keyHash, ?string $requestedName ) {
		$fixed = isset( $record['agent_name'] ) ? (string) $record['agent_name'] : '';
		$name  = self::chooseName( $fixed !== '' ? $fixed : null, $requestedName );

		if ( $name === null ) {
			return self::fail( $record, $keyHash, new \WP_Error( 'ab_agent_name_required', 'Choose an agent_name (3 to 32 characters, a-z, 0-9 and "-").', [ 'status' => 422 ] ) );
		}

		if ( ! Fqdn::isValidName( $name ) ) {
			return self::fail( $record, $keyHash, new \WP_Error( 'ab_invalid_agent_name', 'Invalid agent_name: use 3 to 32 characters, a-z, 0-9 and "-", starting with a letter.', [ 'status' => 422 ] ) );
		}

		$id = (int) $record['id'];

		global $wpdb;
		// One transaction for the whole registration (RFC section 6.3, #236):
		// a savepoint when one is already open (autocommit off, as in the test
		// suite), else START TRANSACTION — the Portability\Transaction pattern.
		// The audit `agent.issue` row the issuer writes mid-registration is on
		// this same connection, so a rollback removes it too: no orphan row can
		// claim an identity was issued for an agent that does not exist.
		$nested   = (string) $wpdb->get_var( 'SELECT @@autocommit' ) === '0';
		$begin    = $nested ? 'SAVEPOINT ab_registration' : 'START TRANSACTION';
		$rollback = $nested ? 'ROLLBACK TO SAVEPOINT ab_registration' : 'ROLLBACK';
		$commit   = $nested ? 'RELEASE SAVEPOINT ab_registration' : 'COMMIT';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- fixed statements, no values.
		$wpdb->query( $begin );

		$userId = 0;
		$error  = null;
		try {
			if ( ! RegistrationStore::claim( $id ) ) {
				$error = new \WP_Error( 'ab_registration_unavailable', 'This registration key has already been used, expired or was revoked.', [ 'status' => 409 ] );
			} else {
				$result = self::create( $record, $name );
				if ( $result instanceof \WP_Error ) {
					$userId = (int) ( $result->get_error_data()['user_id'] ?? 0 );
					$error  = $result;
				} else {
					$userId = (int) $result['user_id'];
					if ( ! RegistrationStore::markSpent( $id, $userId ) ) {
						$error = new \WP_Error( 'ab_registration_unavailable', 'This registration key could not be completed.', [ 'status' => 409 ] );
					}
				}
			}
		} catch ( \Throwable $e ) {
			$error = new \WP_Error( 'ab_registration_failed', 'Registration failed; the key was not used.', [ 'status' => 500 ] );
		}

		if ( $error instanceof \WP_Error ) {
			$wpdb->query( $rollback );
			wp_cache_flush();

			// Outer safety only: the rollback already restored the pending
			// claim, removed the user and its meta and erased the `agent.issue`
			// audit row. This stays for side effects a rollback cannot reach
			// (third-party user_register handlers, non-DB state).
			self::undo( $id, $userId );

			return self::fail( $record, $keyHash, $error );
		}

		$wpdb->query( $commit );
		// phpcs:enable

		unset( $result['user_id'] );

		// Say so when the agent asked for a different name than the fixed one.
		// Only reachable with a valid, pending key, so it leaks nothing.
		if ( $fixed !== '' && $requestedName !== null && trim( $requestedName ) !== '' && trim( $requestedName ) !== $fixed ) {
			$result['note'] = 'agent_name was fixed by the person who created this registration';
		}

		do_action( 'agent_brain/audit', [
			'actor_id'    => (int) $record['issued_by'],
			'event'       => 'agent.register',
			'status'      => 'ok',
			'object_type' => 'user',
			'object_id'   => (string) $userId,
			'meta'        => [ 'fqdn' => $result['fqdn'], 'class' => $result['class'], 'registration' => $id ],
		] );

		return $result;
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function create( array $record, string $name ) {
		$registerId = (string) $record['register_id'];
		$class      = (string) $record['agent_class'];
		$issuedBy   = (int) $record['issued_by'];
		$login      = $name . '-' . $registerId;

		if ( username_exists( $login ) !== false || Principals::loginReserved( $login ) ) {
			return new \WP_Error( 'ab_fqdn_taken', 'That agent ID is already in use.', [ 'status' => 409 ] );
		}

		AgentIssuer::deferAutoIssue( true );
		try {
			$userId = wp_insert_user( [
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 48, true, true ),
				'display_name' => $login,
				'role'         => Capabilities::ROLE_AGENT,
			] );
		} finally {
			AgentIssuer::deferAutoIssue( false );
		}

		if ( $userId instanceof \WP_Error ) {
			return new \WP_Error( 'ab_registration_failed', 'Could not create the agent.', [ 'status' => 500 ] );
		}

		$fail = static fn ( string $code, string $msg, int $status = 500 ): \WP_Error => new \WP_Error( $code, $msg, [ 'status' => $status, 'user_id' => $userId ] );

		$fqdn = ( new AgentIssuer() )->issue( $userId, $name, $class, $issuedBy, $registerId );
		if ( $fqdn instanceof \WP_Error ) {
			return $fail( $fqdn->get_error_code(), $fqdn->get_error_message(), 409 );
		}

		// The ceiling was validated when the person created the key.
		update_user_meta( $userId, Scopes::META_READ_SCOPES, $record['read_ids'] );
		update_user_meta( $userId, Scopes::META_WRITE_SCOPES, $record['write_ids'] );

		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			return $fail( 'ab_registration_failed', 'Application passwords are not available.' );
		}

		$created = \WP_Application_Passwords::create_new_application_password( $userId, [ 'name' => $fqdn ] );
		if ( $created instanceof \WP_Error ) {
			return $fail( 'ab_registration_failed', 'Could not create the credential.' );
		}

		$password = (string) $created[0];

		return [
			'user_id'    => $userId,
			'fqdn'       => $fqdn,
			'login'      => $login,
			'class'      => $class,
			'scopes'     => [
				'read'  => self::scopeNames( $record['read_ids'] ),
				'write' => self::scopeNames( $record['write_ids'] ),
			],
			'credential' => [ 'type' => 'application_password', 'value' => $password ],
			'connect'    => self::connectCommand( $login, $password ),
			// The same Basic header as in `connect`, as its own secret field so
			// clients can store it without echoing a command line.
			'authorization_header' => [
				'name'   => 'Authorization',
				'value'  => self::basicHeader( $login, $password ),
				'secret' => true,
			],
			'connect_from_file'    => self::connectFromFileCommand(),
			'secret_fields'        => [ 'credential.value', 'authorization_header.value', 'connect' ],
			'handling'             => 'The fields listed in secret_fields are secrets. Never print, echo, quote or log them, including their base64 form. Store authorization_header.value in a secret store or a private file and reconnect with connect_from_file.',
		];
	}

	/**
	 * Outer-safety net after a rolled-back registration. The transaction
	 * rollback already removed the user, its meta, the `agent.issue` audit row
	 * and the claim; this only mops up side effects a rollback cannot reach
	 * (third-party user_register handlers, non-DB state), and its store
	 * release/updates simply no-op on the already-restored rows.
	 */
	private static function undo( int $registrationId, int $userId ): void {
		if ( $userId > 0 ) {
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			wp_delete_user( $userId );
			// The agent never existed for anyone: free its principal id so
			// the released key can register the same name again.
			Principals::discardUnused( $userId );
		}

		RegistrationStore::release( $registrationId );
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private static function fail( array $record, string $keyHash, \WP_Error $error ): \WP_Error {
		RegistrationStore::recordFailure( $keyHash );

		do_action( 'agent_brain/audit', [
			'actor_id'    => (int) $record['issued_by'],
			'event'       => 'agent.register_failed',
			'status'      => 'denied',
			'object_type' => 'registration',
			'object_id'   => (string) $record['id'],
			'meta'        => [ 'reason' => $error->get_error_code() ],
		] );

		// Strip internal detail before returning to the caller.
		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), [ 'status' => $error->get_error_data()['status'] ?? 400 ] );
	}

	/**
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	public static function scopeNames( array $ids ): array {
		$names = [];
		foreach ( $ids as $id ) {
			$term = get_term( $id, Scopes::TAXONOMY );
			if ( $term instanceof \WP_Term ) {
				$names[] = $term->name;
			}
		}

		return $names;
	}

	private static function basicHeader( string $login, string $password ): string {
		return 'Basic ' . base64_encode( $login . ':' . $password );
	}

	/** Reconnect command that reads the header from a private file, so it holds no secret. */
	public static function connectFromFileCommand(): string {
		return sprintf(
			'claude mcp add --transport http %s %s --header "Authorization: $(cat ~/.config/agent-brain/authorization)"',
			ConnectPage::connectorSlug(),
			ConnectPage::mcpUrl()
		);
	}

	private static function connectCommand( string $login, string $password ): string {
		return sprintf(
			'claude mcp add --transport http %s %s --header "Authorization: Basic %s"',
			ConnectPage::connectorSlug(),
			ConnectPage::mcpUrl(),
			base64_encode( $login . ':' . $password )
		);
	}
}
