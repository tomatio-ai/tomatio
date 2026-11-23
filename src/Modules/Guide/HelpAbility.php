<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Guide;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;

/**
 * #861: tomatio-help, the TomaTío manual for any connection. It answers
 * questions about TomaTío itself (what agents and Tíos are, connecting an
 * AI, scopes, PCP, export, migration and moving a brain) from Manual, never
 * from the brain's memory, so it needs no scope and writes nothing.
 */
final class HelpAbility {

	public const QUESTION_MAX = 500;

	public static function register(): void {
		if (! function_exists('wp_register_ability')) {
			return;
		}

		AbilityNames::registerOnce(
			AbilityNames::HELP,
			[
				'label'               => __('TomaTío help', 'tomatio'),
				'description'         => __('The TomaTío manual, for questions about TomaTío itself rather than this team: what a brain, an agent and a Tío are, connecting Claude, ChatGPT or another AI, scopes and review, documents and pages, TíoDay, PCP export and import, migrating a brain and moving it to hosting or to your own computer. Pass question for the best-matching sections, page for one whole page (names are in pages), or nothing for the contents. Answers come from the manual, never from memory; cite the url of what you use.', 'tomatio'),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'question' => ['type' => 'string', 'maxLength' => self::QUESTION_MAX, 'description' => 'What the person wants to know, in their words.'],
						'page'     => ['type' => 'string', 'maxLength' => 64, 'description' => 'One page by name, e.g. "export-and-migration".'],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'source'     => ['type' => 'string', 'enum' => ['tomatio.ai', 'bundled']],
						'version'    => ['type' => 'string'],
						'fetched_at' => ['type' => ['string', 'null']],
						'note'       => ['type' => 'string'],
						'results'    => ['type' => 'array'],
						'pages'      => ['type' => 'array'],
					],
					'required'   => ['source', 'version', 'results', 'pages'],
				],
				'permission_callback' => static fn (): bool => self::can(),
				'execute_callback'    => static fn ($input): array => self::answer(is_array($input) ? $input : []),
			]
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function answer(array $input): array {
		$manual   = Manual::current();
		$question = is_string($input['question'] ?? null) ? mb_substr(trim($input['question']), 0, self::QUESTION_MAX) : '';
		$name     = is_string($input['page'] ?? null) ? trim($input['page']) : '';

		$notes = [];
		if ($name !== '') {
			$page = Manual::page($name);
			if ($page === null) {
				$results = [];
				/* translators: %s: the page name asked for. */
				$notes[] = sprintf(__('There is no page "%s" in this copy of the manual; the pages are listed.', 'tomatio'), $name);
			} else {
				$results = [[
					'page'    => $page['name'],
					'title'   => $page['title'],
					'section' => '',
					'url'     => $page['url'],
					'text'    => Manual::clip(Manual::text($page['html']), 20000),
				]];
			}
		} elseif ($question !== '') {
			$results = Manual::search($question);
			if ($results === []) {
				$notes[] = __('Nothing in this copy of the manual matches; try other words, or one of the pages listed.', 'tomatio');
			}
		} else {
			$contents = Manual::page('README');
			$results  = $contents === null ? [] : [[
				'page'    => 'README',
				'title'   => $contents['title'],
				'section' => '',
				'url'     => $contents['url'],
				'text'    => Manual::clip(Manual::text($contents['html']), 20000),
			]];
		}

		if ($manual['source'] === 'bundled') {
			/* translators: %s: address of the full manual. */
			$notes[] = sprintf(__('This brain has only the core pages of the manual so far; it fetches the rest from tomatio.ai once a day. The full manual is at %s.', 'tomatio'), Manual::DOCS_URL);
		}

		$out = [
			'source'     => $manual['source'],
			'version'    => $manual['version'],
			'fetched_at' => $manual['fetched_at'] !== null ? gmdate('Y-m-d\TH:i:s\Z', $manual['fetched_at']) : null,
			'results'    => $results,
			'pages'      => array_map(static fn (array $p): array => ['name' => $p['name'], 'title' => $p['title'], 'url' => $p['url']], $manual['pages']),
		];

		return $notes !== [] ? $out + ['note' => implode(' ', $notes)] : $out;
	}

	/** Any connection that may use MCP, whatever its scopes. */
	private static function can(): bool {
		$actorId = get_current_user_id();

		return $actorId > 0 && user_can($actorId, Capabilities::CAP_USE_MCP);
	}
}
