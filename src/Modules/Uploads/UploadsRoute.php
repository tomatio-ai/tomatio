<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Uploads;

use AgentBrain\Modules\Sharing\ShareLinks;
use AgentBrain\Modules\Sharing\ShareRoute;
use AgentBrain\Modules\Sharing\ViewerKeys;
use AgentBrain\Modules\Sharing\ViewerToken;

/**
 * ART-27 (#167): the asset-store endpoints below an artifact's share URL,
 * reached only after ShareRoute validated the share link (token, IP binding,
 * expiry, revocation, approval) and its rate limit, so this class only
 * decides identity and authorization:
 *
 * - GET    /share/{id}/{token}/uploads              list (anyone with the link)
 * - GET    /share/{id}/{token}/uploads/{id}/{name}  the bytes, forced attachment
 * - POST   /share/{id}/{token}/uploads              member upload (viewer token)
 * - DELETE /share/{id}/{token}/uploads/{id}         uploader, lead or curators
 *
 * The whole route exists only while UploadMode is active for the artifact;
 * otherwise every request is the uniform 404, like the viewer endpoint.
 * Writes present the page's ART-24 ViewerToken (POST body or query string),
 * verified with ViewerKeys::all() and matched to the request context
 * (artifact_id and share_link_id claims), the sibling-endpoint contract.
 * Downloads answer anonymous viewers too (read-only access) and always send
 * Content-Disposition: attachment with X-Content-Type-Options: nosniff, so a
 * stored file can never execute in or confuse a browser. No cookies, CORS *,
 * no-store — the sandboxed page fetches from its opaque origin.
 */
final class UploadsRoute {

	public const PATH = 'uploads';

