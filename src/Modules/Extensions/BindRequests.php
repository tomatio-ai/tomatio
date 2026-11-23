<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\StoreMode;
use AgentBrain\Modules\DataStore\StoreRules;
use AgentBrain\Modules\Identity\Capabilities;
use WP_Error;

/**
 * #994 (RFC artifact-extensions, section 5.2; decision 2): binding an
 * existing HTML page to an installed or bundled extension goes through
 * the Review queue.
 *
 * - Request: the page's lead, a curator or a manager, also through an
 *   agent (`extension-bind-request`, with a reason). Refused when the page
 *   is not an HTML page, already runs an extension, already waits, or the
 *   extension is neither bundled nor installed.
 * - The item shows the files (the page's approved version against the
 *   release), the store (every collection the page has must be in the
 *   manifest with the same rules or tighter; one it doesn't know, or
 *   gives looser rules, blocks the bind) and what changes.
 * - Approval: a curator or a manager who is a person, in wp-admin; never
 *   an agent, not even with act-as. The page is bound, records the
 *   extension it now comes from, and gets the release's files as a new
 *   version only when they differ. Store documents are never touched,
 *   and the page keeps its store settings.
 *
 * Requests are few and small, so they live in one option, like agent
 * proposals.
 *
 * A request nobody decided within EXPIRES_DAYS days expires: it is dropped
 * the next time the requests are read, or by the Review queue's daily job,
 * and the Audit log records extension.page_bind_expired. Forget (the
 * principal and subject kinds) and the WordPress personal data tools
 * (Tools > Export / Erase Personal Data) cover the requests a person made.
 */
final class BindRequests {

	public const OPTION      = 'ab_extension_bind_requests';
	public const MAX_PENDING = 20;
	public const REASON_MAX  = 500;

	/** Days a request waits for a decision before it expires. */
	public const EXPIRES_DAYS = 14;

	/** The store name Forget's receipts count bind requests under. */
	public const STORE = 'extension_bind_requests';

	/** The id of the WordPress personal data exporter and eraser. */
	public const PRIVACY_ID = 'tomatio-extension-bind-requests';

	/**
	 * Ask for $artifactId to run extension $extensionId.
	 *
	 * @return array{id:string, artifact_id:int, extension:string, version:string, reason:string, person_id:int, acting_id:int, created:string}|WP_Error
	 */
	public static function request( int $actorId, int $artifactId, string $extensionId, string $reason ) {
		$person = HtmlFiles::delegatedPerson( $actorId );
		if ( ! self::mayRequest( $actorId, $artifactId ) && ! ( $person > 0 && self::mayRequest( $person, $artifactId ) ) ) {
			return new WP_Error( 'ab_forbidden', __( 'Only the page\'s lead, a curator or a manager can ask to bind a page to an extension.', 'tomatio' ), [ 'status' => 403 ] );
		}
		if ( ! HtmlFiles::isHtml( $artifactId ) ) {
			return new WP_Error( 'ab_extension_not_html', __( 'Only an HTML page can be bound to an extension.', 'tomatio' ), [ 'status' => 409 ] );
		}
		if ( Binding::isBound( $artifactId ) ) {
			return new WP_Error( 'ab_extension_bound', __( 'This page already runs an extension.', 'tomatio' ), [ 'status' => 409 ] );
		}
		$package = Sandbox::package( $extensionId );
		if ( $package === null ) {
			return new WP_Error( 'ab_extension_unknown', __( 'No extension with that id is bundled with the plugin or installed on this brain.', 'tomatio' ), [ 'status' => 404 ] );
		}
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( $reason === '' || mb_strlen( $reason, 'UTF-8' ) > self::REASON_MAX ) {
			/* translators: %d: maximum length. */
			return new WP_Error( 'ab_invalid_input', sprintf( __( 'reason is required: one or two sentences on why, at most %d characters.', 'tomatio' ), self::REASON_MAX ), [ 'status' => 400 ] );
		}

		$pending = self::pending();
		foreach ( $pending as $row ) {
			if ( $row['artifact_id'] === $artifactId ) {
				return new WP_Error( 'ab_extension_bind_pending', __( 'A request to bind this page already waits in the Review queue.', 'tomatio' ), [ 'status' => 409 ] );
			}
		}
		if ( count( $pending ) >= self::MAX_PENDING ) {
			return new WP_Error( 'ab_too_many_requests', __( 'Too many bind requests are waiting. Ask a curator to review them first.', 'tomatio' ), [ 'status' => 429 ] );
		}

		$request = [
			'id'          => wp_generate_uuid4(),
			'artifact_id' => $artifactId,
			'extension'   => $package->id(),
			'version'     => $package->version(),
			'reason'      => $reason,
			'person_id'   => $person > 0 ? $person : $actorId,
			'acting_id'   => $actorId,
			'created'     => gmdate( 'Y-m-d H:i:s' ),
		];
		$all                   = self::stored();
		$all[ $request['id'] ] = $request;
		update_option( self::OPTION, $all, false );

		self::audit( $actorId, 'extension.page_bind_requested', $artifactId, [ 'request' => $request['id'], 'extension' => $package->id(), 'version' => $package->version() ] );

		return $request;
	}

