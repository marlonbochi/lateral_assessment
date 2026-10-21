<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * HTTP Basic authentication for the CMS webhook, against credentials supplied
 * only through environment configuration. These credentials are unrelated to
 * consumer tokens: the webhook accepts nothing else, and they open nothing else.
 */
final class AuthenticateCms
{
    private const string CHALLENGE = 'Basic realm="cms-webhook"';

    public function handle(Request $request, Closure $next): Response
    {
        $expectedUser = config('cms.webhook.username');
        $expectedPass = config('cms.webhook.password');

        if (! is_string($expectedUser) || $expectedUser === '' || ! is_string($expectedPass) || $expectedPass === '') {
            // Fail closed: never accept requests when the server is misconfigured.
            Log::critical('CMS webhook credentials are not configured; rejecting request.');

            throw $this->unauthorized();
        }

        [$user, $pass] = $this->credentials($request);

        // Compare both parts in constant time and only then branch, so timing
        // does not reveal which part was wrong.
        $userMatches = hash_equals($expectedUser, $user);
        $passMatches = hash_equals($expectedPass, $pass);

        if (! ($userMatches && $passMatches)) {
            throw $this->unauthorized();
        }

        return $next($request);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function credentials(Request $request): array
    {
        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with($header, 'Basic ')) {
            return ['', ''];
        }

        $decoded = base64_decode(substr($header, 6), true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return ['', ''];
        }

        [$user, $pass] = explode(':', $decoded, 2);

        return [$user, $pass];
    }

    private function unauthorized(): UnauthorizedHttpException
    {
        return new UnauthorizedHttpException(self::CHALLENGE, 'Authentication is required.');
    }
}
