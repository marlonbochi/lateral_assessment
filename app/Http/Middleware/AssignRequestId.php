<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlates a request with its logs. A well-formed incoming X-Request-Id is
 * reused, anything else is replaced. The id is put in Laravel's Context, so it
 * is attached to every log record and travels with queued jobs to the worker.
 */
final class AssignRequestId
{
    private const string HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $id = is_string($incoming) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid7();

        $request->attributes->set('request_id', $id);
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
