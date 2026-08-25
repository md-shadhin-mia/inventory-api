<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(
        'Feature/Auth',
        'Feature/Requests',
        'Feature/Services',
        'Feature/Inventory',
        'Feature/Events',
        'Feature/RateLimit',
        'Feature/Health',
    );

pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Feature/Concurrency');

pest()->extend(TestCase::class)
    ->in('Feature/Docs');

function flushRateLimiterState(): void
{
    Illuminate\Support\Facades\Cache::store()->flush();

    if (array_key_exists('redis', config('cache.stores', []))) {
        try {
            Illuminate\Support\Facades\Cache::store('redis')->flush();
        } catch (Throwable) {

        }
    }
}
