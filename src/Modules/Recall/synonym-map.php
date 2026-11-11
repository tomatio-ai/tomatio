<?php

declare(strict_types=1);

/**
 * Small, deliberately conservative synonym groups for Recall query expansion
 * (#64). Each group is a list of interchangeable lowercase terms. Filterable
 * through `tomatio/recall_synonyms`. No embeddings, no external calls.
 */
return [
	[ 'ship', 'release', 'deploy', 'launch' ],
	[ 'bug', 'defect', 'issue', 'problem' ],
	[ 'doc', 'docs', 'documentation' ],
	[ 'config', 'configuration', 'settings' ],
	[ 'error', 'failure', 'exception' ],
	[ 'delete', 'remove' ],
	[ 'auth', 'authentication', 'login' ],
	[ 'repo', 'repository' ],
	[ 'test', 'tests', 'testing' ],
	// T199: a redesign is often written up as a cleanup or a simpler page.
	[ 'redesign', 'rework', 'overhaul', 'revamp', 'cleanup', 'simplify', 'simpler' ],
];