	/**
	 * @return array{artifact_id:int,token:string,upload_id:int|null}|null
	 */
	public static function parsePath( string $uri ): ?array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#^/share/(\d+)/([^/]+)/' . preg_quote( self::PATH, '#' ) . '(?:/(\d+)(?:/[^/]+)?)?/?$#', $path, $m ) !== 1 ) {
			return null;
		}

		return [
			'artifact_id' => (int) $m[1],
			'upload_id'   => isset( $m[3] ) ? (int) $m[3] : null,
			'token'       => rawurldecode( $m[2] ),
		];
	}

	/**
	 * @param array{artifact_id:int,token:string,upload_id:int|null} $route Validated route context.
	 * @param array<string, mixed>                                   $link  The validated share link row.
	 * @param array<string, mixed>                                   $server $_SERVER-shaped (unused; IP work happened in ShareRoute).
	 * @param string|null                                            $body  Raw request body (POST/DELETE), when already read.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function respond( array $route, array $link, string $method, string $uri, array $server, ?string $body = null, ?int $now = null ): array {
		unset( $server );
		$now    = $now ?? time();
		$method = strtoupper( $method );

		if ( ! in_array( $method, [ 'GET', 'HEAD', 'POST', 'DELETE' ], true ) ) {
			return ShareRoute::notFound();
		}

		$artifactId = $route['artifact_id'];
		if ( ! UploadMode::isActive( $artifactId ) ) {
			return ShareRoute::notFound();
		}

		if ( $method === 'POST' ) {
			if ( $route['upload_id'] !== null ) {
				return ShareRoute::notFound();
			}

			return self::upload( $route, $link, $uri, $body, $now );
		}

		if ( $route['upload_id'] === null ) {
			if ( $method !== 'GET' && $method !== 'HEAD' ) {
				return ShareRoute::notFound();
			}

			return self::listing( $route );
		}

		if ( $method === 'GET' || $method === 'HEAD' ) {
			return self::download( $route, $method );
		}

		return self::delete( $route, $link, $uri, $body, $now );
	}

	/**
	 * @param array{artifact_id:int,token:string,upload_id:int|null} $route
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function listing( array $route ): array {
		$items = [];
		foreach ( UploadStore::listFor( $route['artifact_id'] ) as $row ) {
			$items[] = self::present( $route, $row );
		}

		return self::json( 200, [ 'uploads' => $items ], self::headers() );
	}

	/**
	 * @param array{artifact_id:int,token:string,upload_id:int|null} $route
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function download( array $route, string $method ): array {
		$row = UploadStore::find( (int) $route['upload_id'] );
		if ( $row === null || (int) $row['artifact_id'] !== $route['artifact_id'] ) {
			return ShareRoute::notFound();
		}

		$headers = self::headers();
		$headers['Content-Type']           = (string) $row['mime'];
		$headers['Content-Disposition']    = self::attachmentDisposition( (string) $row['name'] );
		$headers['X-Content-Type-Options'] = 'nosniff';

		if ( $method === 'HEAD' ) {
			return [ 'status' => 200, 'headers' => $headers, 'body' => '' ];
		}

		$path = UploadStore::dirFor( $route['artifact_id'] ) . '/' . (string) $row['stored_name'];
		$raw  = is_file( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- binary bytes served through the authorized route only.
		if ( $raw === false ) {
			return ShareRoute::notFound();
		}

		return [ 'status' => 200, 'headers' => $headers, 'body' => $raw ];
	}

	/**
	 * @param array{artifact_id:int,token:string,upload_id:int|null} $route
	 * @param array<string, mixed>                                   $link
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function upload( array $route, array $link, string $uri, ?string $body, int $now ): array {
		$viewer = self::viewerFrom( $route, $link, $uri, $body, $now );
		if ( $viewer === null ) {
			return ShareRoute::notFound();
		}

		$decoded = self::jsonBody( $body );
		if ( $decoded === null ) {
			return self::jsonError( 422, 'ab_upload_bad_request', 'Send the file as JSON: name, content, encoding (utf8 or base64), optional content_type.' );
		}

		unset( $decoded['viewer_token'] );
		$file = UploadPolicy::normalize( $decoded );
		if ( $file instanceof \WP_Error ) {
			return self::jsonError( self::errorStatus( $file ), $file->get_error_code(), $file->get_error_message() );
		}

		$stored = UploadStore::store( $route['artifact_id'], (int) $viewer['principal_id'], $file );
		if ( $stored instanceof \WP_Error ) {
			return self::jsonError( self::errorStatus( $stored ), $stored->get_error_code(), $stored->get_error_message() );
		}

		self::audit( (int) $viewer['principal_id'], 'artifact_upload_created', 'ok', $stored['id'], [ 'artifact_id' => $route['artifact_id'], 'size' => $stored['size'] ] );

		return self::json( 201, [ 'upload' => self::present( $route, $stored ) ], self::headers() );
	}

	/**
	 * @param array{artifact_id:int,token:string,upload_id:int|null} $route
	 * @param array<string, mixed>                                   $link
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function delete( array $route, array $link, string $uri, ?string $body, int $now ): array {
		$viewer = self::viewerFrom( $route, $link, $uri, $body, $now );
		if ( $viewer === null ) {
			return ShareRoute::notFound();
		}

		$row = UploadStore::find( (int) $route['upload_id'] );
		if ( $row === null || (int) $row['artifact_id'] !== $route['artifact_id'] ) {
			return ShareRoute::notFound();
		}

		$manager = array_intersect( [ 'lead', 'curator', 'manage' ], $viewer['roles'] ) !== [];
		if ( ! $manager && (int) $viewer['principal_id'] !== (int) $row['uploaded_by'] ) {
			self::audit( (int) $viewer['principal_id'], 'artifact_upload_denied', 'denied', (int) $row['id'], [ 'artifact_id' => $route['artifact_id'], 'reason' => 'not_owner' ] );

			return ShareRoute::notFound();
		}

		$id = (int) $row['id'];
		UploadStore::delete( $row );
		self::audit( (int) $viewer['principal_id'], 'artifact_upload_deleted', 'ok', $id, [ 'artifact_id' => $route['artifact_id'] ] );

		return self::json( 200, [ 'deleted' => true ], self::headers() );
	}

	/**
	 * The verified viewer for a write, or null: no/invalid/wrong-context
	 * tokens are indistinguishable from anonymous (the uniform 404), and the
	 * denial is audited.
	 *
	 * @param array{artifact_id:int,token:string,upload_id:int|null} $route
	 * @param array<string, mixed>                                   $link
	 * @return array{principal_id:int,roles:array<int,string>}|null
	 */
	private static function viewerFrom( array $route, array $link, string $uri, ?string $body, int $now ): ?array {
		$token = self::presentedToken( $uri, $body );
		if ( $token === null ) {
			self::audit( 0, 'artifact_upload_denied', 'denied', 0, [ 'artifact_id' => $route['artifact_id'], 'reason' => 'anonymous' ] );

			return null;
		}

		$claims = ViewerToken::verify( $token, ViewerKeys::all(), $now );
		if ( $claims === null
			|| $claims['artifact_id'] !== $route['artifact_id']
			|| $claims['share_link_id'] !== (int) $link['id']
		) {
			self::audit( 0, 'artifact_upload_denied', 'denied', 0, [ 'artifact_id' => $route['artifact_id'], 'reason' => 'bad_viewer_token' ] );

			return null;
		}

		return [ 'principal_id' => $claims['principal_id'], 'roles' => $claims['roles'] ];
	}

	/**
	 * The viewer token from the POST body ({"viewer_token":"..."}) or the
	 * query string (works for body-less DELETE).
	 */
	private static function presentedToken( string $uri, ?string $body ): ?string {
		$decoded = self::jsonBody( $body );
		if ( $decoded !== null && isset( $decoded['viewer_token'] ) && is_string( $decoded['viewer_token'] ) && $decoded['viewer_token'] !== '' ) {
			return $decoded['viewer_token'];
		}

		$query = [];
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );

		return isset( $query['viewer_token'] ) && is_string( $query['viewer_token'] ) ? $query['viewer_token'] : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function jsonBody( ?string $body ): ?array {
		$raw = $body;
		if ( $raw === null ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- no WP filesystem API for php://input; read-only.
			$raw = file_get_contents( 'php://input' );
		}

		$decoded = json_decode( is_string( $raw ) ? $raw : '', true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * @param array{artifact_id:int,token:string} $route
	 * @param array<string, mixed>                $row
	 * @return array<string, mixed>
	 */
	private static function present( array $route, array $row ): array {
		$name = (string) $row['name'];

		return [
			'id'           => (int) $row['id'],
			'name'         => $name,
			'mime'         => (string) $row['mime'],
			'size'         => (int) $row['size'],
			'uploaded_by'  => (int) $row['uploaded_by'],
			'created_at'   => (string) $row['created_at'],
			'url'          => sprintf( '/share/%d/%s/%s/%d/%s', $route['artifact_id'], $route['token'], self::PATH, (int) $row['id'], rawurlencode( $name ) ),
		];
	}

	/**
	 * attachment + RFC 5987 filename*: the original name is bytes the browser
	 * must never interpret as a type or a path.
	 */
	private static function attachmentDisposition( string $name ): string {
		$fallback = preg_replace( '/[^A-Za-z0-9._-]/', '_', $name ) ?? 'download';

		return sprintf( 'attachment; filename="%s"; filename*=UTF-8\'\'%s', $fallback, rawurlencode( $name ) );
	}

	/**
	 * @return array<string, string>
	 */
	private static function headers(): array {
		return [
			'Content-Type'                 => 'application/json; charset=utf-8',
			'X-Robots-Tag'                 => 'noindex, nofollow',
			'Cache-Control'                => 'private, no-store',
			'Pragma'                       => 'no-cache',
			'X-Content-Type-Options'       => 'nosniff',
			// The page runs in an opaque origin; this endpoint takes no
			// cookies and sets none.
			'Access-Control-Allow-Origin'  => '*',
			'Cross-Origin-Resource-Policy' => 'cross-origin',
		];
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, string> $headers
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function json( int $status, array $data, array $headers ): array {
		return [
			'status'  => $status,
			'headers' => $headers,
			'body'    => (string) wp_json_encode( $data ),
		];
	}

	/**
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function jsonError( int $status, string $code, string $message ): array {
		return self::json( $status, [ 'error' => $code, 'message' => $message ], self::headers() );
	}

	private static function errorStatus( \WP_Error $error ): int {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 422;
	}

	/**
	 * @param array<string, mixed> $meta Never token material.
	 */
	private static function audit( int $actorId, string $event, string $status, int $uploadId, array $meta ): void {
		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => $event,
				'status'      => $status,
				'object_type' => 'artifact_upload',
				'object_id'   => (string) $uploadId,
				'meta'        => $meta,
			]
		);
	}
}
