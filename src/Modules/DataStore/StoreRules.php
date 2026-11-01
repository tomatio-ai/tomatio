<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

/**
 * #384: what a share page may do with one collection of its artifact's
 * store. The server enforces it: a page's code runs on the viewer's device,
 * and anyone holding the link can call the API without the page.
 *
 * A collection's rules always start from a named preset and may only be
 * tightened on the dimensions that decide who can do what (ops, writers,
 * owner, visibility, version checks, one document per identity). Keys,
 * value schema, increment/append settings and rate limits may be set freely
 * within hard caps. Everything not allowed is refused (default deny).
 *
 * Identity: every request comes through a share link, so the writer is
 * "member:{type}:{id}" when a verified viewer token is presented and
 * "link:{id}" otherwise. writers "anonymous" and "link" admit the same
 * requests; "link" says the collection relies on per-link ownership, so give
 * each person their own link. "member" needs a viewer token.
 *
 * #469: with seats on (StoreMode), a viewer who claimed a seat writes as
 * "seat:{NAME}". Owner "seat" is the tightest owner: a document's key is a
 * seat name and only that seat may write it (no seat, or another seat, is
 * not_owner); reads stay as the visibility says.
 */
final class StoreRules {

	public const OPS = [ 'read', 'create', 'replace', 'update', 'delete', 'increment', 'append' ];

	public const WRITERS    = [ 'anonymous', 'link', 'member' ];      // Weakest first.
	public const OWNERS     = [ 'anyone', 'writer', 'self', 'seat' ]; // Loosest first.
	public const VISIBILITY = [ 'all', 'own', 'none' ];               // Widest first.

	public const KEY_GENERATED = 'generated';
	public const KEY_ANY       = 'any';

	public const MAX_LIMIT_COUNT   = 10000;
	public const MAX_LIMIT_SECONDS = 86400 * 7;
	public const MAX_PATTERN_BYTES = 200;

	/**
	 * The presets: name => rules. A preset is complete; tightening starts here.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function presets(): array {
		$all = self::OPS;

		return [
			// Read-only for the page; members write over MCP. Also what a
			// pre-#384 "read" collection means.
			'read'     => [
				'ops'        => [ 'read' ],
				'writers'    => 'anonymous',
				'owner'      => 'anyone',
				'visibility' => 'all',
				'key'        => self::KEY_ANY,
				'limits'     => [],
			],
			// Leaderboards, likes: bump an integer field by a fixed step.
			'counter'  => [
				'ops'        => [ 'read', 'increment' ],
				'writers'    => 'anonymous',
				'owner'      => 'anyone',
				'visibility' => 'all',
				'key'        => '^[A-Za-z0-9][A-Za-z0-9 _-]{0,31}$',
				'increment'  => [ 'fields' => [ 'count' ], 'step' => 1 ],
				'limits'     => [ 'per_key' => [ 'max' => 1, 'seconds' => 60 ], 'per_identity' => [ 'max' => 60, 'seconds' => 3600 ] ],
			],
			// Polls, ratings: one document per identity, changeable by its owner.
			'ballot'   => [
				'ops'              => [ 'read', 'create', 'update', 'replace' ],
				'writers'          => 'link',
				'owner'            => 'self',
				'visibility'       => 'all',
				'key'              => self::KEY_ANY,
				'one_per_identity' => true,
				'limits'           => [ 'per_identity' => [ 'max' => 20, 'seconds' => 3600 ] ],
			],
			// Sign-up sheets, RSVPs, guest books, comments.
			'entries'  => [
				'ops'        => [ 'read', 'create', 'update', 'replace', 'delete' ],
				'writers'    => 'anonymous',
				'owner'      => 'writer',
				'visibility' => 'all',
				'key'        => self::KEY_GENERATED,
				'limits'     => [ 'per_identity' => [ 'max' => 20, 'seconds' => 3600 ], 'per_ip_day' => [ 'max' => 100 ] ],
			],
			// Checklists, saved games, drafts: each identity sees only its own.
			'personal' => [
				'ops'        => $all,
				'writers'    => 'link',
				'owner'      => 'self',
				'visibility' => 'own',
				'key'        => self::KEY_ANY,
				'limits'     => [ 'per_identity' => [ 'max' => 120, 'seconds' => 3600 ] ],
			],
			// Forms, surveys: write-only for the page, read by members.
			'inbox'    => [
				'ops'        => [ 'create' ],
				'writers'    => 'anonymous',
				'owner'      => 'anyone',
				'visibility' => 'none',
				'key'        => self::KEY_GENERATED,
				'limits'     => [ 'per_identity' => [ 'max' => 20, 'seconds' => 3600 ], 'per_ip_day' => [ 'max' => 20 ] ],
			],
			// Team boards: named members only, every write version-checked.
			// Also what a pre-#384 "write" collection means.
			'shared'   => [
				'ops'             => $all,
				'writers'         => 'member',
				'owner'           => 'anyone',
				'visibility'      => 'all',
				'key'             => self::KEY_ANY,
				'require_version' => true,
				'limits'          => [ 'per_identity' => [ 'max' => 300, 'seconds' => 3600 ] ],
			],
		];
	}

	/**
	 * Full rules from a configuration entry: a preset name, a pre-#384
	 * access word ("read", "write" and their spellings), or an object
	 * {"preset": name, ...overrides}.
	 *
	 * @param mixed $entry
	 * @return array<string, mixed>|string The rules, or an error message.
	 */
	public static function normalize( $entry ) {
		$legacy = [
			'read-only'  => 'read',
			'readonly'   => 'read',
			'ro'         => 'read',
			'write'      => 'shared',
			'read-write' => 'shared',
			'readwrite'  => 'shared',
			'rw'         => 'shared',
		];

		if ( is_string( $entry ) ) {
			$entry = [ 'preset' => $entry ];
		}

		if ( ! is_array( $entry ) || ! isset( $entry['preset'] ) || ! is_string( $entry['preset'] ) ) {
			return 'every collection needs a preset: ' . implode( ', ', array_keys( self::presets() ) );
		}

		$name    = strtolower( trim( $entry['preset'] ) );
		$name    = $legacy[ $name ] ?? $name;
		$presets = self::presets();
		if ( ! isset( $presets[ $name ] ) ) {
			return 'unknown preset "' . $entry['preset'] . '"; use one of ' . implode( ', ', array_keys( $presets ) );
		}

		$base        = $presets[ $name ];
		$base['ops'] = array_values( array_intersect( self::OPS, $base['ops'] ) );
		$rules       = $base + [ 'require_version' => false, 'one_per_identity' => false ];
		unset( $entry['preset'] );

		foreach ( $entry as $field => $value ) {
			$error = self::apply( $rules, $base, (string) $field, $value );
			if ( $error !== null ) {
				return $field . ': ' . $error;
			}
		}

		if ( $rules['owner'] === 'seat' && $rules['key'] === self::KEY_GENERATED ) {
			return 'owner: "seat" keys documents by seat name, so the preset must not generate keys';
		}

		if ( in_array( 'increment', $rules['ops'], true ) && $name === 'counter' && ( $rules['increment']['fields'] ?? [] ) === [] ) {
			return 'increment: a counter needs at least one field';
		}

		$rules['preset'] = $name;

		return self::ordered( $rules );
	}

