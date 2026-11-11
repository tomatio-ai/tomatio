<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\AdminUi;
use AgentBrain\Modules\Artifacts\ArtifactService;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\Design;
use AgentBrain\Modules\DataStore\TioDay;
use WP_Error;

/**
 * #886: the review page of a section artifact, at its members address with
 * ?review (/a/{slug}?review), for the people who review it
 * (ReviewBrief::canReview). Everyone else is sent to the plain page.
 *
 * In this order: the lead's plain-words summary, the open decisions as
 * cards (accept, change with a comment, ask), the visual when there is
 * one, then every section folded under its heading with its brief and
 * state. Approve a section, all submitted ones at once, or request changes
 * with a note, all from here: nothing needs wp-admin (Dennis, 8 Nov 2026).
 *
 * One document, no scripts: sections fold with <details>, the buttons are
 * plain forms posting to admin-post.php with a nonce, and the page CSP
 * allows no script at all. Section content is the share page's static
 * rendering (active elements stripped, then wp_kses), never the
 * interactive one. The visual is the other artifact's members frame,
 * sandboxed exactly as on its own address, shown only to people who may
 * open that address.
 */
final class ReviewPage {

	public const PARAM = 'review';

	public const ACTION_SECTION  = 'ab_review_section';
	public const ACTION_DECISION = 'ab_review_decision';

	private const NONCE = 'ab_review_';

	/** Query flags the handlers return with. */
	public const DONE  = 'ab_done';
	public const ERROR = 'ab_error';

	/**
	 * The page for $actorId, or null when they may not review (the caller
	 * then shows the plain page).
	 *
	 * @param array<string, mixed> $query
	 * @param array<string, string> $headers
	 * @return array{status:int,headers:array<string,string>,body:string}|null
	 */
	public static function respond( int $artifactId, int $actorId, array $query, array $headers ): ?array {
		if ( ! ReviewBrief::canReview( $actorId, $artifactId ) ) {
			return null;
		}

		$headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data: https:; frame-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'none'";

		return [
			'status'  => 200,
			'headers' => $headers,
			'body'    => self::render( $artifactId, $actorId, $query ),
		];
	}

