<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('cms-webhook', fn (Request $request): Limit => Limit::perMinute(
            (int) config('cms.webhook.rate_limit_per_minute'),
        )->by('cms:'.$request->ip()));

        RateLimiter::for('api', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $key = $token !== null && isset($token->id) ? 'token:'.$token->id : 'ip:'.$request->ip();

            return Limit::perMinute((int) config('cms.api.rate_limit_per_minute'))->by($key);
        });
    }
}
