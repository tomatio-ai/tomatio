<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Context;

use AgentBrain\Modules\Admin\ScopeVisibility;
use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Integrations\Catalog;

/**
 * #991: preview, store, refresh and stop from Settings > Integrations.
 * Curators only. A stored key is never read into the page.
 */
final class Actions {

	public const ACTION = 'ab_context_source';

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

		if ( $do === 'preview' ) {
			$kind    = is_string( $post['kind'] ?? null ) ? $post['kind'] : '';
			$locator = is_string( $post['locator'] ?? null ) ? $post['locator'] : '';
			$scopeId = isset( $post['scope_id'] ) ? absint( $post['scope_id'] ) : 0;
			if ( ! ScopeVisibility::canSeeScope( $actor, $scopeId ) ) {
				self::finish( $id, false, __( 'You cannot read that scope, so nothing was stored.', 'tomatio' ) );
			}
			$result = Sync::preview( $id, $kind, $locator, $scopeId, $actor, self::scopeName( $scopeId ) );
			self::finish( $id, $result['ok'], $result['text'] );
		}

		$pickId = is_string( $post['pick'] ?? null ) ? $post['pick'] : '';
		$pick   = Library::get( $pickId );
		if ( $pick === null || ( $pick['service'] ?? '' ) !== $id ) {
			self::finish( $id, false, __( 'That source is not on this brain.', 'tomatio' ) );
		}
		if ( ! ScopeVisibility::canSeeScope( $actor, (int) ( $pick['scope_id'] ?? 0 ) ) ) {
			self::finish( $id, false, __( 'You cannot read that scope.', 'tomatio' ) );
		}

		if ( $do === 'store' ) {
			$result = Sync::store( $pickId, $actor );
			self::finish( $id, $result['ok'], $result['text'] );
		}
		if ( $do === 'refresh' ) {
			$result = Sync::refresh( $pick );
			self::finish( $id, $result['ok'], $result['text'] );
		}
		if ( $do === 'remove' ) {
			$result = Sync::remove( $pickId, $actor );
			self::finish( $id, $result['ok'], $result['text'] );
		}

		self::finish( $id, false, __( 'That action is not available.', 'tomatio' ) );
	}

	public static function scopeName( int $scopeId ): string {
		if ( $scopeId <= 0 || ! function_exists( 'get_term' ) ) {
			return '';
		}
		$term = get_term( $scopeId, Scopes::TAXONOMY );
		if ( is_object( $term ) && isset( $term->name ) && is_string( $term->name ) && $term->name !== '' ) {
			return $term->name;
		}

		return '';
	}

	/**
	 * @return array<string, mixed>|null
	 */
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
