<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\Backlog\Sources\IssuesSource;
use AgentBrain\Modules\DataStore\StoreData;
use WP_Error;

/**
 * B227: how a backlog delivers its work, set per backlog (per project, not
 * per person: Dennis, 22 Nov).
 *
 *  - releases (today's behaviour): items go into versions, and an item
 *    ships when its version is cut (Releases::roll);
 *  - continuous: there are no versions. The Roadmap shows Now, Next and
 *    Later, Shipped is grouped by week, and an item ships when its pull
 *    request merges (into `branch` when one is set), or when someone marks
 *    it Done. Releases::plan() is empty, so the next patch, the roll-over,
 *    "ready to cut" and the TíoDay release list all stand down.
 *
 * meta/project.delivery holds {mode, deployed, branch, set_by, set_at,
 * suggested: {mode, why, at}, deployed_at}. With `deployed` on ("shipped
 * means deployed", off by default) a merge or a Done mark waits for the
 * next deploy: the repository's newest release, read on the hourly pass,
 * or a deploy someone records (tomatio-project-release, deployed: true).
 * Without a linked repository there is nothing to deploy from, so Done
 * ships at once.
 *
 * Until a person sets the mode the board keeps releases, so no board
 * changes by itself. Tomagic suggests a mode (suggest()) when the board
 * starts and then once a day until someone chooses, and again on
 * request: releases when the board ever planned one or follows a running
 * version, or the linked repository tags its releases; continuous when no
 * repository is linked (a real-estate or agency brain) or it has no release
 * tags. It says why in one line, and Magic Backlog shows it for a person to
 * confirm.
 *
 * Switching never loses items: to continuous, past releases stay in Shipped
 * and every release choice is kept (switch back and it is there); to
 * releases, open items in Now or being built go to the next release and the
 * rest stay in No release (the Roadmap's Inbox).
 */
final class Delivery {

	public const RELEASES   = 'releases';
	public const CONTINUOUS = 'continuous';
	public const MODES      = [ self::RELEASES, self::CONTINUOUS ];

	/** Who the ship lines are by. */
	private const SYSTEM = 'TomaTío';

	/** Statuses that never ship. */
	private const CLOSED = [ 'idea', 'shipped', 'declined', 'parked', 'archived' ];

	/** @var array<int, bool> Boards whose Done marks changed during this request. */
	private static array $dirty = [];

	public static function register(): void {
		add_action( 'tomatio/store_written', [ self::class, 'written' ], 29, 5 );
		add_action( 'shutdown', [ self::class, 'flush' ], 29 );
	}

	// -----------------------------------------------------------------
	// Reading the mode
	// -----------------------------------------------------------------

	/**
	 * meta/project.delivery, cleaned.
	 *
	 * @param array<string, mixed> $project
	 * @return array{mode: string, deployed: bool, branch: string, set_by: string, set_at: string, suggested: array{mode: string, why: string, at: string}|null, deployed_at: string}
	 */
	public static function settings( array $project ): array {
		$raw = is_array( $project['delivery'] ?? null ) ? $project['delivery'] : [];
		$sug = is_array( $raw['suggested'] ?? null ) && in_array( $raw['suggested']['mode'] ?? null, self::MODES, true ) ? $raw['suggested'] : null;

		return [
			'mode'        => in_array( $raw['mode'] ?? null, self::MODES, true ) ? (string) $raw['mode'] : '',
			'deployed'    => ! empty( $raw['deployed'] ),
			'branch'      => is_string( $raw['branch'] ?? null ) ? $raw['branch'] : '',
			'set_by'      => is_string( $raw['set_by'] ?? null ) ? $raw['set_by'] : '',
			'set_at'      => is_string( $raw['set_at'] ?? null ) ? $raw['set_at'] : '',
			'suggested'   => $sug === null ? null : [
				'mode' => (string) $sug['mode'],
				'why'  => is_string( $sug['why'] ?? null ) ? $sug['why'] : '',
				'at'   => is_string( $sug['at'] ?? null ) ? $sug['at'] : '',
			],
			'deployed_at' => is_string( $raw['deployed_at'] ?? null ) ? $raw['deployed_at'] : '',
		];
	}

	/**
	 * The mode the board runs in: the one a person set, else releases
	 * (today's behaviour, so no board changes until someone chooses).
	 *
	 * @param array<string, mixed> $project
	 */
	public static function mode( array $project ): string {
		$set = self::settings( $project )['mode'];

		return $set !== '' ? $set : self::RELEASES;
	}

