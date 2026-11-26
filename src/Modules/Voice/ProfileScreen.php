<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Voice;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Provenance\ScopeGuard;

/**
 * #1002: "Voice" on a user's profile screen, where people see, edit,
 * approve and delete the voice profiles kept for them: their own on their
 * profile (and those of the agents they lead), an agent's on the agent's
 * profile for its lead and curators. It is part of the profile form, so
 * it saves with "Update profile"; each change goes through Service::set
 * as the signed-in person, which makes them the approver Access names.
 *
 * Shown only while Voice is on, and only to someone Access lets write the
 * owner's voice.
 */
final class ProfileScreen {

	public const ANCHOR       = 'tomatio-voice';
	public const FIELD        = 'ab_voice';
	public const NONCE_ACTION = 'ab_voice_save';
	public const NONCE_FIELD  = 'ab_voice_nonce';

	public static function register(): void {
		add_action( 'show_user_profile', [ self::class, 'render' ] );
		add_action( 'edit_user_profile', [ self::class, 'render' ] );
		add_action( 'personal_options_update', [ self::class, 'save' ] );
		add_action( 'edit_user_profile_update', [ self::class, 'save' ] );
	}

	/** Where $owner's profiles are seen and approved. */
	public static function url( int $owner ): string {
		$lead = Access::lead( $owner );
		$page = $lead > 0 ? $lead : $owner;

		return $page === (int) get_current_user_id() && ! Access::isAgent( $page )
			? admin_url( 'profile.php#' . self::ANCHOR )
			: admin_url( 'user-edit.php?user_id=' . $page . '#' . self::ANCHOR );
	}

	/**
	 * Whose profiles show on $pageUser's screen: theirs, and for a person
	 * the agents they lead.
	 *
	 * @return array<int, int>
	 */
	public static function owners( int $pageUser ): array {
		$owners = [ $pageUser ];
		if ( ! Access::isAgent( $pageUser ) ) {
			foreach ( ScopeGuard::ownAgents( $pageUser ) as $agent ) {
				if ( Access::lead( (int) $agent ) === $pageUser ) {
					$owners[] = (int) $agent;
				}
			}
		}
		$viewer = (int) get_current_user_id();

		return array_values( array_filter( array_unique( $owners ), static fn ( int $o ): bool => Access::canWrite( $o, $viewer, $viewer ) ) );
	}

