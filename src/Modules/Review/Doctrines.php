<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Identity\DefaultScope;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Recall\KnowledgeSearch;

/**
 * #792: doctrines every brain ships with, as approved, pinned knowledge
 * (so every briefing of a scope reader carries them whole, see Pins).
 *
 * Seeded by a Review migration, so a new brain gets them at activation and
 * an existing one on its first boot after the upgrade. The post carries
 * META with the doctrine's key, and the option records each key once
 * handled (the post id, or 0 when an item of the same title was already
 * there), so a doctrine a curator deleted never comes back.
 *
 * Scope: the team scope a brain starts with (Identity\DefaultScope), which
 * administrators and curators always hold and every agent reads while it is
 * the only scope; otherwise the scope most people and agents read, so a
 * person's private scope is never picked on a brain without a team scope.
 * Agents that cannot read it still get the rule from the briefing
 * orientation and the memory-write description.
 *
 * A migration needs an empty brain: an unchanged shipped doctrine does not
 * count against it, is removed before the restore and seeded again after
 * it unless the archive brought one.
 *
 * The tomatio/ship_doctrines filter turns seeding off (the integration
 * suite does, so other tests start without knowledge).
 */
final class Doctrines {

	public const META        = 'ab_doctrine';
	public const OPTION      = 'ab_shipped_doctrines';
	public const AUDIT_EVENT = 'doctrine.seeded';

	public const WHERE_SAVES_GO = 'where-saves-go';

	public static function enabled(): bool {
		return (bool) \AgentBrain\Core\Hooks::filter('ship_doctrines', true);
	}

	/**
	 * The shipped doctrines: key => [title, text].
	 *
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function all(): array {
		return [
			self::WHERE_SAVES_GO => [
				__('Doctrine: where saves go', 'tomatio'),
				self::whereSavesGo(),
			],
		];
	}

	/** The rule itself; also the line in the orientation (Recall\Orientation). */
	public static function whereSavesGo(): string {
		return __('Where saves go: When a user asks to save, remember or note something in a chat with this brain, write it to the brain (tomatio-memory-write), not to the AI app\'s built-in memory. If more than one scope is writable, use the user\'s private scope unless they say otherwise. Never store tokens, passwords or keys.', 'tomatio');
	}

	/**
	 * #977: project context is saved without being asked, not only when
	 * the user says "remember this" or when work lands. A claude.ai chat
	 * that discussed project matters never reached the brain: it decided
	 * nothing, so "log what lands" did not cover it.
	 * Live text only (the briefing and the memory-write description), not
	 * a seeded doctrine; ServerIdentity::logging() gives each connection
	 * the same rule with its own private scope named.
	 */
	public static function saveAsYouGo(): string {
		return __('Save as you go: whenever a conversation touches this brain\'s work (its projects, people, plans, money, customers or tools), save what someone would want to find later with memory-write, without being asked. That means open questions, ideas, options weighed, plans, agreements and concerns as well as decisions and finished work; say it is still open when nothing was decided. Personal or sensitive matters (pay, money between people, contracts, legal, health) go to a scope only the person can read, never a shared one. Skip small talk and anything unrelated to this brain.', 'tomatio');
	}

	/**
	 * Migration entry point. The scope taxonomy and the team scope are only
	 * ready once init has run (DefaultScope::migrate() waits for it at 20).
	 */
	public static function migrate(): void {
		if (did_action('init') > 0) {
			self::ensure();

			return;
		}

		add_action('init', static function (): void {
			self::ensure();
		}, 30, 0);
	}

	/**
	 * Seeds every shipped doctrine not handled yet. Returns key => post id
	 * for the ones it created.
	 *
	 * @return array<string, int>
	 */
	public static function ensure(): array {
		if (! self::enabled()) {
			return [];
		}

		$handled = self::handled();
		$created = [];
		foreach (self::all() as $key => [$title, $text]) {
			if (array_key_exists($key, $handled)) {
				continue;
			}

			$existing = self::existing($key, $title);
			if ($existing > 0) {
				$handled[$key] = 0;
				continue;
			}

			$scopeId = self::scope();
			if ($scopeId === null) {
				// No scope at all (the team scope is off): nothing to put it
				// in, and not handled, so a later ensure() can still add it.
				continue;
			}

			$postId = self::insert($key, $title, $text, $scopeId);
			if ($postId === 0) {
				continue;
			}

			$handled[$key] = $postId;
			$created[$key] = $postId;

			\AgentBrain\Core\Hooks::action('audit', [
				'actor_id'    => 0,
				'event'       => self::AUDIT_EVENT,
				'status'      => 'ok',
				'object_type' => 'ab_knowledge',
				'object_id'   => (string) $postId,
				'meta'        => ['doctrine' => $key, 'scope_id' => $scopeId],
			]);
		}

		update_option(self::OPTION, $handled, false);

		return $created;
	}

