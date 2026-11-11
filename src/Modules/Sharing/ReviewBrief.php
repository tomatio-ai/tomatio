<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\ArtifactService;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\Artifacts\Stages;
use AgentBrain\Modules\Comments\CommentService;
use AgentBrain\Modules\DataStore\TioDay;
use WP_Error;

/**
 * #886: what a section artifact's review page leads with, written by its
 * lead agent: a plain-words summary, the open decisions, and an optional
 * visual. Stored on the artifact (post meta ab_review_brief, JSON), not as
 * a section: it is about the review, never part of the document, so it is
 * neither reviewed nor compiled.
 *
 *  - summary:  one paragraph of plain text.
 *  - decisions: [{id, title, recommendation, alternative, section_id, answer}],
 *    in the order the lead gives them. section_id (0: the whole artifact)
 *    ties a decision to the section it belongs to: its card links there and
 *    its answer is a comment on that section.
 *  - visual_artifact_id: another artifact (an HTML page such as a diagram
 *    or a visual summary) shown under the summary, for the people who may
 *    open it.
 *
 * An answer (accept, change with a comment, or ask) is kept on its
 * decision until the lead clears it, recorded as a comment on the section
 * and, for accept and change, as a decision on the answering person's
 * TíoDay when they have one (any brain; nothing is recorded there when they
 * have none).
 */
final class ReviewBrief {

	public const META = 'ab_review_brief';

	public const MAX_SUMMARY   = 1500;
	public const MAX_TITLE     = 200;
	public const MAX_TEXT      = 1000;
	public const MAX_COMMENT   = 2000;
	public const MAX_DECISIONS = 12;

	public const VERDICTS = [ 'accept', 'change', 'ask' ];

	private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/';

	/** The review page of an artifact: its members address with ?review. */
	public static function url( int $artifactId ): string {
		return MembersAddress::url( $artifactId ) . '?review';
	}

	/**
	 * Whether an artifact has a review page: a section artifact (not an HTML page).
	 */
	public static function applies( int $artifactId ): bool {
		$post = get_post( $artifactId );

		return $post instanceof \WP_Post && $post->post_type === Cpts::ARTIFACT_POST_TYPE && ! HtmlFiles::isHtml( $artifactId );
	}

	/**
	 * The stored brief, always in full shape.
	 *
	 * @return array{summary:string,decisions:array<int, array<string, mixed>>,visual_artifact_id:int,updated_at:string,updated_by:string}
	 */
	public static function get( int $artifactId ): array {
		$raw  = get_post_meta( $artifactId, self::META, true );
		$data = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : null;
		$data = is_array( $data ) ? $data : [];

		$decisions = [];
		foreach ( is_array( $data['decisions'] ?? null ) ? $data['decisions'] : [] as $decision ) {
			if ( is_array( $decision ) && is_string( $decision['id'] ?? null ) ) {
				$decisions[] = [
					'id'             => (string) $decision['id'],
					'title'          => (string) ( $decision['title'] ?? '' ),
					'recommendation' => (string) ( $decision['recommendation'] ?? '' ),
					'alternative'    => (string) ( $decision['alternative'] ?? '' ),
					'section_id'     => (int) ( $decision['section_id'] ?? 0 ),
					'answer'         => is_array( $decision['answer'] ?? null ) ? $decision['answer'] : null,
				];
			}
		}

		return [
			'summary'            => (string) ( $data['summary'] ?? '' ),
			'decisions'          => $decisions,
			'visual_artifact_id' => (int) ( $data['visual_artifact_id'] ?? 0 ),
			'updated_at'         => (string) ( $data['updated_at'] ?? '' ),
			'updated_by'         => (string) ( $data['updated_by'] ?? '' ),
		];
	}

	/** @param array<string, mixed> $brief */
	private static function save( int $artifactId, array $brief ): void {
		update_post_meta( $artifactId, self::META, wp_slash( (string) wp_json_encode( $brief ) ) );
	}

