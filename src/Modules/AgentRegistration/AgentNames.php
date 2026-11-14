<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentRegistration;

use AgentBrain\Modules\Admin\AgentsPage;
use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\TaskTakers;
use AgentBrain\Modules\DataStore\TioDayAssignee;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * #950: a curator names an agent and describes it, on the Agents page.
 *
 * - rename() sets the display name, nickname and first name to the new
 *   name (last name cleared) and the description (Biographical Info). The
 *   login and the agent ID stay, so every connection keeps working. A name
 *   another account goes by is refused, so a name points at one agent
 *   (TíoDay tasks are assigned by it, #949).
 * - An agent cannot rename itself: when an agent updates its own account
 *   (REST users/me, profile.php), its name fields keep their values.
 * - The WordPress profile of an agent saves without an email address
 *   (agents have none), instead of "Please enter an email address".
 */
final class AgentNames {

	public const ACTION = 'ab_agent_rename';

	public const MAX_NAME        = 60;
	public const MAX_DESCRIPTION = 1000;

	/** The user meta that make up an agent's name. */
	private const NAME_META = [ 'nickname', 'first_name', 'last_name' ];

	public static function registerHooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_notices', [ self::class, 'notice' ] );
		add_filter( 'wp_pre_insert_user_data', [ self::class, 'keepOwnDisplayName' ], 10, 4 );
		add_filter( 'insert_user_meta', [ self::class, 'keepOwnNameMeta' ], 10, 4 );
		add_action( 'user_profile_update_errors', [ self::class, 'allowNoEmail' ], 10, 3 );
	}

	/**
	 * Rename an agent and set its description. True, or a WP_Error that
	 * says why not.
	 *
	 * @return true|\WP_Error
	 */
	public static function rename( int $actingId, int $userId, string $name, string $description ) {
		if ( $actingId <= 0 || ! user_can( $actingId, Capabilities::CAP_MANAGE ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'Only a curator may rename an agent.', 'tomatio' ), [ 'status' => 403 ] );
		}
		if ( ! TioDayAssignee::isAgent( $userId ) ) {
			return new \WP_Error( 'ab_not_an_agent', __( 'Not an agent.', 'tomatio' ), [ 'status' => 422 ] );
		}
		$user = get_userdata( $userId );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'ab_not_an_agent', __( 'Not an agent.', 'tomatio' ), [ 'status' => 422 ] );
		}

		$name        = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $name ) ) );
		$description = trim( sanitize_textarea_field( $description ) );
		if ( $name === '' ) {
			return new \WP_Error( 'ab_agent_name_empty', __( 'An agent needs a name.', 'tomatio' ), [ 'status' => 422 ] );
		}
		if ( mb_strlen( $name ) > self::MAX_NAME ) {
			/* translators: %d: the most characters a name may have. */
			return new \WP_Error( 'ab_agent_name_long', sprintf( __( 'A name has at most %d characters.', 'tomatio' ), self::MAX_NAME ), [ 'status' => 422 ] );
		}
		if ( mb_strlen( $description ) > self::MAX_DESCRIPTION ) {
			/* translators: %d: the most characters a description may have. */
			return new \WP_Error( 'ab_agent_description_long', sprintf( __( 'A description has at most %d characters.', 'tomatio' ), self::MAX_DESCRIPTION ), [ 'status' => 422 ] );
		}
		if ( TioDayAssignee::takenByOthers( $name, [ $userId ] ) ) {
			/* translators: %s: the name that was asked for. */
			return new \WP_Error( 'ab_agent_name_taken', sprintf( __( 'Another account already goes by %s. Pick a name nobody else has.', 'tomatio' ), $name ), [ 'status' => 409 ] );
		}

		$before = (string) $user->display_name;
		$result = wp_update_user(
			[
				'ID'           => $userId,
				'display_name' => $name,
				'nickname'     => $name,
				'first_name'   => $name,
				'last_name'    => '',
				'description'  => $description,
			]
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actingId,
				'event'       => 'agent.rename',
				'status'      => 'ok',
				'object_type' => 'user',
				'object_id'   => (string) $userId,
				'meta'        => [ 'fqdn' => AgentIssuer::fqdnFor( $userId ), 'from' => $before, 'to' => $name ],
			]
		);

		return true;
	}

	public static function handle(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'tomatio' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() below, bound to the agent.
		$userId      = isset( $_POST['user_id'] ) ? absint( wp_unslash( (string) $_POST['user_id'] ) ) : 0;
		$name        = isset( $_POST['agent_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['agent_name'] ) ) : '';
		$description = isset( $_POST['agent_description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['agent_description'] ) ) : '';
		$takes       = ! empty( $_POST['agent_takes_tasks'] );
		$aliases     = isset( $_POST['agent_aliases'] ) ? explode( ',', sanitize_text_field( wp_unslash( (string) $_POST['agent_aliases'] ) ) ) : [];
		// phpcs:enable
		check_admin_referer( self::nonceAction( $userId ) );

		$result = self::rename( get_current_user_id(), $userId, $name, $description );
		if ( $result === true ) {
			// #953: whether it takes tasks, and its aliases.
			$result = TaskTakers::set( get_current_user_id(), $userId, $takes, $aliases );
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'           => AgentsPage::SLUG,
					'item'           => (string) $userId,
					'ab_agent_named' => $result instanceof \WP_Error ? $result->get_error_code() : 'ok',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function nonceAction( int $userId ): string {
		return self::ACTION . '_' . $userId;
	}

	public static function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice selector.
		if ( ! isset( $_GET['page'], $_GET['ab_agent_named'] ) || $_GET['page'] !== AgentsPage::SLUG ) {
			return;
		}
		$code = sanitize_key( wp_unslash( (string) $_GET['ab_agent_named'] ) );
		// phpcs:enable

		$messages = [
			'ab_agent_name_empty'       => __( 'An agent needs a name.', 'tomatio' ),
			'ab_agent_name_long'        => sprintf( /* translators: %d: characters. */ __( 'A name has at most %d characters.', 'tomatio' ), self::MAX_NAME ),
			'ab_agent_description_long' => sprintf( /* translators: %d: characters. */ __( 'A description has at most %d characters.', 'tomatio' ), self::MAX_DESCRIPTION ),
			'ab_agent_name_taken'       => __( 'Another account already goes by that name. Pick a name nobody else has.', 'tomatio' ),
			'ab_agent_alias_long'       => sprintf( /* translators: %d: characters. */ __( 'An alias has at most %d characters.', 'tomatio' ), TaskTakers::MAX_ALIAS ),
			'ab_agent_alias_taken'      => __( 'Another account already goes by one of those aliases. Pick aliases nobody else has.', 'tomatio' ),
			'ab_agent_alias_many'       => sprintf( /* translators: %d: aliases. */ __( 'An agent has at most %d aliases.', 'tomatio' ), TaskTakers::MAX_ALIASES ),
		];
		if ( $code === 'ok' ) {
			echo '<div class="notice notice-success is-dismissible" role="status"><p>' . esc_html__( 'Agent saved.', 'tomatio' ) . '</p></div>';
		} else {
			/* translators: %s: error code. */
			$text = $messages[ $code ] ?? sprintf( __( 'Could not rename the agent (%s).', 'tomatio' ), $code );
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html( $text ) . '</p></div>';
		}
	}

	/**
	 * The name and description form for the Agents page detail.
	 */
	public static function form( int $userId, string $name, string $description ): string {
		$nameId  = 'ab-agent-name-' . $userId;
		$descId  = 'ab-agent-description-' . $userId;
		$takesId = 'ab-agent-takes-tasks-' . $userId;
		$aliasId = 'ab-agent-aliases-' . $userId;
		$takes   = (string) get_user_meta( $userId, TaskTakers::META_FLAG, true ) === '1';

		return '<details class="ab-agent-rename" id="ab-agent-rename"><summary>' . esc_html__( 'Edit name, description and tasks', 'tomatio' ) . '</summary>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />'
			. '<input type="hidden" name="user_id" value="' . esc_attr( (string) $userId ) . '" />'
			. wp_nonce_field( self::nonceAction( $userId ), '_wpnonce', false, false )
			. '<p><label for="' . esc_attr( $nameId ) . '">' . esc_html__( 'Name', 'tomatio' ) . '</label><br />'
			. '<input type="text" class="regular-text" id="' . esc_attr( $nameId ) . '" name="agent_name" required maxlength="' . esc_attr( (string) self::MAX_NAME ) . '" value="' . esc_attr( $name ) . '" /></p>'
			. '<p class="description">' . esc_html__( 'What people and TíoDay call it. Its login and agent ID stay the same, so its connections keep working. A TíoDay task whose who is this name is assigned to it.', 'tomatio' ) . '</p>'
			. '<p><label for="' . esc_attr( $descId ) . '">' . esc_html__( 'Description', 'tomatio' ) . '</label><br />'
			. '<textarea class="large-text" rows="3" id="' . esc_attr( $descId ) . '" name="agent_description" maxlength="' . esc_attr( (string) self::MAX_DESCRIPTION ) . '">' . esc_textarea( $description ) . '</textarea></p>'
			. '<p class="description">' . esc_html__( 'People see it when they connect an AI as this agent.', 'tomatio' ) . '</p>'
			. '<p><label for="' . esc_attr( $takesId ) . '"><input type="checkbox" id="' . esc_attr( $takesId ) . '" name="agent_takes_tasks" value="1"' . checked( $takes, true, false ) . ' /> ' . esc_html__( 'Takes tasks', 'tomatio' ) . '</label></p>'
			. '<p class="description">' . esc_html__( 'For an agent that runs on its own, such as a bot on another machine. People give it work as a TíoDay task whose who is its name or an alias; it may close those tasks and comment on them, and nothing else. Every connected AI is told so, and nobody can act as it.', 'tomatio' ) . '</p>'
			. '<p><label for="' . esc_attr( $aliasId ) . '">' . esc_html__( 'Aliases', 'tomatio' ) . '</label><br />'
			. '<input type="text" class="regular-text" id="' . esc_attr( $aliasId ) . '" name="agent_aliases" value="' . esc_attr( implode( ', ', TaskTakers::aliases( $userId ) ) ) . '" /></p>'
			/* translators: %d: the most aliases an agent may have. */
			. '<p class="description">' . esc_html( sprintf( __( 'Other names people call it, separated by commas, at most %d. No other account may go by them.', 'tomatio' ), TaskTakers::MAX_ALIASES ) ) . '</p>'
			. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'tomatio' ) . '</button></p>'
			. '</form></details>';
	}

	/**
	 * An agent updating its own account keeps its display name.
	 *
	 * @param mixed                $data
	 * @param mixed                $update
	 * @param mixed                $userId
	 * @param array<string, mixed> $userdata
	 * @return mixed
	 */
	public static function keepOwnDisplayName( $data, $update, $userId, $userdata = [] ) {
		if ( ! is_array( $data ) || ! self::isSelfEdit( (bool) $update, (int) $userId ) ) {
			return $data;
		}
		$user = get_userdata( (int) $userId );
		if ( $user instanceof \WP_User ) {
			$data['display_name'] = (string) $user->display_name;
		}

		return $data;
	}

	/**
	 * An agent updating its own account keeps its nickname, first and last name.
	 *
	 * @param mixed                $meta
	 * @param mixed                $user
	 * @param mixed                $update
	 * @param array<string, mixed> $userdata
	 * @return mixed
	 */
	public static function keepOwnNameMeta( $meta, $user, $update, $userdata = [] ) {
		if ( ! is_array( $meta ) || ! $user instanceof \WP_User || ! self::isSelfEdit( (bool) $update, (int) $user->ID ) ) {
			return $meta;
		}
		foreach ( self::NAME_META as $key ) {
			if ( array_key_exists( $key, $meta ) ) {
				$meta[ $key ] = (string) get_user_meta( (int) $user->ID, $key, true );
			}
		}

		return $meta;
	}

	/**
	 * user-edit.php: an agent has no email address, so saving its profile
	 * does not ask for one.
	 *
	 * @param \WP_Error         $errors
	 * @param bool              $update
	 * @param \stdClass|\WP_User $user
	 */
	public static function allowNoEmail( $errors, $update, $user ): void {
		if ( ! $errors instanceof \WP_Error || ! $update || ! is_object( $user ) ) {
			return;
		}
		$userId = (int) ( $user->ID ?? 0 );
		if ( TioDayAssignee::isAgent( $userId ) && trim( (string) ( $user->user_email ?? '' ) ) === '' ) {
			// Older WordPress says empty_email, newer says invalid_email, for the same empty field.
			$errors->remove( 'empty_email' );
			$errors->remove( 'invalid_email' );
		}
	}

	/** Whether this is an agent updating its own account. */
	private static function isSelfEdit( bool $update, int $userId ): bool {
		return $update && $userId > 0 && $userId === (int) get_current_user_id() && TioDayAssignee::isAgent( $userId );
	}
}
