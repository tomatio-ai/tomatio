<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\AgentId\TaskTakers;
use AgentBrain\Modules\Backlog\Backlog;
use AgentBrain\Modules\Backlog\Project;
use AgentBrain\Modules\Backlog\Sources\Config;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use WP_Error;

/**
 * #1011: Magic Backlog is on for every brain, and hidden until TíoDay offers it.
 * Accepting opens the board. "Not now" hides the offer for 30 days.
 * Software words (the tagline, issues, pull requests, checks, version
 * releases, the coding bar) appear only once an issue tracker is connected.
 */
final class Offer {

	public const ACTION = 'ab_tomagic_offer';

	public const SNOOZE = 'ab_tomagic_offer_until';

	public const IDEAS = 'ab_tomagic_pending_ideas';

	public const OPEN_AT = 15;

	public const SNOOZE_DAYS = 30;

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/** An issue tracker is connected (Settings > Issue tracker): software mode. */
	public static function software(): bool {
		$issues = Config::all()['issues'];

		return $issues['repo'] !== '';
	}

	/**
	 * What TíoDay's header meta should offer this person, or null.
	 *
	 * @return array{text: string, accept: string, later: string}|null
	 */
	public static function payload( int $personId ): ?array {
		if ( ! self::show( $personId ) ) {
			return null;
		}

		return [
			'text'   => self::line( $personId ),
			'accept' => self::url( 'accept' ),
			'later'  => self::url( 'later' ),
		];
	}

	public static function show( int $personId ): bool {
		if ( $personId <= 0 || ! Switches::enabled( Switches::BACKLOG ) || Switches::revealed() ) {
			return false;
		}
		$until = (int) get_user_meta( $personId, self::SNOOZE, true );
		if ( $until > time() ) {
			return false;
		}

		return self::reason( $personId ) !== '';
	}

	/** Hide the offer for this person for 30 days. */
	public static function snooze( int $personId ): void {
		if ( $personId > 0 ) {
			update_user_meta( $personId, self::SNOOZE, time() + self::SNOOZE_DAYS * DAY_IN_SECONDS );
		}
	}

	/**
	 * Accept the offer: open the board and put this person's open tasks on it.
	 *
	 * @return array{url: string}|WP_Error
	 */
	public static function accept( int $personId ) {
		if ( $personId <= 0 ) {
			return new WP_Error( 'ab_tomagic_offer', __( 'Sign in to open the board.', 'tomatio' ), [ 'status' => 401 ] );
		}
		if ( ! Switches::enabled( Switches::BACKLOG ) ) {
			return new WP_Error( 'ab_tomagic_offer', __( 'Magic Backlog is turned off. A manager can turn it on in Settings.', 'tomatio' ), [ 'status' => 409 ] );
		}
		$page = Switches::reveal( $personId );
		if ( $page instanceof WP_Error ) {
			return $page;
		}
		self::copyTasks( (int) $page, $personId );
		self::copyIdeas( (int) $page, $personId );
		delete_user_meta( $personId, self::SNOOZE );

		return [ 'url' => (string) get_permalink( (int) $page ) ];
	}

	/** Hold an idea logged before the board is open, so TíoDay can offer it. */
	public static function rememberIdea( string $title, string $note = '' ): void {
		$title = trim( $title );
		if ( $title === '' || Switches::revealed() ) {
			return;
		}
		$all   = get_option( self::IDEAS, [] );
		$all   = is_array( $all ) ? $all : [];
		$all[] = [
			'title' => $title,
			'note'  => trim( $note ),
			'at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
		];
		update_option( self::IDEAS, array_slice( $all, -20 ), false );
	}

	public static function handle(): void {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		check_admin_referer( self::ACTION );
		$user   = (int) get_current_user_id();
		$choice = isset( $_REQUEST['choice'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['choice'] ) ) : '';
		if ( $choice === 'later' ) {
			self::snooze( $user );
			wp_safe_redirect( self::back( $user ) );
			exit;
		}
		if ( $choice === 'accept' ) {
			$done = self::accept( $user );
			$url  = is_array( $done ) ? (string) $done['url'] : self::back( $user );
			wp_safe_redirect( $url !== '' ? $url : self::back( $user ) );
			exit;
		}
		wp_safe_redirect( self::back( $user ) );
		exit;
	}

