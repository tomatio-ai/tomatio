<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentInbox;

use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\AgentId\TaskTakers;
use AgentBrain\Modules\Backlog\Backlog;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDayIds;
use AgentBrain\Modules\Review\ProposalService;

/**
 * #1060: what puts events in an agent's inbox.
 *
 * Every page store write goes through StoreData::write(), which passes the
 * stored value to the tomatio/store_value filter (here only to remember
 * it) and fires tomatio/store_written after. Comparing the two:
 *
 * - TíoDay `tasks`: a task newly assigned to an agent that takes tasks
 *   (its `who` names it, TaskTakers::named), and that agent's open task
 *   changing: title, why or how (task.changed), reopened from done
 *   (task.reopened), or back to open from waiting (task.unblocked).
 * - TíoDay `comments`: a new comment on a task, or on an in-flight item,
 *   whose `who` names the agent (comment.added).
 * - TíoDay `inflight`: `waiting_on` cleared on an item whose `who` names
 *   the agent: the person it waited on acted (inflight.unblocked).
 * - Tomagic `items`: an open item's `owner` newly names the agent
 *   (item.assigned).
 * - tomatio/review_outcome: a suggested fact or procedure the agent
 *   proposed was approved, edited or rejected (review.decided).
 *
 * The agent's own writes never wake it. Each event names its source by
 * the document and its store revision, so a write delivered twice adds
 * one event. Summaries say what happened in a line; the ref points at the
 * item ("t:<key>@<artifact>"), and the agent reads the item itself.
 */
final class Events {

	/** Tomagic statuses that are not open work (Backlog\Assignees::CLOSED). */
	private const CLOSED = [ 'idea', 'shipped', 'declined', 'parked', 'archived' ];

	private const WATCHED = [ 'tasks', 'comments', 'inflight', 'items' ];

	/** @var array<string, mixed> "artifact|collection|key" => the value before the write */
	private static array $before = [];

	public static function register(): void {
		add_filter( 'tomatio/store_value', [ self::class, 'remember' ], 99, 6 );
		add_action( 'tomatio/store_written', [ self::class, 'written' ], 40, 5 );
		add_action( 'tomatio/review_outcome', [ self::class, 'reviewed' ], 10, 3 );
	}

	/**
	 * tomatio/store_value: keep the stored value, to compare after the write.
	 *
	 * @param mixed $value
	 * @param mixed $before
	 * @param mixed $by
	 * @return mixed
	 */
	public static function remember( $value, int $artifactId = 0, string $collection = '', string $key = '', $before = null, $by = null ) {
		if ( in_array( $collection, self::WATCHED, true ) ) {
			self::$before[ $artifactId . '|' . $collection . '|' . $key ] = $before;
		}

		return $value;
	}

	/**
	 * tomatio/store_written.
	 *
	 * @param mixed                $value
	 * @param array<string, mixed> $by
	 */
	public static function written( int $artifactId, string $collection, string $key, $value, array $by ): void {
		$slot = $artifactId . '|' . $collection . '|' . $key;
		if ( ! array_key_exists( $slot, self::$before ) ) {
			return;
		}
		$before = self::$before[ $slot ];
		unset( self::$before[ $slot ] );
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return;
		}
		$before = is_array( $before ) && ! array_is_list( $before ) ? $before : null;
		$writer = empty( $by['anonymous'] ) ? (int) ( $by['id'] ?? 0 ) : 0;

		if ( $collection === 'items' ) {
			if ( Backlog::isBacklog( $artifactId ) ) {
				self::item( $artifactId, $key, $value, $before, $writer );
			}

			return;
		}
		if ( (string) get_post_meta( $artifactId, HomeWorkspace::META_OWNER, true ) === '' ) {
			return; // Not a TíoDay.
		}

