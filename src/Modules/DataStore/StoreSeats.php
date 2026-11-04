<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Core\Db;
use WP_Error;

/**
 * #469: seats, personal identities on one public share link. A viewer
 * claims a name for the artifact and gets a secret; the personal link is the
 * share link with ?seat=NAME (a display hint the server may read) and
 * #seat=SECRET (the proof, which never reaches the server in a URL). The
 * page sends the secret with every store call (the X-Tomatio-Seat header),
 * and rules with owner "seat" let only the seat named by a document's key
 * write it.
 *
 * Names are unique per artifact (case and spacing folded), claims are
 * create-only, and curators release a seat to free its name. Only the hex
 * sha256 of the secret is stored, and the secret is never written to the
 * audit log. Each seat counts the opens of its personal link.
 *
 * Secrets are random (24 bytes here), so the hash needs no salt, and that
 * keeps it the plain hex sha256 pages already use for home-made personal
 * links: a curator imports such claims (name + hash) as seats with no
 * secret needed, and the links already handed out keep working.
 */
final class StoreSeats {

	public const HEADER       = 'X-Tomatio-Seat';
	public const NAME_MAX     = 24;
	public const SECRET_BYTES = 24;
	public const MAX_SEATS    = 200;

	private const NAME_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N} ._\'-]*$/u';

	public static function table(): string {
		return Db::table( 'datastore_seats' );
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			artifact_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(64) NOT NULL,
			name_key VARCHAR(64) NOT NULL,
			secret_hash CHAR(64) NOT NULL,
			link_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			opens INT UNSIGNED NOT NULL DEFAULT 0,
			last_seen_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY artifact_name (artifact_id, name_key),
			UNIQUE KEY secret (secret_hash)
		) {$charset};";
	}

	/**
	 * The display form of a name: trimmed, inner spaces folded, or null when
	 * it is empty, too long or has characters outside letters, digits,
	 * space and . _ ' -.
	 */
	public static function normalizeName( string $name ): ?string {
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
		if ( $name === '' || mb_strlen( $name ) > self::NAME_MAX || preg_match( self::NAME_PATTERN, $name ) !== 1 ) {
			return null;
		}

		return $name;
	}

	/**
	 * The uniqueness key of a name: "Ana  María" and "ANA MARÍA" are one seat.
	 */
	public static function nameKey( string $name ): string {
		return mb_strtoupper( (string) self::normalizeName( $name ) );
	}

	/**
	 * Claims a name. Returns the seat and its secret, which is shown once.
	 *
	 * @return array{name:string,secret:string}|WP_Error
	 */
	public static function claim( int $artifactId, string $name, int $linkId, ?int $now = null ) {
		global $wpdb;

		$normal = self::normalizeName( $name );
		if ( $normal === null ) {
			return new WP_Error( 'seat_name_invalid', sprintf( 'A seat name is 1 to %d letters, digits, spaces and . _ \' -.', self::NAME_MAX ), [ 'status' => 400 ] );
		}

		if ( self::count( $artifactId ) >= self::MAX_SEATS ) {
			return new WP_Error( 'seats_full', 'This page has no more seats.', [ 'status' => 409 ] );
		}

		$secret   = rtrim( strtr( base64_encode( random_bytes( self::SECRET_BYTES ) ), '+/', '-_' ), '=' );
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert(
			self::table(),
			[
				'artifact_id' => $artifactId,
				'name'        => $normal,
				'name_key'    => self::nameKey( $normal ),
				'secret_hash' => self::hash( $secret ),
				'link_id'     => $linkId > 0 ? $linkId : null,
				'created_at'  => gmdate( 'Y-m-d H:i:s', $now ?? time() ),
			]
		);
		$wpdb->suppress_errors( $suppress );

		if ( ! $inserted ) {
			// The unique (artifact, name) index: first claim wins.
			return new WP_Error( 'seat_taken', 'That name already has a seat.', [ 'status' => 409 ] );
		}

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => 0,
				'event'       => 'datastore_seat_claimed',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'seat' => $normal, 'share_link_id' => $linkId ],
			]
		);

		return [ 'name' => $normal, 'secret' => $secret ];
	}

	/**
	 * Imports an existing claim (a name and the hex sha256 of its secret) as
	 * a seat: true when added, false when the name or hash already has one.
	 *
	 * @return bool|WP_Error
	 */
	public static function import( int $artifactId, string $name, string $hash, int $actorId, ?int $createdAt = null ) {
		global $wpdb;

		$normal = self::normalizeName( $name );
		$hash   = strtolower( trim( $hash ) );
		if ( $normal === null || preg_match( '/^[a-f0-9]{64}$/', $hash ) !== 1 ) {
			return new WP_Error( 'seat_import_invalid', 'Each claim needs a seat name and a 64-digit hex sha256.', [ 'status' => 400 ] );
		}

		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert(
			self::table(),
			[
				'artifact_id' => $artifactId,
				'name'        => $normal,
				'name_key'    => self::nameKey( $normal ),
				'secret_hash' => $hash,
				'link_id'     => null,
				'created_at'  => gmdate( 'Y-m-d H:i:s', $createdAt ?? time() ),
			]
		);
		$wpdb->suppress_errors( $suppress );

		if ( ! $inserted ) {
			return false;
		}

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'datastore_seat_imported',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'seat' => $normal ],
			]
		);

		return true;
	}

	/**
	 * Imports the claims a page kept in one of its collections, documents
	 * of {name, hash}: the first claim of each name wins (oldest first),
	 * as such pages decide it. Names already seated and invalid documents
	 * are skipped. The collection itself is left as it is.
	 *
	 * @return array{imported:array<int,string>,skipped:array<int,string>}
	 */
	public static function importCollection( int $artifactId, string $collection, int $actorId ): array {
		$docs = StoreData::list( $artifactId, $collection );
		usort( $docs, static fn ( array $a, array $b ): int => strcmp( (string) $a['created_at'], (string) $b['created_at'] ) );

		$out = [ 'imported' => [], 'skipped' => [] ];
		foreach ( $docs as $doc ) {
			$value = $doc['value'];
			$name  = is_array( $value ) && is_string( $value['name'] ?? null ) ? $value['name'] : '';
			$hash  = is_array( $value ) && is_string( $value['hash'] ?? null ) ? $value['hash'] : '';
			$at    = strtotime( (string) $doc['created_at'] . ' UTC' );
			$done  = self::import( $artifactId, $name, $hash, $actorId, $at !== false ? $at : null );
			$out[ $done === true ? 'imported' : 'skipped' ][] = $name !== '' ? $name : (string) $doc['key'];
		}

		return $out;
	}

	/**
	 * The seat a secret proves, for this artifact, or null.
	 *
	 * @return array{name:string,name_key:string}|null
	 */
	public static function verify( int $artifactId, string $secret ): ?array {
		if ( preg_match( '/^[A-Za-z0-9_-]{20,64}$/', $secret ) !== 1 ) {
			return null;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT name, name_key FROM {$table} WHERE artifact_id = %d AND secret_hash = %s", [ $artifactId, self::hash( $secret ) ] );

		return isset( $rows[0] ) ? [ 'name' => (string) $rows[0]['name'], 'name_key' => (string) $rows[0]['name_key'] ] : null;
	}

	/**
	 * The claimed seat with this name (any spelling), or null: the server
	 * reads ?seat=NAME only to dress the page (title, icon, manifest); it
	 * proves nothing.
	 */
	public static function named( int $artifactId, string $name ): ?string {
		$normal = self::normalizeName( $name );
		if ( $normal === null ) {
			return null;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT name FROM {$table} WHERE artifact_id = %d AND name_key = %s", [ $artifactId, self::nameKey( $normal ) ] );

		return isset( $rows[0] ) ? (string) $rows[0]['name'] : null;
	}

	/**
	 * Counts an open of a seat's personal link.
	 */
	public static function touch( int $artifactId, string $name, ?int $now = null ): void {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$wpdb->query( Db::prepare( "UPDATE {$table} SET opens = opens + 1, last_seen_at = %s WHERE artifact_id = %d AND name_key = %s", [ gmdate( 'Y-m-d H:i:s', $now ?? time() ), $artifactId, self::nameKey( $name ) ] ) );
	}

	/**
	 * Every seat of an artifact (no secrets, no hashes), oldest first.
	 *
	 * @return array<int, array{name:string,created_at:string,share_link_id:int|null,opens:int,last_seen_at:string|null}>
	 */
	public static function all( int $artifactId ): array {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT name, created_at, link_id, opens, last_seen_at FROM {$table} WHERE artifact_id = %d ORDER BY id ASC", [ $artifactId ] );

		return array_map(
			static fn ( array $r ): array => [
				'name'          => (string) $r['name'],
				'created_at'    => (string) $r['created_at'],
				'share_link_id' => $r['link_id'] !== null ? (int) $r['link_id'] : null,
				'opens'         => (int) $r['opens'],
				'last_seen_at'  => $r['last_seen_at'] !== null ? (string) $r['last_seen_at'] : null,
			],
			$rows
		);
	}

	/**
	 * #501: the seats' names, in claim order, and nothing else: what a page
	 * may read (SeatRoute _seats).
	 *
	 * @return array<int, string>
	 */
	public static function names( int $artifactId ): array {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT name FROM {$table} WHERE artifact_id = %d ORDER BY created_at ASC, id ASC", [ $artifactId ] );

		return array_map( static fn ( array $r ): string => (string) $r['name'], $rows );
	}

	public static function count( int $artifactId ): int {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		return (int) $wpdb->get_var( Db::prepare( "SELECT COUNT(*) FROM {$table} WHERE artifact_id = %d", [ $artifactId ] ) );
	}

	/**
	 * Frees a name (a curator's call): its secret stops working and the
	 * name can be claimed again. The documents keyed by it stay.
	 */
	public static function release( int $artifactId, string $name, int $actorId ): bool {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$wpdb->query( Db::prepare( "DELETE FROM {$table} WHERE artifact_id = %d AND name_key = %s", [ $artifactId, self::nameKey( $name ) ] ) );
		$released = (int) $wpdb->rows_affected > 0;

		if ( $released ) {
			do_action(
				'agent_brain/audit',
				[
					'actor_id'    => $actorId,
					'event'       => 'datastore_seat_released',
					'status'      => 'ok',
					'object_type' => 'artifact',
					'object_id'   => (string) $artifactId,
					'meta'        => [ 'seat' => (string) self::normalizeName( $name ) ],
				]
			);
		}

		return $released;
	}

	public static function deleteForArtifact( int $artifactId ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$wpdb->query( Db::prepare( 'DELETE FROM ' . self::table() . ' WHERE artifact_id = %d', [ $artifactId ] ) );
	}

	private static function hash( string $secret ): string {
		return hash( 'sha256', $secret );
	}
}
