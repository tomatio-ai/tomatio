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
	public const PER_PAGE  = 50;
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
	 * #1001: the open issues only (no pull requests read), as records, for
	 * the Inbox intake. At most $max issues, oldest first.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function open( Manifest $manifest, int $max = 2000 ) {
		if ( $this->json !== null ) {
			$issues = array_is_list( $this->json ) ? $this->json : ( is_array( $this->json['issues'] ?? null ) ? $this->json['issues'] : [] );
		} else {
			$issues = $this->pages( '/repos/' . $this->repo . '/issues', [ 'state' => 'open', 'type' => 'issues' ], max( 1, (int) ceil( $max / self::PER_PAGE ) ) );
			if ( $issues instanceof WP_Error ) {
				return $issues;
			}
		}
		$out = [];
		foreach ( $issues as $issue ) {
			if ( ! is_array( $issue ) || isset( $issue['pull_request'] ) || ( (string) ( $issue['state'] ?? 'open' ) ) !== 'open' ) {
				continue;
			}
			$record = $this->record( $issue, $manifest );
			if ( $record !== null ) {
				$out[ $record['id'] ] = $record;
			}
		}
		ksort( $out, SORT_NATURAL );

		return array_slice( array_values( $out ), 0, $max );
	}

	/**
	 * #1001, the Test button: read one page of open issues and say how many
	 * it found, or why it failed. Never writes, never shows the token.
	 *
	 * @return array{ok: bool, count: int, message: string}
	 */
	public function test(): array {
		$got = $this->call( '/repos/' . $this->repo . '/issues', [ 'state' => 'open', 'type' => 'issues', 'page' => '1', 'limit' => (string) self::PER_PAGE, 'per_page' => (string) self::PER_PAGE ] );
		if ( $got instanceof WP_Error ) {
			$data = $got->get_error_data();
			$code = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

			return [ 'ok' => false, 'count' => 0, 'message' => self::why( $code, $got->get_error_message(), $this->token !== '' ) ];
		}
		if ( ! array_is_list( $got ) ) {
			return [ 'ok' => false, 'count' => 0, 'message' => 'The tracker answered something that is not a list of issues. Check the API address.' ];
		}
		$count = count( array_filter( $got, static fn ( $i ): bool => is_array( $i ) && ! isset( $i['pull_request'] ) ) );
		$more  = count( $got ) >= self::PER_PAGE ? ' (the first page; there are more)' : '';

		return [ 'ok' => true, 'count' => $count, 'message' => sprintf( 'It works: found %d open issues in %s%s.', $count, $this->repo, $more ) ];
	}

	/** Why a read failed, in words a person can act on. */
	public static function why( int $code, string $fallback, bool $hasToken ): string {
		switch ( $code ) {
			case 401:
				return 'The tracker refused the token (HTTP 401): it is wrong, expired or revoked. Make a new one and paste it.';
			case 403:
				return 'The token lacks a permission (HTTP 403): it needs read on issues. Forgejo: read:issue, plus read:repository for a private repository. GitHub: a fine-grained token with Issues: Read on this repository.';
			case 404:
				return $hasToken
					? 'Repository not found (HTTP 404): check owner/name and the API address, and that the token can see this repository (Forgejo: read:repository for a private one; GitHub: the token must be given this repository).'
					: 'Repository not found (HTTP 404): check owner/name and the API address. A private repository needs a token.';
			default:
				return $fallback;
		}
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
	 * #1088: issue or pull request $number as the API gives it (an issue
	 * read of a pull request carries pull_request), open or closed. From the
	 * JSON given (its issues, closed and pulls lists) or the API. Null when
	 * there is none or it can't be read.
	 *
	 * @return array<string, mixed>|null
	 */
	public function raw( int $number ): ?array {
		if ( $this->json === null ) {
			$got = $this->call( '/repos/' . $this->repo . '/issues/' . $number, [] );

			return is_array( $got ) && ! array_is_list( $got ) && (int) ( $got['number'] ?? 0 ) === $number ? $got : null;
		}
		$lists = array_is_list( $this->json ) ? [ 'issues' => $this->json ] : $this->json;
		foreach ( [ 'issues', 'closed', 'pulls' ] as $list ) {
			foreach ( is_array( $lists[ $list ] ?? null ) ? $lists[ $list ] : [] as $candidate ) {
				if ( ! is_array( $candidate ) || (int) ( $candidate['number'] ?? 0 ) !== $number ) {
					continue;
				}
				if ( $list === 'pulls' && ! is_array( $candidate['pull_request'] ?? null ) ) {
					// The pulls list says merged on the pull request itself.
					$candidate['pull_request'] = [ 'merged' => ! empty( $candidate['merged'] ) || ! empty( $candidate['merged_at'] ), 'merged_at' => $candidate['merged_at'] ?? null ];
				}

				return $candidate;
			}
		}

		return null;
	}

	/**
	 * #1092: one page of the repository's pull requests, open or closed,
	 * the newest change first, as the API gives them. From the JSON given
	 * (its pulls list, on page 1) or the API: one read.
	 *
	 * @return array<int, mixed>|WP_Error
	 */
	public function pulls( string $state, int $page = 1 ) {
		$open = $state === 'open';
		if ( $this->json !== null ) {
			$pulls = ! array_is_list( $this->json ) && is_array( $this->json['pulls'] ?? null ) ? $this->json['pulls'] : [];

			return $page > 1 ? [] : array_values( array_filter( $pulls, static fn ( $p ): bool => is_array( $p ) && ( ( $p['state'] ?? '' ) === 'open' ) === $open ) );
		}
		$sort = $this->host === 'github' ? [ 'sort' => 'updated', 'direction' => 'desc' ] : [ 'sort' => 'recentupdate' ];
		$got  = $this->call( '/repos/' . $this->repo . '/pulls', [ 'state' => $open ? 'open' : 'closed' ] + $sort + [ 'page' => (string) max( 1, $page ), 'limit' => (string) self::PER_PAGE, 'per_page' => (string) self::PER_PAGE ] );
		if ( $got instanceof WP_Error ) {
			return $got;
		}

		return array_is_list( $got ) ? $got : new WP_Error( 'ab_backlog_source', 'The issues API answered something that is not a list.' );
	}

	/**
	 * B227: the repository's newest tags (a few), as the API gives them
	 * ({name, ...}). From the JSON given (its tags list) or the API: one read.
	 *
	 * @return array<int, mixed>|WP_Error
	 */
	public function tags() {
		return $this->few( 'tags', '/repos/' . $this->repo . '/tags' );
	}

	/**
	 * B227: the repository's newest releases ($n, at most 50), as the API
	 * gives them ({tag_name, published_at, draft, prerelease, ...}). One read.
	 *
	 * @return array<int, mixed>|WP_Error
	 */
	public function releases( int $n = 5 ) {
		return $this->few( 'releases', '/repos/' . $this->repo . '/releases', $n );
	}

	/**
	 * The first page ($n) of a list endpoint, or the JSON given's $list.
	 *
	 * @return array<int, mixed>|WP_Error
	 */
	private function few( string $list, string $path, int $n = 5 ) {
		if ( $this->json !== null ) {
			$got = ! array_is_list( $this->json ) && is_array( $this->json[ $list ] ?? null ) ? $this->json[ $list ] : [];

			return array_values( $got );
		}
		$n   = (string) min( 50, max( 1, $n ) );
		$got = $this->call( $path, [ 'page' => '1', 'limit' => $n, 'per_page' => $n ] );
		if ( $got instanceof WP_Error ) {
			return $got;
		}

		return array_is_list( $got ) ? $got : new WP_Error( 'ab_backlog_source', 'The issues API answered something that is not a list.' );
	}

	/** How many reads checks() makes on this host: GitHub keeps commit statuses and check runs apart. */
	public function checksCost(): int {
		return $this->host === 'github' ? 2 : 1;
	}

	/**
	 * #1092: where the checks on commit $sha stand: green, failing, running
	 * or '' (it has none). Null when they can't be read.
	 */
	public function checks( string $sha ): ?string {
		if ( $this->json !== null || preg_match( '/^[0-9a-f]{7,64}$/', $sha ) !== 1 ) {
			return null;
		}
		$status = $this->call( '/repos/' . $this->repo . '/commits/' . $sha . '/status', [] );
		if ( $status instanceof WP_Error ) {
			return null;
		}
		$runs = [];
		if ( $this->host === 'github' ) {
			$got = $this->call( '/repos/' . $this->repo . '/commits/' . $sha . '/check-runs', [ 'per_page' => '100' ] );
			if ( $got instanceof WP_Error ) {
				// A token without Checks: read still gives the commit statuses.
				$got = [];
			}
			$runs = is_array( $got['check_runs'] ?? null ) ? $got['check_runs'] : [];
		}

		return self::checksOf( is_array( $status['statuses'] ?? null ) ? $status['statuses'] : [], $runs );
	}

	/**
	 * Commit statuses (Forgejo says status, GitHub state) and GitHub check
	 * runs as one word: failing when any failed, else running while any is
	 * pending, else green when any passed, else ''. Skipped ones don't count.
	 *
	 * @param array<int, mixed> $statuses
	 * @param array<int, mixed> $runs
	 */
	public static function checksOf( array $statuses, array $runs = [] ): string {
		$seen = [];
		foreach ( $statuses as $status ) {
			$state = is_array( $status ) ? (string) ( $status['status'] ?? $status['state'] ?? '' ) : '';
			if ( in_array( $state, [ 'failure', 'error' ], true ) ) {
				$seen['failing'] = true;
			} elseif ( $state === 'pending' ) {
				$seen['running'] = true;
			} elseif ( $state === 'success' ) {
				$seen['green'] = true;
			}
		}
		foreach ( $runs as $run ) {
			if ( ! is_array( $run ) ) {
				continue;
			}
			$conclusion = (string) ( $run['conclusion'] ?? '' );
			if ( ( $run['status'] ?? '' ) !== 'completed' ) {
				$seen['running'] = true;
			} elseif ( in_array( $conclusion, [ 'failure', 'timed_out', 'cancelled', 'action_required', 'startup_failure' ], true ) ) {
				$seen['failing'] = true;
			} elseif ( $conclusion === 'success' ) {
				$seen['green'] = true;
			}
		}
		foreach ( [ 'failing', 'running', 'green' ] as $word ) {
			if ( isset( $seen[ $word ] ) ) {
				return $word;
			}
		}

		return '';
	}

	/**
	 * #1092: when commit $sha was made, as UTC ISO 8601, unless it is a
	 * merge (the base branch merged in, by a person or a sync bot, is not
	 * new work): then ''. Also '' when the tracker says there is no such
	 * commit (404 or 422, such as a head that lives in the author's fork) or
	 * answers without one, so it is not asked for again (#1095). Null when
	 * the tracker did not answer. One read.
	 */
	public function committed( string $sha ): ?string {
		if ( $this->json !== null || preg_match( '/^[0-9a-f]{7,64}$/', $sha ) !== 1 ) {
			return null;
		}
		$got = $this->call( '/repos/' . $this->repo . ( $this->host === 'github' ? '/commits/' : '/git/commits/' ) . $sha, [] );
		if ( $got instanceof WP_Error ) {
			$data = $got->get_error_data();

			return is_array( $data ) && in_array( (int) ( $data['status'] ?? 0 ), [ 404, 422 ], true ) ? '' : null;
		}
		if ( ! is_array( $got['commit'] ?? null ) ) {
			return '';
		}
		if ( is_array( $got['parents'] ?? null ) && count( $got['parents'] ) > 1 ) {
			return '';
		}
		$date = $got['commit']['committer']['date'] ?? $got['commit']['author']['date'] ?? '';
		$time = is_string( $date ) && $date !== '' ? strtotime( $date ) : false;

		return $time !== false ? gmdate( 'Y-m-d\TH:i:s\Z', $time ) : '';
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
	private function pages( string $path, array $query, int $maxPage = self::MAX_PAGE ) {
		$all = [];
		for ( $page = 1; $page <= min( $maxPage, self::MAX_PAGE ); ++$page ) {
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
			return new WP_Error( 'ab_backlog_source', 'The issues API answered HTTP ' . $code . ( in_array( $code, [ 401, 403, 404 ], true ) ? ' (check the repository and its read token in Settings > Extensions, Issue tracker)' : '' ) . '.', [ 'status' => $code ] );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : new WP_Error( 'ab_backlog_source', 'The issues API answered something that is not JSON.' );
	}

	/** @param mixed $url */
	private static function https( $url ): string {
		return is_string( $url ) && str_starts_with( $url, 'https://' ) ? $url : '';
	}
}
