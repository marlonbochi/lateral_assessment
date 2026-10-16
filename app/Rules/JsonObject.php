<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use stdClass;

/**
 * The value, decoded with objects preserved, must be a JSON object ({...}).
 * Lists, scalars and null are rejected; an empty object {} is accepted.
 */
final class JsonObject implements ValidationRule
{
    public const string MESSAGE = 'The :attribute must be a JSON object.';

    public static function passes(mixed $value): bool
    {
        return $value instanceof stdClass;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::passes($value)) {
            $fail(self::MESSAGE);
        }
    }
}
