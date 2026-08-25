<?php

use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;

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

it('exposes a real adjustment written by the audit listener to an auditor', function () {
    $manager = User::factory()->warehouseManager()->create();
    $auditor = User::factory()->auditor()->create();

    $inventory = Inventory::factory()->create([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => Product::factory()->create(['reorder_threshold' => 1])->id,
        'quantity' => 10,
    ]);

    $this->withHeaders(auditTokenFor($manager))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => -7,
            'reason' => 'shrinkage',
        ])
        ->assertOk();

    $this->withHeaders(auditTokenFor($auditor))
        ->getJson('/api/v1/audit/transactions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user_id', $manager->id)
        ->assertJsonPath('data.0.warehouse_id', $inventory->warehouse_id)
        ->assertJsonPath('data.0.product_id', $inventory->product_id)
        ->assertJsonPath('data.0.old_balance', 10)
        ->assertJsonPath('data.0.new_balance', 3)
        ->assertJsonPath('data.0.quantity_delta', -7)
        ->assertJsonPath('data.0.type', 'adjustment')
        ->assertJsonPath('data.0.reason', 'shrinkage');
});

it('returns 401 for unauthenticated audit requests', function () {
    $this->getJson('/api/v1/audit/transactions')->assertUnauthorized();
});