	private static function line( int $personId ): string {
		$why = self::reason( $personId );
		if ( $why === 'idea' ) {
			return __( 'You have an idea. Open it on a board?', 'tomatio' );
		}
		if ( $why === 'who' ) {
			return __( 'A task is for someone else. Open the list as a board?', 'tomatio' );
		}

		return __( 'Your list is getting big. Open it as a board?', 'tomatio' );
	}

	/** idea, who, tasks, or ''. */
	private static function reason( int $personId ): string {
		if ( self::hasIdea() ) {
			return 'idea';
		}
		$tioday = TioDay::forPerson( $personId );
		if ( $tioday <= 0 ) {
			return '';
		}
		$open  = 0;
		$other = false;
		$owner = strtolower( TioDay::name( $personId ) );
		foreach ( StoreData::list( $tioday, 'tasks' ) as $doc ) {
			$value = is_array( $doc['value'] ?? null ) ? $doc['value'] : [];
			if ( ( $value['status'] ?? 'open' ) === 'done' ) {
				continue;
			}
			++$open;
			$who = is_string( $value['who'] ?? null ) ? trim( $value['who'] ) : '';
			if ( $who !== '' && ( TaskTakers::named( $who ) > 0 || strtolower( $who ) !== $owner ) ) {
				$other = true;
			}
		}
		if ( $other ) {
			return 'who';
		}

		return $open >= self::OPEN_AT ? 'tasks' : '';
	}

	private static function hasIdea(): bool {
		$pending = get_option( self::IDEAS, [] );
		if ( is_array( $pending ) && $pending !== [] ) {
			return true;
		}
		foreach ( Backlog::all() as $board ) {
			foreach ( StoreData::list( $board, 'items' ) as $doc ) {
				if ( is_array( $doc['value'] ?? null ) && ( $doc['value']['status'] ?? '' ) === 'idea' ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function url( string $choice ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&choice=' . $choice ), self::ACTION );
	}

	private static function back( int $personId ): string {
		$tioday = $personId > 0 ? TioDay::forPerson( $personId ) : 0;

		return $tioday > 0 ? (string) get_permalink( $tioday ) : home_url( '/' );
	}

	private static function copyTasks( int $board, int $personId ): void {
		$tioday = TioDay::forPerson( $personId );
		if ( $tioday <= 0 ) {
			return;
		}
		$caller = self::caller();
		foreach ( StoreData::list( $tioday, 'tasks' ) as $doc ) {
			$value = is_array( $doc['value'] ?? null ) ? $doc['value'] : [];
			if ( ( $value['status'] ?? 'open' ) === 'done' || ( is_string( $value['item'] ?? null ) && $value['item'] !== '' ) ) {
				continue;
			}
			$title = is_string( $value['title'] ?? null ) ? trim( $value['title'] ) : '';
			if ( $title === '' ) {
				continue;
			}
			Project::log(
				$board,
				[
					'title'  => $title,
					'detail' => is_string( $value['why'] ?? null ) ? $value['why'] : '',
					'kind'   => 'chore',
					'owner'  => is_string( $value['who'] ?? null ) ? $value['who'] : '',
					'links'  => is_array( $value['links'] ?? null ) ? $value['links'] : [],
				],
				$caller
			);
		}
	}

	private static function copyIdeas( int $board, int $personId ): void {
		unset( $personId );
		$pending = get_option( self::IDEAS, [] );
		if ( ! is_array( $pending ) || $pending === [] ) {
			return;
		}
		$caller = self::caller();
		foreach ( $pending as $idea ) {
			if ( ! is_array( $idea ) || ! is_string( $idea['title'] ?? null ) || trim( $idea['title'] ) === '' ) {
				continue;
			}
			Project::log(
				$board,
				[
					'idea'   => true,
					'title'  => $idea['title'],
					'detail' => is_string( $idea['note'] ?? null ) ? $idea['note'] : '',
				],
				$caller
			);
		}
		delete_option( self::IDEAS );
	}

	/** @return array<string, mixed> */
	private static function caller(): array {
		return [
			'writer' => 0,
			'person' => 0,
			'by'     => 'Magic Backlog',
			'for'    => '',
			'people' => true,
			'store'  => StoreData::anonymousAttribution(),
		];
	}
}
