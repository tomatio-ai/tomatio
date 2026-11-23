<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Voice;

/**
 * #1002: Voice is an opt-in extension: off until a curator or a manager
 * turns it on (Settings > Extensions, or tomatio-extension-enable with
 * extension voice). While it is off the voice tools are not listed and
 * the briefing says nothing about them; profiles already kept stay where
 * they are (they are memory rows) and come back when it is turned on.
 */
final class Enabled {

	public const OPTION = 'agent_brain_voice';

	public static function on(): bool {
		return (string) get_option( self::OPTION, '0' ) === '1';
	}

	public static function set( bool $on ): void {
		update_option( self::OPTION, $on ? '1' : '0', true );
	}
}
