<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Sharing\AppIcon;
use AgentBrain\Modules\Sharing\Config;

/**
 * ART-23 (#163): opt-in shared data store for an artifact's share pages.
 *
 * The mode is a property of the ARTIFACT (post meta), evaluated on every
 * request, never stored on a link — the same pattern as interactive mode
 * (#61) and viewer identity (#164): a link minted while the flag is off
 * serves no store API until the flag (and the kill switch) allow it.
 */
final class StoreMode {

	public const META_FLAG        = 'ab_artifact_data_store';
	public const META_COLLECTIONS = 'ab_artifact_data_store_collections';
	public const OPT_DISABLED     = 'ab_data_store_disabled';
	// #469: seats, personal links on one public link (StoreSeats).
	public const META_SEATS = 'ab_artifact_data_store_seats';
	// How a seat's install icon differs from the link's (Sharing\AppIcon::STYLES); absent means recolor.
	public const META_SEAT_ICON = 'ab_artifact_seat_icon_style';

	public static function killSwitchOn(): bool {
		return (bool) get_option( self::OPT_DISABLED, false );
	}

	public static function isFlagged( int $artifactId ): bool {
		return get_post_meta( $artifactId, self::META_FLAG, true ) === '1';
	}

	/**
	 * True only when the kill switch is off, sharing is enabled and the
	 * artifact opted in.
	 */
	public static function isActive( int $artifactId ): bool {
		return ! self::killSwitchOn() && ! Config::disabled() && self::isFlagged( $artifactId );
	}

	/**
	 * Curator (or ab_manage) only; being the lead agent is not enough —
	 * identical to the interactive-mode and viewer-identity toggles.
	 */
	public static function canToggle( int $actorId ): bool {
		return $actorId > 0 && ( ObjectAccess::userIsCurator( $actorId ) || user_can( $actorId, Capabilities::CAP_MANAGE ) );
	}

