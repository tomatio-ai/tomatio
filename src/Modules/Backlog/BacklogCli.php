<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\Backlog\Sources\Report;
use AgentBrain\Modules\Backlog\Sources\Runner;

/**
 * WP-CLI commands for the team backlog (`wp tomatio backlog ...`, also
 * `wp agent-brain backlog ...`), #891 and #892.
 */
final class BacklogCli {

	/**
	 * Makes a backlog: the bundled page in a scope, led by an agent.
	 *
	 * ## OPTIONS
	 *
	 * --scope=<id>
	 * : The scope the backlog lives in. Who can read the scope can read the backlog.
	 *
	 * --lead=<agent>
	 * : The lead agent: its ID or login. It must be able to write to the scope.
	 *
	 * [--title=<title>]
	 * : The project's name. Default "Backlog".
	 *
	 * [--slug=<slug>]
	 * : Its members address, /a/<slug>. Default backlog, when free.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tomatio backlog create --scope=5 --lead=dennis-ai --title="TomaTío"
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function create( array $args, array $assocArgs ): void {
		unset( $args );
		$lead = self::user( (string) ( $assocArgs['lead'] ?? '' ) );
		if ( $lead === 0 ) {
			\WP_CLI::error( 'No such lead agent.' );
		}
		$id = Backlog::make( (string) ( $assocArgs['title'] ?? '' ), $lead, (int) ( $assocArgs['scope'] ?? 0 ), (string) ( $assocArgs['slug'] ?? 'backlog' ) );
		if ( $id instanceof \WP_Error ) {
			\WP_CLI::error( $id->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Made backlog %d: %s', $id, \AgentBrain\Modules\DataStore\TioDay::url( $id ) ) );
	}

	/**
	 * Lists the backlogs on this brain.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function list( array $args, array $assocArgs ): void {
		unset( $args, $assocArgs );
		$rows = [];
		foreach ( Backlog::all() as $id ) {
			[ $project ] = Project::project( $id );
			$rows[]      = [
				'id'    => $id,
				'name'  => (string) ( $project['name'] ?? get_the_title( $id ) ),
				'scope' => Backlog::scopeOf( $id ),
				'items' => count( \AgentBrain\Modules\DataStore\StoreData::list( $id, 'items' ) ),
				'url'   => \AgentBrain\Modules\DataStore\TioDay::url( $id ),
			];
		}
		if ( $rows === [] ) {
			\WP_CLI::line( 'No backlog yet: wp tomatio backlog create --scope=<id> --lead=<agent>.' );
		}
		foreach ( $rows as $row ) {
			\WP_CLI::line( sprintf( '%d  %s  (scope %d, %d items)  %s', $row['id'], $row['name'], $row['scope'], $row['items'], $row['url'] ) );
		}
	}

	/**
	 * Reads the team's tools into the backlog: the first read, then any later
	 * one. Without --yes it is a dry run: it reads the sources, writes
	 * nothing anywhere and prints the report. With --yes=<fingerprint> it
	 * writes exactly the plan that report showed, and refuses when anything
	 * changed since. It never writes to the tools themselves.
	 *
	 * ## OPTIONS
	 *
	 * [--backlog=<id>]
	 * : The backlog. Default: the only one on this brain. A dry run with no backlog yet plans into an empty one.
	 *
	 * [--dry-run]
	 * : Only report (the default without --yes).
	 *
	 * [--yes=<fingerprint>]
	 * : Write the plan whose report carried this fingerprint.
	 *
	 * [--source=<list>]
	 * : Which sources, comma-separated: issues, notion, tioday. Default all three.
	 *
	 * [--issues-json=<file>]
	 * : The issues as the API returns them, fetched elsewhere: a file, or - for standard input. An object {issues: [...], pulls: [...], closed: [...]} or a list of issues. Without it the issues are read from the API set in Settings > Backlog.
	 *
	 * [--notion-json=<file>]
	 * : The Notion rows as the API returns them ({database: {...}, results: [...]}), instead of reading Notion.
	 *
	 * [--repo=<repo>]
	 * : The repository as owner/name, when it isn't set in Settings > Backlog (with --issues-json). WP-CLI allows no slash in the placeholder.
	 *
	 * [--host=<host>]
	 * : forgejo or github, with --repo.
	 *
	 * [--tioday=<id>]
	 * : The TíoDay whose open feature tasks are linked to their items.
	 *
	 * [--as=<agent>]
	 * : For the real run: the account the changes are made as (ID or login). It must be able to write the backlog.
	 *
	 * [--for=<person>]
	 * : For the real run: the person it acts for, as the log names them.
	 *
	 * [--format=<format>]
	 * : md (default) or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tomatio backlog read --tioday=390 --issues-json=issues.json
	 *     wp tomatio backlog read --tioday=390 --issues-json=issues.json --yes=3f2a9c1d0b7e --as=dennis-ai --for=dennis
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function read( array $args, array $assocArgs ): void {
		unset( $args );
		$backlog = (int) ( $assocArgs['backlog'] ?? 0 );
		if ( $backlog === 0 ) {
			$all = Backlog::all();
			if ( count( $all ) > 1 ) {
				\WP_CLI::error( 'There are several backlogs (' . implode( ', ', $all ) . '): pass --backlog=<id>.' );
			}
			$backlog = $all[0] ?? 0;
		} elseif ( ! Backlog::isBacklog( $backlog ) ) {
			\WP_CLI::error( 'Artifact ' . $backlog . ' is not a backlog.' );
		}

		$options = [
			'sources' => isset( $assocArgs['source'] ) ? array_values( array_intersect( array_map( 'trim', explode( ',', (string) $assocArgs['source'] ) ), [ 'issues', 'notion', 'tioday' ] ) ) : [ 'issues', 'notion', 'tioday' ],
			'repo'    => (string) ( $assocArgs['repo'] ?? '' ),
			'host'    => (string) ( $assocArgs['host'] ?? '' ),
			'tioday'  => (int) ( $assocArgs['tioday'] ?? 0 ),
		];
		foreach ( [ 'issues-json' => 'issues_json', 'notion-json' => 'notion_json' ] as $flag => $option ) {
			if ( isset( $assocArgs[ $flag ] ) ) {
				$options[ $option ] = self::json( (string) $assocArgs[ $flag ] );
			}
		}

		$yes = (string) ( $assocArgs['yes'] ?? '' );
		if ( $yes === '' || isset( $assocArgs['dry-run'] ) ) {
			$plan = Runner::plan( $backlog, $options );
			if ( $plan instanceof \WP_Error ) {
				\WP_CLI::error( $plan->get_error_message() );
			}
			if ( ( $assocArgs['format'] ?? 'md' ) === 'json' ) {
				\WP_CLI::line( (string) wp_json_encode( Report::json( $plan ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			} else {
				\WP_CLI::line( Report::markdown( $plan ) );
			}

			return;
		}

		if ( $backlog === 0 ) {
			\WP_CLI::error( 'There is no backlog to read into: make one with wp tomatio backlog create.' );
		}
		$as = self::user( (string) ( $assocArgs['as'] ?? '' ) );
		if ( $as === 0 ) {
			\WP_CLI::error( 'The real run needs --as=<agent>: the account the changes are made as.' );
		}
		$done = Runner::apply( $backlog, $options, $yes, $as, sanitize_user( (string) ( $assocArgs['for'] ?? '' ) ) );
		if ( $done instanceof \WP_Error ) {
			\WP_CLI::error( $done->get_error_message() );
		}
		foreach ( $done['failed'] as $failure ) {
			\WP_CLI::warning( $failure );
		}
		\WP_CLI::success( sprintf( 'Read plan %s: %d new items, %d updated, %d unchanged, %d TíoDay tasks linked.', $done['fingerprint'], $done['created'], $done['updated'], $done['unchanged'], $done['tioday_linked'] ) );
	}

	/**
	 * Lets a page read the backlog's store through a link (#891, linked
	 * stores): the page names its collections "project--items",
	 * "project--log" and so on. Read only, for named viewers who can see the
	 * backlog. Used by a TíoDay to show its team's backlog.
	 *
	 * ## OPTIONS
	 *
	 * --page=<id>
	 * : The page that reads, such as a TíoDay.
	 *
	 * [--backlog=<id>]
	 * : The backlog. Default: the only one on this brain.
	 *
	 * [--alias=<alias>]
	 * : The name the page uses. Default project.
	 *
	 * [--remove]
	 * : Remove the link instead.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function link( array $args, array $assocArgs ): void {
		unset( $args );
		$page    = (int) ( $assocArgs['page'] ?? 0 );
		$backlog = (int) ( $assocArgs['backlog'] ?? ( Backlog::all()[0] ?? 0 ) );
		$alias   = (string) ( $assocArgs['alias'] ?? 'project' );
		if ( $page <= 0 || get_post( $page ) === null ) {
			\WP_CLI::error( 'No such page.' );
		}
		if ( ! isset( $assocArgs['remove'] ) && ! Backlog::isBacklog( $backlog ) ) {
			\WP_CLI::error( 'No backlog to link to: pass --backlog=<id>.' );
		}
		if ( ! \AgentBrain\Modules\DataStore\StoreLinks::set( $page, $alias, isset( $assocArgs['remove'] ) ? 0 : $backlog ) ) {
			\WP_CLI::error( 'The alias is lower-case letters and digits, up to 16, and a page cannot link to itself.' );
		}
		\WP_CLI::success( isset( $assocArgs['remove'] ) ? sprintf( 'Page %d no longer reads %s--*.', $page, $alias ) : sprintf( 'Page %d reads backlog %d as %s--items, %s--comments, %s--log.', $page, $backlog, $alias, $alias, $alias ) );
	}

	/** A user by ID or login, 0 when there is none. */
	private static function user( string $who ): int {
		if ( $who === '' ) {
			return 0;
		}
		$user = ctype_digit( $who ) ? get_userdata( (int) $who ) : get_user_by( 'login', $who );

		return $user instanceof \WP_User ? (int) $user->ID : 0;
	}

	/** @return array<mixed> */
	private static function json( string $path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file or standard input named on the command line.
		$raw  = $path === '-' ? stream_get_contents( STDIN ) : ( is_readable( $path ) ? file_get_contents( $path ) : false );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) ) {
			\WP_CLI::error( 'Not readable JSON: ' . $path );
		}

		return $data;
	}
}
