<?php

/*
 * Phase 8 — GET /api/v1/health.
 *
 * A public health endpoint that actually probes the dependencies the API needs
 * (PostgreSQL and the cache store), so container orchestrators get a signal
 * that means something rather than "PHP is running".
 *
 * Contract: 200 { status: "ok", services: { database: "ok", cache: "ok" } }
 * when everything responds, 503 with status "degraded" when a probe throws.
 */

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
    // No Authorization header at all — a health probe must never need a token.
    $this->getJson('/api/v1/health')->assertOk();
});

it('is reachable without a json accept header', function () {
    // Orchestrator probes (wget/curl in a healthcheck) do not send Accept.
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
        // The healthy dependency must still be reported as healthy.
        ->assertJsonPath('services.database', 'ok');
});
