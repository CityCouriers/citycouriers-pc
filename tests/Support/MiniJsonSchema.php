<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

/**
 * Minimalny walidator JSON Schema na potrzeby testów kontraktu (bez zależności dev). Obsługuje wyłącznie
 * słowa kluczowe użyte w docs/schema/pc-run-1.json: $ref, type, enum, const, required, properties,
 * additionalProperties, items, minimum, minLength, minItems, maxItems, pattern, oneOf, allOf, if/then.
 */
final class MiniJsonSchema
{
    /** @var array<string, mixed> */
    private array $root;

    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(array $schema)
    {
        $this->root = $schema;
    }

    public static function forRun(): self
    {
        $json = file_get_contents(__DIR__ . '/../../docs/schema/pc-run-1.json');
        /** @var array<string, mixed> $schema */
        $schema = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);

        return new self($schema);
    }

    /**
     * @return list<string> lista błędów, pusta gdy dokument jest poprawny
     */
    public function validate(mixed $value): array
    {
        return $this->check($value, $this->root, '$');
    }

    /**
     * @param array<mixed> $schema
     *
     * @return list<string>
     */
    private function check(mixed $value, array $schema, string $path): array
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            return $this->check($value, $this->resolve($schema['$ref']), $path);
        }

        $errors = [];
        foreach ($schema as $keyword => $argument) {
            $errors = [...$errors, ...$this->keyword((string) $keyword, $argument, $value, $schema, $path)];
        }

        return $errors;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return list<string>
     */
    private function keyword(string $keyword, mixed $argument, mixed $value, array $schema, string $path): array
    {
        return match ($keyword) {
            'type' => $this->matchesType($value, $argument) ? [] : [sprintf('%s: zły typ', $path)],
            'enum' => in_array($value, (array) $argument, true) ? [] : [sprintf('%s: wartość spoza zbioru', $path)],
            'const' => $value === $argument ? [] : [sprintf('%s: wartość inna niż stała', $path)],
            'minimum' => !is_int($value) || $value >= $argument ? [] : [sprintf('%s: poniżej minimum', $path)],
            'minLength' => !is_string($value) || mb_strlen($value) >= $argument ? [] : [sprintf('%s: za krótki tekst', $path)],
            'pattern' => !is_string($value) || !is_string($argument) || preg_match('~' . $argument . '~', $value) === 1 ? [] : [sprintf('%s: zły format', $path)],
            'minItems' => !is_array($value) || count($value) >= $argument ? [] : [sprintf('%s: za mało elementów', $path)],
            'maxItems' => !is_array($value) || count($value) <= $argument ? [] : [sprintf('%s: za dużo elementów', $path)],
            'required' => $this->required($value, (array) $argument, $path),
            'properties' => $this->properties($value, (array) $argument, $path),
            'additionalProperties' => $this->additional($value, $argument, $schema, $path),
            'items' => $this->items($value, (array) $argument, $path),
            'oneOf' => $this->oneOf($value, (array) $argument, $path),
            'allOf' => $this->allOf($value, (array) $argument, $path),
            default => [],
        };
    }

    private function matchesType(mixed $value, mixed $type): bool
    {
        foreach ((array) $type as $name) {
            $matches = match ($name) {
                'null' => $value === null,
                'string' => is_string($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && !array_is_list($value) || $value === [],
                default => false,
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $required
     *
     * @return list<string>
     */
    private function required(mixed $value, array $required, string $path): array
    {
        if (!is_array($value)) {
            return [];
        }
        $errors = [];
        foreach ($required as $key) {
            if (is_string($key) && !array_key_exists($key, $value)) {
                $errors[] = sprintf('%s: brak wymaganego pola %s', $path, $key);
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $properties
     *
     * @return list<string>
     */
    private function properties(mixed $value, array $properties, string $path): array
    {
        if (!is_array($value)) {
            return [];
        }
        $errors = [];
        foreach ($properties as $key => $subschema) {
            if (array_key_exists($key, $value) && is_array($subschema)) {
                $errors = [...$errors, ...$this->check($value[$key], $subschema, $path . '.' . $key)];
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return list<string>
     */
    private function additional(mixed $value, mixed $argument, array $schema, string $path): array
    {
        if ($argument !== false || !is_array($value)) {
            return [];
        }
        $known = array_keys(is_array($schema['properties'] ?? null) ? $schema['properties'] : []);
        $errors = [];
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $known, true)) {
                $errors[] = sprintf('%s: nieznane pole %s', $path, $key);
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $items
     *
     * @return list<string>
     */
    private function items(mixed $value, array $items, string $path): array
    {
        if (!is_array($value)) {
            return [];
        }
        $errors = [];
        foreach ($value as $index => $item) {
            $errors = [...$errors, ...$this->check($item, $items, $path . '[' . $index . ']')];
        }

        return $errors;
    }

    /**
     * @param array<mixed> $options
     *
     * @return list<string>
     */
    private function oneOf(mixed $value, array $options, string $path): array
    {
        $matching = 0;
        foreach ($options as $option) {
            if (is_array($option) && $this->check($value, $option, $path) === []) {
                ++$matching;
            }
        }

        return $matching === 1 ? [] : [sprintf('%s: oneOf dopasowało %d wariantów', $path, $matching)];
    }

    /**
     * @param array<mixed> $branches
     *
     * @return list<string>
     */
    private function allOf(mixed $value, array $branches, string $path): array
    {
        $errors = [];
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $condition = $branch['if'] ?? null;
            if (is_array($condition) && $this->check($value, $condition, $path) !== []) {
                continue;
            }
            $consequence = $branch['then'] ?? $branch;
            if (is_array($consequence)) {
                $errors = [...$errors, ...$this->check($value, $consequence, $path)];
            }
        }

        return $errors;
    }

    /**
     * @return array<mixed>
     */
    private function resolve(string $reference): array
    {
        $node = $this->root;
        foreach (explode('/', ltrim($reference, '#/')) as $segment) {
            $child = $node[$segment] ?? null;
            $node = is_array($child) ? $child : [];
        }

        return $node;
    }
}