	/**
	 * One override onto $rules. Null when it applies, else the reason.
	 *
	 * @param array<string, mixed> $rules
	 * @param array<string, mixed> $base The preset, the limit of loosening.
	 * @param mixed                $value
	 */
	private static function apply( array &$rules, array $base, string $field, $value ): ?string {
		switch ( $field ) {
			case 'ops':
				if ( ! is_array( $value ) || $value === [] ) {
					return 'a non-empty list';
				}
				foreach ( $value as $op ) {
					if ( ! is_string( $op ) || ! in_array( $op, $base['ops'], true ) ) {
						return 'the preset allows only ' . implode( ', ', $base['ops'] );
					}
				}
				$rules['ops'] = array_values( array_intersect( self::OPS, $value ) );

				return null;

			case 'writers':
			case 'owner':
			case 'visibility':
				$order = [ 'writers' => self::WRITERS, 'owner' => self::OWNERS, 'visibility' => self::VISIBILITY ][ $field ];
				$at    = is_string( $value ) ? array_search( $value, $order, true ) : false;
				if ( $at === false ) {
					return 'one of ' . implode( ', ', $order );
				}
				if ( $at < array_search( $base[ $field ], $order, true ) ) {
					return 'may only be tightened from "' . $base[ $field ] . '"';
				}
				$rules[ $field ] = $value;

				return null;

			case 'require_version':
			case 'one_per_identity':
				if ( ! is_bool( $value ) ) {
					return 'true or false';
				}
				if ( ! $value && ! empty( $base[ $field ] ) ) {
					return 'the preset requires it';
				}
				$rules[ $field ] = $value;

				return null;

			case 'key':
				if ( ( $base['key'] ?? null ) === self::KEY_GENERATED ) {
					return $value === self::KEY_GENERATED ? null : 'the preset generates keys';
				}
				if ( $value === self::KEY_GENERATED || $value === self::KEY_ANY ) {
					$rules['key'] = $value;

					return null;
				}
				if ( ! is_string( $value ) || strlen( $value ) > self::MAX_PATTERN_BYTES || @preg_match( self::delimit( $value ), '' ) === false ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid pattern is reported, not warned.
					return 'a regular expression of at most ' . self::MAX_PATTERN_BYTES . ' bytes, "generated" or "any"';
				}
				$rules['key'] = $value;

				return null;

			case 'increment':
			case 'append':
				if ( ! is_array( $value ) ) {
					return 'an object {fields, ' . ( $field === 'increment' ? 'step' : 'max_items' ) . '}';
				}
				$fields = $value['fields'] ?? [];
				if ( ! is_array( $fields ) || array_filter( $fields, static fn ( $f ): bool => ! is_string( $f ) || preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $f ) !== 1 ) !== [] ) {
					return 'fields must be a list of field names';
				}
				$out = [ 'fields' => array_values( array_unique( $fields ) ) ];
				if ( $field === 'increment' ) {
					$step = $value['step'] ?? null;
					if ( $step !== null && ( ! is_int( $step ) || $step < 1 || $step > 1000000 ) ) {
						return 'step must be a whole number from 1';
					}
					$out['step'] = $step;
				}
				$rules[ $field ] = $out;

				return null;

			case 'schema':
				$schema = StoreSchema::normalize( $value );

				if ( is_string( $schema ) ) {
					return $schema;
				}
				$rules['schema'] = $schema;

				return null;

			case 'limits':
				if ( ! is_array( $value ) ) {
					return 'an object with per_key, per_identity and/or per_ip_day';
				}
				$limits = [];
				foreach ( $value as $scope => $limit ) {
					if ( ! in_array( $scope, [ 'per_key', 'per_identity', 'per_ip_day' ], true ) ) {
						return 'unknown limit "' . $scope . '"';
					}
					$max     = is_array( $limit ) ? ( $limit['max'] ?? null ) : null;
					$seconds = $scope === 'per_ip_day' ? 86400 : ( is_array( $limit ) ? ( $limit['seconds'] ?? null ) : null );
					if ( ! is_int( $max ) || $max < 1 || $max > self::MAX_LIMIT_COUNT || ! is_int( $seconds ) || $seconds < 1 || $seconds > self::MAX_LIMIT_SECONDS ) {
						return $scope . ' needs max (1 to ' . self::MAX_LIMIT_COUNT . ')' . ( $scope === 'per_ip_day' ? '' : ' and seconds (1 to ' . self::MAX_LIMIT_SECONDS . ')' );
					}
					$limits[ $scope ] = $scope === 'per_ip_day' ? [ 'max' => $max ] : [ 'max' => $max, 'seconds' => $seconds ];
				}
				$rules['limits'] = $limits + $rules['limits'];

				return null;
		}