	/**
	 * @param array<string, mixed> $query
	 */
	public static function render( int $artifactId, int $actorId, array $query = [] ): string {
		$artifact = get_post( $artifactId );
		$title    = (string) $artifact->post_title;
		$brief    = ReviewBrief::get( $artifactId );
		$sections = ReviewBrief::sections( $artifactId );
		$counts   = array_count_values( array_column( $sections, 'state' ) );
		$waiting  = (int) ( $counts[ Cpts::SECTION_STATE_SUBMITTED ] ?? 0 );
		$approved = (int) ( $counts[ Cpts::SECTION_STATE_APPROVED ] ?? 0 );
		$lead     = (int) get_post_meta( $artifactId, Cpts::META_LEAD_AGENT, true );
		$open     = count( array_filter( $brief['decisions'], static fn ( array $d ): bool => ! is_array( $d['answer'] ) ) );

		$meta = [
			/* translators: %d: number of sections. */
			sprintf( _n( '%d section', '%d sections', count( $sections ), 'tomatio' ), count( $sections ) ),
			/* translators: %d: number of sections submitted for review. */
			sprintf( _n( '%d waiting for review', '%d waiting for review', $waiting, 'tomatio' ), $waiting ),
			/* translators: %d: number of approved sections. */
			sprintf( _n( '%d approved', '%d approved', $approved, 'tomatio' ), $approved ),
		];
		if ( $lead > 0 ) {
			/* translators: %s: the lead agent's name. */
			$meta[] = sprintf( __( 'lead: %s', 'tomatio' ), self::shortName( $lead ) );
		}

		$body  = Design::brandLine();
		$body .= '<p class="ab-kicker">' . esc_html__( 'Review', 'tomatio' ) . '</p>';
		$body .= '<h1>' . esc_html( $title ) . '</h1>';
		$body .= '<p class="ab-meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
		$body .= self::flash( $query );

		// 1. Summary.
		$body .= '<section class="ab-rv-block" aria-labelledby="ab-rv-summary"><h2 id="ab-rv-summary">' . esc_html__( 'Summary', 'tomatio' ) . '</h2>';
		$body .= $brief['summary'] !== ''
			? '<p class="ab-lede">' . nl2br( esc_html( $brief['summary'] ) ) . '</p>'
			: '<p class="ab-rv-empty">' . esc_html__( 'No summary yet. The lead agent writes one with artifact-review-set.', 'tomatio' ) . '</p>';
		$body .= '</section>';

		// 2. Decisions.
		if ( $brief['decisions'] !== [] ) {
			$body .= '<section class="ab-rv-block" aria-labelledby="ab-rv-decisions"><h2 id="ab-rv-decisions">'
				. esc_html(
					$open > 0
						/* translators: %d: number of open decisions. */
						? sprintf( _n( 'Decision (%d open)', 'Decisions (%d open)', count( $brief['decisions'] ), 'tomatio' ), $open )
						: _n( 'Decision (answered)', 'Decisions (all answered)', count( $brief['decisions'] ), 'tomatio' )
				) . '</h2>';
			$titles = array_column( $sections, 'title', 'id' );
			foreach ( $brief['decisions'] as $n => $decision ) {
				$body .= self::decisionCard( $artifactId, $decision, $n + 1, $titles );
			}
			$body .= '</section>';
		}

		// 3. Visual.
		$visual = $brief['visual_artifact_id'];
		if ( $visual > 0 && ShareLinks::isServable( get_post( $visual ) ) && MembersAddress::canSeeAddress( $actorId, $visual ) ) {
			$visualTitle = (string) get_post( $visual )->post_title;
			$body       .= '<section class="ab-rv-block" aria-labelledby="ab-rv-visual"><h2 id="ab-rv-visual">' . esc_html__( 'Visual', 'tomatio' ) . '</h2>'
				. '<div class="ab-rv-visual"><iframe sandbox="allow-scripts" referrerpolicy="no-referrer" loading="lazy" title="' . esc_attr( $visualTitle ) . '" src="' . esc_url( MembersAddress::url( $visual ) . '?frame=1' ) . '"></iframe></div>'
				. '<p class="ab-meta"><a href="' . esc_url( MembersAddress::url( $visual ) ) . '">'
				/* translators: %s: the visual's title. */
				. esc_html( sprintf( __( 'Open %s on its own page', 'tomatio' ), $visualTitle ) ) . '</a></p></section>';
		}

		// 4. Sections, folded.
		$body .= '<section class="ab-rv-block" aria-labelledby="ab-rv-sections"><h2 id="ab-rv-sections">' . esc_html__( 'Sections', 'tomatio' ) . '</h2>';
		$mine  = WaitingSections::approvable( $actorId, WaitingSections::outline( $artifactId )['waiting'] );
		if ( count( $mine ) > 1 ) {
			$body .= '<form class="ab-rv-all" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="' . esc_attr( WaitingSections::ACTION ) . '">'
				. '<input type="hidden" name="artifact_id" value="' . esc_attr( (string) $artifactId ) . '">'
				. '<input type="hidden" name="back" value="' . esc_attr( self::PARAM ) . '">'
				. wp_nonce_field( WaitingSections::NONCE . $artifactId, '_wpnonce', false, false )
				/* translators: %d: number of sections. */
				. '<button type="submit" class="ab-btn ab-btn--go">' . esc_html( sprintf( __( 'Approve all %d submitted sections', 'tomatio' ), count( $mine ) ) ) . '</button></form>';
		}
		if ( $sections === [] ) {
			$body .= '<p class="ab-rv-empty">' . esc_html__( 'This artifact has no sections yet.', 'tomatio' ) . '</p>';
		}
		$openId = isset( $query['section'] ) && is_string( $query['section'] ) && ctype_digit( $query['section'] ) ? (int) $query['section'] : 0;
		foreach ( $sections as $n => $section ) {
			$body .= self::sectionFold( $artifactId, $actorId, $section, $n + 1, $openId === $section['id'] );
		}
		$body .= '</section>';

		$body .= '<p class="ab-footer"><a href="' . esc_url( MembersAddress::url( $artifactId ) ) . '">' . esc_html__( 'Open the page as members see it', 'tomatio' ) . '</a></p>';

		$icon = MembersAddress::url( $artifactId ) . '?icon=1';

		return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<meta name="referrer" content="no-referrer">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			/* translators: %s: the artifact's title. */
			. '<title>' . esc_html( sprintf( __( 'Review: %s', 'tomatio' ), $title ) ) . '</title>'
			. '<link rel="icon" type="image/png" href="' . esc_url( $icon ) . '"><link rel="apple-touch-icon" href="' . esc_url( $icon ) . '">'
			. Design::styleTag()
			. '<style>' . self::css() . '</style>'
			. '</head><body class="ab-doc"><main class="ab-page ab-review">' . $body . '</main></body></html>';
	}

