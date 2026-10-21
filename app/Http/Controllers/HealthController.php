<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Liveness check. Like every other endpoint it requires an authenticated
 * consumer (the brief requires all endpoints to be protected).
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'up']);
    }
}
