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

/*
 * Phase 7 (A2) — pin the transaction BOUNDARY, not just the happy path.
 *
 * Every other failure path above throws before the first write, so they stay
 * green even with DB::transaction() deleted. This one injects a failure
 * BETWEEN the two writes via a model event listener scoped to the target row:
 * the source decrement has already been issued when the target update blows
 * up, so only a real transaction can restore the source balance.
 *
 * The listener is registered inside the test body and flushed in a finally so
 * it cannot leak into sibling tests sharing this file's model dispatcher.
 */
it('rolls the source decrement back when the target write fails mid transfer', function () {
    $this->withoutExceptionHandling();

    $manager = User::factory()->warehouseManager()->create();
    ['product' => $product, 'source' => $source, 'target' => $target] = seedTransferPair(50, 5);

    $targetWarehouseId = (int) $target->warehouse_id;

    Inventory::updating(function (Inventory $inventory) use ($targetWarehouseId) {
        if ((int) $inventory->warehouse_id === $targetWarehouseId) {
            throw new RuntimeException('injected failure on target row update');
        }
    });

    try {
        expect(fn () => $this->withHeaders(transferTokenFor($manager))
            ->postJson('/api/v1/inventory/transfer', [
                'source_warehouse_id' => $source->warehouse_id,
                'target_warehouse_id' => $target->warehouse_id,
                'product_id' => $product->id,
                'quantity' => 20,
            ]))->toThrow(RuntimeException::class, 'injected failure on target row update');
    } finally {
        Inventory::flushEventListeners();
    }

    // THE assertion: the source write happened before the failure, so if the
    // DB::transaction() wrapper is removed this row reads 30.
    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $source->warehouse_id,
        'product_id' => $product->id,
        'quantity' => 50,
    ]);

    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $target->warehouse_id,
        'product_id' => $product->id,
        'quantity' => 5,
    ]);

    // Events are dispatched only after commit, so no audit row may exist.
    $this->assertDatabaseCount('inventory_transactions', 0);
});

/*
 * Phase 7 (A5) — a valid warehouse + product with no `inventories` row.
 * The service's lockForUpdate()->firstOrFail() surfaces as 404 (decision:
 * keep the 404, pin it).
 */
it('returns 404 when the source warehouse has no inventory row for the product', function () {
    $manager = User::factory()->warehouseManager()->create();

    $product = Product::factory()->create();
    $sourceWarehouse = Warehouse::factory()->create();

    $target = Inventory::factory()->create([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => $product->id,
        'quantity' => 5,
    ]);

    $this->assertDatabaseMissing('inventories', [
        'warehouse_id' => $sourceWarehouse->id,
        'product_id' => $product->id,
    ]);

    $this->withHeaders(transferTokenFor($manager))
        ->postJson('/api/v1/inventory/transfer', [
            'source_warehouse_id' => $sourceWarehouse->id,
            'target_warehouse_id' => $target->warehouse_id,
            'product_id' => $product->id,
            'quantity' => 1,
        ])
        ->assertNotFound();

    $this->assertDatabaseCount('inventory_transactions', 0);

    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $target->warehouse_id,
        'product_id' => $product->id,
        'quantity' => 5,
    ]);
});
