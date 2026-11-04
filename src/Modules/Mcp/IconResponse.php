<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Mcp;

/**
 * A REST response whose body is image bytes (ConnectorIcon::respond()),
 * sent by ConnectorIcon::serve() instead of JSON.
 */
final class IconResponse extends \WP_REST_Response {

	public function __construct(public string $bytes, string $type) {
		parent::__construct(null, 200);
		$this->header('Content-Type', $type);
		$this->header('Content-Length', (string) strlen($bytes));
		$this->header('Cache-Control', 'public, max-age=86400');
		$this->header('X-Content-Type-Options', 'nosniff');
	}
}
