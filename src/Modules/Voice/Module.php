<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Voice;

use AgentBrain\Core\ModuleInterface;
use AgentBrain\Modules\Mcp\AbilityNames;

/**
 * #1002 Voice: a writing-style profile per person and agent, by context
 * and language, so AIs write for someone (or as someone) in their voice.
 *
 * - Profile, Profiles: the record (a readable part in MYNAH's fields, a
 *   measured part, shifts), kept as an ab_memory row of type voice in the
 *   owner's private scope (or a team scope for a shared voice).
 * - Measure, Check: stylometry in PHP (function words for Burrows's Delta,
 *   MATTR, sentence length and spread, punctuation per 1,000 words,
 *   openers, short signature phrases, banned words). No sample is kept and
 *   no model is called.
 * - Mynah: the MYNAH Markdown importer (PACK, NAPSAC, Claude memory).
 * - Access, Service, Abilities: who may read and change a profile, and
 *   the six voice tools.
 * - ProfileScreen: the Voice section on the user profile screen.
 *
 * Opt-in (Enabled), off by default. No table of its own, so no migration.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'voice';
	}

	public function register(): void {
		add_action( 'wp_abilities_api_init', [ Abilities::class, 'register' ] );
		ProfileScreen::register();
		add_filter( 'wp_register_ability_args', [ self::class, 'pointToVoice' ], 20, 2 );
	}

	/** The writing tools whose description points to tomatio-voice-get while Voice is on. */
	public const WRITING_TOOLS = [
		AbilityNames::SECTION_UPDATE,
		AbilityNames::COMMENT_ADD,
		AbilityNames::COMMENT_REPLY,
		AbilityNames::TIODAY_REPLY,
	];

	/**
	 * `wp_register_ability_args`: while Voice is on, the tools an AI writes
	 * prose with say to get the voice first.
	 *
	 * @param mixed $args
	 * @param mixed $name
	 * @return mixed
	 */
	public static function pointToVoice( $args, $name ) {
		if ( ! is_array( $args ) || ! is_string( $name ) || ! is_string( $args['description'] ?? null ) || ! Enabled::on() ) {
			return $args;
		}
		if ( ! in_array( AbilityNames::canonicalFor( $name ), self::WRITING_TOOLS, true ) ) {
			return $args;
		}
		$args['description'] .= ' ' . __( 'Writing for someone or in their name? Get their voice first (tomatio-voice-get) and check the draft (tomatio-voice-check).', 'tomatio' );

		return $args;
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [];
	}

	public function activate(): void {
	}

	public function deactivate(): void {
	}
}
