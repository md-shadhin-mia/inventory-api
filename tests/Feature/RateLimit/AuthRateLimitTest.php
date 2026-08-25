<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    flushRateLimiterState();
});

it('allows five login attempts per minute from the same IP', function () {
    User::factory()->admin()->create([
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    foreach (range(1, 5) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@example.com',
                'password' => 'secret-password',
            ])
            ->assertOk();
    }
});

it('blocks the sixth login attempt within a minute with 429 and a Retry-After header', function () {
    User::factory()->admin()->create([
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    foreach (range(1, 5) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@example.com',
                'password' => 'secret-password',
            ])
            ->assertOk();
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ]);

    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('message', 'Too many requests');

    expect($response->json('retry_after'))->toBeInt()->toBeGreaterThan(0);
    expect((int) $response->headers->get('Retry-After'))
        ->toBe($response->json('retry_after'));
});

it('counts failed login attempts towards the auth limit', function () {
    User::factory()->admin()->create([
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    foreach (range(1, 5) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@example.com',
                'password' => 'wrong-password',
            ])
            ->assertUnauthorized();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ])
        ->assertStatus(429);
});

it('keys the auth limiter per IP so another client is not blocked', function () {
    User::factory()->admin()->create([
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    foreach (range(1, 6) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@example.com',
                'password' => 'secret-password',
            ]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ])
        ->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ])
        ->assertOk();
});

it('does not rate limit authenticated auth endpoints with the login limiter', function () {
    $user = User::factory()->admin()->create();
    $headers = ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];

    foreach (range(1, 8) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->withHeaders($headers)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }
});