	/** @param array<string, mixed> $project */
	public static function continuous( array $project ): bool {
		return self::mode( $project ) === self::CONTINUOUS;
	}

	/** Whether a repository is linked (the issue tracker, Settings > Extensions). */
	public static function linked(): bool {
		return Sources\Config::all()['issues']['repo'] !== '';
	}

	// -----------------------------------------------------------------
	// The detector (Tomagic)
	// -----------------------------------------------------------------

	/**
	 * What Tomagic suggests, with a one-line why. Reads the linked
	 * repository's tags once when $readRepo; a tracker that does not answer
	 * counts as no tags.
	 *
	 * @param array<string, mixed> $project
	 * @return array{mode: string, why: string}
	 */
	public static function suggest( array $project, bool $readRepo = true ): array {
		$listed = Releases::listed( $project );
		if ( $listed !== [] ) {
			$n = count( $listed );

			return [
				'mode' => self::RELEASES,
				/* translators: %d: how many releases. */
				'why'  => sprintf( _n( '%d release is planned on this board, so it ships in versions.', '%d releases are planned on this board, so it ships in versions.', $n, 'tomatio' ), $n ),
			];
		}
		if ( is_string( $project['running'] ?? null ) && $project['running'] !== '' ) {
			return [ 'mode' => self::RELEASES, 'why' => __( 'The board follows a running version, so it ships in versions.', 'tomatio' ) ];
		}
		if ( ! self::linked() ) {
			return [ 'mode' => self::CONTINUOUS, 'why' => __( 'No repository is linked, so an item ships when someone marks it Done.', 'tomatio' ) ];
		}
		$tag = $readRepo ? self::latestTag() : null;
		if ( $tag !== null && $tag !== '' ) {
			return [
				'mode' => self::RELEASES,
				/* translators: %s: a tag such as v1.2.0. */
				'why'  => sprintf( __( 'The linked repository tags its releases (latest %s), so it ships in versions.', 'tomatio' ), $tag ),
			];
		}
		if ( $tag === '' ) {
			return [ 'mode' => self::CONTINUOUS, 'why' => __( 'The linked repository has no release tags, so work ships when its pull request merges.', 'tomatio' ) ];
		}

		return [ 'mode' => self::RELEASES, 'why' => __( 'A repository is linked but its tags could not be read; releases until someone chooses.', 'tomatio' ) ];
	}

	/**
	 * The linked repository's newest version tag (such as v1.2.0): '' when
	 * it has none, null when it can't be read. One read.
	 */
	public static function latestTag(): ?string {
		$source = IssuesSource::configured();
		if ( is_string( $source ) ) {
			return null;
		}
		$tags = $source->tags();
		if ( $tags instanceof WP_Error ) {
			return null;
		}
		foreach ( $tags as $tag ) {
			$name = is_array( $tag ) && is_string( $tag['name'] ?? null ) ? $tag['name'] : '';
			if ( Releases::normalize( $name ) !== null && preg_match( '/\d/', $name ) === 1 ) {
				return substr( $name, 0, 60 );
			}
		}

		return '';
	}

	/**
	 * Store a fresh suggestion on the board (meta/project.delivery.suggested).
	 *
	 * @return array{mode: string, why: string}
	 */
	public static function refreshSuggestion( int $artifactId ): array {
		[ $project, $version ] = Project::project( $artifactId );
		$suggestion            = self::suggest( $project );
		$delivery              = is_array( $project['delivery'] ?? null ) ? $project['delivery'] : [];
		$delivery['suggested'] = $suggestion + [ 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ];
		$project['delivery']   = $delivery;
		StoreData::write( $artifactId, 'meta', 'project', $project, StoreData::anonymousAttribution(), 'replace', null, $version > 0 ? $version : 0 );

		return $suggestion;
	}

	// -----------------------------------------------------------------
	// Setting it
	// -----------------------------------------------------------------

