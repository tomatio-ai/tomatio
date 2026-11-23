<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

/**
 * A key pasted on an integration card. Copy buttons and chat apps add line
 * breaks, spaces and invisible characters (zero-width space, BOM, no-break
 * space) to keys, and the card used to answer every one of those with the
 * same "Paste the key". Clean what can't be part of a key, and say which
 * service and which problem when what is left still isn't one.
 */
final class KeyPaste {

	/** Whitespace and invisible format characters a key never contains. */
	private const NOISE = '/[\s\x{00A0}\x{1680}\x{2000}-\x{200F}\x{2028}-\x{202F}\x{205F}-\x{2064}\x{3000}\x{FEFF}]+/u';

	public static function clean( string $pasted ): string {
		$clean = preg_replace( self::NOISE, '', $pasted );
		return is_string( $clean ) ? $clean : trim( $pasted );
	}

	/**
	 * Empty when the cleaned key is usable, otherwise the message to show.
	 *
	 * @param list<string> $prefixes How this service's keys start (integration.json token.prefixes); empty: any.
	 */
	public static function problem( string $key, string $service, array $prefixes = [] ): string {
		if ( $key === '' ) {
			return sprintf(
				/* translators: %s: service name, such as Notion. */
				__( 'The %1$s key field was empty. Paste the key in the field on the %1$s card, then Save and test.', 'tomatio' ),
				$service
			);
		}
		if ( preg_match( '/^[\x21-\x7E]+$/', $key ) !== 1 ) {
			return sprintf(
				/* translators: %s: service name, such as Notion. */
				__( 'That doesn\'t look like a %1$s key: it has characters a key can\'t have. Copy it again from %1$s and paste it here.', 'tomatio' ),
				$service
			);
		}
		if ( strlen( $key ) < 8 || strlen( $key ) > 4096 ) {
			return sprintf(
				/* translators: %s: service name, such as Notion. */
				__( 'That %s key is too short or too long. Copy the whole key again and paste it here.', 'tomatio' ),
				$service
			);
		}
		if ( preg_match( '#^https?://#i', $key ) === 1 ) {
			return sprintf(
				/* translators: %s: service name, such as Notion. */
				__( 'That is a link, not a %1$s key. Copy the key itself from %1$s and paste it here.', 'tomatio' ),
				$service
			);
		}
		if ( $prefixes !== [] ) {
			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( $key, $prefix ) ) {
					return '';
				}
			}
			return sprintf(
				/* translators: 1: service name, such as Notion. 2: how its keys start, such as "ntn_ or secret_". */
				__( 'That doesn\'t look like a %1$s key: %1$s keys start with %2$s. Copy the key from %1$s again and paste it here.', 'tomatio' ),
				$service,
				self::either( $prefixes )
			);
		}
		return '';
	}

	/** @param list<string> $prefixes */
	private static function either( array $prefixes ): string {
		$last = array_pop( $prefixes );
		return $prefixes === [] ? (string) $last : implode( ', ', $prefixes ) . ' ' . __( 'or', 'tomatio' ) . ' ' . $last;
	}
}
