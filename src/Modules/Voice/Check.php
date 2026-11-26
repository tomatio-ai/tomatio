<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Voice;

/**
 * #1002: voice-check. Lists what in a draft is off for the owner's voice
 * in a context; never rewrites it. Each issue is
 * {rule, severity, message, found?}: severity `off` breaks a rule the
 * owner set or a habit the numbers are sure of, `note` is worth a look.
 *
 * Rules, from the profile (the context's, with the owner's global part):
 *  - em dashes when an avoid rule names them (or the owner never used one
 *    in 500 words or more of samples, or keeps removing them from drafts);
 *  - banned words and phrases (measured.banned, either part);
 *  - quoted phrases in an avoid rule ("I hope this finds you well");
 *  - exclamation marks when an avoid rule names them;
 *  - emoji when the profile says none;
 *  - length against the context: words per message and per sentence;
 *  - a Delta-style function-word distance, as a note, for longer drafts.
 */
final class Check {

	public const OFF  = 'off';
	public const NOTE = 'note';

	/**
	 * @param array<string, mixed>|null $profile the context's record, or null
	 * @param array<string, mixed>|null $global  the owner's global part, or null
	 * @return array{issues: array<int, array<string, mixed>>, words: int, sentences: int, delta: float|null}
	 */
	public static function draft( string $draft, ?array $profile, ?array $global, string $language ): array {
		$stats    = Measure::one( $draft, $language );
		$words    = (int) $stats['words'];
		$readable = self::readable( $profile );
		$globalR  = self::readable( $global );
		$measured = Measure::normalize( is_array( $profile['measured'] ?? null ) ? $profile['measured'] : [] );
		$avoid    = array_merge( $readable['avoid'], $globalR['avoid'] );
		$avoidAll = mb_strtolower( implode( ' | ', $avoid ) );
		$punct    = mb_strtolower( $readable['punctuation'] . ' | ' . $globalR['punctuation'] );
		$issues   = [];

		$dashes = substr_count( $draft, '—' );
		if ( $dashes > 0 ) {
			$named   = preg_match( '/em[\s-]?dash|—/u', $avoidAll ) === 1 || preg_match( '/\b(no|never|avoids?|without|not)\b[^.;|]*(em[\s-]?dash|—)/u', $punct ) === 1;
			$never   = $measured['words'] >= 500 && (int) $measured['punctuation']['em_dash'] === 0;
			$removed = (int) ( $measured['removed']['—'] ?? 0 ) >= Measure::BAN_AFTER_EDITS;
			if ( $named || $never || $removed ) {
				$why      = $named ? 'an avoid rule says no em dashes' : ( $removed ? 'the owner removes them from drafts' : sprintf( 'the owner used none in %d words of samples', $measured['words'] ) );
				$issues[] = self::issue( 'em-dash', $named || $removed ? self::OFF : self::NOTE, sprintf( '%d em dash%s (—): %s. Use a comma, a colon, parentheses or a new sentence.', $dashes, $dashes === 1 ? '' : 'es', $why ), [ '—' ] );
			}
		}

		$banned = array_values( array_unique( array_merge( $measured['banned'], Measure::normalize( is_array( $global['measured'] ?? null ) ? $global['measured'] : [] )['banned'] ) ) );
		$found  = [];
		$lower  = mb_strtolower( $draft );
		foreach ( $banned as $word ) {
			if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $word, '/' ) . '(?![\p{L}\p{N}])/u', $lower ) === 1 ) {
				$found[] = $word;
			}
		}
		if ( $found !== [] ) {
			$issues[] = self::issue( 'banned-word', self::OFF, 'Words the owner does not use: ' . implode( ', ', $found ) . '.', $found );
		}

		$phrases = [];
		foreach ( $avoid as $rule ) {
			if ( preg_match_all( '/["“]([^"”]{3,80})["”]/u', $rule, $m ) > 0 ) {
				foreach ( $m[1] as $phrase ) {
					$phrase = trim( $phrase, " \t.,;:!?…—-" );
					if ( mb_strlen( $phrase ) >= 3 && mb_strpos( $lower, mb_strtolower( $phrase ) ) !== false ) {
						$phrases[] = $phrase;
					}
				}
			}
		}
		if ( $phrases !== [] ) {
			$issues[] = self::issue( 'avoid-phrase', self::OFF, 'Phrases an avoid rule names: "' . implode( '", "', array_unique( $phrases ) ) . '".', array_values( array_unique( $phrases ) ) );
		}

		$bangs = substr_count( $draft, '!' );
		if ( $bangs > 0 && ( preg_match( '/exclamation/u', $avoidAll ) === 1 || preg_match( '/\b(no|never|avoids?|without|not)\b[^.;|]*exclamation/u', $punct ) === 1 ) ) {
			$issues[] = self::issue( 'exclamation', self::OFF, sprintf( '%d exclamation mark%s: an avoid rule names them.', $bangs, $bangs === 1 ? '' : 's' ), [ '!' ] );
		}

		$emoji = (int) $stats['emoji'];
		if ( $emoji > 0 && preg_match( '/^\s*(none|never|no emoji)/iu', $readable['emoji'] !== '' ? $readable['emoji'] : $globalR['emoji'] ) === 1 ) {
			$issues[] = self::issue( 'emoji', self::OFF, sprintf( '%d emoji: the profile says none.', $emoji ) );
		}

		$length = $measured['length'];
		if ( $length['n'] >= 3 && $words > 0 ) {
			$sd   = Measure::sd( $length );
			$high = $length['mean'] + 2 * max( $sd, 0.25 * $length['mean'] );
			if ( $words > $high && $words > 1.5 * $length['mean'] ) {
				$issues[] = self::issue( 'too-long', self::OFF, sprintf( 'Too long for %s: %d words, where the owner usually writes about %d (up to about %d).', (string) ( $profile['context'] ?? 'this context' ), $words, (int) round( $length['mean'] ), (int) round( $high ) ) );
			} elseif ( $words < 0.25 * $length['mean'] && $length['mean'] >= 40 ) {
				$issues[] = self::issue( 'too-short', self::NOTE, sprintf( 'Much shorter than usual for %s: %d words, where the owner usually writes about %d.', (string) ( $profile['context'] ?? 'this context' ), $words, (int) round( $length['mean'] ) ) );
			}
		}
		$sentences = $measured['sentences'];
		$draftMean = (float) $stats['sentences']['mean'];
		if ( $sentences['n'] >= 10 && $stats['sentences']['n'] >= 2 && $sentences['mean'] > 0 ) {
			if ( $draftMean > 1.5 * $sentences['mean'] && $draftMean - $sentences['mean'] >= 5 ) {
				$issues[] = self::issue( 'long-sentences', self::NOTE, sprintf( 'Sentences run long: %.1f words on average, where the owner writes about %.1f.', $draftMean, $sentences['mean'] ) );
			}
		}

		$delta = Measure::delta( $stats, $measured );
		if ( $delta !== null && $delta > 2.5 ) {
			$issues[] = self::issue( 'function-words', self::NOTE, sprintf( 'The small words (the, and, of, I, you) are used unlike the owner\'s samples (distance %.2f; under 1.5 reads like the owner).', $delta ) );
		}

		return [
			'issues'    => $issues,
			'words'     => $words,
			'sentences' => (int) $stats['sentences']['n'],
			'delta'     => $delta,
		];
	}

	/**
	 * @param array<string, mixed>|null $record
	 * @return array<string, mixed>
	 */
	private static function readable( ?array $record ): array {
		return Profile::readable( is_array( $record['readable'] ?? null ) ? $record['readable'] : [] );
	}

	/**
	 * @param array<int, string> $found
	 * @return array<string, mixed>
	 */
	private static function issue( string $rule, string $severity, string $message, array $found = [] ): array {
		$out = [
			'rule'     => $rule,
			'severity' => $severity,
			'message'  => $message,
		];
		if ( $found !== [] ) {
			$out['found'] = $found;
		}

		return $out;
	}
}
