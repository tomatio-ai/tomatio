<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Forget;

use AgentBrain\Modules\Admin\EmptyState;
use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\Notices;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\Identity\Capabilities;
use WP_Error;

/**
 * Curator-facing "Forget" admin page (FGT-3, PCP v0.3 SPEC 8.9): erase by
 * subject, by page path or id, or everything a principal wrote, with a
 * preview (dry run) that only counts; receipts; and a check whether a
 * subject is the one a receipt erased (keyed digest). Every state-changing
 * action is nonce-protected, `ab_manage` gated, and every value echoed is
 * escaped. Exports moved to the "Export and import" page
 * (Portability\ExportPage).
 *
 * #751: a three-step flow. 1 What: the kind and what to look for, posted
 * back to this page (nonce-checked). 2 Check: a dry run counts what would
 * go, rendered from that post, so the subject is never stored. 3 Erase:
 * type it again and tick the box; this posts to handleRun() as before.
 */
final class AdminPage {

	public const CAPABILITY = Capabilities::CAP_MANAGE;
	public const SLUG       = 'agent-brain-forget';
	public const RUN_ACTION      = 'agent_brain_forget_run';
	public const RECEIPT_ACTION  = 'agent_brain_forget_receipt_download';
	public const VERIFY_ACTION   = 'agent_brain_forget_verify';

	public const PREVIEW_NONCE   = 'agent_brain_forget_preview';

