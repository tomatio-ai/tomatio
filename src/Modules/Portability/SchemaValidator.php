<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * Validates documents against the PCP v0.3 schemas shipped in schemas/
 * (copied unchanged from the PCP repository), for the checks an importer
 * runs before writing anything (SPEC section 8.3 items 1 and 5). Pure.
 *
 * Implements the JSON Schema 2020-12 keywords those schemas use: type,
 * enum, const, pattern, minLength, maxLength, minimum, maximum, required,
 * properties, patternProperties, additionalProperties, propertyNames,
 * items, minItems, maxItems, uniqueItems, contains, allOf, anyOf, not,
 * if/then/else and $ref (within a schema and across the shipped files).
 * format is an annotation, as 2020-12 says by default.
 *
 * Data is PHP's decoding with associative arrays: a list is an array, any
 * other array is an object, and an empty array is both.
 */
final class SchemaValidator {

	public const PAGE     = 'memory.page.schema.json';
	public const CONTEXT  = 'export.context.schema.json';
	public const MANIFEST = 'export.manifest.schema.json';
	public const AUDIT    = 'audit.entry.schema.json';
	public const RECEIPT  = 'erasure.receipt.schema.json';

	/** Agent Brain's own extension schema, for manifest.signature.json (PROV-8); not a PCP spec schema. */
	public const SIGNATURE = 'export.signature.schema.json';

	private const MAX_ERRORS = 10;

	/** @var array<string, array<string, mixed>> */
	private static array $schemas = [];

	/** @var array<int, string> */
	private array $errors = [];

