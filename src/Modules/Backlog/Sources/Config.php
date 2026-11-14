<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog\Sources;

use AgentBrain\Core\SecretBox;
use AgentBrain\Core\SecretBoxException;

/**
 * #892: where each source reads from, kept by the brain (Settings >
 * Backlog), never by an agent. Tokens are SecretBox envelopes, never
 * plaintext, and are never shown again or printed.
 *
 *   issues: {host: forgejo|github, api: https://…/api/v1, repo: owner/name, token}
 *   notion: {database: <id>, token}
 */
final class Config {

	public const OPTION  = 'ab_backlog_sources';
	public const PURPOSE = 'backlog-source';

	/** @return array{issues: array{host:string, api:string, repo:string, token:string}, notion: array{database:string, token:string}} */
	public static function all(): array {
		$raw    = get_option( self::OPTION, [] );
		$raw    = is_array( $raw ) ? $raw : [];
		$issues = is_array( $raw['issues'] ?? null ) ? $raw['issues'] : [];
		$notion = is_array( $raw['notion'] ?? null ) ? $raw['notion'] : [];

		return [
			'issues' => [
				'host'  => ( $issues['host'] ?? '' ) === 'github' ? 'github' : 'forgejo',
				'api'   => is_string( $issues['api'] ?? null ) ? $issues['api'] : '',
				'repo'  => is_string( $issues['repo'] ?? null ) ? $issues['repo'] : '',
				'token' => is_string( $issues['token'] ?? null ) ? $issues['token'] : '',
			],
			'notion' => [
				'database' => is_string( $notion['database'] ?? null ) ? $notion['database'] : '',
				'token'    => is_string( $notion['token'] ?? null ) ? $notion['token'] : '',
			],
		];
	}

	/**
	 * Save the issues source. An empty $token keeps the stored one; $clear
	 * removes it.
	 */
	public static function saveIssues( string $host, string $api, string $repo, string $token, bool $clear = false ): bool {
		$api  = self::api( $api, $host );
		$repo = self::repo( $repo );
		if ( $repo === '' || $api === '' ) {
			return false;
		}
		$all              = self::all();
		$all['issues']    = [
			'host'  => $host === 'github' ? 'github' : 'forgejo',
			'api'   => $api,
			'repo'  => $repo,
			'token' => $clear ? '' : ( $token !== '' ? self::seal( $token ) : $all['issues']['token'] ),
		];

		return self::store( $all );
	}

	/** Save the Notion source. An empty $token keeps the stored one. */
	public static function saveNotion( string $database, string $token, bool $clear = false ): bool {
		$database = self::database( $database );
		if ( $database === '' ) {
			return false;
		}
		$all           = self::all();
		$all['notion'] = [
			'database' => $database,
			'token'    => $clear ? '' : ( $token !== '' ? self::seal( $token ) : $all['notion']['token'] ),
		];

		return self::store( $all );
	}

	public static function remove( string $source ): void {
		$all = self::all();
		if ( $source === 'issues' ) {
			$all['issues'] = [ 'host' => 'forgejo', 'api' => '', 'repo' => '', 'token' => '' ];
		} elseif ( $source === 'notion' ) {
			$all['notion'] = [ 'database' => '', 'token' => '' ];
		}
		self::store( $all );
	}

	/** The plaintext of a stored token, '' when empty or unreadable. */
	public static function token( string $source ): string {
		$envelope = (string) ( self::all()[ $source ]['token'] ?? '' );
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

	/** The integration key items carry ("forgejo:team/project", "notion:backlog"). */
	public static function key( string $source ): string {
		$all = self::all();
		if ( $source === 'issues' ) {
			return $all['issues']['repo'] !== '' ? $all['issues']['host'] . ':' . $all['issues']['repo'] : '';
		}

		return $all['notion']['database'] !== '' ? 'notion:backlog' : '';
	}

	/** "owner/name", or '' for anything else. */
	public static function repo( string $repo ): string {
		$repo = trim( $repo, " \t\n\r/" );

		return preg_match( '#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#', $repo ) === 1 ? $repo : '';
	}

	/** An https API base without a trailing slash; GitHub's by default for host github. */
	public static function api( string $api, string $host ): string {
		$api = rtrim( trim( $api ), '/' );
		if ( $api === '' && $host === 'github' ) {
			return 'https://api.github.com';
		}

		return preg_match( '#^https://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#', $api ) === 1 ? $api : '';
	}

	/** A Notion database ID (32 hex digits, with or without hyphens, or the link that ends in it). */
	public static function database( string $database ): string {
		if ( preg_match( '/([0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12})/i', $database, $m ) !== 1 ) {
			return '';
		}

		return strtolower( str_replace( '-', '', $m[1] ) );
	}

	private static function seal( string $token ): string {
		return SecretBox::seal( trim( $token ), SecretBox::currentMaterial(), self::PURPOSE );
	}

	/** @param array<string, mixed> $all */
	private static function store( array $all ): bool {
		update_option( self::OPTION, $all, false );

		return true;
	}
}
