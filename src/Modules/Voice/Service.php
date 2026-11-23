<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Voice;

use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Identity\UserRef;
use WP_Error;

/**
 * #1002: what the voice tools and the profile screen do, for the current
 * caller (Access). Every method returns an array for the answer or a
 * WP_Error; none of them keeps a sample.
 */
final class Service {

	public const MAX_SAMPLES      = 50;
	public const MAX_SAMPLE_CHARS = 20000;
	public const MAX_TOTAL_CHARS  = 200000;
	public const MAX_EDITS        = 20;
	public const MAX_DRAFT_CHARS  = 40000;
	public const MAX_IMPORT_CHARS = 200000;
	public const MAX_IMPORTED     = 40;

	/**
	 * voice-get: the owner's profile for a context and language, with the
	 * global part and short guidance; the nearest profile they have when
	 * there is none for that context, saying so.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( array $input ) {
		$owner = self::owner( $input['owner'] ?? null );
		if ( $owner instanceof WP_Error ) {
			return $owner;
		}
		$context  = Contexts::normalize( (string) ( $input['context'] ?? '' ) );
		$language = Contexts::language( (string) ( $input['language'] ?? '' ) );
		if ( $context === '' ) {
			return self::invalid( __( 'context is required, such as email-external or linkedin-dm. tomatio-voice-list shows the owner\'s contexts.', 'tomatio' ) );
		}
		$readable = self::readable( $owner );
		if ( $readable instanceof WP_Error ) {
			return $readable;
		}

		[ $profile, $note ] = self::pick( $readable, $context, $language );
		$global             = self::global( $readable, $language );
		$user               = get_userdata( $owner );
		$name               = $user instanceof \WP_User ? (string) $user->display_name : '';

		$out = [
			'owner'      => $user instanceof \WP_User ? (string) $user->user_login : '',
			'owner_name' => $name,
			'context'    => $context,
			'language'   => $language,
			'found'      => $profile !== null,
			'fallback'   => $profile !== null && $note !== '',
			'note'       => $note,
			'global'     => $global !== null ? self::globalPart( $global ) : null,
		];
		if ( $profile === null ) {
			$out['guidance'] = $global !== null
				/* translators: %s: the owner's name. */
				? sprintf( __( 'No profile yet for %s, only their rules for every context (global). Follow them, write plainly, and ask the person to review.', 'tomatio' ), $name ) . ' ' . self::learnHint()
				/* translators: %s: the owner's name. */
				: sprintf( __( 'No voice profile yet for %s. Write plainly and ask the person to review.', 'tomatio' ), $name ) . ' ' . self::learnHint();

