<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{

    public function register(): void
    {

    }

    public function boot(): void
    {

        $this->app->rebinding('request', fn ($app) => $app['auth']->forgetGuards());

        RateLimiter::for('auth-limiter', fn (Request $request) => Limit::perMinute(5)
            ->by($request->ip())
            ->response($this->tooManyRequests()));

        RateLimiter::for('inventory-mutation', fn (Request $request) => Limit::perMinute(60)
            ->by((string) $request->user()?->id ?: $request->ip())
            ->response($this->tooManyRequests()));
    }

    protected function tooManyRequests(): callable
    {
        return fn (Request $request, array $headers) => response()->json([
            'message' => 'Too many requests',
            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
        ], 429, $headers);
    }
}
