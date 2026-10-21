<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use DateTimeImmutable;
use Exception;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An RFC 3339 date-time with an explicit offset, e.g. 2026-01-01T10:00:00Z or
 * 2026-01-01T10:00:00.123+02:00. Dates without time or offset are rejected,
 * and so are offsets outside the RFC 3339 range (hh 00-23, mm 00-59): every
 * accepted value must also parse when the worker builds the event.
 */
final class Rfc3339DateTime implements ValidationRule
{
    private const string PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,6})?(?:Z|[+-](\d{2}):(\d{2}))$/';

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
        $offsetHour = (int) ($m[8] ?? 0);
        $offsetMinute = (int) ($m[9] ?? 0);

        return checkdate((int) $month, (int) $day, (int) $year)
            && (int) $hour < 24 && (int) $minute < 60 && (int) $second < 60
            && $offsetHour < 24 && $offsetMinute < 60
            && $this->parses($value);
    }

    /**
     * The same parser CmsEvent uses, so validation and processing never disagree.
     */
    private function parses(string $value): bool
    {
        try {
            new DateTimeImmutable($value);
        } catch (Exception) {
            return false;
        }

        return true;
    }
}
