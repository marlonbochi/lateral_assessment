<?php

declare(strict_types=1);

use App\Http\Controllers\Cms\EventBatchController;
use App\Http\Middleware\EnsureJsonBody;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CMS webhook
|--------------------------------------------------------------------------
|
| Authenticated with HTTP Basic credentials from the environment only. It
| runs outside the `api` middleware group: no Sanctum, no consumer tokens.
| Rate limiting (throttle:cms-webhook) is applied once, in bootstrap/app.php.
|
*/

Route::post('/cms/events', EventBatchController::class)
    ->middleware(['auth.cms', EnsureJsonBody::class])
    ->name('cms.events.store');
