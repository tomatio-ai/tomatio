<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Mcp;

/**
 * Agent-readable text for tool input that failed schema validation.
 *
 * WordPress reports 'q is a required property of input.' which says nothing
 * about what q is. This names the tool and each missing parameter with its
 * schema description, so an agent can fix the call without guessing. Pure:
 * works from the ability's own input schema, so it covers every tool.
 */
final class InputErrors {

	/**
	 * @param string               $ability  Ability name, e.g. agent-brain/memory-search.
	 * @param array<string, mixed> $schema   The ability's input schema.
	 * @param mixed                $args     The arguments the caller sent.
	 * @param string               $original WordPress's own message.
	 */
	public static function message( string $ability, array $schema, $args, string $original ): string {
		$tool       = str_contains( $ability, '/' ) ? substr( $ability, (int) strrpos( $ability, '/' ) + 1 ) : $ability;
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : [];
		$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : [];
		$args       = is_array( $args ) ? $args : [];

		$missing = [];
		foreach ( $required as $name ) {
			if ( is_string( $name ) && ! array_key_exists( $name, $args ) ) {
				$missing[] = self::describe( $name, $properties[ $name ] ?? null );
			}
		}

		if ( $missing !== [] ) {
			return sprintf( '%s needs %s.', $tool, implode( ', ', $missing ) );
		}

		$all = [];
		foreach ( $properties as $name => $def ) {
			$all[] = self::describe( (string) $name, $def ) . ( in_array( $name, $required, true ) ? ' [required]' : '' );
		}

		return $all === [] ? $original : sprintf( '%s Parameters of %s: %s.', rtrim( $original ), $tool, implode( '; ', $all ) );
	}

	/** @param mixed $def */
	private static function describe( string $name, $def ): string {
		$desc = is_array( $def ) && isset( $def['description'] ) && is_string( $def['description'] ) ? trim( $def['description'] ) : '';

		return $desc === '' ? $name : sprintf( '%s (%s)', $name, rtrim( $desc, '.' ) );
	}
}
