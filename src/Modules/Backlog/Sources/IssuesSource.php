<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog\Sources;

use WP_Error;

/**
 * #892 (sections 652 and 665): the GitHub and Forgejo issues source,
 * read-only. It reads the open issues of one repository (and the pull
 * requests, to find "Closes #N"), and a closed issue only when a Notion
 * row or a TíoDay task points at it.
 *
 * Two ways in: the API, with the token kept in Settings > Backlog, or the
 * same JSON the API returns, fetched elsewhere and passed in (the first
 * read from a Mac, design 652: `--issues-json=<file>|-`, an object
 * {issues: [...], pulls: [...]} or a plain list of issues). Either way it
 * never writes to the repository.
 */
final class IssuesSource {

	public const SOURCE    = 'issues';
	private const PER_PAGE = 50;
	private const MAX_PAGE = 40;

	/** @var array<int, array<string, mixed>> closed issues fetched on demand */
	private array $extra = [];

	/**
	 * @param array<mixed>|null $json Issues and pulls fetched elsewhere, or null to call the API.
	 */
	public function __construct( private string $key, private string $host, private string $api, private string $repo, private string $token, private ?array $json = null ) {
	}

	/**
	 * From Settings > Backlog, or from fetched JSON (then the repo may be given on the command line).
	 *
	 * @param array<mixed>|null $json
	 */
	public static function configured( ?array $json = null, string $repo = '', string $host = '' ): self|string {
		$config = Config::all()['issues'];
		$repo   = $repo !== '' ? Config::repo( $repo ) : $config['repo'];
		$host   = $host !== '' ? ( $host === 'github' ? 'github' : 'forgejo' ) : $config['host'];
		if ( $repo === '' ) {
			return 'The issues source has no repository: set one in Settings > Backlog, or pass --repo=owner/name.';
		}
		if ( $json === null && $config['api'] === '' ) {
			return 'The issues source has no API address: set it in Settings > Backlog, or pass --issues-json.';
		}

		return new self( $host . ':' . $repo, $host, $config['api'], $repo, $json === null ? Config::token( self::SOURCE ) : '', $json );
	}

	public function key(): string {
		return $this->key;
	}

	/**
	 * Every open issue as a record, oldest first.
	 *
	 * @return array{records: array<int, array<string, mixed>>, notes: array<int, string>, closes: array<string, array<int, array<string, mixed>>>}|WP_Error
	 */
	public function read( Manifest $manifest ) {
		if ( $this->json !== null ) {
			$issues = array_is_list( $this->json ) ? $this->json : ( is_array( $this->json['issues'] ?? null ) ? $this->json['issues'] : [] );
			$pulls  = array_is_list( $this->json ) ? [] : ( is_array( $this->json['pulls'] ?? null ) ? $this->json['pulls'] : [] );
			$notes  = [ 'Read ' . count( $issues ) . ' issues and ' . count( $pulls ) . ' pull requests from the JSON given.' ];
		} else {
			$issues = $this->pages( '/repos/' . $this->repo . '/issues', [ 'state' => 'open', 'type' => 'issues' ] );
			if ( $issues instanceof WP_Error ) {
				return $issues;
			}
			$pulls = $this->pages( '/repos/' . $this->repo . '/pulls', [ 'state' => 'all' ] );
			$notes = [];
			if ( $pulls instanceof WP_Error ) {
				$notes[] = 'Pull requests could not be read (' . $pulls->get_error_message() . '): no "Closes #N" links this time.';
				$pulls   = [];
			}
			$notes[] = 'Read ' . count( $issues ) . ' open issues and ' . count( $pulls ) . ' pull requests from ' . $this->api . '.';
		}

		$closes = self::closes( $pulls );
		$out    = [];
		foreach ( $issues as $issue ) {
			if ( ! is_array( $issue ) || ! empty( $issue['pull_request'] ) ) {
				continue;
			}
			$record = $this->record( $issue, $manifest, $closes );
			if ( $record !== null ) {
				$out[ $record['id'] ] = $record;
			}
		}
		ksort( $out, SORT_NATURAL );

		return [ 'records' => array_values( $out ), 'notes' => $notes, 'closes' => $closes ];
	}

