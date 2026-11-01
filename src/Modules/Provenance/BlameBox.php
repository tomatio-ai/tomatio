<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Provenance;

use AgentBrain\Modules\Artifacts\ObjectAccess;

/**
 * PROV-5 (#174): the wp-admin blame surfaces.
 *
 * Knowledge editor (ab_knowledge / ab_procedure edit screens): a "History &
 * Blame" box with a per-revision history list (author, via, client, AI label,
 * time), a Blame toggle rendering the block-level blame of the current
 * content, and a diff between any two versions. The artifact screen reuses
 * the History box's list/compare from #220; this class renders the per-section
 * block blame view it links to (a "Blame" action per section row, opened with
 * the ab_blame query arg).
 *
 * Both surfaces only render what the viewer may already read: the knowledge
 * box sits on the edit screen (edit_post is the gate) and the section blame
 * re-checks the per-section read gate (ObjectAccess::canReadSection), the
 * same gate a section version's content uses.
 */
final class BlameBox {

	public const BOX_ID = 'ab-blame';

	/** Artifact screen: which section to blame (History box link target). */
	public const GET_BLAME = 'ab_blame';

	/** Knowledge screen: render the blame table for the current content. */
	public const GET_KB_BLAME = 'ab_kb_blame';

	/** Knowledge screen: compare two states by ordinal. */
	public const GET_CMP_FROM = 'ab_kb_cmp_from';
	public const GET_CMP_TO   = 'ab_kb_cmp_to';

	/** @var string[] Compare form printed in the footer (the box sits inside the post form). */
	private static array $footerForms = [];

	public static function register(): void {
		add_action( 'add_meta_boxes_ab_knowledge', [ self::class, 'addKnowledgeBox' ] );
		add_action( 'add_meta_boxes_ab_procedure', [ self::class, 'addKnowledgeBox' ] );
		add_action( 'admin_footer', [ self::class, 'printFooterForms' ] );
	}

	public static function addKnowledgeBox( \WP_Post $post ): void {
		add_meta_box( self::BOX_ID, __( 'History & Blame', 'agent-brain' ), [ self::class, 'render' ], $post->post_type, 'normal', 'default' );
	}

