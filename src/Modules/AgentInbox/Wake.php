<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentInbox;

use AgentBrain\Core\SecretBox;
use AgentBrain\Core\SecretBoxException;
use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\TaskTakers;
use AgentBrain\Modules\Identity\Capabilities;
use WP_Error;

/**
 * #1060: wake an agent when an event lands in its inbox.
 *
 * An agent that takes tasks may register an https address (the agent
 * itself, or a curator for it). When push() adds an event, the address
 * gets a small signed POST, outside the request that pushed: a one-off
 * WP-Cron event, spawned at shutdown. The body is {agent, pending, at}
 * and never an event's content; the agent claims its inbox to see what
 * is new, so the handler can answer at once and leave the work to the
 * agent's run.
 *
 * The signature is X-TomaTio-Signature: sha256=<hex HMAC-SHA256 of the
 * body with the wake secret>, as GitHub and Forgejo sign webhooks. The
 * secret is made here, shown once, and stored sealed (Core\SecretBox,
 * like MergeLog\Repos and Integrations\Vault). A failed wake backs off
 * (1, 2, 4 … minutes, at most an hour) and is retried MAX_RETRIES times;
 * polling the feed or peeking stays the fallback.
 */
final class Wake {

	/** User meta: {url, secret (sealed), set_by, set_at}. */
	public const META = 'ab_agent_inbox_wake';

	/** User meta: {failures, retry_at, last_at, last_code}. */
	public const META_STATE = 'ab_agent_inbox_wake_state';

	public const PURPOSE = 'agent-inbox-wake';

	public const HOOK = 'agent_brain_agent_inbox_wake';

	public const HEADER_SIGNATURE = 'X-TomaTio-Signature';
	public const HEADER_EVENT     = 'X-TomaTio-Event';

	public const MAX_URL     = 500;
	public const MAX_RETRIES = 8;
	public const TIMEOUT     = 5;

