<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

use AgentBrain\Modules\DataStore\TioDay;

/**
 * #990: a failing integration says so on the person's TíoDay, and a
 * recovery closes that task. No TíoDay is created just to carry the
 * note; the card still says it. The text never includes the key.
 */
final class Notice {

	public static function sync( string $id, string $name, bool $failing, string $message, int $userId ): void {
		if ( $userId <= 0 || ! class_exists( TioDay::class ) ) {
			return;
		}
		$artifact = TioDay::forPerson( $userId );
		if ( $artifact <= 0 ) {
			return;
		}
		$slug = 'integration-' . $id;
		if ( $failing ) {
			TioDay::task( $artifact, $userId, [
				'slug'   => $slug,
				'title'  => $name . ' needs attention',
				'why'    => $message !== '' ? $message : $name . ' is not working.',
				'how'    => 'Open Settings, Integrations, and test the connection or connect again.',
				'group'  => 'waiting',
				'status' => 'open',
				'who'    => 'You',
			] );

			return;
		}
		TioDay::task( $artifact, $userId, [
			'slug'  => $slug,
			'close' => true,
		] );
	}
}
