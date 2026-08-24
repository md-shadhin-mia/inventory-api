<?php

/*
 * Phase 6 — Queued Event Listeners (Redis). Covers plan.md test #8 (event
 * dispatch + queue routing side). Written FIRST; drives:
 *   - App\Events\StockLevelChangedEvent gaining a trailing `public string $reason`
 *   - InventoryService::transferStock dispatching TWO events after commit
 *     (source `transfer_out`, target `transfer_in`)
 *   - App\Listeners\{AuditLogListener,LowStockAlertListener,InvalidateCacheListener}
 *     each implementing ShouldQueue on the `redis` connection
 *
 * Note: under QueueFake a CallQueuedListener's `connection` is ALWAYS null
 * (Dispatcher::propagateListenerOptions never assigns it), so the redis routing
 * is asserted statically off the listener instances instead.
 */

use App\Events\StockLevelChangedEvent;
use App\Listeners\AuditLogListener;
use App\Listeners\InvalidateCacheListener;
use App\Listeners\LowStockAlertListener;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

function eventQueueTokenFor(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
}

function seedEventQueueInventory(int $quantity = 10): Inventory
{
    return Inventory::factory()->create([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => Product::factory()->create(['reorder_threshold' => 1])->id,
        'quantity' => $quantity,
    ]);
}

it('dispatches StockLevelChangedEvent carrying the adjustment reason', function () {
    Event::fake([StockLevelChangedEvent::class]);

    $manager = User::factory()->warehouseManager()->create();
    $inventory = seedEventQueueInventory(10);

    $this->withHeaders(eventQueueTokenFor($manager))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => -7,
            'reason' => 'shrinkage',
        ])
        ->assertOk();

    Event::assertDispatched(
        StockLevelChangedEvent::class,
        fn (StockLevelChangedEvent $event) => $event->type === 'adjustment'
            && $event->reason === 'shrinkage'
    );
});

it('dispatches a transfer_out and a transfer_in event for a transfer', function () {
    Event::fake([StockLevelChangedEvent::class]);

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

    $this->withHeaders(eventQueueTokenFor($manager))
        ->postJson('/api/v1/inventory/transfer', [
            'source_warehouse_id' => $source->warehouse_id,
            'target_warehouse_id' => $target->warehouse_id,
            'product_id' => $product->id,
            'quantity' => 10,
        ])
        ->assertOk();

    Event::assertDispatchedTimes(StockLevelChangedEvent::class, 2);

    Event::assertDispatched(
        StockLevelChangedEvent::class,
        fn (StockLevelChangedEvent $event) => $event->type === 'transfer_out'
            && $event->warehouseId === $source->warehouse_id
            && $event->productId === $product->id
            && $event->oldBalance === 30
            && $event->newBalance === 20
    );

    Event::assertDispatched(
        StockLevelChangedEvent::class,
        fn (StockLevelChangedEvent $event) => $event->type === 'transfer_in'
            && $event->warehouseId === $target->warehouse_id
            && $event->productId === $product->id
            && $event->oldBalance === 20
            && $event->newBalance === 30
    );
});

it('pushes all three listeners onto the queue for an adjustment', function () {
    Queue::fake();

    $manager = User::factory()->warehouseManager()->create();
    $inventory = seedEventQueueInventory(10);

    $this->withHeaders(eventQueueTokenFor($manager))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => -7,
            'reason' => 'shrinkage',
        ])
        ->assertOk();

    Queue::assertPushed(
        CallQueuedListener::class,
        fn ($job) => $job->class === AuditLogListener::class
    );

    Queue::assertPushed(
        CallQueuedListener::class,
        fn ($job) => $job->class === LowStockAlertListener::class
    );

    Queue::assertPushed(
        CallQueuedListener::class,
        fn ($job) => $job->class === InvalidateCacheListener::class
    );

    Queue::assertPushedTimes(CallQueuedListener::class, 3);
});

it('routes its listeners to the redis queue connection', function (string $listener) {
    expect(new $listener)
        ->toBeInstanceOf(ShouldQueue::class)
        ->and((new $listener)->connection)
        ->toBe('redis');
})->with([
    AuditLogListener::class,
    LowStockAlertListener::class,
    InvalidateCacheListener::class,
]);

it('does not run the listeners inline while the queue is faked', function () {
    Queue::fake();

    $manager = User::factory()->warehouseManager()->create();
    $inventory = seedEventQueueInventory(10);

    $this->withHeaders(eventQueueTokenFor($manager))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => -7,
            'reason' => 'shrinkage',
        ])
        ->assertOk();

    // If the audit row appeared here, the service called the listener directly
    // instead of leaving the side effect to the queue.
    $this->assertDatabaseCount('inventory_transactions', 0);
});