			return $out;
		}

		$out['profile_context']  = (string) $profile['context'];
		$out['profile_language'] = (string) $profile['language'];
		$out['readable']         = $profile['readable'];
		$out['measured']         = Measure::summary( $profile['measured'] );
		$out['pending']          = is_array( $profile['pending'] );
		$out['last_shift']       = Profile::lastShift( $profile );
		$out['updated_at']       = (string) ( $profile['updated_at'] ?? '' );
		$out['shared']           = ! Access::isPrivateTo( (int) $profile['scope_id'], $owner );
		$out['profile_id']       = (int) $profile['memory_id'];
		$out['guidance']         = self::guidance( $profile, $global, $name );

		return $out;
	}

	/**
	 * voice-list: the owner's contexts, with the known ones for reference.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list( array $input ) {
		$owner = self::owner( $input['owner'] ?? null );
		if ( $owner instanceof WP_Error ) {
			return $owner;
		}
		$readable = self::readable( $owner );
		if ( $readable instanceof WP_Error ) {
			return $readable;
		}
		$user = get_userdata( $owner );
		$rows = [];
		foreach ( $readable as $record ) {
			$summary = Measure::summary( $record['measured'] );
			$rows[]  = [
				'context'    => (string) $record['context'],
				'language'   => (string) $record['language'],
				'about'      => Contexts::KNOWN[ (string) $record['context'] ] ?? ( $record['context'] === Contexts::GLOBAL_CONTEXT ? 'Rules for every context' : '' ),
				'profile_id' => (int) $record['memory_id'],
				'scope_id'   => (int) $record['scope_id'],
				'shared'     => ! Access::isPrivateTo( (int) $record['scope_id'], $owner ),
				'samples'    => (int) $summary['samples'],
				'edits'      => (int) $summary['edits'],
				'pending'    => is_array( $record['pending'] ),
				'last_shift' => Profile::lastShift( $record ),
				'updated_at' => (string) ( $record['updated_at'] ?? '' ),
			];
		}

		return [
			'owner'      => $user instanceof \WP_User ? (string) $user->user_login : '',
			'owner_name' => $user instanceof \WP_User ? (string) $user->display_name : '',
			'profiles'   => $rows,
			'known'      => Contexts::KNOWN,
			'page_url'   => ProfileScreen::url( $owner ),
		];
	}

	/**
	 * voice-learn: measure samples (and before/after edits), merge them
	 * into the profile, record a shift when the new writing differs, drop
	 * the text.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function learn( array $input ) {
		$target = self::writable( $input );
		if ( $target instanceof WP_Error ) {
			return $target;
		}
		[ $owner, $context, $language ] = $target;
		if ( $context === Contexts::GLOBAL_CONTEXT ) {
			return self::invalid( __( 'The global part holds rules for every context (tomatio-voice-set). Learn samples into a context, such as email-external.', 'tomatio' ) );
		}

		$samples = self::strings( $input['samples'] ?? [] );
		$edits   = [];
		foreach ( array_merge( isset( $input['edit'] ) ? [ $input['edit'] ] : [], is_array( $input['edits'] ?? null ) ? $input['edits'] : [] ) as $edit ) {
			if ( is_array( $edit ) && is_string( $edit['after'] ?? null ) && trim( $edit['after'] ) !== '' ) {
				$edits[] = [ (string) ( $edit['before'] ?? '' ), (string) $edit['after'] ];
			}
		}
		if ( $samples === [] && $edits === [] ) {
			return self::invalid( __( 'Send samples (texts the owner wrote) or edit (before: the draft, after: what the person sent).', 'tomatio' ) );
		}
		$total = array_sum( array_map( 'mb_strlen', $samples ) );
		foreach ( $edits as [ $before, $after ] ) {
			$total += mb_strlen( $before ) + mb_strlen( $after );
		}
		if ( count( $samples ) > self::MAX_SAMPLES || count( $edits ) > self::MAX_EDITS || $total > self::MAX_TOTAL_CHARS || array_filter( $samples, static fn ( string $s ): bool => mb_strlen( $s ) > self::MAX_SAMPLE_CHARS ) !== [] ) {
			/* translators: 1: max samples, 2: max characters per sample, 3: max edits, 4: max characters in all. */
			return self::invalid( sprintf( __( 'At most %1$d samples of %2$d characters each and %3$d edits, %4$d characters in all, per call.', 'tomatio' ), self::MAX_SAMPLES, self::MAX_SAMPLE_CHARS, self::MAX_EDITS, self::MAX_TOTAL_CHARS ) );
		}

		$batch = Measure::measure( $samples, $language );
		foreach ( $edits as [ $before, $after ] ) {
			$batch = Measure::merge( $batch, Measure::edit( $before, $after, $language ) );
		}
		// The texts are not needed past this line, and are not kept.
		unset( $samples, $edits, $input['samples'], $input['edit'], $input['edits'] );

		$record = self::recordFor( $owner, $context, $language );
		$scope  = self::scopeFor( $record, $owner, $input );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}
		$created     = ! isset( $record['memory_id'] );
		$before      = Measure::normalize( $record['measured'] );
		$shift       = Measure::shift( $before, $batch );
		$merged      = Measure::merge( $before, $batch );
		$newlyBanned = array_values( array_diff( $merged['banned'], $before['banned'] ) );
		if ( $newlyBanned !== [] ) {
			$shift = trim( $shift . ( $shift !== '' ? '; ' : '' ) . 'now avoids ' . implode( ', ', $newlyBanned ) );
		}
		$record['measured'] = $merged;
		if ( $shift !== '' ) {
			$record = Profile::addShift( $record, ucfirst( $shift ), self::by() );
		}

		$id = Profiles::save( $record, $scope );
		if ( $id <= 0 ) {
			return self::failed();
		}

		$summary = Measure::summary( Measure::trim( $merged ) );
		/* translators: 1: number of texts measured, 2: context, 3: number of samples in all. */
		$say = sprintf( __( 'Measured %1$d texts for the %2$s voice (%3$d in all); the texts themselves were not kept.', 'tomatio' ), (int) $batch['samples'], $context, (int) $summary['samples'] );
		if ( $shift !== '' ) {
			/* translators: %s: what changed. */
			$say .= ' ' . sprintf( __( 'Shift: %s.', 'tomatio' ), ucfirst( $shift ) );
		}

		return [
			'owner'        => (string) $record['owner'],
			'context'      => $context,
			'language'     => $language,
			'profile_id'   => $id,
			'created'      => $created,
			'measured_now' => (int) $batch['samples'],
			'edits_now'    => (int) $batch['edits'],
			'measured'     => $summary,
			'shift'        => $shift !== '' ? ucfirst( $shift ) : '',
			'banned_added' => $newlyBanned,
			'stored'       => __( 'Numbers and phrases of at most three words only. No sample text is stored.', 'tomatio' ),
			'say'          => $say,
		];
	}

	/**
	 * voice-check: what in a draft is off for the owner's voice. Read-only.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function check( array $input ) {
		$draft = is_string( $input['draft'] ?? null ) ? (string) $input['draft'] : '';
		if ( trim( $draft ) === '' ) {
			return self::invalid( __( 'draft is required: the text you are about to hand over.', 'tomatio' ) );
		}
		if ( mb_strlen( $draft ) > self::MAX_DRAFT_CHARS ) {
			/* translators: %d: max characters. */
			return self::invalid( sprintf( __( 'A draft of at most %d characters.', 'tomatio' ), self::MAX_DRAFT_CHARS ) );
		}
		$got = self::get( $input );
		if ( $got instanceof WP_Error ) {
			return $got;
		}
		$owner    = self::owner( $input['owner'] ?? null );
		$readable = $owner instanceof WP_Error ? [] : self::readable( $owner );
		$readable = $readable instanceof WP_Error ? [] : $readable;
		$profile  = ! empty( $got['found'] ) ? Profiles::byId( (int) $got['profile_id'] ) : null;
		$global   = self::global( $readable, (string) $got['language'] );
		$result   = Check::draft( $draft, $profile, $global, (string) $got['language'] );
		$off      = array_values( array_filter( $result['issues'], static fn ( array $i ): bool => $i['severity'] === Check::OFF ) );

		return [
			'owner'           => (string) $got['owner'],
			'context'         => (string) $got['context'],
			'profile_context' => (string) ( $got['profile_context'] ?? '' ),
			'found'           => (bool) $got['found'],
			'fallback'        => (bool) $got['fallback'],
			'note'            => (string) $got['note'],
			'ok'              => $off === [],
			'issues'          => $result['issues'],
			'words'           => $result['words'],
			'sentences'       => $result['sentences'],
			'delta'           => $result['delta'],
			'say'             => $off === []
				? __( 'Nothing off for this voice.', 'tomatio' )
				/* translators: %d: number of issues. */
				: sprintf( _n( '%d thing is off for this voice; fix it before you hand the draft over.', '%d things are off for this voice; fix them before you hand the draft over.', count( $off ), 'tomatio' ), count( $off ) ),
		];
	}

	/**
	 * voice-set: write the readable fields (applied at once when a person
	 * decides on this call, else waiting for the owner or their lead),
	 * banned words, approve or reject what waits, move or delete.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set( array $input ) {
		$target = self::writable( $input );
		if ( $target instanceof WP_Error ) {
			return $target;
		}
		[ $owner, $context, $language ] = $target;
		if ( $context === Contexts::GLOBAL_CONTEXT ) {
			$language = Contexts::ANY_LANGUAGE;
		}
		$approver = Access::approver( $owner, Access::actor() );
		$record   = self::recordFor( $owner, $context, $language );
		$exists   = isset( $record['memory_id'] );
		$by       = self::by();

		if ( ! empty( $input['delete'] ) || ! empty( $input['approve'] ) || ! empty( $input['reject'] ) ) {
			if ( ! $exists ) {
				return new WP_Error( 'ab_not_found', __( 'There is no such profile.', 'tomatio' ), [ 'status' => 404 ] );
			}
			if ( $approver <= 0 ) {
				return self::needsPerson( $owner );
			}
			if ( ! empty( $input['delete'] ) ) {
				if ( ! Profiles::delete( (int) $record['memory_id'] ) ) {
					return self::failed();
				}
				self::audit( 'voice.deleted', (int) $record['memory_id'], $owner, $context, $language );

				return [
					'owner'      => (string) $record['owner'],
					'context'    => $context,
					'language'   => $language,
					'profile_id' => (int) $record['memory_id'],
					'deleted'    => true,
					/* translators: %s: context. */
					'say'        => sprintf( __( 'Deleted the %s voice profile.', 'tomatio' ), $context ),
				];
			}
			if ( ! is_array( $record['pending'] ) ) {
				return self::invalid( __( 'Nothing waits for approval on this profile.', 'tomatio' ) );
			}
			if ( ! empty( $input['approve'] ) ) {
				$changed            = Profile::changedFields( $record['readable'], Profile::readable( (array) $record['pending']['readable'] ) );
				$record['readable'] = Profile::readable( (array) $record['pending']['readable'] );
				$record['approved'] = [ 'by' => $by, 'by_id' => $approver, 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ];
				/* translators: 1: who wrote them, 2: changed fields. */
				$record = Profile::addShift( $record, sprintf( __( 'Approved the fields %1$s wrote: %2$s', 'tomatio' ), (string) ( $record['pending']['by'] ?? '' ), $changed !== [] ? implode( ', ', $changed ) : __( 'no change', 'tomatio' ) ), $by );
			}
			$record['pending'] = null;
			$id                = Profiles::save( $record, (int) $record['scope_id'] );
			if ( $id <= 0 ) {
				return self::failed();
			}

			return [
				'owner'      => (string) $record['owner'],
				'context'    => $context,
				'language'   => $language,
				'profile_id' => $id,
				'applied'    => ! empty( $input['approve'] ),
				'pending'    => false,
				'say'        => ! empty( $input['approve'] )
					/* translators: %s: context. */
					? sprintf( __( 'Approved the waiting fields of the %s voice.', 'tomatio' ), $context )
					/* translators: %s: context. */
					: sprintf( __( 'Rejected the waiting fields of the %s voice; it stays as it was.', 'tomatio' ), $context ),
			];
		}

		$scope = self::scopeFor( $record, $owner, $input );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}

		$fields  = is_array( $input['fields'] ?? null ) ? Profile::readable( $input['fields'], true ) : [];
		$base    = is_array( $record['pending'] ) && $approver <= 0 ? Profile::readable( (array) $record['pending']['readable'] ) : $record['readable'];
		$next    = array_merge( $base, $fields );
		$changed = Profile::changedFields( $record['readable'], $next );
		$applied = false;
		$pending = false;
		if ( $fields !== [] && $changed !== [] ) {
			if ( $approver > 0 ) {
				$record['readable'] = $next;
				$record['pending']  = null;
				$record['approved'] = [ 'by' => $by, 'by_id' => $approver, 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ];
				/* translators: %s: changed fields. */
				$record  = Profile::addShift( $record, sprintf( __( 'Readable fields changed: %s', 'tomatio' ), implode( ', ', $changed ) ), $by );
				$applied = true;
			} else {
				$record['pending'] = [ 'readable' => $next, 'by' => $by, 'by_id' => Access::actor(), 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'fields' => $changed ];
				$pending           = true;
			}
		}

		$measured = Measure::normalize( $record['measured'] );
		$ban      = Measure::words( self::strings( $input['banned_words'] ?? [] ) );
		$unban    = Measure::words( self::strings( $input['unban_words'] ?? [] ) );
		$banned   = Measure::words( array_values( array_diff( array_merge( $measured['banned'], $ban ), $unban ) ) );
		$rebanned = $banned !== $measured['banned'];
		if ( $rebanned ) {
			// A word let back in is no longer counted as removed either.
			foreach ( $unban as $word ) {
				unset( $measured['removed'][ $word ] );
			}
			$measured['banned'] = $banned;
			$record['measured'] = $measured;
		}

		if ( ! $exists && $fields === [] && $ban === [] ) {
			return self::invalid( __( 'Send fields (the readable part) or banned_words for a new profile.', 'tomatio' ) );
		}
		if ( $exists && ! $applied && ! $pending && ! $rebanned && $scope === (int) $record['scope_id'] ) {
			return [
				'owner'      => (string) $record['owner'],
				'context'    => $context,
				'language'   => $language,
				'profile_id' => (int) $record['memory_id'],
				'applied'    => false,
				'pending'    => is_array( $record['pending'] ),
				'changed'    => [],
				'banned'     => $banned,
				'page_url'   => ProfileScreen::url( $owner ),
				'say'        => __( 'Nothing changed.', 'tomatio' ),
			];
		}

		$id = Profiles::save( $record, $scope );
		if ( $id <= 0 ) {
			return self::failed();
		}
		if ( $applied || ! $exists ) {
			self::audit( 'voice.changed', $id, $owner, $context, $language );
		}

		$user = get_userdata( $owner );
		$name = $user instanceof \WP_User ? (string) $user->display_name : '';

		return [
			'owner'      => (string) $record['owner'],
			'context'    => $context,
			'language'   => $language,
			'profile_id' => $id,
			'applied'    => $applied,
			'pending'    => $pending || is_array( $record['pending'] ),
			'changed'    => $changed,
			'banned'     => $banned,
			'page_url'   => ProfileScreen::url( $owner ),
			'say'        => $pending
				/* translators: 1: context, 2: owner's name, 3: page address. */
				? sprintf( __( 'Proposed changes to the %1$s voice; they apply once %2$s (or their lead) approves them: %3$s', 'tomatio' ), $context, $name, ProfileScreen::url( $owner ) )
				/* translators: %s: context. */
				: sprintf( __( 'Updated the %s voice.', 'tomatio' ), $context ),
		];
	}

	/**
	 * voice-import: read MYNAH profiles from Markdown and store each as
	 * the owner's profile for its context and language. Readable fields
	 * apply at once or wait for approval, as with voice-set.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function import( array $input ) {
		$markdown = is_string( $input['markdown'] ?? null ) ? (string) $input['markdown'] : '';
		if ( trim( $markdown ) === '' ) {
			return self::invalid( __( 'markdown is required: the MYNAH profiles as Markdown (a PACK or NAPSAC page, or mynah-profile files).', 'tomatio' ) );
		}
		if ( mb_strlen( $markdown ) > self::MAX_IMPORT_CHARS ) {
			/* translators: %d: max characters. */
			return self::invalid( sprintf( __( 'At most %d characters of Markdown per call.', 'tomatio' ), self::MAX_IMPORT_CHARS ) );
		}
		$owner = self::owner( $input['owner'] ?? null );
		if ( $owner instanceof WP_Error ) {
			return $owner;
		}
		if ( ! Access::canWrite( $owner, Access::actor(), Access::person() ) ) {
			return self::refused();
		}

		$parsed = Mynah::parse( $markdown, (string) ( $input['language'] ?? 'en' ) );
		if ( $parsed['profiles'] === [] ) {
			return self::invalid( __( 'No MYNAH profile found: each one starts with a heading that is its context id (### email-external) followed by - **Field**: value lines.', 'tomatio' ) );
		}
		if ( count( $parsed['profiles'] ) > self::MAX_IMPORTED ) {
			/* translators: %d: max profiles. */
			return self::invalid( sprintf( __( 'At most %d profiles per call.', 'tomatio' ), self::MAX_IMPORTED ) );
		}

		$imported = [];
		$waiting  = 0;
		foreach ( $parsed['profiles'] as $profile ) {
			$set = self::set( [
				'owner'    => $owner,
				'context'  => $profile['context'],
				'language' => $profile['language'],
				'fields'   => $profile['readable'],
				'scope_id' => $input['scope_id'] ?? null,
			] );
			if ( $set instanceof WP_Error ) {
				return $set;
			}
			$record = Profiles::byId( (int) $set['profile_id'] );
			if ( $record !== null ) {
				$note   = 'Imported from MYNAH' . ( $profile['samples'] > 0 ? ' (' . $profile['samples'] . ' samples' . ( $profile['updated'] !== '' ? ', updated ' . $profile['updated'] : '' ) . ')' : '' );
				$record = Profile::addShift( $record, $note, self::by() );
				Profiles::save( $record, (int) $record['scope_id'] );
			}
			$waiting   += ! empty( $set['pending'] ) ? 1 : 0;
			$imported[] = [
				'context'    => $profile['context'],
				'language'   => $profile['language'],
				'profile_id' => (int) $set['profile_id'],
				'pending'    => ! empty( $set['pending'] ),
			];
		}

		$user = get_userdata( $owner );
		$name = $user instanceof \WP_User ? (string) $user->display_name : '';
		/* translators: 1: how many profiles, 2: owner's name. */
		$say = sprintf( _n( 'Imported %1$d MYNAH profile for %2$s.', 'Imported %1$d MYNAH profiles for %2$s.', count( $imported ), 'tomatio' ), count( $imported ), $name );
		if ( $waiting > 0 ) {
			/* translators: %s: page address. */
			$say .= ' ' . sprintf( __( 'They wait for approval here: %s', 'tomatio' ), ProfileScreen::url( $owner ) );
		}

		return [
			'owner'    => $user instanceof \WP_User ? (string) $user->user_login : '',
			'imported' => $imported,
			'skipped'  => $parsed['skipped'],
			'say'      => $say,
		];
	}

	/**
	 * The user a tool argument names: "me" or nothing for the person behind
	 * the call (else the caller), a login, an agent id, a numeric id or an
	 * exact display name.
	 *
	 * @param mixed $value
	 * @return int|WP_Error
	 */
	public static function owner( $value ) {
		if ( $value === null || $value === '' || ( is_string( $value ) && in_array( strtolower( trim( $value ) ), [ 'me', 'myself', 'self' ], true ) ) ) {
			$me = Access::me();

			return $me > 0 ? $me : self::refused();
		}
		$id = UserRef::resolve( $value );
		if ( $id <= 0 && is_string( $value ) && trim( $value ) !== '' ) {
			$ids = get_users( [ 'search' => trim( $value ), 'search_columns' => [ 'display_name' ], 'fields' => 'ID', 'number' => 2 ] );
			$id  = count( $ids ) === 1 ? (int) $ids[0] : 0;
		}
		if ( $id <= 0 || ! get_userdata( $id ) instanceof \WP_User ) {
			// The same answer as a refusal: no probing for who exists.
			return self::refused();
		}

		return $id;
	}

	/**
	 * The owner's profiles the caller may read; refused when it may read none.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function readable( int $owner ) {
		$actor  = Access::actor();
		$person = Access::person();
		if ( Access::relation( $owner, $actor, $person ) !== '' ) {
			return Profiles::forOwner( $owner );
		}
		$scopes = new Scopes();
		$out    = array_values( array_filter( Profiles::forOwner( $owner ), static fn ( array $r ): bool => $actor > 0 && $scopes->canRead( $actor, (int) $r['scope_id'] ) ) );

		return $out !== [] ? $out : self::refused();
	}

	/**
	 * The best profile for a context and language among $records, and a
	 * note on the fallback ('' for an exact match).
	 *
	 * @param array<int, array<string, mixed>> $records
	 * @return array{0: array<string, mixed>|null, 1: string}
	 */
	private static function pick( array $records, string $context, string $language ): array {
		$by = [];
		foreach ( $records as $record ) {
			if ( $record['context'] !== Contexts::GLOBAL_CONTEXT ) {
				$by[ $record['context'] ][ $record['language'] ] = $record;
			}
		}
		$inLanguage = static function ( array $variants ) use ( $language ): ?array {
			return $variants[ $language ] ?? $variants[ Contexts::ANY_LANGUAGE ] ?? null;
		};

		if ( isset( $by[ $context ] ) ) {
			$exact = $inLanguage( $by[ $context ] );
			if ( $exact !== null ) {
				return [ $exact, '' ];
			}
			$other = reset( $by[ $context ] );
			/* translators: 1: context, 2: language asked for, 3: language used. */
			return [ $other, sprintf( __( 'No %1$s profile in %2$s; this is the %1$s profile in %3$s.', 'tomatio' ), $context, $language, (string) $other['language'] ) ];
		}

		$near = Contexts::nearest( $context, array_keys( $by ) );
		foreach ( $near as $candidate ) {
			$hit = $inLanguage( $by[ $candidate ] );
			if ( $hit !== null ) {
				/* translators: 1: context asked for, 2: nearest context used. */
				return [ $hit, sprintf( __( 'No %1$s profile yet; this is the nearest one, %2$s. Adjust for the difference, and learn %1$s samples when you can.', 'tomatio' ), $context, $candidate ) ];
			}
		}
		foreach ( $near as $candidate ) {
			$hit = reset( $by[ $candidate ] );
			if ( is_array( $hit ) ) {
				/* translators: 1: context asked for, 2: nearest context used, 3: its language. */
				return [ $hit, sprintf( __( 'No %1$s profile yet; this is the nearest one, %2$s, in %3$s.', 'tomatio' ), $context, $candidate, (string) $hit['language'] ) ];
			}
		}

		return [ null, '' ];
	}

	/**
	 * The owner's global part among $records.
	 *
	 * @param array<int, array<string, mixed>> $records
	 * @return array<string, mixed>|null
	 */
	private static function global( array $records, string $language ): ?array {
		$any = null;
		foreach ( $records as $record ) {
			if ( $record['context'] === Contexts::GLOBAL_CONTEXT ) {
				if ( $record['language'] === $language ) {
					return $record;
				}
				$any = $any ?? $record;
			}
		}

		return $any;
	}

	/**
	 * @param array<string, mixed> $global
	 * @return array<string, mixed>
	 */
	private static function globalPart( array $global ): array {
		return [
			'readable' => $global['readable'],
			'banned'   => Measure::normalize( $global['measured'] )['banned'],
		];
	}

	/**
	 * A short paragraph an agent can follow as it is.
	 *
	 * @param array<string, mixed>      $profile
	 * @param array<string, mixed>|null $global
	 */
	private static function guidance( array $profile, ?array $global, string $name ): string {
		$r     = Profile::readable( $profile['readable'] );
		$parts = [];
		foreach ( [ 'tone' => 'tone', 'greeting' => 'greeting', 'closing' => 'closing', 'emoji' => 'emoji', 'punctuation' => 'punctuation' ] as $field => $label ) {
			if ( $r[ $field ] !== '' ) {
				$parts[] = $label . ': ' . $r[ $field ];
			}
		}
		if ( $r['formality'] !== null ) {
			$parts[] = 'formality ' . $r['formality'] . ' of 5';
		}
		$summary = Measure::summary( $profile['measured'] );
		if ( $summary['samples'] >= 3 ) {
			$parts[] = sprintf( 'sentences of about %d words, messages of about %d words', (int) round( $summary['sentence_words']['mean'] ), (int) round( $summary['message_words']['mean'] ) );
		}
		$avoid = $r['avoid'];
		if ( $global !== null ) {
			$avoid = array_merge( $avoid, Profile::readable( $global['readable'] )['avoid'] );
		}
		$banned = array_merge( $summary['banned'], $global !== null ? Measure::normalize( $global['measured'] )['banned'] : [] );

		/* translators: 1: owner's name, 2: context. */
		$text = sprintf( __( 'Write as %1$s does in %2$s', 'tomatio' ), $name, (string) $profile['context'] );
		$text .= $parts !== [] ? ': ' . implode( '; ', $parts ) . '.' : '.';
		if ( $r['signature_moves'] !== [] ) {
			$text .= ' Moves: ' . implode( '; ', array_slice( $r['signature_moves'], 0, 3 ) ) . '.';
		}
		if ( $avoid !== [] ) {
			$text .= ' Avoid: ' . implode( '; ', array_slice( array_values( array_unique( $avoid ) ), 0, 8 ) ) . '.';
		}
		if ( $banned !== [] ) {
			$text .= ' Never use: ' . implode( ', ', array_slice( array_values( array_unique( $banned ) ), 0, 15 ) ) . '.';
		}
		$text .= ' ' . __( 'Then run tomatio-voice-check on the draft, and when the person edits it before sending, give the before and after to tomatio-voice-learn.', 'tomatio' );

		return mb_substr( $text, 0, 1500 );
	}

	/**
	 * Owner, context and language of a write, once the caller may write.
	 *
	 * @param array<string, mixed> $input
	 * @return array{0: int, 1: string, 2: string}|WP_Error
	 */
	private static function writable( array $input ) {
		$owner = self::owner( $input['owner'] ?? null );
		if ( $owner instanceof WP_Error ) {
			return $owner;
		}
		if ( ! Access::canWrite( $owner, Access::actor(), Access::person() ) ) {
			return self::refused();
		}
		$context = Contexts::normalize( (string) ( $input['context'] ?? '' ) );
		if ( $context === '' ) {
			return self::invalid( __( 'context is required, such as email-external, linkedin-dm, or global for the rules of every context.', 'tomatio' ) );
		}

		return [ $owner, $context, Contexts::language( (string) ( $input['language'] ?? '' ) ) ];
	}

	/** @return array<string, mixed> */
	private static function recordFor( int $owner, string $context, string $language ): array {
		$found = Profiles::find( $owner, $context, $language );
		if ( $found !== null ) {
			return $found;
		}
		$user = get_userdata( $owner );

		return Profile::blank( $owner, $user instanceof \WP_User ? (string) $user->user_login : '', $context, $language );
	}

	/**
	 * Where the record goes: scope_id when given (a scope the caller or the
	 * person behind it may write), else where it is, else the owner's
	 * private scope.
	 *
	 * @param array<string, mixed> $record
	 * @param array<string, mixed> $input
	 * @return int|WP_Error
	 */
	private static function scopeFor( array $record, int $owner, array $input ) {
		$asked = (int) ( $input['scope_id'] ?? 0 );
		if ( $asked > 0 ) {
			$scopes = new Scopes();
			$person = Access::person();
			if ( ! $scopes->canWrite( Access::actor(), $asked ) && ! ( $person > 0 && $scopes->canWrite( $person, $asked ) ) ) {
				return new WP_Error( 'ab_forbidden', __( 'You may not write to that scope.', 'tomatio' ), [ 'status' => 403 ] );
			}

			return $asked;
		}
		if ( (int) ( $record['scope_id'] ?? 0 ) > 0 ) {
			return (int) $record['scope_id'];
		}
		$home = Access::homeScope( $owner );

		return $home > 0
			? $home
			: new WP_Error( 'ab_voice_no_scope', __( 'The owner has no private scope to keep a voice profile in. Pass scope_id: a scope you may write; its readers can then read the profile (right for a team or brand voice).', 'tomatio' ), [ 'status' => 409 ] );
	}

	/** Who is writing, as shifts and pending fields name them. */
	private static function by(): string {
		$actor  = get_userdata( Access::actor() );
		$person = Access::person();
		$name   = $actor instanceof \WP_User ? (string) $actor->display_name : '';
		if ( $person > 0 && $person !== Access::actor() ) {
			$who  = get_userdata( $person );
			$name = trim( $name . ( $who instanceof \WP_User ? ' for ' . $who->display_name : '' ) );
		}

		return $name;
	}

	/**
	 * @param mixed $value
	 * @return array<int, string>
	 */
	private static function strings( $value ): array {
		if ( is_string( $value ) ) {
			$value = [ $value ];
		}

		return is_array( $value ) ? array_values( array_filter( array_map( static fn ( $v ): string => is_string( $v ) ? $v : '', $value ), static fn ( string $s ): bool => trim( $s ) !== '' ) ) : [];
	}

	private static function learnHint(): string {
		return __( 'To build one: send texts the owner wrote to tomatio-voice-learn (they are measured and dropped), or import their MYNAH profiles with tomatio-voice-import.', 'tomatio' );
	}

	private static function audit( string $event, int $memoryId, int $owner, string $context, string $language ): void {
		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => Access::actor(),
				'event'       => $event,
				'status'      => 'ok',
				'object_type' => 'ab_memory',
				'object_id'   => (string) $memoryId,
				'meta'        => [ 'owner' => $owner, 'context' => $context, 'language' => $language ],
			]
		);
	}

	private static function needsPerson( int $owner ): WP_Error {
		$user = get_userdata( $owner );

		return new WP_Error(
			'ab_voice_needs_person',
			/* translators: 1: owner's name, 2: page address. */
			sprintf( __( 'Only %1$s, their lead or a curator decides this: on their profile page (%2$s), or through their AI on a connection with delegated authority.', 'tomatio' ), $user instanceof \WP_User ? (string) $user->display_name : '', ProfileScreen::url( $owner ) ),
			[ 'status' => 403 ]
		);
	}

	private static function refused(): WP_Error {
		return new WP_Error( 'ab_forbidden', __( 'You may not read or change this voice. A voice profile is for its owner, the agents working for them, their lead and curators.', 'tomatio' ), [ 'status' => 403 ] );
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'ab_invalid_input', $message, [ 'status' => 422 ] );
	}

	private static function failed(): WP_Error {
		return new WP_Error( 'ab_voice_not_saved', __( 'The voice profile could not be saved.', 'tomatio' ), [ 'status' => 500 ] );
	}
}