	/**
	 * Shipped doctrines still as shipped (their text unchanged): post id =>
	 * key. These are not the team's content, so they do not keep a brain
	 * from being empty.
	 *
	 * @return array<int, string>
	 */
	public static function untouched(): array {
		global $wpdb;

		$texts = array_map(static fn (array $doctrine): string => $doctrine[1], self::all());

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT p.ID, p.post_content, m.meta_value FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type = %s",
			[self::META, 'ab_knowledge']
		);
		// phpcs:enable

		$out = [];
		foreach ($rows as $row) {
			$key = (string) $row['meta_value'];
			if (isset($texts[$key]) && trim((string) $row['post_content']) === $texts[$key]) {
				$out[(int) $row['ID']] = $key;
			}
		}

		return $out;
	}

	/**
	 * Before a migration writes an archive into this brain: the untouched
	 * shipped doctrines go, and are no longer counted as handled, so
	 * ensure() afterwards adds them back unless the archive brought them.
	 */
	public static function removeForRestore(): void {
		$handled = self::handled();
		foreach (self::untouched() as $postId => $key) {
			wp_delete_post($postId, true);
			unset($handled[$key]);
		}
		update_option(self::OPTION, $handled, false);
	}

	/** @return array<string, int> */
	private static function handled(): array {
		$handled = get_option(self::OPTION, []);

		return is_array($handled) ? $handled : [];
	}

	/** An item (any status but trash) carrying this doctrine's key or title. */
	private static function existing(string $key, string $title): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type IN (%s, %s) AND p.post_status <> %s AND (m.meta_value = %s OR p.post_title = %s)
			LIMIT 1",
			[self::META, 'ab_knowledge', 'ab_procedure', 'trash', $key, $title]
		);
		// phpcs:enable

		return isset($rows[0]) ? (int) $rows[0]['ID'] : 0;
	}

	/** The team scope, else the scope the most users read (lowest id on a tie). */
	private static function scope(): ?int {
		$teamId = DefaultScope::ensure() ?? DefaultScope::id();
		if ($teamId !== null) {
			return $teamId;
		}

		if (! taxonomy_exists(Scopes::TAXONOMY)) {
			return null;
		}

		$ids = array_map('intval', (array) get_terms(['taxonomy' => Scopes::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids', 'orderby' => 'term_id', 'order' => 'ASC']));
		if ($ids === []) {
			return null;
		}

		$readers = array_fill_keys($ids, 0);
		$scopes  = new Scopes();
		foreach (get_users(['meta_key' => Scopes::META_READ_SCOPES, 'fields' => 'ID']) as $userId) {
			foreach ($scopes->readScopeIds((int) $userId) as $scopeId) {
				if (isset($readers[$scopeId])) {
					++$readers[$scopeId];
				}
			}
		}

		$best = $ids[0];
		foreach ($readers as $scopeId => $count) {
			if ($count > $readers[$best]) {
				$best = $scopeId;
			}
		}

		return $best;
	}

	private static function insert(string $key, string $title, string $text, int $scopeId): int {
		$id = wp_insert_post([
			'post_type'    => 'ab_knowledge',
			'post_status'  => KnowledgeSearch::STATUS,
			'post_title'   => $title,
			'post_content' => $text,
			'post_author'  => 0,
		], true);
		if (is_wp_error($id)) {
			return 0;
		}

		$id = (int) $id;
		wp_set_object_terms($id, [$scopeId], Scopes::TAXONOMY, false);
		update_post_meta($id, self::META, $key);
		Pins::set($id, true);

		return $id;
	}
}
