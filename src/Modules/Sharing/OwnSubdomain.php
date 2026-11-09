<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Artifacts\Preview;

/**
 * #800: share links on a subdomain of the team's own domain (advanced,
 * opt-in). Settings > Sharing guides the change in three steps:
 *
 * 1. The person types the subdomain (for example share.acme.com). It is kept
 *    as PENDING: nothing changes for share links yet.
 * 2. The brain shows the exact DNS record to add for that name: a CNAME to
 *    this brain's host, or A/AAAA records for a self-hoster. The pending host
 *    answers /share/_whoami (and nothing else), so it can be checked.
 * 3. "Check and switch" proves DNS, HTTPS and that the name reaches THIS
 *    install (PreviewCheck), and only then makes it the share address.
 *
 * Every change of the share address is audit-logged (old host, new host,
 * who) and leaves the old address redirecting share links to the new one
 * for GRACE_DAYS, so links already sent keep working. "Use the default
 * address" switches back in one click.
 *
 * Never required: with nothing set, share links work on the brain's own
 * address (#286) or the hosted preview host, with no DNS step.
 */
final class OwnSubdomain {

	public const OPTION_PENDING  = 'ab_share_host_pending';
	public const OPTION_PREVIOUS = 'ab_share_host_previous';

	public const GRACE_DAYS = 30;

	/** How many old addresses keep redirecting at once. */
	private const MAX_PREVIOUS = 5;

	public const ACTION_START  = 'ab_share_subdomain_start';
	public const ACTION_CHECK  = 'ab_share_subdomain_check';
	public const ACTION_CANCEL = 'ab_share_subdomain_cancel';
	public const ACTION_RESET  = 'ab_share_subdomain_reset';

