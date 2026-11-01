<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * PROV-6 (#175, provenance slice TIER 1): action context — what an MCP
 * session saw before it wrote.
 *
 * Two responsibilities:
 *
 *  1. The site on/off switch. Action context (read sets, approval client
 *     labels) is ON by default and can be turned off by a site setting
 *     (PROV-7, #176). The setting lands later; this class already owns its
 *     name and hook so #176 only has to write the option and the UI:
 *       - option `ab_action_context` ('1' = on, stored like other
 *         agent-brain settings), and
 *       - filter `agent_brain/action_context_enabled` as the final say.
 *     enabled() is checked at every record/consume point, so flipping the
 *     option off stops capture immediately (retention/Forget of what was
 *     already recorded is #176's job).
 *
 *  2. The per-session read set. Within one MCP session, every briefing,
 *     search and read the agent received records an IDENTIFIER ONLY
 *     ({kind, id, version?} — never content) into the session's read set.
 *     When the session writes (memory-write, section-update), the ability
 *     consumes the set via consume() and attaches it to the new item; the
 *     set then starts empty again ("the read set since its last write").
 *
 *     Identifiers in, identifiers out: kind is whitelisted against
 *     KINDS, ids/versions are positive ints, the set is deduplicated by
 *     kind:id and capped at MAX_ITEMS (oldest dropped). Nothing the agent
 *     read can ever be reconstructed from a read set.
 *
 * Session lifecycle mirrors Core\ClientContext (Unit M): persisted under
 * the adapter's own Mcp-Session-Id in a short-lived transient, restored at
 * `rest_request_before_callbacks`, and wiped on `initialize`. All entry
 * points no-op (and consume() returns []) when the switch is off, so the
 * off state costs one option read per request.
 */
final class ActionContext {

	/** Option name for the site setting (#176 owns the UI; default '1' = on). */
	public const OPTION = 'ab_action_context';

	/** Filter with the final say over the option (default true = on). */
	public const FILTER_ENABLED = 'agent_brain/action_context_enabled';

	/** Whitelisted identifier kinds. Identifiers only, never content. */
	public const KINDS = [ 'memory', 'knowledge', 'procedure', 'section', 'artifact', 'handoff' ];

	/**
	 * Cap on the number of identifiers kept per session's pending read set.
	 * A session that reads thousands of items before writing still records
	 * at most this many — the identifiers the write most plausibly acted on
	 * are the RECENT ones, so the oldest are dropped first.
	 */
	private const MAX_ITEMS = 200;

	/** Transient key prefix; values are session-scoped identifier lists. */
	private const TRANSIENT_PREFIX = 'ab_mcp_readset_';

	private const TRANSIENT_TTL = DAY_IN_SECONDS;

	/**
	 * Pending identifiers for the current session; null until the session is
	 * known (restoreFromSession()/setSession()) or a read is recorded in-process.
	 *
	 * @var array<int, array{kind:string, id:int, version?:int}>|null
	 */
	private static ?array $items = null;

	/** @var array<string, true> dedupe index: "kind:id" => true. */
	private static array $seen = [];

	private static ?string $sessionId = null;

	private static bool $isSet = false;

	/**
	 * Whether action-context capture is on for this site. Reads the
	 * `ab_action_context` option (default '1') and gives the
	 * `agent_brain/action_context_enabled` filter the final say.
	 */
	public static function enabled(): bool {
		$on = true;

		if ( function_exists( 'get_option' ) ) {
			$on = get_option( self::OPTION, '1' ) === '1';
		}

		if ( function_exists( 'apply_filters' ) ) {
			$on = (bool) apply_filters( self::FILTER_ENABLED, $on );
		}

		return $on;
	}

	/**
	 * The acting request's context for attribution labels (approvals, write
	 * logs): which MCP client (if any) the request arrived on, which person
	 * a delegated token acts for (Core\AuthorizedBy), and whether the action
	 * was relayed by an AI client (a known MCP client — wp-admin and WP-CLI
	 * never set ClientContext). Pure read of the two Core registries; safe
	 * on any request path.
	 *
	 * @return array{client: ?string, via: int, ai: bool}
	 */
	public static function actorContext(): array {
		$client = ClientContext::current();

		return [
			'client' => $client,
			'via'    => AuthorizedBy::id(),
			'ai'     => $client !== null,
		];
	}

	/**
	 * Records one read identifier into the current session's pending read
	 * set. No-op when the switch is off, no session is known yet, the kind
	 * is not whitelisted, or the id is not a positive int. $version is
	 * optional (memory rows and handoffs are not versioned).
	 */
	public static function record( string $kind, int $id, ?int $version = null ): void {
		if ( ! self::enabled() || $id <= 0 || ! in_array( $kind, self::KINDS, true ) ) {
			return;
		}

		// First read of a request may arrive before the session was restored
		// (e.g. an ability invoked in-process in tests): keep those
		// identifiers in-process only, exactly like ClientContext does.
		if ( self::$items === null ) {
			self::$items = [];
			self::$isSet = true;
		}

		$key = $kind . ':' . $id;
		if ( isset( self::$seen[ $key ] ) ) {
			// Re-reading the same item refreshes nothing: one identifier per
			// item per write window keeps the set bounded and unambiguous.
			return;
		}

		$item = [ 'kind' => $kind, 'id' => $id ];
		if ( $version !== null && $version > 0 ) {
			$item['version'] = $version;
		}

		self::$seen[ $key ]  = true;
		self::$items[]       = $item;
		self::$items         = array_slice( self::$items, - self::MAX_ITEMS );
		self::persist();
	}

	/**
	 * Records a batch of read identifiers (a briefing's item list, a search
	 * result page). Each element is [kind, id] or [kind, id, version].
	 *
	 * @param array<int, array{0:string, 1:int, 2?:int}> $items
	 */
	public static function recordMany( array $items ): void {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			self::record(
				(string) $item[0],
				(int) $item[1],
				isset( $item[2] ) ? (int) $item[2] : null
			);
		}
	}

	/**
	 * The read set accumulated since the session's last write: every
	 * identifier consumed, the set cleared, the empty set persisted.
	 * Returns [] when the switch is off — writers then store nothing.
	 *
	 * @return array<int, array{kind:string, id:int, version?:int}>
	 */
	public static function consume(): array {
		if ( ! self::enabled() || self::$items === null ) {
			return [];
		}

		$items       = self::$items;
		self::$items = [];
		self::$seen  = [];
		self::persist();

		return $items;
	}

	/**
	 * Restores a previously persisted read set for $sessionId into the
	 * current request, unless this request already recorded one in-process.
	 * Called from Mcp\AdapterBridge for every request carrying a
	 * Mcp-Session-Id header, exactly like Core\ClientContext.
	 */
	public static function restoreFromSession( ?string $sessionId ): void {
		if ( self::$isSet || $sessionId === null || $sessionId === '' ) {
			return;
		}

		self::$sessionId = self::sanitizeSessionId( $sessionId );
		self::$isSet     = true;
		self::$items     = [];
		self::$seen      = [];

		if ( self::$sessionId === null || ! function_exists( 'get_transient' ) ) {
			return;
		}

		$raw = get_transient( self::transientKey( self::$sessionId ) );

		if ( ! is_string( $raw ) || $raw === '' ) {
			return;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return;
		}

		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$kind = (string) ( $item['kind'] ?? '' );
			$id   = (int) ( $item['id'] ?? 0 );
			if ( $id <= 0 || ! in_array( $kind, self::KINDS, true ) ) {
				continue;
			}

			$version = isset( $item['version'] ) ? (int) $item['version'] : null;

			$entry = [ 'kind' => $kind, 'id' => $id ];
			if ( $version !== null && $version > 0 ) {
				$entry['version'] = $version;
			}

			self::$items[] = $entry;
			self::$seen[ $kind . ':' . $id ] = true;
		}

		// Re-apply the cap after a restore: a persisted set near the cap that
		// then grows in-process is trimmed by record() anyway, but a set
		// restored from an older, unbounded build must never exceed it either.
		self::$items = array_slice( self::$items, - self::MAX_ITEMS );
	}

	/**
	 * Starts a fresh read set for $sessionId. Called on the MCP
	 * `initialize` handshake: a new session never inherits what a previous
	 * session on the same transport saw.
	 */
	public static function resetSession( ?string $sessionId ): void {
		if ( $sessionId === null || $sessionId === '' ) {
			return;
		}

		$sanitized = self::sanitizeSessionId( $sessionId );
		if ( $sanitized === null ) {
			return;
		}

		self::$sessionId = $sanitized;
		self::$items     = [];
		self::$seen      = [];
		self::$isSet     = true;

		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::transientKey( $sanitized ) );
		}
	}

	/** Resets the in-process state only; any persisted session entry is untouched (tests). */
	public static function reset(): void {
		self::$items     = null;
		self::$seen      = [];
		self::$sessionId = null;
		self::$isSet     = false;
	}

	private static function persist(): void {
		if ( self::$sessionId === null || ! function_exists( 'set_transient' ) ) {
			return;
		}

		$payload = self::$items === null ? [] : self::$items;

		set_transient( self::transientKey( self::$sessionId ), wp_json_encode( $payload ), self::TRANSIENT_TTL );
	}

	private static function sanitizeSessionId( string $sessionId ): ?string {
		$sessionId = preg_replace( '/[^A-Za-z0-9._-]/', '', $sessionId ) ?? '';

		return $sessionId === '' ? null : mb_substr( $sessionId, 0, 100 );
	}

	private static function transientKey( string $sessionId ): string {
		// Transient option names are capped at 172 chars by wp_options; the
		// prefix plus a sanitized session id is always well under that
		// (mirrors Core\ClientContext::sessionKey()).
		return self::TRANSIENT_PREFIX . $sessionId;
	}
}
