<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('returns user payload and a sanctum token for valid credentials', function () {
    $user = User::factory()->warehouseManager()->create([
        'email' => 'manager@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'manager@example.com',
        'password' => 'secret-password',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'name', 'email', 'role'],
            'token',
        ])
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', 'manager@example.com')
        ->assertJsonPath('user.role', 'warehouse_manager');

    expect($response->json('token'))->toBeString()->not->toBeEmpty();

    // A persisted personal access token must exist for this user.
    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'tokenable_type' => User::class,
    ]);
});

it('issues a token that authenticates subsequent requests', function () {
    User::factory()->admin()->create([
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'secret-password',
    ])->json('token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'admin@example.com');
});

it('rejects invalid credentials with 401', function () {
    User::factory()->admin()->create([
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnauthorized()
        ->assertJsonStructure(['message']);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('rejects login for an unknown email with 401', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'whatever-password',
    ])->assertUnauthorized();
});

it('returns 422 when email is missing', function () {
    $this->postJson('/api/v1/auth/login', [
        'password' => 'secret-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('returns 422 when password is missing', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

it('returns 422 when email is malformed', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'not-an-email',
        'password' => 'secret-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});
