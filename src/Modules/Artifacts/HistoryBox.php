<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Modules\Provenance\BlameBox;
use AgentBrain\Modules\Sharing\HtmlSite;

/**
 * #220: the "History" box on the artifact screen. One version history per
 * artifact covering both section submissions and HTML uploads (Versions):
 * a list with actor + note per version, a full view of ANY version
 * (including pending ones — a section's rendered snapshot or an HTML
 * version in a sandboxed iframe), a compare of two versions (text diff
 * for sections, file add/change/remove for HTML), and a restore request
 * that creates a new version through the normal approval path.
 *
 * The box lives inside the post form, so every interactive form is printed
 * in the footer and referenced with a form="" attribute — the same
 * pattern AdminUi::reviewControlsHtml() already uses (forms cannot nest).
 */
final class HistoryBox {

	public const BOX_ID          = 'ab-artifact-history';
	public const ACTION_RESTORE  = 'ab_artifact_version_restore';
	public const NONCE_RESTORE   = 'ab_artifact_version_restore_';
	public const GET_VIEW        = 'ab_hist';
	public const GET_COMPARE     = 'ab_hist_cmp';

	/** @var string[] Restore forms printed in the footer (the box sits inside the post form). */
	private static array $footerForms = [];

	public static function register(): void {
		add_action( 'add_meta_boxes_' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'addMetaBox' ] );
		add_action( 'admin_post_' . self::ACTION_RESTORE, [ self::class, 'handleRestore' ] );
		add_action( 'admin_footer', [ self::class, 'printFooterForms' ] );
		add_action( 'admin_notices', [ self::class, 'renderNotices' ] );
	}

	public static function addMetaBox( \WP_Post $post ): void {
		add_meta_box( self::BOX_ID, __( 'History', 'agent-brain' ), [ self::class, 'render' ], Cpts::ARTIFACT_POST_TYPE, 'normal', 'default' );
	}

	public static function render( \WP_Post $post ): void {
		echo self::html( (int) $post->ID, get_current_user_id() );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/**
	 * The box HTML. When the viewer may not read the artifact's history, the
	 * permission note is rendered instead of the list.
	 */
	public static function html( int $artifactId, int $viewerId ): string {
		$versions = Versions::list( $viewerId, $artifactId );
		if ( $versions instanceof \WP_Error ) {
			return '<p class="ab-history-note">' . esc_html__( 'You do not have permission to view the history of this artifact.', 'agent-brain' ) . '</p>';
		}

		$out = '<div class="ab-artifact-history">';
		if ( $versions === [] ) {
			return $out . '<p class="ab-history-note">' . esc_html__( 'No versions yet. Versions appear here when a section is submitted or an HTML upload is made.', 'agent-brain' ) . '</p></div>';
		}

		$canRestore = self::canRestore( $viewerId, $artifactId );

		$out .= '<table class="widefat striped"><thead><tr>';
		foreach ( [ __( 'Version', 'agent-brain' ), __( 'What', 'agent-brain' ), __( 'Made by', 'agent-brain' ), __( 'When', 'agent-brain' ), __( 'Note', 'agent-brain' ), __( 'Status', 'agent-brain' ), __( 'Actions', 'agent-brain' ) ] as $head ) {
			$out .= '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';

		foreach ( array_reverse( $versions ) as $row ) {
			$seq   = (int) $row['seq'];
			$out  .= '<tr>';
			$out  .= '<td>#' . esc_html( (string) $seq ) . '</td>';
			$out  .= '<td>' . esc_html( self::rowLabel( $row ) ) . '</td>';
			$out  .= '<td>' . esc_html( self::userName( (int) $row['actor'] ) ) . '</td>';
			$out  .= '<td>' . esc_html( (string) $row['at'] ) . '</td>';
			$out  .= '<td>' . nl2br( esc_html( (string) $row['note'] ) ) . '</td>';
			$out  .= '<td>' . esc_html( self::statusLabel( $row ) ) . '</td>';
			$out  .= '<td>' . self::rowActions( $row, $artifactId, $seq, $canRestore ) . '</td>';
			$out  .= '</tr>';
		}
		$out .= '</tbody></table>';

		$out .= self::compareControlsHtml( $artifactId, $versions );

		$viewSeq = isset( $_GET[ self::GET_VIEW ] ) ? absint( wp_unslash( (string) $_GET[ self::GET_VIEW ] ) ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $viewSeq > 0 ) {
			$out .= self::viewHtml( $viewerId, $artifactId, $viewSeq );
		}

		$from = isset( $_GET[ self::GET_COMPARE . '_from' ] ) ? absint( wp_unslash( (string) $_GET[ self::GET_COMPARE . '_from' ] ) ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to   = isset( $_GET[ self::GET_COMPARE . '_to' ] ) ? absint( wp_unslash( (string) $_GET[ self::GET_COMPARE . '_to' ] ) ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $from > 0 && $to > 0 ) {
			$out .= self::compareHtml( $viewerId, $artifactId, $from, $to );
		}

		// PROV-5 (#174): the per-section block blame view, linked from each
		// section row's "Blame" action. Re-checks the per-section read gate.
		$blameSection = isset( $_GET[ BlameBox::GET_BLAME ] ) ? absint( wp_unslash( (string) $_GET[ BlameBox::GET_BLAME ] ) ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $blameSection > 0 ) {
			$out .= BlameBox::sectionBlameHtml( $viewerId, $blameSection );
		}

		return $out . '</div>';
	}

	/**
	 * Whether the viewer holds approval-grade rights on this artifact
	 * (curator, lead, or #252 manager-grant) — the same set that may
	 * restore; Versions::restore() re-checks for real.
	 */
	public static function canRestore( int $viewerId, int $artifactId ): bool {
		return ObjectAccess::userIsCurator( $viewerId )
			|| ObjectAccess::userIsLead( $viewerId, $artifactId )
			|| ObjectAccess::userCanReviewAndAssemble( $viewerId, $artifactId );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function rowLabel( array $row ): string {
		if ( $row['kind'] === 'html' ) {
			return sprintf(
				/* translators: %d: HTML version number. */
				__( 'HTML version %d', 'agent-brain' ),
				(int) $row['html_version']
			);
		}

		$title = (string) ( $row['title'] ?? '' );

		return sprintf(
			/* translators: 1: section title, 2: section version. */
			__( 'Section "%1$s" v%2$d', 'agent-brain' ),
			$title !== '' ? $title : __( '(untitled)', 'agent-brain' ),
			(int) $row['section_version']
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function statusLabel( array $row ): string {
		$labels = [];
		if ( ! empty( $row['current'] ) ) {
			$labels[] = __( 'current', 'agent-brain' );
		}
		if ( ! empty( $row['approved'] ) ) {
			$labels[] = $row['kind'] === 'html' ? __( 'approved', 'agent-brain' ) : __( 'section approved', 'agent-brain' );
		}
		if ( ! empty( $row['served'] ) ) {
			$labels[] = __( 'served', 'agent-brain' );
		}

		return $labels !== [] ? implode( ', ', $labels ) : '-';
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function rowActions( array $row, int $artifactId, int $seq, bool $canRestore ): string {
		$viewUrl = add_query_arg( [ 'post' => $artifactId, 'action' => 'edit', self::GET_VIEW => $seq ], admin_url( 'post.php' ) );
		$out     = '<a class="button button-small" href="' . esc_url( $viewUrl ) . '">' . esc_html__( 'View', 'agent-brain' ) . '</a>';

		// PROV-5 (#174): block-level blame of a section version's content.
		if ( ( $row['kind'] ?? '' ) === 'section' ) {
			$blameUrl = add_query_arg( [ 'post' => $artifactId, 'action' => 'edit', BlameBox::GET_BLAME => (int) ( $row['section_id'] ?? 0 ) ], admin_url( 'post.php' ) );
			$out     .= ' <a class="button button-small" href="' . esc_url( $blameUrl ) . '">' . esc_html__( 'Blame', 'agent-brain' ) . '</a>';
		}

		if ( ! $canRestore ) {
			return $out;
		}

		$formId = 'ab-hist-restore-' . $seq;
		$form   = '<form id="' . esc_attr( $formId ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$form  .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RESTORE ) . '" />';
		$form  .= '<input type="hidden" name="artifact_id" value="' . esc_attr( (string) $artifactId ) . '" />';
		$form  .= '<input type="hidden" name="seq" value="' . esc_attr( (string) $seq ) . '" />';
		$form  .= wp_nonce_field( self::NONCE_RESTORE . $artifactId . '_' . $seq, '_wpnonce', false, false ) . '</form>';
		self::$footerForms[] = $form;

		$confirm = \AgentBrain\Modules\Admin\Confirm::attr(
			\AgentBrain\Modules\Admin\Confirm::message(
				__( 'Restore this version as a NEW version: for a section it goes back to "submitted" and must be approved again; for an HTML page nothing is served until the new version is approved', 'agent-brain' ),
				__( 'Nothing is overwritten — the current version stays in the history', 'agent-brain' )
			)
		);

		return $out . ' <button type="submit" form="' . esc_attr( $formId ) . '" class="button button-small"' . $confirm . '>' . esc_html__( 'Restore as new version', 'agent-brain' ) . '</button>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Confirm::attr() escapes.
	}

	/**
	 * The two-dropdown compare control. This too is a form printed in the
	 * footer and referenced with form="".
	 *
	 * @param array<int, array<string, mixed>> $versions
	 */
	private static function compareControlsHtml( int $artifactId, array $versions ): string {
		$formId = 'ab-hist-compare';
		$form   = '<form id="' . esc_attr( $formId ) . '" method="get" action="' . esc_url( admin_url( 'post.php' ) ) . '">';
		$form  .= '<input type="hidden" name="post" value="' . esc_attr( (string) $artifactId ) . '" />';
		$form  .= '<input type="hidden" name="action" value="edit" />';
		$form  .= '</form>';
		self::$footerForms[] = $form;

		$options = '';
		foreach ( array_reverse( $versions ) as $row ) {
			$options .= '<option value="' . esc_attr( (string) (int) $row['seq'] ) . '">#' . esc_html( (string) (int) $row['seq'] ) . ' — ' . esc_html( self::rowLabel( $row ) ) . '</option>';
		}

		return '<p><strong>' . esc_html__( 'Compare', 'agent-brain' ) . ':</strong> '
			. '<label>' . esc_html__( 'From', 'agent-brain' ) . ' <select name="' . esc_attr( self::GET_COMPARE ) . '_from" form="' . esc_attr( $formId ) . '">' . $options . '</select></label> '
			. '<label>' . esc_html__( 'To', 'agent-brain' ) . ' <select name="' . esc_attr( self::GET_COMPARE ) . '_to" form="' . esc_attr( $formId ) . '">' . $options . '</select></label> '
			. '<button type="submit" form="' . esc_attr( $formId ) . '" class="button button-small">' . esc_html__( 'Compare', 'agent-brain' ) . '</button></p>';
	}

	/** Full view of one version: rendered section snapshot or sandboxed HTML preview. */
	private static function viewHtml( int $viewerId, int $artifactId, int $seq ): string {
		$row = Versions::get( $viewerId, $artifactId, $seq );
		if ( $row instanceof \WP_Error ) {
			return '<p class="ab-history-note">' . esc_html( $row->get_error_message() ) . '</p>';
		}

		$out  = '<div class="ab-history-view" style="border-top:1px solid #c3c4c7;margin-top:12px;padding-top:8px">';
		$out .= '<h4>' . sprintf(
			/* translators: 1: version seq, 2: version label. */
			esc_html__( 'Version #%1$d: %2$s', 'agent-brain' ),
			$seq,
			esc_html( self::rowLabel( $row ) )
		) . '</h4>';

		if ( $row['kind'] === 'section' ) {
			$out .= '<div class="ab-review-content" style="background:#fff;border:1px solid #dcdcde;padding:8px 12px">' . wp_kses_post( do_blocks( (string) ( $row['content'] ?? '' ) ) ) . '</div>';
		} else {
			$files = [];
			$htmlVersion = (int) ( $row['html_version'] ?? 0 );
			$stored = HtmlFiles::version( $artifactId, $htmlVersion );
			if ( $stored !== null ) {
				$files = $stored['files'];
			}
			if ( $files === [] ) {
				$out .= '<p class="ab-history-note">' . esc_html__( 'The files of this version have been pruned and can no longer be previewed.', 'agent-brain' ) . '</p>';
			} else {
				$out .= '<p><strong>' . esc_html__( 'Files', 'agent-brain' ) . ':</strong> ' . esc_html( implode( ', ', array_keys( $files ) ) ) . '</p>';
				// Sandboxed like Sharing\HtmlMetaBox: allow-scripts but no
				// allow-same-origin, so a pending version can be inspected
				// safely before approval.
				$out .= '<iframe sandbox="allow-scripts" referrerpolicy="no-referrer" style="width:100%;height:520px;border:1px solid #ccd0d4;background:#fff" srcdoc="' . esc_attr( HtmlSite::previewDocument( $files ) ) . '"></iframe>';
			}
		}

		return $out . '</div>';
	}

	private static function compareHtml( int $viewerId, int $artifactId, int $from, int $to ): string {
		$result = Versions::compare( $viewerId, $artifactId, $from, $to );
		if ( $result instanceof \WP_Error ) {
			return '<p class="ab-history-note">' . esc_html( $result->get_error_message() ) . '</p>';
		}

		$out  = '<div class="ab-history-compare" style="border-top:1px solid #c3c4c7;margin-top:12px;padding-top:8px">';
		$out .= '<h4>' . sprintf(
			/* translators: 1: from seq, 2: to seq. */
			esc_html__( 'Comparing version #%1$d to version #%2$d', 'agent-brain' ),
			$from,
			$to
		) . '</h4>';

		if ( ! empty( $result['identical'] ) ) {
			$out .= '<p class="ab-history-note">' . esc_html__( 'No differences.', 'agent-brain' ) . '</p>';
		}

		if ( ( $result['kind'] ?? '' ) === 'section' ) {
			$out .= '<ul class="ab-history-diff" style="background:#fff;border:1px solid #dcdcde;padding:8px 12px;font-family:monospace;max-height:400px;overflow:auto">';
			foreach ( (array) ( $result['lines'] ?? [] ) as $line ) {
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
			if ( ! empty( $result['truncated'] ) ) {
				$out .= '<p class="description">' . esc_html__( 'Long sections are truncated to the first lines of each side.', 'agent-brain' ) . '</p>';
			}
		} else {
			foreach (
				[
					'added'   => __( 'Files added', 'agent-brain' ),
					'removed' => __( 'Files removed', 'agent-brain' ),
					'changed' => __( 'Files changed', 'agent-brain' ),
				] as $key => $label
			) {
				$paths = (array) ( $result[ $key ] ?? [] );
				$out  .= '<p><strong>' . esc_html( $label ) . ':</strong> ' . ( $paths !== [] ? esc_html( implode( ', ', array_map( 'strval', $paths ) ) ) : esc_html__( 'none', 'agent-brain' ) ) . '</p>';
			}
		}

		return $out . '</div>';
	}

	public static function printFooterForms(): void {
		if ( self::$footerForms === [] ) {
			return;
		}
		echo implode( '', self::$footerForms );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in rowActions()/compareControlsHtml().
		self::$footerForms = [];
	}

	public static function handleRestore(): void {
		$artifactId = isset( $_POST['artifact_id'] ) ? absint( wp_unslash( (string) $_POST['artifact_id'] ) ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
		$seq        = isset( $_POST['seq'] ) ? absint( wp_unslash( (string) $_POST['seq'] ) ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
		check_admin_referer( self::NONCE_RESTORE . $artifactId . '_' . $seq );

		if ( ! self::canRestore( get_current_user_id(), $artifactId ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'agent-brain' ), '', [ 'response' => 403 ] );
		}

		$result = Versions::restore( get_current_user_id(), $artifactId, $seq );
		if ( $result instanceof \WP_Error ) {
			wp_die( esc_html( $result->get_error_message() ), '', [ 'response' => 409, 'back_link' => true ] );
		}

		$target = get_edit_post_link( $artifactId, 'raw' );
		wp_safe_redirect( $target ? add_query_arg( 'ab_hist_restored', (int) $result['seq'], $target ) : admin_url( 'edit.php?post_type=' . Cpts::ARTIFACT_POST_TYPE ) );
		exit;
	}

	public static function renderNotices(): void {
		$seq = isset( $_GET['ab_hist_restored'] ) ? absint( wp_unslash( (string) $_GET['ab_hist_restored'] ) ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $seq > 0 && AdminUi::onArtifactEditScreen() ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %d: new history version number. */
				__( 'Version #%d created from the restore. It must be approved before it counts.', 'agent-brain' ),
				$seq
			) ) . '</p></div>';
		}
	}

	private static function userName( int $userId ): string {
		$user = $userId > 0 ? get_userdata( $userId ) : false;

		return $user instanceof \WP_User ? $user->display_name : '-';
	}
}