	/**
	 * #384: the collections share pages may use, name => rules
	 * (StoreRules). Default deny: an artifact without a list allows none.
	 * Stored entries are normalized again on read, so a list saved before
	 * #384 ("read", "write") reads as the read and shared presets, and an
	 * entry that no longer validates allows nothing rather than everything.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function collections( int $artifactId ): array {
		$raw     = get_post_meta( $artifactId, self::META_COLLECTIONS, true );
		$decoded = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : null;
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$out = [];
		foreach ( $decoded as $name => $entry ) {
			$name  = is_string( $name ) ? StoreData::normalizeCollection( $name ) : null;
			$rules = StoreRules::normalize( $entry );
			if ( $name !== null && is_array( $rules ) ) {
				$out[ $name ] = $rules;
			}
		}

		return $out;
	}

	/**
	 * The rules of one collection, or null when pages may not use it.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function rules( int $artifactId, string $collection ): ?array {
		return self::collections( $artifactId )[ $collection ] ?? null;
	}

	/**
	 * A {collection: preset | {preset, ...overrides}} map as full rules,
	 * sorted by name, or the first reason it is not valid (so a typo never
	 * silently drops or widens a collection).
	 *
	 * @param array<mixed> $input
	 * @return array<string, array<string, mixed>>|string
	 */
	public static function normalizeCollections( array $input ) {
		if ( count( $input ) > StoreData::MAX_COLLECTIONS ) {
			return sprintf( 'at most %d collections', StoreData::MAX_COLLECTIONS );
		}

		$out = [];
		foreach ( $input as $name => $entry ) {
			$normal = is_string( $name ) ? StoreData::normalizeCollection( $name ) : null;
			if ( $normal === null ) {
				return '"' . $name . '" is not a collection name (lowercase letters, digits, - and _)';
			}
			$rules = StoreRules::normalize( $entry );
			if ( is_string( $rules ) ) {
				return $normal . ': ' . $rules;
			}
			$out[ $normal ] = $rules;
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Replace the collection list (curator or ab_manage only); an empty list
	 * allows none.
	 *
	 * @param array<string, array<string, mixed>> $collections Already normalized.
	 * @return true|\WP_Error
	 */
	public static function setCollections( int $artifactId, array $collections, int $actorId ) {
		$post = get_post( $artifactId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE ) {
			return new \WP_Error( 'ab_not_found', __( 'Artifact not found.', 'agent-brain' ), [ 'status' => 404 ] );
		}

		if ( ! self::canToggle( $actorId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( self::collections( $artifactId ) === $collections ) {
			return true;
		}

		if ( $collections === [] ) {
			delete_post_meta( $artifactId, self::META_COLLECTIONS );
		} else {
			update_post_meta( $artifactId, self::META_COLLECTIONS, wp_slash( (string) wp_json_encode( $collections ) ) );
		}

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'artifact_datastore_collections_set',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'collections' => array_map( static fn ( array $rules ): string => (string) $rules['preset'], $collections ) ],
			]
		);

		return true;
	}

	public static function seatsOn( int $artifactId ): bool {
		return get_post_meta( $artifactId, self::META_SEATS, true ) === '1';
	}

	/**
	 * How a seat's Home Screen icon differs from the link's own: recolor
	 * (background and badge in the seat's colour, the default), badge (a
	 * letter badge only) or off.
	 */
	public static function seatIconStyle( int $artifactId ): string {
		return AppIcon::style( (string) get_post_meta( $artifactId, self::META_SEAT_ICON, true ) );
	}

	/**
	 * Curator or ab_manage only, like seats.
	 *
	 * @return true|\WP_Error
	 */
	public static function setSeatIconStyle( int $artifactId, string $style, int $actorId ) {
		$post = get_post( $artifactId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE ) {
			return new \WP_Error( 'ab_not_found', __( 'Artifact not found.', 'agent-brain' ), [ 'status' => 404 ] );
		}

		if ( ! self::canToggle( $actorId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( ! in_array( $style, AppIcon::STYLES, true ) ) {
			return new \WP_Error( 'ab_store_invalid_seat_icon', __( 'seat_icon is recolor, badge or off.', 'agent-brain' ), [ 'status' => 400 ] );
		}

		if ( self::seatIconStyle( $artifactId ) === $style ) {
			return true;
		}

		if ( $style === AppIcon::STYLE_RECOLOR ) {
			delete_post_meta( $artifactId, self::META_SEAT_ICON );
		} else {
			update_post_meta( $artifactId, self::META_SEAT_ICON, $style );
		}

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'artifact_datastore_seat_icon_set',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'style' => $style ],
			]
		);

		return true;
	}

	/**
	 * Seats on or off (curator or ab_manage only). Off keeps the claimed
	 * seats: turning it back on brings their personal links back.
	 *
	 * @return true|\WP_Error
	 */
	public static function setSeats( int $artifactId, bool $on, int $actorId ) {
		$post = get_post( $artifactId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE ) {
			return new \WP_Error( 'ab_not_found', __( 'Artifact not found.', 'agent-brain' ), [ 'status' => 404 ] );
		}

		if ( ! self::canToggle( $actorId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( self::seatsOn( $artifactId ) === $on ) {
			return true;
		}

		if ( $on ) {
			update_post_meta( $artifactId, self::META_SEATS, '1' );
		} else {
			delete_post_meta( $artifactId, self::META_SEATS );
		}

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => $on ? 'artifact_datastore_seats_on' : 'artifact_datastore_seats_off',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [],
			]
		);

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set( int $artifactId, bool $on, int $actorId ) {
		$post = get_post( $artifactId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE ) {
			return new \WP_Error( 'ab_not_found', __( 'Artifact not found.', 'agent-brain' ), [ 'status' => 404 ] );
		}

		if ( ! self::canToggle( $actorId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( self::isFlagged( $artifactId ) === $on ) {
			return true;
		}

		if ( $on ) {
			update_post_meta( $artifactId, self::META_FLAG, '1' );
		} else {
			delete_post_meta( $artifactId, self::META_FLAG );
		}

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => $on ? 'artifact_datastore_enabled' : 'artifact_datastore_disabled',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [],
			]
		);

		return true;
	}
}
