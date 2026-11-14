<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Core\RestNamespaces;

/**
 * GET agent-brain/v1/admin/overview: the live Overview snapshot, polled by
 * assets/overview-live.js. Same permission as the Overview page itself (any
 * TomaTío surface capability); cookie + wp_rest nonce like any wp-admin
 * REST call. Agents (ab_agent) have none of those capabilities.
 */
final class OverviewRest {

	public const NAMESPACE = 'agent-brain/v1';
	public const ROUTE     = '/admin/overview';

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('rest_api_init', [self::class, 'registerRoutes']);
	}

	public static function registerRoutes(): void {
		RestNamespaces::register( self::ROUTE, [
			'methods'             => 'GET',
			'callback'            => [self::class, 'handle'],
			'permission_callback' => [self::class, 'canView'],
		]);
	}

	public static function canView(): bool {
		if (! is_user_logged_in()) {
			return false;
		}

		foreach (Menu::surfaceCapabilities() as $cap) {
			if (current_user_can($cap)) {
				return true;
			}
		}

		return false;
	}

	public static function handle(): \WP_REST_Response {
		$response = new \WP_REST_Response(OverviewData::snapshot(get_current_user_id()), 200);
		$response->header('Cache-Control', 'no-store');

		return $response;
	}
}
