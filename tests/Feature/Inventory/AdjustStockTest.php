<?php

/*
 * Phase 4 tests #3 (adjust stock) and #4 (negative stock protection),
 * written FIRST — they drive:
 *   - POST /api/v1/inventory/adjust (auth:sanctum + role:admin,warehouse_manager)
 *   - `inventories` table/model (warehouse_id, product_id, quantity)
 *   - App\Events\StockLevelChangedEvent dispatched after commit with payload
 *     userId, warehouseId, productId, oldBalance, newBalance, type
 *
 * Chosen contract (asserted consistently across the suite):
 *   - success        → 200 with { data: { warehouse_id, product_id, quantity } }
 *   - insufficient   → 422 (Unprocessable) with { message }, stock unchanged
 */

use App\Events\StockLevelChangedEvent;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Event;

function adjustTokenFor(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
}

function seedAdjustInventory(int $quantity = 10): Inventory
{
    return Inventory::factory()->create([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => Product::factory()->create()->id,
        'quantity' => $quantity,
    ]);
}

it('lets a warehouse manager increase stock and returns the new balance', function () {
    $manager = User::factory()->warehouseManager()->create();
    $inventory = seedAdjustInventory(10);

    $response = $this->withHeaders(adjustTokenFor($manager))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => 15,
            'reason' => 'restock delivery',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.warehouse_id', $inventory->warehouse_id)
        ->assertJsonPath('data.product_id', $inventory->product_id)
        ->assertJsonPath('data.quantity', 25);

    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $inventory->warehouse_id,
        'product_id' => $inventory->product_id,
        'quantity' => 25,
    ]);
});

it('lets an admin decrease stock when the balance is sufficient', function () {
    $admin = User::factory()->admin()->create();
    $inventory = seedAdjustInventory(10);

    $this->withHeaders(adjustTokenFor($admin))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => -7,
            'reason' => 'damaged goods write-off',
        ])
        ->assertOk()
        ->assertJsonPath('data.quantity', 3);

    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $inventory->warehouse_id,
        'product_id' => $inventory->product_id,
        'quantity' => 3,
    ]);
});

it('dispatches StockLevelChangedEvent with old and new balance after an adjustment', function () {
    Event::fake([StockLevelChangedEvent::class]);

    $manager = User::factory()->warehouseManager()->create();
    $inventory = seedAdjustInventory(10);

    $this->withHeaders(adjustTokenFor($manager))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => -7,
            'reason' => 'shrinkage',
        ])
        ->assertOk();

    Event::assertDispatched(StockLevelChangedEvent::class, function (StockLevelChangedEvent $event) use ($manager, $inventory) {
        return $event->userId === $manager->id
            && $event->warehouseId === $inventory->warehouse_id
            && $event->productId === $inventory->product_id
            && $event->oldBalance === 10
            && $event->newBalance === 3
            && $event->type === 'adjustment';
    });
});

it('rejects with 422 an adjustment that would drive stock below zero and leaves stock unchanged', function () {
    Event::fake([StockLevelChangedEvent::class]);

    $manager = User::factory()->warehouseManager()->create();
    $inventory = seedAdjustInventory(10);

    $this->withHeaders(adjustTokenFor($manager))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => -11,
            'reason' => 'oversized write-off',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['message']);

    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $inventory->warehouse_id,
        'product_id' => $inventory->product_id,
        'quantity' => 10,
    ]);

    Event::assertNotDispatched(StockLevelChangedEvent::class);
});

it('forbids an auditor from adjusting stock with 403', function () {
    $auditor = User::factory()->auditor()->create();
    $inventory = seedAdjustInventory(10);

    $this->withHeaders(adjustTokenFor($auditor))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => 5,
            'reason' => 'should never happen',
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $inventory->warehouse_id,
        'product_id' => $inventory->product_id,
        'quantity' => 10,
    ]);
});

it('returns 401 for an unauthenticated adjust request', function () {
    $this->postJson('/api/v1/inventory/adjust', [
        'warehouse_id' => 1,
        'product_id' => 1,
        'quantity_delta' => 5,
        'reason' => 'no token',
    ])->assertUnauthorized();
});