		switch ( $collection ) {
			case 'tasks':
				self::task( $artifactId, $key, $value, $before, $writer );
				break;
			case 'comments':
				if ( $before === null ) {
					self::comment( $artifactId, $key, $value, $writer );
				}
				break;
			default: // inflight
				self::inflight( $artifactId, $key, $value, $before, $writer );
		}
	}

	/**
	 * @param array<string, mixed>      $task
	 * @param array<string, mixed>|null $before
	 */
	private static function task( int $artifactId, string $key, array $task, ?array $before, int $writer ): void {
		$agentId = self::agentNamed( $task['who'] ?? null );
		if ( $agentId <= 0 || self::isSelf( $agentId, $writer ) || ( $task['status'] ?? 'open' ) === 'done' ) {
			return;
		}

		$label = self::label( $task, $key );
		if ( $before === null || self::agentNamed( $before['who'] ?? null ) !== $agentId ) {
			/* translators: 1: the task's short ID, 2: its title. */
			self::push( $agentId, 'tioday', $artifactId, 'tasks', $key, 'task.assigned', sprintf( __( 'Task %1$s assigned to you: %2$s', 'tomatio' ), $label[0], $label[1] ), 't:' . $key . '@' . $artifactId );

			return;
		}

		$was = (string) ( $before['status'] ?? 'open' );
		$now = (string) ( $task['status'] ?? 'open' );
		if ( $was === 'done' ) {
			/* translators: 1: the task's short ID, 2: its title. */
			$summary = sprintf( __( 'Task %1$s reopened: %2$s', 'tomatio' ), $label[0], $label[1] );
			$type    = 'task.reopened';
		} elseif ( $was === 'waiting' && $now === 'open' ) {
			/* translators: 1: the task's short ID, 2: its title. */
			$summary = sprintf( __( 'Task %1$s no longer waits: %2$s', 'tomatio' ), $label[0], $label[1] );
			$type    = 'task.unblocked';
		} else {
			$changed = array_values( array_filter( [ 'title', 'why', 'how' ], static fn ( string $f ): bool => (string) ( $before[ $f ] ?? '' ) !== (string) ( $task[ $f ] ?? '' ) ) );
			if ( $changed === [] ) {
				return;
			}
			/* translators: 1: the task's short ID, 2: the fields that changed, 3: its title. */
			$summary = sprintf( __( 'Task %1$s changed (%2$s): %3$s', 'tomatio' ), $label[0], implode( ', ', $changed ), $label[1] );
			$type    = 'task.changed';
		}

		self::push( $agentId, 'tioday', $artifactId, 'tasks', $key, $type, $summary, 't:' . $key . '@' . $artifactId );
	}

	/**
	 * @param array<string, mixed> $comment
	 */
	private static function comment( int $artifactId, string $key, array $comment, int $writer ): void {
		$on = (string) ( $comment['on'] ?? '' );
		if ( preg_match( '/^([tf]):(.+)$/', $on, $m ) !== 1 ) {
			return;
		}
		$item = StoreData::get( $artifactId, $m[1] === 't' ? 'tasks' : 'inflight', $m[2] );
		if ( $item === null || ! is_array( $item['value'] ) ) {
			return;
		}
		$agentId = self::agentNamed( $item['value']['who'] ?? null );
		if ( $agentId <= 0 || self::isSelf( $agentId, $writer ) ) {
			return;
		}

		$label = self::label( $item['value'], $m[2] );
		$by    = Inbox::line( is_string( $comment['by'] ?? null ) ? $comment['by'] : '', 60 );
		/* translators: 1: who wrote the comment, 2: the item's short ID, 3: its title. */
		$summary = sprintf( __( 'New comment by %1$s on %2$s: %3$s', 'tomatio' ), $by !== '' ? $by : __( 'someone', 'tomatio' ), $label[0], $label[1] );
		Inbox::push( $agentId, 'tioday', $artifactId . ':c:' . $key, 'comment.added', $summary, $on . '@' . $artifactId );
	}

	/**
	 * @param array<string, mixed>      $item
	 * @param array<string, mixed>|null $before
	 */
	private static function inflight( int $artifactId, string $key, array $item, ?array $before, int $writer ): void {
		$was = $before !== null ? trim( (string) ( $before['waiting_on'] ?? '' ) ) : '';
		if ( $was === '' || trim( (string) ( $item['waiting_on'] ?? '' ) ) !== '' ) {
			return;
		}
		$agentId = self::agentNamed( $item['who'] ?? null );
		if ( $agentId <= 0 || self::isSelf( $agentId, $writer ) ) {
			return;
		}

		$label = self::label( $item, $key );
		/* translators: 1: who it waited on, 2: the item's short ID, 3: its title. */
		$summary = sprintf( __( '%1$s acted: %2$s no longer waits on them: %3$s', 'tomatio' ), Inbox::line( $was, 60 ), $label[0], $label[1] );
		self::push( $agentId, 'tioday', $artifactId, 'inflight', $key, 'inflight.unblocked', $summary, 'f:' . $key . '@' . $artifactId );
	}

	/**
	 * @param array<string, mixed>      $item
	 * @param array<string, mixed>|null $before
	 */
	private static function item( int $artifactId, string $key, array $item, ?array $before, int $writer ): void {
		if ( in_array( (string) ( $item['status'] ?? 'inbox' ), self::CLOSED, true ) ) {
			return;
		}
		$agentId = self::agentNamed( $item['owner'] ?? null );
		if ( $agentId <= 0 || self::isSelf( $agentId, $writer ) || ( $before !== null && self::agentNamed( $before['owner'] ?? null ) === $agentId ) ) {
			return;
		}

		$id    = is_string( $item['id'] ?? null ) ? Inbox::line( $item['id'], 16 ) : $key;
		$title = Inbox::line( (string) ( $item['title'] ?? $key ), 80 );
		/* translators: 1: the item's short ID, such as B12, 2: its title. */
		self::push( $agentId, 'tomagic', $artifactId, 'items', $key, 'item.assigned', sprintf( __( 'Tomagic item %1$s assigned to you: %2$s', 'tomatio' ), $id, $title ), 'item:' . $key . '@' . $artifactId );
	}

	/**
	 * tomatio/review_outcome: a suggested fact or procedure was decided.
	 *
	 * @param mixed $postId
	 * @param mixed $outcome
	 * @param mixed $reviewerId
	 */
	public static function reviewed( $postId, $outcome = '', $reviewerId = 0 ): void {
		$post = get_post( (int) $postId );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$agentId = (int) get_post_meta( $post->ID, ProposalService::META_PROPOSING_AGENT, true );
		if ( $agentId <= 0 || $agentId === (int) $reviewerId ) {
			return;
		}

		$outcome = sanitize_key( (string) $outcome );
		/* translators: 1: the proposal's title, 2: the outcome (approved, edited, rejected). */
		$summary = sprintf( __( 'Your proposal “%1$s” was decided: %2$s', 'tomatio' ), Inbox::line( (string) $post->post_title, 80 ), $outcome );
		Inbox::push( $agentId, 'review', $post->ID . ':' . $outcome, 'review.decided', $summary, $post->post_type . ':' . $post->ID );
	}

	/** Push with a source id naming the document's store revision: one event per write. */
	private static function push( int $agentId, string $source, int $artifactId, string $collection, string $key, string $type, string $summary, string $ref ): void {
		$doc      = StoreData::get( $artifactId, $collection, $key );
		$revision = $doc !== null ? (int) $doc['version'] : 0;
		Inbox::push( $agentId, $source, $artifactId . ':' . $collection[0] . ':' . $key . ':r' . $revision, $type, $summary, $ref );
	}

	/**
	 * The agent that takes tasks a `who` (or owner) names, or 0.
	 *
	 * @param mixed $who
	 */
	private static function agentNamed( $who ): int {
		return is_string( $who ) && trim( $who ) !== '' ? TaskTakers::named( $who ) : 0;
	}

	/** Whether the agent wrote this itself (it needs no wake for its own write). */
	private static function isSelf( int $agentId, int $writer ): bool {
		return $writer === $agentId || (int) get_current_user_id() === $agentId;
	}

	/**
	 * An item's short ID (or t:<key>) and title, for a summary.
	 *
	 * @param array<string, mixed> $item
	 * @return array{0: string, 1: string}
	 */
	private static function label( array $item, string $key ): array {
		$id = is_string( $item[ TioDayIds::FIELD ] ?? null ) ? TioDayIds::normalize( $item[ TioDayIds::FIELD ] ) : null;

		return [ $id ?? $key, Inbox::line( (string) ( $item['title'] ?? $key ), 80 ) ];
	}

	/** Forget remembered values (tests). */
	public static function reset(): void {
		self::$before = [];
	}
}