	/**
	 * Change the delivery settings ({mode?, deployed?, branch?}) and move
	 * items as the switch needs. A person's decision: the caller checks.
	 *
	 * @param array<string, mixed>                                           $input
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null} $store Store attribution.
	 * @return array{changed: array<int, string>, moved: array<int, string>, shipped: array<int, string>}|WP_Error
	 */
	public static function set( int $artifactId, array $input, string $by, string $for, array $store ) {
		[ $project, $version ] = Project::project( $artifactId );
		$before                = self::mode( $project );
		$delivery              = is_array( $project['delivery'] ?? null ) ? $project['delivery'] : [];
		$changed               = [];
		$now                   = gmdate( 'Y-m-d\TH:i:s\Z' );

		if ( array_key_exists( 'mode', $input ) ) {
			$mode = is_string( $input['mode'] ) ? strtolower( trim( $input['mode'] ) ) : '';
			if ( ! in_array( $mode, self::MODES, true ) ) {
				return new WP_Error( 'ab_invalid_input', __( 'mode is "releases" or "continuous".', 'tomatio' ), [ 'status' => 400 ] );
			}
			$delivery['mode']   = $mode;
			$delivery['set_by'] = $for !== '' ? $for : $by;
			$delivery['set_at'] = $now;
			$changed[]          = 'delivery ' . $mode;
		}
		if ( array_key_exists( 'deployed', $input ) ) {
			$delivery['deployed'] = ! empty( $input['deployed'] );
			$changed[]            = 'shipped means ' . ( $delivery['deployed'] ? 'deployed' : 'merged' );
		}
		if ( array_key_exists( 'branch', $input ) ) {
			$branch = is_string( $input['branch'] ) ? trim( $input['branch'] ) : '';
			if ( $branch !== '' && preg_match( '#^[A-Za-z0-9._/-]{1,100}$#', $branch ) !== 1 ) {
				return new WP_Error( 'ab_invalid_input', __( 'branch is a branch name such as main or dev, or "" for any.', 'tomatio' ), [ 'status' => 400 ] );
			}
			$delivery['branch'] = $branch;
			$changed[]          = 'branch ' . ( $branch !== '' ? $branch : 'any' );
		}
		if ( $changed === [] ) {
			return [ 'changed' => [], 'moved' => [], 'shipped' => [] ];
		}

		$project['delivery']   = $delivery;
		$project['updated_at'] = $now;
		$project['updated_by'] = $by;
		$stored                = StoreData::write( $artifactId, 'meta', 'project', $project, $store, 'replace', null, $version > 0 ? $version : 0 );
		if ( $stored instanceof WP_Error ) {
			return $stored;
		}

		$moved   = [];
		$shipped = [];
		$after   = self::mode( $project );
		if ( $before === self::CONTINUOUS && $after === self::RELEASES ) {
			$moved = self::toReleases( $artifactId, $project, $store, $by, $for );
		} elseif ( $after === self::CONTINUOUS ) {
			// Work already finished (merged, or marked Done in a release not cut yet) ships now.
			$shipped = self::ship( $artifactId );
		}

		return [ 'changed' => $changed, 'moved' => $moved, 'shipped' => $shipped ];
	}

	/**
	 * Switching to releases: open items in Now, or being built, with no
	 * release go to the next release; the rest stay where they are (No
	 * release, the Roadmap's Inbox). Nothing is lost either way.
	 *
	 * @param array<string, mixed>                                           $project
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null} $store
	 * @return array<int, string> The items moved (short IDs).
	 */
	private static function toReleases( int $artifactId, array $project, array $store, string $by, string $for ): array {
		$choices = Releases::choices( $artifactId );
		$out     = [];
		foreach ( StoreData::list( $artifactId, 'items' ) as $doc ) {
			$value  = is_array( $doc['value'] ) ? $doc['value'] : [];
			$status = (string) ( $value['status'] ?? 'inbox' );
			if ( in_array( $status, self::CLOSED, true ) || Releases::setOf( $doc, $choices ) !== '' ) {
				continue;
			}
			$band = Rank::band( is_string( $value['rank'] ?? null ) ? $value['rank'] : null, $project );
			if ( $band !== 'now' && $status !== 'building' ) {
				continue;
			}
			$line = array_filter( [ 'by' => $by, 'for' => $for, 'reason' => 'the board ships in releases now' ] );
			if ( Releases::place( $artifactId, $doc, Releases::NEXT, $store, $line ) === true ) {
				$out[] = is_string( $value['id'] ?? null ) ? $value['id'] : (string) $doc['key'];
			}
		}
		sort( $out, SORT_NATURAL );

		return $out;
	}

