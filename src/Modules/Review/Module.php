<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Core\ModuleInterface;

/**
 * Review module (REV-1..REV-3): proposed-knowledge lifecycle, curator
 * approve/edit/reject queue, outcome memory write-back, and the
 * server-side publish guard for the `public-grant` term.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'review';
	}

	public function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('init', [PostStatuses::class, 'register']);
		add_action('init', [GrantTaxonomy::class, 'register']);
		add_action('init', [HandoffCpt::class, 'register']);

		add_action('tomatio/fact_extracted', [ProposalService::class, 'onFactExtracted'], 10, 2);

		// Multi-agent authoring pipeline + handoff fixes.
		HandoffCapabilities::register();
		HandoffQueue::register();
		AgentProposalQueue::register();
		MemoryProposalQueue::register();
		AssembledArtifactListener::register();

		// REV-3: block any publish transition for ab_* / compiled posts that
		// lack the public-grant term + a curator actor. wp_insert_post_data
		// fires for both wp_insert_post() and wp_update_post() (including
		// REST, since ab_* post types have no built-in REST support but a
		// direct wp_update_post() call still routes through this filter).
		add_filter('wp_insert_post_data', [PublishGuard::class, 'filterInsertData'], 10, 2);
		// Defense-in-depth: if a status ever slips through to 'publish' via
		// a path that bypasses wp_insert_post_data (e.g. a raw $wpdb write
		// from other code), revert it and raise an admin-visible signal.
		add_action('transition_post_status', [PublishGuard::class, 'onTransition'], 10, 3);

		ReviewQueuePage::register();
		ReviewedByColumn::register();
		Pins::register();
		Lifecycle::register();

		// #658: the queue runs itself: expiry, Settings > Review, and
		// review in chat for curators.
		Expiry::register();
		SettingsTab::register();
		// #958: open items go to the person's TíoDay instead of expiring.
		ReviewReminder::register();
		add_action('wp_abilities_api_init', [ReviewAbilities::class, 'register']);
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			// Unit F: bounds any legacy REV-2 outcome memory rows written
			// before the B2 cap existed (raw block markup, up to tens of KB,
			// e.g. "Your proposal was approved as-is: <!-- wp:paragraph -->
			// ..."). Runs once per install via Core\Migrations (Plugin::boot()
			// on every version bump, Plugin::activate() on
			// activation/upgrade) -- idempotent, so re-running it (e.g. a
			// fresh activate() in tests) is always safe. Writes exactly one
			// ab_audit record summarising the run (counts only, never row
			// content, per the audit contract).
			1 => static function (): void {
				$counts = ReviewActions::migrateLegacyOutcomeMemories();

				if (function_exists('do_action')) {
					\AgentBrain\Core\Hooks::action('audit', [
						'actor_id'    => 0,
						'event'       => 'review_outcome_memory_migration',
						'status'      => 'ok',
						'object_type' => 'ab_memory',
						'object_id'   => '',
						'meta'        => $counts,
					]);
				}
			},
			// #658: the daily job that closes proposals and handoffs nobody
			// touched within the expiry period, on brains that were already
			// active before it existed.
			2 => static function (): void {
				Expiry::schedule();
			},
			// #792: the doctrines every brain ships with ("Where saves go"),
			// on new brains at activation and existing ones after the upgrade.
			3 => static function (): void {
				Doctrines::migrate();
			},
			// #958: nothing closes on its own any more (the expiry setting
			// is gone, so a stored value would keep closing items with no
			// way to change it), open items are reminded in TíoDay, and
			// memories held only for their own person's review (before
			// #879, or because the person's other Tíos read the source
			// scope) are released.
			4 => static function (): void {
				delete_option(Expiry::OPTION_DAYS);
				ReviewReminder::schedule();
				\AgentBrain\Modules\Provenance\ScopeGuard::releaseSelfReviewHolds();
				ReviewReminder::queue();
			},
		];
	}

	public function activate(): void {
		GrantTaxonomy::register();
		PostStatuses::register();
		HandoffCpt::register();
		GrantTaxonomy::ensureTerm();
		Expiry::schedule();
		ReviewReminder::schedule();
	}

	public function deactivate(): void {
		// Post statuses/taxonomy/CPT registrations are in-memory only; the
		// public-grant term and any content created is left in place.
		Expiry::unschedule();
		ReviewReminder::unschedule();
	}
}
