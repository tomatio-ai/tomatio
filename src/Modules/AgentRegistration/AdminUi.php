<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentRegistration;

use AgentBrain\Modules\Admin\AgentsPage;
use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\ConnectPage;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\AgentId\Fqdn;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;

/**
 * "Add an agent" on the Agents page (RFC section 6, step 1; #751): the
 * registration form, the one-time instructions block, and the list of
 * registrations with Revoke. Every action needs ab_manage and a nonce.
 */
final class AdminUi {

	public const ACTION_CREATE = 'ab_create_registration';
	public const ACTION_REVOKE = 'ab_revoke_registration';

	private const SHOWN_TTL    = 120;
	private const SHOWN_COOKIE = 'ab_reg_shown_secret';

	public static function registerHooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_post_' . self::ACTION_CREATE, [ self::class, 'handleCreate' ] );
		add_action( 'admin_post_' . self::ACTION_REVOKE, [ self::class, 'handleRevoke' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'agent_brain/agents_page_registrations', [ self::class, 'render' ] );
	}

	/**
	 * Create a registration for a person. The ceiling can never exceed what the
	 * person may grant: scopes need ab_grant_scopes, an observer gets no write
	 * scopes, and every scope id must be a real ab_scope term.
	 *
	 * @param int[] $readIds
	 * @param int[] $writeIds
	 * @return array{key:string, id:int, expires:string}|\WP_Error
	 */
	public static function create( int $actingId, string $class, array $readIds, array $writeIds, string $name ) {
		if ( $actingId <= 0 || ! user_can( $actingId, Capabilities::CAP_MANAGE ) ) {
			return new \WP_Error( 'ab_forbidden', 'You may not create agent registrations.', [ 'status' => 403 ] );
		}

		if ( $class === AgentMeta::CLASS_OBSERVER ) {
			$writeIds = [];
		}

		$readIds  = array_values( array_unique( array_map( 'intval', $readIds ) ) );
		$writeIds = array_values( array_unique( array_map( 'intval', $writeIds ) ) );

		if ( ( $readIds !== [] || $writeIds !== [] ) && ! user_can( $actingId, Capabilities::CAP_GRANT_SCOPES ) ) {
			return new \WP_Error( 'ab_forbidden', 'Choosing scopes needs the grant-scopes capability.', [ 'status' => 403 ] );
		}

		foreach ( array_merge( $readIds, $writeIds ) as $scopeId ) {
			$term = get_term( $scopeId, Scopes::TAXONOMY );
			if ( ! $term instanceof \WP_Term ) {
				return new \WP_Error( 'ab_invalid_scope', 'Unknown scope.', [ 'status' => 422 ] );
			}
		}

		$created = RegistrationStore::create( $class, $readIds, $writeIds, $name === '' ? null : $name, $actingId );
		if ( $created instanceof \WP_Error ) {
			return $created;
		}

		do_action( 'agent_brain/audit', [
			'actor_id'    => $actingId,
			'event'       => 'agent.registration_create',
			'status'      => 'ok',
			'object_type' => 'registration',
			'object_id'   => (string) $created['id'],
			'meta'        => [ 'class' => $class, 'read' => $readIds, 'write' => $writeIds ],
		] );

		return $created;
	}

	public static function revoke( int $actingId, int $registrationId ): bool {
		if ( $actingId <= 0 || ! user_can( $actingId, Capabilities::CAP_MANAGE ) ) {
			return false;
		}

		$ok = RegistrationStore::revoke( $registrationId );

		do_action( 'agent_brain/audit', [
			'actor_id'    => $actingId,
			'event'       => 'agent.registration_revoke',
			'status'      => $ok ? 'ok' : 'error',
			'object_type' => 'registration',
			'object_id'   => (string) $registrationId,
			'meta'        => [],
		] );

		return $ok;
	}

	public static function handleCreate(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agent-brain' ), 403 );
		}
		check_admin_referer( self::ACTION_CREATE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$class = isset( $_POST['ab_reg_class'] ) ? sanitize_key( wp_unslash( (string) $_POST['ab_reg_class'] ) ) : '';
		$name  = isset( $_POST['ab_reg_name'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( (string) $_POST['ab_reg_name'] ) ) ) ) : '';
		$read  = self::postedIds( 'ab_reg_read' );
		$write = self::postedIds( 'ab_reg_write' );
		// phpcs:enable

		$result = self::create( get_current_user_id(), $class, $read, $write, $name );
		if ( $result instanceof \WP_Error ) {
			self::redirect( 'error', $result->get_error_code() );
		}