	/**
	 * @param array<string, mixed> $decision
	 * @param array<int, string>   $titles section id => title
	 */
	private static function decisionCard( int $artifactId, array $decision, int $n, array $titles ): string {
		$id     = (string) $decision['id'];
		$answer = is_array( $decision['answer'] ) ? $decision['answer'] : null;

		$labels = [
			'accept' => [ __( 'Accepted', 'tomatio' ), 'done' ],
			'change' => [ __( 'Changed', 'tomatio' ), 'decision' ],
			'ask'    => [ __( 'Asked', 'tomatio' ), 'in-progress' ],
		];

		$out  = '<article class="ab-card ab-card--accent ab-rv-decision" id="decision-' . esc_attr( $id ) . '">';
		/* translators: %d: the decision's number. */
		$out .= '<p class="ab-rv-tags"><span class="ab-status ab-status--decision">' . esc_html( sprintf( __( 'Decision %d', 'tomatio' ), $n ) ) . '</span>';
		if ( $answer !== null && isset( $labels[ $answer['verdict'] ?? '' ] ) ) {
			[ $label, $class ] = $labels[ $answer['verdict'] ];
			$out              .= '<span class="ab-status ab-status--' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
		}
		$sectionId = (int) $decision['section_id'];
		if ( $sectionId > 0 && isset( $titles[ $sectionId ] ) ) {
			/* translators: %s: a section title. */
			$out .= '<a class="ab-rv-in" href="' . esc_url( ReviewBrief::url( $artifactId ) . '&section=' . $sectionId . '#section-' . $sectionId ) . '">' . esc_html( sprintf( __( 'in %s', 'tomatio' ), $titles[ $sectionId ] ) ) . '</a>';
		}
		$out .= '</p>';
		$out .= '<h3>' . esc_html( (string) $decision['title'] ) . '</h3>';
		$out .= '<div class="ab-rv-choice ab-rv-choice--rec"><span class="ab-rv-k">' . esc_html__( 'Recommended', 'tomatio' ) . '</span><p>' . nl2br( esc_html( (string) $decision['recommendation'] ) ) . '</p></div>';
		if ( (string) $decision['alternative'] !== '' ) {
			$out .= '<div class="ab-rv-choice"><span class="ab-rv-k">' . esc_html__( 'Alternative', 'tomatio' ) . '</span><p>' . nl2br( esc_html( (string) $decision['alternative'] ) ) . '</p></div>';
		}

		if ( $answer !== null ) {
			$who = (string) ( $answer['by'] ?? '' );
			$at  = strtotime( (string) ( $answer['at'] ?? '' ) );
			$out .= '<div class="ab-callout ab-callout--' . ( ( $answer['verdict'] ?? '' ) === 'ask' ? 'warning' : 'success' ) . '"><p>';
			$out .= esc_html(
				trim(
					sprintf(
						/* translators: 1: answer label, 2: who, 3: date. */
						__( '%1$s by %2$s, %3$s.', 'tomatio' ),
						$labels[ $answer['verdict'] ?? 'accept' ][0] ?? '',
						$who,
						$at ? wp_date( 'j M Y', $at ) : ''
					)
				)
			);
			if ( is_string( $answer['tioday'] ?? null ) && $answer['tioday'] !== '' ) {
				/* translators: %s: a TíoDay decision ID such as C12. */
				$out .= ' ' . esc_html( sprintf( __( 'On TíoDay as %s.', 'tomatio' ), $answer['tioday'] ) );
			}
			$out .= '</p>';
			if ( (string) ( $answer['comment'] ?? '' ) !== '' ) {
				$out .= '<p>' . nl2br( esc_html( (string) $answer['comment'] ) ) . '</p>';
			}
			if ( ( $answer['verdict'] ?? '' ) === 'ask' ) {
				$out .= '<p class="ab-meta">' . esc_html__( 'The lead answers in the comments and opens the decision again.', 'tomatio' ) . '</p>';
			}
			$out .= '</div>';

			return $out . '</article>';
		}

		$field = 'ab-rv-c-' . $id;
		$out  .= '<form class="ab-rv-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DECISION ) . '">'
			. '<input type="hidden" name="artifact_id" value="' . esc_attr( (string) $artifactId ) . '">'
			. '<input type="hidden" name="decision" value="' . esc_attr( $id ) . '">'
			. wp_nonce_field( self::NONCE . $artifactId, '_wpnonce', false, false )
			. '<label for="' . esc_attr( $field ) . '">' . esc_html__( 'Comment (needed to change it or ask)', 'tomatio' ) . '</label>'
			. '<textarea id="' . esc_attr( $field ) . '" name="comment" rows="2" maxlength="' . ReviewBrief::MAX_COMMENT . '"></textarea>'
			. '<div class="ab-rv-buttons">'
			. '<button type="submit" name="answer" value="accept" class="ab-btn ab-btn--go">' . esc_html__( 'Accept', 'tomatio' ) . '</button>'
			. '<button type="submit" name="answer" value="change" class="ab-btn">' . esc_html__( 'Change', 'tomatio' ) . '</button>'
			. '<button type="submit" name="answer" value="ask" class="ab-btn ab-btn--quiet">' . esc_html__( 'Ask', 'tomatio' ) . '</button>'
			. '</div></form>';

		return $out . '</article>';
	}

