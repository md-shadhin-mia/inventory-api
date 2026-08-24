<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Guards cache the first user they authenticate. When several requests
        // are handled by the same PHP process, the cached user would leak into
        // the next request and the mutation limiter would key every caller to
        // the same identity, so guards are dropped whenever a request is bound.
        $this->app->rebinding('request', fn ($app) => $app['auth']->forgetGuards());

        RateLimiter::for('auth-limiter', fn (Request $request) => Limit::perMinute(5)
            ->by($request->ip())
            ->response($this->tooManyRequests()));

        RateLimiter::for('inventory-mutation', fn (Request $request) => Limit::perMinute(60)
            ->by((string) $request->user()?->id ?: $request->ip())
            ->response($this->tooManyRequests()));
    }

    /**
     * Standardized 429 response shared by every named limiter.
     */
    protected function tooManyRequests(): callable
    {
        return fn (Request $request, array $headers) => response()->json([
            'message' => 'Too many requests',
            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
        ], 429, $headers);
    }
}
