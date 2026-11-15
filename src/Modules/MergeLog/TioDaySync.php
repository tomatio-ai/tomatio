<?php

declare(strict_types=1);

namespace AgentBrain\Modules\MergeLog;

use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\Artifacts\ArtifactIndex;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\Sharing\ShareLinks;
use WP_Error;

/**
 * #885: a merge the Merge log records also lands on the repository's
 * TíoDay: by default (automatic) the one its agent leads, else the one
 * chosen in its settings, or none when that is turned off. One `done` item, its
 * `inflight` item cleared, and the pull request on the release card's next
 * version as merged. It writes through TioDay's own helpers, as the
 * repository's agent, which must be able to keep that TíoDay (the rule the
 * tioday tools use: its lead, a curator or a manager).
 *
 * Nothing is recorded twice: a done item that already links the pull
 * request (a session wrote it first) stands, the new one carries the Merge
 * log memory's id so StoreMemory does not mirror it into a second memory,
 * and a release item with the same address is updated, not added.
 */
final class TioDaySync {

	public const BY_SUFFIX = 'via Merge log';

	/** A pull request between two of these long-lived branches is a promotion, not new work. */
	private const LONG_LIVED = '/^(main|master|develop|dev-[a-z0-9-]+|release\/.+)$/';

	/** Whether $agentId may keep TíoDay $artifactId: it is a live TíoDay, and the agent may manage it. */
	public static function allowed( int $agentId, int $artifactId ): bool {
		return $agentId > 0
			&& $artifactId > 0
			&& ! TioDay::resolve( $artifactId ) instanceof WP_Error
			&& ShareLinks::canManage( $agentId, $artifactId );
	}

	/**
	 * The TíoDay a repository records on now (0: none): the one chosen, or,
	 * when automatic, the one its agent leads (TioDay::resolve's rule for an
	 * agent with nobody behind it; none when it leads none, or several and
	 * none is its issuer's).
	 *
	 * @param array<string, mixed> $repo
	 */
	public static function target(array $repo): int {
		$chosen = Repos::tioday($repo);
		if ($chosen !== null) {
			return $chosen;
		}

		return self::ledBy((int) ($repo['agent_id'] ?? 0));
	}

	/** The TíoDay $agentId leads, as the tioday tools find it for that agent; 0 for none. */
	public static function ledBy(int $agentId): int {
		if ($agentId <= 0) {
			return 0;
		}
		$previous = get_current_user_id();
		wp_set_current_user($agentId);
		try {
			$found = TioDay::person() === 0 ? TioDay::resolve() : null;
		} finally {
			wp_set_current_user($previous);
		}

		return is_array($found) ? (int) $found['id'] : 0;
	}

