<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Compression;

/**
 * Validates the Compression output schema v1 (binding, see
 * the design contracts "Compression output schema v1").
 * Both providers (WP AI Client and Cloud) MUST produce output that validates
 * identically here (CMP-3). Invalid output => typed WP_Error, never a
 * partial write (CMP-3/L26).
 */
final class OutputSchema {

	public const VALID_OPS = [ 'add', 'update', 'skip' ];

	/**
	 * @param array<string, mixed> $data
	 */
	public static function validate( array $data ): bool|\WP_Error {
		if ( ! isset( $data['v'] ) || $data['v'] !== 1 ) {
			return new \WP_Error( 'ab_compression_schema_bad_version', 'Output schema "v" must be exactly 1.' );
		}

		if ( ! isset( $data['summary'] ) || ! is_string( $data['summary'] ) || trim( $data['summary'] ) === '' ) {
			return new \WP_Error( 'ab_compression_schema_bad_summary', 'Output schema "summary" must be a non-empty string.' );
		}

		if ( ! self::isScore( $data['importance'] ?? null ) ) {
			return new \WP_Error( 'ab_compression_schema_bad_importance', 'Output schema "importance" must be an integer 0-100.' );
		}

		if ( ! self::isStringList( $data['search_keys'] ?? null ) ) {
			return new \WP_Error( 'ab_compression_schema_bad_search_keys', 'Output schema "search_keys" must be an array of strings.' );
		}

		if ( ! isset( $data['facts'] ) || ! is_array( $data['facts'] ) ) {
			return new \WP_Error( 'ab_compression_schema_bad_facts', 'Output schema "facts" must be an array.' );
		}

		foreach ( $data['facts'] as $index => $fact ) {
			$result = self::validateFact( $fact, (int) $index );
			if ( $result instanceof \WP_Error ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * @param mixed $fact
	 */
	private static function validateFact( $fact, int $index ): bool|\WP_Error {
		if ( ! is_array( $fact ) ) {
			return new \WP_Error( 'ab_compression_schema_bad_fact', "facts[{$index}] must be an object." );
		}

		$op = $fact['op'] ?? null;
		if ( ! in_array( $op, self::VALID_OPS, true ) ) {
			return new \WP_Error(
				'ab_compression_schema_bad_fact_op',
				"facts[{$index}].op must be one of: " . implode( ', ', self::VALID_OPS )
			);
		}

		if ( ! self::isScore( $fact['importance'] ?? null ) ) {
			return new \WP_Error( 'ab_compression_schema_bad_fact_importance', "facts[{$index}].importance must be an integer 0-100." );
		}

		if ( ! self::isStringList( $fact['search_keys'] ?? null ) ) {
			return new \WP_Error( 'ab_compression_schema_bad_fact_search_keys', "facts[{$index}].search_keys must be an array of strings." );
		}

		if ( ! array_key_exists( 'target_id', $fact ) ) {
			return new \WP_Error( 'ab_compression_schema_bad_fact_target', "facts[{$index}].target_id must be present (int or null)." );
		}

		$targetId = $fact['target_id'];
		if ( $targetId !== null && ! ( is_int( $targetId ) && $targetId > 0 ) ) {
			return new \WP_Error( 'ab_compression_schema_bad_fact_target', "facts[{$index}].target_id must be a positive integer or null." );
		}

		if ( in_array( $op, [ 'add', 'update' ], true ) ) {
			if ( ! isset( $fact['text'] ) || ! is_string( $fact['text'] ) || trim( $fact['text'] ) === '' ) {
				return new \WP_Error( 'ab_compression_schema_bad_fact_text', "facts[{$index}].text is required and must be non-empty for op={$op}." );
			}
		}

		if ( in_array( $op, [ 'update', 'skip' ], true ) && $targetId === null ) {
			return new \WP_Error( 'ab_compression_schema_bad_fact_target', "facts[{$index}].target_id is required for op={$op}." );
		}

		return true;
	}

	/**
	 * @param mixed $value
	 */
	private static function isScore( $value ): bool {
		return is_int( $value ) && $value >= 0 && $value <= 100;
	}

	/**
	 * @param mixed $value
	 */
	private static function isStringList( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return false;
			}
		}

		return true;
	}
}
