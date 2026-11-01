<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Modules\Admin\DateFormat;
use AgentBrain\Modules\Admin\DocumentsPage;
use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\Notices;
use AgentBrain\Modules\Admin\ScopeVisibility;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Review\InboxItems;

/**
 * wp-admin surface for artifacts (#89): a "Create artifact" action on the
 * Artifacts list and the outline/section board HTML (rendered into the
 * dedicated edit screen by EditScreen).
 * Creation always goes through ArtifactService::create (never wp_insert_post)
 * so the curator-only and scope-WRITE rules apply unchanged.
 */
final class AdminUi {

	public const ACTION_CREATE = 'ab_artifact_create';
	public const ACTION_STAGE  = 'ab_artifact_stage';
	public const NONCE_CREATE  = 'ab_artifact_create';
	public const NONCE_STAGE   = 'ab_artifact_stage_';
	public const ACTION_REVIEW = 'ab_section_review';
	public const NONCE_REVIEW  = 'ab_section_review_';

	/** @var string[] Review forms printed in the footer: the board sits inside the post form, and forms cannot nest. */
	private static array $reviewForms = [];
	public const ACTION_SECTION_REOPEN = 'ab_section_reopen';
	public const NONCE_SECTION_REOPEN  = 'ab_section_reopen_';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_CREATE, [ self::class, 'handleCreate' ] );
		add_action( 'admin_post_' . self::ACTION_STAGE, [ self::class, 'handleStage' ] );
		add_action( 'admin_post_' . self::ACTION_REVIEW, [ self::class, 'handleReview' ] );
		add_action( 'admin_post_' . self::ACTION_SECTION_REOPEN, [ self::class, 'handleSectionReopen' ] );
		add_action( 'admin_notices', [ self::class, 'renderListNotices' ] );
		add_action( 'admin_notices', [ self::class, 'renderReviewNotice' ] );
		add_action( 'admin_footer', [ self::class, 'printReviewForms' ] );
		add_filter( 'views_edit-' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'renderCreateAction' ], 20 );
		add_filter( 'views_edit-' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'addArchivedView' ], 10 );
		add_action( 'pre_get_posts', [ self::class, 'hideArchived' ] );
		add_action( 'load-post-new.php', [ self::class, 'redirectAddNew' ] );
		add_filter( 'parent_file', [ self::class, 'parentFile' ] );
		add_filter( 'submenu_file', [ self::class, 'submenuFile' ] );
	}

	/** Add New would be a 403 dead end (create_posts is do_not_allow): send people to the list form. */
	public static function redirectAddNew(): void {
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) ) : '';  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $type !== Cpts::ARTIFACT_POST_TYPE ) {
			return;
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Cpts::ARTIFACT_POST_TYPE ) );
		exit;
	}

	// ------------------------------------------------------------------
	// Create
	// ------------------------------------------------------------------

	public static function renderListNotices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'edit-' . Cpts::ARTIFACT_POST_TYPE ) {
			return;
		}

		if ( isset( $_GET['ab_error'] ) ) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['ab_error'] ) ) ) . '</p></div>';  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Printed from `views_edit-ab_artifact`, which runs under the page H1:
	 * a page-title-action button (moved next to the H1 by a tiny script) that
	 * reveals the create form below it. No JS needed to reveal it (:target).
	 *
	 * @param array<string, string> $views
	 * @return array<string, string>
	 */
	public static function renderCreateAction( array $views ): array {
		echo self::createFormHtml();  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.

		return $views;
	}

	/** True when the list is showing the Archived view. */
	private static function archivedViewRequested(): bool {
		return isset( $_GET['ab_archived'] ) && $_GET['ab_archived'] === '1';  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Archived artifacts are out of the default list (they are not deleted);
	 * `?ab_archived=1` shows only them.
	 */
	public static function hideArchived( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || $query->get( 'post_type' ) !== Cpts::ARTIFACT_POST_TYPE ) {
			return;
		}

		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = [ 'key' => ArtifactService::META_ARCHIVED_AT, 'compare' => self::archivedViewRequested() ? 'EXISTS' : 'NOT EXISTS' ];
		$query->set( 'meta_query', $meta );
	}

	/**
	 * @param array<string, string> $views
	 * @return array<string, string>
	 */
	public static function addArchivedView( array $views ): array {
		$url = add_query_arg( [ 'post_type' => Cpts::ARTIFACT_POST_TYPE, 'ab_archived' => '1' ], admin_url( 'edit.php' ) );

		$views['ab_archived'] = '<a href="' . esc_url( $url ) . '"' . ( self::archivedViewRequested() ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'Archived', 'agent-brain' ) . '</a>';

		return $views;
	}

	public static function createFormHtml(): string {
		if ( ! current_user_can( Capabilities::CAP_MANAGE_ARTIFACTS ) ) {
			return '<p class="ab-artifact-note">' . esc_html__( 'Artifacts are created by curators. Ask one, or have a curator set you as lead.', 'agent-brain' ) . '</p>';
		}

		$open = isset( $_GET['ab_error'] ) ? 'display:block;' : '';  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$out  = '<style>#ab-artifact-create{display:none;background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:12px 0}#ab-artifact-create:target{display:block}</style>';
		$out .= '<a class="page-title-action ab-create-trigger" href="#ab-artifact-create">' . esc_html__( 'Create artifact', 'agent-brain' ) . '</a>';
		$out .= '<script>document.addEventListener("DOMContentLoaded",function(){var t=document.querySelector(".ab-create-trigger"),h=document.querySelector(".wrap .wp-heading-inline");if(t&&h){h.insertAdjacentElement("afterend",t);}});</script>';
		$out .= '<div id="ab-artifact-create" class="ab-artifact-create" style="' . esc_attr( $open ) . '">';
		$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$out .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CREATE ) . '" />';
		$out .= wp_nonce_field( self::NONCE_CREATE, '_wpnonce', true, false );
		$out .= '<p><label>' . esc_html__( 'Title', 'agent-brain' ) . '<br /><input type="text" name="title" class="regular-text" required /></label></p>';
		$out .= '<p><label>' . esc_html__( 'Lead', 'agent-brain' ) . '<br /><select name="lead_agent_id" required>';
		foreach ( self::leadChoices() as $id => $label ) {
			$out .= '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $label ) . '</option>';
		}
		$out .= '</select></label></p>';
		$out .= '<p><label>' . esc_html__( 'Scope', 'agent-brain' ) . '<br /><select name="scope_id" required>';
		foreach ( self::scopeChoices() as $id => $label ) {
			$out .= '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $label ) . '</option>';
		}
		$out .= '</select></label></p>';
		$out .= '<p class="description">' . esc_html__( 'The lead must hold write access to the scope.', 'agent-brain' ) . '</p>';
		$out .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create artifact', 'agent-brain' ) . '</button></p>';
		$out .= '</form></div>';

		return $out;
	}

	/** @return array<int, string> user id => label (agents first, curators labelled as such). */
	public static function leadChoices(): array {
		$out = [];
		foreach ( [ Capabilities::ROLE_AGENT => __( 'agent', 'agent-brain' ), Capabilities::ROLE_CURATOR => __( 'curator', 'agent-brain' ) ] as $role => $kind ) {
			foreach ( get_users( [ 'role' => $role, 'orderby' => 'display_name', 'order' => 'ASC' ] ) as $user ) {
				$out[ (int) $user->ID ] = sprintf( '%s (%s)', $user->display_name, $kind );
			}
		}

		return $out;
	}

	/**
	 * The scopes the current user can see (#552), so a new artifact never
	 * starts outside its creator's own view.
	 *
	 * @return array<int, string>
	 */
	public static function scopeChoices(): array {
		$terms   = get_terms( [ 'taxonomy' => Scopes::TAXONOMY, 'hide_empty' => false ] );
		$visible = ScopeVisibility::visibleScopeIds( get_current_user_id() );
		$out     = [];
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( $visible === null || in_array( (int) $term->term_id, $visible, true ) ) {
					$out[ (int) $term->term_id ] = (string) $term->name;
				}
			}
		}

		return $out;
	}

	/**
	 * Core of the create action, separated from redirects for testability.
	 *
	 * @param array<string, mixed> $post
	 * @return int|\WP_Error
	 */
	public static function create( int $actorId, array $post ) {
		$title = sanitize_text_field( wp_unslash( (string) ( $post['title'] ?? '' ) ) );
		if ( $title === '' ) {
			return new \WP_Error( 'ab_title_required', __( 'A title is required.', 'agent-brain' ) );
		}

		return ( new ArtifactService() )->create( $actorId, $title, (int) ( $post['lead_agent_id'] ?? 0 ), (int) ( $post['scope_id'] ?? 0 ) );
	}

	public static function handleCreate(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE_ARTIFACTS ) ) {
			wp_die( esc_html__( 'You do not have permission to create artifacts.', 'agent-brain' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_CREATE );

		$result = self::create( get_current_user_id(), $_POST );  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$list   = admin_url( 'admin.php?page=' . DocumentsPage::SLUG );

		if ( $result instanceof \WP_Error ) {
			wp_safe_redirect( add_query_arg( 'ab_error', rawurlencode( $result->get_error_message() ), $list ) );
			exit;
		}

		$edit = get_edit_post_link( (int) $result, 'raw' );
		wp_safe_redirect( $edit ? $edit : $list );
		exit;
	}

	// ------------------------------------------------------------------
	// Outline board
	// ------------------------------------------------------------------

	public static function onArtifactEditScreen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen instanceof \WP_Screen ) {
			return $screen->base === 'post' && $screen->post_type === Cpts::ARTIFACT_POST_TYPE;
		}

		// Screen not set yet (early parent_file callers): fall back to the request.
		global $pagenow, $typenow;
		$post = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return $pagenow === 'post.php' && ( $typenow === Cpts::ARTIFACT_POST_TYPE || ( $post > 0 && get_post_type( $post ) === Cpts::ARTIFACT_POST_TYPE ) );
	}

	/** Keep the TomaTío menu open and Artifacts highlighted while editing one. */
	public static function parentFile( string $parentFile ): string {
		return self::onArtifactEditScreen() ? Menu::PARENT : $parentFile;
	}

	public static function submenuFile( ?string $submenuFile ): ?string {
		return self::onArtifactEditScreen() ? 'edit.php?post_type=' . Cpts::ARTIFACT_POST_TYPE : $submenuFile;
	}

	/**
	 * Stage actions the viewer may perform from the board: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function viewerActions( int $viewerId, int $artifactId ): array {
		$stage   = Stages::artifactStage( $artifactId );
		$curator = ObjectAccess::userIsCurator( $viewerId );
		$out     = [];

		if ( $curator && ( new ArtifactService() )->canCompile( $artifactId ) ) {
			$out['compile']      = __( 'Compile to draft post', 'agent-brain' );
			$out['compile_page'] = __( 'Compile to draft page', 'agent-brain' );
		}
		if ( ( $curator || ObjectAccess::userIsLead( $viewerId, $artifactId ) ) && Stages::canTransitionArtifact( $stage, Cpts::STAGE_REVISING ) ) {
			$out['reopen'] = __( 'Reopen for revision', 'agent-brain' );
		}
		if ( user_can( $viewerId, Capabilities::CAP_PUBLISH ) ) {
			$status = get_post_status( $artifactId );
			if ( $status === 'publish' ) {
				$out['unpublish'] = __( 'Unpublish', 'agent-brain' );
			} elseif ( $stage === Cpts::STAGE_ASSEMBLED ) {
				$out['publish'] = __( 'Publish', 'agent-brain' );
			}
			if ( (string) get_post_meta( $artifactId, ArtifactService::META_ARCHIVED_AT, true ) === '' ) {
				$out['archive'] = __( 'Archive', 'agent-brain' );
			}
		}

		return $out;
	}

	/**
	 * The document's sections as the Review inbox's list and detail (#751):
	 * the outline on the left with each section's state, owner and version,
	 * the picked section on the right with its text, its review history and,
	 * for a reviewer, Approve and Request changes. It opens on the first
	 * section waiting for the viewer's review; `&section=ID` picks another.
	 * The board sits inside the post form, so the review forms are printed
	 * in the footer and the controls point at them (form="...").
	 */
	public static function outlineHtml( int $artifactId, int $viewerId ): string {
		$stage    = Stages::artifactStage( $artifactId );
		$sections = self::orderedSections( $artifactId );
		$picked   = isset( $_GET['section'] ) ? absint( $_GET['section'] ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection.
		$ids      = array_map( static fn ( \WP_Post $s ): int => (int) $s->ID, $sections );
		$selected = in_array( $picked, $ids, true ) ? $picked : self::firstWaiting( $viewerId, $sections );

		$waiting = 0;
		$drafts  = 0;
		foreach ( $sections as $section ) {
			$state    = Stages::sectionState( (int) $section->ID );
			$waiting += self::reviewActions( $viewerId, (int) $section->ID ) !== [] ? 1 : 0;
			$drafts  += in_array( $state, [ Cpts::SECTION_STATE_DRAFTING, Cpts::SECTION_STATE_CHANGES_REQUESTED ], true ) ? 1 : 0;
		}

		$status = [
			/* translators: %s: stage of the document, such as "Drafting" */
			sprintf( __( 'Stage: %s', 'agent-brain' ), '<b>' . esc_html( self::stageWord( $stage ) ) . '</b>' ),
			/* translators: %s: name of the lead agent */
			sprintf( __( 'Lead: %s', 'agent-brain' ), '<b>' . esc_html( InboxItems::who( (int) get_post_meta( $artifactId, Cpts::META_LEAD_AGENT, true ) )['name'] ) . '</b>' ),
		];
		if ( $waiting > 0 ) {
			/* translators: %d: number of sections */
			$status[] = '<b>' . esc_html( sprintf( _n( '%d section waits for your review', '%d sections wait for your review', $waiting, 'agent-brain' ), $waiting ) ) . '</b>';
		}
		if ( $drafts > 0 ) {
			/* translators: %d: number of sections */
			$status[] = esc_html( sprintf( _n( '%d draft', '%d drafts', $drafts, 'agent-brain' ), $drafts ) );
		}

		$out = '<div class="ab-outline-board"><p class="ab-ui-status">' . implode( ' · ', $status ) . '</p>';

		$buttons = '';
		foreach ( self::viewerActions( $viewerId, $artifactId ) as $slug => $label ) {
			$url      = wp_nonce_url(
				add_query_arg( [ 'action' => self::ACTION_STAGE, 'artifact_id' => $artifactId, 'do' => $slug ], admin_url( 'admin-post.php' ) ),
				self::NONCE_STAGE . $artifactId
			);
			$buttons .= '<a class="button' . ( $slug === 'publish' ? ' button-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		$out .= $buttons !== '' ? '<div class="ab-ui-actions-row">' . $buttons . '</div>' : '';

		if ( $sections === [] ) {
			return $out . '<p class="ab-empty">' . esc_html__( 'No sections yet. The lead plans the outline, then agents draft the sections.', 'agent-brain' ) . '</p></div>';
		}

		$base   = (string) get_edit_post_link( $artifactId, 'raw' );
		$labels = self::stateTags();
		$rows   = '';
		foreach ( $sections as $i => $section ) {
			$id     = (int) $section->ID;
			$state  = Stages::sectionState( $id );
			$holder = self::activeLeaseHolder( $id );
			$owner  = (int) get_post_meta( $id, Cpts::META_SECTION_OWNER, true );
			$meta   = Ui::tag( $labels[ $state ][0], $labels[ $state ][1] )
				. ' ' . esc_html( $owner > 0 ? InboxItems::who( $owner )['name'] : __( 'nobody yet', 'agent-brain' ) )
				/* translators: %d: version number */
				. ' · ' . esc_html( sprintf( __( 'version %d', 'agent-brain' ), (int) get_post_meta( $id, Cpts::META_VERSION, true ) ) )
				/* translators: %s: who is writing the section now */
				. ( $holder ? ' · ' . esc_html( sprintf( __( 'being written by %s', 'agent-brain' ), InboxItems::who( $holder )['name'] ) ) : '' );
			$rows  .= Ui::row(
				add_query_arg( 'section', $id, $base ),
				( $i + 1 ) . '. ' . ( $section->post_title !== '' ? $section->post_title : __( '(untitled)', 'agent-brain' ) ),
				$meta,
				$id === $selected
			);
		}

		$out .= Ui::split( __( 'Sections', 'agent-brain' ), $rows, self::sectionDetailHtml( $viewerId, $artifactId, $selected, count( $sections ), array_search( $selected, $ids, true ), $base ), $picked > 0 );

		return $out . '</div>';
	}

	/**
	 * Verdicts the viewer may give on a section from the board (ART-10): the
	 * same rules as ArtifactService::reviewSection(), which still decides.
	 *
	 * @return array<string, string> verdict => label
	 */
	public static function reviewActions( int $viewerId, int $sectionId ): array {
		$section = get_post( $sectionId );
		if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE ) {
			return [];
		}

		$curator = ObjectAccess::userIsCurator( $viewerId );
		if ( ! $curator && ! ObjectAccess::userIsLead( $viewerId, (int) $section->post_parent ) ) {
			return [];
		}

		$state = Stages::sectionState( $sectionId );
		if ( $state === Cpts::SECTION_STATE_SUBMITTED && ( $curator || ! ObjectAccess::userIsOwner( $viewerId, $sectionId ) ) ) {
			return [
				'approve'         => __( 'Approve', 'agent-brain' ),
				'request_changes' => __( 'Request changes', 'agent-brain' ),
			];
		}

		return [];
	}

	/**
	 * Sections in the order the artifact's outline stores (what
	 * artifact-reorder writes, ART-18); sections missing from the outline
	 * follow, in menu order.
	 *
	 * @return \WP_Post[]
	 */
	private static function orderedSections( int $artifactId ): array {
		$sections = get_posts( [
			'post_type'      => Cpts::SECTION_POST_TYPE,
			'post_parent'    => $artifactId,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order ID',
			'order'          => 'ASC',
		] );

		$position = [];
		foreach ( ( new ArtifactService() )->outlineSectionIds( $artifactId ) as $index => $sectionId ) {
			$position[ $sectionId ] ??= $index;
		}
		usort(
			$sections,
			static fn ( \WP_Post $a, \WP_Post $b ): int => ( $position[ (int) $a->ID ] ?? PHP_INT_MAX ) <=> ( $position[ (int) $b->ID ] ?? PHP_INT_MAX )
		);

		return $sections;
	}

	/**
	 * The first section waiting for the viewer's verdict, else the first.
	 *
	 * @param \WP_Post[] $sections
	 */
	private static function firstWaiting( int $viewerId, array $sections ): int {
		foreach ( $sections as $section ) {
			if ( self::reviewActions( $viewerId, (int) $section->ID ) !== [] ) {
				return (int) $section->ID;
			}
		}

		return $sections !== [] ? (int) $sections[0]->ID : 0;
	}

	/**
	 * After a verdict: the next section still waiting for this reviewer,
	 * counting on from the one just reviewed and wrapping round; 0 when
	 * none is left.
	 */
	public static function nextWaiting( int $viewerId, int $artifactId, int $sectionId ): int {
		$sections = self::orderedSections( $artifactId );
		$at       = array_search( $sectionId, array_map( static fn ( \WP_Post $s ): int => (int) $s->ID, $sections ), true );
		if ( is_int( $at ) ) {
			$sections = array_merge( array_slice( $sections, $at + 1 ), array_slice( $sections, 0, $at ) );
		}

		foreach ( $sections as $section ) {
			if ( self::reviewActions( $viewerId, (int) $section->ID ) !== [] ) {
				return (int) $section->ID;
			}
		}

		return 0;
	}

	/** @return array<string, array{0: string, 1: string}> state => [label, tag tone] */
	private static function stateTags(): array {
		return [
			Cpts::SECTION_STATE_DRAFTING          => [ __( 'Draft', 'agent-brain' ), '' ],
			Cpts::SECTION_STATE_SUBMITTED         => [ __( 'Waiting for review', 'agent-brain' ), 'info' ],
			Cpts::SECTION_STATE_APPROVED          => [ __( 'Approved', 'agent-brain' ), 'ok' ],
			Cpts::SECTION_STATE_CHANGES_REQUESTED => [ __( 'Changes asked', 'agent-brain' ), 'warn' ],
		];
	}

	private static function stageWord( string $stage ): string {
		return [
			Cpts::STAGE_OUTLINE   => __( 'Outline', 'agent-brain' ),
			Cpts::STAGE_DRAFTING  => __( 'Drafting', 'agent-brain' ),
			Cpts::STAGE_REVISING  => __( 'Revising', 'agent-brain' ),
			Cpts::STAGE_READY     => __( 'Ready to assemble', 'agent-brain' ),
			Cpts::STAGE_ASSEMBLED => __( 'Assembled', 'agent-brain' ),
		][ $stage ] ?? ucfirst( $stage );
	}

	/**
	 * The picked section: its text, who wrote it, its review history and the
	 * verdict controls the viewer may use.
	 *
	 * @param int|false $index position in the outline
	 */
	private static function sectionDetailHtml( int $viewerId, int $artifactId, int $sectionId, int $total, $index, string $back ): string {
		$section = get_post( $sectionId );
		if ( ! $section instanceof \WP_Post || (int) $section->post_parent !== $artifactId ) {
			return Ui::detail( '', '<p class="ab-empty">' . esc_html__( 'Pick a section.', 'agent-brain' ) . '</p>', '', $back );
		}

		$state  = Stages::sectionState( $sectionId );
		$labels = self::stateTags();
		$kicker = sprintf(
			/* translators: 1: section number, 2: number of sections, 3: state such as "Waiting for review" */
			__( 'Section %1$d of %2$d · %3$s', 'agent-brain' ),
			is_int( $index ) ? $index + 1 : 0,
			$total,
			$labels[ $state ][0]
		);

		$body = '<h2>' . esc_html( $section->post_title !== '' ? $section->post_title : __( '(untitled)', 'agent-brain' ) ) . '</h2>';
		if ( ObjectAccess::canReadSection( $viewerId, $sectionId ) ) {
			$content = (string) $section->post_content;
			$body   .= trim( $content ) !== ''
				? '<div class="ab-ui-prose ab-ui-section-text">' . wp_kses_post( do_blocks( $content ) ) . '</div>'
				: '<p class="ab-empty">' . esc_html__( 'Nothing written yet.', 'agent-brain' ) . '</p>';
		} else {
			$body .= '<p class="ab-empty">' . esc_html__( 'Only its owner, the lead, curators and reviewers can read this section.', 'agent-brain' ) . '</p>';
		}

		$owner  = (int) get_post_meta( $sectionId, Cpts::META_SECTION_OWNER, true );
		$holder = self::activeLeaseHolder( $sectionId );
		$facts  = [
			__( 'Written by', 'agent-brain' ) => esc_html( $owner > 0 ? InboxItems::who( $owner )['label'] : __( 'Nobody assigned yet', 'agent-brain' ) ),
			__( 'Version', 'agent-brain' )    => esc_html( (string) (int) get_post_meta( $sectionId, Cpts::META_VERSION, true ) ),
			__( 'Last changed', 'agent-brain' ) => DateFormat::html( $section->post_modified_gmt ),
		];
		if ( $holder ) {
			$facts[ __( 'Being written by', 'agent-brain' ) ] = esc_html( InboxItems::who( $holder )['label'] );
		}
		$body .= Ui::facts( $facts );

		$log = Stages::reviewLog( $sectionId );
		if ( $log !== [] ) {
			$verdicts = [
				'approve'         => [ __( 'Approved', 'agent-brain' ), 'ok' ],
				'request_changes' => [ __( 'Changes asked', 'agent-brain' ), 'warn' ],
				'reopen'          => [ __( 'Reopened', 'agent-brain' ), 'warn' ],
			];
			$body .= '<h2 class="ab-ui-kicker">' . esc_html__( 'Review history', 'agent-brain' ) . '</h2><ol class="ab-ui-thread">';
			foreach ( array_reverse( $log ) as $entry ) {
				$verdict = $entry['verdict'];
				$tag     = $verdicts[ $verdict ] ?? [ str_replace( '_', ' ', $verdict ), '' ];
				$body   .= '<li><p class="ab-ui-who"><strong>' . esc_html( InboxItems::who( $entry['reviewer_id'] )['label'] ) . '</strong> '
					. Ui::tag( $tag[0], $tag[1] ) . ' '
					/* translators: %d: version number */
					. esc_html( sprintf( __( 'on version %d', 'agent-brain' ), $entry['version'] ) ) . ' · '
					. DateFormat::html( $entry['at'] ) . '</p>'
					. ( $entry['notes'] !== '' ? '<div class="ab-ui-text">' . esc_html( $entry['notes'] ) . '</div>' : '' )
					. '</li>';
			}
			$body .= '</ol>';
		}

		$actions = '';
		$verdict = self::reviewActions( $viewerId, $sectionId );
		if ( $verdict !== [] ) {
			$formId = 'ab-review-' . $sectionId;
			$form   = '<form id="' . esc_attr( $formId ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$form  .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REVIEW ) . '" />';
			$form  .= '<input type="hidden" name="section_id" value="' . esc_attr( (string) $sectionId ) . '" />';
			$form  .= wp_nonce_field( self::NONCE_REVIEW . $sectionId, '_wpnonce', false, false ) . '</form>';
			self::$reviewForms[] = $form;

			$body .= '<p class="ab-ui-review-notes"><label for="ab-review-notes-' . esc_attr( (string) $sectionId ) . '">' . esc_html__( 'Notes for the writer', 'agent-brain' ) . '</label>'
				. '<textarea id="ab-review-notes-' . esc_attr( (string) $sectionId ) . '" name="notes" form="' . esc_attr( $formId ) . '" rows="3" class="large-text" placeholder="' . esc_attr__( 'Needed to ask for changes; optional to approve.', 'agent-brain' ) . '"></textarea></p>';
			foreach ( $verdict as $value => $label ) {
				$actions .= '<button type="submit" name="verdict" value="' . esc_attr( $value ) . '" form="' . esc_attr( $formId ) . '" class="button' . ( $value === 'approve' ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button>';
			}
		}
		$reopen = self::sectionReopenLink( $viewerId, $artifactId, $sectionId );
		if ( $reopen !== '' ) {
			$actions .= '<span class="ab-ui-spacer"></span>' . $reopen;
		}

		return Ui::detail( $kicker, $body, $actions, $back );
	}

	public static function printReviewForms(): void {
		echo implode( '', self::$reviewForms );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in sectionDetailHtml().
		self::$reviewForms = [];
	}

	/**
	 * Core of the review action, separated from redirects for testability.
	 *
	 * @param array<string, mixed> $post
	 * @return array{state:string}|\WP_Error
	 */
	public static function review( int $actorId, array $post ) {
		$notes   = sanitize_textarea_field( wp_unslash( (string) ( $post['notes'] ?? '' ) ) );
		$verdict = sanitize_key( (string) ( $post['verdict'] ?? '' ) );
		if ( $verdict === 'request_changes' && $notes === '' ) {
			return new \WP_Error( 'ab_notes_required', __( 'Say what needs to change: notes are required when requesting changes.', 'agent-brain' ) );
		}

		return ( new ArtifactService() )->reviewSection( $actorId, (int) ( $post['section_id'] ?? 0 ), $verdict, $notes );
	}

	public static function handleReview(): void {
		$sectionId = isset( $_POST['section_id'] ) ? absint( $_POST['section_id'] ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
		check_admin_referer( self::NONCE_REVIEW . $sectionId );

		$verdict = isset( $_POST['verdict'] ) ? sanitize_key( wp_unslash( (string) $_POST['verdict'] ) ) : '';
		if ( ! array_key_exists( $verdict, self::reviewActions( get_current_user_id(), $sectionId ) ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'agent-brain' ), '', [ 'response' => 403 ] );
		}

		$result = self::review( get_current_user_id(), $_POST );  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		if ( $result instanceof \WP_Error ) {
			// Back to the same section, with the reason (notes missing, a stale version).
			$section = get_post( $sectionId );
			$target  = $section ? get_edit_post_link( (int) $section->post_parent, 'raw' ) : null;
			if ( ! $target ) {
				wp_die( esc_html( $result->get_error_message() ), '', [ 'response' => 409, 'back_link' => true ] );
			}
			Notices::flash( 'error', $result->get_error_message() );
			wp_safe_redirect( add_query_arg( 'section', $sectionId, $target ) );
			exit;
		}

		// Straight on to the next section waiting for this reviewer, or back to this one.
		$section = get_post( $sectionId );
		$target  = $section ? get_edit_post_link( (int) $section->post_parent, 'raw' ) : null;
		$next    = $section ? self::nextWaiting( get_current_user_id(), (int) $section->post_parent, $sectionId ) : 0;
		wp_safe_redirect( $target ? add_query_arg( [ 'ab_reviewed' => $verdict, 'section' => $next > 0 ? $next : $sectionId ], $target ) : admin_url( 'edit.php?post_type=' . Cpts::ARTIFACT_POST_TYPE ) );
		exit;
	}

	public static function renderReviewNotice(): void {
		$done = isset( $_GET['ab_reviewed'] ) ? sanitize_key( wp_unslash( (string) $_GET['ab_reviewed'] ) ) : '';  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$text = [
			'approve'         => __( 'Section approved.', 'agent-brain' ),
			'request_changes' => __( 'Changes requested.', 'agent-brain' ),
			'reopen'          => __( 'Section reopened.', 'agent-brain' ),
		][ $done ] ?? '';
		if ( $text !== '' && self::onArtifactEditScreen() ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
		}
	}

	/** Per-section Reopen (ART-9): curator or lead, approved sections only. */
	public static function canReopenSection( int $viewerId, int $artifactId, int $sectionId ): bool {
		return Stages::sectionState( $sectionId ) === Cpts::SECTION_STATE_APPROVED
			&& ( ObjectAccess::userIsCurator( $viewerId ) || ObjectAccess::userIsLead( $viewerId, $artifactId ) );
	}

	private static function sectionReopenLink( int $viewerId, int $artifactId, int $sectionId ): string {
		if ( ! self::canReopenSection( $viewerId, $artifactId, $sectionId ) ) {
			return '';
		}

		$url = wp_nonce_url(
			add_query_arg( [ 'action' => self::ACTION_SECTION_REOPEN, 'section_id' => $sectionId ], admin_url( 'admin-post.php' ) ),
			self::NONCE_SECTION_REOPEN . $sectionId
		);

		return '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Reopen', 'agent-brain' ) . '</a>';
	}

	public static function handleSectionReopen(): void {
		$sectionId = isset( $_GET['section_id'] ) ? absint( $_GET['section_id'] ) : 0;
		check_admin_referer( self::NONCE_SECTION_REOPEN . $sectionId );

		$section    = get_post( $sectionId );
		$artifactId = $section ? (int) $section->post_parent : 0;
		if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE || ! self::canReopenSection( get_current_user_id(), $artifactId, $sectionId ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'agent-brain' ), '', [ 'response' => 403 ] );
		}

		$result = ( new ArtifactService() )->reviewSection( get_current_user_id(), $sectionId, 'reopen', '' );
		if ( $result instanceof \WP_Error ) {
			wp_die( esc_html( $result->get_error_message() ), '', [ 'response' => 409, 'back_link' => true ] );
		}

		$target = get_edit_post_link( $artifactId, 'raw' );
		wp_safe_redirect( $target ? add_query_arg( [ 'ab_reviewed' => 'reopen', 'section' => $sectionId ], $target ) : admin_url( 'edit.php?post_type=' . Cpts::ARTIFACT_POST_TYPE ) );
		exit;
	}

	/** @return true|int|\WP_Error int is the compiled post id. */
	public static function runStageAction( int $actorId, int $artifactId, string $do ) {
		$service = new ArtifactService();

		return match ( $do ) {
			'compile'      => $service->compile( $actorId, $artifactId ),
			'compile_page' => $service->compile( $actorId, $artifactId, 'page' ),
			'reopen'       => $service->reopen( $actorId, $artifactId ),
			'publish'      => $service->publish( $actorId, $artifactId ),
			'unpublish'    => $service->unpublish( $actorId, $artifactId ),
			'archive'      => $service->archive( $actorId, $artifactId ),
			default        => new \WP_Error( 'ab_unknown_action', __( 'Unknown action.', 'agent-brain' ) ),
		};
	}

	public static function handleStage(): void {
		$artifactId = isset( $_GET['artifact_id'] ) ? absint( $_GET['artifact_id'] ) : 0;
		check_admin_referer( self::NONCE_STAGE . $artifactId );

		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( (string) $_GET['do'] ) ) : '';
		if ( ! array_key_exists( $do, self::viewerActions( get_current_user_id(), $artifactId ) ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'agent-brain' ), '', [ 'response' => 403 ] );
		}

		$result = self::runStageAction( get_current_user_id(), $artifactId, $do );
		if ( $result instanceof \WP_Error ) {
			wp_die( esc_html( $result->get_error_message() ), '', [ 'response' => 409, 'back_link' => true ] );
		}

		$target = in_array( $do, [ 'compile', 'compile_page' ], true ) && is_int( $result ) ? get_edit_post_link( $result, 'raw' ) : get_edit_post_link( $artifactId, 'raw' );
		wp_safe_redirect( $target ? $target : admin_url( 'edit.php?post_type=' . Cpts::ARTIFACT_POST_TYPE ) );
		exit;
	}

	/** Lease holder only while the lease is live and the section can still be worked on. */
	private static function activeLeaseHolder( int $sectionId ): ?int {
		$state = Stages::sectionState( $sectionId );
		if ( $state === Cpts::SECTION_STATE_APPROVED || $state === Cpts::SECTION_STATE_SUBMITTED || Lease::isExpired( $sectionId ) ) {
			return null;
		}

		return Lease::currentHolder( $sectionId );
	}
}
