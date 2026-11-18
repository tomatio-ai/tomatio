<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Voice;

/**
 * #1002: the measured part of a voice profile, computed in PHP from
 * writing samples that are then dropped. Plain counts, no model and no
 * WordPress, so it is unit-testable as it is.
 *
 * What is kept (the shape of empty()):
 *  - samples, words: how much was measured;
 *  - sentences, length: words per sentence and per sample, as running
 *    {n, mean, m2} (Welford), so means and spreads merge exactly;
 *  - mattr: moving-average type-token ratio (window of 50 tokens), as a
 *    token-weighted mean;
 *  - punctuation: counts per mark (per 1,000 words is counts / words);
 *  - emoji: a count;
 *  - function_words: counts of each function word of the language, for a
 *    Delta-style distance (Burrows) between a draft and the profile;
 *  - openers: the first word of each sentence, counted (top 20);
 *  - phrases: runs of 2 or 3 words that recur, counted (top 40; never
 *    more than 3 tokens, and never only function words);
 *  - banned: words the owner does not use (set by hand, or learned when a
 *    person removes the same word from an agent's drafts in 2 edits);
 *  - removed: single words people removed from drafts, counted per edit;
 *  - edits: how many before/after pairs were measured.
 *
 * Nothing longer than 3 tokens of a sample survives measure().
 */
final class Measure {

	public const VERSION     = 1;
	public const WINDOW      = 50;
	public const MAX_OPENERS = 20;
	public const MAX_PHRASES = 40;
	public const MAX_REMOVED = 30;
	public const MAX_BANNED  = 60;

	/** A word removed in this many edits becomes banned. */
	public const BAN_AFTER_EDITS = 2;

	/** Punctuation counted one character at a time; the rest are counted in count(). */
	private const MARKS = [
		'comma'       => ',',
		'semicolon'   => ';',
		'colon'       => ':',
		'exclamation' => '!',
		'question'    => '?',
		'em_dash'     => '—',
		'en_dash'     => '–',
		'parenthesis' => '(',
	];

	/** Every mark measured, in the order summaries list them. */
	public const MARK_NAMES = [ 'comma', 'period', 'semicolon', 'colon', 'exclamation', 'question', 'em_dash', 'en_dash', 'spaced_hyphen', 'double_hyphen', 'ellipsis', 'parenthesis', 'quote' ];

	private const FUNCTION_WORDS = [
		'en' => 'a about above after again against all am an and any are as at be because been before being below between both but by can could did do does doing down during each few for from further had has have having he her here hers herself him himself his how i if in into is it its itself just me more most my myself no nor not now of off on once only or other our ours out over own same she should so some such than that the their theirs them then there these they this those through to too under until up very was we were what when where which while who whom why will with would you your yours',
		'es' => 'a al algo algunos ante antes aquí como con contra cual cuando de del desde donde durante e el ella ellas ellos en entre era es esa ese eso esta este esto estoy fue ha hay la las le les lo los me mi mis mucho muy más nada ni no nos nosotros o os otra otro para pero poco por porque que qué quien se sea ser si sin sino sobre son su sus sí también tan te tengo ti todo tu tus un una uno unos y ya yo',
		'pt' => 'a ao aos as até com como da das de do dos e ela elas ele eles em entre era essa esse eu foi há isso isto já lhe mais mas me meu minha muito na nas nem no nos não o os ou para pela pelo por porque que quando se sem ser seu sua são também te tem um uma você à é',
	];

	/** @return array<string, mixed> */
	public static function empty(): array {
		return [
			'v'              => self::VERSION,
			'samples'        => 0,
			'words'          => 0,
			'sentences'      => self::stat( [] ),
			'length'         => self::stat( [] ),
			'mattr'          => [ 'tokens' => 0, 'mean' => 0.0 ],
			'punctuation'    => array_fill_keys( self::MARK_NAMES, 0 ),
			'emoji'          => 0,
			'function_words' => [],
			'openers'        => [],
			'phrases'        => [],
			'banned'         => [],
			'removed'        => [],
			'edits'          => 0,
		];
	}

	/**
	 * Measure a batch of samples: one stats array for all of them. Empty
	 * samples (no words) are skipped.
	 *
	 * @param array<int, string> $samples
	 * @return array<string, mixed>
	 */
	public static function measure( array $samples, string $language ): array {
		$out = self::empty();
		foreach ( $samples as $sample ) {
			$one = self::one( (string) $sample, $language );
			if ( $one['samples'] > 0 ) {
				$out = self::merge( $out, $one );
			}
		}

		return $out;
	}

