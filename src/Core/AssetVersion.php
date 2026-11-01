<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * Cache-busting version for the plugin's own static files.
 *
 * The plugin version alone does not change between rebuilds of the same
 * release, so a CDN or browser that cached assets/admin.css keeps serving
 * the old file after an upgrade. Appending the file's modification time
 * changes the URL whenever the file changes.
 */
final class AssetVersion {

	/**
	 * @param string $relativePath Path under the plugin directory, e.g. "assets/admin.css".
	 */
	public static function of( string $relativePath ): string {
		$base = defined( 'AGENT_BRAIN_VERSION' ) ? (string) AGENT_BRAIN_VERSION : '0';

		if ( ! defined( 'AGENT_BRAIN_PLUGIN_FILE' ) ) {
			return $base;
		}

		$file  = dirname( (string) AGENT_BRAIN_PLUGIN_FILE ) . '/' . ltrim( $relativePath, '/' );
		$mtime = is_readable( $file ) ? (int) filemtime( $file ) : 0;

		return $mtime > 0 ? $base . '.' . $mtime : $base;
	}
}
