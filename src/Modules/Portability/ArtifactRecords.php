<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\DataStore\StoreMode;
use AgentBrain\Modules\Sharing\Interactive;
use AgentBrain\Modules\Sharing\MembersAddress;
use AgentBrain\Modules\Sharing\ShareLinks;
use AgentBrain\Modules\Sharing\ViewerMode;
use AgentBrain\Modules\Uploads\UploadMode;

/**
 * #547: what an artifact is beyond its outline, in the doc extension
 * (docs/pcp-export.md "Shared documents"), read by ExtensionWriter and
 * written back by ExtensionRestore:
 *
 *  - on the artifact record: its kind (html), its friendly address (the
 *    slug, #438, and the slugs it had before) and its settings (the flags
 *    that switch on the page store, seats, uploads, interactive mode and
 *    viewer identity; the approved HTML version, mode and auto-reload);
 *  - html/<artifact>/<n>.json: an HTML version, its files as stored;
 *  - snapshots/<artifact>/<seq>.json: a row of the artifact's version
 *    history (Artifacts\Versions);
 *  - shares/<link id>.json: a live share link, without its token or bound
 *    IP. Tokens are bearer secrets for the old host: a migration never
 *    makes a link live, it keeps these records so the brain can list the
 *    links to create again (Kept).
 *
 * App icons have no data of their own: they are drawn from the HTML files
 * and the seat icon style, so they travel with those.
 *
 * Settings travel as their stored strings, by a fixed name, so the bytes
 * of an export depend only on the data and a migration writes them back
 * unchanged.
 */
final class ArtifactRecords {

	/** Setting name in the archive => post meta key. */
	public const SETTINGS = [
		'data_store'             => StoreMode::META_FLAG,
		'data_store_collections' => StoreMode::META_COLLECTIONS,
		'data_store_seats'       => StoreMode::META_SEATS,
		'html_approved'          => HtmlFiles::META_APPROVED,
		'html_auto_reload'       => HtmlFiles::META_AUTO_RELOAD,
		'html_mode'              => HtmlFiles::META_MODE,
		'interactive'            => Interactive::META_FLAG,
		'seat_icon_style'        => StoreMode::META_SEAT_ICON,
		'uploads'                => UploadMode::META_FLAG,
		'viewer_identity'        => ViewerMode::META_FLAG,
	];

	public const HTML_KEYS     = ['id', 'artifact', 'partition', 'version', 'created_at', 'created_by', 'note', 'files'];
	public const SNAPSHOT_KEYS = ['id', 'artifact', 'partition', 'seq', 'kind', 'section', 'section_version', 'html_version', 'title', 'content', 'by', 'client', 'note', 'at'];
	public const SHARE_KEYS    = ['id', 'artifact', 'partition', 'kind', 'privacy', 'viewer_mode', 'ip_mode', 'created_by', 'created_at', 'expires_at', 'approved_at', 'first_open_at', 'last_open_at', 'open_count'];

	/** Artifacts\Versions' own meta key (private to that class; the literal is shared deliberately). */
	public const META_SNAPSHOTS = 'ab_version_history';

	public const DIR_HTML      = 'html/';
	public const DIR_SNAPSHOTS = 'snapshots/';
	public const DIR_SHARES    = 'shares/';

	/**
	 * The artifact's settings that are set, by name, as stored.
	 *
	 * @return array<string, string>|null
	 */
	public static function settings(int $artifactId): ?array {
		$out = [];
		foreach (self::SETTINGS as $name => $key) {
			if (metadata_exists('post', $artifactId, $key)) {
				$out[$name] = ExportFormat::validUtf8((string) get_post_meta($artifactId, $key, true));
			}
		}

		return $out !== [] ? $out : null;
	}

	/** The kind, when not a document of sections. */
	public static function kind(int $artifactId): ?string {
		return HtmlFiles::isHtml($artifactId) ? HtmlFiles::KIND_HTML : null;
	}

	/**
	 * The slugs the artifact answered to before (#438), in the order kept.
	 *
	 * @return array<int, string>|null
	 */
	public static function oldSlugs(int $artifactId): ?array {
		$slugs = array_values(array_filter(array_map('strval', (array) get_post_meta($artifactId, MembersAddress::META_OLD_SLUGS, false)), static fn (string $s): bool => $s !== ''));

		return $slugs !== [] ? array_map([ExportFormat::class, 'validUtf8'], $slugs) : null;
	}

	/**
	 * Every HTML version of an artifact, oldest first, as records.
	 *
	 * @return array<int, array<string, mixed>> version number => record
	 */
	public static function htmlRecords(int $artifactId, string $partition, PrincipalMap $principals): array {
		$out = [];
		foreach (HtmlFiles::versions($artifactId) as $version) {
			$files = [];
			foreach ($version['files'] as $path => $file) {
				$files[(string) $path] = [
					'type' => (string) $file['type'],
					'enc'  => (string) $file['enc'],
					'size' => (int) $file['size'],
					'data' => (string) $file['data'],
				];
			}
			ksort($files, SORT_STRING);

			$n         = (int) $version['version'];
			$out[$n]   = self::ordered(self::HTML_KEYS, [
				'id'         => 'html-' . $artifactId . '-' . $n,
				'artifact'   => 'artifact-' . $artifactId,
				'partition'  => $partition,
				'version'    => $n,
				'created_at' => $version['created_at'] !== '' ? ExportFormat::validUtf8($version['created_at']) : null,
				'created_by' => $principals->idFor($version['created_by']),
				'note'       => $version['note'] !== '' ? ExportFormat::validUtf8($version['note']) : null,
				'files'      => $files !== [] ? $files : new \stdClass(),
			]);
		}

		return $out;
	}