	/**
	 * A person's edit of an agent's draft: the after text is a sample of
	 * the person, and the words (and dashes) they took out are counted
	 * once per edit in `removed`.
	 *
	 * @return array<string, mixed>
	 */
	public static function edit( string $before, string $after, string $language ): array {
		$out = self::measure( [ $after ], $language );

		$kept    = array_count_values( self::tokens( self::clean( $after ) ) );
		$removed = [];
		foreach ( array_unique( self::tokens( self::clean( $before ) ) ) as $word ) {
			if ( isset( $kept[ $word ] ) || mb_strlen( $word ) < 4 || self::isFunctionWord( $word, $language ) || preg_match( '/^\d+$/', $word ) === 1 ) {
				continue;
			}
			$removed[ $word ] = 1;
		}
		foreach ( [ '—', '!' ] as $mark ) {
			if ( substr_count( $before, $mark ) > substr_count( $after, $mark ) ) {
				$removed[ $mark ] = 1;
			}
		}

		$out['removed'] = $removed;
		$out['edits']   = 1;

		return $out;
	}

	/**
	 * Stats of one text, as a batch of one.
	 *
	 * @return array<string, mixed>
	 */
	public static function one( string $text, string $language ): array {
		$clean  = self::clean( $text );
		$tokens = self::tokens( $clean );
		$out    = self::empty();
		if ( $tokens === [] ) {
			return $out;
		}

		$lengths = [];
		$openers = [];
		$phrases = [];
		foreach ( self::sentences( $clean ) as $sentence ) {
			$words = self::tokens( $sentence );
			if ( $words === [] ) {
				continue;
			}
			$lengths[]               = count( $words );
			$openers[ $words[0] ]    = ( $openers[ $words[0] ] ?? 0 ) + 1;
			foreach ( self::ngrams( $words, $language ) as $gram ) {
				$phrases[ $gram ] = ( $phrases[ $gram ] ?? 0 ) + 1;
			}
		}

		$functionWords = [];
		foreach ( $tokens as $token ) {
			if ( self::isFunctionWord( $token, $language ) ) {
				$functionWords[ $token ] = ( $functionWords[ $token ] ?? 0 ) + 1;
			}
		}

		$out['samples']        = 1;
		$out['words']          = count( $tokens );
		$out['sentences']      = self::stat( $lengths );
		$out['length']         = self::stat( [ count( $tokens ) ] );
		$out['mattr']          = [ 'tokens' => count( $tokens ), 'mean' => self::mattr( $tokens ) ];
		$out['punctuation']    = self::marks( $clean );
		$out['emoji']          = self::emoji( $clean );
		$out['function_words'] = $functionWords;
		$out['openers']        = $openers;
		$out['phrases']        = $phrases;

		return $out;
	}

	/**
	 * Combine two stats arrays exactly (counts add, running means and
	 * spreads merge). Lists are not trimmed here: trim() does that before a
	 * profile is stored, so counts within one batch add up first.
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 * @return array<string, mixed>
	 */
	public static function merge( array $a, array $b ): array {
		$a   = self::normalize( $a );
		$b   = self::normalize( $b );
		$out = self::empty();

		$out['samples']   = $a['samples'] + $b['samples'];
		$out['words']     = $a['words'] + $b['words'];
		$out['sentences'] = self::combine( $a['sentences'], $b['sentences'] );
		$out['length']    = self::combine( $a['length'], $b['length'] );

		$tokensA      = (int) $a['mattr']['tokens'];
		$tokensB      = (int) $b['mattr']['tokens'];
		$tokens       = $tokensA + $tokensB;
		$out['mattr'] = [
			'tokens' => $tokens,
			'mean'   => $tokens > 0 ? round( ( (float) $a['mattr']['mean'] * $tokensA + (float) $b['mattr']['mean'] * $tokensB ) / $tokens, 4 ) : 0.0,
		];

		foreach ( self::MARK_NAMES as $mark ) {
			$out['punctuation'][ $mark ] = (int) ( $a['punctuation'][ $mark ] ?? 0 ) + (int) ( $b['punctuation'][ $mark ] ?? 0 );
		}
		$out['emoji']          = $a['emoji'] + $b['emoji'];
		$out['function_words'] = self::addCounts( $a['function_words'], $b['function_words'] );
		$out['openers']        = self::addCounts( $a['openers'], $b['openers'] );
		$out['phrases']        = self::addCounts( $a['phrases'], $b['phrases'] );
		$out['removed']        = self::addCounts( $a['removed'], $b['removed'] );
		$out['edits']          = $a['edits'] + $b['edits'];
		$out['banned']         = self::words( array_merge( $a['banned'], $b['banned'] ) );

		foreach ( $out['removed'] as $word => $count ) {
			if ( $count >= self::BAN_AFTER_EDITS && preg_match( '/\p{L}/u', (string) $word ) === 1 && ! in_array( (string) $word, $out['banned'], true ) ) {
				$out['banned'][] = (string) $word;
			}
		}

		return $out;
	}

