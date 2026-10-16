<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Role of a consumer account. Authorization decisions go through the `admin`
 * gate (see AppServiceProvider), never through direct comparisons elsewhere.
 */
enum Role: string
{
    case User = 'user';
    case Admin = 'admin';
}