	/**
	 * @param array{id:int,title:string,brief:string,state:string,notes:string} $section
	 */
	private static function sectionFold( int $artifactId, int $actorId, array $section, int $n, bool $open ): string {
		$states = [
			Cpts::SECTION_STATE_SUBMITTED         => [ __( 'Waiting for review', 'tomatio' ), 'in-progress' ],
			Cpts::SECTION_STATE_APPROVED          => [ __( 'Approved', 'tomatio' ), 'done' ],
			Cpts::SECTION_STATE_CHANGES_REQUESTED => [ __( 'Changes requested', 'tomatio' ), 'blocked' ],
			Cpts::SECTION_STATE_DRAFTING          => [ __( 'Being written', 'tomatio' ), 'idea' ],
		];
		[ $label, $class ] = $states[ $section['state'] ] ?? $states[ Cpts::SECTION_STATE_DRAFTING ];

		$out  = '<details class="ab-rv-section" id="section-' . (int) $section['id'] . '"' . ( $open ? ' open' : '' ) . '>';
		$out .= '<summary><span class="ab-rv-head"><span class="ab-rv-n">' . (int) $n . '</span><span class="ab-rv-title">' . esc_html( $section['title'] ) . '</span>'
			. '<span class="ab-status ab-status--' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span></span>';
		if ( $section['brief'] !== '' ) {
			$out .= '<span class="ab-rv-brief">' . esc_html( $section['brief'] ) . '</span>';
		}
		$out .= '</summary><div class="ab-rv-body">';

		if ( $section['notes'] !== '' ) {
			$out .= '<div class="ab-callout ab-callout--warning"><p><strong>' . esc_html__( 'Changes requested:', 'tomatio' ) . '</strong> ' . nl2br( esc_html( $section['notes'] ) ) . '</p></div>';
		}

		if ( $section['state'] === Cpts::SECTION_STATE_DRAFTING ) {
			$out .= '<p class="ab-rv-empty">' . esc_html__( 'Still being written: nothing to review yet.', 'tomatio' ) . '</p>';
		} else {
			$post = get_post( $section['id'] );
			$out .= '<div class="ab-rv-content">' . wp_kses( ShareRoute::stripActiveElements( (string) $post->post_content ), ShareRoute::allowedHtml(), [ 'https' ] ) . '</div>';
		}

		$actions = AdminUi::reviewActions( $actorId, $section['id'] );
		if ( isset( $actions['approve'] ) ) {
			$field = 'ab-rv-n-' . (int) $section['id'];
			$out  .= '<form class="ab-rv-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SECTION ) . '">'
				. '<input type="hidden" name="artifact_id" value="' . esc_attr( (string) $artifactId ) . '">'
				. '<input type="hidden" name="section_id" value="' . esc_attr( (string) $section['id'] ) . '">'
				. wp_nonce_field( self::NONCE . $artifactId, '_wpnonce', false, false )
				. '<label for="' . esc_attr( $field ) . '">' . esc_html__( 'Note (needed to request changes)', 'tomatio' ) . '</label>'
				. '<textarea id="' . esc_attr( $field ) . '" name="notes" rows="2"></textarea>'
				. '<div class="ab-rv-buttons">'
				. '<button type="submit" name="verdict" value="approve" class="ab-btn ab-btn--go">' . esc_html__( 'Approve this section', 'tomatio' ) . '</button>'
				. '<button type="submit" name="verdict" value="request_changes" class="ab-btn">' . esc_html__( 'Request changes', 'tomatio' ) . '</button>'
				. '</div></form>';
		} elseif ( $section['state'] === Cpts::SECTION_STATE_SUBMITTED ) {
			$out .= '<p class="ab-meta">' . esc_html__( 'You cannot approve this section yourself: a curator or the artifact\'s lead approves it.', 'tomatio' ) . '</p>';
		}

		return $out . '</div></details>';
	}

