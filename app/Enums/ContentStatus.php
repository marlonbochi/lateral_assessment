<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Publication state of a content item as last reported by the CMS.
 * `Deleted` rows are tombstones that keep the deletion version.
 */
enum ContentStatus: string
{
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Deleted = 'deleted';
}
