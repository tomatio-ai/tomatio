<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Planning;

/**
 * #992: one planning sync per service. The sealed key is not stored
 * here. Links remember which Magic Backlog item belongs to which service row,
 * and which rows were unlinked so a removed item is not pulled back.
 */
final class Library {

	public const OPTION = 'ab_planning_sync';

	/**
	 * @return array<string, mixed>
	 */
	public static function blank( string $service ): array {
		return [
			'service'         => $service,
			'target'          => '',
			'target_kind'     => $service === 'linear' ? 'team' : 'database',
			'release_via'     => 'project',
			'backlog_id'      => 0,
			'mapping'         => [],
			'links'           => [],
			'unlinked_remote' => [],
			'unlinked_board'  => [],
			'live'            => false,
			'fingerprint'     => '',
			'report'          => '',
			'actor_id'        => 0,
			'ran_at'          => '',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function row( string $service ): array {
		$all = self::all();
		$raw = is_array( $all[ $service ] ?? null ) ? $all[ $service ] : [];

		return array_merge( self::blank( $service ), $raw );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function save( string $service, array $row ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}
		$all              = self::all();
		$row['service']   = $service;
		$all[ $service ]  = $row;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return [];
		}
		$raw = get_option( self::OPTION, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $id => $row ) {
			if ( is_string( $id ) && is_array( $row ) ) {
				$out[ $id ] = $row;
			}
		}

		return $out;
	}
}
