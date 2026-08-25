<?php

use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;

function auditListenerTokenFor(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
}

function seedAuditListenerInventory(int $quantity = 10): Inventory
{
    return Inventory::factory()->create([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => Product::factory()->create(['reorder_threshold' => 1])->id,
        'quantity' => $quantity,
    ]);
}

it('writes one immutable audit row for an adjustment', function () {
    $manager = User::factory()->warehouseManager()->create();
    $inventory = seedAuditListenerInventory(10);

    $this->withHeaders(auditListenerTokenFor($manager))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => -7,
            'reason' => 'shrinkage',
        ])
        ->assertOk();

    $this->assertDatabaseCount('inventory_transactions', 1);

    $this->assertDatabaseHas('inventory_transactions', [
        'user_id' => $manager->id,
        'warehouse_id' => $inventory->warehouse_id,
        'product_id' => $inventory->product_id,
        'old_balance' => 10,
        'new_balance' => 3,
        'quantity_delta' => -7,
        'type' => 'adjustment',
        'reason' => 'shrinkage',
    ]);

    $row = InventoryTransaction::query()->sole();

    expect($row->created_at)->not->toBeNull()
        ->and($row->updated_at)->toBeNull();
});

it('writes exactly two audit rows with signed deltas for a transfer', function () {
    $manager = User::factory()->warehouseManager()->create();
    $product = Product::factory()->create(['reorder_threshold' => 1]);

    $source = Inventory::factory()->create([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => $product->id,
        'quantity' => 30,
    ]);

    $target = Inventory::factory()->create([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => $product->id,
        'quantity' => 20,
    ]);

    $this->withHeaders(auditListenerTokenFor($manager))
        ->postJson('/api/v1/inventory/transfer', [
            'source_warehouse_id' => $source->warehouse_id,
            'target_warehouse_id' => $target->warehouse_id,
            'product_id' => $product->id,
            'quantity' => 10,
        ])
        ->assertOk();

    $this->assertDatabaseCount('inventory_transactions', 2);

    $this->assertDatabaseHas('inventory_transactions', [
        'user_id' => $manager->id,
        'warehouse_id' => $source->warehouse_id,
        'product_id' => $product->id,
        'old_balance' => 30,
        'new_balance' => 20,
        'quantity_delta' => -10,
        'type' => 'transfer_out',
    ]);

    $this->assertDatabaseHas('inventory_transactions', [
        'user_id' => $manager->id,
        'warehouse_id' => $target->warehouse_id,
        'product_id' => $product->id,
        'old_balance' => 20,
        'new_balance' => 30,
        'quantity_delta' => 10,
        'type' => 'transfer_in',
    ]);
});

it('writes no audit row for a rejected adjustment', function () {
    $manager = User::factory()->warehouseManager()->create();
    $inventory = seedAuditListenerInventory(10);

    $this->withHeaders(auditListenerTokenFor($manager))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => -11,
            'reason' => 'oversized write-off',
        ])
        ->assertStatus(422);

    $this->assertDatabaseCount('inventory_transactions', 0);
});
