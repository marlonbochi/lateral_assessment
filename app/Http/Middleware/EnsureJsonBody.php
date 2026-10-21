<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * The webhook only accepts JSON bodies up to the configured size, so that
 * oversized or non-JSON deliveries fail fast with problem details.
 */
final class EnsureJsonBody
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isJson()) {
            throw new UnsupportedMediaTypeHttpException('The request body must be application/json.');
        }

        $max = (int) config('cms.webhook.max_body_bytes');
        $declared = (int) $request->headers->get('Content-Length', '0');

        if ($declared > $max || strlen($request->getContent()) > $max) {
            throw new HttpException(413, "The request body must not exceed {$max} bytes.");
        }

        return $next($request);
    }
}
