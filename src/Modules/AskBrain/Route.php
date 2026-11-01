<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AskBrain;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\BrainRead\Grants;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Sharing\Config;
use AgentBrain\Modules\Sharing\RateLimiter;
use AgentBrain\Modules\Sharing\ShareLinks;
use AgentBrain\Modules\Sharing\ShareRoute;
use AgentBrain\Modules\Sharing\ViewerIdentity;
use AgentBrain\Modules\Sharing\ViewerKeys;
use AgentBrain\Modules\Sharing\ViewerToken;
use Throwable;

/**
 * POST https://{ab_preview_host}/share/{artifactId}/{token}/brain/ask
 * body: {"question": "…", "viewer_token": "…"}  (ART-26, #166)
 *
 * The page API that answers a question from the artifact's scope with
 * citations, reached from ShareRoute only after the share link passed
 * token/IP/expiry/revocation/approval validation, so this class only
 * decides the grant, the budget and the answer:
 *
 * - feature off for the artifact, or no scope on the artifact: the uniform
 *   404 (the path is intercepted either way, so it can never be used to
 *   fetch the artifact's HTML);
 * - POST only: GET/HEAD on this path are the uniform 404 too;
 * - a viewer_token presented: verified with ViewerToken::verify() and
 *   matched to this link + artifact; a presented-but-invalid token is the
 *   uniform 404 (ART-24's invalid-code rule). The viewer's read grants then
 *   apply, intersected with the artifact's scope (Grants::resolve) — fail
 *   closed, exactly the ART-25 model;
 * - no token: anonymous, and the artifact's share policy applies — the
 *   artifact's scope, read-only.
 *
 * Rate limit: the outer share limiter already ran in ShareRoute; this class
 * applies its own check on the same fixed-window counter at ASK_PER_MINUTE
 * (ask calls reach the site's AI provider, so they get a tighter budget
 * than brain reads). Cost caps (per-artifact + site, per day) are enforced
 * inside Asker and surface here as 429 + Retry-After + a clear JSON error.
 * Every call is audited with viewer attribution and the citations used.
 */
final class Route {

	public const PATH_ASK = 'brain/ask';

	/**
	 * Ask calls per link+IP per fixed window: the outer share limit allows
	 * ~60/min, asks are capped far lower because each one spends money.
	 */
	private const ASK_PER_MINUTE = 10;

	private const QUESTION_AUDIT_MAX = 120;

