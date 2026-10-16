<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\ContentDisabledController;
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

    // Local override (administrators only): PUT disables, DELETE re-enables.
    Route::middleware('role:admin')->group(function (): void {
        Route::put('contents/{contentId}/disabled', [ContentDisabledController::class, 'update'])->name('contents.disabled.update');
        Route::delete('contents/{contentId}/disabled', [ContentDisabledController::class, 'destroy'])->name('contents.disabled.destroy');
    });
});
