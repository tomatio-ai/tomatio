<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Modules\Memory\MemoryRepository;

/**
 * Direct table writes shared by restore and import (RestoreWriter,
 * ImportWriter): pages go straight into the tables, never through
 * wp_insert_post(), so no kses changes their bytes and no hook proposes,
 * publishes, notifies or assigns new ids and paths. The callers clear the
 * caches afterwards.
 */
trait WritesTables {

	/** @var array<int, int> Created post ids (for cache clearing). */
	private array $createdPosts = [];

	/** @return array<int, int> */
	public function createdPosts(): array {
		return $this->createdPosts;
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function insertPost(array $fields): int {
		global $wpdb;

		$row = $fields + [
			'post_excerpt'          => '',
			'comment_status'        => 'closed',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'to_ping'               => '',
			'pinged'                => '',
			'post_content_filtered' => '',
			'guid'                  => '',
			'menu_order'            => 0,
			'post_mime_type'        => '',
			'comment_count'         => 0,
		];

		if ($wpdb->insert($wpdb->posts, $row) === false) {
			throw new ImportFailed('internal', 'A knowledge or procedure page could not be written.');
		}

		$postId               = (int) $wpdb->insert_id;
		$this->createdPosts[] = $postId;

		return $postId;
	}

	/**
	 * @param mixed $value
	 */
	private function setPostMeta(int $postId, string $key, $value): void {
		global $wpdb;

		$wpdb->insert($wpdb->postmeta, ['post_id' => $postId, 'meta_key' => $key, 'meta_value' => maybe_serialize($value)], ['%d', '%s', '%s']); // phpcs:ignore WordPress.DB.SlowDBQuery -- one page at a time.
	}

	/**
	 * @param array<string, mixed>  $data
	 * @param array<int, string>    $formats
	 */
	private function insertRow(array $data, array $formats): int {
		global $wpdb;

		if ($wpdb->insert(MemoryRepository::memoryTable(), $data, $formats) === false) {
			throw new ImportFailed('internal', 'A memory page could not be written.');
		}

		return (int) $wpdb->insert_id;
	}

	private function index(string $kind, int $objectId, string $pcpId, string $path): void {
		global $wpdb;

		$ok = $wpdb->insert(
			PageIndex::table(),
			['object_kind' => $kind, 'object_id' => $objectId, 'pcp_id' => $pcpId, 'path' => $path, 'path_lower' => strtolower($path), 'created' => gmdate('Y-m-d H:i:s')],
			['%s', '%d', '%s', '%s', '%s', '%s']
		);
		if ($ok === false) {
			throw new ImportFailed('conflict', 'Two pages would have the same id or path.');
		}
	}

	/**
	 * Keeps what the columns cannot give back (PageExtra).
	 *
	 * @param array<string, mixed> $page   The page's frontmatter as it should export.
	 * @param array<string, mixed> $native What the columns give (PageWriter).
	 */
	private function keepExtra(string $kind, int $objectId, array $page, array $native): void {
		global $wpdb;

		$json = PageExtra::encode(PageExtra::diff($page, $native));
		if ($json === null) {
			return;
		}

		if ($kind === 'memory') {
			$wpdb->update(MemoryRepository::memoryTable(), ['pcp_extra' => $json], ['id' => $objectId], ['%s'], ['%d']);
		} else {
			$this->setPostMeta($objectId, PageExtra::META, $json);
		}
	}

	/** RFC 3339 (any offset, or a date) to a MySQL UTC DATETIME, with milliseconds when asked. */
	public static function mysql(string $value, bool $millis = false): string {
		$utc = TimeConvert::utc($value);
		if ($utc === null) {
			return '1970-01-01 00:00:00';
		}

		$main     = substr($utc, 0, 10) . ' ' . substr($utc, 11, 8);
		$fraction = preg_match('/\.(\d+)Z$/', $utc, $m) === 1 ? substr($m[1] . '000', 0, 3) : '000';

		return $millis ? $main . '.' . $fraction : $main;
	}
}