	/**
	 * One issue by number, open or closed, for a row or task that points at
	 * it. From the JSON given (when it has it) or the API.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $closes
	 * @return array<string, mixed>|null
	 */
	public function one( int $number, Manifest $manifest, array $closes = [] ): ?array {
		if ( isset( $this->extra[ $number ] ) ) {
			return $this->extra[ $number ];
		}
		$issue = null;
		if ( $this->json !== null ) {
			foreach ( array_merge( array_is_list( $this->json ) ? $this->json : (array) ( $this->json['issues'] ?? [] ), array_is_list( $this->json ) ? [] : (array) ( $this->json['closed'] ?? [] ) ) as $candidate ) {
				if ( is_array( $candidate ) && (int) ( $candidate['number'] ?? 0 ) === $number ) {
					$issue = $candidate;
					break;
				}
			}
		} else {
			$got   = $this->call( '/repos/' . $this->repo . '/issues/' . $number, [] );
			$issue = is_array( $got ) && ! array_is_list( $got ) ? $got : null;
		}
		$record = is_array( $issue ) ? $this->record( $issue, $manifest, $closes ) : null;
		if ( $record !== null ) {
			$this->extra[ $number ] = $record;
		}

		return $record;
	}

	/**
	 * An issue as a source record.
	 *
	 * @param array<string, mixed>                            $issue
	 * @param array<string, array<int, array<string, mixed>>> $closes issue number => pull requests
	 * @return array<string, mixed>|null
	 */
	public function record( array $issue, Manifest $manifest, array $closes = [] ): ?array {
		$number = (int) ( $issue['number'] ?? 0 );
		if ( $number <= 0 ) {
			return null;
		}
		$labels = [];
		foreach ( is_array( $issue['labels'] ?? null ) ? $issue['labels'] : [] as $label ) {
			$name = is_array( $label ) ? (string) ( $label['name'] ?? '' ) : ( is_string( $label ) ? $label : '' );
			if ( $name !== '' ) {
				$labels[] = $name;
			}
		}
		$assignees = [];
		foreach ( is_array( $issue['assignees'] ?? null ) ? $issue['assignees'] : [] as $person ) {
			$login = is_array( $person ) ? (string) ( $person['login'] ?? $person['username'] ?? '' ) : '';
			if ( $login !== '' ) {
				$assignees[] = $login;
			}
		}
		$milestone = is_array( $issue['milestone'] ?? null ) ? (string) ( $issue['milestone']['title'] ?? '' ) : '';
		$title     = trim( (string) ( $issue['title'] ?? '' ) );
		$body      = (string) ( $issue['body'] ?? '' );

		$kinds = (array) ( $manifest->get( 'new_items' )['kinds_by_label'] ?? [] );
		$kind  = (string) ( $manifest->get( 'new_items' )['kind'] ?? 'feature' );
		foreach ( $labels as $label ) {
			if ( isset( $kinds[ strtolower( $label ) ] ) ) {
				$kind = (string) $kinds[ strtolower( $label ) ];
				break;
			}
		}
		if ( preg_match( '/^\s*\[?epic\]?\s*[:\-]/i', $title ) === 1 ) {
			$kind = 'epic';
		}

		$prs = array_map(
			static fn ( array $p ): array => array_filter( [ 'number' => $p['number'], 'url' => $p['url'], 'state' => $p['state'] ] ),
			$closes[ (string) $number ] ?? []
		);

		return [
			'source'     => self::SOURCE,
			'key'        => $this->key,
			'id'         => (string) $number,
			'url'        => self::https( $issue['html_url'] ?? '' ),
			'title'      => $title !== '' ? $title : '#' . $number,
			'detail'     => mb_substr( trim( $body ), 0, 2000 ),
			'kind'       => $kind,
			'updated_at' => (string) ( $issue['updated_at'] ?? '' ),
			'created_at' => (string) ( $issue['created_at'] ?? '' ),
			'status'     => null,
			'band'       => null,
			'release'    => $milestone,
			'labels'     => $labels,
			'alias'      => '#' . $number,
			'refs'       => [],
			'children'   => $kind === 'epic' ? self::taskList( $body ) : [],
			'snapshot'   => array_filter(
				[
					'state'     => (string) ( $issue['state'] ?? '' ),
					'labels'    => $labels,
					'milestone' => $milestone,
					'assignees' => $assignees,
					'prs'       => $prs,
				],
				static fn ( $v ): bool => $v !== '' && $v !== []
			),
		];
	}