	/**
	 * What just happened, from the handler's redirect.
	 *
	 * @param array<string, mixed> $query
	 */
	private static function flash( array $query ): string {
		$done = isset( $query[ self::DONE ] ) && is_string( $query[ self::DONE ] ) ? $query[ self::DONE ] : '';
		$approvedAll = isset( $query[ WaitingSections::APPROVED ] ) && is_string( $query[ WaitingSections::APPROVED ] ) && ctype_digit( $query[ WaitingSections::APPROVED ] ) ? (int) $query[ WaitingSections::APPROVED ] : 0;
		$messages = [
			'approve'         => __( 'Section approved.', 'tomatio' ),
			'request_changes' => __( 'Changes requested. The section is back with its writer.', 'tomatio' ),
			'accept'          => __( 'Recorded: recommendation accepted.', 'tomatio' ),
			'change'          => __( 'Recorded: the change is with the lead.', 'tomatio' ),
			'ask'             => __( 'Your question is with the lead.', 'tomatio' ),
		];
		$out = '';
		if ( isset( $messages[ $done ] ) ) {
			$out .= '<div class="ab-callout ab-callout--success" role="status"><p>' . esc_html( $messages[ $done ] ) . '</p></div>';
		}
		if ( $approvedAll > 0 ) {
			/* translators: %d: number of sections approved. */
			$out .= '<div class="ab-callout ab-callout--success" role="status"><p>' . esc_html( sprintf( _n( 'Approved %d section.', 'Approved %d sections.', $approvedAll, 'tomatio' ), $approvedAll ) ) . '</p></div>';
		}
		$error = isset( $query[ self::ERROR ] ) && is_string( $query[ self::ERROR ] ) ? sanitize_text_field( $query[ self::ERROR ] ) : '';
		if ( $error !== '' ) {
			$out .= '<div class="ab-callout ab-callout--danger" role="alert"><p>' . esc_html( self::errorText( $error, isset( $query['section'] ) ) ) . '</p></div>';
		}

		return $out;
	}

	/** An agent's name without its agent-id host part (dennis-ai-6aae48f5.agnt.example.com → dennis-ai-6aae48f5). */
	private static function shortName( int $userId ): string {
		$name = TioDay::name( $userId );

		return str_contains( $name, '.agnt.' ) ? strstr( $name, '.', true ) : $name;
	}

	/** Known refusals in plain words; anything else names its code. */
	private static function errorText( string $code, bool $section ): string {
		$known = [
			'ab_review_invalid'            => $section ? __( 'Write a note first: requesting changes needs one, so the writer knows what to change.', 'tomatio' ) : __( 'Add a comment first: changing a decision or asking about it needs one.', 'tomatio' ),
			'ab_review_decision_answered'  => __( 'That decision was already answered.', 'tomatio' ),
			'ab_review_decision_not_found' => __( 'That decision is no longer on this page.', 'tomatio' ),
			'ab_scope_hold'                => __( 'Only a curator can approve this section: it was written after reading a scope fewer people can read than this artifact\'s scope.', 'tomatio' ),
			'ab_cannot_review_own_section' => __( 'You cannot review a section you own.', 'tomatio' ),
			'ab_invalid_section_transition' => __( 'That section is no longer waiting for review. Reload the page.', 'tomatio' ),
			'ab_forbidden'                 => __( 'You are not allowed to do that.', 'tomatio' ),
		];

		/* translators: %s: an error code. */
		return $known[ $code ] ?? sprintf( __( 'That did not work (%s). Reload the page and try again.', 'tomatio' ), $code );
	}

