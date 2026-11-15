<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Provenance;

use AgentBrain\Core\ActionContext;
use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Admin\Ui;

/**
 * Settings -> Privacy tab (PROV-7, #176): the site switch for action
 * context and the retention period for action-context and provenance data.
 *
 * The action-context switch writes the `ab_action_context` option Core
 * owns ('1' = on, which is the default and is stored as no option at all;
 * '0' = off). The `tomatio/action_context_enabled` filter keeps the
 * final say: when it disagrees with the option the box shows the effective
 * state, disabled, with a note — the DedicatedSite pattern.
 *
 * The "What is recorded" block is the beta note: the plain list of what
 * turning this on captures, so a site can decide with the facts in front
 * of it. Everything listed is identifiers only (ids, versions, client
 * names), never content.
 */
final class SettingsTab {

	public const ACTION = 'ab_save_action_context';

	public const EVENT_ENABLED   = 'action_context_changed';
	public const EVENT_RETENTION = 'action_context_retention_changed';
	public const EVENT_SCOPE_GUARD = 'scope_guard_changed';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handleSave' ] );
	}

	public static function renderBody(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flags.
		$saved = isset( $_GET['ab_ac_saved'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag.
		$error = isset( $_GET['ab_ac_error'] ) ? sanitize_key( wp_unslash( (string) $_GET['ab_ac_error'] ) ) : '';

		if ( $saved ) {
			echo '<div class="notice notice-success inline" role="status"><p>' . esc_html__( 'Privacy settings saved.', 'tomatio' ) . '</p></div>';
		}
		if ( $error === 'retention_days' ) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html__( 'The retention period must be a whole number of days from 1 to 3650; nothing was saved.', 'tomatio' ) . '</p></div>';
		}
		if ( $error === 'scope_guard' ) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html__( 'Choose hold, refuse or allow for restricted sources; nothing was saved.', 'tomatio' ) . '</p></div>';
		}

		$optionOn = get_option( ActionContext::OPTION, '1' ) === '1';
		$enabled  = ActionContext::enabled();
		$forced   = $enabled !== $optionOn;
		$days     = self::days();

		$now = $enabled
			? esc_html__( 'On: the next thing an AI session writes stores which items it read first.', 'tomatio' )
			: esc_html__( 'Off: nothing new is recorded; what was recorded stays until it ages out.', 'tomatio' );
		if ( $forced ) {
			$now .= ' <em>' . esc_html__( 'A site filter sets this for the site, so it cannot be changed here.', 'tomatio' ) . '</em>';
		}

		echo '<section class="ab-ui-card">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::setting(
			__( 'Record what an AI session saw before it wrote', 'tomatio' ),
			$now,
			Ui::toggle( 'ab_action_context', 'ab_action_context', $enabled, $forced ),
			'ab_action_context',
			'<p>' . esc_html__( 'On by default. When off, no new read sets, action logs or approval labels are captured; what was already recorded stays until its retention period ends or a forget erases it.', 'tomatio' ) . '</p>'
		);
		echo Ui::setting(
			__( 'Keep action context and provenance for (days)', 'tomatio' ),
			esc_html( sprintf(
				/* translators: %d: number of days */
				_n( '%d day, then the daily sweep prunes it.', '%d days, then the daily sweep prunes it.', $days, 'tomatio' ),
				$days
			) ),
			'<input type="number" class="small-text" min="1" max="3650" id="ab_action_context_retention_days" name="' . esc_attr( Retention::OPTION_DAYS ) . '" value="' . esc_attr( (string) $days ) . '" />',
			'ab_action_context_retention_days',
			'<p>' . esc_html__( 'How long read sets, action logs and provenance links are kept before the daily sweep prunes them (default 90). A curator can pin an item — the ab_provenance_pin meta on a section, or the tomatio/provenance_keep filter — to keep its action context and provenance longer.', 'tomatio' ) . '</p>'
		);
		$guard   = ScopeGuard::mode();
		$choices = [
			'review' => __( 'Hold it for a curator', 'tomatio' ),
			'block'  => __( 'Refuse it', 'tomatio' ),
			'off'    => __( 'Allow it (no check)', 'tomatio' ),
		];
		$select  = '<select id="' . esc_attr( ScopeGuard::OPTION ) . '" name="' . esc_attr( ScopeGuard::OPTION ) . '">';
		foreach ( $choices as $value => $label ) {
			$select .= '<option value="' . esc_attr( $value ) . '"' . selected( $guard, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$select .= '</select>';
		echo Ui::setting(
			__( 'When an AI writes something built on a more restricted scope', 'tomatio' ),
			esc_html( $choices[ $guard ] ),
			$select,
			ScopeGuard::OPTION,
			'<p>' . esc_html__( 'Restricted sources stay restricted. If an AI session read or cites an item from a scope fewer people can read, and then writes a memory, a document section or a review note into a wider scope, it is held for a curator (the default), refused, or allowed. The check uses the reads recorded above, so with recording off it sees only citations.', 'tomatio' ) . '</p>'
		);
		echo Ui::saveBar( 'ab_privacy_submit' );
		// phpcs:enable
		echo '</form></section>';

		self::renderWhatIsRecorded();
	}

	/**
	 * The beta note: what recording action context actually captures,
	 * folded away (#751).
	 */
	private static function renderWhatIsRecorded(): void {
		echo '<details class="ab-ui-card ab-ui-fold"><summary>' . esc_html__( 'What is recorded', 'tomatio' ) . '</summary>';
		echo '<p>' . esc_html__( 'Action context and provenance are identifiers only — item numbers, versions and client names. Content is never recorded.', 'tomatio' ) . '</p>';
		echo '<ul class="ul-disc">';
		echo '<li>' . esc_html__( 'Within one AI session, the numbers of the memories, knowledge pages and document sections the session read; the next thing it writes stores that list.', 'tomatio' ) . '</li>';
		echo '<li>' . esc_html__( 'For each section write and each approval: the exact version, who acted, which MCP client it arrived on, and whether an AI client relayed it.', 'tomatio' ) . '</li>';
		echo '<li>' . esc_html__( 'Small reference records for outside sources a memory names (title and where to find it), plus identifier links for citations and what an item was built from.', 'tomatio' ) . '</li>';
		echo '</ul>';
		echo '<p>' . esc_html__( 'The person an item concerns can see this context, it travels in PCP exports, a forget erases it, and it ages out after the retention period above.', 'tomatio' ) . '</p>';
		echo '</details>';
	}

	/**
	 * Effective retention days for the form: the stored/option default with
	 * the filter applied, exactly what the sweep will use.
	 */
	public static function days(): int {
		return Retention::days();
	}

	public static function handleSave(): void {
		if ( ! current_user_can( SettingsHub::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage TomaTío settings.', 'tomatio' ), 403 );
		}

		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$error = self::save( wp_unslash( $_POST ) );

		wp_safe_redirect( SettingsHub::url( SettingsHub::TAB_PRIVACY, $error !== '' ? [ 'ab_ac_error' => $error ] : [ 'ab_ac_saved' => '1' ] ) );
		exit;
	}

	/**
	 * Atomic save, the Sharing SettingsTab pattern: every posted value is
	 * validated first and nothing is written unless all of it is valid;
	 * values equal to their default are deleted, not stored. Returns an
	 * error key ('' on success).
	 *
	 * @param array<string, mixed> $post Unslashed form data.
	 */
	public static function save( array $post ): string {
		$wasEnabled = ActionContext::enabled();
		$wasDays    = Retention::days();

		// 1. Validate everything.
		$rawDays = isset( $post[ Retention::OPTION_DAYS ] ) ? trim( (string) $post[ Retention::OPTION_DAYS ] ) : '';
		$days    = $rawDays === '' ? Retention::DEFAULT_DAYS : ( ctype_digit( $rawDays ) ? (int) $rawDays : 0 );
		if ( $days < 1 || $days > Retention::MAX_DAYS ) {
			return 'retention_days';
		}

		$on = isset( $post['ab_action_context'] ) && $post['ab_action_context'] === '1';

		// The guard mode is only changed when the form sends it.
		$wasGuard = ScopeGuard::mode();
		$guard    = isset( $post[ ScopeGuard::OPTION ] ) ? (string) $post[ ScopeGuard::OPTION ] : null;
		if ( $guard !== null && ! in_array( $guard, ScopeGuard::MODES, true ) ) {
			return 'scope_guard';
		}

		// 2. Write. On ('1') is the default: stored as no option at all. Off
		//    is the only state that persists a value ('0').
		if ( $on ) {
			delete_option( ActionContext::OPTION );
		} else {
			update_option( ActionContext::OPTION, '0' );
		}

		if ( $days === Retention::DEFAULT_DAYS ) {
			delete_option( Retention::OPTION_DAYS );
		} else {
			update_option( Retention::OPTION_DAYS, $days );
		}

		if ( $guard === 'review' ) {
			delete_option( ScopeGuard::OPTION );
		} elseif ( $guard !== null ) {
			update_option( ScopeGuard::OPTION, $guard );
		}

		self::audit( self::EVENT_ENABLED, $wasEnabled !== $on, [ 'enabled' => $on ] );
		self::audit( self::EVENT_SCOPE_GUARD, $guard !== null && $wasGuard !== $guard, [ 'mode' => (string) $guard ] );
		self::audit( self::EVENT_RETENTION, $wasDays !== $days, [ 'days' => $days ] );

		return '';
	}

	/**
	 * One audit entry per changed setting (the Sharing SettingsTab pattern).
	 *
	 * @param array<string, mixed> $meta
	 */
	private static function audit( string $event, bool $changed, array $meta ): void {
		if ( ! $changed ) {
			return;
		}

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => get_current_user_id(),
				'event'       => $event,
				'status'      => 'ok',
				'object_type' => 'setting',
				'object_id'   => $event,
				'meta'        => $meta,
			]
		);
	}
}
