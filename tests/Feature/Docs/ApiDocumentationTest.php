<?php

/*
 * Phase 8 — Swagger UI at /api/documentation.
 *
 * The OpenAPI document is hand-authored at openapi/openapi.yaml and SERVED by
 * l5-swagger (generate_always = false), so these tests guard the wiring: the UI
 * renders, the spec is reachable and parseable, both are public, and the spec
 * actually describes every route in routes/api.php.
 *
 * The last one is the important one — a hand-written spec is a second source of
 * truth, and this is what catches it drifting from the routes.
 */

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

it('serves the swagger ui page publicly', function () {
    $response = $this->get('/api/documentation');

    $response->assertOk();
    expect($response->getContent())->toContain('swagger-ui');
});

it('serves the openapi document publicly', function () {
    $this->get('/docs')->assertOk();
});

it('serves a parseable openapi document describing this api', function () {
    $spec = Yaml::parse($this->get('/docs')->getContent());

    expect($spec)->toHaveKeys(['openapi', 'info', 'paths', 'components'])
        ->and($spec['info']['title'])->toBe('Inventory Management API');
});

it('documents every versioned api route', function () {
    $documented = array_keys(Yaml::parse($this->get('/docs')->getContent())['paths']);

    $actual = collect(Route::getRoutes()->getRoutes())
        // Only GET/POST endpoints under api/v1 — skip the l5-swagger routes and
        // the framework's /up, neither of which belongs in the API reference.
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
        ->map(fn ($route) => '/'.str_replace('api/v1/', '', $route->uri()))
        ->unique()
        ->values()
        ->all();

    sort($actual);
    sort($documented);

    expect($documented)->toBe($actual);
});

it('redirects the root path to the swagger ui', function () {
    $this->get('/')->assertRedirect('/api/documentation');
});
