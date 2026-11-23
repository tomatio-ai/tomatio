<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Planning;

use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Integrations\Catalog;
use AgentBrain\Modules\Integrations\Context\Read;
use AgentBrain\Modules\Integrations\LinearTarget;

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
			$bad = self::save( $id, $post );
			if ( $bad !== '' ) {
				self::finish( $id, false, $bad );
			}
			self::finish( $id, true, __( 'Saved. Run a dry run before anything is written.', 'tomatio' ) );
		}
		if ( $do === 'setup' ) {
			$bad = self::save( $id, $post );
			if ( $bad !== '' ) {
				self::finish( $id, false, $bad );
			}
			if ( (string) Library::row( $id )['target'] === '' ) {
				self::finish( $id, false, __( 'Choose what to sync with first.', 'tomatio' ) );
			}
			$result = Apply::preview( $id, $actor );
			self::finish( $id, $result['ok'], $result['ok'] ? __( 'The dry run is ready below. Nothing was written.', 'tomatio' ) : $result['text'] );
		}
		if ( $do === 'preview' ) {
			$result = Apply::preview( $id, $actor );
			self::finish( $id, $result['ok'], $result['ok'] ? __( 'The dry run is ready below. Nothing was written.', 'tomatio' ) : $result['text'] );
		}
		if ( $do === 'approve' ) {
			$yes    = is_string( $post['fingerprint'] ?? null ) ? $post['fingerprint'] : '';
			$result = Apply::approve( $id, $actor, $yes );
			self::finish( $id, $result['ok'], $result['text'] );
		}
		// B236: the Notion card's first question, and making a backlog there.
		if ( ( $do === 'have' || $do === 'new' || $do === 'ask' || $do === 'create' ) && $id !== 'notion' ) {
			self::finish( $id, false, __( 'That action is not available.', 'tomatio' ) );
		}
		if ( $do === 'have' || $do === 'new' || $do === 'ask' ) {
			$row          = Library::row( $id );
			$row['setup'] = $do === 'have' ? 'have' : ( $do === 'new' ? 'create' : '' );
			Library::save( $id, $row );
			Choices::forget( $id );
			self::finish( $id, true, $do === 'have'
				? __( 'Pick or paste your Notion database below, then do a dry run.', 'tomatio' )
				: ( $do === 'new' ? __( 'Pick the Notion page to put the backlog under.', 'tomatio' ) : __( 'Choose how to start below.', 'tomatio' ) ) );
		}
		if ( $do === 'create' ) {
			$parent = is_string( $post['parent'] ?? null ) ? $post['parent'] : '';
			$board  = isset( $post['backlog_id'] ) ? absint( $post['backlog_id'] ) : 0;
			$result = Apply::create( $id, $actor, $parent, $board );
			self::finish( $id, $result['ok'], $result['text'] );
		}
		if ( $do === 'stop' ) {
			$result = Apply::stop( $id, $actor );
			self::finish( $id, $result['ok'], $result['text'] );
		}

		self::finish( $id, false, __( 'That action is not available.', 'tomatio' ) );
	}

	/**
	 * B231: '' when saved; otherwise why nothing was saved. A Linear team key,
	 * issue identifier, team or project link or name is looked up; what
	 * cannot be found is said, never dropped behind "Saved".
	 *
	 * @param array<string, mixed> $post
	 */
	private static function save( string $service, array $post ): string {
		$row   = Library::row( $service );
		$raw   = is_string( $post['target'] ?? null ) ? $post['target'] : '';
		$kind  = is_string( $post['target_kind'] ?? null ) ? $post['target_kind'] : (string) $row['target_kind'];
		$kinds = $service === 'linear' ? [ 'team', 'project' ] : [ 'database' ];
		if ( ! in_array( $kind, $kinds, true ) ) {
			$kind = $kinds[0];
		}
		$locator = Read::locator( $service === 'linear' ? 'linear' : 'notion', $raw );
		if ( $locator === '' && trim( $raw ) !== '' ) {
			if ( $service !== 'linear' ) {
				return __( 'That is not a Notion database link or id. Nothing was saved.', 'tomatio' );
			}
			$found = LinearTarget::resolve( $raw, $kind, static fn ( string $method, string $url, ?array $body ): array|string => Pull::dispatch( 'linear', $method, $url, $body ) );
			if ( $found['error'] !== '' ) {
				return $found['error'];
			}
			$locator = $found['id'];
			$kind    = $found['kind'];
		}
		if ( $locator !== '' ) {
			if ( $locator !== (string) $row['target'] ) {
				// B236: a database pasted by hand is not one TomaTío made.
				$row['open_only']  = false;
				$row['target_url'] = '';
			}
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

		return '';
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
