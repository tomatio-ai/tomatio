<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Voice;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;
use AgentBrain\Modules\Sharing\ShareAbilities;

/**
 * #1002: the voice tools, listed while Voice is on (Enabled) for any
 * connection that may use MCP. Who may read or change a given owner's
 * profile is decided per call (Access); a refusal names no profile.
 *
 * - tomatio-voice-get: the profile and short guidance, the nearest
 *   context when there is none for the one asked for;
 * - tomatio-voice-list: the owner's contexts;
 * - tomatio-voice-check: what is off in a draft (never rewrites it);
 * - tomatio-voice-learn: measure samples or a before/after edit, merge,
 *   record a shift, drop the text;
 * - tomatio-voice-set: the readable fields, banned words, approve or
 *   reject what waits, delete;
 * - tomatio-voice-import: MYNAH profiles from Markdown.
 */
final class Abilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$target = [
			'owner'    => [ 'type' => [ 'string', 'integer' ], 'description' => 'Whose voice: "me" (default: the person you work for, or yourself), a login, an agent id or an exact display name.' ],
			'context'  => [ 'type' => 'string', 'description' => 'What is being written: ' . implode( ', ', array_keys( Contexts::KNOWN ) ) . ', or another lower-case id with hyphens. global holds the rules for every context.' ],
			'language' => [ 'type' => 'string', 'description' => 'A language tag such as en, es or pt-br; default en.' ],
		];
		$fields = [
			'type'        => 'object',
			'description' => 'MYNAH\'s readable fields; leave out what stays as it is. formality is 1 (very casual) to 5 (very formal); lists are arrays of short lines. example_phrases and example_openers are kept only as the owner or their lead approves them.',
			'properties'  => [
				'tone'            => [ 'type' => 'string' ],
				'formality'       => [ 'type' => [ 'integer', 'null' ], 'minimum' => 1, 'maximum' => 5 ],
				'sentence_length' => [ 'type' => 'string' ],
				'message_length'  => [ 'type' => 'string' ],
				'greeting'        => [ 'type' => 'string' ],
				'closing'         => [ 'type' => 'string' ],
				'emoji'           => [ 'type' => 'string' ],
				'punctuation'     => [ 'type' => 'string' ],
				'directness'      => [ 'type' => 'string' ],
				'signature_moves' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'avoid'           => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Things out of character. A quoted phrase ("I hope this finds you well") is checked word for word; "em dashes" and "exclamation marks" are checked as marks.' ],
				'example_openers' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'example_phrases' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'extra'           => [ 'type' => 'object', 'description' => 'MYNAH\'s optional fields by label, such as {"Humor": "dry"}.' ],
			],
		];
		$profileOut = [
			'type'       => 'object',
			'properties' => [
				'owner'            => [ 'type' => 'string' ],
				'owner_name'       => [ 'type' => 'string' ],
				'context'          => [ 'type' => 'string' ],
				'language'         => [ 'type' => 'string' ],
				'found'            => [ 'type' => 'boolean' ],
				'fallback'         => [ 'type' => 'boolean', 'description' => 'True when the profile is for another context or language than the one asked for; note says which.' ],
				'note'             => [ 'type' => 'string' ],
				'profile_context'  => [ 'type' => 'string' ],
				'profile_language' => [ 'type' => 'string' ],
				'readable'         => [ 'type' => 'object' ],
				'measured'         => [ 'type' => 'object', 'description' => 'samples, words, sentence_words and message_words (mean, sd), mattr, per_1000_words per mark, emoji_per_1000, openers, signature_phrases, banned.' ],
				'global'           => [ 'type' => [ 'object', 'null' ], 'description' => 'The owner\'s rules for every context: readable and banned.' ],
				'guidance'         => [ 'type' => 'string', 'description' => 'One paragraph to write by.' ],
				'pending'          => [ 'type' => 'boolean', 'description' => 'Readable fields an agent proposed wait for the owner or their lead.' ],
				'last_shift'       => [ 'type' => [ 'object', 'null' ] ],
				'updated_at'       => [ 'type' => 'string' ],
				'shared'           => [ 'type' => 'boolean', 'description' => 'Kept in a team scope (its readers may read it), not the owner\'s private one.' ],
				'profile_id'       => [ 'type' => 'integer' ],
			],
			'required'   => [ 'owner', 'context', 'found' ],
		];
		$say = [ 'type' => 'string', 'description' => 'One line for the person on what happened.' ];

		self::register_one(
			AbilityNames::VOICE_GET,
			__( 'Get a writing voice', 'tomatio' ),
			__( 'Before you write anything for someone or as someone (a message, an email, a post, a comment, a document in their name), get their voice for that context and language and write in it. Returns the readable profile (MYNAH fields: tone, formality, greeting, closing, emoji, punctuation, sentence length, signature moves, avoid, examples), the measured numbers, the owner\'s global rules and a guidance paragraph. When there is no profile for the context, the nearest one comes back with fallback true and a note saying which; say so if it matters. Readable by the owner, the agents working for them, their lead and curators only (and the readers of the team scope a shared voice is kept in).', 'tomatio' ),
			[ 'type' => 'object', 'properties' => $target, 'required' => [ 'context' ] ],
			$profileOut,
			static fn ( array $input ) => Service::get( $input ),
			false
		);

		self::register_one(
			AbilityNames::VOICE_LIST,
			__( 'List the voices kept for someone', 'tomatio' ),
			__( 'The contexts and languages someone has a voice profile for, each with its samples, whether fields wait for approval and the last shift, plus the known contexts. owner defaults to the person you work for.', 'tomatio' ),
			[ 'type' => 'object', 'properties' => [ 'owner' => $target['owner'] ] ],
			[
				'type'       => 'object',
				'properties' => [
					'owner'      => [ 'type' => 'string' ],
					'owner_name' => [ 'type' => 'string' ],
					'profiles'   => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
					'known'      => [ 'type' => 'object' ],
					'page_url'   => [ 'type' => 'string', 'description' => 'Where the owner sees, edits, approves and deletes their profiles.' ],
				],
				'required'   => [ 'profiles' ],
			],
			static fn ( array $input ) => Service::list( $input ),
			false
		);

		self::register_one(
			AbilityNames::VOICE_CHECK,
			__( 'Check a draft against a voice', 'tomatio' ),
			__( 'Before you hand over a draft written for or as someone, check it against their voice for the context. Lists what is off, never rewrites: em dashes when they avoid them, banned words, phrases an avoid rule quotes, exclamation marks or emoji they do not use, length for the context, and long sentences (severity off: fix it; note: worth a look). ok is true when nothing is off. Fix what is off yourself and check again. Nothing is stored.', 'tomatio' ),
			[ 'type' => 'object', 'properties' => $target + [ 'draft' => [ 'type' => 'string' ] ], 'required' => [ 'context', 'draft' ] ],
			[
				'type'       => 'object',
				'properties' => [
					'owner'           => [ 'type' => 'string' ],
					'context'         => [ 'type' => 'string' ],
					'profile_context' => [ 'type' => 'string' ],
					'found'           => [ 'type' => 'boolean' ],
					'fallback'        => [ 'type' => 'boolean' ],
					'note'            => [ 'type' => 'string' ],
					'ok'              => [ 'type' => 'boolean' ],
					'issues'          => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'properties' => [ 'rule' => [ 'type' => 'string' ], 'severity' => [ 'type' => 'string', 'enum' => [ Check::OFF, Check::NOTE ] ], 'message' => [ 'type' => 'string' ], 'found' => [ 'type' => 'array' ] ] ] ],
					'words'           => [ 'type' => 'integer' ],
					'sentences'       => [ 'type' => 'integer' ],
					'delta'           => [ 'type' => [ 'number', 'null' ], 'description' => 'Function-word distance from the owner\'s samples (Burrows\'s Delta style); under 1.5 reads like the owner. Null for short texts.' ],
					'say'             => $say,
				],
				'required'   => [ 'ok', 'issues' ],
			],
			static fn ( array $input ) => Service::check( $input ),
			false
		);

		self::register_one(
			AbilityNames::VOICE_LEARN,
			__( 'Learn a writing voice from samples', 'tomatio' ),
			__( 'Measure texts the owner wrote for a context (samples) and merge the numbers into their profile: sentence and message length with their spread, punctuation per 1,000 words, function words, MATTR, sentence openers, phrases of up to three words. The texts are measured and dropped, never stored. When a person edits your draft before sending it, send edit: {before: your draft, after: what they sent}; words and em dashes they keep taking out become rules. A change in how they write is recorded as a shift, not written over silently. Use samples from the context only (5 to 10 are enough to start).', 'tomatio' ),
			[
				'type'       => 'object',
				'properties' => $target + [
					'samples'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Texts the owner wrote, at most ' . Service::MAX_SAMPLES . '.' ],
					'edit'     => [ 'type' => 'object', 'properties' => [ 'before' => [ 'type' => 'string' ], 'after' => [ 'type' => 'string' ] ], 'required' => [ 'after' ] ],
					'edits'    => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'properties' => [ 'before' => [ 'type' => 'string' ], 'after' => [ 'type' => 'string' ] ] ] ],
					'scope_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Only for a new profile kept in a team scope (a brand voice); default the owner\'s private scope.' ],
				],
				'required'   => [ 'context' ],
			],
			[
				'type'       => 'object',
				'properties' => [
					'owner'        => [ 'type' => 'string' ],
					'context'      => [ 'type' => 'string' ],
					'language'     => [ 'type' => 'string' ],
					'profile_id'   => [ 'type' => 'integer' ],
					'created'      => [ 'type' => 'boolean' ],
					'measured_now' => [ 'type' => 'integer' ],
					'edits_now'    => [ 'type' => 'integer' ],
					'measured'     => [ 'type' => 'object' ],
					'shift'        => [ 'type' => 'string' ],
					'banned_added' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'stored'       => [ 'type' => 'string' ],
					'say'          => $say,
				],
				'required'   => [ 'profile_id' ],
			],
			static fn ( array $input ) => Service::learn( $input ),
			true
		);

		$setOut = [
			'type'       => 'object',
			'properties' => [
				'owner'      => [ 'type' => 'string' ],
				'context'    => [ 'type' => 'string' ],
				'language'   => [ 'type' => 'string' ],
				'profile_id' => [ 'type' => 'integer' ],
				'applied'    => [ 'type' => 'boolean', 'description' => 'The fields are in effect.' ],
				'pending'    => [ 'type' => 'boolean', 'description' => 'The fields wait for the owner or their lead to approve them (page_url).' ],
				'changed'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'banned'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'deleted'    => [ 'type' => 'boolean' ],
				'page_url'   => [ 'type' => 'string' ],
				'say'        => $say,
			],
			'required'   => [ 'profile_id' ],
		];

		self::register_one(
			AbilityNames::VOICE_SET,
			__( 'Set the readable part of a voice', 'tomatio' ),
			__( 'Write the readable fields of someone\'s voice for a context (you write them from their samples; you never quote a sample at length), or their global rules (context global: avoid lines and banned words for every context, such as "never em dashes"). They apply at once when the person deciding is on this call (signed in, or behind a connection with delegated authority) and is the owner, their lead or a curator; otherwise they wait, and the answer says where the owner approves them. banned_words adds words or short phrases they never use, unban_words takes them out. approve or reject decides fields that wait, and delete removes the profile for good: both only for the owner, their lead or a curator on such a connection, and only when they asked in this chat.', 'tomatio' ),
			[
				'type'       => 'object',
				'properties' => $target + [
					'fields'       => $fields,
					'banned_words' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'unban_words'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'scope_id'     => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Keep (or move) the profile in this scope, such as a team scope for a brand voice; default the owner\'s private scope.' ],
					'approve'      => [ 'type' => 'boolean' ],
					'reject'       => [ 'type' => 'boolean' ],
					'delete'       => [ 'type' => 'boolean' ],
				],
				'required'   => [ 'context' ],
			],
			$setOut,
			static fn ( array $input ) => Service::set( $input ),
			true
		);

		self::register_one(
			AbilityNames::VOICE_IMPORT,
			__( 'Import MYNAH voice profiles', 'tomatio' ),
			__( 'Import someone\'s MYNAH style profiles from Markdown, as you read them from PACK, NAPSAC (profiles/mynah-styles) or mynah-profile-<context>.md files: each ### <context-id> section (with :pt and the like for a language) becomes their profile for that context. Readable fields apply or wait for approval as with tomatio-voice-set. Pass the Markdown as it is; nothing but the profile fields is kept.', 'tomatio' ),
			[
				'type'       => 'object',
				'properties' => [
					'owner'    => $target['owner'],
					'markdown' => [ 'type' => 'string' ],
					'language' => [ 'type' => 'string', 'description' => 'For profiles that name no language; default en.' ],
					'scope_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				],
				'required'   => [ 'markdown' ],
			],
			[
				'type'       => 'object',
				'properties' => [
					'owner'    => [ 'type' => 'string' ],
					'imported' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
					'skipped'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'say'      => $say,
				],
				'required'   => [ 'imported' ],
			],
			static fn ( array $input ) => Service::import( $input ),
			true
		);
	}

	/**
	 * For the briefing: when an agent writes for someone, it gets their
	 * voice first. Empty while Voice is off.
	 */
	public static function briefing(): string {
		if ( ! Enabled::on() ) {
			return '';
		}

		return __( 'Voice: before you write for someone or in their name (a message, email, post, comment or document), call tomatio-voice-get with them as owner and the context (such as email-external or linkedin-dm) and write in that voice; run tomatio-voice-check on the draft before you hand it over; when they edit your draft before sending, give the before and after to tomatio-voice-learn.', 'tomatio' );
	}

	/** Whether the voice tools are listed for this caller. */
	public static function allowed(): bool {
		$userId = (int) get_current_user_id();

		return Enabled::on() && $userId > 0 && user_can( $userId, Capabilities::CAP_USE_MCP );
	}

	/**
	 * @param array<string, mixed>                  $input
	 * @param array<string, mixed>                  $output
	 * @param callable(array<string, mixed>): mixed $run
	 */
	private static function register_one( string $name, string $label, string $description, array $input, array $output, callable $run, bool $write ): void {
		ShareAbilities::registerOnce(
			$name,
			[
				'label'               => $label,
				'description'         => $description,
				'category'            => McpModule::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $output,
				'permission_callback' => static fn (): bool => self::allowed(),
				'execute_callback'    => static function ( $args = null ) use ( $run, $write ) {
					$args = is_array( $args ) ? $args : [];

					return $write ? ShareAbilities::withLimiter( static fn () => $run( $args ) ) : $run( $args );
				},
			]
		);
	}
}
