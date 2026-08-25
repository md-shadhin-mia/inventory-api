<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {

    Route::middleware(['auth:sanctum', 'role:admin,warehouse_manager'])
        ->post('/api/v1/test/stock-write', fn () => response()->json(['ok' => true]));

    Route::middleware(['auth:sanctum', 'role:admin,auditor'])
        ->get('/api/v1/test/audit-read', fn () => response()->json(['ok' => true]));
});

function actingAsRole(string $role): User
{
    return User::factory()->{$role}()->create();
}

it('persists the role column for each factory role state', function (string $state, string $role) {
    $user = User::factory()->{$state}()->create();

    expect($user->fresh()->role)->toBe($role);

    test()->assertDatabaseHas('users', [
        'id' => $user->id,
        'role' => $role,
    ]);
})->with([
    ['admin', 'admin'],
    ['warehouseManager', 'warehouse_manager'],
    ['auditor', 'auditor'],
]);

it('forbids an auditor from stock writes with 403 JSON', function () {
    $auditor = actingAsRole('auditor');
    $token = $auditor->createToken('api')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/test/stock-write')
        ->assertForbidden()
        ->assertJsonStructure(['message']);
});

it('allows a warehouse manager to perform stock writes', function () {
    $manager = actingAsRole('warehouseManager');
    $token = $manager->createToken('api')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/test/stock-write')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('allows an admin to perform stock writes', function () {
    $admin = actingAsRole('admin');
    $token = $admin->createToken('api')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/test/stock-write')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('allows an auditor to read the audit trail', function () {
    $auditor = actingAsRole('auditor');
    $token = $auditor->createToken('api')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/test/audit-read')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('forbids a warehouse manager from the audit trail with 403', function () {
    $manager = actingAsRole('warehouseManager');
    $token = $manager->createToken('api')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/test/audit-read')
        ->assertForbidden();
});

it('allows an admin to read the audit trail', function () {
    $admin = actingAsRole('admin');
    $token = $admin->createToken('api')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/test/audit-read')
        ->assertOk();
});

it('returns 401 JSON for unauthenticated access to a role-protected route', function () {
    $this->postJson('/api/v1/test/stock-write')
        ->assertUnauthorized()
        ->assertJsonStructure(['message']);
});
