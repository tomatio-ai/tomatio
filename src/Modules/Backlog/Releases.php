<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use WP_Error;

/**
 * #986: a backlog's release plan, on Magic Backlog's Roadmap view.
 *
 *  - meta/project.releases: [{version, title?, date?, shipped_at?}], the
 *    releases planned (tomatio-project-release);
 *  - meta/project.running: what is live now. A version, "plugin" (follow
 *    this plugin's version: the TomaTío project's own backlog), or unset
 *    (nothing ships on its own); running_version is that resolved, for
 *    the page;
 *  - an item's release: verdicts/<item key>.release when that document has
 *    one (what a member chose on the board, or project-edit), else the
 *    item's own `release` (a source's milestone). A version, "next",
 *    "later" (Dennis, 14 Nov: we will do it, not scheduled yet) or ''
 *    (the Inbox: not decided yet).
 *
 * "next" is the lowest planned version above the running one. When the
 * running version reaches a release, roll() ships it: its items marked
 * done are set shipped, the others move to the following release (or back
 * to unplanned) with a log line saying why.
 */
final class Releases {

	public const NEXT    = 'next';
	public const LATER   = 'later';
	public const PLUGIN  = 'plugin';
	public const MAX     = 30;
	private const SYSTEM = 'TomaTío';

	/** The plugin versions this backlog rolled over at, kept in OPTION so roll() runs once per update. */
	public const OPTION = 'agent_brain_backlog_releases_at';

	public static function register(): void {
		add_action( 'init', [ self::class, 'maybeRoll' ], 31 );
	}

