<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Content;
use App\Models\EventBatch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every error on the API and webhook as RFC 9457 Problem Details
 * (application/problem+json). Internals are never exposed unless app.debug.
 */
final class ProblemDetailsRenderer
{
    public const string CONTENT_TYPE = 'application/problem+json';

    public static function appliesTo(Request $request): bool
    {
        return $request->is('api/*', 'cms/*', 'up');
    }

    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::appliesTo($request)) {
            return null;
        }

        [$status, $detail, $headers, $extra] = $this->describe($e, $request);

        $body = [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
            'instance' => '/'.ltrim($request->path(), '/'),
        ] + $extra;

        if ($status >= 500 && (bool) config('app.debug')) {
            $body['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
            ];
        }

        return new JsonResponse($body, $status, ['Content-Type' => self::CONTENT_TYPE] + $headers);
    }

    /**
     * @return array{0: int, 1: string, 2: array<string, string>, 3: array<string, mixed>}
     */
    private function describe(Throwable $e, Request $request): array
    {
        return match (true) {
            $e instanceof ValidationException => [
                422,
                $request->is('cms/*') ? 'The batch is invalid; no events were accepted.' : 'The given data was invalid.',
                [],
                ['errors' => $e->errors()],
            ],
            // Consumer routes authenticate with bearer tokens (RFC 6750 challenge);
            // the webhook throws its own Basic challenge from AuthenticateCms.
            $e instanceof AuthenticationException => [401, 'Authentication is required.', ['WWW-Authenticate' => 'Bearer'], []],
            $e instanceof BatchNotRetryableException => [409, $e->getMessage(), [], []],
            $e instanceof AuthorizationException => [403, $e->getMessage() ?: 'This action is unauthorized.', [], []],
            $e instanceof ModelNotFoundException => [404, $this->notFoundDetail($e->getModel()), [], []],
            // Laravel converts ModelNotFoundException into NotFoundHttpException
            // before render callbacks run; the original is kept as "previous".
            $e instanceof NotFoundHttpException => [
                404,
                $this->notFoundDetail($e->getPrevious() instanceof ModelNotFoundException ? $e->getPrevious()->getModel() : null),
                $this->headers($e),
                [],
            ],
            $e instanceof MethodNotAllowedHttpException => [
                405,
                'The method is not allowed for this resource.',
                $this->headers($e),
                [],
            ],
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                $e->getMessage() !== '' ? $e->getMessage() : (Response::$statusTexts[$e->getStatusCode()] ?? 'Error'),
                $this->headers($e),
                [],
            ],
            ($e instanceof QueryException || $e instanceof PDOException || $e instanceof QueueNotTransactionalException) && $request->is('cms/*') => [
                503,
                'The batch could not be recorded. Please retry the delivery.',
                ['Retry-After' => '5'],
                [],
            ],
            $e instanceof QueueNotTransactionalException => [
                503,
                'Batch processing is temporarily unavailable. Please retry later.',
                ['Retry-After' => '5'],
                [],
            ],
            default => [500, 'An unexpected error occurred.', [], []],
        };
    }

    private function notFoundDetail(?string $model): string
    {
        return match ($model) {
            Content::class => 'Content not found.',
            EventBatch::class => 'Event batch not found.',
            default => 'Resource not found.',
        };
    }

    /**
     * @return array<string, string>
     */
    private function headers(HttpExceptionInterface $e): array
    {
        $headers = [];

        foreach ($e->getHeaders() as $name => $value) {
            $headers[(string) $name] = is_array($value) ? implode(', ', array_map(strval(...), $value)) : (string) $value;
        }

        return $headers;
    }
}
