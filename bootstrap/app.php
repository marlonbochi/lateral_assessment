<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetailsRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateCms;
use App\Http\Middleware\EnsureRole;
use App\Support\Logging\SafeError;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
        then: function (): void {
            Route::middleware('throttle:cms-webhook')->group(base_path('routes/cms.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);

        // Stateless API: never redirect unauthenticated requests to a login page
        // (there is none), whatever the client's Accept header. The resulting
        // AuthenticationException is rendered as a 401 problem details response.
        $middleware->redirectGuestsTo(fn (): ?string => null);

        $middleware->alias([
            'auth.cms' => AuthenticateCms::class,
            'role' => EnsureRole::class,
        ]);

        // Authorize admin-only routes before any {model} binding is resolved,
        // so a regular user cannot probe which ids exist.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureRole::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => ProblemDetailsRenderer::appliesTo($request) || $request->expectsJson(),
        );

        // QueryException messages contain SQL bindings (the inbox INSERT binds the
        // whole batch). Report a sanitized record instead of the default one.
        $exceptions->report(function (QueryException $e): bool {
            Log::error('database.query_failed', [
                'sql' => $e->getSql(),
                'error' => SafeError::describe($e),
            ]);

            return false;
        });

        $exceptions->render(
            fn (Throwable $e, Request $request): ?JsonResponse => app(ProblemDetailsRenderer::class)->render($e, $request),
        );
    })->create();
