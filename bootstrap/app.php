<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetailsRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => ProblemDetailsRenderer::appliesTo($request) || $request->expectsJson(),
        );

        $exceptions->render(
            fn (Throwable $e, Request $request): ?JsonResponse => app(ProblemDetailsRenderer::class)->render($e, $request),
        );
    })->create();