	public const AUDIT_EVENT = 'share_host_changed';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_START, [ self::class, 'handleStart' ] );
		add_action( 'admin_post_' . self::ACTION_CHECK, [ self::class, 'handleCheck' ] );
		add_action( 'admin_post_' . self::ACTION_CANCEL, [ self::class, 'handleCancel' ] );
		add_action( 'admin_post_' . self::ACTION_RESET, [ self::class, 'handleReset' ] );
		// Before the private-site gate (an mu-plugin on template_redirect 0),
		// so a link on an old address redirects instead of asking for a login.
		add_action( 'template_redirect', [ self::class, 'maybeRedirect' ], -1 );
	}

	// State ------------------------------------------------------------------

	public static function pendingHost(): string {
		return strtolower( trim( (string) get_option( self::OPTION_PENDING, '' ) ) );
	}

	public static function setPending( string $host ): void {
		if ( $host === '' ) {
			delete_option( self::OPTION_PENDING );

			return;
		}

		update_option( self::OPTION_PENDING, strtolower( $host ), false );
	}

	public static function isPendingHost( string $requestHost ): bool {
		$pending = self::pendingHost();

		return $pending !== '' && hash_equals( $pending, self::bareHost( $requestHost ) );
	}

	/**
	 * Whether the share address is a subdomain the person chose: a SAVED
	 * preview host, not one a filter or the hosting environment supplies.
	 */
	public static function ownHostActive(): bool {
		if ( ! function_exists( 'wp_load_alloptions' ) ) {
			return false;
		}

		$saved = strtolower( trim( (string) ( wp_load_alloptions()[ Preview::OPTION_HOST ] ?? '' ) ) );

		return $saved !== '' && $saved !== Preview::defaultHost();
	}

	/**
	 * Old addresses that still redirect, newest first.
	 *
	 * @return array<int, array{host:string,until:int}>
	 */
	public static function previousHosts( ?int $now = null ): array {
		$now  = $now ?? time();
		$list = get_option( self::OPTION_PREVIOUS, [] );
		$out  = [];
		foreach ( is_array( $list ) ? $list : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$host  = strtolower( (string) ( $row['host'] ?? '' ) );
			$until = (int) ( $row['until'] ?? 0 );
			if ( $host !== '' && $until > $now ) {
				$out[] = [ 'host' => $host, 'until' => $until ];
			}
		}

		return $out;
	}

	// Changing the share address -----------------------------------------------

	/**
	 * Make $host the share address ('' = back to the default), with the
	 * grace redirect and the audit entry. The caller has checked $host.
	 */
	public static function switchTo( string $host ): void {
		$old = Preview::effectiveHost()['host'];

		if ( $host === '' || strtolower( $host ) === Preview::defaultHost() ) {
			delete_option( Preview::OPTION_HOST );
		} else {
			update_option( Preview::OPTION_HOST, strtolower( $host ) );
		}

		self::setPending( '' );
		HostReachability::invalidate();
		self::recordChange( $old, Preview::effectiveHost()['host'] );
	}

	/**
	 * After the share address changed from $old to $new: keep $old
	 * redirecting for GRACE_DAYS and write the audit entry. A no-op when
	 * nothing changed.
	 */
	public static function recordChange( string $old, string $new, ?int $now = null ): void {
		$old = strtolower( trim( $old ) );
		$new = strtolower( trim( $new ) );
		if ( $old === $new ) {
			return;
		}

		$now  = $now ?? time();
		$list = [];
		if ( $old !== '' ) {
			$list[] = [ 'host' => $old, 'until' => $now + self::GRACE_DAYS * DAY_IN_SECONDS ];
		}
		foreach ( self::previousHosts( $now ) as $row ) {
			// The new address serves links itself; the old one is re-added above.
			if ( $row['host'] !== $new && $row['host'] !== $old ) {
				$list[] = $row;
			}
		}
		$list = array_slice( $list, 0, self::MAX_PREVIOUS );

		if ( $list === [] ) {
			delete_option( self::OPTION_PREVIOUS );
		} else {
			update_option( self::OPTION_PREVIOUS, $list );
		}

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => get_current_user_id(),
				'event'       => self::AUDIT_EVENT,
				'status'      => 'ok',
				'object_type' => 'setting',
				'object_id'   => Preview::OPTION_HOST,
				'meta'        => [
					'old_host'   => $old,
					'new_host'   => $new,
					'grace_days' => $old !== '' ? self::GRACE_DAYS : 0,
				],
			]
		);
	}

	// Serving ------------------------------------------------------------------

	/**
	 * Where a share request on an old address goes during the grace period,
	 * or null to leave it alone. Only GET/HEAD of share pages and their
	 * files; never the identity probe (an old address must not pass a check).
	 */
	public static function redirectTarget( string $requestHost, string $method, string $uri, ?int $now = null ): ?string {
		if ( ! in_array( strtoupper( $method ), [ 'GET', 'HEAD' ], true ) ) {
			return null;
		}

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( $path === Whoami::PATH || ! Preview::isSharePath( $path ) ) {
			return null;
		}

		$host = self::bareHost( $requestHost );
		if ( $host === '' || Preview::servesShareRequests( $host ) ) {
			return null;
		}

		$current = Preview::effectiveHost()['host'];
		if ( $current === '' || $current === $host ) {
			return null;
		}

		foreach ( self::previousHosts( $now ) as $row ) {
			if ( hash_equals( $row['host'], $host ) ) {
				return ShareLinks::baseUrl() . $uri;
			}
		}

		return null;
	}

	public static function maybeRedirect(): void {
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET';

		$target = self::redirectTarget( $host, $method, $uri );
		if ( $target === null ) {
			return;
		}

		// 302, not 301: a browser must not remember it past the grace period
		// or after the person switches back.
		wp_redirect( $target, 302, 'TomaTio' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- this brain's own share address.
		exit;
	}

	// DNS record ---------------------------------------------------------------

	/**
	 * The exact DNS records for $host: a CNAME to this brain's host, and
	 * (unless the hosting says otherwise) A/AAAA records with this brain's
	 * addresses. Hosted deployments set the CNAME target and turn the
	 * address records off through filters.
	 *
	 * @return array{cname:string,addresses:array<int, array{type:string,value:string}>,certificate:string}
	 */
	public static function dnsRecords( string $host ): array {
		$site = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		$cname = \AgentBrain\Core\Hooks::filter( 'share_host_cname_target', $site, $host );
		$cname = is_string( $cname ) ? strtolower( trim( $cname ) ) : $site;

		$addresses = \AgentBrain\Core\Hooks::filter( 'share_host_addresses', null, $site );
		if ( ! is_array( $addresses ) ) {
			$addresses = self::resolve( $site );
		}

		$rows = [];
		foreach ( $addresses as $row ) {
			if ( is_array( $row ) && in_array( $row['type'] ?? '', [ 'A', 'AAAA' ], true ) && filter_var( (string) ( $row['value'] ?? '' ), FILTER_VALIDATE_IP ) !== false ) {
				$rows[] = [ 'type' => (string) $row['type'], 'value' => (string) $row['value'] ];
			}
		}

		$certificate = \AgentBrain\Core\Hooks::filter(
			'share_host_certificate_note',
			__( 'Your web server also needs a valid HTTPS certificate for this name, and must pass requests for it to this WordPress install. An extra name on the site\'s certificate, or a wildcard, works.', 'tomatio' ),
			$host
		);

		return [
			'cname'       => $cname,
			'addresses'   => $rows,
			'certificate' => is_string( $certificate ) ? $certificate : '',
		];
	}

	/**
	 * @return array<int, array{type:string,value:string}>
	 */
	private static function resolve( string $site ): array {
		if ( $site === '' || ! function_exists( 'dns_get_record' ) ) {
			return [];
		}

		$out = [];
		foreach ( [ DNS_A => 'ip', DNS_AAAA => 'ipv6' ] as $type => $field ) {
			$records = @dns_get_record( $site, $type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup just means no address rows.
			foreach ( is_array( $records ) ? $records : [] as $record ) {
				if ( isset( $record[ $field ] ) ) {
					$out[] = [ 'type' => $type === DNS_A ? 'A' : 'AAAA', 'value' => (string) $record[ $field ] ];
				}
			}
		}

		return $out;
	}

	// Admin actions --------------------------------------------------------------

	/**
	 * Step 1: keep the typed subdomain as pending.
	 *
	 * @param array<string, mixed> $post Unslashed form data.
	 * @return string '' or an error key.
	 */
	public static function start( array $post ): string {
		$raw  = isset( $post['ab_share_subdomain'] ) && is_string( $post['ab_share_subdomain'] ) ? sanitize_text_field( $post['ab_share_subdomain'] ) : '';
		$host = Policy::validatePreviewHost( $raw, Preview::siteHosts() );
		if ( strpos( $host, 'error:' ) === 0 ) {
			return substr( $host, 6 );
		}
		if ( $host === Preview::effectiveHost()['host'] ) {
			return 'already';
		}

		self::setPending( $host );

		return '';
	}

	/**
	 * Step 3: check the pending subdomain and switch to it only when it is
	 * proven to reach this install.
	 *
	 * @return string PreviewCheck result ('ok' = switched).
	 */
	public static function checkAndSwitch(): string {
		$pending = self::pendingHost();
		if ( $pending === '' ) {
			return PreviewCheck::NO_HOST;
		}

		$result = PreviewCheck::run( $pending );
		if ( $result === PreviewCheck::OK ) {
			self::switchTo( $pending );
		}

		return $result;
	}

	public static function handleStart(): void {
		self::guard( self::ACTION_START );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$error = self::start( wp_unslash( $_POST ) );
		self::back( $error === '' ? [ 'ab_subdomain' => 'pending' ] : [ 'ab_subdomain_error' => $error ] );
	}

	public static function handleCheck(): void {
		self::guard( self::ACTION_CHECK );
		self::back( [ 'ab_subdomain_check' => self::checkAndSwitch() ] );
	}

	public static function handleCancel(): void {
		self::guard( self::ACTION_CANCEL );
		self::setPending( '' );
		self::back( [] );
	}

	public static function handleReset(): void {
		self::guard( self::ACTION_RESET );
		self::switchTo( '' );
		self::back( [ 'ab_subdomain' => 'reset' ] );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( SettingsHub::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'tomatio' ), 403 );
		}

		check_admin_referer( $action );
	}

	/**
	 * @param array<string, string> $args
	 */
	private static function back( array $args ): void {
		wp_safe_redirect( SettingsHub::url( SettingsHub::TAB_SHARING, $args ) . '#ab-own-subdomain' );
		exit;
	}

	private static function bareHost( string $host ): string {
		$host = strtolower( trim( $host ) );

		return (string) preg_replace( '/:\d+$/', '', $host );
	}
}
