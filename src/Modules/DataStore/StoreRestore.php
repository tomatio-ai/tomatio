<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Portability\ExtensionIds;
use AgentBrain\Modules\Portability\ImportFailed;
use AgentBrain\Modules\Portability\WritesTables;

/**
 * #547: the data store extension (StoreExport) back into the tables when a
 * whole brain is migrated (Portability\RestoreWriter): documents, their
 * write history and seats, under their own ids, for the artifacts the
 * migration rebuilds. Writers are mapped from principal ids to the
 * migrated accounts; share link numbers are kept as they were (the links
 * themselves are not live on the new brain, so undo-a-link finds nothing
 * new to undo).
 *
 * All or nothing, like the documents: every file known and well formed,
 * every artifact rebuilt and every principal in the archive, and no id
 * already used here. Otherwise nothing is written and the files are kept
 * as they came (Portability\Kept), with the reason in the notes.
 */
final class StoreRestore {

	use WritesTables;

	/** @var array<int, array<string, mixed>> */
	private array $docs = [];

	/** @var array<int, array<string, mixed>> */
	private array $history = [];

	/** @var array<int, array<string, mixed>> */
	private array $seats = [];

	private ?string $reason = null;

	private bool $present = false;

	/** Rows of artifacts left behind (#549). */
	private int $left = 0;

	/** @var (callable(int): int)|null Moving (#550): archive artifact id => artifact id here. */
	private $artifactOf = null;

	/**
	 * @param array<int, string>  $files      The archive's extension files.
	 * @param array<int, true>    $artifacts  Artifact ids the migration rebuilds.
	 * @param array<string, true> $principals Principal ids of the archive.
	 * @param array<int, true>    $leftBehind Artifact ids the migration leaves behind (#549): their rows are skipped.
	 * @param bool                $newIds     Moving scopes (#550): rows get new ids here, so the archive's need not be free.
	 */
	public function __construct( private string $dir, array $files, private array $artifacts, private array $principals, private array $leftBehind = [], bool $newIds = false ) {
		try {
			foreach ( $files as $path ) {
				if ( ! self::owns( $path ) ) {
					continue;
				}
				$this->present = true;
				if ( preg_match( '#^(?:docs|history|seats)/([1-9][0-9]{0,17})/#', substr( $path, strlen( ExtensionIds::path( StoreExport::id() ) ) ), $m ) === 1 && isset( $this->leftBehind[ (int) $m[1] ] ) ) {
					++$this->left;
					continue;
				}
				$this->read( $path );
			}
			if ( ! $newIds ) {
				$this->checkFreeIds();
			}
		} catch ( \UnexpectedValueException $e ) {
			$this->reason = $e->getMessage();
			$this->docs   = $this->history = $this->seats = [];
		}
	}

	public static function owns( string $path ): bool {
		return str_starts_with( $path, ExtensionIds::path( StoreExport::id() ) );
	}

	public function rebuilds(): bool {
		return $this->present && $this->reason === null;
	}

	public function reason(): ?string {
		return $this->present ? $this->reason : null;
	}

	/**
	 * Every person and agent the rows name (#550).
	 *
	 * @return array<string, true>
	 */
	public function referencedPrincipals(): array {
		$out = [];
		foreach ( [ $this->docs, $this->history ] as $rows ) {
			foreach ( $rows as $r ) {
				foreach ( [ 'created_by', 'updated_by', 'by' ] as $key ) {
					if ( is_string( $r[ $key ] ?? null ) ) {
						$out[ $r[ $key ] ] = true;
					}
				}
			}
		}

		return $out;
	}

	public function leftRecords(): int {
		return $this->left;
	}

	public function records(): int {
		return count( $this->docs ) + count( $this->history ) + count( $this->seats );
	}

