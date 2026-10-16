<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class ContentCollection extends PaginatedCollection
{
    public $collects = ContentResource::class;
}
