<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Voice;

/**
 * #1002: read MYNAH style profiles (MIT, a Claude skill) from Markdown,
 * in any of the layouts MYNAH writes them:
 *
 *  - PACK and NAPSAC: one page (PACK memory, or NAPSAC's
 *    profiles/mynah-styles page) with a `## MYNAH Profiles` heading and a
 *    `### <context-id>` section per profile;
 *  - Claude memory: one file per profile (mynah-profile-<id>.md) with YAML
 *    front matter, then the same `### <context-id>` section.
 *
 * A section is a profile when its heading is a context id, optionally
 * with a language variant (`email-external:pt`). Inside it:
 *  - `*Last updated: ...*`, `*Samples used: n*`, `*Language: ...*`;
 *  - `- **Field**: value` lines (also `- **Field:** value`), with indented
 *    `- item` lines below a field taken as its list items.
 *
 * Known fields map to the readable part (Profile::READABLE_TEXT and
 * READABLE_LISTS, Formality); MYNAH's optional fields (Hedging, Humor,
 * Structure and the like) go to `extra` under their own label.
 *
 * Pure: no WordPress, no storage.
 */
final class Mynah {

	/** lower-case MYNAH label => readable field. */
	private const FIELDS = [
		'tone'            => 'tone',
		'formality'       => 'formality',
		'sentence length' => 'sentence_length',
		'message length'  => 'message_length',
		'greeting'        => 'greeting',
		'closing'         => 'closing',
		'emoji'           => 'emoji',
		'punctuation'     => 'punctuation',
		'directness'      => 'directness',
		'signature moves' => 'signature_moves',
		'avoid'           => 'avoid',
		'example openers' => 'example_openers',
		'example phrases' => 'example_phrases',
	];

	/**
	 * Every profile in $markdown.
	 *
	 * @return array{profiles: array<int, array{context: string, language: string, readable: array<string, mixed>, samples: int, updated: string}>, skipped: array<int, string>}
	 */
	public static function parse( string $markdown, string $defaultLanguage = 'en' ): array {
		$text  = str_replace( [ "\r\n", "\r" ], "\n", $markdown );
		$text  = (string) preg_replace( '/\A---\n.*?\n---\n/s', '', $text );
		$lines = explode( "\n", $text );

		$profiles = [];
		$skipped  = [];
		$current  = null;
		foreach ( $lines as $line ) {
			if ( preg_match( '/^#{2,4}\s+(.+?)\s*#*\s*$/u', $line, $m ) === 1 ) {
				if ( $current !== null ) {
					$profiles[] = $current;
				}
				$current = null;
				$heading = trim( $m[1], " \t`*" );
				if ( preg_match( '/^([a-z0-9][a-z0-9-]*)(?::([a-z]{2,3}(?:-[a-z0-9]{2,8})?))?$/i', $heading, $h ) === 1 && ( str_contains( $h[1], '-' ) || isset( Contexts::KNOWN[ strtolower( $h[1] ) ] ) ) ) {
					$current = [
						'context'  => Contexts::normalize( $h[1] ),
						'variant'  => isset( $h[2] ) ? strtolower( $h[2] ) : '',
						'language' => '',
						'samples'  => 0,
						'updated'  => '',
						'fields'   => [],
						'field'    => '',
					];
				} elseif ( ! preg_match( '/mynah/i', $heading ) ) {
					$skipped[] = $heading;
				}
				continue;
			}
			if ( $current === null ) {
				continue;
			}
			$current = self::line( $current, $line );
		}
		if ( $current !== null ) {
			$profiles[] = $current;
		}

		$out = [];
		foreach ( $profiles as $p ) {
			if ( $p['context'] === '' || $p['fields'] === [] ) {
				continue;
			}
			$language = $p['variant'] !== '' ? $p['variant'] : ( $p['language'] !== '' ? Contexts::language( $p['language'] ) : Contexts::language( $defaultLanguage ) );
			$out[]    = [
				'context'  => $p['context'],
				'language' => $language,
				'readable' => self::readable( $p['fields'] ),
				'samples'  => $p['samples'],
				'updated'  => $p['updated'],
			];
		}

		return [ 'profiles' => $out, 'skipped' => $skipped ];
	}

	/**
	 * @param array<string, mixed> $current
	 * @return array<string, mixed>
	 */
	private static function line( array $current, string $line ): array {
		if ( preg_match( '/^\s*\*(Last updated|Samples used|Language):\s*(.*?)\*\s*$/i', $line, $m ) === 1 ) {
			$key = strtolower( $m[1] );
			if ( $key === 'language' ) {
				$current['language'] = trim( $m[2] );
			} elseif ( $key === 'samples used' ) {
				$current['samples'] = (int) $m[2];
			} else {
				$current['updated'] = trim( $m[2] );
			}

			return $current;
		}
		if ( preg_match( '/^[-*]\s+\*\*(.+?):?\*\*:?\s*(.*)$/u', $line, $m ) === 1 ) {
			$label                          = trim( rtrim( $m[1], ':' ) );
			$current['field']               = $label;
			$current['fields'][ $label ]    = [ 'value' => trim( $m[2] ), 'items' => [] ];

			return $current;
		}
		if ( $current['field'] !== '' && preg_match( '/^\s{2,}[-*]\s+(.+)$/u', $line, $m ) === 1 ) {
			$current['fields'][ $current['field'] ]['items'][] = trim( $m[1] );
		}

		return $current;
	}

	/**
	 * @param array<string, array{value: string, items: array<int, string>}> $fields
	 * @return array<string, mixed>
	 */
	private static function readable( array $fields ): array {
		$input = [ 'extra' => [] ];
		foreach ( $fields as $label => $field ) {
			$key = self::FIELDS[ strtolower( $label ) ] ?? null;
			if ( $key === null ) {
				$value = $field['value'] !== '' ? $field['value'] : implode( '; ', $field['items'] );
				if ( $value !== '' ) {
					$input['extra'][ $label ] = $value;
				}
				continue;
			}
			if ( isset( Profile::READABLE_LISTS[ $key ] ) ) {
				$input[ $key ] = $field['items'] !== [] ? $field['items'] : self::split( $key, $field['value'] );
				continue;
			}
			$input[ $key ] = $field['value'] !== '' ? $field['value'] : implode( '; ', $field['items'] );
		}

		return Profile::readable( $input );
	}

	/**
	 * A list field written on one line: example openers and phrases are
	 * the quoted strings; avoid and signature moves split on commas and
	 * semicolons outside parentheses and quotes.
	 *
	 * @return array<int, string>
	 */
	private static function split( string $key, string $value ): array {
		if ( in_array( $key, [ 'example_openers', 'example_phrases' ], true ) && preg_match_all( '/["“]([^"”]+)["”]/u', $value, $m ) > 0 ) {
			return array_map( 'trim', $m[1] );
		}

		$out   = [];
		$buf   = '';
		$depth = 0;
		$quote = false;
		foreach ( preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY ) ?: [] as $char ) {
			if ( $char === '"' || $char === '“' || $char === '”' ) {
				$quote = $char === '“' ? true : ( $char === '”' ? false : ! $quote );
			} elseif ( $char === '(' ) {
				++$depth;
			} elseif ( $char === ')' ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ( $char === ',' || $char === ';' ) && $depth === 0 && ! $quote ) {
				$out[] = trim( $buf );
				$buf   = '';
				continue;
			}
			$buf .= $char;
		}
		$out[] = trim( $buf );

		return array_values( array_filter( $out, static fn ( string $s ): bool => $s !== '' ) );
	}
}