	/**
	 * A version as the plan keeps it ("v1.9.18" is "1.9.18"), "next",
	 * "later", or '' (the Inbox); null when $value is none of those.
	 *
	 * @param mixed $value
	 */
	public static function normalize( $value ): ?string {
		if ( $value === null ) {
			return '';
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( $value === '' || in_array( strtolower( $value ), [ self::NEXT, self::LATER ], true ) ) {
			return strtolower( $value );
		}
		if ( preg_match( '/^v?(\d+(?:\.\d+){0,3}(?:-[a-z0-9.]+)?)$/i', $value, $m ) === 1 ) {
			return strtolower( $m[1] );
		}

		return null;
	}

	/**
	 * The version live now, or '' when the backlog has none.
	 *
	 * @param array<string, mixed> $project meta/project.
	 */
	public static function running( array $project ): string {
		$running = is_string( $project['running'] ?? null ) ? $project['running'] : '';
		if ( $running === self::PLUGIN ) {
			return defined( 'AGENT_BRAIN_VERSION' ) ? (string) AGENT_BRAIN_VERSION : '';
		}

		return (string) self::normalize( $running );
	}

	/**
	 * The planned releases, as meta/project lists them.
	 *
	 * @param array<string, mixed> $project
	 * @return array<string, array<string, string>> version => entry
	 */
	public static function listed( array $project ): array {
		$out = [];
		foreach ( is_array( $project['releases'] ?? null ) ? $project['releases'] : [] as $entry ) {
			$version = is_array( $entry ) ? self::normalize( $entry['version'] ?? null ) : null;
			if ( $version !== null && $version !== '' && $version !== self::NEXT ) {
				$out[ $version ] = array_filter( array_map( 'strval', $entry ), static fn ( string $v ): bool => $v !== '' );
			}
		}

		return $out;
	}

	/**
	 * Every per-item release choice, by item key (verdicts documents that
	 * carry `release`).
	 *
	 * @return array<string, array<string, mixed>> key => verdicts document
	 */
	public static function choices( int $artifactId ): array {
		$out = [];
		foreach ( StoreData::list( $artifactId, 'verdicts' ) as $doc ) {
			if ( is_array( $doc['value'] ) && array_key_exists( 'release', $doc['value'] ) ) {
				$out[ (string) $doc['key'] ] = $doc;
			}
		}

		return $out;
	}

	/**
	 * An item's release as set: the board's choice, else the item's own.
	 *
	 * @param array<string, mixed>                $doc     An items document.
	 * @param array<string, array<string, mixed>> $choices choices()
	 */
	public static function setOf( array $doc, array $choices ): string {
		$choice = $choices[ (string) $doc['key'] ] ?? null;
		$raw    = $choice !== null ? $choice['value']['release'] : ( is_array( $doc['value'] ) ? ( $doc['value']['release'] ?? '' ) : '' );

		return (string) ( self::normalize( $raw ) ?? '' );
	}

	/**
	 * The plan: the running version, the releases still to come (listed or
	 * named by an item) in version order, and which is next.
	 *
	 * @param array<string, mixed>                $project
	 * @param array<int, array<string, mixed>>    $items   items documents
	 * @param array<string, array<string, mixed>> $choices
	 * @return array{running:string, next:string, upcoming:array<int, string>}
	 */
	public static function plan( array $project, array $items, array $choices ): array {
		$running  = self::running( $project );
		$versions = [];
		foreach ( self::listed( $project ) as $version => $entry ) {
			if ( empty( $entry['shipped_at'] ) ) {
				$versions[ $version ] = true;
			}
		}
		foreach ( $items as $doc ) {
			$set = self::setOf( $doc, $choices );
			if ( $set !== '' && $set !== self::NEXT && $set !== self::LATER ) {
				$versions[ $set ] = true;
			}
		}
		$upcoming = array_values(
			array_filter(
				array_map( 'strval', array_keys( $versions ) ),
				static fn ( string $v ): bool => $running === '' || version_compare( $v, $running, '>' )
			)
		);
		usort( $upcoming, 'version_compare' );

		return [ 'running' => $running, 'next' => $upcoming[0] ?? '', 'upcoming' => $upcoming ];
	}

	/**
	 * $set with "next" resolved against $plan ('' when nothing is planned).
	 *
	 * @param array{running: string, next: string, upcoming: array<int, string>} $plan
	 */
	public static function resolve( string $set, array $plan ): string {
		return $set === self::NEXT ? $plan['next'] : $set;
	}

	/**
	 * Put an item in a release (a version, "next", or '' for unplanned):
	 * verdicts/<key>.release, keeping the verdict, plus a log line.
	 *
	 * @param array<string, mixed>                                                                   $doc    An items document.
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null}                         $by     Store attribution.
	 * @param array<string, string>                                                                  $line   {by, for?, reason?}
	 * @return true|WP_Error
	 */
	public static function place( int $artifactId, array $doc, string $release, array $by, array $line ) {
		$key     = (string) $doc['key'];
		$current = StoreData::get( $artifactId, 'verdicts', $key );
		$value   = $current !== null && is_array( $current['value'] ) ? $current['value'] : [ 'status' => '' ];
		$before  = self::setOf( $doc, $current !== null && array_key_exists( 'release', $value ) ? [ $key => $current ] : [] );
		if ( $before === $release ) {
			return true;
		}
		$value['release'] = $release;
		$value['at']      = gmdate( 'Y-m-d\TH:i:s\Z' );
		$value['status']  = is_string( $value['status'] ?? null ) ? $value['status'] : '';
		$value['by']      = TioDay::text( $line['for'] ?? '', 80 ) !== '' ? (string) $line['for'] : (string) ( $line['by'] ?? '' );
		$stored           = StoreData::write( $artifactId, 'verdicts', $key, $value, $by, $current !== null ? 'replace' : 'create', null, $current !== null ? (int) $current['version'] : 0 );
		if ( $stored instanceof WP_Error ) {
			return $stored;
		}
		$on = is_array( $doc['value'] ) && is_string( $doc['value']['id'] ?? null ) ? $doc['value']['id'] : $key;
		Change::note( $artifactId, $on, array_filter( [ 'what' => 'release', 'from' => $before !== '' ? $before : 'unplanned', 'to' => $release !== '' ? $release : 'unplanned' ] + $line ), $by );

		return true;
	}

	// -----------------------------------------------------------------
	// The next patch (#1103)
	// -----------------------------------------------------------------

	/**
	 * The patch release after $running: 1.10.1 → 1.10.2, 1.10 → 1.10.1;
	 * '' for anything else (a pre-release, a four-part version, none).
	 */
	public static function nextPatch( string $running ): string {
		if ( preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $running, $m ) === 1 ) {
			return $m[1] . '.' . $m[2] . '.' . ( (int) $m[3] + 1 );
		}
		if ( preg_match( '/^(\d+)\.(\d+)$/', $running, $m ) === 1 ) {
			return $m[1] . '.' . $m[2] . '.1';
		}

		return '';
	}

