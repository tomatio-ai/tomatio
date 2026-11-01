<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Provenance;

use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\ScopeVisibility;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\Artifacts\Stages;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\MemoriesAdmin\Filters;
use AgentBrain\Modules\MemoriesAdmin\Store;
use AgentBrain\Modules\Mcp\Present;

/**
 * PROV-4 (#173): the Provenance wp-admin screen — the curator-facing,
 * UNFILTERED view of one item's provenance: the outside-source records it
 * names, the brain items it cites, what it was derived from, who approved
 * it (and through which client), the action context recorded for it, and
 * block-level blame. Memory rows, knowledge/procedure posts, artifact
 * sections and compiled posts all render through the same screen, reached
 * with ?page=agent-brain-provenance&kind=<kind>&id=<id> (the Memories
 * screen links here per row).
 *
 * Capability: ab_manage (curators and administrators) — the same role that
 * can already read raw memories on the Memories screen or run Forget. The
 * item itself must be within the viewer's scopes (#552, Admin\ScopeVisibility);
 * once it is, the provenance block builders get null (unfiltered): the
 * block names other items by identifier only. MCP reads attach the same
 * block scoped to the caller.
 */
final class Panel {

	public const SLUG = 'agent-brain-provenance';

	public const CAPABILITY = Capabilities::CAP_MANAGE;

	/** @var string[] */
	public const KINDS = [ 'memory', 'knowledge', 'procedure', 'section', 'post' ];

