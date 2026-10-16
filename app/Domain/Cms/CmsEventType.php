<?php

declare(strict_types=1);

namespace App\Domain\Cms;

use App\Enums\ContentStatus;

enum CmsEventType: string
{
    case Publish = 'publish';
    case Unpublish = 'unpublish';
    case Delete = 'delete';

    /**
     * The CMS status an entity has after this event is applied.
     */
    public function targetStatus(): ContentStatus
    {
        return match ($this) {
            self::Publish => ContentStatus::Published,
            self::Unpublish => ContentStatus::Unpublished,
            self::Delete => ContentStatus::Deleted,
        };
    }

    /**
     * Publish and unpublish events must carry a JSON object payload.
     */
    public function requiresPayload(): bool
    {
        return $this !== self::Delete;
    }
}
