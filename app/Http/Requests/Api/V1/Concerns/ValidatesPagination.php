<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Cursor pagination by default; page-number pagination (with totals) when
 * `page` is sent. Bounded page size and tamper-proof cursor validation.
 */
trait ValidatesPagination
{
    public const int DEFAULT_PER_PAGE = 15;

    public const int MAX_PER_PAGE = 100;

    public function perPage(): int
    {
        return $this->integer('per_page', self::DEFAULT_PER_PAGE);
    }

    public function usesPageNumbers(): bool
    {
        return $this->filled('page');
    }

    /**
     * @return array<string, mixed>
     */
    protected function paginationRules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:1024', 'prohibits:page'],
            'page' => ['sometimes', 'integer', 'min:1', 'prohibits:cursor'],
        ];
    }

    /**
     * A cursor must decode to exactly the feed's order columns, with values of
     * the right type, so a tampered cursor is a 422 and never a SQL error.
     *
     * @param  list<string>  $columns
     */
    protected function cursorValidator(array $columns): callable
    {
        return function (Validator $validator) use ($columns): void {
            $encoded = $this->query('cursor');

            if (! is_string($encoded) || $validator->errors()->has('cursor')) {
                return;
            }

            if (! $this->cursorIsValid($encoded, $columns)) {
                $validator->errors()->add('cursor', 'The cursor is invalid.');
            }
        };
    }

    /**
     * @param  list<string>  $columns
     */
    private function cursorIsValid(string $encoded, array $columns): bool
    {
        $cursor = Cursor::fromEncoded($encoded);

        if ($cursor === null) {
            return false;
        }

        $parameters = $cursor->toArray();
        $expected = [...$columns, '_pointsToNextItems'];
        sort($expected);
        $actual = array_keys($parameters);
        sort($actual);

        if ($actual !== $expected || ! is_bool($parameters['_pointsToNextItems'])) {
            return false;
        }

        foreach ($columns as $column) {
            $value = $parameters[$column];

            if (! is_string($value) || $value === '') {
                return false;
            }

            if (str_ends_with($column, '_at') && ! $this->isDate($value)) {
                return false;
            }
        }

        return true;
    }

    private function isDate(string $value): bool
    {
        try {
            CarbonImmutable::parse($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