	/**
	 * Whether $userId may write the brief: the artifact's lead, a curator or
	 * a manager (the artifact-settings rule).
	 */
	public static function canSet( int $userId, int $artifactId ): bool {
		return ShareLinks::canManage( $userId, $artifactId );
	}

	/**
	 * Whether $userId may open the review page and answer its decisions:
	 * the people who may review the artifact (#754's reviewers: curators,
	 * managers, the lead and the person behind it, a reviewer with the
	 * approval grant). Approving a section keeps its own rule.
	 */
	public static function canReview( int $userId, int $artifactId ): bool {
		return $userId > 0 && self::applies( $artifactId ) && WaitingSections::canSee( $userId, $artifactId );
	}

	/**
	 * Change the summary, the decisions and the visual: only the keys
	 * $input gives. decisions replaces the whole list; a decision keeps its
	 * answer while its id stays, unless clear_answer is true.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error The brief after the change.
	 */
	public static function set( int $artifactId, array $input, int $actorId ) {
		if ( ! self::applies( $artifactId ) ) {
			return new WP_Error( 'ab_review_not_applicable', __( 'Only a section artifact has a review page; an HTML page is reviewed as a version (artifact-html-approve).', 'tomatio' ), [ 'status' => 422 ] );
		}

		$brief = self::get( $artifactId );

		if ( array_key_exists( 'summary', $input ) ) {
			if ( ! is_string( $input['summary'] ) ) {
				return self::invalid( __( 'summary is plain text, one paragraph.', 'tomatio' ) );
			}
			$brief['summary'] = self::text( $input['summary'], self::MAX_SUMMARY, true );
		}

		if ( array_key_exists( 'decisions', $input ) ) {
			$decisions = $input['decisions'];
			if ( is_string( $decisions ) ) {
				$decisions = json_decode( $decisions, true ); // #634: some clients send a list as its JSON text.
			}
			$normal = is_array( $decisions ) && array_is_list( $decisions ) ? self::normalizeDecisions( $artifactId, $decisions, $brief['decisions'] ) : self::invalid( __( 'decisions is a list of {title, recommendation, alternative, section_id}.', 'tomatio' ) );
			if ( $normal instanceof WP_Error ) {
				return $normal;
			}
			$brief['decisions'] = $normal;
		}

		if ( array_key_exists( 'visual_artifact_id', $input ) ) {
			$visual = is_numeric( $input['visual_artifact_id'] ) ? (int) $input['visual_artifact_id'] : -1;
			$post   = $visual > 0 ? get_post( $visual ) : null;
			if ( $visual < 0 || $visual === $artifactId || ( $visual > 0 && ( ! $post instanceof \WP_Post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE ) ) ) {
				return self::invalid( __( 'visual_artifact_id is another artifact (such as an HTML page with a diagram), or 0 for none.', 'tomatio' ) );
			}
			$brief['visual_artifact_id'] = $visual;
		}

		$brief['updated_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		$brief['updated_by'] = TioDay::name( $actorId );
		self::save( $artifactId, $brief );

		\AgentBrain\Core\Hooks::action( 'audit', [
			'actor_id'    => $actorId,
			'event'       => 'artifact_review_brief_set',
			'status'      => 'ok',
			'object_type' => Cpts::ARTIFACT_POST_TYPE,
			'object_id'   => (string) $artifactId,
			'meta'        => [ 'fields' => array_values( array_intersect( [ 'summary', 'decisions', 'visual_artifact_id' ], array_keys( $input ) ) ) ],
		] );

		return $brief;
	}

	/**
	 * @param array<int, mixed>                  $decisions
	 * @param array<int, array<string, mixed>>   $before
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function normalizeDecisions( int $artifactId, array $decisions, array $before ) {
		if ( count( $decisions ) > self::MAX_DECISIONS ) {
			/* translators: %d: the most decisions a review page shows. */
			return self::invalid( sprintf( __( 'A review page has at most %d decisions.', 'tomatio' ), self::MAX_DECISIONS ) );
		}

