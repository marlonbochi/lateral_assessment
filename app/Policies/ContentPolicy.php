<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Content;
use App\Models\User;

final class ContentPolicy
{
    /**
     * Any authenticated consumer may list content; which rows they see is
     * decided by Content::visibleTo() (through the `admin` gate).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Content $content): bool
    {
        return true;
    }

    /**
     * Local disable / re-enable is an administrator-only operation.
     */
    public function manageOverride(User $user, Content $content): bool
    {
        return $user->can('admin');
    }
}