		self::stash( $result['key'], $result['expires'] );
		self::redirect( 'created', '' );
	}

	public static function handleRevoke(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agent-brain' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below with a per-registration action.
		$id = isset( $_POST['registration_id'] ) ? absint( wp_unslash( (string) $_POST['registration_id'] ) ) : 0;
		check_admin_referer( self::ACTION_REVOKE . '_' . $id );

		self::redirect( self::revoke( get_current_user_id(), $id ) ? 'revoked' : 'error', '' );
	}

	/**
	 * @return int[]
	 */
	private static function postedIds( string $field ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce checked by the caller; values are cast to int.
		$raw = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : [];

		return array_values( array_filter( array_map( 'absint', $raw ) ) );
	}

	/**
	 * Hand the key to the next page load without ever persisting the plaintext.
	 * The transient holds only a secretbox ciphertext; the decryption secret
	 * exists solely in this admin's browser cookie, so a database dump or backup
	 * taken in the window cannot reveal a live key.
	 */
	public static function stash( string $key, string $expires ): void {
		$secret = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box    = sodium_crypto_secretbox( (string) wp_json_encode( [ 'key' => $key, 'expires' => $expires ] ), $nonce, $secret );
		set_transient( self::shownKey(), base64_encode( $nonce . $box ), self::SHOWN_TTL );

		$value = rtrim( strtr( base64_encode( $secret ), '+/', '-_' ), '=' );
		if ( ! headers_sent() ) {
			setcookie( self::SHOWN_COOKIE, $value, [
				'expires'  => time() + self::SHOWN_TTL,
				'path'     => (string) wp_parse_url( admin_url(), PHP_URL_PATH ),
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			] );
		}
		$_COOKIE[ self::SHOWN_COOKIE ] = $value;
	}

	/**
	 * Single read: the stored ciphertext is deleted whether or not it decrypts.
	 *
	 * @return array{key:string, expires:string}|null
	 */
	public static function take(): ?array {
		$packed = get_transient( self::shownKey() );
		delete_transient( self::shownKey() );
		$cookie = isset( $_COOKIE[ self::SHOWN_COOKIE ] ) ? (string) $_COOKIE[ self::SHOWN_COOKIE ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- decryption secret, never output.
		unset( $_COOKIE[ self::SHOWN_COOKIE ] );
		if ( ! headers_sent() ) {
			setcookie( self::SHOWN_COOKIE, '', [ 'expires' => time() - 3600, 'path' => (string) wp_parse_url( admin_url(), PHP_URL_PATH ) ] );
		}

		$secret = base64_decode( strtr( $cookie, '-_', '+/' ), true );
		$raw    = is_string( $packed ) ? base64_decode( $packed, true ) : false;
		if ( $secret === false || strlen( $secret ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || $raw === false || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $secret );
		$data  = $plain === false ? null : json_decode( $plain, true );

		return is_array( $data ) && isset( $data['key'], $data['expires'] )
			? [ 'key' => (string) $data['key'], 'expires' => (string) $data['expires'] ]
			: null;
	}

	private static function shownKey(): string {
		return 'ab_reg_shown_' . get_current_user_id();
	}

	private static function redirect( string $notice, string $code ): void {
		wp_safe_redirect( add_query_arg(
			[ 'page' => AgentsPage::SLUG, 'ab_reg' => $notice, 'ab_code' => $code ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function enqueue( string $hook ): void {
		if ( ! Menu::isPageHook( $hook, AgentsPage::SLUG ) ) {
			return;
		}

		wp_register_script( 'agent-brain-agents', false, [], '1', true );
		wp_enqueue_script( 'agent-brain-agents' );
		wp_add_inline_script(
			'agent-brain-agents',
			'document.querySelectorAll("[data-ab-copy]").forEach(function(b){b.addEventListener("click",function(){navigator.clipboard.writeText(b.dataset.abCopy).then(function(){var t=b.textContent;b.textContent='
			. wp_json_encode( __( 'Copied', 'agent-brain' ) )
			. ';setTimeout(function(){b.textContent=t;},1500);});});});'
			. 'var shown=document.getElementById("ab-registration-instructions");if(shown){shown.focus();shown.select();}'
		);
	}

	/** Renders the section above the agents table. */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}

		self::renderNotice();
		self::renderShownBlock();

		// #751: the page head's "Add an agent" opens this panel (#ab-agent-new);
		// it opens by itself after a failed attempt, so the reason shows.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice selector.
		$failed  = isset( $_GET['ab_reg'] ) && sanitize_key( wp_unslash( (string) $_GET['ab_reg'] ) ) === 'error';
		$pending = count( array_filter( RegistrationStore::recent(), static fn ( array $row ): bool => RegistrationStore::displayStatus( $row ) === RegistrationStore::STATUS_PENDING ) );
		if ( $pending > 0 ) {
			$callout = Ui::callout(
				'info',
				/* translators: %s: number of registration keys */
				sprintf( _n( '%s registration key is not used yet.', '%s registration keys are not used yet.', $pending, 'agent-brain' ), number_format_i18n( $pending ) ),
				'<a href="#ab-agent-new">' . esc_html__( 'See the keys', 'agent-brain' ) . '</a>'
			);
			echo $callout; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		}

		echo '<details id="ab-agent-new" class="ab-ui-card ab-ui-new ab-registrations"' . ( $failed ? ' open' : '' ) . '><summary>' . esc_html__( 'Add an agent', 'agent-brain' ) . '</summary>';
		echo '<p class="ab-ui-new-intro">' . esc_html__( 'Pick what the agent may read and write. You get instructions with a one-time key to paste into the AI: it registers itself as a new agent within those limits.', 'agent-brain' ) . '</p>';
		self::renderCreateForm();
		self::renderList();
		echo '</details>';
	}

	private static function renderNotice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice selector.
		$notice = isset( $_GET['ab_reg'] ) ? sanitize_key( wp_unslash( (string) $_GET['ab_reg'] ) ) : '';
		$code   = isset( $_GET['ab_code'] ) ? sanitize_key( wp_unslash( (string) $_GET['ab_code'] ) ) : '';
		// phpcs:enable

		if ( $notice === 'revoked' ) {
			echo '<div class="notice notice-success inline" role="status"><p>' . esc_html__( 'Registration key revoked.', 'agent-brain' ) . '</p></div>';
		} elseif ( $notice === 'error' ) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html(
				$code !== '' ? sprintf( /* translators: %s: error code */ __( 'Could not complete that action (%s).', 'agent-brain' ), $code ) : __( 'Could not complete that action.', 'agent-brain' )
			) . '</p></div>';
		}
	}

	private static function renderShownBlock(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
		if ( ( isset( $_GET['ab_reg'] ) ? sanitize_key( wp_unslash( (string) $_GET['ab_reg'] ) ) : '' ) !== 'created' ) {
			return;
		}

		$shown = self::take();

		if ( $shown === null ) {
			echo '<div class="notice notice-warning inline" role="status"><p>' . esc_html__( 'The registration key was already shown once and cannot be shown again. Revoke it and create a new one.', 'agent-brain' ) . '</p></div>';

			return;
		}

		$text = Instructions::build(
			(string) get_bloginfo( 'name' ),
			ConnectPage::mcpUrl(),
			(string) $shown['key'],
			(string) $shown['expires'] . ' UTC'
		);

		echo '<div class="notice notice-success inline" role="status"><p><strong>' . esc_html__( 'Registration created. Copy these instructions now: the key is shown only once.', 'agent-brain' ) . '</strong></p></div>';
		echo '<div class="ab-copy-row"><label class="screen-reader-text" for="ab-registration-instructions">' . esc_html__( 'Registration instructions for the agent', 'agent-brain' ) . '</label><textarea readonly rows="12" class="large-text code" id="ab-registration-instructions">' . esc_textarea( $text ) . '</textarea>';
		echo '<button type="button" class="button button-small button-primary" aria-live="polite" aria-controls="ab-registration-instructions" data-ab-copy="' . esc_attr( $text ) . '">' . esc_html__( 'Copy', 'agent-brain' ) . '</button></div>';
	}

	private static function renderCreateForm(): void {
		$terms = get_terms( [ 'taxonomy' => Scopes::TAXONOMY, 'hide_empty' => false ] );
		$terms = is_array( $terms ) ? $terms : [];

		echo '<form method="post" class="ab-registration-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CREATE ) . '" />';
		wp_nonce_field( self::ACTION_CREATE, '_wpnonce', false );

		echo '<table class="form-table"><tr><th scope="row"><label for="ab_reg_class">' . esc_html__( 'Class', 'agent-brain' ) . '</label></th><td><select name="ab_reg_class" id="ab_reg_class">';
		foreach ( AgentMeta::CLASSES as $class ) {
			echo '<option value="' . esc_attr( $class ) . '"' . selected( $class, AgentMeta::CLASS_EXECUTOR, false ) . '>' . esc_html( $class ) . '</option>';
		}
		echo '</select> <span class="description">' . esc_html__( 'An observer can read only.', 'agent-brain' ) . '</span></td></tr>';

		foreach ( [ 'ab_reg_read' => __( 'Read scopes', 'agent-brain' ), 'ab_reg_write' => __( 'Write scopes', 'agent-brain' ) ] as $field => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			if ( $terms === [] ) {
				echo esc_html__( 'No scopes defined yet.', 'agent-brain' );
			}
			foreach ( $terms as $term ) {
				if ( $term instanceof \WP_Term ) {
					echo '<label style="display:block;"><input type="checkbox" name="' . esc_attr( $field ) . '[]" value="' . esc_attr( (string) $term->term_id ) . '" /> ' . esc_html( $term->name ) . '</label>';
				}
			}
			echo '</td></tr>';
		}

		echo '<tr><th scope="row"><label for="ab_reg_name">' . esc_html__( 'Agent name (optional)', 'agent-brain' ) . '</label></th><td>'
			. '<input type="text" name="ab_reg_name" id="ab_reg_name" maxlength="' . esc_attr( (string) Fqdn::NAME_MAX ) . '" class="regular-text" />'
			. ' <span class="description">' . esc_html__( 'Leave empty to let the agent choose.', 'agent-brain' ) . '</span></td></tr></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create registration key', 'agent-brain' ) . '</button></p>';
		echo '</form>';
	}

	private static function renderList(): void {
		$rows = RegistrationStore::recent();
		if ( $rows === [] ) {
			return;
		}

		$dash = '-';

		echo '<h2 class="ab-ui-kicker">' . esc_html__( 'Recent registration keys', 'agent-brain' ) . '</h2>';
		echo '<div class="ab-table-wrap"><table class="widefat striped"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Status', 'agent-brain' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Class', 'agent-brain' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Name', 'agent-brain' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Read scopes', 'agent-brain' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Write scopes', 'agent-brain' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Created by', 'agent-brain' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Expires / spent', 'agent-brain' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Action', 'agent-brain' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$status = RegistrationStore::displayStatus( $row );
			$by     = get_userdata( (int) $row['issued_by'] );
			$fqdn   = $status === RegistrationStore::STATUS_SPENT && $row['agent_user_id'] > 0 ? AgentIssuer::fqdnFor( (int) $row['agent_user_id'] ) : null;
			$read   = Registrar::scopeNames( $row['read_ids'] );
			$write  = Registrar::scopeNames( $row['write_ids'] );

			echo '<tr><td>' . esc_html( $status ) . '</td>';
			echo '<td>' . esc_html( (string) $row['agent_class'] ) . '</td>';
			echo '<td>';
			if ( $fqdn !== null ) {
				echo '<code>' . esc_html( $fqdn ) . '</code>';
			} else {
				echo esc_html( $row['agent_name'] === null || $row['agent_name'] === '' ? __( 'chosen by the agent', 'agent-brain' ) : (string) $row['agent_name'] );
			}
			echo '</td>';
			echo '<td>' . esc_html( $read === [] ? $dash : implode( ', ', $read ) ) . '</td>';
			echo '<td>' . esc_html( $write === [] ? $dash : implode( ', ', $write ) ) . '</td>';
			echo '<td>' . esc_html( $by instanceof \WP_User ? $by->display_name : $dash ) . '</td>';

			if ( $status === RegistrationStore::STATUS_SPENT ) {
				$spent = isset( $row['spent_at'] ) && $row['spent_at'] !== null && $row['spent_at'] !== '' ? (string) $row['spent_at'] . ' UTC' : $dash;
				echo '<td>' . esc_html( sprintf( /* translators: %s: date and time */ __( 'spent %s', 'agent-brain' ), $spent ) ) . '</td><td>' . esc_html( $dash ) . '</td></tr>';
				continue;
			}

			echo '<td>' . esc_html( (string) $row['expires'] ) . ' UTC</td><td>';

			if ( $status === RegistrationStore::STATUS_PENDING ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"'
					. \AgentBrain\Modules\Admin\Confirm::attr( \AgentBrain\Modules\Admin\Confirm::message( __( 'Revoke this registration key: it can no longer be used to register an agent', 'agent-brain' ), __( 'Create a new registration key if you need one again', 'agent-brain' ) ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Confirm::attr() escapes.
					. '>';
				echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REVOKE ) . '" />';
				echo '<input type="hidden" name="registration_id" value="' . esc_attr( (string) $row['id'] ) . '" />';
				wp_nonce_field( self::ACTION_REVOKE . '_' . $row['id'], '_wpnonce', false );
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Revoke', 'agent-brain' ) . '</button></form>';
			} else {
				echo esc_html( $dash );
			}

			echo '</td></tr>';
		}

		echo '</tbody></table></div>';
	}
}
