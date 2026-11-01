<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\Artifacts\Lease;
use AgentBrain\Modules\Artifacts\Preview;

/**
 * Settings -> Sharing tab: preview host, kill switch, approval, IP binding,
 * mint rate limit and trusted proxies. Since #751 one row per setting, in
 * four cards, with one Save bar for the whole form.
 */
final class SettingsTab {

	public const ACTION      = 'ab_save_sharing';
	public const ACTION_TEST = 'ab_test_preview_host';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handleSave' ] );
		add_action( 'admin_post_' . self::ACTION_TEST, [ self::class, 'handleTest' ] );
	}

	public static function renderBody(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flags.
		$error = isset( $_GET['ab_sharing_error'] ) ? sanitize_key( wp_unslash( (string) $_GET['ab_sharing_error'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flags.
		$saved = isset( $_GET['ab_sharing_saved'] );

		if ( $saved ) {
			echo '<div class="notice notice-success inline" role="status"><p>' . esc_html__( 'Sharing settings saved.', 'agent-brain' ) . '</p></div>';
		}
		if ( $error !== '' ) {
			$messages = [
				'format'              => __( 'The preview host must be a bare hostname such as preview.example.com (no scheme, port or path).', 'agent-brain' ),
				'members_roles'       => __( 'Choose at least one role for the members address: with none checked, nobody could ever open it.', 'agent-brain' ),
				'embed_origins'       => __( 'Embed allowed origins must be https origins such as https://app.example.com, one per line (no wildcards, paths or http).', 'agent-brain' ),
				'script_hosts'        => __( 'Script hosts must be bare hostnames such as cdn.example.com, separated by spaces, commas or new lines (no scheme, port, path or wildcard).', 'agent-brain' ),
				'lease_minutes'       => sprintf(
					/* translators: %d: maximum lease length in minutes. */
					__( 'The section lease length must be a whole number of minutes from 1 to %d.', 'agent-brain' ),
					Lease::MAX_LEASE_MINUTES
				),
				'same_as_site'        => __( 'The preview host must differ from this site\'s host: when it is empty, share pages are already served from the site address itself (same-address mode), so the preview host is the separate-hostname option.', 'agent-brain' ),
				'preview_unreachable' => __( 'This preview host does not reach this site: point its DNS or proxy at this site.', 'agent-brain' ),
			];
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html( $messages[ $error ] ?? __( 'Invalid value.', 'agent-brain' ) ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag.
		$warn = isset( $_GET['ab_sharing_warn'] ) ? sanitize_key( wp_unslash( (string) $_GET['ab_sharing_warn'] ) ) : '';
		if ( $warn === 'preview_unverified' ) {
			echo '<div class="notice notice-warning inline" role="status"><p>' . esc_html__( 'The preview host was saved but could not be verified: outbound HTTPS from this server is blocked. Share links stay allowed, but test the preview host once outbound HTTPS works, to confirm it reaches this site.', 'agent-brain' ) . '</p></div>';
		}

		echo '<section class="ab-ui-card"><h3 class="ab-ui-kicker">' . esc_html__( 'Share address', 'agent-brain' ) . '</h3>';
		self::renderEffectiveHost();
		self::renderChecklist();
		echo '</section>';

		$proxies = Config::trustedProxies();
		$hosts   = Interactive::scriptHosts();
		$origins = EmbedSettings::allowedOrigins();
		$lease   = Lease::leaseMinutes();
		$rate    = Config::mintRatePerHour();
		$host    = Preview::configuredHost();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo '<section class="ab-ui-card"><h3 class="ab-ui-kicker">' . esc_html__( 'Share links', 'agent-brain' ) . '</h3>';
		echo Ui::setting(
			__( 'Preview host', 'agent-brain' ),
			$host !== '' ? '<code>' . esc_html( $host ) . '</code>' : esc_html__( 'None: share pages use this site\'s own address.', 'agent-brain' ),
			'<input type="text" id="ab_preview_host" name="ab_preview_host" class="regular-text" value="' . esc_attr( $host ) . '" placeholder="preview.example.com" />',
			'ab_preview_host',
			'<p>' . esc_html__( 'Optional. A dedicated hostname pointing at this WordPress install, different from the site host, for extra isolation. When set (and verified to reach this site) it takes precedence and share links are served only there. Leave empty to serve share pages from this site\'s own address (default; works with no DNS or extra settings).', 'agent-brain' ) . '</p>'
		);
		echo self::checkbox( Config::OPT_DISABLED, Config::disabled(), __( 'Disable all sharing (kill switch)', 'agent-brain' ), __( 'On: no new links can be made and every existing link returns 404.', 'agent-brain' ), __( 'Off: sharing works.', 'agent-brain' ), '' );
		echo self::checkbox( Config::OPT_REQUIRE_APPROVAL, Config::requireApproval(), __( 'Require curator approval for agent-minted share links', 'agent-brain' ), __( 'On: links a lead agent makes stay inactive until a curator approves them.', 'agent-brain' ), __( 'Off: links a lead agent makes work at once.', 'agent-brain' ), '' );
		echo Ui::setting(
			__( 'Mint limit per hour', 'agent-brain' ),
			esc_html( sprintf(
				/* translators: %d: number of links */
				_n( '%d link per person or agent per hour.', '%d links per person or agent per hour.', $rate, 'agent-brain' ),
				$rate
			) ),
			'<input type="number" class="small-text" min="1" id="ab_share_mint_rate_per_hour" name="ab_share_mint_rate_per_hour" value="' . esc_attr( (string) $rate ) . '" />',
			'ab_share_mint_rate_per_hour'
		);
		echo '</section>';

		$diag   = Config::ipDiagnostic();
		$ipMode = Config::defaultIpMode();
		$select = '<select id="ab_share_default_ip_mode" name="ab_share_default_ip_mode">';
		foreach ( Policy::IP_MODES as $mode ) {
			$select .= '<option value="' . esc_attr( $mode ) . '"' . selected( $ipMode, $mode, false ) . '>' . esc_html( $mode ) . '</option>';
		}
		$select .= '</select>';
		$modeNow = [
			'off'        => __( 'off: links are not bound to an IP.', 'agent-brain' ),
			'first_open' => __( 'first_open: a link is bound to the first opener\'s IP.', 'agent-brain' ),
			'fixed'      => __( 'fixed: a link is bound to the client that made it.', 'agent-brain' ),
		];

		echo '<section class="ab-ui-card"><h3 class="ab-ui-kicker">' . esc_html__( 'IP binding', 'agent-brain' ) . '</h3>';
		echo self::checkbox( Config::OPT_ENFORCE_IP, Config::enforceIpBinding(), __( 'Always bind share links to the first opener\'s IP', 'agent-brain' ), __( 'On: every link is bound at least to the first opener\'s IP.', 'agent-brain' ), __( 'Off: each link keeps the binding it was made with.', 'agent-brain' ), '' );
		echo Ui::setting(
			__( 'Default IP binding for new links', 'agent-brain' ),
			esc_html( $modeNow[ $ipMode ] ?? $ipMode ),
			$select,
			'ab_share_default_ip_mode',
			'<p>' . esc_html__( 'Applied when a link is created without an explicit IP binding (artifact-share-create without ip_bind, and the admin panel preselection). off: no binding. first_open: bound to the first opener. fixed: bound to the minting client. IPv6 clients are compared by /64 prefix so privacy-address rotation does not break first_open links.', 'agent-brain' ) . '</p>'
		);
		echo Ui::setting(
			__( 'Trusted proxies', 'agent-brain' ),
			$proxies === []
				? esc_html__( 'None: forwarded-IP headers are never trusted.', 'agent-brain' )
				: esc_html( sprintf(
					/* translators: %d: number of address ranges */
					_n( '%d range trusted.', '%d ranges trusted.', count( $proxies ), 'agent-brain' ),
					count( $proxies )
				) ),
			'<textarea id="ab_trusted_proxies" name="ab_trusted_proxies" rows="4" class="large-text code">' . esc_textarea( implode( "\n", $proxies ) ) . '</textarea>',
			'ab_trusted_proxies',
			'<p>' . esc_html__( 'One CIDR per line. Forwarded-IP headers (CF-Connecting-IP, X-Forwarded-For) are trusted only when the connection comes from one of these. Empty means never trusted.', 'agent-brain' ) . '</p>',
			true
		);
		echo Ui::setting(
			__( 'Your IP as seen by the server', 'agent-brain' ),
			'<code>' . esc_html( $diag['ip'] !== '' ? $diag['ip'] : __( 'unknown', 'agent-brain' ) ) . '</code> '
				/* translators: %s: header or variable the IP was read from. */
				. esc_html( sprintf( __( '(source: %s)', 'agent-brain' ), $diag['source'] ) ),
			'',
			'',
			'<p>' . esc_html__( 'Behind Cloudflare or a reverse proxy (mod_remoteip) REMOTE_ADDR is the proxy, not the visitor, and every link would bind to the proxy. If the address above is not yours, add your proxy ranges to Trusted proxies so CF-Connecting-IP / X-Forwarded-For is honoured, or configure mod_remoteip on the web server.', 'agent-brain' ) . '</p>'
		);
		echo '</section>';

		$allowedRoles = MembersAddress::allowedRoles();
		$roleLabels   = [
			MembersAddress::ROLE_MEMBER  => __( 'Any member who can read the artifact (section owners, reviewers)', 'agent-brain' ),
			MembersAddress::ROLE_CURATOR => __( 'Curators', 'agent-brain' ),
			MembersAddress::ROLE_MANAGER => __( 'People holding ab_manage', 'agent-brain' ),
			MembersAddress::ROLE_LEAD    => __( 'The artifact\'s lead', 'agent-brain' ),
		];
		$boxes = '<span class="ab-ui-set-checks"><input type="hidden" name="ab_members_address_roles" value="" />';
		foreach ( $roleLabels as $role => $label ) {
			$boxes .= '<label><input type="checkbox" name="ab_members_address_roles[]" value="' . esc_attr( $role ) . '"' . ( in_array( $role, $allowedRoles, true ) ? ' checked="checked"' : '' ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		$boxes .= '</span>';
		$rolesNow = array_values( array_intersect_key( $roleLabels, array_flip( $allowedRoles ) ) );

		echo '<section class="ab-ui-card"><h3 class="ab-ui-kicker">' . esc_html__( 'Members address, embeds and interactive pages', 'agent-brain' ) . '</h3>';
		echo Ui::setting(
			__( 'Members address: who may open it', 'agent-brain' ),
			count( $allowedRoles ) === count( MembersAddress::ROLES )
				? esc_html__( 'Everyone who can read the artifact (the default).', 'agent-brain' )
				/* translators: %s: list of roles */
				: esc_html( sprintf( __( 'Only: %s.', 'agent-brain' ), implode( '; ', $rolesNow ) ) ),
			$boxes,
			'',
			'<p>' . esc_html__( 'On top of the artifact read check: the members address (/a/{artifact_id}) signs a viewer in and then admits only the checked roles. Default: all roles, i.e. the read check alone decides. Older versions (?v=) are always restricted to the lead, curators and managers, whatever is checked here.', 'agent-brain' ) . '</p>',
			true
		);
		echo EmbedSettings::row( $origins );
		echo self::checkbox( Interactive::OPT_DISABLED, Interactive::killSwitchOn(), __( 'Disable interactive mode (kill switch)', 'agent-brain' ), __( 'On: every interactive artifact goes back to the static policy; no scripts run in share links or embeds.', 'agent-brain' ), __( 'Off: interactive artifacts run their scripts, sandboxed.', 'agent-brain' ), '' );
		echo Ui::setting(
			__( 'Interactive script hosts', 'agent-brain' ),
			$hosts === []
				? esc_html__( 'None: inline scripts only.', 'agent-brain' )
				: '<code>' . esc_html( implode( ', ', $hosts ) ) . '</code>',
			'<textarea id="ab_interactive_script_hosts" name="' . esc_attr( Interactive::OPT_SCRIPT_HOSTS ) . '" rows="3" class="large-text code" placeholder="cdn.example.com">' . esc_textarea( implode( "\n", $hosts ) ) . '</textarea>',
			'ab_interactive_script_hosts',
			'<p>' . esc_html__( 'One bare hostname per line. Interactive artifacts may load a script from https on these hosts only. Empty means inline scripts only.', 'agent-brain' ) . '</p>',
			true
		);
		echo '</section>';

		echo '<section class="ab-ui-card"><h3 class="ab-ui-kicker">' . esc_html__( 'Writing documents', 'agent-brain' ) . '</h3>';
		echo self::checkbox( Config::OPT_AGENT_CREATE, Config::agentCreateEnabled(), __( 'Allow agents to create artifacts in their own write scopes', 'agent-brain' ), __( 'On: an agent can start an artifact itself, as its lead, in a scope it can write.', 'agent-brain' ), __( 'Off: agents ask a curator to create artifacts.', 'agent-brain' ), __( 'Off by default. When on, an agent can start an artifact itself (becoming its lead) in a scope it can write, instead of asking a curator. Curators can still create artifacts for any lead.', 'agent-brain' ) );
		echo Ui::setting(
			__( 'Section lease length (minutes)', 'agent-brain' ),
			esc_html( sprintf(
				/* translators: %d: number of minutes */
				_n( '%d minute: how long an agent keeps a claimed section before someone else can take it over.', '%d minutes: how long an agent keeps a claimed section before someone else can take it over.', $lease, 'agent-brain' ),
				$lease
			) ),
			'<input type="number" class="small-text" min="1" max="' . esc_attr( (string) Lease::MAX_LEASE_MINUTES ) . '" id="ab_lease_minutes" name="' . esc_attr( Lease::OPTION_LEASE_MINUTES ) . '" value="' . esc_attr( (string) $lease ) . '" />',
			'ab_lease_minutes'
		);
		echo '</section>';

		echo Ui::saveBar( 'ab_sharing_submit' );
		// phpcs:enable
		echo '</form>';
	}

	/**
	 * #286: the effective share address (preview host when configured, else
	 * this site's own address), where the value came from, and — in
	 * preview-host mode — the last reachability check and when it ran.
	 */
	private static function renderEffectiveHost(): void {
		$effective = Preview::effectiveHost();

		echo '<p><code>' . esc_html( $effective['host'] ) . '</code> ';
		/* translators: %s: source of the effective share address. */
		echo esc_html( sprintf( __( '(effective share address, from: %s)', 'agent-brain' ), $effective['source'] ) ) . '</p>';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::callout() escapes what it emits.
		if ( $effective['mode'] === Preview::MODE_SITE_ADDRESS ) {
			echo Ui::callout(
				'ok',
				__( 'Share pages are served from this site\'s own address.', 'agent-brain' ),
				esc_html__( 'No preview host is configured, so this is same-address mode. It works with no DNS, no extra certificate and no other settings. Add a preview host below if you want the extra isolation of a separate hostname; once it is set and verified, it takes precedence.', 'agent-brain' )
			);

			return;
		}

		$check     = HostReachability::check();
		$result    = $check['result'];
		$checkedAt = (int) $check['checked_at'];
		$when      = human_time_diff( $checkedAt, time() );

		echo Ui::callout(
			$result === PreviewCheck::OK ? 'ok' : ( $result === PreviewCheck::UNKNOWN ? 'warn' : 'bad' ),
			PreviewCheck::message( $result ),
			'<em>' . esc_html(
				sprintf(
					/* translators: %s: how long ago the reachability check ran. */
					__( 'Last checked %s ago.', 'agent-brain' ),
					$when
				)
			) . '</em>'
		);
		// phpcs:enable
	}

	/**
	 * Setup guide for the OPTIONAL preview host, with a test button (separate
	 * form, so it never nests inside the settings form). Same-address mode
	 * needs none of this; the checklist is only the path to extra isolation.
	 */
	private static function renderChecklist(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag.
		$tested = isset( $_GET['ab_preview_test'] ) ? sanitize_key( wp_unslash( (string) $_GET['ab_preview_test'] ) ) : '';
		if ( $tested !== '' ) {
			$ok = $tested === PreviewCheck::OK;
			echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . ' inline" role="' . ( $ok ? 'status' : 'alert' ) . '"><p>' . esc_html( PreviewCheck::message( $tested ) ) . '</p></div>';
		}

		$host = Preview::configuredHost();
		echo '<details class="ab-ui-set-more"' . ( $tested !== '' ? ' open' : '' ) . '><summary>' . esc_html__( 'Optional: set up a preview host', 'agent-brain' ) . '</summary><div>';
		if ( $host === '' ) {
			echo '<p>' . esc_html__( 'Sharing already works from this site\'s own address. A preview host is extra isolation only: shared documents then open on a separate hostname instead of the site\'s. Do these four steps once:', 'agent-brain' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'The preview host is set. To change it, point the new hostname at this install first:', 'agent-brain' ) . '</p>';
		}
		echo '<ol>';
		echo '<li>' . esc_html__( 'Pick a hostname under a domain you control that is not this site\'s host, for example preview.example.com, and enter it below.', 'agent-brain' ) . '</li>';
		echo '<li>' . esc_html__( 'DNS: add an A (or AAAA, or CNAME) record for that hostname that points to the same server as this site.', 'agent-brain' ) . '</li>';
		echo '<li>' . esc_html__( 'Certificate: make sure the web server has a valid https certificate for that hostname (a wildcard or an extra name on the site certificate works), and that it sends the hostname to this WordPress install.', 'agent-brain' ) . '</li>';
		echo '<li>' . esc_html__( 'Save, then press Test preview host. It checks DNS, the certificate and that the host reaches this site.', 'agent-brain' ) . '</li>';
		echo '</ol>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_TEST );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_TEST ) . '" />';
		submit_button( __( 'Test preview host', 'agent-brain' ), 'secondary', 'ab_test_preview_submit', false, $host === '' ? [ 'disabled' => 'disabled' ] : [] );
		echo '</form></div></details>';
	}

	public static function handleTest(): void {
		if ( ! current_user_can( SettingsHub::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agent-brain' ), 403 );
		}

		check_admin_referer( self::ACTION_TEST );

		wp_safe_redirect( SettingsHub::url( 'sharing', [ 'ab_preview_test' => PreviewCheck::run() ] ) );
		exit;
	}

	/**
	 * One on/off row: the setting, what on and off mean, and a switch.
	 */
	private static function checkbox( string $name, bool $checked, string $label, string $onText, string $offText, string $help ): string {
		return Ui::setting( $label, esc_html( $checked ? $onText : $offText ), Ui::toggle( $name, $name, $checked ), $name, $help !== '' ? '<p>' . esc_html( $help ) . '</p>' : '' );
	}

	public static function handleSave(): void {
		if ( ! current_user_can( SettingsHub::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agent-brain' ), 403 );
		}

		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$error = self::save( wp_unslash( $_POST ) );

		if ( $error === 'preview_unverified' ) {
			wp_safe_redirect( SettingsHub::url( 'sharing', [ 'ab_sharing_saved' => '1', 'ab_sharing_warn' => 'preview_unverified' ] ) );
			exit;
		}

		wp_safe_redirect( SettingsHub::url( 'sharing', $error !== '' ? [ 'ab_sharing_error' => $error ] : [ 'ab_sharing_saved' => '1' ] ) );
		exit;
	}

	/**
	 * Atomic save: every posted value is validated first and nothing is written
	 * unless all of them are valid. Options that would only persist their
	 * default are removed instead of written, so an untouched form leaves no
	 * rows behind.
	 *
	 * @param array<string, mixed> $post Unslashed form data.
	 * @return string Error key ('' on success).
	 */
	public static function save( array $post ): string {
		$str = static fn ( string $key ): string => isset( $post[ $key ] ) && is_string( $post[ $key ] ) ? $post[ $key ] : '';

		// 1. Validate everything.
		$host    = sanitize_text_field( $str( 'ab_preview_host' ) );
		$validHost = '';
		if ( trim( $host ) !== '' ) {
			$validHost = Policy::validatePreviewHost( $host, self::siteHosts() );
			if ( strpos( $validHost, 'error:' ) === 0 ) {
				return substr( $validHost, 6 );
			}
		}

		$embed = EmbedPolicy::parseOrigins( sanitize_textarea_field( $str( EmbedSettings::OPTION ) ) );
		if ( $embed['invalid'] !== [] ) {
			return 'embed_origins';
		}

		$mode    = Policy::normalizeIpMode( sanitize_key( $str( Config::OPT_DEFAULT_IP_MODE ) !== '' ? $str( Config::OPT_DEFAULT_IP_MODE ) : Policy::IP_OFF ) );
		$rate    = absint( $str( Config::OPT_MINT_RATE ) );
		$rate    = $rate > 0 ? $rate : Config::DEFAULT_MINT_RATE;
		$proxies = ClientIp::parseTrusted( sanitize_textarea_field( $str( Config::OPT_TRUSTED_PROXIES ) ) );

		$hosts = Interactive::parseHosts( preg_split( '/[\s,]+/', sanitize_textarea_field( $str( Interactive::OPT_SCRIPT_HOSTS ) ) ) ?: [] );
		if ( $hosts['invalid'] !== [] ) {
			return 'script_hosts';
		}

		$leaseRaw = trim( $str( Lease::OPTION_LEASE_MINUTES ) );
		$lease    = Lease::DEFAULT_LEASE_MINUTES;
		if ( $leaseRaw !== '' ) {
			$lease = ctype_digit( $leaseRaw ) ? (int) $leaseRaw : 0;
			if ( $lease < 1 || $lease > Lease::MAX_LEASE_MINUTES ) {
				return 'lease_minutes';
			}
		}

		// #219: the members-address role setting. A missing field means a
		// programmatic caller that does not mention the setting: it keeps
		// the default (all roles, persisted as nothing). The form posts a
		// hidden empty value so "no box checked" arrives as '' and is
		// refused — with none checked the address would admit nobody.
		if ( ! isset( $post['ab_members_address_roles'] ) ) {
			$roles = MembersAddress::ROLES;
		} else {
			$posted = $post['ab_members_address_roles'];
			$posted = is_array( $posted ) ? $posted : ( $posted === '' ? [] : [ $posted ] );
			$roles  = array_values( array_intersect( array_map( 'sanitize_key', $posted ), MembersAddress::ROLES ) );
			if ( $roles === [] ) {
				return 'members_roles';
			}
		}

		// #285: prove a newly entered preview host actually reaches this
		// install before saving it. A finished check that did not verify is
		// refused; a check that cannot run at all (outbound HTTP blocked) is
		// saved unverified, with a warning on the redirect.
		$unverified = false;
		if ( $validHost !== '' ) {
			$check = PreviewCheck::run( $validHost );
			if ( $check !== PreviewCheck::OK && $check !== PreviewCheck::UNKNOWN ) {
				return 'preview_unreachable';
			}
			$unverified = $check === PreviewCheck::UNKNOWN;
		}

		$before = [
			'disabled' => Interactive::killSwitchOn(),
			'hosts'    => Interactive::scriptHosts(),
			'lease'    => Lease::leaseMinutes(),
		];

		// 2. Write.
		if ( $validHost === '' ) {
			delete_option( Preview::OPTION_HOST );
		} else {
			update_option( Preview::OPTION_HOST, $validHost );
		}

		foreach ( [ Config::OPT_DISABLED, Config::OPT_REQUIRE_APPROVAL, Config::OPT_ENFORCE_IP, Config::OPT_AGENT_CREATE ] as $flag ) {
			self::store( $flag, isset( $post[ $flag ] ) && $post[ $flag ] === '1', false );
		}

		self::store( Config::OPT_DEFAULT_IP_MODE, $mode, Policy::IP_OFF );
		self::store( Config::OPT_MINT_RATE, $rate, Config::DEFAULT_MINT_RATE );
		self::store( Config::OPT_TRUSTED_PROXIES, $proxies, [] );
		EmbedSettings::saveRaw( implode( "\n", $embed['origins'] ) );

		$disabled = isset( $post[ Interactive::OPT_DISABLED ] ) && $post[ Interactive::OPT_DISABLED ] === '1';
		self::store( Interactive::OPT_DISABLED, $disabled, false );
		self::store( Interactive::OPT_SCRIPT_HOSTS, implode( "\n", $hosts['hosts'] ), '' );
		self::store( Lease::OPTION_LEASE_MINUTES, $lease, Lease::DEFAULT_LEASE_MINUTES );
		self::store( MembersAddress::OPTION_ROLES, $roles, MembersAddress::ROLES );

		self::audit( 'interactive_kill_switch_changed', $before['disabled'] !== $disabled, [ 'disabled' => $disabled ] );
		self::audit( 'interactive_script_hosts_changed', $before['hosts'] !== $hosts['hosts'], [ 'hosts' => $hosts['hosts'] ] );
		self::audit( 'lease_minutes_changed', $before['lease'] !== $lease, [ 'minutes' => $lease ] );

		// The preview host may have changed: drop the cached reachability
		// record so the next mint re-probes instead of trusting a stale check.
		HostReachability::invalidate();

		return $unverified ? 'preview_unverified' : '';
	}

	/**
	 * One audit entry per changed setting (same shape as the per-artifact toggle).
	 *
	 * @param array<string, mixed> $meta
	 */
	private static function audit( string $event, bool $changed, array $meta ): void {
		if ( ! $changed ) {
			return;
		}

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => get_current_user_id(),
				'event'       => $event,
				'status'      => 'ok',
				'object_type' => 'setting',
				'object_id'   => $event,
				'meta'        => $meta,
			]
		);
	}

	/**
	 * Persist $value, or drop the option when it equals the default.
	 *
	 * @param mixed $value
	 * @param mixed $default
	 */
	private static function store( string $name, $value, $default ): void {
		if ( $value === $default ) {
			delete_option( $name );

			return;
		}

		update_option( $name, $value );
	}

	/**
	 * @return string[]
	 */
	private static function siteHosts(): array {
		return array_filter(
			[
				(string) wp_parse_url( home_url(), PHP_URL_HOST ),
				(string) wp_parse_url( site_url(), PHP_URL_HOST ),
			]
		);
	}
}
