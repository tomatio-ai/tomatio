<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Core\AuthorizedBy;
use AgentBrain\Modules\OAuth\RoleSwitching;

/**
 * #1168: who stood behind an item waiting for review when it was created.
 * A memory row carries "via" (the person whose connection wrote it); a
 * handoff or review-item post has no such column, so the same person is
 * stamped on the post as meta at creation. Author-is-not-reviewer
 * (ReviewAbilities) reads it, so an item created on a connection cannot be
 * decided on that connection after it switches agents, even when the item
 * has no source memories.
 */
final class CreatorStamp {

	public const META = 'ab_created_via';

	/**
	 * Keeps the stamp out of reach of the custom-fields box and REST meta
	 * (#1168): author-is-not-reviewer trusts it, so a person who can edit the
	 * post must not be able to move it. update_post_meta() in stamp() checks
	 * no capability, so the stamp still writes.
	 */
	public static function register(): void {
		add_filter('is_protected_meta', [self::class, 'protect'], 10, 2);
		add_action('init', [self::class, 'registerMeta'], 21);
	}

	/**
	 * @param bool   $protected Whether the key is already protected.
	 * @param string $key       The meta key.
	 */
	public static function protect($protected, $key): bool {
		return $key === self::META ? true : (bool) $protected;
	}

	/** Registered for every post type, never in REST, never writable through map_meta_cap. */
	public static function registerMeta(): void {
		register_meta('post', self::META, [
			'type'          => 'integer',
			'single'        => true,
			'show_in_rest'  => false,
			'auth_callback' => static fn (): bool => false,
		]);
	}

	/**
	 * The person behind the current connection: the role-switching person,
	 * else the person whose writes are routed, else the person an agent token
	 * is authorized by. 0 when the request is nobody's connection (an agent's
	 * own token, a cron job).
	 */
	public static function currentPerson(): int {
		if (RoleSwitching::person() > 0) {
			return RoleSwitching::person();
		}

		return RoleSwitching::writer() > 0 ? RoleSwitching::writer() : AuthorizedBy::id();
	}

	/**
	 * Stamps the current person on $postId, replacing an earlier stamp: a
	 * later assemble by someone else rewrites the text, so the stamp follows
	 * the new author. A no-op when there is no person (an agent's own token,
	 * cron), which keeps the existing stamp: a request with nobody behind it
	 * never clears one.
	 */
	public static function stamp(int $postId): void {
		$person = self::currentPerson();
		if ($postId > 0 && $person > 0) {
			update_post_meta($postId, self::META, $person);
		}
	}

	/** The person stamped on $postId, or 0. */
	public static function of(int $postId): int {
		return (int) get_post_meta($postId, self::META, true);
	}
}
