<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentId;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\MasterRole;

/**
 * A per-agent setting: when it is on, the agent may read every artifact and
 * section whose scope it already reads. Off by default. It never grants
 * write, review or approve. Only a person who can manage agents turns it
 * on, from Agents > the agent > Change scopes, or through agent-update.
 */
final class ScopeArtifactReaders {

	/** User meta: '1' when the agent reads every artifact in its read scopes. */
	public const META = 'ab_agent_reads_scope_artifacts';

	public const ACTION = 'agent_brain_reads_scope_artifacts';

	public static function enabled( int $userId ): bool {
		if ( $userId <= 0 || ! function_exists( 'get_userdata' ) || ! function_exists( 'get_user_meta' ) ) {
			return false;
		}

		$user = get_userdata( $userId );
		if ( ! $user instanceof \WP_User || ! in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true ) ) {
			return false;
		}

		return (string) get_user_meta( $userId, self::META, true ) === '1';
	}

	/** A person (never an agent account) who can manage agents. */
	public static function canSet( int $actingId ): bool {
		if ( $actingId <= 0 || ! function_exists( 'get_userdata' ) || ! function_exists( 'user_can' ) || ! user_can( $actingId, Capabilities::CAP_MANAGE ) ) {
			return false;
		}

		$user = get_userdata( $actingId );

		return $user instanceof \WP_User && ! in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true );
	}

	/**
	 * Turn the setting on or off. True, or a WP_Error that says why not.
	 * A no-op (already as asked) is true and is not audited.
	 *
	 * @return true|\WP_Error
	 */
	public static function set( int $actingId, int $agentId, bool $on ) {
		if ( ! self::canSet( $actingId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'Only a person who can manage agents can say whether an agent reads every artifact in its read scopes.', 'tomatio' ), [ 'status' => 403 ] );
		}
		if ( ! MasterRole::isAgentAccount( $agentId ) ) {
			return new \WP_Error( 'ab_not_an_agent', __( 'That account is not an agent.', 'tomatio' ), [ 'status' => 422 ] );
		}

		$before = self::enabled( $agentId );
		if ( $before === $on ) {
			return true;
		}

		if ( $on ) {
			update_user_meta( $agentId, self::META, '1' );
		} else {
			delete_user_meta( $agentId, self::META );
		}

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actingId,
				'event'       => 'agent.reads_scope_artifacts',
				'status'      => 'ok',
				'object_type' => 'user',
				'object_id'   => (string) $agentId,
				'meta'        => [ 'fqdn' => AgentIssuer::fqdnFor( $agentId ), 'reads_scope_artifacts' => $on ],
			]
		);

		return true;
	}

	public static function nonceAction( int $agentId ): string {
		return self::ACTION . '_' . $agentId;
	}

	/**
	 * A one-button form that flips the setting. Empty when the viewer may
	 * not change it. $back is "agent" (return to that agent) or "scopes".
	 */
	public static function toggleForm( int $agentId, string $back ): string {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return '';
		}

		$on     = self::enabled( $agentId );
		$scopes = $back === 'scopes';
		$label  = $on
			? __( 'Turn off reading every artifact in its read scopes', 'tomatio' )
			: __( 'Turn on reading every artifact in its read scopes', 'tomatio' );
		$word   = $scopes
			? ( $on ? __( 'On', 'tomatio' ) : __( 'Off', 'tomatio' ) )
			: ( $on ? __( 'Turn off', 'tomatio' ) : __( 'Turn on', 'tomatio' ) );

		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ab-reads-scope-artifacts">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />'
			. '<input type="hidden" name="agent_id" value="' . esc_attr( (string) $agentId ) . '" />'
			. '<input type="hidden" name="on" value="' . esc_attr( $on ? '0' : '1' ) . '" />'
			. '<input type="hidden" name="back" value="' . esc_attr( $scopes ? 'scopes' : 'agent' ) . '" />'
			. wp_nonce_field( self::nonceAction( $agentId ), '_wpnonce', false, false )
			. '<button type="submit" class="button button-small" aria-label="' . esc_attr( $label ) . '">' . esc_html( $word ) . '</button>'
			. '</form>';
	}

	public static function handle(): void {
		$actingId = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( ! self::canSet( $actingId ) ) {
			wp_die( esc_html__( 'Only a person who can manage agents can change this.', 'tomatio' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() below, bound to the agent.
		$agentId = isset( $_POST['agent_id'] ) ? absint( wp_unslash( (string) $_POST['agent_id'] ) ) : 0;
		$on      = isset( $_POST['on'] ) && (string) wp_unslash( $_POST['on'] ) === '1';
		$back    = isset( $_POST['back'] ) ? sanitize_key( (string) wp_unslash( $_POST['back'] ) ) : 'scopes';
		// phpcs:enable
		check_admin_referer( self::nonceAction( $agentId ) );

		$result = self::set( $actingId, $agentId, $on );
		$code   = $result instanceof \WP_Error ? $result->get_error_code() : 'ok';
		$args   = [ 'ab_reads_scope' => $code ];
		if ( $back === 'agent' ) {
			$url = add_query_arg( array_merge( $args, [ 'page' => 'agent-brain-agents', 'item' => (string) $agentId ] ), admin_url( 'admin.php' ) );
		} else {
			$url = add_query_arg( array_merge( $args, [ 'page' => 'agent-brain-scopes' ] ), admin_url( 'admin.php' ) );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/** Notice HTML for the page the handler returned to; '' when there is none. */
	public static function noticeHtml(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice selector, no state change.
		if ( ! isset( $_GET['ab_reads_scope'] ) ) {
			return '';
		}
		$code = sanitize_key( (string) wp_unslash( $_GET['ab_reads_scope'] ) );
		// phpcs:enable

		if ( $code === 'ok' ) {
			return '<div class="notice notice-success is-dismissible" role="status"><p>' . esc_html__( 'Saved whether this agent reads every artifact in its read scopes.', 'tomatio' ) . '</p></div>';
		}

		$text = $code === 'ab_not_an_agent'
			? __( 'That account is not an agent.', 'tomatio' )
			: __( 'Could not change whether the agent reads every artifact in its read scopes.', 'tomatio' );

		return '<div class="notice notice-error" role="alert"><p>' . esc_html( $text ) . '</p></div>';
	}
}
