<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Admin\Ui;

/**
 * The embed allowlist setting (ab_embed_allowed_origins): rendered inside the
 * Sharing settings tab and saved by SettingsTab::handleSave.
 */
final class EmbedSettings {

	public const OPTION = 'ab_embed_allowed_origins';

	/**
	 * Validated origins (re-validated on read, so a tampered option cannot widen framing).
	 *
	 * @return string[]
	 */
	public static function allowedOrigins(): array {
		return EmbedPolicy::parseOrigins( (string) get_option( self::OPTION, '' ) )['origins'];
	}

	/**
	 * The setting's row in the Sharing tab (#751).
	 *
	 * @param string[] $origins The allowed origins, as allowedOrigins() returns them.
	 */
	public static function row( array $origins ): string {
		return Ui::setting(
			__( 'Embed allowed origins', 'agent-brain' ),
			$origins === []
				? esc_html__( 'None: embedding is refused everywhere.', 'agent-brain' )
				: '<code>' . esc_html( implode( ', ', $origins ) ) . '</code>',
			'<textarea id="ab_embed_allowed_origins" name="' . esc_attr( self::OPTION ) . '" rows="4" class="large-text code" placeholder="https://app.example.com">' . esc_textarea( implode( "\n", $origins ) ) . '</textarea>',
			'ab_embed_allowed_origins',
			'<p>' . esc_html__( 'One https origin per line (no wildcards, paths or http). Only these sites may show an embed link in an iframe. Empty means embedding is refused.', 'agent-brain' ) . '</p>',
			true
		);
	}

	/**
	 * Validates and stores the posted value. Nothing is stored when any line is invalid.
	 *
	 * @return string[] The rejected lines (empty on success).
	 */
	public static function saveRaw( string $raw ): array {
		$parsed = EmbedPolicy::parseOrigins( $raw );

		if ( $parsed['invalid'] !== [] ) {
			return $parsed['invalid'];
		}

		if ( $parsed['origins'] === [] ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, implode( "\n", $parsed['origins'] ) );
		}

		return [];
	}
}