	/**
	 * #1103 (Dennis, 20 Nov: open the next patch only when something goes
	 * in): an open item whose pull request merged ships in the next patch
	 * after the running version. Unless it is already headed for a release
	 * up to that patch, it moves there with a log line, and the patch is
	 * planned on the board when it is not yet. Runs on the hourly pass,
	 * after the pull requests are read (Progress::syncAll).
	 *
	 * @return array<int, string> The items moved (short IDs).
	 */
	public static function fillNextPatch( int $artifactId ): array {
		[ $project, $version ] = Project::project( $artifactId );
		$running               = self::running( $project );
		$patch                 = self::nextPatch( $running );
		if ( $patch === '' ) {
			return [];
		}
		$items   = StoreData::list( $artifactId, 'items' );
		$choices = self::choices( $artifactId );
		$plan    = self::plan( $project, $items, $choices );

		$merged = [];
		foreach ( $items as $doc ) {
			$value = is_array( $doc['value'] ) ? $doc['value'] : [];
			if ( in_array( (string) ( $value['status'] ?? 'inbox' ), [ 'idea', 'shipped', 'declined', 'parked', 'archived' ], true ) ) {
				continue;
			}
			$headed = self::resolve( self::setOf( $doc, $choices ), $plan );
			if ( $headed !== '' && $headed !== self::LATER && version_compare( $headed, $running, '>' ) && version_compare( $headed, $patch, '<=' ) ) {
				continue;
			}
			$pr = apply_filters( Progress::PR_FILTER, null, $artifactId, $doc );
			if ( is_array( $pr ) && ( $pr['state'] ?? '' ) === 'merged' ) {
				$merged[] = [ $doc, is_int( $pr['number'] ?? null ) ? (int) $pr['number'] : 0 ];
			}
		}
		if ( $merged === [] ) {
			return [];
		}

		$listed = self::listed( $project );
		if ( ! isset( $listed[ $patch ] ) ) {
			if ( count( $listed ) >= self::MAX ) {
				return [];
			}
			$listed[ $patch ] = Projects::stamp( [ 'version' => $patch ] );
			uksort( $listed, 'version_compare' );
			$project['releases']   = array_values( $listed );
			$project['updated_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
			$project['updated_by'] = self::SYSTEM;
			$stored                = StoreData::write( $artifactId, 'meta', 'project', $project, StoreData::anonymousAttribution(), 'replace', null, $version > 0 ? $version : 0 );
			if ( $stored instanceof WP_Error ) {
				return [];
			}
		}

		$by  = StoreData::anonymousAttribution();
		$out = [];
		foreach ( $merged as [ $doc, $number ] ) {
			/* translators: %s: the release. */
			$reason = sprintf( __( 'its pull request merged: it ships in %s', 'tomatio' ), $patch );
			if ( $number > 0 ) {
				/* translators: 1: pull request number, 2: the release. */
				$reason = sprintf( __( 'its pull request #%1$d merged: it ships in %2$s', 'tomatio' ), $number, $patch );
			}
			if ( self::place( $artifactId, $doc, $patch, $by, [ 'by' => self::SYSTEM, 'reason' => $reason ] ) === true ) {
				$out[] = is_array( $doc['value'] ) && is_string( $doc['value']['id'] ?? null ) ? $doc['value']['id'] : (string) $doc['key'];
			}
		}
		sort( $out, SORT_NATURAL );

		return $out;
	}

	// -----------------------------------------------------------------
	// Roll-over
	// -----------------------------------------------------------------

	/** On a plugin update: roll every backlog that follows the plugin version. */
	public static function maybeRoll(): void {
		$version = defined( 'AGENT_BRAIN_VERSION' ) ? (string) AGENT_BRAIN_VERSION : '';
		if ( $version === '' || get_option( self::OPTION ) === $version ) {
			return;
		}
		update_option( self::OPTION, $version, true );
		foreach ( Backlog::all() as $id ) {
			[ $project ] = Project::project( $id );
			if ( ( $project['running'] ?? '' ) === self::PLUGIN ) {
				self::roll( $id );
			}
		}
	}

	/**
	 * Ship every planned release the running version has reached: items in
	 * it that are shipped or marked done are set shipped; the rest move to
	 * the following release, or back to unplanned, each with a log line.
	 *
	 * @return array<string, array{shipped:array<int, string>, moved:array<int, string>, to:string}> version => what happened
	 */
	public static function roll( int $artifactId ): array {
		[ $project, $version ] = Project::project( $artifactId );
		$running               = self::running( $project );
		if ( $running === '' ) {
			if ( isset( $project['running_version'] ) ) {
				unset( $project['running_version'] );
				StoreData::write( $artifactId, 'meta', 'project', $project, StoreData::anonymousAttribution(), 'replace', null, $version > 0 ? $version : 0 );
			}

			return [];
		}
		$items   = StoreData::list( $artifactId, 'items' );
		$choices = self::choices( $artifactId );
		$listed  = self::listed( $project );

		$reached = [];
		foreach ( array_keys( $listed ) as $v ) {
			if ( empty( $listed[ $v ]['shipped_at'] ) && version_compare( (string) $v, $running, '<=' ) ) {
				$reached[ (string) $v ] = true;
			}
		}
		foreach ( $items as $doc ) {
			$set = self::setOf( $doc, $choices );
			if ( $set !== '' && $set !== self::NEXT && $set !== self::LATER && empty( $listed[ $set ]['shipped_at'] ) && version_compare( $set, $running, '<=' ) ) {
				$reached[ $set ] = true;
			}
		}
		if ( $reached === [] ) {
			if ( ( $project['running_version'] ?? '' ) !== $running ) {
				// The page reads the version live now from meta/project.
				$project['running_version'] = $running;
				StoreData::write( $artifactId, 'meta', 'project', $project, StoreData::anonymousAttribution(), 'replace', null, $version > 0 ? $version : 0 );
			}

			return [];
		}
		$reached = array_map( 'strval', array_keys( $reached ) );
		usort( $reached, 'version_compare' );

		$plan    = self::plan( $project, $items, $choices );
		$then    = $plan['next'];
		$by      = StoreData::anonymousAttribution();
		$now     = gmdate( 'Y-m-d\TH:i:s\Z' );
		$out     = [];
		$verdict = [];
		foreach ( StoreData::list( $artifactId, 'verdicts' ) as $doc ) {
			$verdict[ (string) $doc['key'] ] = is_array( $doc['value'] ) ? (string) ( $doc['value']['status'] ?? '' ) : '';
		}

		foreach ( $reached as $i => $release ) {
			$out[ $release ] = [ 'shipped' => [], 'moved' => [], 'to' => $then ];
			foreach ( $items as $doc ) {
				$set = self::setOf( $doc, $choices );
				// "next" items ride along: the done ones ship in the first
				// release reached (and are pinned to it), the rest stay
				// "next" and so move on by themselves.
				$riding = $set === self::NEXT && $i === 0;
				if ( $set !== $release && ! $riding ) {
					continue;
				}
				$v  = is_array( $doc['value'] ) ? $doc['value'] : [];
				$id = (string) ( $v['id'] ?? $doc['key'] );
				if ( in_array( $v['status'] ?? '', [ 'declined', 'archived' ], true ) ) {
					continue;
				}
				$done = ( $v['status'] ?? '' ) === 'shipped' || ( $verdict[ (string) $doc['key'] ] ?? '' ) === 'done';
				// #1107: work whose pull request merged went out with this
				// release, even when nobody marked it Done: it ships here.
				$merged = false;
				if ( ! $done ) {
					$pr     = apply_filters( Progress::PR_FILTER, null, $artifactId, $doc );
					$merged = is_array( $pr ) && ( $pr['state'] ?? '' ) === 'merged';
					$done   = $merged;
				}
				if ( $riding && ! $done ) {
					continue;
				}
				if ( $riding ) {
					self::place( $artifactId, $doc, $release, $by, [ 'by' => self::SYSTEM, 'reason' => 'released in ' . $release ] );
					$doc = (array) StoreData::get( $artifactId, 'items', (string) $doc['key'] );
					$v   = is_array( $doc['value'] ?? null ) ? $doc['value'] : $v;
				}
				if ( $done ) {
					if ( ( $v['status'] ?? '' ) !== 'shipped' ) {
						$before      = (string) ( $v['status'] ?? 'inbox' );
						$v['status'] = 'shipped';
						$v['rank']   = null;
						Change::write( $artifactId, (string) $doc['key'], $v, (int) $doc['version'], [ 'what' => 'status', 'by' => self::SYSTEM, 'from' => $before, 'to' => 'shipped', 'reason' => 'released in ' . $release . ( $merged ? ' (its pull request had merged)' : '' ) ], $by );
					}
					$out[ $release ]['shipped'][] = $id;
					continue;
				}
				self::place( $artifactId, $doc, $then, $by, [ 'by' => self::SYSTEM, 'reason' => $release . ' was released without it; ' . ( $then !== '' ? 'moved to ' . $then : 'back to unplanned' ) ] );
				$out[ $release ]['moved'][] = $id;
			}
			sort( $out[ $release ]['shipped'], SORT_NATURAL );
			sort( $out[ $release ]['moved'], SORT_NATURAL );
			$listed[ $release ] = [ 'version' => $release ] + ( $listed[ $release ] ?? [] ) + [ 'shipped_at' => $now ];
		}

		$project['releases']        = array_values( $listed );
		$project['running_version'] = $running;
		$project['updated_at']      = $now;
		$project['updated_by']      = self::SYSTEM;
		StoreData::write( $artifactId, 'meta', 'project', $project, $by, 'replace', null, $version > 0 ? $version : 0 );

		return $out;
	}
}
