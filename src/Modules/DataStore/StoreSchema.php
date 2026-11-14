<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

/**
 * #384: a small JSON Schema subset for page store values.
 *
 *   type                  string|integer|number|boolean|object|array|null, or a list of them
 *   properties, required  objects; a property not listed is refused (no additionalProperties)
 *   minimum, maximum      numbers
 *   maxLength, pattern    strings (pattern is a regular expression, anchored by the author)
 *   maxItems, items       arrays
 *   enum                  a list of allowed scalar values
 *   immutable             (on a property) may not change once the document exists
 *   monotonic             (on a numeric property) may only go up
 *
 * Anything else is refused when the rules are saved, so a typo never turns
 * into "no check". Depth is capped at 4.
 */
final class StoreSchema {

	private const TYPES     = [ 'string', 'integer', 'number', 'boolean', 'object', 'array', 'null' ];
	// Public so artifact-store-config describes exactly what is accepted (#469).
	public const KEYWORDS   = [ 'type', 'properties', 'required', 'minimum', 'maximum', 'maxLength', 'pattern', 'maxItems', 'items', 'enum', 'immutable', 'monotonic' ];
	private const MAX_DEPTH = 4;

	/**
	 * A validated schema, or the reason it is not one.
	 *
	 * @param mixed $schema
	 * @return array<string, mixed>|string
	 */
	public static function normalize( $schema, int $depth = 0, string $at = '' ) {
		if ( ! is_array( $schema ) ) {
			return 'schema' . $at . ' must be an object';
		}

		if ( $depth > self::MAX_DEPTH ) {
			return 'schema is nested too deeply';
		}

		foreach ( array_keys( $schema ) as $keyword ) {
			if ( ! in_array( $keyword, self::KEYWORDS, true ) ) {
				$hint = $keyword === 'types' ? ' (use "type")' : '';

				return 'schema' . $at . ': unsupported keyword "' . $keyword . '"' . $hint . '; supported: ' . implode( ', ', self::KEYWORDS );
			}
		}

		if ( isset( $schema['type'] ) ) {
			$types = is_array( $schema['type'] ) ? $schema['type'] : [ $schema['type'] ];
			foreach ( $types as $type ) {
				if ( ! in_array( $type, self::TYPES, true ) ) {
					return 'schema' . $at . ': unknown type';
				}
			}
		}

		foreach ( [ 'minimum', 'maximum' ] as $bound ) {
			if ( isset( $schema[ $bound ] ) && ! is_int( $schema[ $bound ] ) && ! is_float( $schema[ $bound ] ) ) {
				return 'schema' . $at . ': ' . $bound . ' must be a number';
			}
		}

		foreach ( [ 'maxLength', 'maxItems' ] as $cap ) {
			if ( isset( $schema[ $cap ] ) && ( ! is_int( $schema[ $cap ] ) || $schema[ $cap ] < 0 ) ) {
				return 'schema' . $at . ': ' . $cap . ' must be a whole number';
			}
		}

		if ( isset( $schema['pattern'] ) && ( ! is_string( $schema['pattern'] ) || strlen( $schema['pattern'] ) > StoreRules::MAX_PATTERN_BYTES || @preg_match( StoreRules::delimit( $schema['pattern'] ), '' ) === false ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid pattern is reported, not warned.
			return 'schema' . $at . ': pattern is not a valid regular expression';
		}

		if ( isset( $schema['enum'] ) && ( ! is_array( $schema['enum'] ) || $schema['enum'] === [] || array_filter( $schema['enum'], static fn ( $v ): bool => is_array( $v ) ) !== [] ) ) {
			return 'schema' . $at . ': enum must be a list of scalar values';
		}

		foreach ( [ 'immutable', 'monotonic' ] as $flag ) {
			if ( isset( $schema[ $flag ] ) && ! is_bool( $schema[ $flag ] ) ) {
				return 'schema' . $at . ': ' . $flag . ' must be true or false';
			}
		}

		if ( isset( $schema['required'] ) && ( ! is_array( $schema['required'] ) || array_filter( $schema['required'], static fn ( $v ): bool => ! is_string( $v ) ) !== [] ) ) {
			return 'schema' . $at . ': required must be a list of property names';
		}

		if ( isset( $schema['properties'] ) ) {
			if ( ! is_array( $schema['properties'] ) ) {
				return 'schema' . $at . ': properties must be an object';
			}
			foreach ( $schema['properties'] as $name => $sub ) {
				$normal = self::normalize( $sub, $depth + 1, $at . '.' . $name );
				if ( is_string( $normal ) ) {
					return $normal;
				}
				$schema['properties'][ $name ] = $normal;
			}
		}

		if ( isset( $schema['items'] ) ) {
			$normal = self::normalize( $schema['items'], $depth + 1, $at . '[]' );
			if ( is_string( $normal ) ) {
				return $normal;
			}
			$schema['items'] = $normal;
		}

		return $schema;
	}

	/**
	 * Null when $value satisfies $schema (given the document's previous
	 * value, for immutable and monotonic), else where and why it does not.
	 *
	 * @param mixed                $value
	 * @param array<string, mixed> $schema
	 * @param mixed                $old   The stored value before this write; null for a new document.
	 */
	public static function check( $value, array $schema, $old = null, bool $exists = false, string $at = 'value' ): ?string {
		if ( isset( $schema['type'] ) ) {
			$types = is_array( $schema['type'] ) ? $schema['type'] : [ $schema['type'] ];
			$ok    = false;
			foreach ( $types as $type ) {
				$ok = $ok || self::isType( $value, (string) $type );
			}
			if ( ! $ok ) {
				return $at . ' must be ' . implode( ' or ', $types );
			}
		}

		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			return $at . ' must be one of the allowed values';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
				return $at . ' must be at least ' . $schema['minimum'];
			}
			if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
				return $at . ' must be at most ' . $schema['maximum'];
			}
		}

		if ( is_string( $value ) ) {
			if ( isset( $schema['maxLength'] ) && mb_strlen( $value ) > $schema['maxLength'] ) {
				return $at . ' must be at most ' . $schema['maxLength'] . ' characters';
			}
			if ( isset( $schema['pattern'] ) && preg_match( StoreRules::delimit( (string) $schema['pattern'] ), $value ) !== 1 ) {
				return $at . ' does not match the pattern';
			}
		}

		if ( is_array( $value ) && self::isList( $value ) ) {
			if ( isset( $schema['maxItems'] ) && count( $value ) > $schema['maxItems'] ) {
				return $at . ' may hold at most ' . $schema['maxItems'] . ' items';
			}
			if ( isset( $schema['items'] ) ) {
				foreach ( $value as $i => $item ) {
					$error = self::check( $item, $schema['items'], null, false, $at . '[' . $i . ']' );
					if ( $error !== null ) {
						return $error;
					}
				}
			}
		}

		// An empty array is also the empty object (json_decode() of {}), so required fields still apply.
		if ( isset( $schema['properties'] ) && is_array( $value ) && ( $value === [] || ! self::isList( $value ) ) ) {
			foreach ( array_keys( $value ) as $name ) {
				if ( ! isset( $schema['properties'][ $name ] ) ) {
					return $at . '.' . $name . ' is not an allowed field';
				}
			}
			foreach ( $schema['required'] ?? [] as $name ) {
				if ( ! array_key_exists( $name, $value ) ) {
					return $at . '.' . $name . ' is required';
				}
			}
			$oldObject = is_array( $old ) && ! self::isList( $old ) ? $old : [];
			foreach ( $schema['properties'] as $name => $sub ) {
				$had = $exists && array_key_exists( $name, $oldObject );
				if ( ! array_key_exists( $name, $value ) ) {
					if ( $had && ! empty( $sub['immutable'] ) ) {
						return $at . '.' . $name . ' cannot change';
					}
					continue;
				}
				if ( $had && ! empty( $sub['immutable'] ) && $oldObject[ $name ] !== $value[ $name ] ) {
					return $at . '.' . $name . ' cannot change';
				}
				if ( $had && ! empty( $sub['monotonic'] ) && is_numeric( $oldObject[ $name ] ) && is_numeric( $value[ $name ] ) && $value[ $name ] < $oldObject[ $name ] ) {
					return $at . '.' . $name . ' may only go up';
				}
				$error = self::check( $value[ $name ], $sub, $oldObject[ $name ] ?? null, $had, $at . '.' . $name );
				if ( $error !== null ) {
					return $error;
				}
			}
		}

		return null;
	}

	/**
	 * @param mixed $value
	 */
	private static function isType( $value, string $type ): bool {
		switch ( $type ) {
			case 'string':
				return is_string( $value );
			case 'integer':
				return is_int( $value );
			case 'number':
				return is_int( $value ) || is_float( $value );
			case 'boolean':
				return is_bool( $value );
			case 'null':
				return $value === null;
			case 'array':
				return is_array( $value ) && self::isList( $value );
			case 'object':
				// json_decode() turns {} into []: an empty array is an empty object too.
				return is_array( $value ) && ( $value === [] || ! self::isList( $value ) );
		}

		return false;
	}

	/**
	 * @param array<mixed> $value
	 */
	private static function isList( array $value ): bool {
		return $value === [] || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
