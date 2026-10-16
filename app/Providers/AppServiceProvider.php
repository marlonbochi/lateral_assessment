<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        $this->configureAuthorization();
        $this->configureRateLimiting();
    }

    /**
     * The `admin` gate is the single source of role decisions. User::isAdmin()
     * is called only here; everything else asks the gate.
     */
    private function configureAuthorization(): void
    {
        Gate::define('admin', fn (User $user): Response => $user->isAdmin()
            ? Response::allow()
            : Response::deny('This action requires the admin role.'));

        // Filtering the listing by CMS status or override flag is administrator-only.
        Gate::define('filter-contents', fn (User $user): Response => Gate::forUser($user)->inspect('admin'));
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