	/**
	 * Issue numbers a text names: "#807", "Closes #807", ".../issues/807".
	 *
	 * @return array<int, int>
	 */
	public static function refs( string $text ): array {
		preg_match_all( '~(?:/issues/|(?<![\w/&])#)(\d{1,7})\b~', $text, $m );

		return array_values( array_unique( array_map( 'intval', $m[1] ) ) );
	}

	/**
	 * The issues an epic's task list names ("- [ ] #812").
	 *
	 * @return array<int, int>
	 */
	public static function taskList( string $body ): array {
		preg_match_all( '~^\s*[-*]\s*\[[ xX]\]\s*.*?(?:/issues/|#)(\d{1,7})\b~m', $body, $m );

		return array_values( array_unique( array_map( 'intval', $m[1] ) ) );
	}

	/**
	 * Which pull requests close which issue ("Closes #N", "Fixes #N", "Resolves #N").
	 *
	 * @param array<int, mixed> $pulls
	 * @return array<string, array<int, array{number:int, url:string, state:string}>>
	 */
	public static function closes( array $pulls ): array {
		$out = [];
		foreach ( $pulls as $pull ) {
			if ( ! is_array( $pull ) ) {
				continue;
			}
			preg_match_all( '~\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s*:?\s+(?:[\w.-]+/[\w.-]+)?#(\d{1,7})\b~i', (string) ( $pull['title'] ?? '' ) . "\n" . (string) ( $pull['body'] ?? '' ), $m );
			$state = ! empty( $pull['merged'] ) || ! empty( $pull['merged_at'] ) ? 'merged' : (string) ( $pull['state'] ?? '' );
			foreach ( array_unique( $m[1] ) as $n ) {
				$out[ (string) (int) $n ][] = [ 'number' => (int) ( $pull['number'] ?? 0 ), 'url' => self::https( $pull['html_url'] ?? '' ), 'state' => $state ];
			}
		}

		return $out;
	}

	/**
	 * Every page of a list endpoint.
	 *
	 * @param array<string, string> $query
	 * @return array<int, mixed>|WP_Error
	 */
	private function pages( string $path, array $query ) {
		$all = [];
		for ( $page = 1; $page <= self::MAX_PAGE; ++$page ) {
			$got = $this->call( $path, $query + [ 'page' => (string) $page, 'limit' => (string) self::PER_PAGE, 'per_page' => (string) self::PER_PAGE ] );
			if ( $got instanceof WP_Error ) {
				return $got;
			}
			if ( ! array_is_list( $got ) ) {
				return new WP_Error( 'ab_backlog_source', 'The issues API answered something that is not a list.' );
			}
			$all = array_merge( $all, $got );
			if ( count( $got ) < self::PER_PAGE ) {
				break;
			}
		}

		return $all;
	}

	/**
	 * @param array<string, string> $query
	 * @return array<mixed>|WP_Error
	 */
	private function call( string $path, array $query ) {
		$headers = [ 'Accept' => 'application/json' ];
		if ( $this->token !== '' ) {
			$headers['Authorization'] = ( $this->host === 'github' ? 'Bearer ' : 'token ' ) . $this->token;
		}
		$response = wp_remote_get( $this->api . $path . ( $query !== [] ? '?' . http_build_query( $query ) : '' ), [ 'headers' => $headers, 'timeout' => 20 ] );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ab_backlog_source', 'The issues API could not be reached: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return new WP_Error( 'ab_backlog_source', 'The issues API answered HTTP ' . $code . ( in_array( $code, [ 401, 403, 404 ], true ) ? ' (check the repository and its read token in Settings > Backlog)' : '' ) . '.' );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : new WP_Error( 'ab_backlog_source', 'The issues API answered something that is not JSON.' );
	}

	/** @param mixed $url */
	private static function https( $url ): string {
		return is_string( $url ) && str_starts_with( $url, 'https://' ) ? $url : '';
	}
}
