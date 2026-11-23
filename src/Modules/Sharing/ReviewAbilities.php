<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;
use WP_Error;

/**
 * #886: artifact-review-get / -set, the review page of a section artifact
 * over MCP. -set writes what the page leads with (summary, decisions,
 * visual), for the artifact's lead, curators and managers; -get reads the
 * page as its reviewers see it, answers included, for them and the lead.
 * Either one counts the person behind a role-switching connection (the
 * artifact-settings rule, ArtifactSettings::decider()).
 */
final class ReviewAbilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$decision = [
			'type'       => 'object',
			'properties' => [
				'id'             => [ 'type' => 'string', 'description' => 'Keep the same id when you rewrite a decision, so its answer stays; d1, d2... when left out.' ],
				'title'          => [ 'type' => 'string', 'description' => 'The question, in a few plain words.' ],
				'recommendation' => [ 'type' => 'string', 'description' => 'What you recommend, and why, in a sentence or two.' ],
				'alternative'    => [ 'type' => 'string', 'description' => 'The main alternative, and what it would cost.' ],
				'section_id'     => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'The section the decision belongs to (its answer becomes a comment there); 0 for the whole artifact.' ],
				'clear_answer'   => [ 'type' => 'boolean', 'description' => 'Open the decision again (after you answered a question in the comments).' ],
			],
			'required'   => [ 'title', 'recommendation' ],
		];

		AbilityNames::registerOnce(
			AbilityNames::ARTIFACT_REVIEW_GET,
			[
				'label'               => __( 'Read an artifact\'s review page', 'tomatio' ),
				'description'         => __( 'The review page of a section artifact, as its reviewers see it at review_url (its members address with ?review): the summary, the decisions with their answers (accept, change with a comment, or ask with a question; each answer is also a comment on its section, and accept and change are TíoDay decisions of the person who answered), the visual, and every section with its brief and state (drafting, submitted, changes_requested with the notes, approved). Read it after a person reviewed, then act on the answers: rewrite what they changed, answer questions with comment-reply and reopen the decision with artifact-review-set clear_answer. For the artifact\'s lead, curators, managers and the people who review it.', 'tomatio' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
					],
					'required'   => [ 'artifact_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'artifact_id'        => [ 'type' => 'integer' ],
						'title'              => [ 'type' => 'string' ],
						'review_url'         => [ 'type' => 'string' ],
						'summary'            => [ 'type' => 'string' ],
						'decisions'          => [ 'type' => 'array' ],
						'open_decisions'     => [ 'type' => 'integer' ],
						'visual_artifact_id' => [ 'type' => 'integer' ],
						'visual_url'         => [ 'type' => 'string' ],
						'sections'           => [ 'type' => 'array' ],
						'waiting'            => [ 'type' => 'integer' ],
						'approved'           => [ 'type' => 'integer' ],
					],
					'required'   => [ 'artifact_id', 'review_url', 'summary', 'decisions', 'sections' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input, false ),
				'execute_callback'    => static function ( $input ) {
					$artifactId = is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0;
					if ( ! ReviewBrief::applies( $artifactId ) ) {
						return self::notApplicable();
					}

					return ReviewBrief::describe( $artifactId );
				},
			]
		);

		AbilityNames::registerOnce(
			AbilityNames::ARTIFACT_REVIEW_SET,
			[
				'label'               => __( 'Write an artifact\'s review page', 'tomatio' ),
				'description'         => sprintf(
					/* translators: 1: most decisions, 2: summary length. */
					__( 'Write what the review page of a section artifact leads with, so a person can review it from one page (review_url) and never in wp-admin. Do it for every design note, plan or proposal you submit for review, before or right after section-submit: summary (one plain-words paragraph on what the artifact proposes and why, up to %2$d characters, no jargon), decisions (each open question: title, recommendation, alternative and the section_id it belongs to; at most %1$d; the person answers each with accept, change or ask), and visual_artifact_id (an HTML artifact you made with a diagram or visual summary, or 0 for none). Only the keys you give change; decisions replaces the whole list, and a decision keeps its answer while its id stays. Then put review_url first in the links of the TíoDay task that asks for the review. For the artifact\'s lead, curators and managers.', 'tomatio' ),
					ReviewBrief::MAX_DECISIONS,
					ReviewBrief::MAX_SUMMARY
				),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id'        => [ 'type' => 'integer', 'minimum' => 1 ],
						'summary'            => [ 'type' => 'string', 'description' => 'One paragraph, plain words, shown first.' ],
						'decisions'          => [
							'type'        => [ 'string', 'array' ],
							'items'       => $decision,
							'description' => 'The open decisions, in the order to answer them. Replaces the list.',
						],
						'visual_artifact_id' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'An artifact shown under the summary (an HTML page with a diagram or visual summary); 0 removes it.' ],
					],
					'required'   => [ 'artifact_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer' ],
						'review_url'  => [ 'type' => 'string' ],
						'summary'     => [ 'type' => 'string' ],
						'decisions'   => [ 'type' => 'array' ],
					],
					'required'   => [ 'artifact_id', 'review_url' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input, true ),
				'execute_callback'    => static function ( $input ) {
					$input      = is_array( $input ) ? $input : [];
					$artifactId = (int) ( $input['artifact_id'] ?? 0 );
					$fields     = array_intersect_key( $input, array_flip( [ 'summary', 'decisions', 'visual_artifact_id' ] ) );
					if ( $fields === [] ) {
						return new WP_Error( 'ab_review_invalid', __( 'Give at least one of summary, decisions or visual_artifact_id.', 'tomatio' ), [ 'status' => 400 ] );
					}

					return ShareAbilities::withLimiter(
						static function () use ( $artifactId, $fields ) {
							$brief = ReviewBrief::set( $artifactId, $fields, ShareAbilities::actorId() );
							if ( $brief instanceof WP_Error ) {
								return $brief;
							}

							return [
								'artifact_id'        => $artifactId,
								'review_url'         => ReviewBrief::url( $artifactId ),
								'summary'            => $brief['summary'],
								'decisions'          => $brief['decisions'],
								'visual_artifact_id' => $brief['visual_artifact_id'],
							];
						}
					);
				},
			]
		);
	}

	/**
	 * Write: the lead, a curator or a manager (as the caller or the person
	 * behind a role-switching connection). Read: those, or a reviewer of the
	 * artifact. Discovery passes no artifact_id: "may manage any".
	 *
	 * @param mixed $input
	 */
	private static function can( $input, bool $write ): bool {
		if ( ! ShareAbilities::canUseMcp() ) {
			return false;
		}

		$artifactId = is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0;
		foreach ( array_unique( [ ShareAbilities::actorId(), ArtifactSettings::decider() ] ) as $userId ) {
			if ( $artifactId <= 0 ) {
				if ( ShareLinks::canManageAny( $userId ) ) {
					return true;
				}
				continue;
			}
			if ( ReviewBrief::canSet( $userId, $artifactId ) || ( ! $write && ReviewBrief::canReview( $userId, $artifactId ) ) ) {
				return true;
			}
		}

		return false;
	}

	private static function notApplicable(): WP_Error {
		return new WP_Error( 'ab_review_not_applicable', __( 'Only a section artifact has a review page; an HTML page is reviewed as a version (artifact-html-approve).', 'tomatio' ), [ 'status' => 422 ] );
	}
}