		return 'unknown rule';
	}

	/**
	 * May this request write at all? Owned documents never need more than
	 * the link: the identity is always the link or the member.
	 *
	 * @param array<string, mixed> $rules
	 */
	public static function writerAllowed( array $rules, bool $isMember ): bool {
		return $rules['writers'] !== 'member' || $isMember;
	}

	/**
	 * The rules as a page sees them (GET store/{collection}/_rules and the
	 * index), plus what the current identity may do.
	 *
	 * @param array<string, mixed> $rules
	 * @param string|null          $seat The request's seat name (#469), null without one.
	 * @return array<string, mixed>
	 */
	public static function describe( string $collection, array $rules, bool $isMember, ?string $seat = null ): array {
		$writes     = self::writerAllowed( $rules, $isMember ) && ( $rules['owner'] !== 'seat' || $seat !== null );
		$out        = [ 'collection' => $collection ] + $rules;
		$out['you'] = [
			'identity' => $isMember ? 'member' : ( $seat !== null ? 'seat' : 'link' ),
			'may'      => $writes ? $rules['ops'] : array_values( array_intersect( $rules['ops'], [ 'read' ] ) ),
		];
		if ( $seat !== null && ! $isMember ) {
			$out['you']['seat'] = $seat;
		}

		return $out;
	}

	/**
	 * A key pattern as a PCRE with delimiters (anchored by the author).
	 */
	public static function delimit( string $pattern ): string {
		return '#' . str_replace( '#', '\\#', $pattern ) . '#u';
	}

	/**
	 * @param array<string, mixed> $rules
	 * @return array<string, mixed>
	 */
	private static function ordered( array $rules ): array {
		$order = [ 'preset', 'ops', 'writers', 'owner', 'visibility', 'key', 'require_version', 'one_per_identity', 'increment', 'append', 'schema', 'limits' ];
		$out   = [];
		foreach ( $order as $field ) {
			if ( array_key_exists( $field, $rules ) ) {
				$out[ $field ] = $rules[ $field ];
			}
		}

		return $out;
	}
}
