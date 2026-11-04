<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\HtmlFiles;

/**
 * #420/#429: a short-lived, read-only asset capability for private HTML
 * artifact share links. A verified viewer opening the page receives a signed
 * capability that lets the sandboxed document load the artifact's own files
 * without the path-scoped viewer cookie the opaque origin cannot send.
 *
 * The capability travels in the URL path (`/share/{id}/{token}/c/{cap}/{path}`)
 * so it appears in access logs, but it is bound to one link/version and expires
 * with the viewer token. It is valid for GET/HEAD only and never reaches the
 * data store, uploads, brain-read, ask or viewer endpoints.
 */
final class AssetCapability {

	public const VERSION = 'c1';
	public const PATH_PREFIX = 'c';

	private const RESERVED_PATHS = [
		'datastore' => 'store',
		'uploads'   => 'uploads',
		'brain'     => 'brain',
		'storeclient' => '_tomatio.js',
		'viewer'    => 'viewer.json',
	];

	/**
	 * @return array{artifact_id:int,token:string,capability:string,path:string}|null
	 */
	public static function parsePath( string $uri ): ?array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#^/share/(\d+)/([^/]+)/' . preg_quote( self::PATH_PREFIX, '#' ) . '/([^/]+)/(.*)$#', $path, $m ) !== 1 ) {
			return null;
		}

		return [
			'artifact_id' => (int) $m[1],
			'token'       => rawurldecode( $m[2] ),
			'capability'  => $m[3],
			'path'        => rawurldecode( $m[4] ),
		];
	}

	/**
	 * Mint a capability bound to (link, artifact, served version, expiry).
	 *
	 * @param int $linkId  The share link id.
	 * @param int $artifactId The artifact id.
	 * @param int $version The served HtmlFiles version number.
	 * @param int $exp     Unix expiry, capped by the viewer token and link expiry.
	 * @return string The signed capability token.
	 */
	public static function mint( int $linkId, int $artifactId, int $version, int $exp ): string {
		[ $kid, $key ] = ViewerKeys::current();
		$claims        = [
			'l' => $linkId,
			'a' => $artifactId,
			'v' => $version,
			'e' => $exp,
		];
		$payload       = self::b64( (string) json_encode( $claims ) );
		$signed        = self::VERSION . '.' . $kid . '.' . $payload;

		return $signed . '.' . self::b64( hash_hmac( 'sha256', $signed, $key, true ) );
	}

	/**
	 * Verify a capability and return its claims.
	 *
	 * @param array<string, string> $keys kid => derived key.
	 * @return array{l:int,a:int,v:int,e:int}|null
	 */
	public static function verify( string $capability, array $keys, int $now ): ?array {
		$parts = explode( '.', $capability );
		if ( count( $parts ) !== 4 || $parts[0] !== self::VERSION || ! isset( $keys[ $parts[1] ] ) ) {
			return null;
		}

		$signed   = $parts[0] . '.' . $parts[1] . '.' . $parts[2];
		$expected = self::b64( hash_hmac( 'sha256', $signed, (string) $keys[ $parts[1] ], true ) );
		if ( ! hash_equals( $expected, $parts[3] ) ) {
			return null;
		}

		$json   = base64_decode( strtr( $parts[2], '-_', '+/' ), true );
		$claims = $json !== false ? json_decode( $json, true ) : null;
		if ( ! is_array( $claims ) ) {
			return null;
		}

		foreach ( [ 'l', 'a', 'v', 'e' ] as $key ) {
			if ( ! isset( $claims[ $key ] ) || ! is_int( $claims[ $key ] ) ) {
				return null;
			}
		}

		if ( $claims['e'] <= $now ) {
			return null;
		}

		return [
			'l' => $claims['l'],
			'a' => $claims['a'],
			'v' => $claims['v'],
			'e' => $claims['e'],
		];
	}

	/**
	 * Serve a file through a validated capability.
	 *
	 * @param array{artifact_id:int,token:string,capability:string,path:string} $route
	 * @param array<string, mixed>                                              $link
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function respond( array $route, array $link, string $method, int $now, string $clientIpHash, string $host = '' ): array {
		if ( ! in_array( strtoupper( $method ), [ 'GET', 'HEAD' ], true ) ) {
			return ShareRoute::notFound();
		}

		$artifactId = $route['artifact_id'];

		// Capabilities are only minted for private members links with viewer
		// identity enabled; keep the same uniform 404 if that is no longer true.
		if ( ShareLinks::normalizePrivacy( (string) ( $link['privacy'] ?? '' ) ) !== ShareLinks::PRIVACY_PRIVATE
			|| ShareLinks::normalizeViewerMode( (string) ( $link['viewer_mode'] ?? '' ) ) !== ShareLinks::VIEWER_MEMBERS
			|| ! ViewerMode::isActive( $artifactId )
		) {
			return ShareRoute::notFound();
		}

		$validated = ShareLinks::evaluate( $link, $clientIpHash, $now );
		if ( $validated['status'] !== ShareLinks::STATUS_OK || $validated['link'] === null ) {
			return ShareRoute::notFound();
		}

		$version = HtmlFiles::servedVersion( $artifactId );
		if ( $version === null ) {
			return ShareRoute::notFound();
		}

		$claims = self::verify( $route['capability'], ViewerKeys::all(), $now );
		if ( $claims === null
			|| $claims['l'] !== (int) $link['id']
			|| $claims['a'] !== $artifactId
			|| $claims['v'] !== $version['version']
		) {
			return ShareRoute::notFound();
		}

		$path = $route['path'];
		if ( self::isReservedPath( $path ) || ! HtmlFiles::isValidPath( $path ) || ! isset( $version['files'][ $path ] ) ) {
			return ShareRoute::notFound();
		}

		$file = $version['files'][ $path ];

		// Same security headers as the normal own-file route (CSP with
		// `sandbox allow-scripts`, X-Frame-Options, nosniff, no-referrer,
		// private/no-store, CORS for the opaque-origin page): a file opened
		// top-level through the capability URL runs sandboxed, never with the
		// site origin. Reused, not duplicated, so the two cannot diverge.
		[ $origin, $scope ] = HtmlSite::sources( $host, $artifactId, $route['token'] );

		return [
			'status'  => 200,
			'headers' => HtmlSite::headers( (string) $file['type'], $origin, $scope ),
			'body'    => strtoupper( $method ) === 'HEAD' ? '' : HtmlFiles::bytes( $file ),
		];
	}

	/**
	 * True when the path looks like a privileged sub-route rather than a
	 * static file of the HTML artifact.
	 */
	private static function isReservedPath( string $path ): bool {
		$first = strtolower( explode( '/', $path, 2 )[0] ?? '' );

		return in_array( $first, array_values( self::RESERVED_PATHS ), true )
			|| strtolower( $path ) === self::RESERVED_PATHS['viewer'];
	}

	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
