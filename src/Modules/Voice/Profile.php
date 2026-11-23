<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Voice;

/**
 * #1002: one voice profile as it is stored: the content of an ab_memory
 * row of type `voice`. The row's content is Markdown a person (or a PCP
 * reader) can read, in MYNAH's profile layout, with the whole record as
 * JSON in an HTML comment at the end:
 *
 *   ### linkedin-dm
 *   *Voice of Dennis Kittrell (dennis). Language: en. Updated 2026-11-17. Samples: 12.*
 *
 *   - **Tone**: direct, warm
 *   ...
 *
 *   <!-- tomatio-voice
 *   {"voice":1,"owner":"dennis",...}
 *   -->
 *
 * The JSON is the record; the Markdown above it is written from it. The
 * record (shape of blank()):
 *  - owner (login), owner_principal (the PCP principal id, which an
 *    export and a restore keep: a restored account may get a new login and
 *    id), owner_id, context, language;
 *  - readable: MYNAH's fields (READABLE_TEXT, READABLE_LISTS, formality
 *    1 to 5, and extra for MYNAH's optional fields), what an agent puts in
 *    its prompt. Example lines are kept only here, and only as the owner
 *    or their lead approved them;
 *  - pending: readable fields an agent wrote that wait for the owner or
 *    their lead to approve ({readable, by, by_id, at}), or null;
 *  - approved: who approved the readable fields last ({by, by_id, at});
 *  - measured: Measure's numbers (never a sample);
 *  - shifts: what changed and when, newest first (at most MAX_SHIFTS).
 */
final class Profile {

	public const VERSION    = 1;
	public const MARKER     = 'tomatio-voice';
	public const MAX_SHIFTS = 20;

	public const MAX_TEXT  = 500;
	public const MAX_ITEM  = 300;
	public const MAX_ITEMS = 12;
	public const MAX_EXTRA = 12;

	/** field => MYNAH label. */
	public const READABLE_TEXT = [
		'tone'            => 'Tone',
		'sentence_length' => 'Sentence length',
		'message_length'  => 'Message length',
		'greeting'        => 'Greeting',
		'closing'         => 'Closing',
		'emoji'           => 'Emoji',
		'punctuation'     => 'Punctuation',
		'directness'      => 'Directness',
	];

	/** field => MYNAH label. */
	public const READABLE_LISTS = [
		'signature_moves' => 'Signature moves',
		'avoid'           => 'Avoid',
		'example_openers' => 'Example openers',
		'example_phrases' => 'Example phrases',
	];

	/** @return array<string, mixed> */
	public static function blank( int $ownerId, string $ownerLogin, string $context, string $language ): array {
		return [
			'voice'           => self::VERSION,
			'owner'           => $ownerLogin,
			'owner_id'        => $ownerId,
			'owner_principal' => '',
			'context'         => $context,
			'language'        => $language,
			'readable'        => self::readable( [] ),
			'pending'         => null,
			'approved'        => null,
			'measured'        => Measure::empty(),
			'shifts'          => [],
			'created_at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'updated_at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
		];
	}

