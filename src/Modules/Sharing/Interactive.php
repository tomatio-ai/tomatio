<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * Opt-in sandboxed script mode for share links (#61).
 *
 * The mode is a property of the ARTIFACT (post meta), evaluated on every
 * request, never stored on a link: a link minted before the toggle gets the
 * static policy until the flag is on, and goes back to it the moment the flag
 * (or the kill switch) is turned off.
 */
final class Interactive {

	public const META_FLAG        = 'ab_artifact_interactive';
	public const OPT_DISABLED     = 'ab_interactive_disabled';
	public const OPT_SCRIPT_HOSTS = 'ab_interactive_script_hosts';

	public static function killSwitchOn(): bool {
		return (bool) get_option( self::OPT_DISABLED, false );
	}

	public static function isFlagged( int $artifactId ): bool {
		return get_post_meta( $artifactId, self::META_FLAG, true ) === '1';
	}

	/**
	 * True only when the kill switch is off AND the artifact opted in.
	 */
	public static function isActive( int $artifactId ): bool {
		return ! self::killSwitchOn() && self::isFlagged( $artifactId );
	}

	/**
	 * Curator (or ab_manage) only; being the lead agent is not enough.
	 */
	public static function canToggle( int $actorId ): bool {
		return $actorId > 0 && ( ObjectAccess::userIsCurator( $actorId ) || user_can( $actorId, Capabilities::CAP_MANAGE ) );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set( int $artifactId, bool $on, int $actorId ) {
		$post = get_post( $artifactId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE ) {
			return new \WP_Error( 'ab_not_found', __( 'Artifact not found.', 'agent-brain' ), [ 'status' => 404 ] );
		}

		if ( ! self::canToggle( $actorId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( self::isFlagged( $artifactId ) === $on ) {
			return true;
		}

		if ( $on ) {
			update_post_meta( $artifactId, self::META_FLAG, '1' );
		} else {
			delete_post_meta( $artifactId, self::META_FLAG );
		}

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => $on ? 'artifact_interactive_enabled' : 'artifact_interactive_disabled',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [],
			]
		);

		return true;
	}

	/**
	 * Allowed remote script hosts (bare hostnames, https only).
	 *
	 * @return array<int, string>
	 */
	public static function scriptHosts(): array {
		$raw = get_option( self::OPT_SCRIPT_HOSTS, [] );
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}

		return self::parseHosts( $raw )['hosts'];
	}

	/**
	 * Split candidate hostnames into valid (lowercased, deduplicated) and invalid ones.
	 *
	 * @param array<int, mixed> $candidates
	 * @return array{hosts: array<int, string>, invalid: array<int, string>}
	 */
	public static function parseHosts( array $candidates ): array {
		$hosts   = [];
		$invalid = [];
		foreach ( $candidates as $host ) {
			$host = strtolower( trim( (string) $host ) );
			if ( $host === '' ) {
				continue;
			}
			if ( preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host ) === 1 ) {
				$hosts[] = $host;
			} else {
				$invalid[] = $host;
			}
		}

		return [
			'hosts'   => array_values( array_unique( $hosts ) ),
			'invalid' => $invalid,
		];
	}

	/**
	 * Static (default) policy for share pages: no script of any kind.
	 */
	public static function staticCsp( string $frameAncestors = "'none'" ): string {
		return \AgentBrain\Modules\Artifacts\Preview::CSP . '; frame-ancestors ' . $frameAncestors;
	}

	/**
	 * Script-capable but isolated policy. `sandbox allow-scripts` without
	 * allow-same-origin gives the document an opaque origin (no cookies, no
	 * storage, no access to the preview host); no top navigation, no forms,
	 * no popups, no network egress (connect-src/form-action none).
	 */
	public static function interactiveCsp( string $frameAncestors = "'none'" ): string {
		$scriptSrc = "'unsafe-inline'";
		foreach ( self::scriptHosts() as $host ) {
			$scriptSrc .= ' https://' . $host;
		}

		return "default-src 'none'; img-src data: https:; style-src 'unsafe-inline'; script-src " . $scriptSrc
			. "; connect-src 'none'; form-action 'none'; base-uri 'none'; sandbox allow-scripts; frame-ancestors " . $frameAncestors;
	}

	/**
	 * The same policies for a `<meta http-equiv="Content-Security-Policy">`
	 * inside a document delivered in a sandboxed iframe (wp-admin previews
	 * in srcdoc, the #219 members address frame): sandbox comes from the
	 * iframe's `sandbox` attribute or the response header, and a meta policy
	 * cannot carry sandbox or frame-ancestors, so both are stripped.
	 */
	public static function metaCsp( bool $interactive = false ): string {
		$policy = preg_replace(
			'#;\s*(?:sandbox|frame-ancestors)\b[^;]*#i',
			'',
			$interactive ? self::interactiveCsp() : self::staticCsp()
		);

		return is_string( $policy ) ? $policy : "default-src 'none'";
	}

	/**
	 * Sanitise stored section HTML for interactive mode. Same tag/attribute
	 * allowlist as static mode plus <script> and <style>. Inline event
	 * handler attributes (on*), javascript: URLs, <iframe>/<object>/<embed>,
	 * <form>, <meta>, <base> and <link> stay stripped by the allowlist. A
	 * <script src> survives only if its https host is in ab_interactive_script_hosts.
	 *
	 * @param array<string, array<string, bool>> $staticAllowed
	 */
	public static function sanitize( string $html, array $staticAllowed ): string {
		$allowed           = $staticAllowed;
		$allowed['script'] = [ 'type' => true, 'src' => true ];
		$allowed['style']  = [ 'type' => true ];

		$clean = wp_kses( $html, $allowed, [ 'https' ] );
		$hosts = self::scriptHosts();

		$filtered = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			static function ( array $m ) use ( $hosts ): string {
				if ( preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/is', $m[1], $src ) !== 1 ) {
					return $m[0];
				}
				$host = strtolower( (string) wp_parse_url( $src[2], PHP_URL_HOST ) );
				$ok   = strtolower( (string) wp_parse_url( $src[2], PHP_URL_SCHEME ) ) === 'https' && in_array( $host, $hosts, true );

				return $ok ? $m[0] : '';
			},
			$clean
		);

		return $filtered ?? '';
	}
}
