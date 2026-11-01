<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Forget;

use AgentBrain\Core\RestNamespaces;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Audit\Repository as AuditRepository;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Portability\Controller;
use AgentBrain\Modules\Portability\Principals;
use AgentBrain\Modules\Portability\ReceiptMapper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Management routes for erasure, shaped like PCP's docs/management-api.md:
 *
 *   POST agent-brain/v1/manage/erasures              erase (201 and the receipt), or a dry run (200)
 *   GET  agent-brain/v1/manage/erasures              receipts, newest first
 *   GET  agent-brain/v1/manage/erasures/{id}         one receipt
 *   POST agent-brain/v1/manage/erasures/{id}/verify  {subject} => {match}
 *
 * POST takes {kind: subject|pages|principal, subject?, paths?, ids?,
 * principal?, include_derived?: true, dry_run?: false}. Receipts come back
 * in the PCP shape (erasure.receipt.schema.json). Like /manage/exports,
 * only the browser login is accepted: a signed-in user who manages the
 * brain (ab_manage, as on the Forget page) with the wp_rest nonce. OAuth
 * bearer tokens, application passwords and any Authorization header are
 * refused.
 *
 * verify answers whether a subject is the one a receipt erased, by its
 * keyed digest. It is rate limited per user and audited without the subject.
 */
final class ErasureRest {

	public const NAMESPACE = 'agent-brain/v1';
	public const BASE      = '/manage/erasures';

	/** Verify calls per user per hour. */
	public const VERIFY_LIMIT = 20;

	private const ID = '(?P<id>[a-f0-9-]{36})';