	/**
	 * The readable fields from what a caller sent: known fields only,
	 * trimmed and capped; formality an integer 1 to 5 or null; lists as
	 * arrays (a string is split on new lines and semicolons). With
	 * $partial, only the fields present in $input are returned, so an
	 * update leaves the rest as they are.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function readable( array $input, bool $partial = false ): array {
		$out = [];
		foreach ( array_keys( self::READABLE_TEXT ) as $field ) {
			if ( $partial && ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$out[ $field ] = self::text( $input[ $field ] ?? '', self::MAX_TEXT );
		}
		if ( ! $partial || array_key_exists( 'formality', $input ) ) {
			$formality        = $input['formality'] ?? null;
			$formality        = is_numeric( $formality ) ? (int) $formality : ( is_string( $formality ) && preg_match( '/^\s*([1-5])/', $formality, $m ) === 1 ? (int) $m[1] : null );
			$out['formality'] = $formality !== null && $formality >= 1 && $formality <= 5 ? $formality : null;
		}
		foreach ( array_keys( self::READABLE_LISTS ) as $field ) {
			if ( $partial && ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$out[ $field ] = self::items( $input[ $field ] ?? [] );
		}
		if ( ! $partial || array_key_exists( 'extra', $input ) ) {
			$extra = [];
			if ( is_array( $input['extra'] ?? null ) ) {
				foreach ( $input['extra'] as $label => $value ) {
					$label = self::text( (string) $label, 60 );
					$value = self::text( is_scalar( $value ) ? (string) $value : '', self::MAX_TEXT );
					if ( $label !== '' && $value !== '' && count( $extra ) < self::MAX_EXTRA ) {
						$extra[ $label ] = $value;
					}
				}
			}
			$out['extra'] = $extra;
		}

		return $out;
	}

	/**
	 * Whether a readable part has nothing in it.
	 *
	 * @param array<string, mixed> $readable
	 */
	public static function readableIsEmpty( array $readable ): bool {
		foreach ( $readable as $value ) {
			if ( $value !== '' && $value !== null && $value !== [] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The stored content of a record: Markdown, then the JSON.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function render( array $record, string $ownerName ): string {
		$readable = self::readable( is_array( $record['readable'] ?? null ) ? $record['readable'] : [] );
		$measured = Measure::normalize( is_array( $record['measured'] ?? null ) ? $record['measured'] : [] );
		$context  = (string) $record['context'];
		$language = (string) $record['language'];
		$updated  = substr( (string) ( $record['updated_at'] ?? '' ), 0, 10 );

		$lines   = [ '### ' . ( $language !== 'en' && $language !== Contexts::ANY_LANGUAGE ? $context . ':' . $language : $context ) ];
		$lines[] = sprintf(
			'*Voice of %s (%s). Language: %s. Updated %s. Samples: %d.*',
			self::line( $ownerName ),
			self::line( (string) $record['owner'] ),
			$language,
			$updated,
			(int) $measured['samples']
		);
		$lines[] = '';
		foreach ( self::READABLE_TEXT as $field => $label ) {
			if ( $field === 'sentence_length' && $readable['formality'] !== null ) {
				$lines[] = '- **Formality**: ' . $readable['formality'];
			}
			if ( $readable[ $field ] !== '' ) {
				$lines[] = '- **' . $label . '**: ' . self::line( $readable[ $field ] );
			}
		}
		foreach ( self::READABLE_LISTS as $field => $label ) {
			if ( $readable[ $field ] === [] ) {
				continue;
			}
			$lines[] = '- **' . $label . '**:';
			foreach ( $readable[ $field ] as $item ) {
				$lines[] = '  - ' . self::line( $item );
			}
		}
		foreach ( $readable['extra'] as $label => $value ) {
			$lines[] = '- **' . self::line( (string) $label ) . '**: ' . self::line( $value );
		}
		$summary = Measure::summary( $measured );
		if ( $summary['samples'] > 0 ) {
			$lines[] = sprintf( '- **Measured**: %d samples, %d words; sentences %.1f words (spread %.1f); MATTR %.3f', $summary['samples'], $summary['words'], $summary['sentence_words']['mean'], $summary['sentence_words']['sd'], $summary['mattr'] );
		}
		if ( $summary['banned'] !== [] ) {
			$lines[] = '- **Banned words**: ' . self::line( implode( ', ', $summary['banned'] ) );
		}
		$shift = self::lastShift( $record );
		if ( $shift !== null ) {
			$lines[] = '- **Last shift**: ' . self::line( $shift['note'] ) . ' (' . $shift['at'] . ')';
		}
		if ( is_array( $record['pending'] ?? null ) ) {
			$lines[] = '- **Waiting for approval**: readable fields from ' . self::line( (string) ( $record['pending']['by'] ?? '' ) );
		}

		$json = (string) wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );

		return implode( "\n", $lines ) . "\n\n<!-- " . self::MARKER . "\n" . $json . "\n-->\n";
	}

	/**
	 * The record in a stored content, or null when the content is not a
	 * voice profile.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function parse( string $content ): ?array {
		$start = strpos( $content, '<!-- ' . self::MARKER . "\n" );
		if ( $start === false ) {
			return null;
		}
		$start += strlen( '<!-- ' . self::MARKER . "\n" );
		$end    = strpos( $content, "\n-->", $start );
		if ( $end === false ) {
			return null;
		}
		$record = json_decode( substr( $content, $start, $end - $start ), true );
		if ( ! is_array( $record ) || (int) ( $record['voice'] ?? 0 ) < 1 || ! is_string( $record['context'] ?? null ) || ! is_string( $record['owner'] ?? null ) ) {
			return null;
		}

		$record['context']         = Contexts::normalize( $record['context'] );
		$record['language']        = Contexts::language( is_string( $record['language'] ?? null ) ? $record['language'] : '' );
		$record['owner_id']        = (int) ( $record['owner_id'] ?? 0 );
		$record['owner_principal'] = is_string( $record['owner_principal'] ?? null ) ? $record['owner_principal'] : '';
		$record['readable']        = self::readable( is_array( $record['readable'] ?? null ) ? $record['readable'] : [] );
		$record['measured']        = Measure::normalize( is_array( $record['measured'] ?? null ) ? $record['measured'] : [] );
		$record['pending']         = is_array( $record['pending'] ?? null ) ? $record['pending'] : null;
		$record['approved']        = is_array( $record['approved'] ?? null ) ? $record['approved'] : null;
		$record['shifts']          = is_array( $record['shifts'] ?? null ) ? array_values( array_filter( $record['shifts'], 'is_array' ) ) : [];

		return $record['context'] !== '' ? $record : null;
	}

	/**
	 * Add a shift note, newest first.
	 *
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	public static function addShift( array $record, string $note, string $by ): array {
		$shifts = is_array( $record['shifts'] ?? null ) ? $record['shifts'] : [];
		array_unshift( $shifts, [ 'at' => gmdate( 'Y-m-d' ), 'note' => self::text( $note, self::MAX_TEXT ), 'by' => self::text( $by, 120 ) ] );
		$record['shifts'] = array_slice( $shifts, 0, self::MAX_SHIFTS );

		return $record;
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array{at: string, note: string, by: string}|null
	 */
	public static function lastShift( array $record ): ?array {
		$first = is_array( $record['shifts'] ?? null ) ? ( $record['shifts'][0] ?? null ) : null;

		return is_array( $first ) ? [ 'at' => (string) ( $first['at'] ?? '' ), 'note' => (string) ( $first['note'] ?? '' ), 'by' => (string) ( $first['by'] ?? '' ) ] : null;
	}

	/**
	 * Which readable fields differ between two readable parts.
	 *
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<int, string>
	 */
	public static function changedFields( array $before, array $after ): array {
		$out = [];
		foreach ( $after as $field => $value ) {
			if ( ( $before[ $field ] ?? null ) !== $value ) {
				$out[] = (string) $field;
			}
		}

		return $out;
	}

	/** One line of text: no line breaks, collapsed spaces, capped. */
	public static function text( string $value, int $max ): string {
		$value = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) );

		return mb_substr( $value, 0, $max );
	}

	/**
	 * @param mixed $value
	 * @return array<int, string>
	 */
	public static function items( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\n|;\s*/u', $value );
		}
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $item ) {
			$item = is_scalar( $item ) ? self::text( ltrim( (string) $item, "-* \t" ), self::MAX_ITEM ) : '';
			if ( $item !== '' && ! in_array( $item, $out, true ) && count( $out ) < self::MAX_ITEMS ) {
				$out[] = $item;
			}
		}

		return $out;
	}

	/** Text safe inside one Markdown list line. */
	private static function line( string $value ): string {
		return str_replace( [ "\n", "\r" ], ' ', $value );
	}
}
