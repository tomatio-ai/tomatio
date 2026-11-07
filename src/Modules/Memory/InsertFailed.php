<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Memory;

/**
 * The database did not store a memory row (issue #183). Thrown instead of
 * returning an id, because after a failed INSERT $wpdb->insert_id still
 * holds the previous row's id in the same request.
 */
final class InsertFailed extends \RuntimeException {
}
