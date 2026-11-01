<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Cpts;

/**
 * "Share links" panel on the artifact edit screen: create, list, revoke.
 * Rendered into the main column of the dedicated artifact screen, inside the
 * post edit form, so create / revoke / list are fetch() calls to admin-post.php
 * (assets/share-panel.js). The raw URL is shown exactly once, in the response
 * to the create request (never stored, never re-rendered into the edit screen).
 */
final class AdminPanel {

	public const ACTION_CREATE  = 'ab_share_create';
	public const ACTION_REVOKE  = 'ab_share_revoke';
	public const ACTION_APPROVE = 'ab_share_approve';
	public const ACTION_LIST    = 'ab_share_list';
	public const SCRIPT_HANDLE  = 'agent-brain-share-panel';

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'admin_post_' . self::ACTION_CREATE, [ self::class, 'handleCreate' ] );
		add_action( 'admin_post_' . self::ACTION_REVOKE, [ self::class, 'handleRevoke' ] );
		add_action( 'admin_post_' . self::ACTION_LIST, [ self::class, 'handleList' ] );
		add_action( 'admin_post_' . self::ACTION_APPROVE, [ self::class, 'handleApprove' ] );
	}

	/**
	 * Main-column panel on the dedicated artifact screen (EditScreen): heading
	 * plus the panel body, only for people who may manage links.
	 */
	public static function renderPanel( \WP_Post $post ): void {
		if ( ! ShareLinks::canManage( get_current_user_id(), (int) $post->ID ) ) {
			return;
		}

		echo '<div class="postbox ab-share-box"><div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Share links', 'agent-brain' ) . '</h2></div><div class="inside">';
		self::render( $post );
		echo '</div></div>';
	}

	/**
	 * Script + localized data, only on the artifact edit screen.
	 */
	public static function enqueue( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) || ! defined( 'AGENT_BRAIN_PLUGIN_FILE' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();
		if ( ! $screen || $screen->post_type !== Cpts::ARTIFACT_POST_TYPE || ! $post instanceof \WP_Post || ! ShareLinks::canManage( get_current_user_id(), (int) $post->ID ) ) {
			return;
		}

		wp_enqueue_script( self::SCRIPT_HANDLE, plugins_url( 'assets/share-panel.js', AGENT_BRAIN_PLUGIN_FILE ), [], \AgentBrain\Core\AssetVersion::of( 'assets/share-panel.js' ), true );
		wp_add_inline_script( self::SCRIPT_HANDLE, 'window.agentBrainShare = ' . wp_json_encode( self::scriptData( (int) $post->ID ) ) . ';', 'before' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function scriptData( int $artifactId ): array {
		return [
			'endpoint'   => admin_url( 'admin-post.php' ),
			'artifactId' => $artifactId,
			'actions'    => [
				'create' => self::ACTION_CREATE,
				'revoke' => self::ACTION_REVOKE,
				'list'   => self::ACTION_LIST,
			],
			'nonces'     => [
				'create' => wp_create_nonce( self::ACTION_CREATE . $artifactId ),
				'list'   => wp_create_nonce( self::ACTION_LIST . $artifactId ),
			],
			'i18n'       => [
				'shownOnce' => __( 'Copy this link now. It is shown only once and cannot be retrieved later.', 'agent-brain' ),
				'copy'      => __( 'Copy', 'agent-brain' ),
				'linkLabel' => __( 'Share link (shown once)', 'agent-brain' ),
				'revoked'   => __( 'Share link revoked.', 'agent-brain' ),
				'copied'    => __( 'Copied', 'agent-brain' ),
				'failed'    => __( 'Request failed.', 'agent-brain' ),
				'pending'   => __( 'This link is inactive until a curator approves it.', 'agent-brain' ),
				'expires'   => __( 'Expires', 'agent-brain' ),
				'ipBinding' => __( 'IP binding', 'agent-brain' ),
				'privacyLabel' => __( 'Who can open it', 'agent-brain' ),
				'publicLink' => __( 'public: anyone with the link', 'agent-brain' ),
				'privateLink' => __( 'private: named members only', 'agent-brain' ),
				'privateHint' => __( 'A private link shows nothing until a named member signs in: the viewer opens the link, exchanges their single-use member code for a viewer token, and then the page serves only with that token.', 'agent-brain' ),
				'embedHtml' => __( 'Embed snippet (iframe). Only the origins on the embed allowlist can display it.', 'agent-brain' ),
				'embedCode' => __( 'Single-use embed URL (valid for 60 seconds, then the page gets a session cookie). Prefer this over the snippet: the long-lived link never appears in a URL.', 'agent-brain' ),
			],
		];
	}

	public static function render( \WP_Post $post ): void {
		$artifactId = (int) $post->ID;
		$list       = self::listHtml( $artifactId, get_current_user_id() );

		if ( $list === null ) {
			return;
		}

		if ( Config::disabled() ) {
			echo '<p><em>' . esc_html__( 'Sharing is disabled on this site.', 'agent-brain' ) . '</em></p>';
		}

		echo '<div id="ab-share-panel" data-artifact-id="' . esc_attr( (string) $artifactId ) . '">';
		echo '<div id="ab-share-list">' . $list . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in listHtml().
		echo '<div id="ab-share-result" role="status" aria-live="polite" tabindex="-1"></div>';
		// Plain controls, deliberately not a submit button: the panel lives inside
		// the post edit form, so a submit would save the post. assets/share-panel.js drives them.
		echo '<p><label>' . esc_html__( 'Expires after (hours)', 'agent-brain' ) . ' <input type="number" id="ab-share-ttl" min="1" max="8760" value="72" /></label> ';
		echo '<label>' . esc_html__( 'IP binding', 'agent-brain' ) . ' <select id="ab-share-ip">';
		foreach ( Policy::IP_MODES as $mode ) {
			echo '<option value="' . esc_attr( $mode ) . '"' . selected( Config::defaultIpMode(), $mode, false ) . '>' . esc_html( $mode ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Who can open it', 'agent-brain' ) . ' <select id="ab-share-privacy">';
		echo '<option value="' . esc_attr( ShareLinks::PRIVACY_PRIVATE ) . '">' . esc_html__( 'Private (named members only)', 'agent-brain' ) . '</option>';
		echo '<option value="' . esc_attr( ShareLinks::PRIVACY_PUBLIC ) . '">' . esc_html__( 'Public (anyone with the link)', 'agent-brain' ) . '</option>';
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Kind', 'agent-brain' ) . ' <select id="ab-share-kind">';
		echo '<option value="' . esc_attr( EmbedPolicy::KIND_LINK ) . '">' . esc_html__( 'Link', 'agent-brain' ) . '</option>';
		echo '<option value="' . esc_attr( EmbedPolicy::KIND_EMBED ) . '">' . esc_html__( 'Embed (iframe, 24 h default)', 'agent-brain' ) . '</option>';
		echo '</select></label> ';
		$origins = EmbedSettings::allowedOrigins();
		if ( $origins !== [] ) {
			echo '<label>' . esc_html__( 'Embedding site (embeds only)', 'agent-brain' ) . ' <select id="ab-share-origin">';
			foreach ( $origins as $origin ) {
				echo '<option value="' . esc_attr( $origin ) . '">' . esc_html( $origin ) . '</option>';
			}
			echo '</select></label> ';
		}
		echo '<button type="button" class="button" id="ab-share-create">' . esc_html__( 'Create share link', 'agent-brain' ) . '</button></p>';

		// #219: the permanent members address — shown to everyone the
		// operator's role setting admits (which can be people who cannot
		// manage links; the panel itself renders for link managers only, so
		// this also gates on canManage via renderPanel above).
		if ( MembersAddress::canSeeAddress( get_current_user_id(), $artifactId ) ) {
			$url = MembersAddress::url( $artifactId );
			echo '<p class="ab-members-address"><strong>' . esc_html__( 'Members address', 'agent-brain' ) . '</strong> ';
			echo '<input type="text" id="ab-members-address-url" class="regular-text code" readonly="readonly" value="' . esc_attr( $url ) . '" aria-label="' . esc_attr__( 'Members address', 'agent-brain' ) . '" /> ';
			echo '<button type="button" class="button ab-share-copy" data-copy="' . esc_attr( $url ) . '">' . esc_html__( 'Copy', 'agent-brain' ) . '</button><br />';
			echo '<span class="description">' . esc_html__( 'Permanent address for brain members: sign in once, then it always shows the current approved version. Older versions: add ?v= (lead, curators, managers). Removing someone\'s access to the artifact is what stops them.', 'agent-brain' ) . '</span></p>';
		}

		echo '</div>';
	}

	/**
	 * Escaped HTML of the link table, or null when the actor may not list.
	 */
	public static function listHtml( int $artifactId, int $actorId ): ?string {
		$links = ShareLinks::listForArtifact( $artifactId, $actorId );

		if ( $links instanceof \WP_Error ) {
			return null;
		}

		if ( $links === [] ) {
			return '<p>' . esc_html__( 'No share links yet.', 'agent-brain' ) . '</p>';
		}

		$html = '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Share links for this artifact', 'agent-brain' ) . '</caption><thead><tr>';
		foreach ( [ 'ID', 'Kind', 'Privacy', 'Status', 'Created', 'Expires', 'IP binding', 'Opens' ] as $heading ) {
			$html .= '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		$html .= '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'agent-brain' ) . '</span></th>';
		$html .= '</tr></thead><tbody>';
		foreach ( $links as $link ) {
			$linkId = (int) $link['id'];
			$html  .= '<tr><td>' . esc_html( (string) $link['id'] ) . '</td><td>' . esc_html( (string) $link['kind'] ) . '</td><td>' . esc_html( (string) $link['privacy'] ) . '</td><td>' . esc_html( (string) $link['status'] ) . '</td><td>' . esc_html( (string) $link['created_at'] ) . ' UTC</td><td>' . esc_html( (string) $link['expires_at'] ) . ' UTC</td><td>' . esc_html( (string) $link['ip_mode'] ) . '</td><td>' . esc_html( (string) $link['open_count'] ) . '</td><td>';
			if ( $link['status'] === 'active' || $link['status'] === 'pending_approval' ) {
				$html .= '<button type="button" class="button-link ab-share-revoke" data-link-id="' . esc_attr( (string) $linkId ) . '" aria-label="' . esc_attr( sprintf( /* translators: %d: share link id */ __( 'Revoke share link %d', 'agent-brain' ), $linkId ) ) . '" data-confirm="' . esc_attr( \AgentBrain\Modules\Admin\Confirm::message( __( 'Revoke this share link: anyone holding it can no longer open the document', 'agent-brain' ), __( 'Create a new share link to share it again', 'agent-brain' ) ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( self::ACTION_REVOKE . $linkId ) ) . '">' . esc_html__( 'Revoke', 'agent-brain' ) . '</button>';
			}
			if ( $link['status'] === 'pending_approval' && current_user_can( \AgentBrain\Modules\Identity\Capabilities::CAP_MANAGE_ARTIFACTS ) ) {
				$approve = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_APPROVE . '&link_id=' . $linkId . '&artifact_id=' . $artifactId ), self::ACTION_APPROVE . $linkId );
				$html   .= ' | <a href="' . esc_url( $approve ) . '">' . esc_html__( 'Approve', 'agent-brain' ) . '</a>';
			}
			$html .= '</td></tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public static function createPayload( int $artifactId, int $actorId, string $nonce, int $hours, ?string $mode = null, string $kind = EmbedPolicy::KIND_LINK, string $origin = '', string $privacy = ShareLinks::PRIVACY_PRIVATE ): array {
		if ( ! ShareLinks::canManage( $actorId, $artifactId ) || ! wp_verify_nonce( $nonce, self::ACTION_CREATE . $artifactId ) ) {
			return [ 'status' => 403, 'body' => [ 'message' => __( 'You do not have permission to do this.', 'agent-brain' ) ] ];
		}

		// #400: refuse a private link the artifact can never serve.
		$guard = PrivateLinkGuard::check( $artifactId, $privacy );
		if ( $guard instanceof \WP_Error ) {
			return [ 'status' => 409, 'body' => [ 'message' => $guard->get_error_message() ] ];
		}

		$result = ShareLinks::mint( $artifactId, $actorId, $hours > 0 ? $hours * 3600 : null, $mode, null, $kind, ShareLinks::VIEWER_ANONYMOUS, $privacy );
		if ( $result instanceof \WP_Error ) {
			return [ 'status' => 400, 'body' => [ 'message' => $result->get_error_message() ] ];
		}

		// No auto-loading preview iframe: it would be a real open from the
		// admin's browser and bind a first_open / enforced-IP link to the admin's IP.
		$embed = $result['kind'] === EmbedPolicy::KIND_EMBED ? EmbedPolicy::iframeSnippet( $result['url'], Interactive::isActive( $artifactId ) ) : '';

		// Embed links also get a single-use exchange code for one allowlisted
		// origin, so the page that shows the embed never has to carry the
		// long-lived token in a URL. The bare-token snippet keeps working.
		$code = null;
		if ( $embed !== '' ) {
			$origins = EmbedSettings::allowedOrigins();
			$origin  = $origin !== '' ? $origin : ( count( $origins ) === 1 ? $origins[0] : '' );
			$code    = $origin !== '' ? EmbedExchange::issueCode( $actorId, (int) $result['id'], $origin ) : null;
		}

		return [
			'status' => 200,
			'body'   => [
				'kind'             => $result['kind'],
				'embed_html'       => $embed,
				'embed_url'        => is_array( $code ) ? $code['url'] : '',
				'url'              => $result['url'],
				'expires_at'       => $result['expires_at'],
				'ip_mode'          => $result['ip_mode'],
				'privacy'          => $result['privacy'],
				'pending_approval' => (bool) $result['pending_approval'],
				'html'             => self::listHtml( $artifactId, $actorId ) ?? '',
			],
		];
	}

	/**
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public static function revokePayload( int $linkId, int $artifactId, int $actorId, string $nonce ): array {
		if ( ! wp_verify_nonce( $nonce, self::ACTION_REVOKE . $linkId ) ) {
			return [ 'status' => 403, 'body' => [ 'message' => __( 'You do not have permission to do this.', 'agent-brain' ) ] ];
		}

		$result = ShareLinks::revoke( $linkId, $actorId );
		if ( $result instanceof \WP_Error ) {
			return [ 'status' => 403, 'body' => [ 'message' => $result->get_error_message() ] ];
		}

		return [ 'status' => 200, 'body' => [ 'html' => self::listHtml( $artifactId, $actorId ) ?? '' ] ];
	}

	/**
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public static function listPayload( int $artifactId, int $actorId, string $nonce ): array {
		$html = wp_verify_nonce( $nonce, self::ACTION_LIST . $artifactId ) ? self::listHtml( $artifactId, $actorId ) : null;
		if ( $html === null ) {
			return [ 'status' => 403, 'body' => [ 'message' => __( 'You do not have permission to do this.', 'agent-brain' ) ] ];
		}

		return [ 'status' => 200, 'body' => [ 'html' => $html ] ];
	}

	public static function handleCreate(): void {
		self::respond(
			self::createPayload(
				self::postInt( 'artifact_id' ),
				get_current_user_id(),
				self::postString( 'nonce' ),
				self::postInt( 'ttl_hours' ),
				sanitize_key( self::postString( 'ip_mode' ) ) ?: null,
				sanitize_key( self::postString( 'kind' ) ),
				esc_url_raw( self::postString( 'embed_origin' ) ),
				sanitize_key( self::postString( 'privacy' ) ) ?: ShareLinks::PRIVACY_PRIVATE
			)
		);
	}

	public static function handleRevoke(): void {
		self::respond( self::revokePayload( self::postInt( 'link_id' ), self::postInt( 'artifact_id' ), get_current_user_id(), self::postString( 'nonce' ) ) );
	}

	public static function handleList(): void {
		self::respond( self::listPayload( self::postInt( 'artifact_id' ), get_current_user_id(), self::postString( 'nonce' ) ) );
	}

	/**
	 * @param array{status:int,body:array<string,mixed>} $payload
	 */
	private static function respond( array $payload ): void {
		nocache_headers();
		header( 'Cache-Control: private, no-store' );
		wp_send_json( $payload['body'], $payload['status'] );
	}

	private static function postInt( string $key ): int {
		return isset( $_POST[ $key ] ) ? absint( wp_unslash( (string) $_POST[ $key ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in the *Payload methods.
	}

	private static function postString( string $key ): string {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in the *Payload methods.
	}

	public static function handleApprove(): void {
		$linkId = isset( $_GET['link_id'] ) ? absint( wp_unslash( (string) $_GET['link_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below.
		check_admin_referer( self::ACTION_APPROVE . $linkId );

		$result = ShareLinks::approve( $linkId, get_current_user_id() );
		if ( $result instanceof \WP_Error ) {
			wp_die( esc_html( $result->get_error_message() ), 403 );
		}

		self::backToArtifact();
	}

	private static function backToArtifact(): void {
		$artifactId = isset( $_GET['artifact_id'] ) ? absint( wp_unslash( (string) $_GET['artifact_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by caller.
		wp_safe_redirect( get_edit_post_link( $artifactId, 'raw' ) ?: admin_url() );
		exit;
	}
}