	public static function render( \WP_User $user ): void {
		if ( ! Enabled::on() ) {
			return;
		}
		echo self::html( (int) $user->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/** The section for $pageUser's screen; '' when there is nothing the viewer may see. */
	public static function html( int $pageUser ): string {
		$owners = self::owners( $pageUser );
		if ( $owners === [] ) {
			return '';
		}

		$out  = '<h2 id="' . esc_attr( self::ANCHOR ) . '">' . esc_html__( 'Voice', 'tomatio' ) . '</h2>';
		$out .= '<p class="description">' . esc_html__( 'How your AIs write for you and as you, by context and language. They measure texts you give them (the texts are not kept) and write the readable part; what they write waits here until you approve it. Changes save with Update profile.', 'tomatio' ) . '</p>';
		$out .= wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false );

		$any = false;
		foreach ( $owners as $owner ) {
			$records = Profiles::forOwner( $owner );
			if ( $records === [] ) {
				continue;
			}
			$any  = true;
			$user = get_userdata( $owner );
			if ( $owner !== $pageUser && $user instanceof \WP_User ) {
				/* translators: %s: agent name. */
				$out .= '<h3>' . esc_html( sprintf( __( 'Voice of %s', 'tomatio' ), $user->display_name ) ) . '</h3>';
			}
			foreach ( $records as $record ) {
				$out .= self::profileHtml( $record );
			}
		}
		if ( ! $any ) {
			$out .= '<p>' . esc_html__( 'No voice profiles yet. Ask your AI to learn your voice from a few messages you wrote, or to import your MYNAH profiles.', 'tomatio' ) . '</p>';
		}

		return $out;
	}

	/** @param array<string, mixed> $record */
	private static function profileHtml( array $record ): string {
		$id      = (int) $record['memory_id'];
		$name    = self::FIELD . '[' . $id . ']';
		$summary = Measure::summary( $record['measured'] );
		$pending = is_array( $record['pending'] ) ? $record['pending'] : null;
		$shown   = $pending !== null ? Profile::readable( (array) $pending['readable'] ) : $record['readable'];
		$title   = $record['context'] === Contexts::GLOBAL_CONTEXT
			? __( 'Every context (global rules)', 'tomatio' )
			: (string) $record['context'] . ' · ' . (string) $record['language'];

		$out  = '<table class="form-table ab-voice" role="presentation"><tbody>';
		$out .= '<tr><th scope="row">' . esc_html( $title ) . '</th><td>';
		$about = Contexts::KNOWN[ (string) $record['context'] ] ?? '';
		if ( $about !== '' ) {
			$out .= '<p class="description">' . esc_html( $about ) . '</p>';
		}
		/* translators: 1: samples measured, 2: edits learned from, 3: words. */
		$out .= '<p class="description">' . esc_html( sprintf( __( 'Measured from %1$d samples and %2$d edits (%3$d words).', 'tomatio' ), (int) $summary['samples'], (int) $summary['edits'], (int) $summary['words'] ) ) . '</p>';
		$shift = Profile::lastShift( $record );
		if ( $shift !== null ) {
			/* translators: 1: date, 2: what changed. */
			$out .= '<p class="description">' . esc_html( sprintf( __( 'Last change (%1$s): %2$s', 'tomatio' ), $shift['at'], $shift['note'] ) ) . '</p>';
		}
		if ( $pending !== null ) {
			/* translators: 1: who proposed, 2: fields. */
			$out .= '<p><strong>' . esc_html( sprintf( __( 'Waiting for your approval: %1$s proposed %2$s. The fields below show the proposal.', 'tomatio' ), (string) ( $pending['by'] ?? '' ), implode( ', ', (array) ( $pending['fields'] ?? [] ) ) ) ) . '</strong></p>';
		}
		$out .= '</td></tr>';

		foreach ( Profile::READABLE_TEXT as $field => $label ) {
			$out .= '<tr><th scope="row"><label>' . esc_html( $label ) . '</label></th><td><input type="text" class="regular-text" name="' . esc_attr( $name . '[' . $field . ']' ) . '" value="' . esc_attr( (string) ( $shown[ $field ] ?? '' ) ) . '" /></td></tr>';
		}
		$out .= '<tr><th scope="row"><label>' . esc_html__( 'Formality', 'tomatio' ) . '</label></th><td><select name="' . esc_attr( $name . '[formality]' ) . '"><option value="">' . esc_html__( 'Not set', 'tomatio' ) . '</option>';
		for ( $i = 1; $i <= 5; $i++ ) {
			$out .= '<option value="' . $i . '"' . selected( (int) ( $shown['formality'] ?? 0 ), $i, false ) . '>' . $i . '</option>';
		}
		$out .= '</select> <span class="description">' . esc_html__( '1 very casual, 5 very formal', 'tomatio' ) . '</span></td></tr>';
		foreach ( Profile::READABLE_LISTS as $field => $label ) {
			$out .= '<tr><th scope="row"><label>' . esc_html( $label ) . '</label></th><td><textarea rows="3" class="large-text" name="' . esc_attr( $name . '[' . $field . ']' ) . '">' . esc_textarea( implode( "\n", (array) ( $shown[ $field ] ?? [] ) ) ) . '</textarea><p class="description">' . esc_html__( 'One per line.', 'tomatio' ) . '</p></td></tr>';
		}
		$banned = Measure::normalize( $record['measured'] )['banned'];
		$out   .= '<tr><th scope="row"><label>' . esc_html__( 'Words never used', 'tomatio' ) . '</label></th><td><textarea rows="2" class="large-text" name="' . esc_attr( $name . '[banned]' ) . '">' . esc_textarea( implode( "\n", $banned ) ) . '</textarea><p class="description">' . esc_html__( 'One per line. Your AIs flag them in drafts.', 'tomatio' ) . '</p></td></tr>';

		$ops = [ 'save' => __( 'Save my changes', 'tomatio' ) ];
		if ( $pending !== null ) {
			// Saving the profile for something else leaves a proposal waiting.
			$ops = [ 'keep' => __( 'Decide later', 'tomatio' ), 'approve' => __( 'Approve the proposal (with my changes)', 'tomatio' ), 'reject' => __( 'Reject the proposal', 'tomatio' ) ];
		}
		$ops['delete'] = __( 'Delete this profile', 'tomatio' );
		$out          .= '<tr><th scope="row">' . esc_html__( 'On update', 'tomatio' ) . '</th><td><select name="' . esc_attr( $name . '[op]' ) . '">';
		foreach ( $ops as $value => $label ) {
			$out .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		$out .= '</select></td></tr>';

		return $out . '</tbody></table>';
	}

	public static function save( int $userId ): void {
		if ( ! Enabled::on() || ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( (string) wp_unslash( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$posted = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? (array) wp_unslash( $_POST[ self::FIELD ] ) : [];
		$viewer = (int) get_current_user_id();
		if ( $viewer <= 0 || Capabilities::isPureAgent( $viewer ) ) {
			return;
		}
		$owners = self::owners( $userId );
		foreach ( $posted as $memoryId => $values ) {
			$record = Profiles::byId( (int) $memoryId );
			if ( $record === null || ! is_array( $values ) || ! in_array( (int) $record['owner_id'], $owners, true ) ) {
				continue;
			}
			self::apply( $record, $values );
		}
	}

	/**
	 * One profile's posted form, through Service::set as the signed-in
	 * person.
	 *
	 * @param array<string, mixed> $record
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>|\WP_Error|null null when nothing was asked
	 */
	public static function apply( array $record, array $values ) {
		$base = [
			'owner'    => (int) $record['owner_id'],
			'context'  => (string) $record['context'],
			'language' => (string) $record['language'],
		];
		$op   = is_string( $values['op'] ?? null ) ? $values['op'] : 'save';
		if ( $op === 'keep' ) {
			return null;
		}
		if ( $op === 'delete' ) {
			return Service::set( $base + [ 'delete' => true ] );
		}
		if ( $op === 'reject' ) {
			return Service::set( $base + [ 'reject' => true ] );
		}

		$fields = [];
		foreach ( array_keys( Profile::READABLE_TEXT ) as $field ) {
			if ( isset( $values[ $field ] ) && is_string( $values[ $field ] ) ) {
				$fields[ $field ] = $values[ $field ];
			}
		}
		if ( isset( $values['formality'] ) && is_string( $values['formality'] ) ) {
			$fields['formality'] = $values['formality'] === '' ? null : (int) $values['formality'];
		}
		foreach ( array_keys( Profile::READABLE_LISTS ) as $field ) {
			if ( isset( $values[ $field ] ) && is_string( $values[ $field ] ) ) {
				$fields[ $field ] = self::lines( $values[ $field ] );
			}
		}
		$input = $base;
		if ( $fields !== [] ) {
			$input['fields'] = $fields;
		}
		if ( isset( $values['banned'] ) && is_string( $values['banned'] ) ) {
			$now                  = self::lines( $values['banned'] );
			$was                  = Measure::normalize( $record['measured'] )['banned'];
			$input['banned_words'] = $now;
			$input['unban_words']  = array_values( array_diff( $was, Measure::words( $now ) ) );
		}

		if ( $op === 'approve' && is_array( $record['pending'] ) ) {
			// The proposal, with whatever the person changed in the form, is what applies.
			$result = Service::set( $input );
			Profiles::reset();
			$after = Profiles::byId( (int) $record['memory_id'] );

			return $after !== null && is_array( $after['pending'] ) ? Service::set( $base + [ 'approve' => true ] ) : $result;
		}

		return Service::set( $input );
	}

	/** @return array<int, string> */
	private static function lines( string $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', $value ) ) ), static fn ( string $s ): bool => $s !== '' ) );
	}
}
