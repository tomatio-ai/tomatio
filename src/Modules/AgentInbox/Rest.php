<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentInbox;

use AgentBrain\Core\RestNamespaces;
use AgentBrain\Modules\AgentId\TaskTakers;
use AgentBrain\Modules\DataStore\TioDayAssigned;
use AgentBrain\Modules\Identity\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * #1060: the inbox and timers over REST, for an agent runtime that is not
 * on MCP (such as a Hermes cron job). Authenticated as the task feed is
 * (DataStore\TioDayAssigned): signed in as the agent with an application
 * password. GET /agent-inbox also takes ?feed=<the agent's task feed
 * token> and then answers only {pending, working, lease_until}, so a
 * leaked feed address shows a count and nothing else.
 *
 *   GET    /tomatio/v1/agent-inbox              peek
 *   POST   /tomatio/v1/agent-inbox/claim        {lease_minutes?}
 *   POST   /tomatio/v1/agent-inbox/done         {claim_id, ids? | all?, failed?, note?}
 *   POST   /tomatio/v1/agent-inbox/wake         {url, agent_id? (curators)}
 *   GET    /tomatio/v1/agent-inbox/timers       list
 *   POST   /tomatio/v1/agent-inbox/timers       set (as timer-set)
 *   DELETE /tomatio/v1/agent-inbox/timers/<id>  cancel
 *
 * The agent-brain/v1 spelling serves the same routes (RestNamespaces).
 */
final class Rest {

	public const ROUTE = '/agent-inbox';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		// Every callback decides who is asking (the signed-in agent, or a feed token for peek).
		$open = '__return_true';

		RestNamespaces::register( self::ROUTE, [ 'methods' => 'GET', 'callback' => [ self::class, 'peek' ], 'permission_callback' => $open, 'args' => [ 'feed' => [ 'type' => 'string' ] ] ] );
		RestNamespaces::register( self::ROUTE . '/claim', [ 'methods' => 'POST', 'callback' => [ self::class, 'claim' ], 'permission_callback' => $open ] );
		RestNamespaces::register( self::ROUTE . '/done', [ 'methods' => 'POST', 'callback' => [ self::class, 'done' ], 'permission_callback' => $open ] );
		RestNamespaces::register( self::ROUTE . '/wake', [ 'methods' => 'POST', 'callback' => [ self::class, 'wake' ], 'permission_callback' => $open ] );
		RestNamespaces::register(
			self::ROUTE . '/timers',
			[
				[ 'methods' => 'GET', 'callback' => [ self::class, 'timers' ], 'permission_callback' => $open ],
				[ 'methods' => 'POST', 'callback' => [ self::class, 'timerSet' ], 'permission_callback' => $open ],
			]
		);
		RestNamespaces::register( self::ROUTE . '/timers/(?P<id>\d+)', [ 'methods' => 'DELETE', 'callback' => [ self::class, 'timerCancel' ], 'permission_callback' => $open ] );
	}

	/** @return WP_REST_Response|WP_Error */
	public static function peek( WP_REST_Request $request ) {
		$feed = $request->get_param( 'feed' );
		if ( is_string( $feed ) && $feed !== '' ) {
			$agentId = TioDayAssigned::agentForFeed( $feed );
			if ( $agentId <= 0 ) {
				return new WP_Error( 'ab_not_found', __( 'No such task feed.', 'tomatio' ), [ 'status' => 404 ] );
			}
			$peek = Inbox::peek( $agentId );

			return self::reply( [ 'pending' => $peek['pending'], 'working' => $peek['working'], 'lease_until' => $peek['lease_until'] ] );
		}

		$agentId = self::agent();

		return $agentId instanceof WP_Error ? $agentId : self::reply( Inbox::peek( $agentId ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public static function claim( WP_REST_Request $request ) {
		$agentId = self::agent();
		if ( $agentId instanceof WP_Error ) {
			return $agentId;
		}
		$lease = $request->get_param( 'lease_minutes' );

		return self::reply( Inbox::claim( $agentId, is_numeric( $lease ) ? (int) $lease : Inbox::DEFAULT_LEASE_MINUTES ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public static function done( WP_REST_Request $request ) {
		$agentId = self::agent();
		if ( $agentId instanceof WP_Error ) {
			return $agentId;
		}
		$result = Abilities::done( self::params( $request ), $agentId );

		return $result instanceof WP_Error ? $result : self::reply( $result );
	}

	/** @return WP_REST_Response|WP_Error */
	public static function wake( WP_REST_Request $request ) {
		$actingId = (int) get_current_user_id();
		if ( $actingId <= 0 ) {
			return self::signIn();
		}
		$agentId = (int) ( $request->get_param( 'agent_id' ) ?? $actingId );
		if ( $agentId !== $actingId && ! user_can( $actingId, Capabilities::CAP_MANAGE ) ) {
			return new WP_Error( 'ab_forbidden', __( 'Only a curator may set another agent\'s wake address.', 'tomatio' ), [ 'status' => 403 ] );
		}
		$url    = $request->get_param( 'url' );
		$result = Wake::set( $actingId, $agentId, is_string( $url ) ? $url : '' );

		return $result instanceof WP_Error ? $result : self::reply( $result );
	}

	/** @return WP_REST_Response|WP_Error */
	public static function timers( WP_REST_Request $request ) {
		$agentId = self::agent();

		return $agentId instanceof WP_Error ? $agentId : self::reply( [ 'timers' => Timers::list( $agentId ), 'max' => Timers::MAX_ACTIVE ] );
	}

	/** @return WP_REST_Response|WP_Error */
	public static function timerSet( WP_REST_Request $request ) {
		$agentId = self::agent();
		if ( $agentId instanceof WP_Error ) {
			return $agentId;
		}
		$timer = Timers::set( $agentId, self::params( $request ) );

		return $timer instanceof WP_Error ? $timer : self::reply( $timer, 201 );
	}

	/** @return WP_REST_Response|WP_Error */
	public static function timerCancel( WP_REST_Request $request ) {
		$agentId = self::agent();
		if ( $agentId instanceof WP_Error ) {
			return $agentId;
		}
		$id = (int) $request->get_param( 'id' );

		return Timers::cancel( $agentId, $id )
			? self::reply( [ 'id' => $id, 'cancelled' => true ] )
			: new WP_Error( 'ab_not_found', __( 'You have no active timer with that id.', 'tomatio' ), [ 'status' => 404 ] );
	}

	/**
	 * The signed-in agent that takes tasks, or why not.
	 *
	 * @return int|WP_Error
	 */
	private static function agent() {
		$agentId = (int) get_current_user_id();
		if ( $agentId <= 0 ) {
			return self::signIn();
		}
		if ( ! TaskTakers::takes( $agentId ) ) {
			return new WP_Error( 'ab_forbidden', __( 'Only an agent that takes tasks has an inbox.', 'tomatio' ), [ 'status' => 403 ] );
		}

		return $agentId;
	}

	private static function signIn(): WP_Error {
		return new WP_Error( 'ab_unauthorized', __( 'Sign in as the agent with an application password.', 'tomatio' ), [ 'status' => 401 ] );
	}

	/** @return array<string, mixed> */
	private static function params( WP_REST_Request $request ): array {
		$json = $request->get_json_params();

		return is_array( $json ) && $json !== [] ? $json : $request->get_params();
	}

	/** @param array<string, mixed> $body */
	private static function reply( array $body, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $body, $status, [ 'Cache-Control' => 'private, no-store' ] );
	}
}