	public static function render( \WP_Post $post ): void {
		echo self::knowledgeHtml( $post );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/**
	 * The knowledge/procedure box HTML.
	 */
	public static function knowledgeHtml( \WP_Post $post ): string {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return '<p class="ab-blame-note">' . esc_html__( 'You do not have permission to view the history of this entry.', 'agent-brain' ) . '</p>';
		}

		$states = RevisionBlame::states( (int) $post->ID );

		$out = '<div class="ab-blame">';
		$out .= self::historyTableHtml( $states );
		$out .= self::blameToggleHtml( (int) $post->ID );
		$out .= self::compareControlsHtml( (int) $post->ID, $states );

		$blame = isset( $_GET[ self::GET_KB_BLAME ] ) ? absint( wp_unslash( (string) $_GET[ self::GET_KB_BLAME ] ) ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $blame > 0 ) {
			$out .= self::blameTableHtml( RevisionBlame::blame( (int) $post->ID ) );
		}

		$from = isset( $_GET[ self::GET_CMP_FROM ] ) ? absint( wp_unslash( (string) $_GET[ self::GET_CMP_FROM ] ) ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to   = isset( $_GET[ self::GET_CMP_TO ] ) ? absint( wp_unslash( (string) $_GET[ self::GET_CMP_TO ] ) ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $from > 0 && $to > 0 ) {
			$out .= self::diffHtml( RevisionBlame::compareStates( (int) $post->ID, $from, $to ), $from, $to );
		}

		return $out . '</div>';
	}

	/**
	 * The artifact screen's per-section blame view, linked from the History
	 * box. Gates on the per-section read gate exactly like a section
	 * version's content.
	 */
	public static function sectionBlameHtml( int $viewerId, int $sectionId ): string {
		if ( ! ObjectAccess::canReadSection( $viewerId, $sectionId ) ) {
			return '<p class="ab-blame-note">' . esc_html__( 'You do not have permission to view the blame of this section.', 'agent-brain' ) . '</p>';
		}

		$section = get_post( $sectionId );
		$title   = $section instanceof \WP_Post ? $section->post_title : '';

		$out  = '<div class="ab-blame" style="border-top:1px solid #c3c4c7;margin-top:12px;padding-top:8px">';
		$out .= '<h4>' . sprintf(
			/* translators: %s: section title. */
			esc_html__( 'Blame: section "%s"', 'agent-brain' ),
			esc_html( $title !== '' ? $title : __( '(untitled)', 'agent-brain' ) )
		) . '</h4>';
		$out .= self::blameTableHtml( RevisionBlame::blame( $sectionId ) );

		return $out . '</div>';
	}

	// ------------------------------------------------------------------
	// Renderers
	// ------------------------------------------------------------------

	/**
	 * The per-revision history list: one row per content state, oldest
	 * first, with the author carried from the revision's blame stamp.
	 *
	 * @param array<int, array<string, mixed>> $states
	 */
	private static function historyTableHtml( array $states ): string {
		if ( $states === [] ) {
			return '<p class="ab-blame-note">' . esc_html__( 'No history yet. Revisions appear here after the first edit.', 'agent-brain' ) . '</p>';
		}

		$out = '<table class="widefat striped"><thead><tr>';
		foreach ( [ __( 'Version', 'agent-brain' ), __( 'When', 'agent-brain' ), __( 'Made by', 'agent-brain' ), __( 'Via', 'agent-brain' ), __( 'Client', 'agent-brain' ), __( 'AI', 'agent-brain' ), __( 'Status', 'agent-brain' ) ] as $head ) {
			$out .= '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';

		$last = count( $states ) - 1;
		foreach ( $states as $i => $state ) {
			$actor = is_array( $state['actor'] ?? null ) ? $state['actor'] : [];

			$out .= '<tr>';
			$out .= '<td>#' . esc_html( (string) (int) $state['ordinal'] ) . '</td>';
			$out .= '<td>' . esc_html( (string) $state['at'] ) . '</td>';
			$out .= '<td>' . esc_html( self::userName( (int) ( $actor['actor_id'] ?? 0 ) ) ) . '</td>';
			$out .= '<td>' . esc_html( self::userName( (int) ( $actor['via'] ?? 0 ) ) ) . '</td>';
			$out .= '<td>' . esc_html( (string) ( $actor['client'] ?? '' ) ) . '</td>';
			$out .= '<td>' . ( ! empty( $actor['ai'] ) ? esc_html__( 'yes', 'agent-brain' ) : esc_html__( 'no', 'agent-brain' ) ) . '</td>';
			$out .= '<td>' . ( $i === $last ? esc_html__( 'current', 'agent-brain' ) : '-' ) . '</td>';
			$out .= '</tr>';
		}

		return $out . '</tbody></table>';
	}

	private static function blameToggleHtml( int $postId ): string {
		$url = add_query_arg( [ 'post' => $postId, 'action' => 'edit', self::GET_KB_BLAME => 1 ], admin_url( 'post.php' ) );

		return '<p><a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Blame', 'agent-brain' ) . '</a> '
			. '<span class="description">' . esc_html__( 'Show which author wrote each block of the current content.', 'agent-brain' ) . '</span></p>';
	}

	/**
	 * @param array<int, array<string, mixed>> $states
	 */
	private static function compareControlsHtml( int $postId, array $states ): string {
		if ( count( $states ) < 2 ) {
			return '';
		}

		$formId = 'ab-blame-compare';
		$form   = '<form id="' . esc_attr( $formId ) . '" method="get" action="' . esc_url( admin_url( 'post.php' ) ) . '">';
		$form  .= '<input type="hidden" name="post" value="' . esc_attr( (string) $postId ) . '" />';
		$form  .= '<input type="hidden" name="action" value="edit" />';
		$form  .= '</form>';
		self::$footerForms[] = $form;

		$options = '';
		foreach ( $states as $state ) {
			$options .= '<option value="' . esc_attr( (string) (int) $state['ordinal'] ) . '">#' . esc_html( (string) (int) $state['ordinal'] ) . ' — ' . esc_html( (string) $state['at'] ) . '</option>';
		}

		return '<p><strong>' . esc_html__( 'Compare', 'agent-brain' ) . ':</strong> '
			. '<label>' . esc_html__( 'From', 'agent-brain' ) . ' <select name="' . esc_attr( self::GET_CMP_FROM ) . '" form="' . esc_attr( $formId ) . '">' . $options . '</select></label> '
			. '<label>' . esc_html__( 'To', 'agent-brain' ) . ' <select name="' . esc_attr( self::GET_CMP_TO ) . '" form="' . esc_attr( $formId ) . '">' . $options . '</select></label> '
			. '<button type="submit" form="' . esc_attr( $formId ) . '" class="button button-small">' . esc_html__( 'Compare', 'agent-brain' ) . '</button></p>';
	}

	/**
	 * The block-level blame table: one row per top-level block of the
	 * current content, with the author it carries.
	 *
	 * @param array<string, mixed> $blame
	 */
	private static function blameTableHtml( array $blame ): string {
		$blocks = is_array( $blame['blocks'] ?? null ) ? $blame['blocks'] : [];
		if ( $blocks === [] ) {
			return '<p class="ab-blame-note">' . esc_html__( 'No blocks to blame.', 'agent-brain' ) . '</p>';
		}

		$out = '<table class="widefat striped"><thead><tr>';
		foreach ( [ __( '#', 'agent-brain' ), __( 'Block', 'agent-brain' ), __( 'Text', 'agent-brain' ), __( 'Author', 'agent-brain' ), __( 'Via', 'agent-brain' ), __( 'Client', 'agent-brain' ), __( 'Version', 'agent-brain' ), __( 'When', 'agent-brain' ) ] as $head ) {
			$out .= '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';

		foreach ( $blocks as $block ) {
			$author = is_array( $block['author'] ?? null ) ? $block['author'] : [];

			$out .= '<tr>';
			$out .= '<td>' . esc_html( (string) (int) ( $block['index'] ?? 0 ) ) . '</td>';
			$out .= '<td>' . esc_html( (string) ( $block['block'] ?? '' ) ) . '</td>';
			$out .= '<td>' . esc_html( (string) ( $block['text'] ?? '' ) ) . '</td>';
			$out .= '<td>' . esc_html( self::userName( (int) ( $author['actor_id'] ?? 0 ) ) ) . '</td>';
			$out .= '<td>' . esc_html( self::userName( (int) ( $author['via'] ?? 0 ) ) ) . '</td>';
			$out .= '<td>' . esc_html( (string) ( $author['client'] ?? '' ) ) . '</td>';
			$out .= '<td>' . ( isset( $author['version'] ) && $author['version'] !== null ? 'v' . esc_html( (string) (int) $author['version'] ) : '-' ) . '</td>';
			$out .= '<td>' . esc_html( (string) ( $author['at'] ?? '' ) ) . '</td>';
			$out .= '</tr>';
		}

		return $out . '</tbody></table>';
	}

	/**
	 * @param array<string, mixed>|null $cmp
	 */
	private static function diffHtml( ?array $cmp, int $from, int $to ): string {
		$out = '<div class="ab-blame-diff" style="border-top:1px solid #c3c4c7;margin-top:12px;padding-top:8px">';
		$out .= '<h4>' . sprintf(
			/* translators: 1: from version, 2: to version. */
			esc_html__( 'Comparing version #%1$d to version #%2$d', 'agent-brain' ),
			$from,
			$to
		) . '</h4>';

		if ( $cmp === null ) {
			return $out . '<p class="ab-blame-note">' . esc_html__( 'One of those versions does not exist.', 'agent-brain' ) . '</p></div>';
		}

		if ( ! empty( $cmp['identical'] ) ) {
			$out .= '<p class="ab-blame-note">' . esc_html__( 'No differences.', 'agent-brain' ) . '</p>';
		}

		$out .= '<ul class="ab-blame-diff-lines" style="background:#fff;border:1px solid #dcdcde;padding:8px 12px;font-family:monospace;max-height:400px;overflow:auto">';
		foreach ( (array) ( $cmp['lines'] ?? [] ) as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$type = (string) ( $line['type'] ?? 'same' );
			$text = (string) ( $line['line'] ?? '' );
			if ( $type === 'added' ) {
				$out .= '<li><ins>+ ' . esc_html( $text ) . '</ins></li>';
			} elseif ( $type === 'removed' ) {
				$out .= '<li><del>- ' . esc_html( $text ) . '</del></li>';
			} else {
				$out .= '<li>' . esc_html( $text ) . '</li>';
			}
		}
		$out .= '</ul>';
		if ( ! empty( $cmp['truncated'] ) ) {
			$out .= '<p class="description">' . esc_html__( 'Long entries are truncated to the first lines of each side.', 'agent-brain' ) . '</p>';
		}

		return $out . '</div>';
	}

	public static function printFooterForms(): void {
		if ( self::$footerForms === [] ) {
			return;
		}
		echo implode( '', self::$footerForms );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in compareControlsHtml().
		self::$footerForms = [];
	}

	private static function userName( int $userId ): string {
		$user = $userId > 0 ? get_userdata( $userId ) : false;

		return $user instanceof \WP_User ? $user->display_name : '-';
	}
}