	private function read( string $path ): void {
		$base = ExtensionIds::path( StoreExport::id() );
		$file = substr( $path, strlen( $base ) );
		$raw  = (string) file_get_contents( $this->dir . '/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file of the unpacked archive.
		$r    = json_decode( $raw, true );
		if ( ! is_array( $r ) || array_is_list( $r ) ) {
			self::fail( $path . ' is not a JSON object' );
		}

		if ( preg_match( '#^docs/([1-9][0-9]{0,17})/([A-Za-z0-9_][A-Za-z0-9._-]{0,63})/([1-9][0-9]{0,17})\.json$#', $file, $m ) === 1 ) {
			$this->check( $path, $r, StoreExport::DOC_KEYS, 'datastore-' . $m[3], (int) $m[1] );
			if ( ( $r['collection'] ?? null ) !== $m[2] || ! is_string( $r['key'] ?? null ) || ! array_key_exists( 'value', $r ) ) {
				self::fail( $path . ': collection, key or value is not what the export writes' );
			}
			$this->writers( $path, $r, [ 'created_by', 'updated_by' ] );
			// The value as written, objects kept as objects.
			$object      = json_decode( $raw );
			$r['_value'] = (string) json_encode( is_object( $object ) ? $object->value : null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$this->docs[ (int) $m[3] ] = $r;
		} elseif ( preg_match( '#^history/([1-9][0-9]{0,17})/([1-9][0-9]{0,17})\.json$#', $file, $m ) === 1 ) {
			$this->check( $path, $r, StoreExport::HISTORY_KEYS, 'datastore-history-' . $m[2], (int) $m[1] );
			if ( ! is_string( $r['collection'] ?? null ) || ! is_string( $r['key'] ?? null ) || ! is_int( $r['version'] ?? null ) || ! is_string( $r['op'] ?? null ) ) {
				self::fail( $path . ': collection, key, version or op is not what the export writes' );
			}
			$this->writers( $path, $r, [ 'by' ] );
			$this->history[ (int) $m[2] ] = $r;
		} elseif ( preg_match( '#^seats/([1-9][0-9]{0,17})/([1-9][0-9]{0,17})\.json$#', $file, $m ) === 1 ) {
			$this->check( $path, $r, StoreExport::SEAT_KEYS, 'seat-' . $m[2], (int) $m[1] );
			if ( ! is_string( $r['name'] ?? null ) || StoreSeats::normalizeName( $r['name'] ) === null || ! is_string( $r['secret_sha256'] ?? null ) || preg_match( '/^[a-f0-9]{64}$/', $r['secret_sha256'] ) !== 1 ) {
				self::fail( $path . ': its name or secret digest is not one this version reads' );
			}
			$this->seats[ (int) $m[2] ] = $r;
		} else {
			self::fail( $path . ' is not a file this version reads' );
		}
	}

	/**
	 * @param array<string, mixed> $r
	 * @param array<int, string>   $keys
	 */
	private function check( string $path, array $r, array $keys, string $id, int $artifact ): void {
		$unknown = array_diff( array_keys( $r ), $keys );
		if ( $unknown !== [] ) {
			self::fail( sprintf( '%s: unknown key %s', $path, (string) reset( $unknown ) ) );
		}
		if ( ( $r['id'] ?? null ) !== $id || ( $r['artifact'] ?? null ) !== 'artifact-' . $artifact ) {
			self::fail( $path . ': its id or artifact does not match its file name' );
		}
		if ( ! isset( $this->artifacts[ $artifact ] ) ) {
			self::fail( $path . ': its artifact is not migrated' );
		}
	}

	/**
	 * @param array<string, mixed> $r
	 * @param array<int, string>   $keys
	 */
	private function writers( string $path, array $r, array $keys ): void {
		foreach ( $keys as $key ) {
			$type = $r[ $key . '_type' ] ?? 'anonymous';
			if ( ! in_array( $type, [ 'person', 'agent', 'anonymous' ], true ) ) {
				self::fail( sprintf( '%s: %s_type is not person, agent or anonymous', $path, $key ) );
			}
			$principal = $r[ $key ] ?? null;
			if ( $principal !== null && ( ! is_string( $principal ) || ! isset( $this->principals[ $principal ] ) ) ) {
				self::fail( sprintf( '%s: %s is not a person or agent of the archive', $path, $key ) );
			}
		}
	}

	private function checkFreeIds(): void {
		foreach ( [ StoreData::table() => $this->docs, StoreHistory::table() => $this->history, StoreSeats::table() => $this->seats ] as $table => $rows ) {
			if ( $rows === [] ) {
				continue;
			}
			foreach ( array_chunk( array_keys( $rows ), 500 ) as $chunk ) {
				$in = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
				if ( Db::getResults( "SELECT id FROM {$table} WHERE id IN ({$in}) LIMIT 1", $chunk ) !== [] ) {
					self::fail( 'page store ids of the archive are already used on this brain' );
				}
			}
		}
	}

	/**
	 * Writes every row under its own id, inside the migration's transaction;
	 * or, moving scopes into a brain in use (#550, $artifactOf given), under
	 * new ids for the moved artifacts, without the share links of the source.
	 *
	 * @param callable(mixed): int    $userOf     principal id => user id (0 when unknown)
	 * @param (callable(int): int)|null $artifactOf archive artifact id => artifact id here
	 */
	public function write( callable $userOf, ?callable $artifactOf = null ): void {
		if ( ! $this->rebuilds() ) {
			return;
		}
		$this->artifactOf = $artifactOf;

		foreach ( $this->docs as $id => $r ) {
			$this->insert( StoreData::table(), [
				'id'               => $id,
				'artifact_id'      => $this->artifact( $r ),
				'collection'       => (string) $r['collection'],
				'doc_key'          => (string) $r['key'],
				'value'            => (string) $r['_value'],
				'size'             => (int) ( $r['size'] ?? strlen( (string) $r['_value'] ) ),
				'created_by_type'  => (string) ( $r['created_by_type'] ?? 'anonymous' ),
				'created_by_id'    => self::writer( $r, 'created_by', $userOf ),
				'created_by_name'  => isset( $r['created_by_name'] ) ? (string) $r['created_by_name'] : null,
				'created_at'       => self::mysql( (string) ( $r['created_at'] ?? '' ) ),
				'updated_by_type'  => (string) ( $r['updated_by_type'] ?? 'anonymous' ),
				'updated_by_id'    => self::writer( $r, 'updated_by', $userOf ),
				'updated_by_name'  => isset( $r['updated_by_name'] ) ? (string) $r['updated_by_name'] : null,
				'updated_at'       => self::mysql( (string) ( $r['updated_at'] ?? '' ) ),
				'artifact_version' => (int) ( $r['artifact_version'] ?? 0 ),
				'revision'         => (int) ( $r['revision'] ?? 1 ),
				'created_via_link' => $this->link( $r['created_via'] ?? null ),
				'updated_via_link' => $this->link( $r['updated_via'] ?? null ),
				'owner'            => isset( $r['owner'] ) ? (string) $r['owner'] : null,
			] );
		}

		foreach ( $this->history as $id => $r ) {
			$this->insert( StoreHistory::table(), [
				'id'          => $id,
				'artifact_id' => $this->artifact( $r ),
				'collection'  => (string) $r['collection'],
				'doc_key'     => (string) $r['key'],
				'version'     => (int) $r['version'],
				'op'          => (string) $r['op'],
				'value'       => isset( $r['value'] ) ? (string) $r['value'] : null,
				'delta'       => isset( $r['delta'] ) ? (string) $r['delta'] : null,
				'by_type'     => (string) ( $r['by_type'] ?? 'anonymous' ),
				'by_id'       => self::writer( $r, 'by', $userOf ),
				'by_name'     => isset( $r['by_name'] ) ? (string) $r['by_name'] : null,
				'via_link'    => $this->link( $r['via'] ?? null ),
				'at'          => self::mysql( (string) ( $r['at'] ?? '' ) ),
			] );
		}

		foreach ( $this->seats as $id => $r ) {
			$name = (string) StoreSeats::normalizeName( (string) $r['name'] );
			$this->insert( StoreSeats::table(), [
				'id'           => $id,
				'artifact_id'  => $this->artifact( $r ),
				'name'         => $name,
				'name_key'     => StoreSeats::nameKey( $name ),
				'secret_hash'  => (string) $r['secret_sha256'],
				'link_id'      => $this->link( $r['via'] ?? null ),
				'created_at'   => self::mysql( (string) ( $r['created_at'] ?? '' ) ),
				'opens'        => (int) ( $r['opens'] ?? 0 ),
				'last_seen_at' => isset( $r['last_seen_at'] ) ? self::mysql( (string) $r['last_seen_at'] ) : null,
			] );
		}
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function insert( string $table, array $row ): void {
		global $wpdb;

		if ( $this->artifactOf !== null ) {
			unset( $row['id'] );
		}

		if ( $wpdb->insert( $table, $row ) === false ) {
			throw new ImportFailed( 'internal', 'The page store data could not be written.' );
		}
	}

	/**
	 * @param array<string, mixed> $r
	 */
	private function artifact( array $r ): int {
		$id = (int) substr( (string) $r['artifact'], 9 ); // 'artifact-' is 9 chars.

		return $this->artifactOf !== null ? (int) ( $this->artifactOf )( $id ) : $id;
	}

	/**
	 * @param array<string, mixed> $r
	 * @param callable(mixed): int $userOf
	 */
	private static function writer( array $r, string $key, callable $userOf ): ?int {
		if ( ( $r[ $key . '_type' ] ?? 'anonymous' ) === 'anonymous' || ! isset( $r[ $key ] ) ) {
			return null;
		}
		$user = $userOf( $r[ $key ] );

		return $user > 0 ? $user : null;
	}

	/**
	 * @param mixed $ref
	 */
	private function link( $ref ): ?int {
		return $this->artifactOf === null && is_string( $ref ) && preg_match( '/^share-([1-9][0-9]{0,17})$/', $ref, $m ) === 1 ? (int) $m[1] : null;
	}

	/**
	 * @throws \UnexpectedValueException Always: the files are kept as they came, for this reason.
	 */
	private static function fail( string $reason ): never {
		throw new \UnexpectedValueException( $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a note for the report, never HTML.
	}
}
