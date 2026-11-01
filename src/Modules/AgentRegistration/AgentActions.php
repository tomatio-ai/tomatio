<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentRegistration;

use AgentBrain\Modules\Admin\AgentsPage;
use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\Identity\ApprovalGrants;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * Per-agent lifecycle actions on the Agents page (issue #88): Suspend,
 * Reactivate, Revoke and "Revoke app passwords", plus the issue #252
 * Grant/Revoke approval toggle (the manager-agent approval grant). Each
 * needs ab_manage and a nonce bound to the agent and the operation; status
 * changes go through AgentIssuer::setStatus so the transitions and audit
 * stay in one place, approval-grant changes through Identity\ApprovalGrants.
 */
final class AgentActions {

	public const ACTION = 'ab_agent_action';

	public const OP_SUSPEND    = 'suspend';
	public const OP_REACTIVATE = 'reactivate';
	public const OP_REVOKE     = 'revoke';
	public const OP_PASSWORDS  = 'revoke_passwords';

	/**
	 * Issue #252: toggle the manager-agent approval grant
	 * (Identity\ApprovalGrants). Shown per row based on the agent's current
	 * grant state, never for a revoked agent, so it is rendered outside
	 * availableOps() below.
	 */
	public const OP_GRANT_APPROVAL  = 'grant_approval';
	public const OP_REVOKE_APPROVAL = 'revoke_approval';

	/** Issue #233: give an agent that has none its agent ID. */
	public const OP_ISSUE = 'issue_id';

	/** @var array<int, string> */
	public const OPS = [ self::OP_SUSPEND, self::OP_REACTIVATE, self::OP_REVOKE, self::OP_PASSWORDS, self::OP_GRANT_APPROVAL, self::OP_REVOKE_APPROVAL, self::OP_ISSUE ];

	public static function registerHooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_notices', [ self::class, 'notice' ] );
	}

	/**
	 * Pure: which operations apply to an agent in this status.
	 *
	 * @return array<int, string>
	 */
	public static function availableOps( string $status ): array {
		$ops = [];

		if ( AgentMeta::canTransition( $status, AgentMeta::STATUS_SUSPENDED ) ) {
			$ops[] = self::OP_SUSPEND;
		}
		if ( $status === AgentMeta::STATUS_SUSPENDED ) {
			$ops[] = self::OP_REACTIVATE;
		}
		if ( AgentMeta::canTransition( $status, AgentMeta::STATUS_REVOKED ) ) {
			$ops[] = self::OP_REVOKE;
		}
		if ( $status !== AgentMeta::STATUS_REVOKED ) {
			$ops[] = self::OP_PASSWORDS;
		}

		return $ops;
	}

	/**
	 * Apply one operation. Returns true or a WP_Error.
	 *
	 * @return true|\WP_Error
	 */
	public static function apply( int $actingId, int $userId, string $op ) {
		if ( $actingId <= 0 || ! user_can( $actingId, Capabilities::CAP_MANAGE ) ) {
			return new \WP_Error( 'ab_forbidden', 'You may not manage agents.', [ 'status' => 403 ] );
		}

		$user = get_userdata( $userId );
		if ( ! $user instanceof \WP_User || ! in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true ) ) {
			return new \WP_Error( 'ab_not_an_agent', 'Not an agent.', [ 'status' => 422 ] );
		}

		$issuer = new AgentIssuer();

		switch ( $op ) {
			case self::OP_SUSPEND:
				return $issuer->setStatus( $userId, AgentMeta::STATUS_SUSPENDED, $actingId, 'admin' );
			case self::OP_REACTIVATE:
				return $issuer->setStatus( $userId, AgentMeta::STATUS_ACTIVE, $actingId, 'admin' );
			case self::OP_REVOKE:
				return $issuer->setStatus( $userId, AgentMeta::STATUS_REVOKED, $actingId, 'admin' );
			case self::OP_PASSWORDS:
				if ( ! class_exists( '\WP_Application_Passwords' ) ) {
					return new \WP_Error( 'ab_unavailable', 'Application passwords are not available.', [ 'status' => 500 ] );
				}
				\WP_Application_Passwords::delete_all_application_passwords( $userId );
				do_action( 'agent_brain/audit', [
					'actor_id'    => $actingId,
					'event'       => 'agent.revoke_app_passwords',
					'status'      => 'ok',
					'object_type' => 'user',
					'object_id'   => (string) $userId,
					'meta'        => [ 'fqdn' => AgentIssuer::fqdnFor( $userId ) ],
				] );

				return true;
			case self::OP_GRANT_APPROVAL:
				// ApprovalGrants re-checks ab_manage_artifacts itself; the
				// ab_manage gate above is the Agents-page surface check.
				return ( new ApprovalGrants() )->grant( $actingId, $userId )
					? true
					: new \WP_Error( 'ab_grant_failed', 'Could not grant approval rights.', [ 'status' => 500 ] );
			case self::OP_REVOKE_APPROVAL:
				return ( new ApprovalGrants() )->revoke( $actingId, $userId )
					? true
					: new \WP_Error( 'ab_grant_failed', 'Could not revoke approval rights.', [ 'status' => 500 ] );
			case self::OP_ISSUE:
				if ( AgentIssuer::fqdnFor( $userId ) !== null ) {
					return new \WP_Error( 'ab_already_issued', 'This agent already has an ID.', [ 'status' => 409 ] );
				}

				return $issuer->issueFromUser( $userId, $actingId ) !== null
					? true
					: new \WP_Error( 'ab_issue_failed', 'Could not issue an agent ID.', [ 'status' => 422 ] );
			default:
				return new \WP_Error( 'ab_invalid_action', 'Unknown action.', [ 'status' => 422 ] );
		}
	}

	public static function handle(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agent-brain' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() below, bound to the agent and operation.
		$userId = isset( $_POST['user_id'] ) ? absint( wp_unslash( (string) $_POST['user_id'] ) ) : 0;
		$op     = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		// phpcs:enable
		check_admin_referer( self::nonceAction( $op, $userId ) );

		$result = self::apply( get_current_user_id(), $userId, $op );

		wp_safe_redirect( add_query_arg(
			[
				'page'        => AgentsPage::SLUG,
				'item'        => (string) $userId,
				'ab_agent_op' => $result instanceof \WP_Error ? 'error' : $op,
				'ab_code'     => $result instanceof \WP_Error ? $result->get_error_code() : '',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function nonceAction( string $op, int $userId ): string {
		return self::ACTION . '_' . $op . '_' . $userId;
	}

	public static function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice selector.
		if ( ! isset( $_GET['page'], $_GET['ab_agent_op'] ) || $_GET['page'] !== AgentsPage::SLUG ) {
			return;
		}
		$op   = sanitize_key( wp_unslash( (string) $_GET['ab_agent_op'] ) );
		$code = isset( $_GET['ab_code'] ) ? sanitize_key( wp_unslash( (string) $_GET['ab_code'] ) ) : '';
		// phpcs:enable

		if ( $op === 'error' ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html( sprintf( /* translators: %s: error code */ __( 'Could not complete that action (%s).', 'agent-brain' ), $code ) ) . '</p></div>';
		} elseif ( in_array( $op, self::OPS, true ) ) {
			echo '<div class="notice notice-success is-dismissible" role="status"><p>' . esc_html__( 'Agent updated.', 'agent-brain' ) . '</p></div>';
		}
	}

	/**
	 * The action buttons in one row; echoes escaped markup. An agent without
	 * an ID (#233) gets "Issue ID" plus the status actions: it cannot sign
	 * in, but it can still be suspended or revoked.
	 */
	public static function renderRowActions( int $userId, string $status, bool $issued = true ): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}

		$style = 'display:inline-block;margin:0 4px 4px 0;';
		if ( ! $issued && $status !== AgentMeta::STATUS_REVOKED ) {
			echo self::buttonForm( $userId, self::OP_ISSUE, 'button button-small button-primary', $style ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buttonForm() escapes.
		}
		foreach ( self::availableOps( $status ) as $op ) {
			echo self::buttonForm( $userId, $op, 'button button-small' . ( $op === self::OP_REVOKE ? ' button-link-delete' : '' ), $style ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buttonForm() escapes.
		}
		// Issue #252: the manager-agent approval grant toggle, driven by the
		// agent's grant state rather than its status. Not offered for revoked
		// agents: they cannot sign in or call tools, so a grant would mean
		// nothing.
		if ( $status !== AgentMeta::STATUS_REVOKED ) {
			$approvalOp = self::approvalOp( $userId );
			echo self::buttonForm( $userId, $approvalOp, 'button button-small' . ( $approvalOp === self::OP_REVOKE_APPROVAL ? ' button-link-delete' : '' ), $style ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buttonForm() escapes.
		}
	}

	/**
	 * Grant or revoke approval, whichever applies to this agent now.
	 */
	public static function approvalOp( int $userId ): string {
		return ( new ApprovalGrants() )->hasGrant( $userId ) ? self::OP_REVOKE_APPROVAL : self::OP_GRANT_APPROVAL;
	}

	/**
	 * One operation as a small form with its button, for the Agents page
	 * (#751) and renderRowActions(). Operations that are hard to take back
	 * ask first, through Confirm.
	 */
	public static function buttonForm( int $userId, string $op, string $buttonClass = 'button', string $style = '' ): string {
		$labels = [
			self::OP_ISSUE           => __( 'Issue ID', 'agent-brain' ),
			self::OP_SUSPEND         => __( 'Suspend', 'agent-brain' ),
			self::OP_REACTIVATE      => __( 'Reactivate', 'agent-brain' ),
			self::OP_REVOKE          => __( 'Revoke', 'agent-brain' ),
			self::OP_PASSWORDS       => __( 'Revoke app passwords', 'agent-brain' ),
			self::OP_GRANT_APPROVAL  => __( 'Grant approval', 'agent-brain' ),
			self::OP_REVOKE_APPROVAL => __( 'Revoke approval', 'agent-brain' ),
		];
		$confirm = [
			self::OP_SUSPEND         => \AgentBrain\Modules\Admin\Confirm::message( __( 'Suspend this agent: it can no longer sign in or call tools until you reactivate it', 'agent-brain' ), __( 'You can reactivate it at any time', 'agent-brain' ) ),
			self::OP_REVOKE          => \AgentBrain\Modules\Admin\Confirm::message( __( 'Revoke this agent: its credentials and scopes are removed and it can no longer sign in', 'agent-brain' ) ),
			self::OP_PASSWORDS       => \AgentBrain\Modules\Admin\Confirm::message( __( 'Revoke all application passwords for this agent: every client using one stops working', 'agent-brain' ), __( 'Create new application passwords to reconnect', 'agent-brain' ) ),
			self::OP_REVOKE_APPROVAL => \AgentBrain\Modules\Admin\Confirm::message( __( 'Revoke this agent\'s approval grant: it can no longer review artifact sections or trigger assembly', 'agent-brain' ), __( 'Grant it again at any time', 'agent-brain' ) ),
		];
		if ( ! isset( $labels[ $op ] ) ) {
			return '';
		}

		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"'
			. ( $style !== '' ? ' style="' . esc_attr( $style ) . '"' : '' )
			. ( isset( $confirm[ $op ] ) ? \AgentBrain\Modules\Admin\Confirm::attr( $confirm[ $op ] ) : '' )
			. '><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />'
			. '<input type="hidden" name="user_id" value="' . esc_attr( (string) $userId ) . '" />'
			. '<input type="hidden" name="op" value="' . esc_attr( $op ) . '" />'
			. wp_nonce_field( self::nonceAction( $op, $userId ), '_wpnonce', false, false )
			. '<button type="submit" class="' . esc_attr( $buttonClass ) . '">' . esc_html( $labels[ $op ] ) . '</button></form>';
	}
}
