<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * A REST response whose body is a held archive file (ExportRest::archive()),
 * sent by ExportRest::serve() instead of JSON.
 */
final class ArchiveResponse extends \WP_REST_Response {

	public function __construct(public string $file) {
		parent::__construct(null, 200);
	}
}
