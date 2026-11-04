<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Provenance;

use AgentBrain\Core\ModuleInterface;

/**
 * Provenance module (PROV-3, #172; PROV-2, #171; PROV-1, #170; PROV-7,
 * #176): outside
 * source records — the small reference records (kind, title, locator, time,
 * hash) an item names as "where this came from outside the brain" — plus
 * the generic item -> outside-source link table, item citations (the brain
 * items an item cites: memory ids, knowledge ids, section versions), the
 * derived-from link table recording which artifact / section versions
 * an assembled, compiled or proposed item was built from, and PROV-7's
 * rules for all of it: the Privacy settings tab (action-context switch,
 * retention period), the daily retention sweep, and the Forget cascade.
 * PROV-4 (#173) adds the surfaces: the wp-admin provenance panel (the
 * curator-facing, unfiltered view of one item) and the provenance block
 * the MCP reads attach to their results.
 *
 * A source record is never a copy of the source: locator + metadata only;
 * a cite and a derived-from link are identifiers and versions only, never
 * content.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'provenance';
	}

	public function register(): void {
		// PROV-7 (#176): the Privacy settings tab (action-context switch and
		// retention period), the daily retention sweep, and the Forget
		// cascade for provenance residue.
		SettingsTab::register();
		Retention::register();
		add_filter( 'agent_brain/forget_collect', [ Forget::class, 'collect' ], 10, 2 );
		add_action( 'agent_brain/forget_delete', [ Forget::class, 'delete' ], 10, 2 );
		add_action( 'agent_brain/forget_propagate', [ Forget::class, 'propagate' ], 10, 2 );
		add_filter( 'agent_brain/forget_propagation_result', [ Forget::class, 'propagationResult' ], 10, 2 );

		// PROV-4 (#173): the wp-admin provenance panel — the curator-facing,
		// unfiltered view of one item's sources, cites, derived-from,
		// approvals, action context and blame.
		Panel::register();

		// PROV-5 (#174): blame. Revision stamping hooks wp_insert_post (which
		// fires for revisions too) so EVERY content write path is covered
		// uniformly; the MCP tools register on the abilities API; the
		// wp-admin blame/history boxes register their own meta boxes.
		add_action( 'wp_insert_post', [ RevisionBlame::class, 'stampRevision' ], 10, 3 );
		add_action( 'wp_abilities_api_init', [ BlameAbilities::class, 'register' ] );
		BlameBox::register();
	}

	public function activate(): void {
		Retention::schedule();
	}

	public function deactivate(): void {
		Retention::unschedule();
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			1 => static function (): void {
				global $wpdb;

				$table          = OutsideSources::sourcesTable();
				$charsetCollate = $wpdb->get_charset_collate();

				$sql = "CREATE TABLE {$table} (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					kind VARCHAR(16) NOT NULL,
					title VARCHAR(255) NOT NULL,
					locator TEXT NOT NULL,
					publisher VARCHAR(255) NULL,
					retrieved_at DATETIME NULL,
					hash CHAR(64) NULL,
					agent_id BIGINT UNSIGNED NOT NULL,
					created DATETIME NOT NULL,
					PRIMARY KEY  (id),
					KEY agent_kind (agent_id, kind)
				) ENGINE=InnoDB {$charsetCollate};";

				self::dbDelta( $sql );
			},
			2 => static function (): void {
				global $wpdb;

				$table          = OutsideSources::linksTable();
				$charsetCollate = $wpdb->get_charset_collate();

				$sql = "CREATE TABLE {$table} (
					item_kind VARCHAR(16) NOT NULL,
					item_id BIGINT UNSIGNED NOT NULL,
					source_id BIGINT UNSIGNED NOT NULL,
					position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
					PRIMARY KEY  (item_kind, item_id, source_id),
					KEY source_id (source_id)
				) ENGINE=InnoDB {$charsetCollate};";

				self::dbDelta( $sql );
			},
			// PROV-2 (#171): citations — links from a written item (memory
			// row, section version, proposed knowledge) to the brain items
			// its author used. Identifiers and versions only, never content.
			3 => static function (): void {
				global $wpdb;

				$table          = ItemCites::table();
				$charsetCollate = $wpdb->get_charset_collate();

				$sql = "CREATE TABLE {$table} (
					item_kind VARCHAR(16) NOT NULL,
					item_id BIGINT UNSIGNED NOT NULL,
					item_version INT UNSIGNED NULL,
					cited_kind VARCHAR(16) NOT NULL,
					cited_id BIGINT UNSIGNED NOT NULL,
					cited_version INT UNSIGNED NULL,
					position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
					PRIMARY KEY  (item_kind, item_id, cited_kind, cited_id),
					KEY cited (cited_kind, cited_id)
				) ENGINE=InnoDB {$charsetCollate};";

				self::dbDelta( $sql );
			},
			// PROV-1 (#170): derived-from links — the artifact / section
			// versions an item built by the pipeline was built from.
			// Identifiers and versions only, never content.
			4 => static function (): void {
				global $wpdb;

				$table          = DerivedFrom::table();
				$charsetCollate = $wpdb->get_charset_collate();

				$sql = "CREATE TABLE {$table} (
					item_kind VARCHAR(16) NOT NULL,
					item_id BIGINT UNSIGNED NOT NULL,
					source_kind VARCHAR(16) NOT NULL,
					source_id BIGINT UNSIGNED NOT NULL,
					source_version INT UNSIGNED NULL,
					position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
					PRIMARY KEY  (item_kind, item_id, source_kind, source_id),
					KEY source (source_kind, source_id)
				) ENGINE=InnoDB {$charsetCollate};";

				self::dbDelta( $sql );
			},
			// PROV-7 (#176): the retention sweep TTLs links by their own age,
			// so each link table gets a created stamp. The default applies
			// to rows written before this migration (MySQL fills them with
			// the migration time); new links get the default at insert.
			5 => static function (): void {
				global $wpdb;

				$charsetCollate = $wpdb->get_charset_collate();

				$links = OutsideSources::linksTable();
				self::dbDelta( "CREATE TABLE {$links} (
					item_kind VARCHAR(16) NOT NULL,
					item_id BIGINT UNSIGNED NOT NULL,
					source_id BIGINT UNSIGNED NOT NULL,
					position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
					created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY  (item_kind, item_id, source_id),
					KEY source_id (source_id)
				) ENGINE=InnoDB {$charsetCollate};" );

				$cites = ItemCites::table();
				self::dbDelta( "CREATE TABLE {$cites} (
					item_kind VARCHAR(16) NOT NULL,
					item_id BIGINT UNSIGNED NOT NULL,
					item_version INT UNSIGNED NULL,
					cited_kind VARCHAR(16) NOT NULL,
					cited_id BIGINT UNSIGNED NOT NULL,
					cited_version INT UNSIGNED NULL,
					position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
					created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY  (item_kind, item_id, cited_kind, cited_id),
					KEY cited (cited_kind, cited_id)
				) ENGINE=InnoDB {$charsetCollate};" );

				$derived = DerivedFrom::table();
				self::dbDelta( "CREATE TABLE {$derived} (
					item_kind VARCHAR(16) NOT NULL,
					item_id BIGINT UNSIGNED NOT NULL,
					source_kind VARCHAR(16) NOT NULL,
					source_id BIGINT UNSIGNED NOT NULL,
					source_version INT UNSIGNED NULL,
					position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
					created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY  (item_kind, item_id, source_kind, source_id),
					KEY source (source_kind, source_id)
				) ENGINE=InnoDB {$charsetCollate};" );
			},
		];
	}

	private static function dbDelta( string $sql ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );
	}
}