	/** @var array<int, true> agents pushed to during this request */
	private static array $queued = [];

	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'cron' ], 10, 1 );
		add_action( 'shutdown', [ self::class, 'flush' ], 30 );
	}

	/**
	 * The address as stored, or a WP_Error that says why not: https only,
	 * with a host, without credentials, not a local or private address.
	 *
	 * @return string|WP_Error
	 */
	public static function validate( string $url ) {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		$fail  = static fn ( string $why ): WP_Error => new WP_Error( 'ab_inbox_wake_url', $why, [ 'status' => 422 ] );

		if ( $url === '' || strlen( $url ) > self::MAX_URL || ! is_array( $parts ) ) {
			return $fail( __( 'The wake address must be an https address of at most 500 characters.', 'tomatio' ) );
		}
		if ( strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https' ) {
			return $fail( __( 'The wake address must use https.', 'tomatio' ) );
		}
		$host = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );
		if ( $host === '' || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $fail( __( 'The wake address needs a host, and no user name or password in it.', 'tomatio' ) );
		}
		if ( $host === 'localhost' || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) ) {
			return $fail( __( 'The wake address must be reachable on the internet, not a local name.', 'tomatio' ) );
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) !== false && filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
			return $fail( __( 'The wake address must not be a private or reserved IP address.', 'tomatio' ) );
		}
		if ( isset( $parts['fragment'] ) ) {
			return $fail( __( 'The wake address cannot have a #fragment.', 'tomatio' ) );
		}

		return esc_url_raw( $url, [ 'https' ] );
	}

	/**
	 * Register (or with '' remove) an agent's wake address. The agent
	 * itself or a curator may. Answers the address and, when one is set,
	 * the new secret, shown only this once.
	 *
	 * @return array{url: string, secret?: string, cleared: bool}|WP_Error
	 */
	public static function set( int $actingId, int $agentId, string $url ) {
		if ( $actingId <= 0 || ( $actingId !== $agentId && ! user_can( $actingId, Capabilities::CAP_MANAGE ) ) ) {
			return new WP_Error( 'ab_forbidden', __( 'Only the agent itself or a curator may set its wake address.', 'tomatio' ), [ 'status' => 403 ] );
		}
		if ( ! TaskTakers::takes( $agentId ) ) {
			return new WP_Error( 'ab_agent_takes_no_tasks', __( 'Only an agent that takes tasks has an inbox to wake it for.', 'tomatio' ), [ 'status' => 422 ] );
		}

		if ( trim( $url ) === '' ) {
			$had = self::get( $agentId ) !== null;
			delete_user_meta( $agentId, self::META );
			delete_user_meta( $agentId, self::META_STATE );
			if ( $had ) {
				self::audit( $actingId, $agentId, true );
			}

			return [ 'url' => '', 'cleared' => true ];
		}

		$clean = self::validate( $url );
		if ( $clean instanceof WP_Error ) {
			return $clean;
		}

		$secret = 'whs_' . bin2hex( random_bytes( 24 ) );
		update_user_meta(
			$agentId,
			self::META,
			[
				'url'    => $clean,
				'secret' => SecretBox::seal( $secret, SecretBox::currentMaterial(), self::PURPOSE ),
				'set_by' => $actingId,
				'set_at' => time(),
			]
		);
		delete_user_meta( $agentId, self::META_STATE );
		self::audit( $actingId, $agentId, false );

		return [ 'url' => $clean, 'secret' => $secret, 'cleared' => false ];
	}

	/**
	 * The agent's wake address and its opened secret, or null.
	 *
	 * @return array{url: string, secret: string}|null
	 */
	public static function get( int $agentId ): ?array {
		$stored = $agentId > 0 ? get_user_meta( $agentId, self::META, true ) : null;
		if ( ! is_array( $stored ) || ! is_string( $stored['url'] ?? null ) || $stored['url'] === '' ) {
			return null;
		}

		return [ 'url' => $stored['url'], 'secret' => self::open( (string) ( $stored['secret'] ?? '' ) ) ];
	}

	/** The signature header's value for $body. */
	public static function signature( string $body, string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	/** The wake body: who, how many events wait, and when. Never an event's content. */
	public static function body( int $agentId, int $pending, int $now ): string {
		$user = get_userdata( $agentId );
		$name = AgentIssuer::fqdnFor( $agentId ) ?? ( $user instanceof \WP_User ? (string) $user->user_login : (string) $agentId );

		return (string) wp_json_encode( [ 'agent' => $name, 'pending' => $pending, 'at' => gmdate( 'Y-m-d\TH:i:s\Z', $now ) ] );
	}

	/** push() added an event for $agentId: wake it once this request ends. */
	public static function queue( int $agentId ): void {
		self::$queued[ $agentId ] = true;
	}

	/**
	 * At shutdown: one wake per agent pushed to, through WP-Cron so the
	 * request that pushed never waits on the agent's endpoint.
	 */
	public static function flush(): void {
		$queued       = array_keys( self::$queued );
		self::$queued = [];
		$scheduled    = false;
		$now          = time();
		foreach ( $queued as $agentId ) {
			if ( self::get( (int) $agentId ) === null ) {
				continue;
			}
			$state = self::state( (int) $agentId );
			if ( $state['retry_at'] > $now ) {
				continue; // Backing off: the retry already scheduled wakes it.
			}
			if ( ! wp_next_scheduled( self::HOOK, [ (int) $agentId ] ) ) {
				wp_schedule_single_event( $now, self::HOOK, [ (int) $agentId ] );
				$scheduled = true;
			}
		}
		if ( $scheduled && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/** @param mixed $agentId */
	public static function cron( $agentId ): void {
		self::send( (int) $agentId );
	}

	/**
	 * Send the wake now. Answers what happened: sent (with the HTTP code),
	 * nothing (no address, or nothing pending) or failed (and when it is
	 * retried).
	 *
	 * @return array{result: string, code?: int, retry_at?: int}
	 */
	public static function send( int $agentId, ?int $now = null ): array {
		$now  = $now ?? time();
		$wake = self::get( $agentId );
		if ( $wake === null || $wake['secret'] === '' || ! TaskTakers::takes( $agentId ) ) {
			return [ 'result' => 'nothing' ];
		}
		$pending = Inbox::availableCount( $agentId, $now );
		if ( $pending === 0 ) {
			return [ 'result' => 'nothing' ];
		}

		$body     = self::body( $agentId, $pending, $now );
		$response = wp_safe_remote_post(
			$wake['url'],
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => [
					'Content-Type'          => 'application/json',
					self::HEADER_EVENT      => 'wake',
					self::HEADER_SIGNATURE  => self::signature( $body, $wake['secret'] ),
				],
				'body'        => $body,
			]
		);
		$code  = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$state = self::state( $agentId );

		if ( $code >= 200 && $code < 300 ) {
			update_user_meta( $agentId, self::META_STATE, [ 'failures' => 0, 'retry_at' => 0, 'last_at' => $now, 'last_code' => $code ] );

			return [ 'result' => 'sent', 'code' => $code ];
		}

		$failures = $state['failures'] + 1;
		$retryAt  = $now + min( HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** min( 6, $failures - 1 ) ) );
		update_user_meta( $agentId, self::META_STATE, [ 'failures' => $failures, 'retry_at' => $retryAt, 'last_at' => $now, 'last_code' => $code ] );
		if ( $failures <= self::MAX_RETRIES ) {
			wp_schedule_single_event( $retryAt, self::HOOK, [ $agentId ] );
		}

		return [ 'result' => 'failed', 'code' => $code, 'retry_at' => $retryAt ];
	}

	/**
	 * @return array{failures: int, retry_at: int, last_at: int, last_code: int}
	 */
	public static function state( int $agentId ): array {
		$state = get_user_meta( $agentId, self::META_STATE, true );
		$state = is_array( $state ) ? $state : [];

		return [
			'failures'  => (int) ( $state['failures'] ?? 0 ),
			'retry_at'  => (int) ( $state['retry_at'] ?? 0 ),
			'last_at'   => (int) ( $state['last_at'] ?? 0 ),
			'last_code' => (int) ( $state['last_code'] ?? 0 ),
		];
	}

	/** Forget what was queued (tests). */
	public static function reset(): void {
		self::$queued = [];
	}

	private static function open( string $envelope ): string {
		if ( $envelope === '' ) {
			return '';
		}
		foreach ( [ SecretBox::currentMaterial(), SecretBox::previousMaterial() ] as $material ) {
			if ( $material === null ) {
				continue;
			}
			try {
				return SecretBox::open( $envelope, $material, self::PURPOSE );
			} catch ( SecretBoxException $e ) {
				continue;
			}
		}

		return '';
	}

	private static function audit( int $actingId, int $agentId, bool $cleared ): void {
		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actingId,
				'event'       => 'agent_inbox.wake',
				'status'      => 'ok',
				'object_type' => 'user',
				'object_id'   => (string) $agentId,
				'meta'        => [ 'fqdn' => AgentIssuer::fqdnFor( $agentId ), 'cleared' => $cleared ],
			]
		);
	}
}
