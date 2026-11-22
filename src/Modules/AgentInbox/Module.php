<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentInbox;

use AgentBrain\Core\ModuleInterface;

/**
 * #1060: always-on agents wake on events instead of polling, and set
 * their own follow-ups (after Anthropic's "Building always-on agents").
 *
 * - Inbox: a durable inbox per agent that takes tasks, deduplicated by the
 *   source's event id, claimed as one batch under a lease by one run at a
 *   time, closed as done or failed.
 * - Events: TíoDay tasks, comments and waiting_on, Magic Backlog assignments
 *   and review decisions push into it.
 * - Timers: follow-ups and schedules in their own table, fired into the
 *   inbox by Cron.
 * - Wake: a signed POST to the agent's https address when an event lands.
 * - Abilities and Rest: claim, done, peek, wake, timer-set/list/cancel.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'agent-inbox';
	}

	public function register(): void {
		add_action( 'wp_abilities_api_init', [ Abilities::class, 'register' ] );
		Rest::register();
		Events::register();
		Wake::register();
		Cron::register();
		add_action(
			'deleted_user',
			static function ( $userId ): void {
				Inbox::deleteForAgent( (int) $userId );
				Timers::deleteForAgent( (int) $userId );
			}
		);
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			1 => static function (): void {
				self::dbDelta( Inbox::createTableSql() );
				self::dbDelta( Timers::createTableSql() );
				Cron::schedule();
			},
		];
	}

	private static function dbDelta( string $sql ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );
	}

	public function activate(): void {
		Cron::schedule();
	}

	public function deactivate(): void {
		Cron::unschedule();
	}
}
