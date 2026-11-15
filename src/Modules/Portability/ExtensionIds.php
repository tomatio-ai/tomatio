<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * The ids of TomaTío's two PCP tool extensions (PCP v0.3 SPEC sections 6.5
 * and 8.5): shared documents (slot doc: artifacts, sections, stages) and
 * handoffs (slot handoff). Each id names the extension's folder in an
 * archive, extensions/<id>/, and its manifest entry.
 *
 * The ids are the product's own, per the launch roadmap's naming decision:
 * ai.tomatio plus the tool part (export plan, decision 3). The tool part is
 * joined with a dot because a manifest extension id allows no slash.
 */
final class ExtensionIds {

	/** ai.tomatio: the only line to change if the product's id ever changes. */
	public const ID_BASE = 'ai.tomatio';

	public const DOC     = self::ID_BASE . '.doc';
	public const HANDOFF = self::ID_BASE . '.handoff';

	/** Version of the export layouts under extensions/<id>/. */
	public const LAYOUT_VERSION = '1';

	/** The archive folder of an extension, with its trailing slash. */
	public static function path(string $id): string {
		return 'extensions/' . $id . '/';
	}
}
