<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\Json\JsonPayload;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use stdClass;

/**
 * jsonb array column ⇄ list<stdClass>, preserving {} vs [] in nested values.
 *
 * A raw JSON string is stored as-is, so a request body can be persisted
 * without ever being re-encoded by PHP (exact numbers and structure).
 *
 * @implements CastsAttributes<list<stdClass>, list<stdClass>|string>
 */
final class JsonObjectList implements CastsAttributes
{
    /**
     * @return list<stdClass>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $decoded = JsonPayload::decode((string) $value);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new InvalidArgumentException("Column [{$key}] does not contain a JSON list.");
        }

        return array_values(array_filter($decoded, fn (mixed $item): bool => $item instanceof stdClass));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return is_string($value) ? $value : JsonPayload::encode($value);
    }
}
