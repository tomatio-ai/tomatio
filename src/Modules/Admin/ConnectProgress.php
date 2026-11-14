<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Audit\Repository as AuditRepository;
use AgentBrain\Modules\OAuth\Audit;

/**
 * #853: how far a person gets toward connecting an AI, as light audit rows
 * (no content, no network addresses), so a brain that never connected can
 * say where its owner stopped:
 *
 *  - connect.page_viewed: TomaTío > Connect an AI opened, once per person
 *    per day, with the AI tab shown;
 *  - connect.url_copied: a copy button on that page, once per person per
 *    kind (COPY_KINDS) per day;
 *  - oauth.signin_asked and oauth.consent_shown come from
 *    AuthorizeController (the authorize address reached signed out, and the
 *    consent screen or its error page).
 *
 * soFar() reads the four back for the "So far" line on Overview's Get
 * started list.
 */
final class ConnectProgress {

	public const EVENT_PAGE_VIEWED   = 'connect.page_viewed';
	public const EVENT_URL_COPIED    = 'connect.url_copied';
	public const EVENT_SIGNIN_ASKED  = 'oauth.signin_asked';
	public const EVENT_CONSENT_SHOWN = 'oauth.consent_shown';

	/** User meta: the day (UTC) and what this person has already recorded that day. */
	public const META_TODAY = 'ab_connect_progress_today';

	public const AJAX_ACTION = 'ab_connect_copied';
	public const NONCE_ACTION = 'ab_connect_copied';

	/** What a copy button on Connect an AI copies (its data-ab-copy-kind). */
	public const COPY_KINDS = ['address', 'roles_address', 'claude_code_command', 'chatgpt_name', 'chatgpt_description'];

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'handleCopied']);
	}

	/**
	 * Records that $userId opened Connect an AI, once a day. True when a
	 * row was written.
	 */
	public static function pageViewed(int $userId, string $ai): bool {
		if ($userId <= 0 || ! self::firstToday($userId, 'page')) {
			return false;
		}

		Audit::emit($userId, 'connect.page_viewed', 'ok', ['ai' => sanitize_key($ai)]);

		return true;
	}

	/**
	 * Records a copy button click, once a day per kind. True when a row
	 * was written; false for an unknown kind or a repeat.
	 */
	public static function copied(int $userId, string $kind): bool {
		if ($userId <= 0 || ! in_array($kind, self::COPY_KINDS, true) || ! self::firstToday($userId, 'copy:' . $kind)) {
			return false;
		}

		Audit::emit($userId, 'connect.url_copied', 'ok', ['what' => $kind]);

		return true;
	}

	/** admin-ajax: the copy button's beacon. */
	public static function handleCopied(): void {
		if (! current_user_can(ConnectPage::CAPABILITY) || ! check_ajax_referer(self::NONCE_ACTION, '_ajax_nonce', false)) {
			wp_send_json_error(null, 403);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$kind = isset($_POST['kind']) ? sanitize_key((string) wp_unslash($_POST['kind'])) : '';

		wp_send_json_success(['recorded' => self::copied((int) get_current_user_id(), $kind)]);
	}

	/**
	 * The latest time each step happened on this brain (any person), for
	 * Overview: page, copied, signin, consent (ok) and consent_error. Only
	 * steps that happened are in the result.
	 *
	 * @return array<string, string> step => UTC "Y-m-d H:i:s"
	 */
	public static function soFar(): array {
		$rows = Db::getResults(
			'SELECT event, status, MAX(ts) AS ts FROM ' . AuditRepository::tableName() . ' WHERE event IN (%s, %s, %s, %s) GROUP BY event, status',
			[self::EVENT_PAGE_VIEWED, self::EVENT_URL_COPIED, self::EVENT_SIGNIN_ASKED, self::EVENT_CONSENT_SHOWN]
		);

		$steps = [
			self::EVENT_PAGE_VIEWED . ':ok'    => 'page',
			self::EVENT_URL_COPIED . ':ok'     => 'copied',
			self::EVENT_SIGNIN_ASKED . ':ok'   => 'signin',
			self::EVENT_CONSENT_SHOWN . ':ok'  => 'consent',
			self::EVENT_CONSENT_SHOWN . ':error' => 'consent_error',
		];

		$out = [];
		foreach ($rows as $row) {
			$step = $steps[(string) $row['event'] . ':' . (string) $row['status']] ?? null;
			if ($step !== null) {
				$out[$step] = (string) $row['ts'];
			}
		}

		// In the order a person goes through them.
		$ordered = [];
		foreach ($steps as $step) {
			if (isset($out[$step])) {
				$ordered[$step] = $out[$step];
			}
		}

		return $ordered;
	}

	/**
	 * "So far: opened Connect an AI (Nov 4) · copied the connector address
	 * (Nov 4) · …", or '' when nothing happened yet.
	 *
	 * @param array<string, string>|null $soFar soFar(), or null to read it.
	 */
	public static function soFarHtml(?array $soFar = null): string {
		$soFar ??= self::soFar();
		if ($soFar === []) {
			return '';
		}

		$labels = [
			'page'          => __('opened Connect an AI', 'tomatio'),
			'copied'        => __('copied the connector details', 'tomatio'),
			'signin'        => __('an AI sent someone to sign in', 'tomatio'),
			'consent'       => __('saw the consent screen', 'tomatio'),
			'consent_error' => __('got an error instead of the consent screen', 'tomatio'),
		];

		$parts = [];
		foreach ($soFar as $step => $ts) {
			$time    = DateFormat::timestamp($ts);
			$parts[] = esc_html($labels[$step]) . ($time === null ? '' : ' <time datetime="' . esc_attr(gmdate('Y-m-d\\TH:i:s\\Z', $time)) . '">(' . esc_html(gmdate('M j', $time)) . ')</time>');
		}

		return '<span class="ab-connect-progress">' . esc_html__('So far:', 'tomatio') . ' ' . implode(' · ', $parts) . '</span>';
	}

	/**
	 * Whether $key is new for $userId today (UTC); marks it seen.
	 */
	private static function firstToday(int $userId, string $key): bool {
		$today = gmdate('Y-m-d');
		$seen  = get_user_meta($userId, self::META_TODAY, true);
		$keys  = is_array($seen) && ($seen['day'] ?? '') === $today && is_array($seen['keys'] ?? null) ? $seen['keys'] : [];

		if (in_array($key, $keys, true)) {
			return false;
		}

		$keys[] = $key;
		update_user_meta($userId, self::META_TODAY, ['day' => $today, 'keys' => $keys]);

		return true;
	}
}
