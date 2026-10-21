<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\EmbedsRawJson;

final class ContentCollection extends PaginatedCollection
{
    use EmbedsRawJson;

    public $collects = ContentResource::class;
}