		$answers = [];
		foreach ( $before as $decision ) {
			$answers[ $decision['id'] ] = $decision['answer'];
		}

		$out  = [];
		$seen = [];
		foreach ( $decisions as $index => $decision ) {
			if ( ! is_array( $decision ) ) {
				return self::invalid( __( 'Each decision is an object {title, recommendation, alternative, section_id}.', 'tomatio' ) );
			}
			$title = self::text( $decision['title'] ?? '', self::MAX_TITLE );
			$rec   = self::text( $decision['recommendation'] ?? '', self::MAX_TEXT, true );
			if ( $title === '' || $rec === '' ) {
				/* translators: %d: the decision's position, from 1. */
				return self::invalid( sprintf( __( 'Decision %d needs a title and a recommendation.', 'tomatio' ), $index + 1 ) );
			}

			$id = isset( $decision['id'] ) && is_scalar( $decision['id'] ) ? strtolower( trim( (string) $decision['id'] ) ) : '';
			if ( $id === '' ) {
				$n = $index + 1;
				do {
					$id = 'd' . $n++;
				} while ( isset( $seen[ $id ] ) );
			} elseif ( preg_match( self::ID_PATTERN, $id ) !== 1 ) {
				return self::invalid( __( 'A decision id is lower-case letters, digits and hyphens, up to 32.', 'tomatio' ) );
			}
			if ( isset( $seen[ $id ] ) ) {
				/* translators: %s: a decision id. */
				return self::invalid( sprintf( __( 'Two decisions have the id "%s".', 'tomatio' ), $id ) );
			}
			$seen[ $id ] = true;

			$sectionId = isset( $decision['section_id'] ) && is_numeric( $decision['section_id'] ) ? (int) $decision['section_id'] : 0;
			if ( $sectionId !== 0 && ! self::isSectionOf( $sectionId, $artifactId ) ) {
				/* translators: %d: the decision's position, from 1. */
				return self::invalid( sprintf( __( 'Decision %d: section_id must be a section of this artifact (or 0 for the whole artifact).', 'tomatio' ), $index + 1 ) );
			}

			$out[] = [
				'id'             => $id,
				'title'          => $title,
				'recommendation' => $rec,
				'alternative'    => self::text( $decision['alternative'] ?? '', self::MAX_TEXT, true ),
				'section_id'     => $sectionId,
				'answer'         => ! empty( $decision['clear_answer'] ) ? null : ( $answers[ $id ] ?? null ),
			];
		}

