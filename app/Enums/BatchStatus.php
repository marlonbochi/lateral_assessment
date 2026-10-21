<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an acknowledged CMS event batch in the inbox.
 */
enum BatchStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';
}
