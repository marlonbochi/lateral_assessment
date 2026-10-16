<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level guard (`role:admin`) that runs before route-model binding, so a
 * regular user gets 403 for an admin operation whether or not the target
 * exists. It only adapts the gate: no role comparison happens here.
 */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        Gate::authorize($ability);

        return $next($request);
    }
}