		return $out;
	}

	public static function isSectionOf( int $sectionId, int $artifactId ): bool {
		$section = get_post( $sectionId );

		return $section instanceof \WP_Post && $section->post_type === Cpts::SECTION_POST_TYPE && (int) $section->post_parent === $artifactId;
	}

	/**
	 * Answer one open decision from the review page: accept the
	 * recommendation, change it (comment says how) or ask (comment is the
	 * question). Recorded on the decision, as a comment on its section (or
	 * the artifact) and, for accept and change, as a decision on the
	 * answering person's TíoDay when they have one.
	 *
	 * @return array{answer:array<string, mixed>}|WP_Error
	 */
	public static function answer( int $artifactId, string $decisionId, string $verdict, string $comment, int $actorId ) {
		if ( ! self::canReview( $actorId, $artifactId ) ) {
			return new WP_Error( 'ab_forbidden', __( 'Only the people who review this artifact can answer its decisions.', 'tomatio' ), [ 'status' => 403 ] );
		}
		if ( ! in_array( $verdict, self::VERDICTS, true ) ) {
			return self::invalid( __( 'The answer is accept, change or ask.', 'tomatio' ) );
		}

		$comment = self::text( $comment, self::MAX_COMMENT, true );
		if ( $verdict !== 'accept' && $comment === '' ) {
			return self::invalid( $verdict === 'change' ? __( 'Say what to change in the comment.', 'tomatio' ) : __( 'Write the question in the comment.', 'tomatio' ) );
		}

		$brief = self::get( $artifactId );
		$index = null;
		foreach ( $brief['decisions'] as $i => $decision ) {
			if ( $decision['id'] === $decisionId ) {
				$index = $i;
				break;
			}
		}
		if ( $index === null ) {
			return new WP_Error( 'ab_review_decision_not_found', __( 'That decision is not on this review page any more. Reload it.', 'tomatio' ), [ 'status' => 404 ] );
		}
		$decision = $brief['decisions'][ $index ];
		if ( is_array( $decision['answer'] ) ) {
			return new WP_Error( 'ab_review_decision_answered', __( 'This decision is already answered.', 'tomatio' ), [ 'status' => 409 ] );
		}

		$title     = (string) get_post( $artifactId )->post_title;
		$sectionId = $decision['section_id'] > 0 && self::isSectionOf( $decision['section_id'], $artifactId ) ? $decision['section_id'] : 0;

		$line = self::answerLine( $decision, $verdict, $comment );

		// The comment on the section: best effort, for people who may comment there.
		$commentId = null;
		$stored    = ( new CommentService() )->add( $actorId, $artifactId, $line, null, $sectionId > 0 ? $sectionId : null, 'decision:' . $decision['id'] );
		if ( is_array( $stored ) && isset( $stored['id'] ) ) {
			$commentId = (int) $stored['id'];
		}

		// The decision on the person's own TíoDay, when they have one.
		$tioday = null;
		if ( $verdict !== 'ask' ) {
			$page = TioDay::forPerson( $actorId );
			if ( $page > 0 ) {
				$recorded = TioDay::decided(
					$page,
					$actorId,
					[
						'title'  => self::clip( $title . ': ' . $decision['title'], TioDay::MAX_TITLE ),
						'detail' => self::clip( $line . "\n" . self::url( $artifactId ), TioDay::MAX_TEXT ),
						'by'     => TioDay::name( $actorId ),
					]
				);
				if ( is_array( $recorded ) ) {
					$tioday = is_string( $recorded['id'] ?? null ) ? $recorded['id'] : (string) ( $recorded['key'] ?? '' );
				}
			}
		}

		$answer = [
			'verdict'    => $verdict,
			'comment'    => $comment,
			'by'         => TioDay::name( $actorId ),
			'by_id'      => $actorId,
			'at'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'comment_id' => $commentId,
			'tioday'     => $tioday,
		];

		// Re-read before writing: the lead may have changed the list meanwhile.
		$fresh = self::get( $artifactId );
		foreach ( $fresh['decisions'] as $i => $item ) {
			if ( $item['id'] === $decisionId ) {
				$fresh['decisions'][ $i ]['answer'] = $answer;
			}
		}
		self::save( $artifactId, $fresh );

		\AgentBrain\Core\Hooks::action( 'audit', [
			'actor_id'    => $actorId,
			'event'       => 'artifact_review_decision',
			'status'      => 'ok',
			'object_type' => Cpts::ARTIFACT_POST_TYPE,
			'object_id'   => (string) $artifactId,
			'meta'        => [ 'decision' => $decisionId, 'verdict' => $verdict, 'section_id' => $sectionId ],
		] );

		return [ 'answer' => $answer ];
	}

	/**
	 * The words an answer is recorded with.
	 *
	 * @param array<string, mixed> $decision
	 */
	public static function answerLine( array $decision, string $verdict, string $comment ): string {
		$title = (string) $decision['title'];
		switch ( $verdict ) {
			case 'accept':
				/* translators: 1: decision title, 2: the recommendation. */
				$line = sprintf( __( 'Decision "%1$s": accepted the recommendation: %2$s', 'tomatio' ), $title, (string) $decision['recommendation'] );
				return $comment !== '' ? $line . "\n" . $comment : $line;
			case 'change':
				/* translators: 1: decision title, 2: what to do instead. */
				return sprintf( __( 'Decision "%1$s": change it: %2$s', 'tomatio' ), $title, $comment );
			default:
				/* translators: 1: decision title, 2: the question. */
				return sprintf( __( 'Decision "%1$s": question: %2$s', 'tomatio' ), $title, $comment );
		}
	}

	/**
	 * The artifact's outline sections with their review state, brief and
	 * the notes of the last request for changes.
	 *
	 * @return array<int, array{id:int,title:string,brief:string,state:string,notes:string}>
	 */
	public static function sections( int $artifactId ): array {
		$out = [];
		foreach ( array_unique( ( new ArtifactService() )->outlineSectionIds( $artifactId ) ) as $sectionId ) {
			if ( ! self::isSectionOf( (int) $sectionId, $artifactId ) ) {
				continue;
			}
			$state = Stages::sectionState( (int) $sectionId );
			$notes = '';
			if ( $state === Cpts::SECTION_STATE_CHANGES_REQUESTED ) {
				foreach ( array_reverse( Stages::reviewLog( (int) $sectionId ) ) as $entry ) {
					if ( in_array( $entry['verdict'], [ 'request_changes', 'reopen' ], true ) ) {
						$notes = (string) $entry['notes'];
						break;
					}
				}
			}
			$out[] = [
				'id'    => (int) $sectionId,
				'title' => (string) get_post( (int) $sectionId )->post_title,
				'brief' => (string) get_post_meta( (int) $sectionId, Cpts::META_SECTION_BRIEF, true ),
				'state' => $state,
				'notes' => $notes,
			];
		}

		return $out;
	}

	/**
	 * What section-submit adds to its result: the review page, and what to
	 * do next. Empty for an artifact with no review page.
	 *
	 * @return array{review_url?:string,next?:string}
	 */
	public static function submitHint( int $artifactId ): array {
		if ( $artifactId <= 0 || ! self::applies( $artifactId ) ) {
			return [];
		}

		$brief = self::get( $artifactId );
		$next  = $brief['summary'] === ''
			? __( 'The review page has no summary yet: write the summary and the open decisions with artifact-review-set, then link review_url from the TíoDay task that asks for the review.', 'tomatio' )
			: __( 'Link review_url from the TíoDay task that asks for the review, and keep the summary and decisions current with artifact-review-set.', 'tomatio' );

		return [
			'review_url' => self::url( $artifactId ),
			'next'       => $next,
		];
	}

	/**
	 * Everything the review page shows, for artifact-review-get.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe( int $artifactId ): array {
		$brief    = self::get( $artifactId );
		$sections = self::sections( $artifactId );
		$counts   = array_count_values( array_column( $sections, 'state' ) );
		$visual   = $brief['visual_artifact_id'];

		return [
			'artifact_id'        => $artifactId,
			'title'              => (string) get_post( $artifactId )->post_title,
			'review_url'         => self::url( $artifactId ),
			'summary'            => $brief['summary'],
			'decisions'          => $brief['decisions'],
			'open_decisions'     => count( array_filter( $brief['decisions'], static fn ( array $d ): bool => ! is_array( $d['answer'] ) ) ),
			'visual_artifact_id' => $visual,
			'visual_url'         => $visual > 0 ? MembersAddress::url( $visual ) : '',
			'sections'           => $sections,
			'waiting'            => (int) ( $counts[ Cpts::SECTION_STATE_SUBMITTED ] ?? 0 ),
			'approved'           => (int) ( $counts[ Cpts::SECTION_STATE_APPROVED ] ?? 0 ),
			'updated_at'         => $brief['updated_at'],
			'updated_by'         => $brief['updated_by'],
		];
	}

	/** @param mixed $value */
	private static function text( $value, int $max, bool $multiline = false ): string {
		return TioDay::text( $value, $max, $multiline );
	}

	private static function clip( string $text, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'ab_review_invalid', $message, [ 'status' => 400 ] );
	}
}
