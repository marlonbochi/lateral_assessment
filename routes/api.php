<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ContentController;
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
    Route::pattern('contentId', '[A-Za-z0-9][A-Za-z0-9._:-]*');

    Route::get('contents', [ContentController::class, 'index'])->name('contents.index');
    Route::get('contents/{contentId}', [ContentController::class, 'show'])->name('contents.show');
});
