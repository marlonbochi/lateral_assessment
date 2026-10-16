<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Shapes pagination metadata exactly as the OpenAPI contract
 * (CursorMeta / NumberedMeta / Links), instead of Laravel's default.
 */
abstract class PaginatedCollection extends ResourceCollection
{
    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        if (array_key_exists('next_cursor', $paginated)) {
            return [
                'meta' => [
                    'pagination' => 'cursor',
                    'per_page' => $paginated['per_page'],
                    'next_cursor' => $paginated['next_cursor'],
                    'prev_cursor' => $paginated['prev_cursor'],
                ],
                'links' => [
                    'prev' => $paginated['prev_page_url'],
                    'next' => $paginated['next_page_url'],
                ],
            ];
        }

        return [
            'meta' => [
                'pagination' => 'page',
                'current_page' => $paginated['current_page'],
                'per_page' => $paginated['per_page'],
                'total' => $paginated['total'],
                'last_page' => $paginated['last_page'],
            ],
            'links' => [
                'first' => $paginated['first_page_url'],
                'last' => $paginated['last_page_url'],
                'prev' => $paginated['prev_page_url'],
                'next' => $paginated['next_page_url'],
            ],
        ];
    }
}
