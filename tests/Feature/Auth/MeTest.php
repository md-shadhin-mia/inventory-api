<?php

use App\Models\User;

it('returns the authenticated user with role', function () {
    $user = User::factory()->auditor()->create([
        'email' => 'auditor@example.com',
    ]);
    $token = $user->createToken('api')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', 'auditor@example.com')
        ->assertJsonPath('user.role', 'auditor');
});

it('does not expose the password hash in the payload', function () {
    $user = User::factory()->admin()->create();
    $token = $user->createToken('api')->plainTextToken;

    $json = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->json();

    expect(json_encode($json))->not->toContain('password');
});

it('returns a standardized 401 JSON error when unauthenticated', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message']);
});

it('returns 401 for an invalid bearer token', function () {
    $this->withHeader('Authorization', 'Bearer definitely-not-a-real-token')
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});