	public static function registerRoutes(): void {
		RestNamespaces::register( self::BASE, [
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'create' ],
				'permission_callback' => [ self::class, 'permission' ],
			],
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'index' ],
				'permission_callback' => [ self::class, 'permission' ],
			],
		] );

		RestNamespaces::register( self::BASE . '/' . self::ID, [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'show' ],
			'permission_callback' => [ self::class, 'permission' ],
		] );

		RestNamespaces::register( self::BASE . '/' . self::ID . '/verify', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'verify' ],
			'permission_callback' => [ self::class, 'permission' ],
		] );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function permission( WP_REST_Request $request ) {
		$viaAppPassword = did_action( 'application_password_did_authenticate' ) > 0 || ! empty( $GLOBALS['wp_rest_application_password_uuid'] ?? null );
		if ( (string) $request->get_header( 'authorization' ) !== '' || $viaAppPassword || (bool) apply_filters( 'agent_brain/transport_oauth_authenticated', false ) ) {
			return self::error( 'forbidden', 'Management routes accept the browser login only. Tokens and application passwords are refused.', 401 );
		}

		if ( ! is_user_logged_in() ) {
			return self::error( 'forbidden', 'Sign in to WordPress first.', 401 );
		}

		$nonce = (string) ( $request->get_header( 'x_wp_nonce' ) ?? '' );
		if ( $nonce === '' ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}
		if ( $nonce === '' || wp_verify_nonce( $nonce, 'wp_rest' ) === false ) {
			return self::error( 'forbidden', 'The request needs the wp_rest nonce of a signed-in session.', 403 );
		}

		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return self::error( 'forbidden', 'Only people who manage this brain can erase.', 403 );
		}

		return true;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$body = array_merge( (array) $request->get_body_params(), (array) $request->get_json_params() );

		$input = [ 'kind' => (string) ( $body['kind'] ?? '' ) ];
		foreach ( [ 'subject', 'principal' ] as $key ) {
			if ( isset( $body[ $key ] ) && is_string( $body[ $key ] ) ) {
				$input[ $key ] = $body[ $key ];
			}
		}
		foreach ( [ 'paths', 'ids' ] as $key ) {
			if ( isset( $body[ $key ] ) ) {
				if ( ! is_array( $body[ $key ] ) ) {
					return self::error( 'invalid_input', $key . ' must be a list.', 400 );
				}
				$input[ $key ] = array_values( array_filter( $body[ $key ], 'is_string' ) );
			}
		}
		$input['include_derived'] = ! array_key_exists( 'include_derived', $body ) || (bool) $body['include_derived'];
		$input['dry_run']         = ! empty( $body['dry_run'] );

		$result = ( new Forgetter() )->erase( $input, get_current_user_id() );

		if ( $result instanceof WP_Error ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			$code   = match ( $result->get_error_code() ) {
				'ab_forbidden'    => 'forbidden',
				'ab_not_found'    => 'not_found',
				'ab_audit_failed' => 'internal',
				default           => 'invalid_input',
			};
			if ( $code === 'internal' ) {
				$status = 500;
			} elseif ( $code === 'forbidden' ) {
				$status = 403;
			}

			return self::error( $code, $result->get_error_message(), $status );
		}

		if ( ! empty( $result['dry_run'] ) ) {
			$response = new WP_REST_Response( [ 'dry_run' => true, 'would_erase' => (object) $result['would_erase'] ], 200 );
		} else {
			$response = new WP_REST_Response( self::view( $result ), 201 );
			$response->header( 'Location', rest_url( self::NAMESPACE . self::BASE . '/' . $result['id'] ) );
		}
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	public static function index( WP_REST_Request $request ): WP_REST_Response {
		$limit    = max( 1, min( 200, (int) ( $request->get_param( 'limit' ) ?? 50 ) ) );
		$items    = array_map( [ self::class, 'view' ], ReceiptRepository::recent( $limit ) );
		$response = new WP_REST_Response( [ 'items' => $items, 'next_cursor' => null ], 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function show( WP_REST_Request $request ) {
		$receipt = ReceiptRepository::get( (string) $request['id'] );
		if ( $receipt === null ) {
			return self::error( 'not_found', 'No such receipt.', 404 );
		}

		$response = new WP_REST_Response( self::view( $receipt ), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function verify( WP_REST_Request $request ) {
		$receipt = ReceiptRepository::get( (string) $request['id'] );
		if ( $receipt === null ) {
			return self::error( 'not_found', 'No such receipt.', 404 );
		}

		$body    = $request->get_json_params();
		$subject = is_array( $body ) && isset( $body['subject'] ) && is_string( $body['subject'] ) ? trim( $body['subject'] ) : '';
		if ( $subject === '' ) {
			return self::error( 'invalid_input', 'Give the subject to check.', 400 );
		}

		$userId = get_current_user_id();
		$key    = 'ab_erase_verify_' . $userId;
		$used   = (int) get_transient( $key );
		if ( $used >= self::VERIFY_LIMIT ) {
			return self::error( 'rate_limited', 'Too many checks; try again in an hour.', 429 );
		}
		set_transient( $key, $used + 1, HOUR_IN_SECONDS );

		$match = self::matches( $receipt, $subject );

		do_action( 'agent_brain/audit', [
			'actor_id'    => $userId,
			'event'       => 'erase.verify',
			'status'      => 'ok',
			'object_type' => 'ab_forget_receipt',
			'object_id'   => (string) $receipt['id'],
			'meta'        => [ 'match' => $match ],
		] );

		$response = new WP_REST_Response( [ 'match' => $match ], 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Whether $subject is the subject this receipt erased: only for subject
	 * receipts with a digest under the current key. A receipt from before
	 * keyed digests (decision 5 cleared its hash) never matches.
	 *
	 * @param array<string, mixed> $receipt
	 */
	public static function matches( array $receipt, string $subject ): bool {
		$digest = (string) ( $receipt['subject_digest'] ?? '' );

		return ( $receipt['kind'] ?? 'subject' ) === 'subject'
			&& $digest !== ''
			&& (string) ( $receipt['digest_key_id'] ?? '' ) === DigestKey::id()
			&& hash_equals( $digest, DigestKey::subjectDigest( $subject ) );
	}

	/**
	 * A receipt row in the PCP shape, with its audit entry ids.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function view( array $row ): array {
		$audit = AuditRepository::tableName();
		$ids   = Db::getResults(
			"SELECT id FROM {$audit} WHERE object_type = %s AND object_id = %s AND event IN (%s, %s, %s, %s) ORDER BY id ASC",
			array_merge( [ 'ab_forget_receipt', (string) ( $row['id'] ?? '' ), Forgetter::EVENT_REQUEST, Forgetter::EVENT_COMPLETE ], Forgetter::LEGACY_EVENTS )
		);

		return ReceiptMapper::receipt(
			$row,
			static fn ( int $userId ): ?string => Principals::idFor( $userId ),
			Controller::ensure(),
			array_map( static fn ( array $r ): string => 'ab-audit-' . (int) $r['id'], $ids )
		);
	}

	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