	/**
	 * Keep the lists short enough to store: the top openers, phrases and
	 * removed words by count (then alphabetically, so the result is stable).
	 *
	 * @param array<string, mixed> $m
	 * @return array<string, mixed>
	 */
	public static function trim( array $m ): array {
		$m              = self::normalize( $m );
		$m['openers']   = self::top( $m['openers'], self::MAX_OPENERS );
		// Only phrases that recur are kept: a run of words seen once is just a
		// piece of a sample, and samples are never stored.
		$m['phrases']   = self::top( array_filter( $m['phrases'], static fn ( int $n ): bool => $n >= 2 ), self::MAX_PHRASES );
		$m['removed']   = self::top( $m['removed'], self::MAX_REMOVED );
		$m['banned']    = array_slice( $m['banned'], 0, self::MAX_BANNED );
		$m['sentences'] = self::rounded( $m['sentences'] );
		$m['length']    = self::rounded( $m['length'] );
		ksort( $m['function_words'], SORT_STRING );

		return $m;
	}

	/**
	 * The numbers an agent reads: means and spreads, marks per 1,000 words,
	 * the usual openers and the phrases that recur.
	 *
	 * @param array<string, mixed> $m
	 * @return array<string, mixed>
	 */
	public static function summary( array $m ): array {
		$m     = self::normalize( $m );
		$words = $m['words'];
		$per   = [];
		foreach ( self::MARK_NAMES as $mark ) {
			$per[ $mark ] = self::per1000( (int) $m['punctuation'][ $mark ], $words );
		}

		return [
			'samples'          => $m['samples'],
			'words'            => $words,
			'edits'            => $m['edits'],
			'sentence_words'   => [ 'mean' => round( $m['sentences']['mean'], 1 ), 'sd' => self::sd( $m['sentences'] ) ],
			'message_words'    => [ 'mean' => round( $m['length']['mean'], 1 ), 'sd' => self::sd( $m['length'] ) ],
			'mattr'            => round( (float) $m['mattr']['mean'], 3 ),
			'per_1000_words'   => $per,
			'emoji_per_1000'   => self::per1000( $m['emoji'], $words ),
			'openers'          => array_keys( array_slice( self::top( $m['openers'], 5 ), 0, 5, true ) ),
			'signature_phrases' => array_keys( array_filter( self::top( $m['phrases'], 12 ), static fn ( int $n ): bool => $n >= 2 ) ),
			'banned'           => $m['banned'],
		];
	}

	/**
	 * A Delta-style distance between a draft and the profile over the
	 * profile's 30 most used function words: for each, how many standard
	 * errors the draft's rate is from the profile's, averaged. Under about
	 * 1.5 reads like the owner; over 2.5 does not. Null when either text is
	 * too short to say (draft under 80 words, profile under 300).
	 *
	 * @param array<string, mixed> $draft
	 * @param array<string, mixed> $profile
	 */
	public static function delta( array $draft, array $profile ): ?float {
		$draft   = self::normalize( $draft );
		$profile = self::normalize( $profile );
		if ( $draft['words'] < 80 || $profile['words'] < 300 || $profile['function_words'] === [] ) {
			return null;
		}

		$words = self::top( $profile['function_words'], 30 );
		$sum   = 0.0;
		$n     = 0;
		foreach ( $words as $word => $count ) {
			$p  = $count / $profile['words'];
			$sd = sqrt( $p * ( 1 - $p ) / $draft['words'] );
			if ( $sd <= 0.0 ) {
				continue;
			}
			$d    = (int) ( $draft['function_words'][ $word ] ?? 0 ) / $draft['words'];
			$sum += abs( $d - $p ) / $sd;
			++$n;
		}

		return $n > 0 ? round( $sum / $n, 2 ) : null;
	}

