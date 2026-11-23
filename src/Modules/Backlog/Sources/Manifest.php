<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog\Sources;

/**
 * #892 (note 643 section 665): what a source declares, read from
 * extensions/backlog/sources/<id>.json. One contract for every tool: its
 * link prefix, per-field direction (read, write or both) and clash rule,
 * mode (once or continuous), trigger, where new items go, the credentials
 * it needs (kept by the brain, never an agent) and the snapshot it keeps.
 *
 * A source with no write fields never writes to its tool. In this version
 * (step 2) no source writes at all: the Notion manifest's write fields are
 * listed in the dry-run report, and sent in step 5.
 */
final class Manifest {

	public const CONTRACT   = 1;
	public const DIRECTIONS = [ 'read', 'write', 'both' ];
	public const CLASHES    = [ 'replace', 'fill', 'flag', 'both_changed' ];
	public const MODES      = [ 'once', 'continuous' ];

	/** @param array<string, mixed> $data */
	private function __construct( private array $data ) {
	}

	/** The bundled manifest of source $id ("issues", "notion"). */
	public static function bundled( string $id ): self|string {
		if ( preg_match( '/^[a-z][a-z0-9-]{0,31}$/', $id ) !== 1 ) {
			return 'Unknown source: ' . $id;
		}
		$path = \AgentBrain\Modules\Extensions\Package::bundledDir( \AgentBrain\Modules\Backlog\Backlog::PACKAGE ) . '/sources/' . $id . '.json';
		$raw  = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file inside the plugin.
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) ) {
			return 'Unknown source: ' . $id;
		}

		return self::fromArray( $data );
	}

	/** @param array<string, mixed> $data */
	public static function fromArray( array $data ): self|string {
		$problem = self::check( $data );

		return $problem === '' ? new self( $data ) : $problem;
	}

	/**
	 * The first reason $data is not a contract 1 source manifest, or ''.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function check( array $data ): string {
		if ( ( $data['contract'] ?? null ) !== self::CONTRACT ) {
			return 'The source manifest targets another contract.';
		}
		if ( ! is_string( $data['id'] ?? null ) || preg_match( '/^[a-z][a-z0-9-]{0,31}$/', $data['id'] ) !== 1 ) {
			return 'The source manifest id must be lower-case letters, digits and hyphens.';
		}
		if ( ! is_string( $data['link']['prefix'] ?? null ) || preg_match( '/^[a-z][a-z0-9-]{0,31}$/', $data['link']['prefix'] ) !== 1 ) {
			return 'The source manifest needs link.prefix.';
		}
		if ( ! in_array( $data['mode'] ?? null, self::MODES, true ) ) {
			return 'The source manifest mode must be once or continuous.';
		}
		if ( ! is_array( $data['fields'] ?? null ) || $data['fields'] === [] ) {
			return 'The source manifest maps no fields.';
		}
		foreach ( $data['fields'] as $name => $field ) {
			if ( ! is_array( $field ) || ! is_string( $field['to'] ?? null ) || ! in_array( $field['direction'] ?? null, self::DIRECTIONS, true ) || ! in_array( $field['clash'] ?? null, self::CLASHES, true ) ) {
				return 'Field ' . $name . ' needs to, a direction (read, write, both) and a clash rule.';
			}
		}
		if ( ! in_array( $data['new_items']['status'] ?? 'inbox', [ 'inbox', 'ready' ], true ) ) {
			return 'New items go to the Inbox, or ready.';
		}

		return '';
	}

	public function id(): string {
		return (string) $this->data['id'];
	}

	public function name(): string {
		return (string) ( $this->data['name'] ?? $this->data['id'] );
	}

	public function prefix(): string {
		return (string) $this->data['link']['prefix'];
	}

	/**
	 * The fields this source writes to its tool (direction write or both).
	 *
	 * @return array<int, string>
	 */
	public function writeFields(): array {
		$out = [];
		foreach ( $this->data['fields'] as $name => $field ) {
			if ( in_array( $field['direction'], [ 'write', 'both' ], true ) ) {
				$out[] = (string) $name;
			}
		}

		return $out;
	}

	public function writes(): bool {
		return $this->writeFields() !== [];
	}

	/** The clash rule of field $name ('replace' for a field it doesn't map). */
	public function clash( string $name ): string {
		return (string) ( $this->data['fields'][ $name ]['clash'] ?? 'flag' );
	}

	/**
	 * A part of the manifest (properties, statuses, bands, new_items, ...).
	 *
	 * @return mixed
	 */
	public function get( string $part ) {
		return $this->data[ $part ] ?? null;
	}
}
