<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * Pure embed policy (no WordPress): share-link kinds, the default embed
 * expiry, frame-ancestors origin validation and the iframe snippet.
 *
 * An embed link is an ordinary share link that may additionally be framed by
 * the origins on the Settings allowlist. With an empty allowlist nothing may
 * frame it (frame-ancestors 'none').
 */
final class EmbedPolicy {

	public const KIND_LINK  = 'link';
	public const KIND_EMBED = 'embed';

	public const KINDS = [ self::KIND_LINK, self::KIND_EMBED ];

	public const DEFAULT_EMBED_TTL = 86400; // 24 hours.

	public static function normalizeKind( string $kind ): string {
		return in_array( $kind, self::KINDS, true ) ? $kind : self::KIND_LINK;
	}

	/**
	 * Embed links get a short default expiry; an explicit TTL wins.
	 */
	public static function ttlFor( string $kind, ?int $seconds ): int {
		if ( $kind === self::KIND_EMBED && ( $seconds === null || $seconds <= 0 ) ) {
			return self::DEFAULT_EMBED_TTL;
		}

		return Policy::clampTtl( $seconds );
	}

	/**
	 * Normalizes one origin (scheme + host [+ port], no path) or returns null.
	 * Only https is accepted; wildcards, userinfo, paths, queries and IP
	 * literals are rejected.
	 */
	public static function validateOrigin( string $origin ): ?string {
		$origin = strtolower( trim( $origin ) );

		if ( preg_match( '#^https://([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*)(:([0-9]{1,5}))?$#', $origin, $m ) !== 1 ) {
			return null;
		}

		$host = $m[1];
		$port = isset( $m[6] ) && $m[6] !== '' ? (int) $m[6] : null;

		if ( strlen( $host ) > 253 || preg_match( '/^[0-9.]+$/', $host ) === 1 ) {
			return null;
		}

		if ( $port !== null && ( $port < 1 || $port > 65535 ) ) {
			return null;
		}

		return 'https://' . $host . ( $port !== null && $port !== 443 ? ':' . $port : '' );
	}

	/**
	 * Parses the newline-separated setting value.
	 *
	 * @return array{origins:string[],invalid:string[]} Unique valid origins, and the raw lines rejected.
	 */
	public static function parseOrigins( string $raw ): array {
		$origins = [];
		$invalid = [];

		foreach ( preg_split( '/\R/', $raw ) ?: [] as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}

			$origin = self::validateOrigin( $line );
			if ( $origin === null ) {
				$invalid[] = $line;
			} elseif ( ! in_array( $origin, $origins, true ) ) {
				$origins[] = $origin;
			}
		}

		return [ 'origins' => $origins, 'invalid' => $invalid ];
	}

	/**
	 * The CSP frame-ancestors directive for an embed link.
	 *
	 * @param string[] $origins Validated origins.
	 */
	public static function frameAncestors( array $origins ): string {
		$valid = [];
		foreach ( $origins as $origin ) {
			$normalized = self::validateOrigin( (string) $origin );
			if ( $normalized !== null ) {
				$valid[] = $normalized;
			}
		}

		return $valid === [] ? "frame-ancestors 'none'" : 'frame-ancestors ' . implode( ' ', array_unique( $valid ) );
	}

	/**
	 * Origin of a Referer URL (scheme://host[:port]) or '' when unusable.
	 */
	public static function originOfUrl( string $url ): string {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class, no WordPress.
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '';
		}

		return self::validateOrigin( $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) ) ?? '';
	}

	/**
	 * May this request count as an embed by $boundOrigin? The bound origin must
	 * be on the allowlist; a presented Origin (else Referer) must equal it; with
	 * neither (the embedding page may send no referrer) the browser must say it
	 * is loading an iframe, where CSP frame-ancestors enforces the allowlist.
	 * Any other Sec-Fetch-Dest (a top-level visit, a script fetch) is refused.
	 *
	 * @param string[] $allowed Allowlist origins.
	 */
	public static function embeddingOriginOk( string $boundOrigin, array $allowed, string $originHeader, string $referer, string $fetchDest ): bool {
		$bound = self::validateOrigin( $boundOrigin );
		if ( $bound === null || ! in_array( $bound, $allowed, true ) ) {
			return false;
		}

		$fetchDest = strtolower( trim( $fetchDest ) );
		if ( $fetchDest !== '' && $fetchDest !== 'iframe' ) {
			return false;
		}

		$presented = self::validateOrigin( $originHeader ) ?? '';
		if ( $presented === '' ) {
			$presented = self::originOfUrl( $referer );
		}

		if ( $presented !== '' ) {
			return hash_equals( $bound, $presented );
		}

		return $fetchDest === 'iframe';
	}

	/**
	 * Sandboxed iframe markup for a freshly minted embed URL. The sandbox
	 * grants nothing (no same-origin), and no scripts unless the artifact is
	 * interactive, which gets allow-scripts only (never allow-same-origin).
	 */
	public static function iframeSnippet( string $url, bool $interactive = false ): string {
		return sprintf(
			'<iframe src="%s" sandbox%s referrerpolicy="no-referrer" loading="lazy" style="width:100%%;height:600px;border:0"></iframe>',
			htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ),
			$interactive ? '="allow-scripts"' : ''
		);
	}
}