	/**
	 * What changed between the profile so far and a new batch, in plain
	 * words, or '' when nothing moved enough (or there is too little on
	 * either side to say).
	 *
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $batch
	 */
	public static function shift( array $before, array $batch ): string {
		$before = self::normalize( $before );
		$batch  = self::normalize( $batch );
		if ( $before['samples'] < 3 || $before['words'] < 150 || $batch['words'] < 60 ) {
			return '';
		}

		$notes = [];
		$was   = (float) $before['sentences']['mean'];
		$now   = (float) $batch['sentences']['mean'];
		if ( $was > 0 && abs( $now - $was ) / $was >= 0.2 ) {
			$notes[] = sprintf( '%s sentences (%.1f to %.1f words)', $now < $was ? 'shorter' : 'longer', $was, $now );
		}
		$was = (float) $before['length']['mean'];
		$now = (float) $batch['length']['mean'];
		if ( $was > 0 && abs( $now - $was ) / $was >= 0.3 ) {
			$notes[] = sprintf( '%s messages (%d to %d words)', $now < $was ? 'shorter' : 'longer', (int) round( $was ), (int) round( $now ) );
		}

		$labels = [
			'em_dash'     => 'em dashes',
			'exclamation' => 'exclamation marks',
			'question'    => 'questions',
			'semicolon'   => 'semicolons',
		];
		foreach ( $labels as $mark => $label ) {
			$was = self::per1000( (int) $before['punctuation'][ $mark ], $before['words'] );
			$now = self::per1000( (int) $batch['punctuation'][ $mark ], $batch['words'] );
			if ( abs( $now - $was ) >= 2.0 && abs( $now - $was ) >= 0.5 * max( $was, $now ) ) {
				$notes[] = ( $now < $was ? 'fewer ' : 'more ' ) . $label;
			}
		}
		$was = self::per1000( $before['emoji'], $before['words'] );
		$now = self::per1000( $batch['emoji'], $batch['words'] );
		if ( abs( $now - $was ) >= 2.0 && abs( $now - $was ) >= 0.5 * max( $was, $now ) ) {
			$notes[] = $now < $was ? 'fewer emoji' : 'more emoji';
		}

		return $notes === [] ? '' : ucfirst( implode( ', ', $notes ) );
	}

	/** The text without links and addresses, which are not style. */
	public static function clean( string $text ): string {
		$text = (string) preg_replace( '~(?:https?://|www\.)\S+~iu', ' ', $text );
		$text = (string) preg_replace( '~\S+@\S+\.\S+~u', ' ', $text );

		return str_replace( [ "\r\n", "\r" ], "\n", $text );
	}

	/**
	 * The words of a text, lower case: letters and digits, with inner
	 * apostrophes kept (don't, it's).
	 *
	 * @return array<int, string>
	 */
	public static function tokens( string $text ): array {
		if ( preg_match_all( '/[\p{L}\p{N}]+(?:[\'’][\p{L}]+)*/u', $text, $m ) === false ) {
			return [];
		}

		return array_map( static fn ( string $w ): string => mb_strtolower( str_replace( '’', "'", $w ) ), $m[0] );
	}

	/**
	 * Sentences: split after . ! ? … followed by space, and at line breaks
	 * (a chat line is a sentence even without a full stop).
	 *
	 * @return array<int, string>
	 */
	public static function sentences( string $text ): array {
		$parts = preg_split( '/(?<=[.!?…])\s+|\n+/u', $text );

		return is_array( $parts ) ? array_values( array_filter( array_map( 'trim', $parts ), static fn ( string $s ): bool => $s !== '' ) ) : [];
	}

	public static function isFunctionWord( string $word, string $language ): bool {
		static $lists = [];
		$lang = self::primary( $language );
		if ( ! isset( $lists[ $lang ] ) ) {
			$lists[ $lang ] = isset( self::FUNCTION_WORDS[ $lang ] ) ? array_flip( explode( ' ', self::FUNCTION_WORDS[ $lang ] ) ) : [];
		}

		return isset( $lists[ $lang ][ $word ] );
	}

	/** Whether function words are measured for $language (en, es and pt). */
	public static function hasFunctionWords( string $language ): bool {
		return isset( self::FUNCTION_WORDS[ self::primary( $language ) ] );
	}

	/** Marks per 1,000 words, one decimal. */
	public static function per1000( int $count, int $words ): float {
		return $words > 0 ? round( $count * 1000 / $words, 1 ) : 0.0;
	}