	/**
	 * The artifact's version history rows, by sequence, as records. A row
	 * names its section only while that section is exported.
	 *
	 * @param array<int, true> $sections exported section ids
	 * @return array<int, array<string, mixed>> seq => record
	 */
	public static function snapshotRecords(int $artifactId, string $partition, array $sections, PrincipalMap $principals): array {
		$out = [];
		foreach (get_post_meta($artifactId, self::META_SNAPSHOTS, false) as $raw) {
			$row = is_string($raw) ? json_decode($raw, true) : null;
			if (! is_array($row) || ! isset($row['seq'], $row['kind']) || (int) $row['seq'] < 1) {
				continue;
			}

			$seq       = (int) $row['seq'];
			$out[$seq] = self::ordered(self::SNAPSHOT_KEYS, [
				'id'              => 'snapshot-' . $artifactId . '-' . $seq,
				'artifact'        => 'artifact-' . $artifactId,
				'partition'       => $partition,
				'seq'             => $seq,
				'kind'            => ExportFormat::validUtf8((string) $row['kind']),
				'section'         => isset($row['section_id'], $sections[(int) $row['section_id']]) ? 'section-' . (int) $row['section_id'] : null,
				'section_version' => isset($row['section_version']) ? (int) $row['section_version'] : null,
				'html_version'    => isset($row['html_version']) ? (int) $row['html_version'] : null,
				'title'           => isset($row['title']) ? ExportFormat::validUtf8((string) $row['title']) : null,
				'content'         => isset($row['content']) ? ExportFormat::validUtf8((string) $row['content']) : null,
				'by'              => $principals->idFor((int) ($row['actor'] ?? 0)),
				'client'          => isset($row['client']) && (string) $row['client'] !== '' ? ExportFormat::validUtf8((string) $row['client']) : null,
				'note'            => isset($row['note']) && (string) $row['note'] !== '' ? ExportFormat::validUtf8((string) $row['note']) : null,
				'at'              => ExportFormat::utc(isset($row['at']) ? (string) $row['at'] : null),
			]);
		}
		ksort($out, SORT_NUMERIC);

		return $out;
	}

	/**
	 * Live share links of the exported artifacts (not revoked, not expired),
	 * without their secrets.
	 *
	 * @param array<int, string> $artifacts exported artifact id => partition
	 * @return array<int, array<string, mixed>> link id => record
	 */
	public static function shareRecords(array $artifacts, PrincipalMap $principals): array {
		if ($artifacts === []) {
			return [];
		}

		$table = ShareLinks::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults("SELECT * FROM {$table} WHERE revoked_at IS NULL AND expires_at > %s ORDER BY id ASC", [gmdate('Y-m-d H:i:s')]);

		$out = [];
		foreach ($rows as $row) {
			$artifact = (int) $row['artifact_id'];
			if (! isset($artifacts[$artifact])) {
				continue;
			}

			$id       = (int) $row['id'];
			$out[$id] = self::ordered(self::SHARE_KEYS, [
				'id'            => 'share-' . $id,
				'artifact'      => 'artifact-' . $artifact,
				'partition'     => $artifacts[$artifact],
				'kind'          => (string) ($row['kind'] ?? 'link'),
				'privacy'       => (string) ($row['privacy'] ?? 'private'),
				'viewer_mode'   => (string) ($row['viewer_mode'] ?? 'anonymous'),
				'ip_mode'       => (string) ($row['ip_mode'] ?? 'off'),
				'created_by'    => $principals->idFor((int) $row['created_by']),
				'created_at'    => ExportFormat::utc((string) $row['created_at']),
				'expires_at'    => ExportFormat::utc((string) $row['expires_at']),
				'approved_at'   => ExportFormat::utc(isset($row['approved_at']) ? (string) $row['approved_at'] : null),
				'first_open_at' => ExportFormat::utc(isset($row['first_open_at']) ? (string) $row['first_open_at'] : null),
				'last_open_at'  => ExportFormat::utc(isset($row['last_open_at']) ? (string) $row['last_open_at'] : null),
				'open_count'    => (int) ($row['open_count'] ?? 0),
			]);
		}

		return $out;
	}

	/**
	 * @param array<int, string>   $order
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	public static function ordered(array $order, array $values): array {
		$out = [];
		foreach ($order as $key) {
			if (array_key_exists($key, $values) && $values[$key] !== null) {
				$out[$key] = $values[$key];
			}
		}

		return $out;
	}
}