	private const PREVIEW_OPTION = 'ab_forget_preview_';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'addMenu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'admin_post_' . self::RUN_ACTION, [ self::class, 'handleRun' ] );
		add_action( 'admin_post_' . self::RECEIPT_ACTION, [ self::class, 'handleReceiptDownload' ] );
		add_action( 'admin_post_' . self::VERIFY_ACTION, [ self::class, 'handleVerify' ] );
	}

	public static function addMenu(): void {
		add_submenu_page(
			Menu::PARENT,
			__( 'Forget', 'agent-brain' ),
			__( 'Forget', 'agent-brain' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * Confirmation dialog for the destructive form. Attached with
	 * addEventListener (an inline onsubmit handler is shadowed by any form
	 * control named "confirm", which silently skipped the dialog).
	 */
	public static function enqueue( string $hook ): void {
		if ( ! Menu::isPageHook( $hook, self::SLUG ) ) {
			return;
		}

		wp_register_script( 'agent-brain-forget', false, [], '1', true );
		wp_enqueue_script( 'agent-brain-forget' );
		wp_add_inline_script(
			'agent-brain-forget',
			'(function(){var f=document.getElementById("ab-forget-form");if(!f){return;}'
			. 'f.addEventListener("submit",function(e){if(e.submitter&&e.submitter.name==="ab_dry_run"){return;}if(!window.confirm('
			. wp_json_encode( __( 'This permanently deletes every matching row across every store. Continue?', 'agent-brain' ) )
			. ')){e.preventDefault();}});})();'
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run forget.', 'agent-brain' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash rendering, no state change here.
		$noticeReceiptId = isset( $_GET['receipt'] ) ? sanitize_text_field( wp_unslash( $_GET['receipt'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		$preview = self::previewFromPost();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::head() escapes what it emits.
		echo '<div class="wrap ab-admin ab-admin-forget">' . Ui::head(
			__( 'Forget', 'agent-brain' ),
			esc_html__( 'Erase a subject, pages or everything about a member or agent, for good', 'agent-brain' ),
			'',
			'<p>' . esc_html__( 'Removes every memory, handoff, document and pending agent proposal that mentions the subject, across all scopes and stores, with what was derived from it, and deletes every held export archive. Archives already downloaded still contain it. A receipt of what was removed is kept; it holds no subject, only a keyed fingerprint you can check below.', 'agent-brain' ) . '</p>'
				. '<p>' . esc_html__( 'You can also erase named pages (by path or id, as in an export), or everything a former member or agent wrote or had written for them. The second step counts what would go and deletes nothing.', 'agent-brain' ) . '</p>'
		);

		if ( $error !== '' ) {
			echo Notices::html( 'error', $error ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notices::html() escapes.
		}

		if ( $noticeReceiptId !== '' ) {
			$receipt = ReceiptRepository::get( $noticeReceiptId );
			if ( $receipt !== null ) {
				Notices::success( sprintf(
					/* translators: 1: receipt id, 2: status */
					__( 'Forget receipt %1$s recorded, status: %2$s.', 'agent-brain' ),
					$receipt['id'],
					$receipt['status']
				) );
			}
		}

		self::renderPreview();

		$steps = [ __( 'What', 'agent-brain' ), __( 'Check', 'agent-brain' ), __( 'Erase', 'agent-brain' ) ];
		$on    = $preview === null ? 0 : 1;
		echo '<ol class="ab-ui-stepper" aria-label="' . esc_attr__( 'Steps', 'agent-brain' ) . '">';
		foreach ( $steps as $i => $label ) {
			echo '<li' . ( $i === $on ? ' aria-current="step"' : '' ) . ( $i < $on ? ' class="ab-ui-step-done"' : '' ) . '>' . esc_html( $label ) . '</li>';
		}
		echo '</ol>';

		if ( $preview === null ) {
			self::renderWhat();
		} else {
			self::renderCheckAndErase( $preview );
		}

		echo '<section class="ab-ui-card"><h2 class="ab-ui-kicker">' . esc_html__( 'Check a subject', 'agent-brain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Whether a subject was erased here, by its keyed fingerprint. Subjects forgotten before receipts were keyed can no longer be checked.', 'agent-brain' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ab-ui-inline-form">';
		wp_nonce_field( self::VERIFY_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::VERIFY_ACTION ) . '" />';
		echo '<label for="ab-forget-verify" class="screen-reader-text">' . esc_html__( 'Subject to check', 'agent-brain' ) . '</label>';
		echo '<input type="text" id="ab-forget-verify" name="subject" class="regular-text" autocomplete="off" required /> ';
		submit_button( __( 'Check', 'agent-brain' ), 'secondary', 'ab_verify_submit', false );
		echo '</form></section>';

		echo '<section class="ab-ui-card"><h2 class="ab-ui-kicker">' . esc_html__( 'Receipts', 'agent-brain' ) . '</h2>';
		$receipts = ReceiptRepository::recent();

		if ( $receipts === [] ) {
			EmptyState::render(
				__( 'No forget receipts yet', 'agent-brain' ),
				__( 'A receipt is written every time a subject is forgotten, so you can prove what was removed. Nothing has been forgotten on this site.', 'agent-brain' ),
				null
			);
			echo '</section></div>';

			return;
		}

		echo '<div class="ab-table-wrap"><table class="widefat striped"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Id', 'agent-brain' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Kind', 'agent-brain' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Created', 'agent-brain' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Curator', 'agent-brain' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Status', 'agent-brain' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Download', 'agent-brain' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $receipts as $receipt ) {
			$downloadUrl = wp_nonce_url(
				add_query_arg(
					[ 'action' => self::RECEIPT_ACTION, 'receipt' => $receipt['id'] ],
					admin_url( 'admin-post.php' )
				),
				self::RECEIPT_ACTION
			);
			$curator     = get_userdata( (int) $receipt['curator_id'] );

			echo '<tr>'
				. '<td>' . esc_html( (string) $receipt['id'] ) . '</td>'
				. '<td>' . esc_html( (string) ( $receipt['kind'] ?? 'subject' ) ) . '</td>'
				. '<td>' . esc_html( (string) $receipt['created'] ) . '</td>'
				. '<td>' . esc_html( $curator ? $curator->display_name : (string) $receipt['curator_id'] ) . '</td>'
				. '<td>' . esc_html( (string) $receipt['status'] ) . '</td>'
				. '<td><a href="' . esc_url( $downloadUrl ) . '">' . esc_html__( 'JSON', 'agent-brain' ) . '</a></td>'
				. '</tr>';
		}

		echo '</tbody></table></div></section></div>';
	}

	/**
	 * Step 1: what should the brain forget? The kind is a choice of three
	 * tiles; the matching field shows (CSS :has(), so no script is needed).
	 * Posts back to this page with its own nonce for step 2.
	 */
	private static function renderWhat(): void {
		echo '<section class="ab-ui-card ab-forget-what"><h2 class="ab-ui-kicker">' . esc_html__( '1 · What should the brain forget?', 'agent-brain' ) . '</h2>';
		echo '<form id="ab-forget-what" method="post" action="' . esc_url( add_query_arg( [ 'page' => self::SLUG ], admin_url( 'admin.php' ) ) ) . '">';
		wp_nonce_field( self::PREVIEW_NONCE, '_ab_forget_preview', false );
		echo '<fieldset class="ab-ui-pick"><legend class="screen-reader-text">' . esc_html__( 'What to erase', 'agent-brain' ) . '</legend>';
		foreach ( [
			'subject'   => [ __( 'A subject', 'agent-brain' ), __( 'A name or phrase, wherever it appears', 'agent-brain' ) ],
			'pages'     => [ __( 'Named pages', 'agent-brain' ), __( 'By path or page id, as in an export', 'agent-brain' ) ],
			'principal' => [ __( 'Everything about a member or agent', 'agent-brain' ), __( 'What they wrote and what was written for them', 'agent-brain' ) ],
		] as $kind => [ $label, $hint ] ) {
			echo '<label class="ab-ui-pick-tile"><input type="radio" name="ab_kind" value="' . esc_attr( $kind ) . '"' . checked( $kind, 'subject', false ) . ' /> '
				. '<strong>' . esc_html( $label ) . '</strong><small>' . esc_html( $hint ) . '</small></label>';
		}
		echo '</fieldset>';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped; principalSelect() escapes its own output.
		echo '<p class="ab-forget-field ab-forget-field--subject"><label for="ab-forget-subject">' . esc_html__( 'Subject', 'agent-brain' ) . '</label>'
			. '<input type="text" id="ab-forget-subject" name="subject" class="regular-text" autocomplete="off" placeholder="' . esc_attr__( 'For example: Acme Corp', 'agent-brain' ) . '" /></p>';
		echo '<p class="ab-forget-field ab-forget-field--pages"><label for="ab-forget-pages">' . esc_html__( 'Pages', 'agent-brain' ) . '</label>'
			. '<textarea id="ab-forget-pages" name="ab_pages" rows="3" class="large-text code" aria-describedby="ab-forget-pages-help"></textarea>'
			. '<span class="description" id="ab-forget-pages-help">' . esc_html__( 'One path or page id per line, for example memory/episode/42 or ab-knowledge-7.', 'agent-brain' ) . '</span></p>';
		echo '<p class="ab-forget-field ab-forget-field--principal"><label for="ab-forget-principal">' . esc_html__( 'Member or agent', 'agent-brain' ) . '</label>'
			. self::principalSelect() . '</p>';
		// phpcs:enable
		echo '<p><label><input type="checkbox" name="ab_include_derived" value="1" checked="checked" /> '
			. esc_html__( 'Also erase what was derived from it (summaries and proposals built from these memories).', 'agent-brain' ) . '</label></p>';
		echo '<div class="ab-ui-actions-row"><button type="submit" name="ab_preview" value="1" class="button button-primary">' . esc_html__( 'See what would go', 'agent-brain' ) . '</button></div>';
		echo '</form></section>';
	}

	/**
	 * Steps 2 and 3: what would go, then the typed confirmation. The request
	 * travels in hidden fields to handleRun(), which checks it all again.
	 *
	 * @param array{request: array<string, mixed>, counts: array<string, int>|WP_Error, label: string} $preview
	 */
	private static function renderCheckAndErase( array $preview ): void {
		$request = $preview['request'];
		$restart = add_query_arg( [ 'page' => self::SLUG ], admin_url( 'admin.php' ) );

		echo '<section class="ab-ui-card"><h2 class="ab-ui-kicker">' . esc_html__( '2 · What would go', 'agent-brain' ) . '</h2>';
		if ( $preview['counts'] instanceof WP_Error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::callout() escapes what it emits.
			echo Ui::callout( 'bad', __( 'This cannot be erased as asked.', 'agent-brain' ), esc_html( $preview['counts']->get_error_message() ), '<a class="button" href="' . esc_url( $restart ) . '">' . esc_html__( 'Start again', 'agent-brain' ) . '</a>' );
			echo '</section>';

			return;
		}

		$rows  = [];
		$total = 0;
		foreach ( $preview['counts'] as $store => $count ) {
			$total += (int) $count;
			if ( (int) $count > 0 ) {
				$rows[ self::storeLabel( (string) $store ) ] = esc_html( number_format_i18n( (int) $count ) );
			}
		}
		$rows[ __( 'Export archives', 'agent-brain' ) ] = esc_html__( 'every one held on this site', 'agent-brain' );

		echo '<p>' . esc_html( $preview['label'] ) . '</p>';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::facts( $rows );
		if ( $total === 0 ) {
			echo Ui::callout( 'info', __( 'Nothing matches.', 'agent-brain' ), esc_html__( 'Only the held export archives would go. Check the spelling, or start again with another subject.', 'agent-brain' ) );
		}
		// phpcs:enable
		echo '<p class="description">' . esc_html__( 'Nothing has been deleted yet.', 'agent-brain' ) . ' <a href="' . esc_url( $restart ) . '">' . esc_html__( 'Start again', 'agent-brain' ) . '</a></p>';
		echo '</section>';

		echo '<section class="ab-ui-card ab-forget-erase"><h2 class="ab-ui-kicker">' . esc_html__( '3 · Erase for good', 'agent-brain' ) . '</h2>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::callout() escapes what it emits.
		echo Ui::callout( 'warn', __( 'This cannot be undone.', 'agent-brain' ), esc_html__( 'There is no Undo and no copy here afterwards. Archives already downloaded still contain it.', 'agent-brain' ) );
		echo '<form id="ab-forget-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::RUN_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::RUN_ACTION ) . '" />';
		echo '<input type="hidden" name="ab_kind" value="' . esc_attr( (string) $request['kind'] ) . '" />';
		if ( ! empty( $request['include_derived'] ) ) {
			echo '<input type="hidden" name="ab_include_derived" value="1" />';
		}

		if ( $request['kind'] === 'pages' ) {
			echo '<input type="hidden" name="ab_pages" value="' . esc_attr( implode( "\n", (array) $request['paths'] ) ) . '" />';
		} elseif ( $request['kind'] === 'principal' ) {
			echo '<input type="hidden" name="ab_principal" value="' . esc_attr( (string) $request['principal'] ) . '" />';
			echo '<p class="ab-forget-field"><label for="ab-forget-principal-again">' . esc_html__( 'Type their id again', 'agent-brain' ) . '</label>'
				. '<input type="text" id="ab-forget-principal-again" name="ab_principal_again" class="regular-text code" autocomplete="off" required="required" /></p>';
		} else {
			echo '<input type="hidden" name="subject" value="' . esc_attr( (string) $request['subject'] ) . '" />';
			echo '<p class="ab-forget-field"><label for="ab-forget-subject-again">' . esc_html__( 'Type the subject again', 'agent-brain' ) . '</label>'
				. '<input type="text" id="ab-forget-subject-again" name="ab_subject_again" class="regular-text" autocomplete="off" required="required" /></p>';
		}

		echo '<p><label><input type="checkbox" name="ab_understand" value="1" required="required" /> '
			. esc_html__( 'I understand this permanently deletes matching data and cannot be undone.', 'agent-brain' ) . '</label></p>';
		echo '<div class="ab-ui-actions-row"><button type="submit" name="ab_erase" value="1" class="button button-primary ab-ui-danger">' . esc_html__( 'Erase for good', 'agent-brain' ) . '</button>'
			. '<a class="button" href="' . esc_url( $restart ) . '">' . esc_html__( 'Cancel', 'agent-brain' ) . '</a></div>';
		echo '</form></section>';
	}

	/**
	 * Step 2's input: the step 1 post, when its nonce checks out, run as a
	 * dry run. Nothing is stored; null when there is no such post.
	 *
	 * @return array{request: array<string, mixed>, counts: array<string, int>|WP_Error, label: string}|null
	 */
	private static function previewFromPost(): ?array {
		if ( ! isset( $_POST['_ab_forget_preview'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_ab_forget_preview'] ) ), self::PREVIEW_NONCE ) ) {
			return null;
		}

		$kind    = isset( $_POST['ab_kind'] ) ? sanitize_key( wp_unslash( $_POST['ab_kind'] ) ) : 'subject';
		$request = [
			'kind'            => in_array( $kind, [ 'pages', 'principal' ], true ) ? $kind : 'subject',
			'include_derived' => ! empty( $_POST['ab_include_derived'] ),
			'dry_run'         => true,
		];

		if ( $request['kind'] === 'pages' ) {
			$lines            = isset( $_POST['ab_pages'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ab_pages'] ) ) : '';
			$request['paths'] = array_values( array_filter( array_map( 'trim', explode( "\n", $lines ) ), static fn ( string $line ): bool => $line !== '' ) );
			/* translators: %d: number of pages */
			$label = sprintf( _n( 'Erasing %d named page would remove:', 'Erasing %d named pages would remove:', count( $request['paths'] ), 'agent-brain' ), count( $request['paths'] ) );
		} elseif ( $request['kind'] === 'principal' ) {
			$request['principal'] = isset( $_POST['ab_principal'] ) ? sanitize_text_field( wp_unslash( $_POST['ab_principal'] ) ) : '';
			/* translators: %s: member or agent id */
			$label = sprintf( __( 'Erasing everything about %s would remove:', 'agent-brain' ), $request['principal'] );
		} else {
			$request['subject'] = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
			/* translators: %s: the subject */
			$label = sprintf( __( 'Erasing "%s" would remove:', 'agent-brain' ), $request['subject'] );
		}

		$result = ( new Forgetter() )->erase( $request, get_current_user_id() );
		$counts = $result instanceof WP_Error ? $result : array_map( 'intval', (array) ( $result['would_erase'] ?? [] ) );

		return [ 'request' => $request, 'counts' => $counts, 'label' => $label ];
	}

	public static function handleRun(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run forget.', 'agent-brain' ) );
		}

		check_admin_referer( self::RUN_ACTION );

		$kind    = isset( $_POST['ab_kind'] ) ? sanitize_key( wp_unslash( $_POST['ab_kind'] ) ) : 'subject';
		$dryRun  = ! empty( $_POST['ab_dry_run'] );
		$request = [
			'kind'            => $kind,
			'include_derived' => ! empty( $_POST['ab_include_derived'] ),
			'dry_run'         => $dryRun,
		];

		if ( $kind === 'pages' ) {
			$lines            = isset( $_POST['ab_pages'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ab_pages'] ) ) : '';
			$request['paths'] = array_values( array_filter( array_map( 'trim', explode( "\n", $lines ) ), static fn ( string $line ): bool => $line !== '' ) );
			$confirmed        = $request['paths'] !== [];
		} elseif ( $kind === 'principal' ) {
			$principal            = isset( $_POST['ab_principal'] ) ? sanitize_text_field( wp_unslash( $_POST['ab_principal'] ) ) : '';
			$again                = isset( $_POST['ab_principal_again'] ) ? sanitize_text_field( wp_unslash( $_POST['ab_principal_again'] ) ) : '';
			$request['principal'] = $principal;
			$confirmed            = $principal !== '' && ( $dryRun || $again === $principal );
		} else {
			$subject            = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
			$again              = isset( $_POST['ab_subject_again'] ) ? sanitize_text_field( wp_unslash( $_POST['ab_subject_again'] ) ) : '';
			$request['kind']    = 'subject';
			$request['subject'] = $subject;
			$confirmed          = $subject !== '' && ( $dryRun || $again === $subject );
		}

		if ( ! $confirmed || ( ! $dryRun && empty( $_POST['ab_understand'] ) ) ) {
			self::redirectWithError( __( 'You must tick the confirmation box and type the subject (or the id) a second time before it will run.', 'agent-brain' ) );
		}

		$forgetter = new Forgetter();
		$result    = $forgetter->erase( $request, get_current_user_id() );

		if ( $result instanceof WP_Error ) {
			self::redirectWithError( $result->get_error_message() );
		}

		if ( $dryRun ) {
			update_user_meta( get_current_user_id(), self::PREVIEW_OPTION . 'counts', $result['would_erase'] );
			wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'preview' => '1' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'receipt' => $result['id'] ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Check a subject against the keyed digests of the receipts. The
	 * subject is never stored or logged; the audit entry says only whether
	 * it matched.
	 */
	public static function handleVerify(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run forget.', 'agent-brain' ) );
		}

		check_admin_referer( self::VERIFY_ACTION );

		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		if ( trim( $subject ) === '' ) {
			self::redirectWithError( __( 'Type the subject to check.', 'agent-brain' ) );
		}

		$ids = ReceiptRepository::matchingSubject( $subject );

		do_action( 'agent_brain/audit', [
			'actor_id'    => get_current_user_id(),
			'event'       => 'erase.verify',
			'status'      => 'ok',
			'object_type' => 'ab_forget_receipt',
			'object_id'   => $ids[0] ?? null,
			'meta'        => [ 'match' => $ids !== [] ],
		] );

		if ( $ids === [] ) {
			Notices::flash( 'info', __( 'No receipt here matches that subject. It was not erased here, or it was erased before receipts were keyed.', 'agent-brain' ) );
		} else {
			Notices::flash( 'success', sprintf(
				/* translators: %s: receipt ids */
				__( 'That subject was erased here: receipt %s.', 'agent-brain' ),
				implode( ', ', $ids )
			) );
		}

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handleReceiptDownload(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view receipts.', 'agent-brain' ) );
		}

		check_admin_referer( self::RECEIPT_ACTION );

		$id      = isset( $_GET['receipt'] ) ? sanitize_text_field( wp_unslash( $_GET['receipt'] ) ) : '';
		$receipt = $id !== '' ? ReceiptRepository::get( $id ) : null;

		if ( $receipt === null ) {
			wp_die( esc_html__( 'Receipt not found.', 'agent-brain' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="ab-forget-receipt-' . preg_replace( '/[^a-zA-Z0-9-]/', '', $id ) . '.json"' );
		echo wp_json_encode( ErasureRest::view( $receipt ) );
		exit;
	}

	/**
	 * The counts of the last preview, shown once.
	 */
	private static function renderPreview(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash rendering, no state change here.
		if ( empty( $_GET['preview'] ) ) {
			return;
		}

		$userId = get_current_user_id();
		$counts = get_user_meta( $userId, self::PREVIEW_OPTION . 'counts', true );
		delete_user_meta( $userId, self::PREVIEW_OPTION . 'counts' );
		if ( ! is_array( $counts ) ) {
			return;
		}

		$parts = [];
		foreach ( $counts as $store => $count ) {
			$parts[] = sprintf( '%d %s', (int) $count, strtolower( self::storeLabel( (string) $store ) ) );
		}

		$message = sprintf(
			/* translators: %s: counts per store */
			__( 'Preview, nothing deleted. Running it would erase: %s, and every held export archive.', 'agent-brain' ),
			implode( ', ', $parts )
		);

		echo Notices::html( 'info', $message, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notices::html() escapes.
	}

	/**
	 * A store's name as people say it.
	 */
	private static function storeLabel( string $store ): string {
		$labels = [
			'ab_memory'                     => __( 'Memories', 'agent-brain' ),
			'posts'                         => __( 'Knowledge, procedures and handoffs (with revisions)', 'agent-brain' ),
			'agent_proposals'               => __( 'Pending agent proposals', 'agent-brain' ),
			'artifacts_ab_artifact'         => __( 'Documents', 'agent-brain' ),
			'artifacts_ab_artifact_section' => __( 'Document sections', 'agent-brain' ),
			'artifacts_assembled_post'      => __( 'Assembled documents', 'agent-brain' ),
		];

		return $labels[ $store ] ?? $store;
	}

	/**
	 * Principals that can be erased: everyone but the controller.
	 */
	private static function principalSelect(): string {
		$controller = \AgentBrain\Modules\Portability\Controller::ensure();
		$html       = '<select id="ab-forget-principal" name="ab_principal"><option value="">' . esc_html__( 'Choose', 'agent-brain' ) . '</option>';

		foreach ( \AgentBrain\Modules\Portability\Principals::all() as $row ) {
			if ( $row['principal_id'] === $controller || $row['user_id'] <= 0 ) {
				continue;
			}

			$label = $row['principal_id'] . ( $row['display_name'] !== '' ? ' (' . $row['display_name'] . ')' : '' ) . ( $row['status'] !== 'active' ? ', ' . $row['status'] : '' );
			$html .= '<option value="' . esc_attr( $row['principal_id'] ) . '">' . esc_html( $label ) . '</option>';
		}

		return $html . '</select>';
	}

	private static function redirectWithError( string $message ): void {
		Notices::flash( 'error', $message );
		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
