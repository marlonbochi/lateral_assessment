<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\Json\JsonPayload;
use App\Support\Json\RawJson;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use stdClass;

/**
 * jsonb object column ⇄ RawJson, the document exactly as PostgreSQL stores it.
 *
 * Reading never decodes the payload in PHP, so numbers beyond int64 or double
 * precision stay exact, and saving a model never rewrites a payload with a
 * lossy re-encoding. Writes accept a RawJson or any value to encode (stdClass
 * keeps {} as an object, unlike the `array` cast).
 *
 * @implements CastsAttributes<RawJson|null, RawJson|stdClass|null>
 */
final class JsonObjectPayload implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?RawJson
    {
        if ($value === null) {
            return null;
        }

        $payload = new RawJson((string) $value);

        if (! $payload->isObject()) {
            throw new InvalidArgumentException("Column [{$key}] does not contain a JSON object.");
        }

        return $payload;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof RawJson => $value->json,
            default => JsonPayload::encode($value),
        };
    }
}