	/**
	 * A deploy happened at $at (now when ''): with "shipped means deployed"
	 * on, the work merged or marked Done before it ships.
	 *
	 * @return array<int, string> The items shipped (short IDs).
	 */
	public static function deployed( int $artifactId, string $at = '' ): array {
		[ $project, $version ] = Project::project( $artifactId );
		$at                    = $at !== '' ? $at : gmdate( 'Y-m-d\TH:i:s\Z' );
		$delivery              = is_array( $project['delivery'] ?? null ) ? $project['delivery'] : [];
		if ( strcmp( $at, (string) ( $delivery['deployed_at'] ?? '' ) ) > 0 ) {
			$delivery['deployed_at'] = $at;
			$project['delivery']     = $delivery;
			StoreData::write( $artifactId, 'meta', 'project', $project, StoreData::anonymousAttribution(), 'replace', null, $version > 0 ? $version : 0 );
		}

		return self::ship( $artifactId );
	}

	// -----------------------------------------------------------------
	// Shipping (continuous)
	// -----------------------------------------------------------------

	/**
	 * tomatio/store_written: a Done mark on a continuous board ships at the
	 * end of the request.
	 *
	 * @param mixed                $value
	 * @param array<string, mixed> $by
	 */
	public static function written( int $artifactId, string $collection, string $key, $value, array $by ): void {
		unset( $key, $by );
		if ( $collection === 'verdicts' && is_array( $value ) && ( $value['status'] ?? '' ) === 'done' && Backlog::isBacklog( $artifactId ) ) {
			self::$dirty[ $artifactId ] = true;
		}
	}

	public static function flush(): void {
		$ids         = array_keys( self::$dirty );
		self::$dirty = [];
		foreach ( $ids as $artifactId ) {
			self::ship( (int) $artifactId );
		}
	}

	/**
	 * The hourly pass for a board, after the pull requests are read: a
	 * fresh suggestion (once a day) while nobody has chosen, the
	 * repository's newest release as a deploy when "shipped means deployed"
	 * is on, then ship().
	 *
	 * @return array<int, string> The items shipped.
	 */
	public static function hourly( int $artifactId ): array {
		[ $project ] = Project::project( $artifactId );
		$settings    = self::settings( $project );
		$asked       = $settings['suggested'] !== null ? strtotime( $settings['suggested']['at'] ) : false;
		if ( $settings['mode'] === '' && ( $asked === false || time() - $asked >= DAY_IN_SECONDS ) ) {
			// Until someone chooses: once when the board starts, then once a day.
			self::refreshSuggestion( $artifactId );
		}
		if ( ! self::continuous( $project ) ) {
			return [];
		}
		if ( $settings['deployed'] && self::linked() ) {
			$at = self::latestRelease();
			if ( $at !== '' && strcmp( $at, $settings['deployed_at'] ) > 0 ) {
				return self::deployed( $artifactId, $at );
			}
		}

		return self::ship( $artifactId );
	}

	/** When the linked repository last published a release (UTC ISO 8601), or ''. */
	public static function latestRelease(): string {
		$source = IssuesSource::configured();
		if ( is_string( $source ) ) {
			return '';
		}
		$got    = $source->releases();
		$newest = '';
		foreach ( $got instanceof WP_Error ? [] : $got as $release ) {
			if ( ! is_array( $release ) || ! empty( $release['draft'] ) ) {
				continue;
			}
			$time = strtotime( (string) ( $release['published_at'] ?? $release['created_at'] ?? '' ) );
			if ( $time !== false ) {
				$at = gmdate( 'Y-m-d\TH:i:s\Z', $time );
				if ( strcmp( $at, $newest ) > 0 ) {
					$newest = $at;
				}
			}
		}

		return $newest;
	}

