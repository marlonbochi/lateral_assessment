<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EventBatch;
use App\Models\User;

/**
 * Event batches are an operational resource: administrators only.
 */
final class EventBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin');
    }

    public function view(User $user, EventBatch $batch): bool
    {
        return $user->can('admin');
    }

    public function retry(User $user, EventBatch $batch): bool
    {
        return $user->can('admin');
    }
}
