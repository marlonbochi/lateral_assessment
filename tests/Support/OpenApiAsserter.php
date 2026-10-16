<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates responses against specs/001-cms-content-sync/contracts/openapi.yaml.
 *
 * The usual PHP validators only support OpenAPI 3.0; the contract is 3.1, so
 * this implements the JSON Schema subset it uses: $ref, type (including type
 * arrays such as [string, 'null']), const, enum, required, properties, items,
 * minimum/maximum, minItems/maxItems, oneOf, anyOf and allOf. JSON objects are
 * checked as stdClass, so {} and [] are told apart.
 */
final class OpenApiAsserter
{
    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    public static function assertResponseMatches(string $method, string $pathTemplate, int $status, string $contentType, string $body): void
    {
        $spec = self::spec();
        $operation = $spec['paths'][$pathTemplate][strtolower($method)] ?? null;
        Assert::assertIsArray($operation, "Operation {$method} {$pathTemplate} is not documented.");

        $response = $operation['responses'][(string) $status] ?? null;
        Assert::assertIsArray($response, "Status {$status} is not documented for {$method} {$pathTemplate}.");
        $response = self::resolve($response);

        $mediaType = explode(';', $contentType)[0];
        $schema = $response['content'][$mediaType]['schema'] ?? null;
        Assert::assertIsArray($schema, "No {$mediaType} schema documented for {$status} on {$method} {$pathTemplate}.");

        $errors = self::validate(json_decode($body, false, 512, JSON_THROW_ON_ERROR), $schema, '$');

        Assert::assertSame([], $errors, "Response does not match the contract for {$method} {$pathTemplate} {$status}:\n".implode("\n", $errors));
    }

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        return self::$spec ??= Yaml::parseFile(dirname(__DIR__, 2).'/specs/001-cms-content-sync/contracts/openapi.yaml');
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function resolve(array $node): array
    {
        while (isset($node['$ref'])) {
            $node = self::pointer((string) $node['$ref']);
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private static function pointer(string $ref): array
    {
        $node = self::spec();
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $node = $node[str_replace(['~1', '~0'], ['/', '~'], $segment)];
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private static function validate(mixed $value, array $schema, string $path): array
    {
        $schema = self::resolve($schema);
        $errors = [];

        foreach ($schema['allOf'] ?? [] as $part) {
            $errors = [...$errors, ...self::validate($value, $part, $path)];
        }

        if (isset($schema['oneOf'])) {
            $matches = count(array_filter($schema['oneOf'], fn (array $s): bool => self::validate($value, $s, $path) === []));
            if ($matches !== 1) {
                $errors[] = "{$path}: matches {$matches} oneOf alternatives (expected exactly 1)";
            }
        }

        if (isset($schema['anyOf']) && array_filter($schema['anyOf'], fn (array $s): bool => self::validate($value, $s, $path) === []) === []) {
            $errors[] = "{$path}: matches no anyOf alternative";
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            if (! array_filter($types, fn (string $t): bool => self::isType($value, $t))) {
                return [...$errors, "{$path}: expected ".implode('|', $types).', got '.get_debug_type($value)];
            }
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = "{$path}: expected const ".json_encode($schema['const']);
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: ".json_encode($value).' is not one of '.json_encode($schema['enum']);
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path}: below minimum {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path}: above maximum {$schema['maximum']}";
            }
        }

        if (is_string($value) && ($schema['format'] ?? null) === 'date-time'
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            $errors[] = "{$path}: '{$value}' is not an RFC 3339 date-time";
        }

        if ($value instanceof stdClass) {
            foreach ($schema['required'] ?? [] as $property) {
                if (! property_exists($value, $property)) {
                    $errors[] = "{$path}: missing required property '{$property}'";
                }
            }
            foreach ($schema['properties'] ?? [] as $property => $propertySchema) {
                if (property_exists($value, $property)) {
                    $errors = [...$errors, ...self::validate($value->{$property}, $propertySchema, "{$path}.{$property}")];
                }
            }
        }

        if (is_array($value)) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                $errors[] = "{$path}: fewer than {$schema['minItems']} items";
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = "{$path}: more than {$schema['maxItems']} items";
            }
            if (isset($schema['items'])) {
                foreach ($value as $i => $item) {
                    $errors = [...$errors, ...self::validate($item, $schema['items'], "{$path}[{$i}]")];
                }
            }
        }

        return $errors;
    }

    private static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => $value instanceof stdClass,
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }
}
