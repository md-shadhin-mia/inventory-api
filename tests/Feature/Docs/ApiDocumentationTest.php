<?php

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