	/**
	 * @param array{n?: int, mean?: float|int, m2?: float|int} $stat
	 */
	public static function sd( array $stat ): float {
		$n = (int) ( $stat['n'] ?? 0 );

		return $n > 1 ? round( sqrt( max( 0.0, (float) ( $stat['m2'] ?? 0 ) ) / ( $n - 1 ) ), 1 ) : 0.0;
	}

	/** @return array<string, int> mark name => count */
	public static function marks( string $text ): array {
		$out = array_fill_keys( self::MARK_NAMES, 0 );
		foreach ( self::MARKS as $name => $char ) {
			$out[ $name ] = substr_count( $text, $char );
		}
		$dots                  = substr_count( $text, '...' );
		$out['ellipsis']       = $dots + substr_count( $text, '…' );
		$out['period']         = max( 0, substr_count( $text, '.' ) - 3 * $dots );
		$out['spaced_hyphen']  = (int) preg_match_all( '/(?<=\s)-(?=\s)/u', $text );
		$out['double_hyphen']  = substr_count( $text, '--' );
		$out['quote']          = substr_count( $text, '"' ) + substr_count( $text, '“' ) + substr_count( $text, '”' );

		return $out;
	}

	public static function emoji( string $text ): int {
		return (int) preg_match_all( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{1F1E6}-\x{1F1FF}]/u', $text );
	}

	/**
	 * Moving-average type-token ratio over windows of WINDOW tokens; the
	 * plain ratio for a shorter text.
	 *
	 * @param array<int, string> $tokens
	 */
	public static function mattr( array $tokens ): float {
		$n = count( $tokens );
		if ( $n === 0 ) {
			return 0.0;
		}
		if ( $n <= self::WINDOW ) {
			return round( count( array_unique( $tokens ) ) / $n, 4 );
		}

		$counts = array_count_values( array_slice( $tokens, 0, self::WINDOW ) );
		$sum    = count( $counts ) / self::WINDOW;
		for ( $i = self::WINDOW; $i < $n; $i++ ) {
			$out = $tokens[ $i - self::WINDOW ];
			if ( --$counts[ $out ] === 0 ) {
				unset( $counts[ $out ] );
			}
			$counts[ $tokens[ $i ] ] = ( $counts[ $tokens[ $i ] ] ?? 0 ) + 1;
			$sum                    += count( $counts ) / self::WINDOW;
		}

		return round( $sum / ( $n - self::WINDOW + 1 ), 4 );
	}

	/**
	 * Fill in what an older or partial stats array lacks, with the right
	 * types, so every method can trust the shape.
	 *
	 * @param array<string, mixed> $m
	 * @return array<string, mixed>
	 */
	public static function normalize( array $m ): array {
		$out = self::empty();
		foreach ( [ 'samples', 'words', 'emoji', 'edits' ] as $key ) {
			$out[ $key ] = max( 0, (int) ( $m[ $key ] ?? 0 ) );
		}
		foreach ( [ 'sentences', 'length' ] as $key ) {
			$stat        = is_array( $m[ $key ] ?? null ) ? $m[ $key ] : [];
			$out[ $key ] = [
				'n'    => max( 0, (int) ( $stat['n'] ?? 0 ) ),
				'mean' => (float) ( $stat['mean'] ?? 0 ),
				'm2'   => max( 0.0, (float) ( $stat['m2'] ?? 0 ) ),
			];
		}
		$mattr        = is_array( $m['mattr'] ?? null ) ? $m['mattr'] : [];
		$out['mattr'] = [ 'tokens' => max( 0, (int) ( $mattr['tokens'] ?? 0 ) ), 'mean' => (float) ( $mattr['mean'] ?? 0 ) ];
		$marks        = is_array( $m['punctuation'] ?? null ) ? $m['punctuation'] : [];
		foreach ( self::MARK_NAMES as $mark ) {
			$out['punctuation'][ $mark ] = max( 0, (int) ( $marks[ $mark ] ?? 0 ) );
		}
		foreach ( [ 'function_words', 'openers', 'phrases', 'removed' ] as $key ) {
			$out[ $key ] = self::counts( $m[ $key ] ?? [] );
		}
		$out['banned'] = self::words( is_array( $m['banned'] ?? null ) ? $m['banned'] : [] );

		return $out;
	}

