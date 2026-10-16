<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An RFC 3339 date-time with an explicit offset, e.g. 2026-01-01T10:00:00Z or
 * 2026-01-01T10:00:00.123+02:00. Dates without time or offset are rejected.
 */
final class Rfc3339DateTime implements ValidationRule
{
    private const string PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isValid($value)) {
            $fail('The :attribute must be an RFC 3339 date-time with a time zone offset.');
        }
    }

    private function isValid(string $value): bool
    {
        if (preg_match(self::PATTERN, $value, $m) !== 1) {
            return false;
        }

        [, $year, $month, $day, $hour, $minute, $second] = $m;

        return checkdate((int) $month, (int) $day, (int) $year)
            && (int) $hour < 24 && (int) $minute < 60 && (int) $second < 60
            && DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', "{$year}-{$month}-{$day}T{$hour}:{$minute}:{$second}") !== false;
    }
}
