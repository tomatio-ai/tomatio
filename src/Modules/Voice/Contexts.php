<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Voice;

/**
 * #1002: the contexts a voice profile is kept for. MYNAH's ten, plus
 * linkedin-dm and text-friend (Dennis, 14 Nov 2026), and any other
 * lower-case id a person or agent uses (free-form extras are allowed).
 * `global` is reserved: it holds the owner's rules for every context.
 *
 * Nearest context: when an owner has no profile for the context asked
 * for, voice-get falls back to the closest one they have, in the order of
 * NEIGHBOURS, then to one with the same channel (the part before the first
 * hyphen, so email-sales finds email-external), then to any profile.
 */
final class Contexts {

	public const GLOBAL_CONTEXT = 'global';

	/** Every language: the global part, and a profile kept for all of them. */
	public const ANY_LANGUAGE = 'any';

	/** id => what it is for. */
	public const KNOWN = [
		'slack-dm-tech'         => 'Slack DM to a technical peer',
		'slack-dm-biz'          => 'Slack DM to a business or non-technical colleague',
		'slack-channel-team'    => 'Slack channel for a team or small group',
		'slack-channel-company' => 'Slack channel for the whole company',
		'email-internal'        => 'Email to colleagues',
		'email-external'        => 'Email to customers, partners, vendors or prospects',
		'notion-internal'       => 'Internal docs: specs, wikis, meeting notes',
		'doc-comment'           => 'Inline comments in a document',
		'calendar-invite'       => 'Calendar invite: title, description, agenda',
		'blog-public'           => 'Blog post or other public writing',
		'linkedin-dm'           => 'LinkedIn direct message',
		'text-friend'           => 'Text message to a friend',
	];

	/** context => the contexts to try next, nearest first. */
	private const NEIGHBOURS = [
		'slack-dm-tech'         => [ 'slack-dm-biz', 'slack-channel-team', 'text-friend', 'linkedin-dm' ],
		'slack-dm-biz'          => [ 'slack-dm-tech', 'linkedin-dm', 'slack-channel-team', 'email-internal' ],
		'slack-channel-team'    => [ 'slack-channel-company', 'slack-dm-tech', 'slack-dm-biz', 'email-internal' ],
		'slack-channel-company' => [ 'slack-channel-team', 'email-internal', 'notion-internal', 'blog-public' ],
		'email-internal'        => [ 'email-external', 'slack-dm-biz', 'slack-channel-team', 'notion-internal' ],
		'email-external'        => [ 'email-internal', 'linkedin-dm', 'blog-public', 'slack-dm-biz' ],
		'notion-internal'       => [ 'doc-comment', 'email-internal', 'blog-public', 'slack-channel-company' ],
		'doc-comment'           => [ 'notion-internal', 'slack-dm-tech', 'slack-channel-team', 'email-internal' ],
		'calendar-invite'       => [ 'email-internal', 'slack-channel-team', 'email-external', 'notion-internal' ],
		'blog-public'           => [ 'notion-internal', 'email-external', 'slack-channel-company', 'linkedin-dm' ],
		'linkedin-dm'           => [ 'email-external', 'slack-dm-biz', 'text-friend', 'slack-dm-tech' ],
		'text-friend'           => [ 'slack-dm-tech', 'slack-dm-biz', 'linkedin-dm', 'email-internal' ],
	];

	/**
	 * A context id as stored: lower case, letters, digits and hyphens, at
	 * most 40 characters. '' when nothing usable is left.
	 */
	public static function normalize( string $context ): string {
		$context = strtolower( trim( $context ) );
		$context = (string) preg_replace( '/[\s_]+/', '-', $context );
		$context = (string) preg_replace( '/[^a-z0-9-]/', '', $context );
		$context = trim( (string) preg_replace( '/-+/', '-', $context ), '-' );

		return substr( $context, 0, 40 );
	}

	/**
	 * A language as stored: a lower-case tag such as en, es or pt-br, or
	 * `any`. English names MYNAH profiles use are mapped (English => en).
	 * Default en.
	 */
	public static function language( string $language ): string {
		$value = strtolower( trim( $language ) );
		if ( $value === '' ) {
			return 'en';
		}
		$names = [
			'english'    => 'en',
			'spanish'    => 'es',
			'español'    => 'es',
			'castellano' => 'es',
			'portuguese' => 'pt',
			'português'  => 'pt',
			'german'     => 'de',
			'deutsch'    => 'de',
			'french'     => 'fr',
			'français'   => 'fr',
			'italian'    => 'it',
			'dutch'      => 'nl',
			'japanese'   => 'ja',
			'any'        => self::ANY_LANGUAGE,
			'all'        => self::ANY_LANGUAGE,
		];
		// "English + Portuguese", "Portuguese (Brazil)": the first language named.
		$parts = preg_split( '/[+,\/(]|\band\b/u', $value );
		$first = trim( is_array( $parts ) && isset( $parts[0] ) ? (string) $parts[0] : $value );
		if ( isset( $names[ $first ] ) ) {
			return $names[ $first ];
		}
		if ( preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $first ) === 1 ) {
			return $first;
		}

		return 'en';
	}

	/**
	 * The contexts to try for $context, nearest first, $context itself
	 * excluded: the known neighbours, then those of the same channel.
	 *
	 * @param array<int, string> $have the owner's contexts
	 * @return array<int, string>
	 */
	public static function nearest( string $context, array $have ): array {
		$out = [];
		foreach ( self::NEIGHBOURS[ $context ] ?? [] as $neighbour ) {
			if ( in_array( $neighbour, $have, true ) ) {
				$out[] = $neighbour;
			}
		}
		$channel = explode( '-', $context )[0];
		foreach ( $have as $other ) {
			if ( $other !== $context && $other !== self::GLOBAL_CONTEXT && ! in_array( $other, $out, true ) && explode( '-', $other )[0] === $channel ) {
				$out[] = $other;
			}
		}
		foreach ( $have as $other ) {
			if ( $other !== $context && $other !== self::GLOBAL_CONTEXT && ! in_array( $other, $out, true ) ) {
				$out[] = $other;
			}
		}

		return $out;
	}
}
