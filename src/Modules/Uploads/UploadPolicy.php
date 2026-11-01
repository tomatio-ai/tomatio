<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Uploads;

/**
 * ART-27 (#167): what may be stored as an artifact upload and how much.
 *
 * The HtmlFiles pattern (ART-22) applied to member uploads: a fixed allowlist
 * by extension, chosen so that nothing the server could execute or the
 * browser could run as a page can be stored at all — no php, no html, no
 * svg, no scripts. Binary types are magic-number sniffed (a file named
 * .png cannot be anything else) and text types must be valid UTF-8 without
 * NUL bytes. Everything is served back with forced Content-Disposition:
 * attachment and nosniff (UploadsRoute), never inline.
 */
final class UploadPolicy {

	/** @var array<string, array{type:string,text:bool}> extension => content type. */
	public const TYPES = [
		'png'  => [ 'type' => 'image/png', 'text' => false ],
		'jpg'  => [ 'type' => 'image/jpeg', 'text' => false ],
		'jpeg' => [ 'type' => 'image/jpeg', 'text' => false ],
		'gif'  => [ 'type' => 'image/gif', 'text' => false ],
		'webp' => [ 'type' => 'image/webp', 'text' => false ],
		'pdf'  => [ 'type' => 'application/pdf', 'text' => false ],
		'txt'  => [ 'type' => 'text/plain; charset=utf-8', 'text' => true ],
		'md'   => [ 'type' => 'text/markdown; charset=utf-8', 'text' => true ],
		'csv'  => [ 'type' => 'text/csv; charset=utf-8', 'text' => true ],
		'json' => [ 'type' => 'application/json; charset=utf-8', 'text' => true ],
	];

	/** File name characters: basename of letters, digits, dot, dash, underscore, space. */
	private const NAME_PATTERN = '/^[A-Za-z0-9._-]+(?: [A-Za-z0-9._-]+)*$/';

	/**
	 * @return array{max_file_bytes:int,max_per_artifact:int}
	 */
	public static function limits(): array {
		$defaults = [ 'max_file_bytes' => 10 * 1024 * 1024, 'max_per_artifact' => 100 ];
		$limits   = function_exists( 'apply_filters' ) ? apply_filters( 'agent_brain/upload_limits', $defaults ) : $defaults;
		$limits   = is_array( $limits ) ? $limits : $defaults;

		return [
			'max_file_bytes'   => max( 1024, (int) ( $limits['max_file_bytes'] ?? $defaults['max_file_bytes'] ) ),
			'max_per_artifact' => max( 1, (int) ( $limits['max_per_artifact'] ?? $defaults['max_per_artifact'] ) ),
		];
	}

	/**
	 * The original file name a viewer picked; only a safe basename survives.
	 */
	public static function isValidName( string $name ): bool {
		return $name !== ''
			&& strlen( $name ) <= 120
			&& preg_match( self::NAME_PATTERN, $name ) === 1
			&& $name[0] !== '.'
			&& ! str_contains( $name, '..' );
	}

	/**
	 * Content type for a file name by its (last) extension, or null when the
	 * extension is not on the allowlist.
	 */
	public static function typeFor( string $name ): ?string {
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		return self::TYPES[ $ext ]['type'] ?? null;
	}

	public static function isTextName( string $name ): bool {
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		return self::TYPES[ $ext ]['text'] ?? false;
	}

	/**
	 * Magic-number check for the binary types, so a file named .pdf cannot
	 * be anything else. Pure function (unit-tested without WordPress).
	 */
	public static function sniff( string $name, string $raw ): bool {
		$ext   = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$magic = [
			'png'  => "\x89PNG",
			'jpg'  => "\xFF\xD8\xFF",
			'jpeg' => "\xFF\xD8\xFF",
			'gif'  => 'GIF8',
			'webp' => 'RIFF',
			'pdf'  => '%PDF-',
		];

		return isset( $magic[ $ext ] ) && strncmp( $raw, $magic[ $ext ], strlen( $magic[ $ext ] ) ) === 0;
	}

	/**
	 * Validate one upload and return its raw bytes plus the served type.
	 *
	 * @param array<string, mixed> $file name, content, optional content_type, optional encoding (utf8|base64).
	 * @return array{name:string,mime:string,raw:string,size:int}|\WP_Error
	 */
	public static function normalize( array $file ) {
		$name = trim( (string) ( $file['name'] ?? '' ) );
		if ( ! self::isValidName( $name ) ) {
			return self::error( 'ab_upload_bad_name', 'Invalid file name. Use a short name of letters, digits, dot, dash, underscore and space.' );
		}

		$type = self::typeFor( $name );
		if ( $type === null ) {
			return self::error( 'ab_upload_bad_type', 'File type not allowed: ' . $name . '. Allowed extensions: ' . implode( ', ', array_keys( self::TYPES ) ) . '.' );
		}

		$declared = strtolower( trim( explode( ';', (string) ( $file['content_type'] ?? '' ) )[0] ) );
		if ( $declared !== '' && $declared !== strtolower( trim( explode( ';', $type )[0] ) ) ) {
			return self::error( 'ab_upload_type_mismatch', 'content_type ' . $declared . ' does not match the type of ' . $name . '.' );
		}

		$content  = (string) ( $file['content'] ?? '' );
		$isText   = self::isTextName( $name );
		$encoding = strtolower( (string) ( $file['encoding'] ?? ( $isText ? 'utf8' : 'base64' ) ) );
		if ( ! in_array( $encoding, [ 'utf8', 'base64' ], true ) || ( ! $isText && $encoding !== 'base64' ) ) {
			return self::error( 'ab_upload_bad_encoding', 'Use encoding base64 for binary files and utf8 or base64 for text: ' . $name . '.' );
		}

		if ( $encoding === 'base64' ) {
			$raw = base64_decode( $content, true );
			if ( $raw === false ) {
				return self::error( 'ab_upload_bad_encoding', 'Content of ' . $name . ' is not valid base64.' );
			}
		} else {
			$raw = $content;
		}

		if ( $isText && ( preg_match( '//u', $raw ) !== 1 || strpos( $raw, "\0" ) !== false ) ) {
			return self::error( 'ab_upload_bad_encoding', 'Content of ' . $name . ' is not valid UTF-8 text.' );
		}

		if ( ! $isText && ! self::sniff( $name, $raw ) ) {
			return self::error( 'ab_upload_type_mismatch', 'Content of ' . $name . ' does not look like that file type.' );
		}

		if ( strlen( $raw ) === 0 ) {
			return self::error( 'ab_upload_empty', 'The file is empty.' );
		}

		if ( strlen( $raw ) > self::limits()['max_file_bytes'] ) {
			return self::error( 'ab_upload_too_large', $name . ' is larger than ' . self::limits()['max_file_bytes'] . ' bytes.' );
		}

		return [
			'name' => $name,
			'mime' => $type,
			'raw'  => $raw,
			'size' => strlen( $raw ),
		];
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => 422 ] );
	}
}
