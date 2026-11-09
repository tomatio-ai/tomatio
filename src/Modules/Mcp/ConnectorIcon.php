<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Mcp;

use AgentBrain\Core\RestNamespaces;
use AgentBrain\Modules\Admin\BrandIcon;
use AgentBrain\Modules\Sharing\AppIcon;
use WP_Error;
use WP_REST_Request;

/**
 * #778: the brain's own icon at an address any AI app can fetch, for a
 * brain without a Site Icon: its monogram (BrandIcon) as a PNG, or as an
 * SVG without GD. MCP serverInfo icons point here, and the Connect page
 * offers it as the icon to upload where an app asks for one (ChatGPT).
 * Never TomaTío's mark: the connector is the team's brain.
 *
 *   GET /wp-json/agent-brain/v1/brand-icon/{192|512}?v={hash}
 *
 * Public and read-only, like a favicon. The hash changes with the name,
 * the accent and the plugin version, since claude.ai caches connector
 * icons by address.
 */
final class ConnectorIcon {

	public const ROUTE = 'brand-icon';
	public const SIZES = [512, 192];

	public static function register(): void {
		add_action('rest_api_init', [self::class, 'registerRoutes']);
		add_filter('rest_pre_serve_request', [self::class, 'serve'], 10, 2);
	}

	public static function registerRoutes(): void {
		RestNamespaces::register('/' . self::ROUTE . '/(?P<size>\d+)', [
			'methods'             => 'GET',
			'callback'            => [self::class, 'respond'],
			'permission_callback' => '__return_true',
		]);
	}

	public static function url(int $size): string {
		return add_query_arg('v', self::version(), rest_url(RestNamespaces::CANONICAL . '/' . self::ROUTE . '/' . $size));
	}

	/**
	 * The monogram as serverInfo icons: PNG at 512 and 192 px, or one SVG.
	 *
	 * @return array<int, array{src:string, mimeType:string, sizes:string[]}>
	 */
	public static function icons(): array {
		if (! AppIcon::canDraw()) {
			return [['src' => self::url(512), 'mimeType' => 'image/svg+xml', 'sizes' => ['any']]];
		}

		$icons = [];
		foreach (self::SIZES as $size) {
			$icons[] = ['src' => self::url($size), 'mimeType' => 'image/png', 'sizes' => [$size . 'x' . $size]];
		}

		return $icons;
	}

	/**
	 * @return IconResponse|WP_Error
	 */
	public static function respond(WP_REST_Request $request) {
		$size = (int) $request['size'];
		if (! in_array($size, self::SIZES, true)) {
			return new WP_Error('rest_no_route', 'No such icon.', ['status' => 404]);
		}

		$png = BrandIcon::png($size);

		return $png !== null
			? new IconResponse($png, 'image/png')
			: new IconResponse(BrandIcon::svg(BrandIcon::name(), BrandIcon::accent()), 'image/svg+xml');
	}

	/**
	 * rest_pre_serve_request: the icon's bytes instead of JSON. Headers
	 * were already sent by the REST server.
	 *
	 * @param mixed $served
	 * @param mixed $result
	 * @return mixed
	 */
	public static function serve($served, $result) {
		if ($served || ! $result instanceof IconResponse) {
			return $served;
		}

		echo $result->bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image bytes.

		return true;
	}

	private static function version(): string {
		return substr(md5(BrandIcon::name() . '|' . BrandIcon::accent() . '|' . (AppIcon::canDraw() ? 'png' : 'svg') . '|' . \AGENT_BRAIN_VERSION), 0, 8);
	}
}
