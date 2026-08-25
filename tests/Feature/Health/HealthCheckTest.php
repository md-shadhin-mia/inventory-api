<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('reports ok with per service detail', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('services.database', 'ok')
        ->assertJsonPath('services.cache', 'ok');
});

it('is reachable without authentication', function () {

    $this->getJson('/api/v1/health')->assertOk();
});

it('is reachable without a json accept header', function () {

    $this->get('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});

it('is readable by an authenticated user of any role', function (string $state) {
    $user = User::factory()->{$state}()->create();

    $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken])
        ->getJson('/api/v1/health')
        ->assertOk();
})->with(['admin', 'warehouseManager', 'auditor']);

it('reports 503 and degraded when a dependency probe fails', function () {
    Cache::shouldReceive('store')->andThrow(new RuntimeException('cache down'));

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('services.cache', 'error')

        ->assertJsonPath('services.database', 'ok');
});
