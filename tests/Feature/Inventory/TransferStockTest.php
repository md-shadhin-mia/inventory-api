<?php

/*
 * Phase 4 test #6 — transfer atomicity, written FIRST. Drives:
 *   - POST /api/v1/inventory/transfer (auth:sanctum + role:admin,warehouse_manager)
 *   - single transaction moving -Q from source and +Q to target, or full rollback
 *
 * Insufficient source stock uses the same chosen status as adjust: 422.
 * "Transfer to the same warehouse → 422" is already covered by the Phase 3
 * TransferStockRequest validation tests and is not duplicated here.
 */

use App\Events\StockLevelChangedEvent;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Event;

function transferTokenFor(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
}

/**
 * @return array{product: \App\Models\Product, source: \App\Models\Inventory, target: \App\Models\Inventory}
 */
function seedTransferPair(int $sourceQty, int $targetQty): array
{
    $product = Product::factory()->create();

    $source = Inventory::factory()->create([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => $product->id,
        'quantity' => $sourceQty,
    ]);

    $target = Inventory::factory()->create([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => $product->id,
        'quantity' => $targetQty,
    ]);

    return ['product' => $product, 'source' => $source, 'target' => $target];
}

it('moves quantity from source to target atomically for a manager', function () {
    $manager = User::factory()->warehouseManager()->create();
    ['product' => $product, 'source' => $source, 'target' => $target] = seedTransferPair(50, 5);

    $this->withHeaders(transferTokenFor($manager))
        ->postJson('/api/v1/inventory/transfer', [
            'source_warehouse_id' => $source->warehouse_id,
            'target_warehouse_id' => $target->warehouse_id,
            'product_id' => $product->id,
            'quantity' => 20,
        ])
        ->assertOk();

    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $source->warehouse_id,
        'product_id' => $product->id,
        'quantity' => 30,
    ]);

    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $target->warehouse_id,
        'product_id' => $product->id,
        'quantity' => 25,
    ]);
});

it('rejects a transfer exceeding source stock with 422 and leaves BOTH rows unchanged', function () {
    $manager = User::factory()->warehouseManager()->create();
    ['product' => $product, 'source' => $source, 'target' => $target] = seedTransferPair(10, 5);

    $this->withHeaders(transferTokenFor($manager))
        ->postJson('/api/v1/inventory/transfer', [
            'source_warehouse_id' => $source->warehouse_id,
            'target_warehouse_id' => $target->warehouse_id,
            'product_id' => $product->id,
            'quantity' => 11,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['message']);

    // Full rollback: neither the -Q nor the +Q side may be applied.
    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $source->warehouse_id,
        'product_id' => $product->id,
        'quantity' => 10,
    ]);

    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $target->warehouse_id,
        'product_id' => $product->id,
        'quantity' => 5,
    ]);
});

it('does not dispatch StockLevelChangedEvent for a rejected transfer', function () {
    Event::fake([StockLevelChangedEvent::class]);

    $manager = User::factory()->warehouseManager()->create();
    ['product' => $product, 'source' => $source, 'target' => $target] = seedTransferPair(10, 5);

    $this->withHeaders(transferTokenFor($manager))
        ->postJson('/api/v1/inventory/transfer', [
            'source_warehouse_id' => $source->warehouse_id,
            'target_warehouse_id' => $target->warehouse_id,
            'product_id' => $product->id,
            'quantity' => 999,
        ])
        ->assertStatus(422);

    Event::assertNotDispatched(StockLevelChangedEvent::class);
});

it('forbids an auditor from transferring stock with 403', function () {
    $auditor = User::factory()->auditor()->create();
    ['product' => $product, 'source' => $source, 'target' => $target] = seedTransferPair(50, 5);

    $this->withHeaders(transferTokenFor($auditor))
        ->postJson('/api/v1/inventory/transfer', [
            'source_warehouse_id' => $source->warehouse_id,
            'target_warehouse_id' => $target->warehouse_id,
            'product_id' => $product->id,
            'quantity' => 20,
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $source->warehouse_id,
        'product_id' => $product->id,
        'quantity' => 50,
    ]);
});

it('returns 401 for an unauthenticated transfer request', function () {
    $this->postJson('/api/v1/inventory/transfer', [
        'source_warehouse_id' => 1,
        'target_warehouse_id' => 2,
        'product_id' => 1,
        'quantity' => 5,
    ])->assertUnauthorized();
});
