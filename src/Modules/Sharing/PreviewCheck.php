<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Preview;

/**
 * Check that the configured preview host resolves, answers over https with a
 * valid certificate, and is served by THIS install: the probe is a GET of
 * /share/_whoami, which the share route answers on the preview host with a
 * short-lived value signed by this install's random id (Whoami). A host that
 * routes to any other install — even one running this plugin — fails the
 * signature check instead of silently minting dead links (#285).
 *
 * Fail-safe: when the check cannot run at all (outbound HTTP blocked on this
 * server) the result is UNKNOWN. Callers must treat UNKNOWN as "not proven"
 * and keep allowing the action with a visible warning, never as "reaches".
 * Only a finished check that did not verify (DNS / TLS / UNREACHED /
 * NOT_PLUGIN) proves the host is wrong and may refuse the action.
 */
final class PreviewCheck {

	public const PROBE_PATH = '/share/_whoami';

	public const OK         = 'ok';
	public const NO_HOST    = 'no_host';
	public const DNS        = 'dns';
	public const TLS        = 'tls';
	public const UNREACHED  = 'unreached';
	public const NOT_PLUGIN = 'not_plugin';
	public const UNKNOWN    = 'unknown';

	private const RESULTS = [ self::OK, self::NO_HOST, self::DNS, self::TLS, self::UNREACHED, self::NOT_PLUGIN, self::UNKNOWN ];

	/**
	 * @param string|null $host Defaults to the configured preview host.
	 * @return string One of the class constants; OK means the host is proven to reach this install.
	 */
	public static function run( ?string $host = null ): string {
		$host = trim( (string) ( $host ?? Preview::configuredHost() ) );
		if ( $host === '' ) {
			return self::NO_HOST;
		}

		// Test hook: integration tests stub the whole probe without HTTP.
		$forced = apply_filters( 'agent_brain/preview_check_forced', null, $host );
		if ( is_string( $forced ) && in_array( $forced, self::RESULTS, true ) ) {
			return $forced;
		}

		if ( self::outboundBlocked( $host ) ) {
			return self::UNKNOWN;
		}

		$resolved = apply_filters( 'agent_brain/preview_check_resolves', null, $host );
		if ( $resolved === null ) {
			$resolved = gethostbyname( $host ) !== $host;
		}
		if ( ! $resolved ) {
			return self::DNS;
		}

		$response = wp_remote_get(
			'https://' . $host . self::PROBE_PATH,
			[
				'timeout'     => 8,
				'redirection' => 0,
				'sslverify'   => true,
			]
		);

		if ( $response instanceof \WP_Error ) {
			$message = strtolower( $response->get_error_message() );

			return strpos( $message, 'ssl' ) !== false || strpos( $message, 'certificate' ) !== false ? self::TLS : self::UNREACHED;
		}

		if ( wp_remote_retrieve_response_code( $response ) === 200 && Whoami::valid( (string) wp_remote_retrieve_body( $response ) ) ) {
			return self::OK;
		}

		return self::NOT_PLUGIN;
	}

	/**
	 * Whether outbound HTTP from this server is (or is treated as) blocked,
	 * so the check cannot run at all. Mirrors WordPress core's
	 * WP_HTTP_BLOCK_EXTERNAL / WP_ACCESSIBLE_HOSTS handling; a boolean on the
	 * `agent_brain/preview_check_outbound_blocked` filter overrides (tests).
	 */
	private static function outboundBlocked( string $host ): bool {
		$blocked = apply_filters( 'agent_brain/preview_check_outbound_blocked', null, $host );
		if ( is_bool( $blocked ) ) {
			return $blocked;
		}

		if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || ! WP_HTTP_BLOCK_EXTERNAL ) {
			return false;
		}

		if ( ! defined( 'WP_ACCESSIBLE_HOSTS' ) || WP_ACCESSIBLE_HOSTS === '' ) {
			return true;
		}

		foreach ( array_map( 'trim', explode( ',', (string) WP_ACCESSIBLE_HOSTS ) ) as $allowed ) {
			$allowed = strtolower( $allowed );
			if ( $allowed === '*' ) {
				return false;
			}
			if ( $allowed !== '' && substr_compare( $host, $allowed, -strlen( $allowed ) ) === 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Plain-language outcome for a result key.
	 */
	public static function message( string $result ): string {
		$messages = [
			self::OK         => __( 'The preview host resolves, has a valid certificate and answers as this site: its /share/_whoami matches this install\'s identity. You can create share links.', 'agent-brain' ),
			self::NO_HOST    => __( 'No preview host is saved yet. Enter one below and save first.', 'agent-brain' ),
			self::DNS        => __( 'The preview host does not resolve. Add a DNS record for it that points at the same server as this site, then test again (new records can take a while to spread).', 'agent-brain' ),
			self::TLS        => __( 'The preview host answers, but its certificate is not valid for that name. Issue a certificate that covers the preview host (or a wildcard) and test again.', 'agent-brain' ),
			self::UNREACHED  => __( 'The preview host could not be reached over https. Check that the web server listens for this hostname on port 443 and that this server can call it.', 'agent-brain' ),
			self::NOT_PLUGIN => __( 'The preview host answered, but not as this site: /share/_whoami did not match this install\'s identity. Point the hostname\'s DNS or proxy at this WordPress install (a different site running TomaTío is still the wrong site).', 'agent-brain' ),
			self::UNKNOWN    => __( 'The reachability check could not run: outbound HTTPS from this server is blocked. The host stays allowed but unverified — fix outbound HTTPS if you can, then test again.', 'agent-brain' ),
		];

		return $messages[ $result ] ?? $messages[ self::UNREACHED ];
	}
}
