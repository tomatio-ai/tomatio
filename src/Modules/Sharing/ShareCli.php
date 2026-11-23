<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * WP-CLI commands for share links (`wp tomatio share ...`, also
 * `wp agent-brain share ...`).
 */
final class ShareCli {

	/**
	 * Remembers an existing public share link, so the home page shows and
	 * copies it (#645). Links minted since #645 are remembered on their own;
	 * this is for links made before. The token in the address must belong
	 * to a live, approved, public link of the artifact the address names:
	 * a private (sign-in) link, an embed link, a revoked or expired link, an
	 * unknown token or the wrong artifact is refused and nothing is stored.
	 * The token is never printed. No new link is made.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The full share link, https://<host>/share/<artifact id>/<token>. Give - to read it from standard input instead (keeps it out of the shell history).
	 *
	 * ## EXAMPLES
	 *
	 *     wp tomatio share remember https://brain.example.com/share/51/<token>
	 *     echo 'https://brain.example.com/share/51/<token>' | wp tomatio share remember -
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function remember( array $args, array $assocArgs ): void {
		unset( $assocArgs );

		$url = (string) ( $args[0] ?? '' );
		if ( $url === '-' ) {
			$line = fgets( STDIN );
			$url  = is_string( $line ) ? $line : '';
		}

		$result = PublicLinks::remember( $url, (int) get_current_user_id() );
		if ( $result instanceof \WP_Error ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Remembered public share link #%d of artifact %d. The home page now shows it.', $result['link_id'], $result['artifact_id'] ) );
	}
}