	/**
	 * Banned words or phrases: trimmed, lower case, at most 3 tokens and
	 * 60 characters each, no duplicates, in the order given.
	 *
	 * @param array<int|string, mixed> $words
	 * @return array<int, string>
	 */
	public static function words( array $words ): array {
		$out = [];
		foreach ( $words as $word ) {
			if ( ! is_string( $word ) ) {
				continue;
			}
			$word = mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $word ) ) );
			if ( $word === '' || mb_strlen( $word ) > 60 || count( explode( ' ', $word ) ) > 3 || in_array( $word, $out, true ) ) {
				continue;
			}
			$out[] = $word;
		}

		return $out;
	}

	private static function primary( string $language ): string {
		return strtolower( (string) preg_replace( '/[-_].*$/', '', trim( $language ) ) );
	}

	/**
	 * Runs of 2 and 3 words in one sentence, skipping runs made only of
	 * function words or numbers.
	 *
	 * @param array<int, string> $words
	 * @return array<int, string>
	 */
	private static function ngrams( array $words, string $language ): array {
		$out = [];
		$n   = count( $words );
		foreach ( [ 2, 3 ] as $size ) {
			for ( $i = 0; $i + $size <= $n; $i++ ) {
				$gram    = array_slice( $words, $i, $size );
				$content = array_filter( $gram, static fn ( string $w ): bool => ! self::isFunctionWord( $w, $language ) && preg_match( '/^\d+$/', $w ) !== 1 );
				if ( $content !== [] ) {
					$out[] = implode( ' ', $gram );
				}
			}
		}

		return $out;
	}

	/**
	 * @param array<int, int> $values
	 * @return array{n: int, mean: float, m2: float}
	 */
	private static function stat( array $values ): array {
		$n = count( $values );
		if ( $n === 0 ) {
			return [ 'n' => 0, 'mean' => 0.0, 'm2' => 0.0 ];
		}
		$mean = array_sum( $values ) / $n;
		$m2   = 0.0;
		foreach ( $values as $v ) {
			$m2 += ( $v - $mean ) ** 2;
		}

		return [ 'n' => $n, 'mean' => (float) $mean, 'm2' => $m2 ];
	}

	/**
	 * Chan et al.'s parallel combination of two running {n, mean, m2}.
	 *
	 * @param array{n: int, mean: float, m2: float} $a
	 * @param array{n: int, mean: float, m2: float} $b
	 * @return array{n: int, mean: float, m2: float}
	 */
	private static function combine( array $a, array $b ): array {
		$n = $a['n'] + $b['n'];
		if ( $n === 0 ) {
			return [ 'n' => 0, 'mean' => 0.0, 'm2' => 0.0 ];
		}
		$delta = $b['mean'] - $a['mean'];

		return [
			'n'    => $n,
			'mean' => $a['mean'] + $delta * $b['n'] / $n,
			'm2'   => $a['m2'] + $b['m2'] + $delta * $delta * $a['n'] * $b['n'] / $n,
		];
	}

	/**
	 * @param array{n: int, mean: float, m2: float} $stat
	 * @return array{n: int, mean: float, m2: float}
	 */
	private static function rounded( array $stat ): array {
		return [ 'n' => $stat['n'], 'mean' => round( $stat['mean'], 4 ), 'm2' => round( $stat['m2'], 4 ) ];
	}

	/**
	 * @param array<string, int> $a
	 * @param array<string, int> $b
	 * @return array<string, int>
	 */
	private static function addCounts( array $a, array $b ): array {
		foreach ( $b as $key => $count ) {
			$a[ (string) $key ] = ( $a[ (string) $key ] ?? 0 ) + $count;
		}

		return $a;
	}

	/**
	 * @param mixed $value
	 * @return array<string, int>
	 */
	private static function counts( $value ): array {
		$out = [];
		if ( ! is_array( $value ) ) {
			return $out;
		}
		foreach ( $value as $key => $count ) {
			if ( is_numeric( $count ) && (int) $count > 0 && (string) $key !== '' ) {
				$out[ (string) $key ] = (int) $count;
			}
		}

		return $out;
	}

	/**
	 * The $max highest counts, highest first, then alphabetically.
	 *
	 * @param array<string, int> $counts
	 * @return array<string, int>
	 */
	private static function top( array $counts, int $max ): array {
		$keys = array_map( 'strval', array_keys( $counts ) );
		usort(
			$keys,
			static fn ( string $x, string $y ): int => $counts[ $y ] <=> $counts[ $x ] ?: strcmp( $x, $y )
		);
		$out = [];
		foreach ( array_slice( $keys, 0, $max ) as $key ) {
			$out[ $key ] = $counts[ $key ];
		}

		return $out;
	}
}
