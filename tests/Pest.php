<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the framework and run against the real PostgreSQL
| `inventory_testing` database (see phpunit.xml), refreshed per test.
|
| Exception: tests under Feature/Concurrency use DatabaseTruncation instead
| of RefreshDatabase. RefreshDatabase wraps each test in a never-committed
| transaction, which makes seeded rows invisible to the parallel PHP
| processes those tests spawn; DatabaseTruncation commits for real and
| truncates between tests.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(
        'Feature/Auth',
        'Feature/Requests',
        'Feature/Services',
        'Feature/Inventory',
        'Feature/RateLimit',
        'Feature/ExampleTest.php',
    );

pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Feature/Concurrency');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Reset all rate limiter counters so throttling tests are deterministic.
 *
 * The limiter counts through the cache; in production that is Redis, in the
 * test env it may be the array store. Both are cleared here.
 */
function flushRateLimiterState(): void
{
    Illuminate\Support\Facades\Cache::store()->flush();

    if (array_key_exists('redis', config('cache.stores', []))) {
        try {
            Illuminate\Support\Facades\Cache::store('redis')->flush();
        } catch (Throwable) {
            // Redis unavailable — the default store flush above is enough.
        }
    }
}