	public static function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_menu', [ self::class, 'addMenu' ] );
	}

	public static function addMenu(): void {
		add_submenu_page(
			Menu::PARENT,
			__( 'Provenance', 'agent-brain' ),
			__( 'Provenance', 'agent-brain' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * A deep link into the panel for one item (the Memories screen, #220's
	 * History box and later surfaces use it).
	 */
	public static function url( string $kind, int $id ): string {
		return add_query_arg(
			[ 'page' => self::SLUG, 'kind' => $kind, 'id' => $id ],
			admin_url( 'admin.php' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view Provenance.', 'agent-brain' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only search, no state change.
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( (string) $_GET['kind'] ) ) : '';
		$id   = isset( $_GET['id'] ) ? absint( wp_unslash( (string) $_GET['id'] ) ) : 0;
		$q    = isset( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) ) : '';
		// phpcs:enable

		$viewer = get_current_user_id();
		$hits   = [];

		echo '<div class="wrap ab-admin ab-admin-provenance">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::head(
			__( 'Where did this come from?', 'agent-brain' ),
			esc_html__( 'Trace a fact, memory or document back to its sources', 'agent-brain' ),
			'',
			'<p>' . esc_html__( 'The chain shows the item, who made it official and through which client, what it was built from, what it cites and the outside sources it names. Below it: what the writer\'s session had read (action context) and who wrote each part (blame). Everything is identifiers only, never content of other items.', 'agent-brain' ) . '</p>'
		);

		if ( $kind === '' || $id === 0 ) {
			$hits = $q !== '' ? self::search( $viewer, $q ) : [];
			if ( count( $hits ) === 1 ) {
				[ $kind, $id ] = [ $hits[0]['kind'], $hits[0]['id'] ];
			}
		}

		self::renderSearch( $q !== '' ? $q : ( $kind !== '' && $id > 0 ? self::titleOf( $kind, $id ) : '' ) );

		if ( $kind !== '' && $id > 0 ) {
			echo self::itemHtml( $viewer, $kind, $id );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		} elseif ( $q !== '' ) {
			self::renderHits( $hits );
		}

		echo '</div>';
	}

	/**
	 * #751: search by text instead of "item kind + numeric ID". "memory 12"
	 * or "#12" still finds an item by its number.
	 */
	private static function renderSearch( string $value ): void {
		echo '<form method="get" class="ab-ui-search" role="search" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<label for="ab-prov-q" class="screen-reader-text">' . esc_html__( 'Find an item', 'agent-brain' ) . '</label>';
		echo '<input type="search" id="ab-prov-q" name="q" class="ab-ui-search-input" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr__( 'A title, words from a memory, or memory 12', 'agent-brain' ) . '" />';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Trace', 'agent-brain' ) . '</button>';
		echo '</form>';
	}

	/**
	 * @param array<int, array{kind:string, id:int, title:string, meta:string}> $hits
	 */
	private static function renderHits( array $hits ): void {
		if ( $hits === [] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
			echo Ui::callout( 'info', __( 'Nothing matches.', 'agent-brain' ), esc_html__( 'Try other words from the title or the text, or a number such as memory 12.', 'agent-brain' ) );

			return;
		}

		echo '<section class="ab-ui-card"><h2 class="ab-ui-kicker">' . esc_html__( 'Pick the item to trace', 'agent-brain' ) . '</h2><ul class="ab-ui-hits">';
		foreach ( $hits as $hit ) {
			echo '<li><a href="' . esc_url( self::url( $hit['kind'], $hit['id'] ) ) . '">'
				. Ui::tag( self::kindLabels()[ $hit['kind'] ] ?? $hit['kind'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::tag() escapes.
				. ' <b>' . esc_html( $hit['title'] ) . '</b></a>'
				. ( $hit['meta'] !== '' ? '<small>' . esc_html( $hit['meta'] ) . '</small>' : '' ) . '</li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Items the viewer may see whose text or title matches, memories first.
	 *
	 * @return array<int, array{kind:string, id:int, title:string, meta:string}>
	 */
	public static function search( int $viewerId, string $q, int $limit = 10 ): array {
		if ( ! user_can( $viewerId, self::CAPABILITY ) || $q === '' ) {
			return [];
		}

		if ( preg_match( '/^(memory|knowledge|procedure|section|post)?\s*#?\s*(\d+)$/i', $q, $m ) === 1 ) {
			$kind = $m[1] !== '' ? strtolower( $m[1] ) : 'memory';
			$id   = (int) $m[2];

			return self::visibleTo( $viewerId, $kind, $id ) && self::headerFields( $kind, $id ) !== null
				? [ [ 'kind' => $kind, 'id' => $id, 'title' => self::titleOf( $kind, $id ), 'meta' => '' ] ]
				: [];
		}

		$hits = [];
		$rows = Store::query( Filters::fromArray( [ 'q' => $q ] ), 1, $limit, ScopeVisibility::visibleScopeIds( $viewerId ) )['rows'];
		foreach ( $rows as $row ) {
			$hits[] = [
				'kind'  => 'memory',
				'id'    => (int) $row['id'],
				'title' => self::excerpt( (string) $row['content'] ),
				/* translators: 1: memory number, 2: who wrote it */
				'meta'  => sprintf( __( 'Memory #%1$d by %2$s', 'agent-brain' ), (int) $row['id'], self::userName( (int) $row['agent_id'] ) ),
			];
		}

		$types = [ 'ab_knowledge' => 'knowledge', 'ab_procedure' => 'procedure', 'ab_artifact_section' => 'section' ];
		$ids   = get_posts(
			[
				'post_type'        => array_keys( $types ),
				'post_status'      => 'any',
				's'                => $q,
				'posts_per_page'   => $limit,
				'fields'           => 'ids',
				'suppress_filters' => false,
			]
		);
		foreach ( ScopeVisibility::filterPostIds( $viewerId, array_map( 'intval', $ids ) ) as $postId ) {
			$post = get_post( $postId );
			if ( $post instanceof \WP_Post && isset( $types[ $post->post_type ] ) ) {
				$hits[] = [
					'kind'  => $types[ $post->post_type ],
					'id'    => (int) $post->ID,
					'title' => $post->post_title !== '' ? $post->post_title : self::excerpt( $post->post_content ),
					/* translators: 1: item kind, 2: item number */
					'meta'  => sprintf( __( '%1$s #%2$d', 'agent-brain' ), self::kindLabels()[ $types[ $post->post_type ] ], (int) $post->ID ),
				];
			}
		}

		return array_slice( $hits, 0, $limit );
	}

	private static function excerpt( string $text ): string {
		return wp_trim_words( wp_strip_all_tags( $text ), 14, '…' );
	}

	/**
	 * The whole provenance view for one item, as HTML. Gates on the
	 * curator capability for $viewerId (user_can, so it is testable with an
	 * arbitrary viewer id); renders nothing sensitive when the item does
	 * not exist or the kind is unknown.
	 */
	public static function itemHtml( int $viewerId, string $kind, int $id ): string {
		if ( ! user_can( $viewerId, self::CAPABILITY ) ) {
			return '<p class="ab-empty">' . esc_html__( 'You do not have permission to view the provenance of this item.', 'agent-brain' ) . '</p>';
		}

		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return '<p class="ab-empty">' . esc_html__( 'Unknown item kind. Choose memory, knowledge, procedure, section or post.', 'agent-brain' ) . '</p>';
		}

		$fields = self::visibleTo( $viewerId, $kind, $id ) ? self::headerFields( $kind, $id ) : null;
		if ( $fields === null ) {
			return '<p class="ab-empty">' . esc_html__( 'That item does not exist.', 'agent-brain' ) . '</p>';
		}

		$block = self::blockFor( $kind, $id );

		$out = self::traceHtml( $kind, $id, $fields, $block );

		$out .= self::foldHtml( __( 'Sources, citations and build', 'agent-brain' ),
			self::sourcesHtml( $block['sources'] )
			. self::linksHtml( __( 'Citations', 'agent-brain' ), __( 'This item does not cite any brain items.', 'agent-brain' ), $block['cites'] )
			. self::linksHtml( __( 'Derived from', 'agent-brain' ), __( 'This item was not built from other brain items.', 'agent-brain' ), $block['derived_from'] )
			. self::approvalsHtml( $block['approvals'] ) );
		$out .= self::foldHtml( __( 'Action context', 'agent-brain' ), self::actionContextHtml( $kind, $id ) );
		$out .= self::foldHtml( __( 'Blame', 'agent-brain' ), self::blameHtml( $kind, $id ) );

		return $out;
	}

	/** A detail table folded into one line, with its h2 in the summary. */
	private static function foldHtml( string $title, string $body ): string {
		return '<details class="ab-ui-card ab-ui-fold"><summary><h2 class="ab-ui-fold-title">' . esc_html( $title ) . '</h2></summary>' . $body . '</details>';
	}

	/**
	 * #751: the chain top to bottom: the item, who made it official, what
	 * it was built from, what it cites and the outside sources it names.
	 * Each brain item links to its own trace.
	 *
	 * @param array<string, string> $fields
	 * @param array{sources: array<int, array<string, mixed>>, cites: array<int, array{kind:string, id:int, version:?int}>, derived_from: array<int, array{kind:string, id:int, version:?int}>, approvals: array<int, array<string, mixed>>} $block
	 */
	private static function traceHtml( string $kind, int $id, array $fields, array $block ): string {
		$official = $kind !== 'memory' && ( $fields['status'] ?? '' ) === 'publish';
		$label    = $official ? __( 'Official', 'agent-brain' ) : ( self::kindLabels()[ $kind ] ?? $kind );
		$meta     = array_filter( [ $fields['meta'] ?? '', sprintf( '%s #%d', $kind, $id ) ] );

		$out  = '<ol class="ab-ui-trace" aria-label="' . esc_attr__( 'Where it came from', 'agent-brain' ) . '">';
		$out .= '<li>' . Ui::tag( $label, $official ? 'ok' : 'type' ) . ' <b>' . esc_html( $fields['title'] ) . '</b><small>' . esc_html( implode( ' · ', $meta ) ) . '</small></li>';

		foreach ( $block['approvals'] as $approval ) {
			$by     = self::userName( (int) ( $approval['by'] ?? 0 ) );
			$client = (string) ( $approval['client'] ?? '' );
			$out   .= '<li>' . Ui::tag( __( 'Approved by', 'agent-brain' ), 'ok' ) . ' <b>' . esc_html( $by ) . '</b><small>'
				. esc_html( implode( ' · ', array_filter( [ (string) ( $approval['at'] ?? '' ), $client !== '' ? sprintf( /* translators: %s: MCP client name */ __( 'through %s', 'agent-brain' ), $client ) : '', ! empty( $approval['ai'] ) ? __( 'relayed by an AI', 'agent-brain' ) : '' ] ) ) )
				. '</small></li>';
		}

		if ( $kind !== 'memory' && ( $fields['author'] ?? '-' ) !== '-' ) {
			$out .= '<li>' . Ui::tag( __( 'Written by', 'agent-brain' ) ) . ' <b>' . esc_html( $fields['author'] ) . '</b></li>';
		}

		foreach ( [ [ $block['derived_from'], __( 'Built from', 'agent-brain' ) ], [ $block['cites'], __( 'Cites', 'agent-brain' ) ] ] as [ $links, $tag ] ) {
			foreach ( $links as $link ) {
				$ref  = (string) $link['kind'] . ' #' . (int) $link['id'] . ( $link['version'] !== null ? ' v' . (int) $link['version'] : '' );
				$name = in_array( $link['kind'], self::KINDS, true ) ? self::titleOf( (string) $link['kind'], (int) $link['id'] ) : '';
				$out .= '<li>' . Ui::tag( $tag, 'info' ) . ' '
					. ( in_array( $link['kind'], self::KINDS, true ) ? '<a href="' . esc_url( self::url( (string) $link['kind'], (int) $link['id'] ) ) . '">' . esc_html( $name !== '' ? $name : $ref ) . '</a>' : esc_html( $ref ) )
					. '<small>' . esc_html( $ref ) . '</small></li>';
			}
		}

		foreach ( $block['sources'] as $source ) {
			$locator = (string) $source['locator'];
			$title   = (string) $source['title'] !== '' ? (string) $source['title'] : $locator;
			$link    = wp_http_validate_url( $locator ) ? '<a href="' . esc_url( $locator ) . '" rel="noopener noreferrer">' . esc_html( $title ) . '</a>' : esc_html( $title );
			$out    .= '<li>' . Ui::tag( __( 'Source', 'agent-brain' ) ) . ' ' . $link . '<small>' . esc_html( implode( ' · ', array_filter( [ $locator !== $title ? $locator : '', (string) ( $source['publisher'] ?? '' ), sprintf( /* translators: %s: who registered the source */ __( 'Registered by %s', 'agent-brain' ), self::userName( (int) ( $source['agent_id'] ?? 0 ) ) ) ] ) ) ) . '</small></li>';
		}

		if ( $block['approvals'] === [] && $block['derived_from'] === [] && $block['cites'] === [] && $block['sources'] === [] ) {
			$out .= '<li class="ab-ui-trace-end">' . esc_html__( 'Nothing further is recorded: no approvals, no items it was built from or cites, and no outside sources.', 'agent-brain' ) . '</li>';
		}

		return $out . '</ol>';
	}

	/**
	 * #552: an item outside the viewer's scopes reads as one that does not
	 * exist, the same answer as a wrong id.
	 */
	private static function visibleTo( int $viewerId, string $kind, int $id ): bool {
		if ( $kind === 'memory' ) {
			$row = Store::find( $id );

			return $row === null || ScopeVisibility::canSeeScope( $viewerId, (int) $row['scope_id'] );
		}

		return ScopeVisibility::canSeePost( $viewerId, $id );
	}

	// ---------------------------------------------------------------
	// Header
	// ---------------------------------------------------------------

	/**
	 * The item's title, status, author and a one-line description, or null
	 * when it does not exist (or is not of that kind).
	 *
	 * @return array{title:string, status:string, author:string, meta:string}|null
	 */
	private static function headerFields( string $kind, int $id ): ?array {
		if ( $kind === 'memory' ) {
			$row = Store::find( $id );
			if ( $row === null ) {
				return null;
			}

			$author = self::userName( (int) $row['agent_id'] );

			return [
				'title'  => self::excerpt( (string) $row['content'] ),
				'status' => (string) ( $row['status'] ?? '' ),
				'author' => $author,
				/* translators: 1: memory type, 2: who wrote it, 3: when */
				'meta'   => sprintf( __( '%1$s memory by %2$s, %3$s', 'agent-brain' ), (string) $row['type'], $author, Present::iso( (string) ( $row['created'] ?? '' ) ) ),
			];
		}

		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$postType = $kind === 'section' ? 'ab_artifact_section' : ( $kind === 'knowledge' ? 'ab_knowledge' : ( $kind === 'procedure' ? 'ab_procedure' : '' ) );
		if ( $kind === 'post' ) {
			if ( in_array( $post->post_type, [ 'ab_artifact_section', 'ab_knowledge', 'ab_procedure', 'ab_artifact' ], true ) ) {
				return null;
			}
		} elseif ( $post->post_type !== $postType ) {
			return null;
		}

		return [
			'title'  => $post->post_title !== '' ? $post->post_title : self::excerpt( $post->post_content ),
			'status' => $post->post_status,
			'author' => self::userName( (int) $post->post_author ),
			/* translators: %s: post status */
			'meta'   => sprintf( __( 'Status: %s', 'agent-brain' ), $post->post_status ),
		];
	}

	/** A short name for an item, for the search box and the chain's links. */
	private static function titleOf( string $kind, int $id ): string {
		$fields = self::headerFields( $kind, $id );

		return $fields !== null ? $fields['title'] : '';
	}

	// ---------------------------------------------------------------
	// Provenance block sections
	// ---------------------------------------------------------------

	/**
	 * The provenance block for the kind, unfiltered (the panel is the
	 * curator view).
	 *
	 * @return array{sources: array<int, array<string, mixed>>, cites: array<int, array{kind:string, id:int, version:?int}>, derived_from: array<int, array{kind:string, id:int, version:?int}>, approvals: array<int, array<string, mixed>>}
	 */
	private static function blockFor( string $kind, int $id ): array {
		switch ( $kind ) {
			case 'memory':
				return Provenance::forMemory( $id );
			case 'section':
				return Provenance::forSection( $id );
			default:
				return Provenance::forPost( $id );
		}
	}

	/**
	 * The outside-source records (PROV-3): small reference records, never
	 * copies of the source content.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 */
	private static function sourcesHtml( array $sources ): string {
		$out = '<h3>' . esc_html__( 'Outside sources', 'agent-brain' ) . '</h3>';
		if ( $sources === [] ) {
			return $out . '<p class="ab-empty">' . esc_html__( 'No outside sources are recorded for this item.', 'agent-brain' ) . '</p>';
		}

		$out .= '<table class="widefat striped"><thead><tr>';
		foreach ( [ __( 'Kind', 'agent-brain' ), __( 'Title', 'agent-brain' ), __( 'Locator', 'agent-brain' ), __( 'Publisher', 'agent-brain' ), __( 'Retrieved', 'agent-brain' ), __( 'Hash', 'agent-brain' ), __( 'Registered by', 'agent-brain' ) ] as $head ) {
			$out .= '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';

		foreach ( $sources as $source ) {
			$out .= '<tr>';
			$out .= '<td>' . esc_html( (string) $source['kind'] ) . '</td>';
			$out .= '<td>' . esc_html( (string) $source['title'] ) . '</td>';
			$out .= '<td><code>' . esc_html( (string) $source['locator'] ) . '</code></td>';
			$out .= '<td>' . esc_html( (string) ( $source['publisher'] ?? '' ) ) . '</td>';
			$out .= '<td>' . esc_html( (string) ( $source['retrieved_at'] ?? '' ) ) . '</td>';
			$out .= '<td>' . esc_html( $source['hash'] !== null ? substr( (string) $source['hash'], 0, 12 ) . '…' : '' ) . '</td>';
			$out .= '<td>' . esc_html( self::userName( (int) ( $source['agent_id'] ?? 0 ) ) ) . '</td>';
			$out .= '</tr>';
		}

		return $out . '</tbody></table>';
	}

	/**
	 * A cites or derived-from list (identifiers and versions only).
	 *
	 * @param array<int, array{kind:string, id:int, version:?int}> $links
	 */
	private static function linksHtml( string $title, string $empty, array $links ): string {
		$out = '<h3>' . esc_html( $title ) . '</h3>';
		if ( $links === [] ) {
			return $out . '<p class="ab-empty">' . esc_html( $empty ) . '</p>';
		}

		$out .= '<ul class="ul-disc">';
		foreach ( $links as $link ) {
			$label = (string) $link['kind'] . ' #' . (int) $link['id'];
			if ( $link['version'] !== null ) {
				$label .= ' v' . (int) $link['version'];
			}
			$out .= '<li><code>' . esc_html( $label ) . '</code></li>';
		}

		return $out . '</ul>';
	}

	/**
	 * Who approved it, through which client (PROV-6).
	 *
	 * @param array<int, array<string, mixed>> $approvals
	 */
	private static function approvalsHtml( array $approvals ): string {
		$out = '<h3>' . esc_html__( 'Approvals', 'agent-brain' ) . '</h3>';
		if ( $approvals === [] ) {
			return $out . '<p class="ab-empty">' . esc_html__( 'No approvals are recorded for this item.', 'agent-brain' ) . '</p>';
		}

		$out .= '<table class="widefat striped"><thead><tr>';
		foreach ( [ __( 'Approved by', 'agent-brain' ), __( 'When', 'agent-brain' ), __( 'Client', 'agent-brain' ), __( 'Via', 'agent-brain' ), __( 'AI', 'agent-brain' ), __( 'Version', 'agent-brain' ) ] as $head ) {
			$out .= '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';

		foreach ( $approvals as $approval ) {
			$out .= '<tr>';
			$out .= '<td>' . esc_html( self::userName( (int) ( $approval['by'] ?? 0 ) ) ) . '</td>';
			$out .= '<td>' . esc_html( (string) ( $approval['at'] ?? '' ) ) . '</td>';
			$out .= '<td>' . esc_html( (string) ( $approval['client'] ?? '' ) ) . '</td>';
			$out .= '<td>' . esc_html( self::userName( (int) ( $approval['via'] ?? 0 ) ) ) . '</td>';
			$out .= '<td>' . ( ! empty( $approval['ai'] ) ? esc_html__( 'yes', 'agent-brain' ) : esc_html__( 'no', 'agent-brain' ) ) . '</td>';
			$out .= '<td>' . ( isset( $approval['version'] ) && $approval['version'] !== null ? 'v' . esc_html( (string) (int) $approval['version'] ) : '-' ) . '</td>';
			$out .= '</tr>';
		}

		return $out . '</tbody></table>';
	}

	// ---------------------------------------------------------------
	// Action context
	// ---------------------------------------------------------------

	/**
	 * What the writer's session saw (a memory row's read set, PROV-6) or
	 * the per-write action log (a section, PROV-6). Identifiers only.
	 */
	private static function actionContextHtml( string $kind, int $id ): string {
		if ( $kind === 'memory' ) {
			$row = Store::find( $id );
			$raw = is_array( $row ) ? (string) ( $row['read_set'] ?? '' ) : '';
			$set = $raw !== '' ? json_decode( $raw, true ) : [];
			if ( ! is_array( $set ) || $set === [] ) {
				return '<p class="ab-empty">' . esc_html__( 'No read set was recorded for this memory (action context may be off, or the row predates it).', 'agent-brain' ) . '</p>';
			}

			$out = '<p>' . esc_html__( 'The identifiers this row\'s session had read when it wrote (identifiers only, never content):', 'agent-brain' ) . '</p>';
			$out .= '<ul class="ul-disc">';
			foreach ( $set as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$label = (string) ( $item['kind'] ?? '' ) . ' #' . (int) ( $item['id'] ?? 0 );
				if ( isset( $item['version'] ) && is_numeric( $item['version'] ) ) {
					$label .= ' v' . (int) $item['version'];
				}
				$out .= '<li><code>' . esc_html( $label ) . '</code></li>';
			}

			return $out . '</ul>';
		}

		if ( $kind === 'section' ) {
			// Stored JSON, not a guaranteed shape: entries written before the
			// PROV-6 stamps lack the client/via/ai/read_set keys.
			/** @var array<int, array<string, mixed>> $log */
			$log = Stages::actionLog( $id );
			if ( $log === [] ) {
				return '<p class="ab-empty">' . esc_html__( 'No action log is recorded for this section (action context may be off, or the section predates it).', 'agent-brain' ) . '</p>';
			}

			$out = '<table class="widefat striped"><thead><tr>';
			foreach ( [ __( 'Version', 'agent-brain' ), __( 'When', 'agent-brain' ), __( 'Actor', 'agent-brain' ), __( 'Client', 'agent-brain' ), __( 'Via', 'agent-brain' ), __( 'AI', 'agent-brain' ), __( 'Read set', 'agent-brain' ) ] as $head ) {
				$out .= '<th scope="col">' . esc_html( $head ) . '</th>';
			}
			$out .= '</tr></thead><tbody>';

			foreach ( $log as $entry ) {
				$out .= '<tr>';
				$out .= '<td>v' . esc_html( (string) (int) ( $entry['version'] ?? 0 ) ) . '</td>';
				$out .= '<td>' . esc_html( (string) ( $entry['at'] ?? '' ) ) . '</td>';
				$out .= '<td>' . esc_html( self::userName( (int) ( $entry['actor_id'] ?? 0 ) ) ) . '</td>';
				$out .= '<td>' . esc_html( (string) ( $entry['client'] ?? '' ) ) . '</td>';
				$out .= '<td>' . esc_html( self::userName( (int) ( $entry['via'] ?? 0 ) ) ) . '</td>';
				$out .= '<td>' . ( ! empty( $entry['ai'] ) ? esc_html__( 'yes', 'agent-brain' ) : esc_html__( 'no', 'agent-brain' ) ) . '</td>';
				$out .= '<td>' . esc_html( self::readSetLabel( $entry['read_set'] ?? [] ) ) . '</td>';
				$out .= '</tr>';
			}

			return $out . '</tbody></table>';
		}

		return '<p class="ab-empty">' . esc_html__( 'Action context is recorded for memory rows and sections only.', 'agent-brain' ) . '</p>';
	}

	/**
	 * One action-log read set as a compact label: "memory #1, section #2 v3".
	 *
	 * @param mixed $readSet
	 */
	private static function readSetLabel( $readSet ): string {
		if ( ! is_array( $readSet ) ) {
			return '';
		}

		$labels = [];
		foreach ( $readSet as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = (string) ( $item['kind'] ?? '' ) . ' #' . (int) ( $item['id'] ?? 0 );
			if ( isset( $item['version'] ) && is_numeric( $item['version'] ) ) {
				$label .= ' v' . (int) $item['version'];
			}
			$labels[] = $label;
		}

		return implode( ', ', $labels );
	}

	// ---------------------------------------------------------------
	// Blame
	// ---------------------------------------------------------------

	/**
	 * Per-revision history (actor, via, client, AI, time) and the
	 * block-level blame of the current content, reusing RevisionBlame
	 * exactly as the History & Blame box does (PROV-5).
	 */
	private static function blameHtml( string $kind, int $id ): string {
		if ( $kind === 'memory' ) {
			return '<p class="ab-empty">' . esc_html__( 'Memory rows carry their author on the row itself; revision blame applies to sections and posts.', 'agent-brain' ) . '</p>';
		}

		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return '<p class="ab-empty">' . esc_html__( 'That item does not exist.', 'agent-brain' ) . '</p>';
		}

		$states = RevisionBlame::states( $id );

		$out = '<h3>' . esc_html__( 'Revision history', 'agent-brain' ) . '</h3>';
		if ( $states === [] ) {
			$out .= '<p class="ab-empty">' . esc_html__( 'No revisions yet.', 'agent-brain' ) . '</p>';
		} else {
			// RevisionBlame::states() documents the precise shape, but the
			// blame stamps behind it are stored JSON — read defensively.
			/** @var array<int, array<string, mixed>> $states */
			$out .= '<table class="widefat striped"><thead><tr>';
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
				$out .= ( $i === $last ? '<td>' . esc_html__( 'current', 'agent-brain' ) . '</td>' : '' );
				$out .= '</tr>';
			}

			$out .= '</tbody></table>';
		}

		$blame  = RevisionBlame::blame( $id );
		$blocks = $blame['blocks'];

		$out .= '<h3>' . esc_html__( 'Block blame', 'agent-brain' ) . '</h3>';
		if ( $blocks === [] ) {
			return $out . '<p class="ab-empty">' . esc_html__( 'No blocks to blame.', 'agent-brain' ) . '</p>';
		}

		// Same defensive read as the history table above: the blame stamps
		// behind the documented shape are stored JSON.
		/** @var array<int, array<string, mixed>> $blocks */
		$out .= '<table class="widefat striped"><thead><tr>';
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

	// ---------------------------------------------------------------
	// Lookups
	// ---------------------------------------------------------------

	/**
	 * @return array<string, string>
	 */
	private static function kindLabels(): array {
		return [
			'memory'    => __( 'Memory row', 'agent-brain' ),
			'knowledge' => __( 'Knowledge entry', 'agent-brain' ),
			'procedure' => __( 'Procedure entry', 'agent-brain' ),
			'section'   => __( 'Artifact section', 'agent-brain' ),
			'post'      => __( 'Compiled post', 'agent-brain' ),
		];
	}

	private static function userName( int $userId ): string {
		$user = $userId > 0 ? get_userdata( $userId ) : false;

		return $user instanceof \WP_User ? (string) $user->display_name : '-';
	}
}
