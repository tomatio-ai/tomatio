<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog\Sources;

use WP_Error;

/**
 * #892 (sections 652, 665 and 648): the Notion source, read side. It reads
 * every row of one database through the Notion API with the integration
 * token kept in Settings > Backlog, 100 rows per request and at most three
 * requests a second (Retry-After on a 429).
 *
 * The source is two-way (C188), and its manifest says which fields it
 * writes; this version only reads. What a sync would write to Notion (the
 * columns it adds, the rows it changes and creates) is worked out for the
 * dry-run report and sent in step 5, never here.
 *
 * The same JSON the API returns can be given instead ({database: {...},
 * results: [...]} or a list of pages), so the source is testable and a
 * first read can come from elsewhere.
 */
final class NotionSource {

	public const SOURCE  = 'notion';
	public const KEY     = 'notion:backlog';
	private const API    = 'https://api.notion.com/v1';
	private const VERSION = '2022-06-28';
	private const MAX_PAGES = 30;

	/** @var array<string, string> property name => type, from the database */
	private array $schema = [];

	private float $last = 0.0;

	/** @param array<mixed>|null $json */
	public function __construct( private string $database, private string $token, private ?array $json = null ) {
	}

	/** @param array<mixed>|null $json */
	public static function configured( ?array $json = null ): self|string {
		$config = Config::all()['notion'];
		if ( $json !== null ) {
			return new self( $config['database'], '', $json );
		}
		if ( $config['database'] === '' ) {
			return 'The Notion source is not set up: add the database in Settings > Backlog.';
		}
		$token = Config::token( self::SOURCE );
		if ( $token === '' ) {
			return 'The Notion source has no token yet: paste the integration token in Settings > Backlog.';
		}

		return new self( $config['database'], $token );
	}

