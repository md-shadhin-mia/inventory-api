<?php

use App\Models\User;

it('revokes the current token on logout', function () {
    $user = User::factory()->admin()->create();
    $token = $user->createToken('api')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('returns 401 when the revoked token is used again', function () {
    $user = User::factory()->warehouseManager()->create();
    $token = $user->createToken('api')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    // Fresh request with the same (now revoked) token must be rejected.
    $this->flushHeaders();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

it('only revokes the current token, not the user\'s other tokens', function () {
    $user = User::factory()->admin()->create();
    $tokenA = $user->createToken('device-a')->plainTextToken;
    $tokenB = $user->createToken('device-b')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    $this->flushHeaders();

    $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->getJson('/api/v1/auth/me')
        ->assertOk();
});

it('rejects unauthenticated logout with 401 JSON', function () {
    $this->postJson('/api/v1/auth/logout')
        ->assertUnauthorized()
        ->assertJsonStructure(['message']);
});