	/**
	 * Errors for one document (empty when it validates), each as
	 * "<JSON pointer>: <message>".
	 *
	 * @param mixed $data
	 * @return array<int, string>
	 */
	public static function errors(string $schema, $data): array {
		$validator = new self();
		$validator->check($data, self::load($schema), $schema, '');

		return array_slice($validator->errors, 0, self::MAX_ERRORS);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function load(string $file): array {
		if (! isset(self::$schemas[$file])) {
			if (preg_match('/^[a-z.]+\.schema\.json$/', $file) !== 1) {
				throw new \InvalidArgumentException('Unknown schema.');
			}

			$decoded = json_decode((string) file_get_contents(__DIR__ . '/schemas/' . $file), true);
			if (! is_array($decoded)) {
				throw new \RuntimeException('A shipped PCP schema is missing or unreadable: ' . $file); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- file name of a shipped file.
			}

			self::$schemas[$file] = $decoded;
		}

		return self::$schemas[$file];
	}

	/**
	 * @param mixed                $data
	 * @param array<string, mixed>|bool $schema
	 */
	private function check($data, $schema, string $file, string $pointer): void {
		if (count($this->errors) >= self::MAX_ERRORS || $schema === true) {
			return;
		}
		if ($schema === false) {
			$this->fail($pointer, 'is not allowed');

			return;
		}

		if (isset($schema['$ref'])) {
			[$refFile, $refSchema] = $this->resolve((string) $schema['$ref'], $file);
			$this->check($data, $refSchema, $refFile, $pointer);
		}

		if (isset($schema['type']) && ! self::isType($data, $schema['type'])) {
			$this->fail($pointer, 'must be of type ' . implode(' or ', (array) $schema['type']));

			return;
		}

		if (array_key_exists('const', $schema) && ! self::equal($data, $schema['const'])) {
			$this->fail($pointer, 'must equal ' . self::show($schema['const']));
		}

		if (isset($schema['enum']) && is_array($schema['enum'])) {
			$found = false;
			foreach ($schema['enum'] as $option) {
				if (self::equal($data, $option)) {
					$found = true;
					break;
				}
			}
			if (! $found) {
				$this->fail($pointer, 'must be one of ' . implode(', ', array_map([self::class, 'show'], $schema['enum'])));
			}
		}

		if (is_string($data)) {
			$this->checkString($data, $schema, $pointer);
		}

		if (is_int($data) || is_float($data)) {
			if (isset($schema['minimum']) && $data < $schema['minimum']) {
				$this->fail($pointer, 'must be at least ' . $schema['minimum']);
			}
			if (isset($schema['maximum']) && $data > $schema['maximum']) {
				$this->fail($pointer, 'must be at most ' . $schema['maximum']);
			}
		}

		if (is_array($data)) {
			if (self::isObject($data)) {
				$this->checkObject($data, $schema, $file, $pointer);
			}
			if (array_is_list($data)) {
				$this->checkArray($data, $schema, $file, $pointer);
			}
		}

		foreach ((array) ($schema['allOf'] ?? []) as $sub) {
			$this->check($data, $sub, $file, $pointer);
		}

		if (isset($schema['anyOf']) && ! $this->any($data, (array) $schema['anyOf'], $file, $pointer)) {
			$this->fail($pointer, 'matches none of the allowed forms');
		}

		if (isset($schema['not']) && $this->passes($data, $schema['not'], $file, $pointer)) {
			$this->fail($pointer, 'matches a form that is not allowed');
		}

		if (isset($schema['if'])) {
			if ($this->passes($data, $schema['if'], $file, $pointer)) {
				if (isset($schema['then'])) {
					$this->check($data, $schema['then'], $file, $pointer);
				}
			} elseif (isset($schema['else'])) {
				$this->check($data, $schema['else'], $file, $pointer);
			}
		}
	}

	/**
	 * @param array<string, mixed> $schema
	 */
	private function checkString(string $data, array $schema, string $pointer): void {
		$length = mb_strlen($data, 'UTF-8');
		if (isset($schema['minLength']) && $length < $schema['minLength']) {
			$this->fail($pointer, 'must be at least ' . $schema['minLength'] . ' characters');
		}
		if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
			$this->fail($pointer, 'must be at most ' . $schema['maxLength'] . ' characters');
		}
		if (isset($schema['pattern']) && ! self::matches((string) $schema['pattern'], $data)) {
			$this->fail($pointer, 'does not match the required pattern');
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $schema
	 */
	private function checkObject(array $data, array $schema, string $file, string $pointer): void {
		foreach ((array) ($schema['required'] ?? []) as $key) {
			if (! array_key_exists((string) $key, $data)) {
				$this->fail($pointer, 'is missing "' . $key . '"');
			}
		}

		$properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
		$patterns   = is_array($schema['patternProperties'] ?? null) ? $schema['patternProperties'] : [];

		foreach ($data as $key => $value) {
			$key     = (string) $key;
			$child   = $pointer . '/' . str_replace(['~', '/'], ['~0', '~1'], $key);
			$matched = false;

			if (array_key_exists($key, $properties)) {
				$matched = true;
				$this->check($value, $properties[$key], $file, $child);
			}
			foreach ($patterns as $pattern => $sub) {
				if (self::matches((string) $pattern, $key)) {
					$matched = true;
					$this->check($value, $sub, $file, $child);
				}
			}
			if (! $matched && array_key_exists('additionalProperties', $schema)) {
				if ($schema['additionalProperties'] === false) {
					$this->fail($pointer, 'has the key "' . $key . '", which is not allowed');
				} else {
					$this->check($value, $schema['additionalProperties'], $file, $child);
				}
			}
			if (isset($schema['propertyNames'])) {
				$this->check($key, $schema['propertyNames'], $file, $child);
			}
		}
	}

	/**
	 * @param array<int, mixed>    $data
	 * @param array<string, mixed> $schema
	 */
	private function checkArray(array $data, array $schema, string $file, string $pointer): void {
		if (isset($schema['minItems']) && count($data) < $schema['minItems']) {
			$this->fail($pointer, 'must have at least ' . $schema['minItems'] . ' items');
		}
		if (isset($schema['maxItems']) && count($data) > $schema['maxItems']) {
			$this->fail($pointer, 'must have at most ' . $schema['maxItems'] . ' items');
		}
		if (! empty($schema['uniqueItems'])) {
			$seen = [];
			foreach ($data as $item) {
				$key = (string) json_encode($item);
				if (isset($seen[$key])) {
					$this->fail($pointer, 'has the same item twice');
					break;
				}
				$seen[$key] = true;
			}
		}
		if (isset($schema['items'])) {
			foreach ($data as $i => $item) {
				$this->check($item, $schema['items'], $file, $pointer . '/' . $i);
			}
		}
		if (isset($schema['contains'])) {
			$found = false;
			foreach ($data as $item) {
				if ($this->passes($item, $schema['contains'], $file, $pointer)) {
					$found = true;
					break;
				}
			}
			if (! $found) {
				$this->fail($pointer, 'has no item of the required form');
			}
		}
	}

	/**
	 * @param mixed                     $data
	 * @param array<string, mixed>|bool $schema
	 */
	private function passes($data, $schema, string $file, string $pointer): bool {
		$probe = new self();
		$probe->check($data, $schema, $file, $pointer);

		return $probe->errors === [];
	}

	/**
	 * @param mixed             $data
	 * @param array<int, mixed> $options
	 */
	private function any($data, array $options, string $file, string $pointer): bool {
		foreach ($options as $option) {
			if ($this->passes($data, $option, $file, $pointer)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{0:string, 1:array<string, mixed>|bool}
	 */
	private function resolve(string $ref, string $file): array {
		[$target, $fragment] = array_pad(explode('#', $ref, 2), 2, '');
		$target              = $target === '' ? $file : basename($target);
		$schema              = self::load($target);

		if ($fragment !== '') {
			foreach (array_filter(explode('/', $fragment), static fn (string $s): bool => $s !== '') as $segment) {
				$segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
				if (! is_array($schema) || ! array_key_exists($segment, $schema)) {
					throw new \RuntimeException('Unresolvable $ref in a shipped PCP schema: ' . $ref); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- reference inside a shipped file.
				}
				$schema = $schema[$segment];
			}
		}

		return [$target, $schema];
	}

	/**
	 * @param mixed                $data
	 * @param string|array<int, string> $types
	 */
	private static function isType($data, $types): bool {
		foreach ((array) $types as $type) {
			$ok = match ($type) {
				'object'  => is_array($data) && self::isObject($data),
				'array'   => is_array($data) && array_is_list($data),
				'string'  => is_string($data),
				'integer' => is_int($data) || (is_float($data) && floor($data) === $data && is_finite($data)),
				'number'  => is_int($data) || is_float($data),
				'boolean' => is_bool($data),
				'null'    => $data === null,
				default   => false,
			};
			if ($ok) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<mixed> $data
	 */
	private static function isObject(array $data): bool {
		return $data === [] || ! array_is_list($data);
	}

	/**
	 * @param mixed $a
	 * @param mixed $b
	 */
	private static function equal($a, $b): bool {
		if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
			return (float) $a === (float) $b;
		}

		return $a === $b;
	}

	private static function matches(string $pattern, string $subject): bool {
		$regex = '/' . str_replace('/', '\/', $pattern) . '/u';

		return preg_match($regex, $subject) === 1;
	}

	/**
	 * @param mixed $value
	 */
	private static function show($value): string {
		return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	private function fail(string $pointer, string $message): void {
		if (count($this->errors) < self::MAX_ERRORS) {
			$this->errors[] = ($pointer === '' ? '/' : $pointer) . ': ' . $message;
		}
	}
}
