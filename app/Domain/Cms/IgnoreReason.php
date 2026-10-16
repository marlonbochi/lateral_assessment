<?php

declare(strict_types=1);

namespace App\Domain\Cms;

/**
 * Why an event was not applied. Precedence is decided by version only.
 */
enum IgnoreReason: string
{
    /** Same version as the latest accepted one (replay / redelivery). */
    case Duplicate = 'duplicate';

    /** Lower version than the latest accepted one (out of order). */
    case Stale = 'stale';

    /** Lower version than a recorded deletion: must never restore content. */
    case StaleAfterDelete = 'stale_after_delete';
}