	/**
	 * @return array{artifact_id:int,token:string}|null
	 */
	public static function parsePath( string $uri ): ?array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#^/share/(\d+)/([^/]+)/' . preg_quote( self::PATH_ASK, '#' ) . '$#', $path, $m ) !== 1 ) {
			return null;
		}

		return [
			'artifact_id' => (int) $m[1],
			'token'       => rawurldecode( $m[2] ),
		];
	}

	/**
	 * @param array{artifact_id:int,token:string} $route Validated route context.
	 * @param array<string, mixed>                $link  The validated share link row.
	 * @param array<string, mixed>|null           $server $_SERVER-shaped, for the rate-limit IP.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function respond( array $route, array $link, string $method, string $uri, ?string $body = null, ?int $now = null, ?array $server = null ): array {
		$now    = $now ?? time();
		$method = strtoupper( $method );

		if ( $method !== 'POST' ) {
			return ShareRoute::notFound();
		}

		$ip = Config::clientIp( $server );
		if ( ! RateLimiter::allow( $ip !== '' ? Config::ipHash( $ip ) : str_repeat( '0', 64 ), (int) ( $link['id'] ?? 0 ), $now, self::ASK_PER_MINUTE ) ) {
			$response                           = self::error( 429, 'rate_limited', 'Too many questions. Try again later.' );
			$response['headers']['Retry-After'] = '60';

			return $response;
		}

		$artifactId = $route['artifact_id'];
		$headers    = self::headers();

		// Intercepted either way: with the feature off the path is still a
		// 404, never a way to render the artifact's HTML.
		if ( ! Mode::isActive( $artifactId ) ) {
			return ShareRoute::notFound();
		}

		$scopeId = (int) get_post_meta( $artifactId, Cpts::META_SCOPE_ID, true );
		if ( $scopeId <= 0 ) {
			return ShareRoute::notFound();
		}

		[ 'grants' => $grants, 'viewer' => $viewer ] = self::resolveViewer( $method, $uri, $body, $link, $artifactId, $scopeId, $now );
		if ( $grants === null ) {
			// A presented-but-invalid viewer token: the uniform 404.
			return ShareRoute::notFound();
		}

		$params   = self::params( $method, $uri, $body );
		$question = isset( $params['question'] ) && is_string( $params['question'] ) ? $params['question'] : '';

		$asker  = new Asker( Selector::select() );
		$result = $asker->ask( $question, $grants['allowed_scope_ids'], $artifactId );

		if ( $result instanceof \WP_Error ) {
			return self::errorResponse( $result, $artifactId, (int) ( $link['id'] ?? 0 ), $viewer, $question, $now );
		}

		$usage = $result['usage'];
		self::audit(
			$artifactId,
			(int) ( $link['id'] ?? 0 ),
			$viewer,
			'ok',
			[
				'question'          => mb_substr( trim( $question ), 0, self::QUESTION_AUDIT_MAX ),
				'citations'         => self::citationRefs( $result['citations'] ),
				'provider'          => (string) ( $usage['provider'] ?? '' ),
				'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
				'cost_usd'          => Spend::estimateUsd( (int) ( $usage['total_tokens'] ?? 0 ), Spend::pricePer1k() ),
			]
		);

		return self::json(
			200,
			[
				'viewer'        => $viewer,
				'scope_granted' => $grants['scope_granted'],
				'answer'        => $result['answer'],
				'citations'     => $result['citations'],
				'usage'         => $usage,
			],
			$headers
		);
	}

	/**
	 * Map an engine failure to the JSON error contract: cost caps are 429
	 * with Retry-After, a missing provider is 503, any provider failure is
	 * 502 — all with sanitized messages (no keys, no endpoint internals, no
	 * raw transport errors). Every failure is audited.
	 *
	 * @param array<string, mixed> $viewer
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function errorResponse( \WP_Error $error, int $artifactId, int $linkId, array $viewer, string $question, int $now ): array {
		$code = $error->get_error_code();
		$meta = [ 'question' => mb_substr( trim( $question ), 0, self::QUESTION_AUDIT_MAX ), 'error' => $code ];

		if ( $code === Spend::REASON_ARTIFACT_CAP || $code === Spend::REASON_SITE_CAP ) {
			self::audit( $artifactId, $linkId, $viewer, 'denied', $meta + [ 'reason' => 'cost_cap' ] );

			$response                           = self::error( 429, 'ask_brain_cost_cap', 'The daily ask budget is exhausted. Try again tomorrow.' );
			$response['headers']['Retry-After'] = '3600';

			return $response;
		}

		if ( $code === 'invalid_question' ) {
			self::audit( $artifactId, $linkId, $viewer, 'denied', $meta );

			return self::error( 400, 'invalid_question', 'The question must be 1-' . Asker::QUESTION_MAX . ' characters long.' );
		}

		if ( $code === 'ab_ask_endpoint_unconfigured' || $code === 'ab_ask_wp_ai_unavailable' || $code === 'ab_ask_unavailable' ) {
			self::audit( $artifactId, $linkId, $viewer, 'error', $meta );

			return self::error( 503, 'ask_brain_unavailable', 'No AI provider is configured for this site.' );
		}

		self::audit( $artifactId, $linkId, $viewer, 'error', $meta );

		return self::error( 502, 'ask_brain_provider_error', 'The AI provider could not answer the question.' );
	}

	/**
	 * The presented viewer, or anonymous. Returns grants === null when a
	 * viewer_token was presented but does not verify for this link+artifact.
	 * Identical resolution to BrainRead\Route (ART-25).
	 *
	 * @param array<string, mixed> $link
	 * @return array{grants:array{allowed_scope_ids:array<int,int>,scope_granted:bool,anonymous:bool}|null, viewer:array<string, mixed>}
	 */
	private static function resolveViewer( string $method, string $uri, ?string $body, array $link, int $artifactId, int $scopeId, int $now ): array {
		$presented = self::presentedToken( $method, $uri, $body );

		if ( $presented === null ) {
			return [
				'grants' => Grants::resolve( null, $scopeId ),
				'viewer' => ViewerIdentity::anonymous(),
			];
		}

		$claims = ViewerToken::verify( $presented, ViewerKeys::all(), $now );
		if ( $claims === null
			|| $claims['artifact_id'] !== $artifactId
			|| $claims['share_link_id'] !== (int) ( $link['id'] ?? 0 )
		) {
			return [ 'grants' => null, 'viewer' => ViewerIdentity::anonymous() ];
		}

		// Fail closed exactly like Recall: any error in scope resolution, or
		// no read scopes at all, resolves to "no access".
		try {
			$readScopeIds = ( new Scopes() )->readScopeIds( $claims['principal_id'] );
		} catch ( Throwable $e ) {
			$readScopeIds = [];
		}

		return [
			'grants' => Grants::resolve( $readScopeIds, $scopeId ),
			'viewer' => [
				'principal_id'   => $claims['principal_id'],
				'principal_type' => $claims['principal_type'],
				'name'           => $claims['name'],
				'roles'          => $claims['roles'],
			],
		];
	}

	/**
	 * The viewer token from the POST body ({"viewer_token":"..."}) or the
	 * query string — the same two channels BrainRead offers.
	 */
	private static function presentedToken( string $method, string $uri, ?string $body ): ?string {
		$params = self::params( $method, $uri, $body );

		return isset( $params['viewer_token'] ) && is_string( $params['viewer_token'] ) && $params['viewer_token'] !== '' ? $params['viewer_token'] : null;
	}

	/**
	 * Query params, merged with the POST JSON body (body wins) when present.
	 *
	 * @return array<string, mixed>
	 */
	private static function params( string $method, string $uri, ?string $body ): array {
		$query = [];
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );

		if ( strtoupper( $method ) === 'POST' ) {
			$raw = $body;
			if ( $raw === null ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- no WP filesystem API for php://input; read-only.
				$raw = file_get_contents( 'php://input' );
			}
			$decoded = json_decode( is_string( $raw ) ? $raw : '', true );
			if ( is_array( $decoded ) ) {
				$query = array_merge( $query, $decoded );
			}
		}

		return $query;
	}

	/**
	 * @param array<int, array<string, mixed>> $citations
	 * @return array<int, array{kind:string, id:int}>
	 */
	private static function citationRefs( array $citations ): array {
		return array_map(
			static fn ( array $item ): array => [
				'kind' => (string) ( $item['kind'] ?? '' ),
				'id'   => (int) ( $item['id'] ?? 0 ),
			],
			$citations
		);
	}

	/**
	 * @param array<string, mixed> $viewer
	 * @param array<string, mixed> $meta
	 */
	private static function audit( int $artifactId, int $linkId, array $viewer, string $status, array $meta ): void {
		if ( isset( $viewer['anonymous'] ) && $viewer['anonymous'] === true ) {
			$meta['anonymous'] = true;
		} else {
			$meta['principal_id']   = (int) ( $viewer['principal_id'] ?? 0 );
			$meta['principal_type'] = (string) ( $viewer['principal_type'] ?? '' );
		}

		ShareLinks::audit( 0, 'ask_brain_question', $status, $linkId, [ 'artifact_id' => $artifactId ] + $meta );
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
	 * @param array<string, mixed>  $data
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
	private static function error( int $status, string $code, string $message ): array {
		return self::json(
			$status,
			[ 'error' => $code, 'message' => $message ],
			self::headers()
		);
	}
}
