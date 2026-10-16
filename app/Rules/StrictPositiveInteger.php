<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A JSON integer between 1 and PHP_INT_MAX. Unlike Laravel's `integer` rule,
 * numeric strings ("2") and floats (2.0, 2.5) are rejected; integers beyond
 * int64 arrive as strings (JSON_BIGINT_AS_STRING) and are rejected too.
 */
final class StrictPositiveInteger implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) || $value < 1) {
            $fail('The :attribute must be a positive integer.');
        }
    }
}
