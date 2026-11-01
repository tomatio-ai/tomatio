<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Core\AuthorizedBy;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * Delegated authority (#629): an AI connection may act with the rights of
 * the person behind it when that person asks (create an artifact, publish a
 * section in one step, approve an HTML version), instead of drafting for
 * review. Off by default; an explicit, per-connection choice.
 *
 *  - Opt-in per connection, on the consent screen (an unticked box). The
 *    grant is the `delegate` OAuth scope on that connection's token family,
 *    so it is carried through the code exchange and every refresh, and it
 *    belongs to that one connection only.
 *  - The person can turn it off later from their profile's connections
 *    list (revokeFor()): the scope is dropped from every token of the
 *    family, so the very next request no longer has it. No reconnect.
 *  - Administrators can forbid it brain-wide (allowed(), Settings >
 *    Onboarding). The consent screen then does not offer it, and existing
 *    grants stop working: it is checked at use time, never only at consent.
 *  - It only ever applies to a call made via the person (AuthorizedBy: a
 *    delegated agent token, an agent chosen with act-as, or a person's
 *    write routed to their agent), and only to what that person may do
 *    themselves. A pure agent, with no person behind it, never has it.
 *  - The review-required lock on an artifact (Artifacts\ReviewLock) wins:
 *    the callers check it and fall back to normal review.
 *  - Every write made with it is audited (record()): actor the agent, `via`
 *    the person, `delegated_authority` true.
 */
final class DelegatedAuthority {

	/** OAuth scope that marks a connection with delegated authority. */
	public const SCOPE = 'delegate';

	/** Option: '1' (default) allows it, '0' forbids it brain-wide. */
	public const OPTION = 'agent_brain_delegated_authority';

	/** Consent form field (the checkbox). */
	public const FIELD = 'ab_delegated_authority';

	/** Audit event for a write made with delegated authority. */
	public const EVENT = 'delegated_authority.used';

	/** Whether the connection authenticating this request carries the grant. */
	private static bool $granted = false;

	/** That connection's OAuth client_id (for the audit record). */
	private static string $clientId = '';

	/**
	 * Whether this brain lets people give a connection delegated authority.
	 * Allowed unless an administrator forbids it (Settings > Onboarding):
	 * each connection still opts in on its own.
	 */
	public static function allowed(): bool {
		// Stored as '1' / '0' (see OnboardingSettings::handleSave()); absent means allowed.
		$stored = get_option(self::OPTION, '1');

		return (bool) apply_filters('agent_brain/delegated_authority', ! in_array($stored, ['0', 0, false, ''], true));
	}

	/** True when an OAuth scope string carries the grant. */
	public static function scopeGrants(string $scope): bool {
		return in_array(self::SCOPE, preg_split('/\s+/', trim($scope)) ?: [], true);
	}

	/** $scope with the grant added (once). */
	public static function withScope(string $scope): string {
		return self::scopeGrants($scope) ? $scope : trim($scope . ' ' . self::SCOPE);
	}

	/** $scope with the grant removed. */
	public static function withoutScope(string $scope): string {
		$parts = array_filter(preg_split('/\s+/', trim($scope)) ?: [], static fn (string $part): bool => $part !== '' && $part !== self::SCOPE);

		return implode(' ', $parts);
	}

	/**
	 * Called by Bearer for each request whose access token validated: the
	 * token row is read fresh per request, so a grant turned off on the
	 * profile is gone from the next request.
	 */
	public static function remember(string $scope, string $clientId): void {
		self::$granted  = self::scopeGrants($scope);
		self::$clientId = $clientId;
	}

	/** Whether this request's connection has the grant and the brain allows it. */
	public static function connectionGranted(): bool {
		return self::$granted && self::allowed();
	}

	/**
	 * The person whose rights $actorId may use for this call, or 0: the
	 * connection has the grant, the brain allows it, and a person is behind
	 * the call (AuthorizedBy). The person is never the actor itself and
	 * never an agent, so a pure agent never gets it.
	 */
	public static function personFor(int $actorId): int {
		if ($actorId <= 0 || ! self::connectionGranted()) {
			return 0;
		}

		$person = AuthorizedBy::id();
		if ($person <= 0 || $person === $actorId) {
			return 0;
		}

		$user = get_userdata($person);
		if (! $user instanceof \WP_User || in_array(Capabilities::ROLE_AGENT, (array) $user->roles, true)) {
			return 0;
		}

		return $person;
	}

	/**
	 * Audit a write made with delegated authority: by the agent, for the
	 * person, with the marker.
	 *
	 * @param array<string, mixed> $meta
	 */
	public static function record(int $agentId, int $personId, string $action, string $objectType, string $objectId, array $meta = []): void {
		do_action('agent_brain/audit', [
			'actor_id'    => $agentId,
			'event'       => self::EVENT,
			'status'      => 'ok',
			'object_type' => $objectType,
			'object_id'   => $objectId,
			'meta'        => [
				'action'              => $action,
				'via'                 => $personId,
				'delegated_authority' => true,
			] + (self::$clientId !== '' ? ['client_id' => self::$clientId] : []) + $meta,
		]);
	}

	/**
	 * Turn the grant off for one connection (a token family) of $personId:
	 * their own connection, or an agent connection they authorized. Takes
	 * effect on the next request; the connection itself stays signed in.
	 */
	public static function revokeFor(int $personId, string $familyId): bool {
		if ($personId <= 0 || $familyId === '') {
			return false;
		}

		$changed = TokenStore::dropScopeForFamily($personId, $familyId, self::SCOPE);
		Audit::emit(get_current_user_id(), 'oauth.delegated_authority_off', $changed ? 'ok' : 'denied', ['target_user_id' => $personId]);

		return $changed;
	}

	public static function reset(): void {
		self::$granted  = false;
		self::$clientId = '';
	}
}