	/**
	 * Every row as a record, plus the database's columns.
	 *
	 * @return array{records: array<int, array<string, mixed>>, notes: array<int, string>, columns: array<string, string>, unmapped: array<string, int>}|WP_Error
	 */
	public function read( Manifest $manifest ) {
		if ( $this->json !== null ) {
			$pages        = array_is_list( $this->json ) ? $this->json : ( is_array( $this->json['results'] ?? null ) ? $this->json['results'] : [] );
			$this->schema = self::schemaOf( array_is_list( $this->json ) ? [] : ( is_array( $this->json['database'] ?? null ) ? $this->json['database'] : [] ), $pages );
			$notes        = [ 'Read ' . count( $pages ) . ' rows from the JSON given.' ];
		} else {
			$database = $this->call( 'GET', '/databases/' . $this->database, null );
			if ( $database instanceof WP_Error ) {
				return $database;
			}
			$pages  = [];
			$cursor = null;
			for ( $i = 0; $i < self::MAX_PAGES; ++$i ) {
				$body = [ 'page_size' => 100 ];
				if ( $cursor !== null ) {
					$body['start_cursor'] = $cursor;
				}
				$got = $this->call( 'POST', '/databases/' . $this->database . '/query', $body );
				if ( $got instanceof WP_Error ) {
					return $got;
				}
				$pages  = array_merge( $pages, is_array( $got['results'] ?? null ) ? $got['results'] : [] );
				$cursor = ! empty( $got['has_more'] ) && is_string( $got['next_cursor'] ?? null ) ? $got['next_cursor'] : null;
				if ( $cursor === null ) {
					break;
				}
			}
			$this->schema = self::schemaOf( $database, $pages );
			$notes        = [ 'Read ' . count( $pages ) . ' rows from the Notion database.' ];
		}

		$records  = [];
		$unmapped = [];
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) || ! empty( $page['archived'] ) || ! empty( $page['in_trash'] ) ) {
				continue;
			}
			$record = $this->record( $page, $manifest );
			if ( $record === null ) {
				continue;
			}
			if ( isset( $record['unmapped_status'] ) ) {
				$unmapped[ $record['unmapped_status'] ] = ( $unmapped[ $record['unmapped_status'] ] ?? 0 ) + 1;
			}
			$records[] = $record;
		}

		return [ 'records' => $records, 'notes' => $notes, 'columns' => $this->schema, 'unmapped' => $unmapped ];
	}

	/**
	 * A row as a source record.
	 *
	 * @param array<string, mixed> $page
	 * @return array<string, mixed>|null
	 */
	public function record( array $page, Manifest $manifest ): ?array {
		$props = is_array( $page['properties'] ?? null ) ? $page['properties'] : [];
		$names = (array) $manifest->get( 'properties' );
		$value = static function ( string $field ) use ( $props, $names ): string {
			foreach ( (array) ( $names[ $field ] ?? [] ) as $name ) {
				if ( isset( $props[ $name ] ) && is_array( $props[ $name ] ) ) {
					return self::text( $props[ $name ] );
				}
			}

			return '';
		};

		$title = $value( 'title' );
		if ( $title === '' ) {
			foreach ( $props as $prop ) {
				if ( is_array( $prop ) && ( $prop['type'] ?? '' ) === 'title' ) {
					$title = self::text( $prop );
					break;
				}
			}
		}
		$pageId = strtolower( str_replace( '-', '', (string) ( $page['id'] ?? '' ) ) );
		if ( $pageId === '' ) {
			return null;
		}
		$id = $value( 'id' );
		if ( $id === '' ) {
			foreach ( $props as $prop ) {
				if ( is_array( $prop ) && ( $prop['type'] ?? '' ) === 'unique_id' ) {
					$id = self::text( $prop );
					break;
				}
			}
		}
		$id = $id !== '' ? $id : substr( $pageId, 0, 8 );

		$rawStatus = $value( 'status' );
		$statuses  = (array) $manifest->get( 'statuses' );
		$status    = $rawStatus !== '' ? ( $statuses[ mb_strtolower( $rawStatus ) ] ?? null ) : null;
		$rawBand   = mb_strtolower( $value( 'band' ) );
		$bands     = (array) $manifest->get( 'bands' );

		$record = [
			'source'     => self::SOURCE,
			'key'        => self::KEY,
			'id'         => $id,
			'page_id'    => $pageId,
			'url'        => is_string( $page['url'] ?? null ) && str_starts_with( $page['url'], 'https://' ) ? $page['url'] : '',
			'title'      => $title !== '' ? $title : $id,
			'detail'     => '',
			'kind'       => (string) ( $manifest->get( 'new_items' )['kind'] ?? 'idea' ),
			'updated_at' => (string) ( $page['last_edited_time'] ?? '' ),
			'created_at' => (string) ( $page['created_time'] ?? '' ),
			'status'     => is_string( $status ) ? $status : null,
			'band'       => isset( $bands[ $rawBand ] ) ? (string) $bands[ $rawBand ] : null,
			'release'    => $value( 'release' ),
			'area'       => $value( 'area' ),
			'owner'      => $value( 'owner' ),
			'labels'     => [],
			'alias'      => $id,
			'refs'       => IssuesSource::refs( $value( 'issue' ) . ' ' . $title ),
			'children'   => [],
			'written'    => array_filter( [ 'rank' => $value( 'rank' ), 'b_id' => $value( 'b_id' ), 'link' => $value( 'link' ) ] ),
			'snapshot'   => array_filter(
				[
					'status'           => $rawStatus,
					'band'             => $value( 'band' ),
					'release'          => $value( 'release' ),
					'area'             => $value( 'area' ),
					'owner'            => $value( 'owner' ),
					'last_edited_time' => (string) ( $page['last_edited_time'] ?? '' ),
				],
				static fn ( $v ): bool => $v !== ''
			),
		];
		if ( $rawStatus !== '' && $status === null ) {
			$record['unmapped_status'] = $rawStatus;
		}

		return $record;
	}

	/**
	 * The columns a sync would add (the manifest's adds_columns the database
	 * lacks).
	 *
	 * @param array<string, string> $columns
	 * @return array<int, string>
	 */
	public static function missingColumns( Manifest $manifest, array $columns ): array {
		$have = array_map( 'mb_strtolower', array_keys( $columns ) );

		return array_values( array_filter( (array) $manifest->get( 'adds_columns' ), static fn ( $c ): bool => is_string( $c ) && ! in_array( mb_strtolower( $c ), $have, true ) ) );
	}

	/**
	 * A property's value as plain text.
	 *
	 * @param array<string, mixed> $prop
	 */
	public static function text( array $prop ): string {
		$type = (string) ( $prop['type'] ?? '' );
		$v    = $prop[ $type ] ?? null;
		switch ( $type ) {
			case 'title':
			case 'rich_text':
				return trim( implode( '', array_map( static fn ( $t ): string => is_array( $t ) ? (string) ( $t['plain_text'] ?? ( $t['text']['content'] ?? '' ) ) : '', is_array( $v ) ? $v : [] ) ) );
			case 'select':
			case 'status':
				return is_array( $v ) ? trim( (string) ( $v['name'] ?? '' ) ) : '';
			case 'multi_select':
				return implode( ', ', array_filter( array_map( static fn ( $o ): string => is_array( $o ) ? (string) ( $o['name'] ?? '' ) : '', is_array( $v ) ? $v : [] ) ) );
			case 'people':
				return implode( ', ', array_filter( array_map( static fn ( $p ): string => is_array( $p ) ? (string) ( $p['name'] ?? '' ) : '', is_array( $v ) ? $v : [] ) ) );
			case 'url':
			case 'email':
			case 'phone_number':
				return is_string( $v ) ? trim( $v ) : '';
			case 'number':
				return is_numeric( $v ) ? (string) $v : '';
			case 'unique_id':
				return is_array( $v ) && isset( $v['number'] ) ? ( ! empty( $v['prefix'] ) ? $v['prefix'] . '-' : '' ) . (int) $v['number'] : '';
			case 'formula':
				return is_array( $v ) ? (string) ( $v[ $v['type'] ?? '' ] ?? '' ) : '';
			default:
				return '';
		}
	}

	/**
	 * Column name => type, from the database (or, without it, the rows).
	 *
	 * @param array<string, mixed>   $database
	 * @param array<int, mixed>      $pages
	 * @return array<string, string>
	 */
	private static function schemaOf( array $database, array $pages ): array {
		$out = [];
		foreach ( is_array( $database['properties'] ?? null ) ? $database['properties'] : [] as $name => $prop ) {
			$out[ (string) $name ] = is_array( $prop ) ? (string) ( $prop['type'] ?? '' ) : '';
		}
		if ( $out === [] ) {
			foreach ( $pages as $page ) {
				foreach ( is_array( $page['properties'] ?? null ) ? $page['properties'] : [] as $name => $prop ) {
					$out[ (string) $name ] = is_array( $prop ) ? (string) ( $prop['type'] ?? '' ) : '';
				}
			}
		}

		return $out;
	}

	/**
	 * One API request, at most three a second, waiting out a 429 once.
	 *
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>|WP_Error
	 */
	private function call( string $method, string $path, ?array $body ) {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$wait = 0.34 - ( microtime( true ) - $this->last );
			if ( $wait > 0 ) {
				usleep( (int) ( $wait * 1000000 ) );
			}
			$this->last = microtime( true );
			$response   = wp_remote_request(
				self::API . $path,
				[
					'method'  => $method,
					'timeout' => 20,
					'headers' => [
						'Authorization'  => 'Bearer ' . $this->token,
						'Notion-Version' => self::VERSION,
						'Content-Type'   => 'application/json',
					],
					'body'    => $body !== null ? (string) wp_json_encode( $body ) : null,
				]
			);
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'ab_backlog_source', 'Notion could not be reached: ' . $response->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code === 429 ) {
				sleep( max( 1, min( 30, (int) wp_remote_retrieve_header( $response, 'retry-after' ) ) ) );
				continue;
			}
			if ( $code !== 200 ) {
				return new WP_Error( 'ab_backlog_source', 'Notion answered HTTP ' . $code . ( in_array( $code, [ 401, 403, 404 ], true ) ? ' (check the token, and that the database is shared with the integration)' : '' ) . '.' );
			}
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			return is_array( $data ) ? $data : new WP_Error( 'ab_backlog_source', 'Notion answered something that is not JSON.' );
		}

		return new WP_Error( 'ab_backlog_source', 'Notion kept answering 429 (too many requests). Try again in a minute.' );
	}
}
