<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Core\RestNamespaces;

/**
 * POST /wp-json/agent-brain/v1/share-links/{id}/viewer-code  (ART-24, #164)
 *
 * A logged-in brain member (person or agent) who may read the artifact asks
 * for a single-use viewer code for a members link of an artifact with
 * viewer identity enabled. The response carries the code and the URL
 * fragment to append to the share URL: the sandboxed artifact page reads the
 * fragment from location.hash and exchanges it for a signed viewer token at
 * viewer.json on the preview host.
 */
final class ViewerRest {

	public const NAMESPACE = 'agent-brain/v1';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'registerRoutes' ] );
	}

	public static function registerRoutes(): void {
		RestNamespaces::register(
			
			'/share-links/(?P<id>\d+)/viewer-code',
			[
				'methods'             => 'POST',
				'permission_callback' => static fn (): bool => is_user_logged_in(),
				'callback'            => [ self::class, 'issue' ],
				'args'                => [
					'id' => [ 'type' => 'integer', 'required' => true ],
				],
			]
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function issue( \WP_REST_Request $request ) {
		$result = ViewerExchange::issueCode( get_current_user_id(), (int) $request['id'] );

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 201 );
	}
}