	// -----------------------------------------------------------------
	// Actions
	// -----------------------------------------------------------------

	/**
	 * Approve a section or request changes on it, with the artifact
	 * screen's rule (AdminUi::reviewActions) and service. Returns where to
	 * go back to.
	 */
	public static function reviewSection( int $actorId, int $artifactId, int $sectionId, string $verdict, string $notes ): string {
		$notes  = trim( sanitize_textarea_field( $notes ) );
		$result = null;
		if ( ! ReviewBrief::canReview( $actorId, $artifactId ) || ! ReviewBrief::isSectionOf( $sectionId, $artifactId ) || ! isset( AdminUi::reviewActions( $actorId, $sectionId )[ $verdict ] ) ) {
			$result = new WP_Error( 'ab_forbidden', '' );
		} elseif ( $verdict === 'request_changes' && $notes === '' ) {
			$result = new WP_Error( 'ab_review_invalid', '' );
		} else {
			$result = ( new ArtifactService() )->reviewSection( $actorId, $sectionId, $verdict, $notes );
		}

		return self::back( $artifactId, $result instanceof WP_Error ? [ self::ERROR => $result->get_error_code(), 'section' => $sectionId ] : [ self::DONE => $verdict, 'section' => $sectionId ], 'section-' . $sectionId );
	}

	/** Answer a decision; returns where to go back to. */
	public static function answerDecision( int $actorId, int $artifactId, string $decisionId, string $verdict, string $comment ): string {
		$result = ReviewBrief::answer( $artifactId, $decisionId, $verdict, $comment, $actorId );

		return self::back( $artifactId, $result instanceof WP_Error ? [ self::ERROR => $result->get_error_code() ] : [ self::DONE => $verdict ], 'decision-' . $decisionId );
	}

	/**
	 * @param array<string, string|int> $args
	 */
	private static function back( int $artifactId, array $args, string $anchor ): string {
		return ReviewBrief::url( $artifactId ) . '&' . http_build_query( $args ) . '#' . rawurlencode( $anchor );
	}

