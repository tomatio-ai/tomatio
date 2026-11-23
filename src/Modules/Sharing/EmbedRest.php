<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Core\RestNamespaces;

/**
 * POST /wp-json/agent-brain/v1/share-links/{id}/embed-code
 *
 * A logged-in person who may read the artifact asks for a single-use code
 * for an embed link and one allowlisted embedding origin. The embedding page
 * then loads the returned url in its iframe; the code works once, for 60
 * seconds.
 */
final class EmbedRest {

	public const NAMESPACE = 'agent-brain/v1';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'registerRoutes' ] );
	}

	public static function registerRoutes(): void {
		RestNamespaces::register(
			
			'/share-links/(?P<id>\d+)/embed-code',
			[
				'methods'             => 'POST',
				'permission_callback' => static fn (): bool => is_user_logged_in(),
				'callback'            => [ self::class, 'issue' ],
				'args'                => [
					'id'     => [ 'type' => 'integer', 'required' => true ],
					'origin' => [ 'type' => 'string', 'required' => true ],
				],
			]
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function issue( \WP_REST_Request $request ) {
		$result = EmbedExchange::issueCode( get_current_user_id(), (int) $request['id'], (string) $request['origin'] );

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 201 );
	}
}
