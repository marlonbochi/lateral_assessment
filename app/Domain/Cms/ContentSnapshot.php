<?php

declare(strict_types=1);

namespace App\Domain\Cms;

use App\Enums\ContentStatus;

/**
 * The part of a stored content item that event precedence depends on.
 */
final readonly class ContentSnapshot
{
    public function __construct(
        public int $version,
        public ContentStatus $status,
    ) {}
}
