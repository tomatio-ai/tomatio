<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Planning;

use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Integrations\Catalog;
use AgentBrain\Modules\Integrations\Context\Read;

/**
 * #992: save the target, run a dry run, approve it, or stop.
 * Curators only. A stored key is never read into the page.
 */
final class Actions {

	public const ACTION = 'ab_planning_sync';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		if ( ! current_user_can( SettingsHub::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage TomaTío settings.', 'tomatio' ), 403 );
		}
		check_admin_referer( self::ACTION );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$post    = wp_unslash( $_POST );
		$service = self::service( is_string( $post['service'] ?? null ) ? $post['service'] : '' );
		if ( $service === null ) {
			self::finish( '', false, __( 'That integration is not installed.', 'tomatio' ) );
		}
		$id = (string) $service['id'];
		$do = is_string( $post['do'] ?? null ) ? $post['do'] : '';
		$actor = (int) get_current_user_id();

		if ( $do === 'save' ) {
			self::save( $id, $post );
			self::finish( $id, true, __( 'Saved. Run a dry run before anything is written.', 'tomatio' ) );
		}
		if ( $do === 'preview' ) {
			$result = Apply::preview( $id, $actor );
			self::finish( $id, $result['ok'], $result['text'] );
		}
		if ( $do === 'approve' ) {
			$yes    = is_string( $post['fingerprint'] ?? null ) ? $post['fingerprint'] : '';
			$result = Apply::approve( $id, $actor, $yes );
			self::finish( $id, $result['ok'], $result['text'] );
		}
		if ( $do === 'stop' ) {
			$result = Apply::stop( $id, $actor );
			self::finish( $id, $result['ok'], $result['text'] );
		}

		self::finish( $id, false, __( 'That action is not available.', 'tomatio' ) );
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function save( string $service, array $post ): void {
		$row   = Library::row( $service );
		$raw   = is_string( $post['target'] ?? null ) ? $post['target'] : '';
		$kind  = is_string( $post['target_kind'] ?? null ) ? $post['target_kind'] : (string) $row['target_kind'];
		$kinds = $service === 'linear' ? [ 'team', 'project' ] : [ 'database' ];
		if ( ! in_array( $kind, $kinds, true ) ) {
			$kind = $kinds[0];
		}
		$locator = Read::locator( $service === 'linear' ? 'linear' : 'notion', $raw );
		if ( $locator !== '' ) {
			$row['target']      = $locator;
			$row['target_kind'] = $kind;
		}
		$via = is_string( $post['release_via'] ?? null ) ? $post['release_via'] : 'project';
		$row['release_via'] = $via === 'cycle' ? 'cycle' : 'project';
		$board = isset( $post['backlog_id'] ) ? absint( $post['backlog_id'] ) : 0;
		if ( $board > 0 ) {
			$row['backlog_id'] = $board;
		}
		$mapping = [];
		foreach ( [ 'title', 'detail', 'status', 'owner', 'release', 'rank' ] as $field ) {
			$name = is_string( $post[ 'map_' . $field ] ?? null ) ? trim( $post[ 'map_' . $field ] ) : '';
			if ( $name !== '' && strlen( $name ) <= 40 ) {
				$mapping[ $field ] = $name;
			}
		}
		$row['mapping']     = $mapping;
		$row['live']        = false;
		$row['fingerprint'] = '';
		Library::save( $service, $row );
	}

	/** @return array<string, mixed>|null */
	private static function service( string $id ): ?array {
		return ( new Catalog() )->get( sanitize_key( $id ) );
	}

	private static function finish( string $id, bool $ok, string $text ): never {
		set_transient( 'ab_int_notice_' . (int) get_current_user_id(), [ 'ok' => $ok, 'text' => $text ], 5 * MINUTE_IN_SECONDS );
		$url = SettingsHub::url( SettingsHub::TAB_INTEGRATIONS, [ 'ab_integration' => '1' ] );
		if ( $id !== '' ) {
			$url .= '#integration-' . rawurlencode( $id );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
