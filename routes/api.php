<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Consumer API (v1)
|--------------------------------------------------------------------------
|
| Every route requires a Sanctum bearer token. Visibility is role-aware and
| decided through the `admin` gate and the policies.
|
*/

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    //
});