	/** admin-post: a section verdict. */
	public static function handleSection(): void {
		$artifactId = isset( $_POST['artifact_id'] ) ? absint( $_POST['artifact_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
		check_admin_referer( self::NONCE . $artifactId );

		$sectionId = isset( $_POST['section_id'] ) ? absint( $_POST['section_id'] ) : 0;
		$verdict   = isset( $_POST['verdict'] ) ? sanitize_key( wp_unslash( (string) $_POST['verdict'] ) ) : '';
		$notes     = isset( $_POST['notes'] ) ? (string) wp_unslash( (string) $_POST['notes'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_textarea_field in reviewSection().

		self::guard( $artifactId );
		wp_safe_redirect( self::reviewSection( get_current_user_id(), $artifactId, $sectionId, $verdict, $notes ) );
		exit;
	}

	/** admin-post: a decision answer. */
	public static function handleDecision(): void {
		$artifactId = isset( $_POST['artifact_id'] ) ? absint( $_POST['artifact_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
		check_admin_referer( self::NONCE . $artifactId );

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( (string) $_POST['decision'] ) ) : '';
		$verdict  = isset( $_POST['answer'] ) ? sanitize_key( wp_unslash( (string) $_POST['answer'] ) ) : '';
		$comment  = isset( $_POST['comment'] ) ? (string) wp_unslash( (string) $_POST['comment'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in ReviewBrief::answer().

		self::guard( $artifactId );
		wp_safe_redirect( self::answerDecision( get_current_user_id(), $artifactId, $decision, $verdict, $comment ) );
		exit;
	}

	private static function guard( int $artifactId ): void {
		if ( ! MembersAddress::canSeeAddress( get_current_user_id(), $artifactId ) || ! ReviewBrief::canReview( get_current_user_id(), $artifactId ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'tomatio' ), '', [ 'response' => 403 ] );
		}
	}

	private static function css(): string {
		return '.ab-review{max-width:820px}'
			. '.ab-rv-block{margin-top:8px}'
			. '.ab-rv-empty{color:var(--ab-muted);font-style:italic}'
			. '.ab-rv-tags{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin:0 0 6px}.ab-rv-tags .ab-status{margin:0}'
			. '.ab-rv-in{font-size:13px}'
			. '.ab-rv-decision h3{margin:0 0 10px}'
			. '.ab-rv-choice{margin:0 0 10px;padding:10px 12px;border:1px solid var(--ab-line);border-radius:8px}'
			. '.ab-rv-choice p{margin:0}'
			. '.ab-rv-choice--rec{border-color:var(--ab-done);background:var(--ab-done-soft)}'
			. '.ab-rv-k{display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.07em;color:var(--ab-muted);margin-bottom:2px}'
			. '.ab-rv-choice--rec .ab-rv-k{color:var(--ab-done)}'
			. '.ab-rv-form{margin:12px 0 0}'
			. '.ab-rv-form label{display:block;font-size:13px;color:var(--ab-muted);margin:0 0 4px}'
			. '.ab-rv-form textarea{display:block;width:100%;box-sizing:border-box;min-height:3.2em;padding:8px 10px;border:1px solid var(--ab-line);border-radius:8px;background:var(--ab-bg);color:var(--ab-fg);font:inherit;font-size:15px;resize:vertical}'
			. '.ab-rv-form textarea:focus-visible,.ab-btn:focus-visible,.ab-rv-section>summary:focus-visible{outline:3px solid var(--ab-accent);outline-offset:2px}'
			. '.ab-rv-buttons{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}'
			. '.ab-btn{appearance:none;min-height:40px;padding:8px 16px;border:1px solid var(--ab-line);border-radius:999px;background:var(--ab-surface);color:var(--ab-fg);font:600 14px/1.2 var(--ab-font-body);cursor:pointer}'
			. '.ab-btn:hover{border-color:var(--ab-fg)}'
			. '.ab-btn--go{background:var(--ab-accent);border-color:var(--ab-accent);color:var(--ab-on-accent)}'
			. '.ab-btn--quiet{background:transparent}'
			. '.ab-rv-all{margin:0 0 14px}'
			. '.ab-rv-section{background:var(--ab-surface);border:1px solid var(--ab-line);border-radius:var(--ab-radius);margin:0 0 10px}'
			. '.ab-rv-section>summary{list-style:none;cursor:pointer;padding:12px 16px;display:block}'
			. '.ab-rv-section>summary::-webkit-details-marker{display:none}'
			. '.ab-rv-head{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px}'
			. '.ab-rv-head::after{content:"";margin-left:auto;width:8px;height:8px;border-right:2px solid var(--ab-muted);border-bottom:2px solid var(--ab-muted);transform:rotate(45deg);transition:transform .15s}'
			. '.ab-rv-section[open] .ab-rv-head::after{transform:rotate(-135deg)}'
			. '.ab-rv-head .ab-status{margin:0}'
			. '.ab-rv-n{display:inline-grid;place-items:center;min-width:24px;height:24px;border-radius:999px;background:var(--ab-line);font-size:12px;font-weight:600}'
			. '.ab-rv-title{font-family:var(--ab-font-display);font-weight:600;font-size:16px;flex:1 1 12em}'
			. '.ab-rv-brief{display:block;margin-top:4px;color:var(--ab-muted);font-size:14px}'
			. '.ab-rv-body{padding:4px 16px 16px;border-top:1px solid var(--ab-line)}'
			. '.ab-rv-content{overflow-wrap:break-word}'
			. '.ab-rv-content h2{border-top:0;padding-top:0;margin-top:20px;font-size:18px}'
			. '.ab-rv-visual{border:1px solid var(--ab-line);border-radius:var(--ab-radius);overflow:hidden;background:var(--ab-surface)}'
			. '.ab-rv-visual iframe{display:block;width:100%;height:70vh;min-height:420px;border:0}'
			. '@media (max-width:560px){.ab-btn{flex:1 1 auto}.ab-rv-section>summary{padding:12px}.ab-rv-body{padding:4px 12px 14px}}';
	}
}