	/**
	 * The TíoDays a repository can record on, as artifact id => label.
	 *
	 * @return array<int, string>
	 */
	public static function choices(): array {
		$posts = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'post_status'    => [ 'publish', 'private', 'draft', 'pending' ],
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_key'       => HomeWorkspace::META_OWNER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a handful of pinned pages.
				'meta_compare'   => 'EXISTS',
			]
		);
		$out = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post || ArtifactIndex::isArchived( (int) $post->ID ) ) {
				continue;
			}
			$owner = get_userdata( (int) get_post_meta( (int) $post->ID, HomeWorkspace::META_OWNER, true ) );
			$out[ (int) $post->ID ] = sprintf(
				/* translators: 1: the TíoDay's title, 2: its person, 3: artifact id. */
				__( '%1$s (%2$s, artifact %3$d)', 'tomatio' ),
				(string) get_post_field( 'post_title', (int) $post->ID ),
				$owner instanceof \WP_User ? $owner->display_name : '?',
				(int) $post->ID
			);
		}

		return $out;
	}

	/**
	 * Record one merged pull request on $artifactId, as $agentId.
	 *
	 * @param array<string, mixed> $data      The webhook payload.
	 * @param string[]             $fragments The changelog.d/ files it adds.
	 * @return array<string, mixed> What happened, for the webhook's reply.
	 */
	public static function record( int $artifactId, int $agentId, array $data, int $memoryId, array $fragments, string $token ): array {
		$pr = is_array( $data['pull_request'] ?? null ) ? $data['pull_request'] : [];
		if ( preg_match( self::LONG_LIVED, (string) ( $pr['head']['ref'] ?? '' ) ) === 1 ) {
			return [ 'result' => 'promotion' ];
		}
		if ( ! self::allowed( $agentId, $artifactId ) ) {
			return [ 'result' => 'agent_cannot_keep_tioday' ];
		}

		$prUrl = esc_url_raw( (string) ( $pr['html_url'] ?? '' ), [ 'https' ] );
		$prRef = self::ref( $prUrl );
		if ( $prRef === null ) {
			return [ 'result' => 'no_address' ];
		}
		$number = (int) ( $pr['number'] ?? 0 );
		$issues = self::closes( $pr );
		$refs   = [ $prRef ];
		$links  = [ [ 'label' => '#' . $number, 'url' => $prUrl ] ];
		foreach ( $issues as $issue ) {
			$url = self::issueUrl( $prUrl, $issue );
			$ref = self::ref( $url );
			if ( $ref !== null && ! in_array( $ref, $refs, true ) && count( $links ) < TioDay::MAX_LINKS ) {
				$refs[]  = $ref;
				$links[] = [ 'label' => '#' . $issue, 'url' => $url ];
			}
		}
		$title = trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $pr['title'] ?? '' ) ) );

		$previous = get_current_user_id();
		wp_set_current_user( $agentId );
		try {
			$out = [ 'result' => 'recorded', 'artifact_id' => $artifactId ];

			// Done, unless a done item already links this pull request.
			$existing = self::linking( $artifactId, 'done', [ $prRef ] );
			if ( $existing !== [] ) {
				$out['done'] = [ 'key' => $existing[0], 'existing' => true ];
			} else {
				$author = (string) ( $pr['user']['login'] ?? $pr['user']['username'] ?? '' );
				$done   = TioDay::done(
					$artifactId,
					[
						'date'   => self::day( (string) ( $pr['merged_at'] ?? '' ) ),
						'stream' => (string) ( $data['repository']['name'] ?? '' ) !== '' ? (string) $data['repository']['name'] : (string) ( $data['repository']['full_name'] ?? '' ),
						'title'  => $title !== '' ? $title : '#' . $number,
						'detail' => self::summary( (string) ( $data['repository']['url'] ?? '' ), $pr, $fragments, $token ),
						'links'  => $links,
						'by'     => ( $author !== '' ? $author . ' ' : '' ) . '(' . self::BY_SUFFIX . ')',
					],
					$memoryId
				);
				if ( $done instanceof WP_Error ) {
					return [ 'result' => 'error', 'error' => $done->get_error_code() ];
				}
				$out['done'] = [ 'key' => $done['key'], 'id' => $done['id'] ];
			}

			// In flight: every item that links the pull request or an issue it closes.
			$cleared = [];
			foreach ( self::linking( $artifactId, 'inflight', $refs ) as $key ) {
				$gone = TioDay::inflight( $artifactId, [ 'slug' => $key, 'clear' => true ] );
				if ( is_array( $gone ) && ! empty( $gone['cleared'] ) ) {
					$cleared[] = $key;
				}
			}
			$out['cleared'] = $cleared;

			// The release card: the pull request, merged, on the next version.
			$version = TioDay::nextRelease( $artifactId );
			if ( $version !== null ) {
				$release = TioDay::release(
					$artifactId,
					[
						'version' => $version,
						'item'    => [
							'title' => $title !== '' ? $title : '#' . $number,
							'url'   => $prUrl,
							'label' => implode( ', ', array_column( $links, 'label' ) ),
							'state' => 'merged',
						],
					]
				);
				$out['release'] = $release instanceof WP_Error ? [ 'error' => $release->get_error_code() ] : [ 'version' => $version ];
			}

			return $out;
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * The issue numbers a pull request closes: "Closes #12", "fixes #3",
	 * "Resolved: #7" in its body or title.
	 *
	 * @param array<string, mixed> $pr
	 * @return int[]
	 */
	public static function closes( array $pr ): array {
		$text = (string) ( $pr['title'] ?? '' ) . "\n" . (string) ( $pr['body'] ?? '' );
		$out  = [];
		if ( preg_match_all( '/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s*:?\s+#(\d+)\b/i', $text, $m ) > 0 ) {
			foreach ( $m[1] as $n ) {
				$n = (int) $n;
				if ( $n > 0 && $n !== (int) ( $pr['number'] ?? 0 ) && ! in_array( $n, $out, true ) ) {
					$out[] = $n;
				}
			}
		}

		return $out;
	}

	/**
	 * What an address points at, as "host/owner/name#number", when it is an
	 * issue or pull request ("/issues/12", "/pulls/12", "/pull/12"): an
	 * issue and a pull request share their numbers, so either spelling
	 * matches. Null for any other address.
	 */
	public static function ref( string $url ): ?string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( $host === '' || preg_match( '#^/(.+?)/(?:issues|pulls|pull)/(\d+)/?$#i', $path, $m ) !== 1 ) {
			return null;
		}

		return $host . '/' . strtolower( $m[1] ) . '#' . (int) $m[2];
	}

	/** The address of issue $number in the pull request's repository. */
	private static function issueUrl( string $prUrl, int $number ): string {
		return (string) preg_replace( '#/(?:pulls|pull)/\d+/?$#', '/issues/' . $number, $prUrl );
	}

	/**
	 * Keys of the documents in $collection with a link to any of $refs.
	 *
	 * @param string[] $refs
	 * @return string[]
	 */
	private static function linking( int $artifactId, string $collection, array $refs ): array {
		$out = [];
		foreach ( StoreData::list( $artifactId, $collection ) as $doc ) {
			$links = is_array( $doc['value'] ) && is_array( $doc['value']['links'] ?? null ) ? $doc['value']['links'] : [];
			foreach ( $links as $link ) {
				$ref = is_array( $link ) && is_string( $link['url'] ?? null ) ? self::ref( $link['url'] ) : null;
				if ( $ref !== null && in_array( $ref, $refs, true ) ) {
					$out[] = (string) $doc['key'];
					break;
				}
			}
		}

		return $out;
	}

	/** The day it merged, in the brain's time zone; today when unknown. */
	private static function day( string $mergedAt ): string {
		$at = $mergedAt !== '' ? strtotime( $mergedAt ) : false;

		return $at !== false ? (string) wp_date( 'Y-m-d', $at ) : TioDay::today();
	}

	/**
	 * The first line of the pull request's first changelog fragment: its
	 * `readme:` line, else its first bullet. '' when it can't be read.
	 *
	 * @param array<string, mixed> $pr
	 * @param string[]             $fragments
	 */
	private static function summary( string $apiUrl, array $pr, array $fragments, string $token ): string {
		$sha = (string) ( $pr['merge_commit_sha'] ?? '' ) !== '' ? (string) $pr['merge_commit_sha'] : (string) ( $pr['head']['sha'] ?? '' );
		if ( $fragments === [] || ! str_starts_with( $apiUrl, 'https://' ) || preg_match( '/^[0-9a-f]{7,64}$/i', $sha ) !== 1 ) {
			return '';
		}
		$path    = implode( '/', array_map( 'rawurlencode', explode( '/', $fragments[0] ) ) );
		$headers = [ 'Accept' => 'application/json' ];
		if ( $token !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$response = wp_safe_remote_get( rtrim( $apiUrl, '/' ) . '/contents/' . $path . '?ref=' . rawurlencode( $sha ), [ 'timeout' => 10, 'headers' => $headers ] );
		$file     = is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		$text     = is_array( $file ) && is_string( $file['content'] ?? null ) ? base64_decode( (string) preg_replace( '/\s+/', '', $file['content'] ), true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the contents API sends files base64-encoded.

		return is_string( $text ) ? self::firstLine( $text ) : '';
	}

	/** A fragment's one-line summary: its `readme:` line, else its first bullet, else its first line. */
	public static function firstLine( string $fragment ): string {
		$lines  = preg_split( '/\R/', $fragment ) ?: [];
		$bullet = '';
		$first  = '';
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^readme:\s*(.+)$/i', $line, $m ) === 1 && trim( $m[1] ) !== '' ) {
				return trim( $m[1] );
			}
			if ( $bullet === '' && preg_match( '/^[-*]\s+(.+)$/', $line, $m ) === 1 ) {
				$bullet = trim( $m[1] );
			}
			if ( $first === '' && $line !== '' && preg_match( '/^(section:|#)/i', $line ) !== 1 ) {
				$first = $line;
			}
		}

		return $bullet !== '' ? $bullet : $first;
	}
}
