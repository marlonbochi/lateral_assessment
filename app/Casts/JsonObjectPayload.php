<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\Json\JsonPayload;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use stdClass;

/**
 * jsonb object column ⇄ stdClass, preserving {} vs [] (unlike the `array` cast).
 *
 * @implements CastsAttributes<stdClass|null, stdClass|null>
 */
final class JsonObjectPayload implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?stdClass
    {
        if ($value === null) {
            return null;
        }

        $decoded = JsonPayload::decode((string) $value);

        if (! $decoded instanceof stdClass) {
            throw new InvalidArgumentException("Column [{$key}] does not contain a JSON object.");
        }

        return $decoded;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : JsonPayload::encode($value);
    }
}