	/** Lead (or the person behind it), curator or manager: HtmlFiles::canEdit(). */
	private static function mayRequest( int $userId, int $artifactId ): bool {
		return HtmlFiles::canEdit( $userId, $artifactId );
	}

	/** A curator or a manager who is a person (decision 2). */
	public static function canApprove( int $userId ): bool {
		$user = $userId > 0 ? get_userdata( $userId ) : false;
		if ( ! $user instanceof \WP_User || in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true ) ) {
			return false;
		}

		return ObjectAccess::userIsCurator( $userId ) || user_can( $user, Capabilities::CAP_MANAGE );
	}

	/**
	 * The requests that wait, oldest first; ones whose page is gone, or
	 * already runs an extension, are left out.
	 *
	 * @return array<string, array{id:string, artifact_id:int, extension:string, version:string, reason:string, person_id:int, acting_id:int, created:string}>
	 */
	public static function pending(): array {
		self::expire();
		$out = [];
		foreach ( self::stored() as $id => $row ) {
			$post = get_post( $row['artifact_id'] );
			if ( ! $post instanceof \WP_Post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE || $post->post_status === 'trash' || Binding::isBound( $row['artifact_id'] ) ) {
				continue;
			}
			$out[ $id ] = $row;
		}
		uasort( $out, static fn ( array $a, array $b ): int => [ $a['created'], $a['id'] ] <=> [ $b['created'], $b['id'] ] );

		return $out;
	}

	/**
	 * One waiting request, or null.
	 *
	 * @return array{id:string, artifact_id:int, extension:string, version:string, reason:string, person_id:int, acting_id:int, created:string}|null
	 */
	public static function get( string $id ): ?array {
		return self::pending()[ $id ] ?? null;
	}

	/**
	 * What the review item shows: the files of the page's approved version
	 * against the release, each store collection against the manifest, and
	 * what the page gains.
	 *
	 * @return array{
	 *   served:int,
	 *   same:bool,
	 *   differ:array<int, string>,
	 *   store_ok:bool,
	 *   store:array<int, array{collection:string, page:string, manifest:string, docs:int, ok:bool}>,
	 *   unknown:array<int, string>,
	 *   looser:array<int, string>,
	 *   flags:array<int, string>,
	 *   gains:string
	 * }
	 */
	public static function check( int $artifactId, Package $package ): array {
		$approved = HtmlFiles::approvedNumber( $artifactId );
		$row      = $approved > 0 ? HtmlFiles::version( $artifactId, $approved ) : null;
		$have     = [];
		if ( $row !== null ) {
			foreach ( $row['files'] as $path => $file ) {
				$have[ (string) $path ] = HtmlFiles::bytes( $file );
			}
		}
		$want   = $package->files();
		$differ = [];
		foreach ( array_unique( array_merge( array_keys( $have ), array_keys( $want ) ) ) as $path ) {
			$path = (string) $path;
			if ( ! isset( $have[ $path ], $want[ $path ] ) || $have[ $path ] !== $want[ $path ] ) {
				$differ[] = $path;
			}
		}
		sort( $differ );

		$manifest = $package->collections();
		$config   = StoreMode::collections( $artifactId );
		$docs     = [];
		foreach ( StoreData::collections( $artifactId ) as $stats ) {
			$docs[ $stats['collection'] ] = $stats['docs'];
		}
		$names = array_unique( array_merge( array_keys( $config ), array_keys( $docs ) ) );
		sort( $names );

		$store   = [];
		$unknown = [];
		$looser  = [];
		foreach ( $names as $name ) {
			$name  = (string) $name;
			$known = array_key_exists( $name, $manifest );
			if ( ! $known ) {
				$unknown[] = $name;
			}
			// RFC 5.2: the manifest's rules may be the same as the page's or tighter, never looser.
			$rules = $known ? StoreRules::normalize( $manifest[ $name ] ) : null;
			$wider = $known && isset( $config[ $name ] ) && ( ! is_array( $rules ) || StoreRules::looserThan( $rules, $config[ $name ] ) );
			if ( $wider ) {
				$looser[] = $name;
			}
			$store[] = [
				'collection' => $name,
				'page'       => isset( $config[ $name ] ) ? (string) ( $config[ $name ]['preset'] ?? '' ) : '',
				'manifest'   => $known ? self::presetOf( $manifest[ $name ] ) : '',
				'docs'       => (int) ( $docs[ $name ] ?? 0 ),
				'ok'         => $known && ! $wider,
			];
		}

		$flags = Sandbox::baseOnly( $package->id() ) ? [] : array_values( array_intersect( Sandbox::ALLOWED, $package->sandbox(), Sandbox::approved( $package->id() ) ) );

		return [
			'served'   => $approved,
			'same'     => $row !== null && $differ === [],
			'differ'   => $differ,
			'store_ok' => $unknown === [] && $looser === [],
			'store'    => $store,
			'unknown'  => $unknown,
			'looser'   => $looser,
			'flags'    => $flags,
			'gains'    => Sandbox::describe( $flags ),
		];
	}

	/** @param mixed $entry A manifest collection entry: a preset name or {preset, ...}. */
	private static function presetOf( $entry ): string {
		if ( is_string( $entry ) ) {
			return $entry;
		}

		return is_array( $entry ) && is_string( $entry['preset'] ?? null ) ? $entry['preset'] : '';
	}

	/**
	 * A curator or manager binds the page.
	 *
	 * @return true|WP_Error
	 */
	public static function approve( int $actorId, string $id ) {
		if ( ! self::canApprove( $actorId ) ) {
			return new WP_Error( 'ab_forbidden', __( 'Only a curator or a manager can bind a page to an extension, and never through an AI.', 'tomatio' ), [ 'status' => 403 ] );
		}
		$request = self::get( $id );
		if ( $request === null ) {
			return new WP_Error( 'ab_not_found', __( 'That request is no longer waiting.', 'tomatio' ), [ 'status' => 404 ] );
		}
		$artifactId = $request['artifact_id'];
		if ( ! HtmlFiles::isHtml( $artifactId ) ) {
			return new WP_Error( 'ab_extension_not_html', __( 'Only an HTML page can be bound to an extension.', 'tomatio' ), [ 'status' => 409 ] );
		}
		$package = Sandbox::package( $request['extension'] );
		if ( $package === null ) {
			return new WP_Error( 'ab_extension_unknown', __( 'That extension is no longer bundled or installed.', 'tomatio' ), [ 'status' => 404 ] );
		}

		$check = self::check( $artifactId, $package );
		if ( $check['unknown'] === [] && $check['looser'] !== [] ) {
			return new WP_Error(
				'ab_extension_store_looser',
				sprintf(
					/* translators: %s: comma-separated collection names. */
					__( 'The extension\'s manifest gives these store collections looser rules than the page has: %s. It can be bound once the manifest gives them the same rules or tighter.', 'tomatio' ),
					implode( ', ', $check['looser'] )
				),
				[ 'status' => 409 ]
			);
		}
		if ( ! $check['store_ok'] ) {
			return new WP_Error(
				'ab_extension_store_mismatch',
				sprintf(
					/* translators: %s: comma-separated collection names. */
					__( 'The page has store collections the extension does not list: %s. It can be bound once the extension\'s manifest lists them.', 'tomatio' ),
					implode( ', ', $check['unknown'] )
				),
				[ 'status' => 409 ]
			);
		}

		$version = $check['served'];
		if ( ! $check['same'] ) {
			$put = HtmlFiles::putBySystem( $artifactId, $package->htmlFiles(), $package->name() . ' ' . $package->version() . ', the extension\'s files on binding' );
			if ( $put instanceof WP_Error ) {
				return $put;
			}
			$approved = HtmlFiles::approveBySystem( $artifactId, (int) $put['version'] );
			if ( $approved instanceof WP_Error ) {
				return $approved;
			}
			$version = (int) $put['version'];
		}

		Binding::bind( $artifactId, $package );
		update_post_meta( $artifactId, PageMaker::META_FROM, $package->id() );
		update_post_meta( $artifactId, PageMaker::META_VERSION, $package->version() );
		self::forget( $id );

		self::audit(
			$actorId,
			'extension.page_bind_approved',
			$artifactId,
			[
				'request'      => $id,
				'extension'    => $package->id(),
				'version'      => $package->version(),
				'files'        => $check['same'] ? 'identical' : 'new version',
				'served'       => $version,
				'flags'        => $check['flags'],
				'requested_by' => $request['acting_id'],
			]
		);

		return true;
	}

	/**
	 * A curator or manager says no; the page stays as it is.
	 *
	 * @return true|WP_Error
	 */
	public static function refuse( int $actorId, string $id, string $why = '' ) {
		if ( ! self::canApprove( $actorId ) ) {
			return new WP_Error( 'ab_forbidden', __( 'Only a curator or a manager can decide a bind request, and never through an AI.', 'tomatio' ), [ 'status' => 403 ] );
		}
		$request = self::get( $id );
		if ( $request === null ) {
			return new WP_Error( 'ab_not_found', __( 'That request is no longer waiting.', 'tomatio' ), [ 'status' => 404 ] );
		}
		self::forget( $id );

		self::audit( $actorId, 'extension.page_bind_refused', $request['artifact_id'], [ 'request' => $id, 'extension' => $request['extension'], 'why' => sanitize_text_field( $why ) ] );

		return true;
	}

	/**
	 * Whether $row waited longer than EXPIRES_DAYS. A request whose time
	 * cannot be read counts as expired.
	 *
	 * @param array{created:string} $row
	 */
	public static function isExpired( array $row, ?int $now = null ): bool {
		$created = strtotime( $row['created'] . ' UTC' );

		return $created === false || ( $now ?? time() ) - $created >= self::EXPIRES_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Drop the requests that expired, auditing each. Runs whenever the
	 * requests are read and on the Review queue's daily job.
	 *
	 * @return int How many expired.
	 */
	public static function expire( ?int $now = null ): int {
		$all     = self::stored();
		$expired = array_filter( $all, static fn ( array $row ): bool => self::isExpired( $row, $now ) );
		if ( $expired === [] ) {
			return 0;
		}
		update_option( self::OPTION, array_diff_key( $all, $expired ), false );
		foreach ( $expired as $id => $row ) {
			self::audit(
				0,
				'extension.page_bind_expired',
				$row['artifact_id'],
				[ 'request' => (string) $id, 'extension' => $row['extension'], 'created' => $row['created'], 'days' => self::EXPIRES_DAYS, 'requested_by' => $row['acting_id'] ]
			);
		}

		return count( $expired );
	}

	/** The Review queue's daily job (Review\Expiry::HOOK) returns nothing. */
	public static function expireDaily(): void {
		self::expire();
	}

	/**
	 * The ids of the requests $userId made, themselves or through one of
	 * their agents, or that their agent made for someone (Forget, principal).
	 *
	 * @return array<int, string>
	 */
	public static function idsBy( int $userId ): array {
		if ( $userId <= 0 ) {
			return [];
		}
		$ids = [];
		foreach ( self::stored() as $id => $row ) {
			if ( $row['person_id'] === $userId || $row['acting_id'] === $userId ) {
				$ids[] = (string) $id;
			}
		}

		return $ids;
	}

	/**
	 * The ids of the requests whose reason holds $subject as a phrase,
	 * case-insensitively (Forget, subject).
	 *
	 * @return array<int, string>
	 */
	public static function idsMatching( string $subject ): array {
		$subject = trim( $subject );
		if ( $subject === '' ) {
			return [];
		}
		$ids = [];
		foreach ( self::stored() as $id => $row ) {
			if ( mb_stripos( $row['reason'], $subject, 0, 'UTF-8' ) !== false ) {
				$ids[] = (string) $id;
			}
		}

		return $ids;
	}

	/**
	 * Erasure: drop requests without a decision. The caller audits the
	 * erasure; nothing is written to the Audit log here.
	 *
	 * @param array<int, int|string> $ids
	 * @return int Requests removed.
	 */
	public static function remove( array $ids ): int {
		$all     = self::stored();
		$removed = 0;
		foreach ( $ids as $id ) {
			if ( isset( $all[ (string) $id ] ) ) {
				unset( $all[ (string) $id ] );
				++$removed;
			}
		}
		if ( $removed > 0 ) {
			update_option( self::OPTION, $all, false );
		}

		return $removed;
	}

	/**
	 * `tomatio/forget_collect`: requests whose reason names the subject.
	 *
	 * @param mixed $targets
	 * @return array<int, mixed>
	 */
	public static function forgetCollect( $targets, string $subject = '' ): array {
		$targets = is_array( $targets ) ? array_values( $targets ) : [];
		foreach ( self::idsMatching( $subject ) as $id ) {
			$targets[] = [ 'store' => self::STORE, 'id' => $id ];
		}

		return $targets;
	}

	/**
	 * `tomatio/forget_delete`, for our store only.
	 *
	 * @param array<int, int|string> $ids
	 */
	public static function forgetDelete( string $store, array $ids ): void {
		if ( $store === self::STORE ) {
			self::remove( $ids );
		}
	}

	/**
	 * Tools > Export Personal Data lists the requests a person made.
	 *
	 * @param mixed $exporters The registered exporters.
	 * @return array<mixed>
	 */
	public static function privacyExporters( $exporters ): array {
		$exporters                     = is_array( $exporters ) ? $exporters : [];
		$exporters[ self::PRIVACY_ID ] = [
			'exporter_friendly_name' => __( 'TomaTío requests to bind a page to an extension', 'tomatio' ),
			'callback'               => [ self::class, 'privacyExport' ],
		];

		return $exporters;
	}

	/**
	 * Tools > Erase Personal Data drops the requests a person made.
	 *
	 * @param mixed $erasers The registered erasers.
	 * @return array<mixed>
	 */
	public static function privacyErasers( $erasers ): array {
		$erasers                     = is_array( $erasers ) ? $erasers : [];
		$erasers[ self::PRIVACY_ID ] = [
			'eraser_friendly_name' => __( 'TomaTío requests to bind a page to an extension', 'tomatio' ),
			'callback'             => [ self::class, 'privacyErase' ],
		];

		return $erasers;
	}

	/**
	 * @return array{data:array<int, array{group_id:string, group_label:string, item_id:string, data:array<int, array{name:string, value:string}>}>, done:bool}
	 */
	public static function privacyExport( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		$data = [];
		if ( $user instanceof \WP_User && $page <= 1 ) {
			$all = self::stored();
			foreach ( self::idsBy( (int) $user->ID ) as $id ) {
				$row    = $all[ $id ];
				$data[] = [
					'group_id'    => 'tomatio-extension-bind-requests',
					'group_label' => __( 'TomaTío requests to bind a page to an extension', 'tomatio' ),
					'item_id'     => 'tomatio-extension-bind-request-' . $id,
					'data'        => [
						[ 'name' => __( 'Page', 'tomatio' ), 'value' => '#' . $row['artifact_id'] ],
						[ 'name' => __( 'Extension', 'tomatio' ), 'value' => $row['extension'] . ' ' . $row['version'] ],
						[ 'name' => __( 'Reason', 'tomatio' ), 'value' => $row['reason'] ],
						[ 'name' => __( 'Asked (UTC)', 'tomatio' ), 'value' => $row['created'] ],
					],
				];
			}
		}

		return [ 'data' => $data, 'done' => true ];
	}

	/**
	 * @return array{items_removed:bool, items_retained:bool, messages:array<int, string>, done:bool}
	 */
	public static function privacyErase( string $email, int $page = 1 ): array {
		$user    = get_user_by( 'email', $email );
		$removed = $user instanceof \WP_User ? self::remove( self::idsBy( (int) $user->ID ) ) : 0;
		if ( $removed > 0 ) {
			\AgentBrain\Core\Hooks::action(
				'audit',
				[
					'actor_id'    => (int) get_current_user_id(),
					'event'       => 'extension.page_bind_requests_erased',
					'status'      => 'ok',
					'object_type' => 'user',
					'object_id'   => (string) ( $user instanceof \WP_User ? $user->ID : 0 ),
					'meta'        => [ 'removed' => $removed ],
				]
			);
		}

		return [
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => [ __( 'TomaTío memories, pages and other work are erased from TomaTío > Forget.', 'tomatio' ) ],
			'done'           => true,
		];
	}

	private static function forget( string $id ): void {
		$all = self::stored();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * @return array<string, array{id:string, artifact_id:int, extension:string, version:string, reason:string, person_id:int, acting_id:int, created:string}>
	 */
	private static function stored(): array {
		$raw = get_option( self::OPTION, [] );
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null ) ) {
				continue;
			}
			$out[ $row['id'] ] = [
				'id'          => $row['id'],
				'artifact_id' => (int) ( $row['artifact_id'] ?? 0 ),
				'extension'   => (string) ( $row['extension'] ?? '' ),
				'version'     => (string) ( $row['version'] ?? '' ),
				'reason'      => (string) ( $row['reason'] ?? '' ),
				'person_id'   => (int) ( $row['person_id'] ?? 0 ),
				'acting_id'   => (int) ( $row['acting_id'] ?? 0 ),
				'created'     => (string) ( $row['created'] ?? '' ),
			];
		}

		return $out;
	}

	/** @param array<string, mixed> $meta */
	private static function audit( int $actorId, string $event, int $artifactId, array $meta ): void {
		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actorId,
				'event'       => $event,
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => $meta,
			]
		);
	}
}
