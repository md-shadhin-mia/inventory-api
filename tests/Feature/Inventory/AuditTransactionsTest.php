<?php

/*
 * Phase 4 — GET /api/v1/audit/transactions (auth:sanctum + role:admin,auditor).
 * Written FIRST; drives the read endpoint over `inventory_transactions`.
 *
 * The listener that WRITES audit rows is Phase 6, so rows are seeded here
 * directly via InventoryTransactionFactory — assertions stay minimal and
 * cover only the read contract + RBAC.
 */

use App\Models\InventoryTransaction;
use App\Models\User;

function auditTokenFor(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
}

it('returns seeded transaction rows to an auditor', function () {
    $auditor = User::factory()->auditor()->create();

    $tx = InventoryTransaction::factory()->create([
        'old_balance' => 10,
        'new_balance' => 3,
        'quantity_delta' => -7,
        'type' => 'adjustment',
    ]);

    $this->withHeaders(auditTokenFor($auditor))
        ->getJson('/api/v1/audit/transactions')
        ->assertOk()
        ->assertJsonFragment([
            'warehouse_id' => $tx->warehouse_id,
            'product_id' => $tx->product_id,
            'old_balance' => 10,
            'new_balance' => 3,
            'quantity_delta' => -7,
            'type' => 'adjustment',
        ]);
});

it('is readable by an admin', function () {
    $admin = User::factory()->admin()->create();
    InventoryTransaction::factory()->count(2)->create();

    $this->withHeaders(auditTokenFor($admin))
        ->getJson('/api/v1/audit/transactions')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('forbids a warehouse manager from the audit trail with 403', function () {
    $manager = User::factory()->warehouseManager()->create();

    $this->withHeaders(auditTokenFor($manager))
        ->getJson('/api/v1/audit/transactions')
        ->assertForbidden();
});

it('returns 401 for unauthenticated audit requests', function () {
    $this->getJson('/api/v1/audit/transactions')->assertUnauthorized();
});
