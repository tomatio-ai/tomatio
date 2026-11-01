<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;
use AgentBrain\Modules\Sharing\ShareAbilities;

/**
 * #449: brain-app-link-get / brain-app-link-set, so the link back to the
 * team's app can be set from the connector. Authority: ab_manage
 * (administrators and managers) on a connection that may use MCP; an
 * agent never sees either ability.
 */
final class AppLinkAbilities {

	public static function register(): void {
		if (! function_exists('wp_register_ability')) {
			return;
		}

		$output = [
			'type'       => 'object',
			'properties' => [
				'set'          => ['type' => 'boolean'],
				'url'          => ['type' => 'string'],
				'label'        => ['type' => 'string'],
				'custom_label' => ['type' => 'boolean'],
			],
			'required'   => ['set'],
		];

		AbilityNames::registerOnce(
			AbilityNames::BRAIN_APP_LINK_GET,
			[
				'label'               => __('Get the app link', 'agent-brain'),
				'description'         => __('The brain\'s link back to the team\'s own app or site (#449): its address and label, shown on the brain\'s home page, the sign-in page and the admin toolbar. Returns set=false when none is set. Authority: administrators and managers (ab_manage).', 'agent-brain'),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => ['type' => 'object', 'properties' => new \stdClass()],
				'output_schema'       => $output,
				'permission_callback' => static fn (): bool => self::can(),
				'execute_callback'    => static fn (): array => self::shape(AppLink::get()),
			]
		);

		AbilityNames::registerOnce(
			AbilityNames::BRAIN_APP_LINK_SET,
			[
				'label'               => __('Set the app link', 'agent-brain'),
				'description'         => __('Set or clear the brain\'s link back to the team\'s own app or site (#449). url must be a full http:// or https:// address; an empty url clears the link. label is optional (at most 80 characters); without it the link reads "Back to" and the site title. Every change is written to the audit log. Authority: administrators and managers (ab_manage), on their own connection rather than while acting as an agent.', 'agent-brain'),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'url'   => ['type' => 'string', 'maxLength' => AppLink::MAX_URL, 'description' => 'http(s) address of the team\'s app or site; empty to clear.'],
						'label' => ['type' => 'string', 'maxLength' => AppLink::MAX_LABEL, 'description' => 'Optional link text, e.g. "Back to Cumulus.aero".'],
					],
					'required'   => ['url'],
				],
				'output_schema'       => $output,
				'permission_callback' => static fn (): bool => self::can(),
				'execute_callback'    => static function ($input) {
					$input = is_array($input) ? $input : [];

					return ShareAbilities::withLimiter(static function () use ($input) {
						$result = AppLink::set(
							get_current_user_id(),
							is_string($input['url'] ?? null) ? $input['url'] : '',
							is_string($input['label'] ?? null) ? $input['label'] : '',
							'ability'
						);

						return $result instanceof \WP_Error ? $result : self::shape($result);
					});
				},
			]
		);
	}

	private static function can(): bool {
		$actorId = get_current_user_id();

		return $actorId > 0 && user_can($actorId, Capabilities::CAP_USE_MCP) && AppLink::canManage($actorId);
	}

	/**
	 * @param array{url:string, label:string, custom_label:bool}|null $link
	 * @return array<string, mixed>
	 */
	private static function shape(?array $link): array {
		return $link === null ? ['set' => false] : ['set' => true] + $link;
	}
}