	/**
	 * Ship what is finished on a continuous board: an open item whose pull
	 * request merged (into the branch, when one is set) or that someone
	 * marked Done. With "shipped means deployed" on and a repository linked,
	 * only what merged or was marked Done before the last deploy.
	 *
	 * @return array<int, string> The items shipped (short IDs).
	 */
	public static function ship( int $artifactId ): array {
		[ $project ] = Project::project( $artifactId );
		if ( ! self::continuous( $project ) ) {
			return [];
		}
		$settings = self::settings( $project );
		$wait     = $settings['deployed'] && self::linked();
		$verdicts = [];
		foreach ( StoreData::list( $artifactId, 'verdicts' ) as $doc ) {
			if ( is_array( $doc['value'] ) && ( $doc['value']['status'] ?? '' ) === 'done' ) {
				$verdicts[ (string) $doc['key'] ] = is_string( $doc['value']['at'] ?? null ) ? $doc['value']['at'] : '';
			}
		}

		$by  = StoreData::anonymousAttribution();
		$out = [];
		foreach ( StoreData::list( $artifactId, 'items' ) as $doc ) {
			$value = is_array( $doc['value'] ) ? $doc['value'] : [];
			if ( in_array( (string) ( $value['status'] ?? 'inbox' ), self::CLOSED, true ) ) {
				continue;
			}
			$reason = self::why( $artifactId, $doc, $verdicts, $settings, $wait );
			if ( $reason === '' ) {
				continue;
			}
			$before          = (string) ( $value['status'] ?? 'inbox' );
			$value['status'] = 'shipped';
			$value['rank']   = null;
			$done            = Change::write( $artifactId, (string) $doc['key'], $value, (int) $doc['version'], [ 'what' => 'status', 'by' => self::SYSTEM, 'from' => $before, 'to' => 'shipped', 'reason' => $reason ], $by );
			if ( ! $done instanceof WP_Error ) {
				$out[] = is_string( $value['id'] ?? null ) ? $value['id'] : (string) $doc['key'];
			}
		}
		sort( $out, SORT_NATURAL );

		return $out;
	}

	/**
	 * Why an open item ships now on a continuous board, or '' when it does
	 * not yet.
	 *
	 * @param array<string, mixed>                                                                                              $doc
	 * @param array<string, string>                                                                                             $verdicts Done marks: key => when.
	 * @param array{mode: string, deployed: bool, branch: string, set_by: string, set_at: string, suggested: mixed, deployed_at: string} $settings
	 */
	private static function why( int $artifactId, array $doc, array $verdicts, array $settings, bool $wait ): string {
		$key    = (string) $doc['key'];
		$pr     = apply_filters( Progress::PR_FILTER, null, $artifactId, $doc );
		$merged = is_array( $pr ) && ( $pr['state'] ?? '' ) === 'merged';
		if ( $merged && $settings['branch'] !== '' && is_string( $pr['base'] ?? null ) && $pr['base'] !== '' && $pr['base'] !== $settings['branch'] ) {
			// Merged somewhere else, not into the main development branch.
			$merged = false;
		}
		$number = $merged && is_int( $pr['number'] ?? null ) ? (int) $pr['number'] : 0;
		$into   = $settings['branch'] !== '' ? ' into ' . $settings['branch'] : '';
		$done   = array_key_exists( $key, $verdicts );

		if ( ! $wait ) {
			if ( $merged ) {
				return $number > 0 ? 'its pull request #' . $number . ' merged' . $into : 'its pull request merged' . $into;
			}

			return $done ? 'marked Done' : '';
		}

		$deploy = $settings['deployed_at'];
		if ( $deploy === '' ) {
			return '';
		}
		if ( $merged ) {
			$at = is_string( $pr['merged_at'] ?? null ) ? (string) $pr['merged_at'] : '';
			$t  = $at !== '' ? strtotime( $at ) : false;
			if ( $t === false || gmdate( 'Y-m-d\TH:i:s\Z', $t ) <= $deploy ) {
				return ( $number > 0 ? 'its pull request #' . $number . ' merged' : 'its pull request merged' ) . ' and was deployed ' . substr( $deploy, 0, 10 );
			}

			return '';
		}
		if ( $done && $verdicts[ $key ] !== '' && strcmp( $verdicts[ $key ], $deploy ) <= 0 ) {
			return 'marked Done and deployed ' . substr( $deploy, 0, 10 );
		}

		return '';
	}

	/**
	 * What project-get says about delivery.
	 *
	 * @param array<string, mixed> $project
	 * @return array<string, mixed>
	 */
	public static function out( array $project ): array {
		$settings = self::settings( $project );

		return array_filter(
			[
				'mode'                   => self::mode( $project ),
				'chosen'                 => $settings['mode'] !== '',
				'shipped_means_deployed' => $settings['deployed'],
				'branch'                 => $settings['branch'],
				'set_by'                 => $settings['set_by'],
				'set_at'                 => $settings['set_at'],
				'suggested'              => $settings['suggested'],
				'deployed_at'            => $settings['deployed_at'],
				'repository_linked'      => self::linked(),
			],
			static fn ( $v ): bool => $v !== '' && $v !== null
		);
	}
}
