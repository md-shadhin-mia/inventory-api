<?php

/*
 * Phase 5 test #7 (mutation half), written FIRST — drives:
 *   - `inventory-mutation` registered in AppServiceProvider::boot()
 *   - 60 requests / minute, keyed by the AUTHENTICATED USER ID
 *   - applied as `throttle:inventory-mutation` on the stock write routes
 *     (POST /api/v1/inventory/adjust, POST /api/v1/inventory/transfer)
 *   - 429 body { "message": "Too many requests", "retry_after": N }
 *     plus a `Retry-After` header
 *   - read endpoints (summary, audit) are NOT throttled by this limiter
 */

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;

beforeEach(function () {
    flushRateLimiterState();
});

function mutationHeadersFor(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
}

function seedMutationInventory(int $quantity = 100000): Inventory
{
    return Inventory::factory()->create([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => Product::factory()->create()->id,
        'quantity' => $quantity,
    ]);
}

it('allows sixty stock mutations per minute for one user', function () {
    $manager = User::factory()->warehouseManager()->create();
    $inventory = seedMutationInventory();
    $headers = mutationHeadersFor($manager);

    foreach (range(1, 60) as $attempt) {
        $this->withHeaders($headers)
            ->postJson('/api/v1/inventory/adjust', [
                'warehouse_id' => $inventory->warehouse_id,
                'product_id' => $inventory->product_id,
                'quantity_delta' => 1,
                'reason' => "bulk adjustment {$attempt}",
            ])
            ->assertOk();
    }

    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $inventory->warehouse_id,
        'product_id' => $inventory->product_id,
        'quantity' => 100060,
    ]);
});

it('blocks the sixty first stock mutation within a minute with 429 and a Retry-After header', function () {
    $manager = User::factory()->warehouseManager()->create();
    $inventory = seedMutationInventory();
    $headers = mutationHeadersFor($manager);

    foreach (range(1, 60) as $attempt) {
        $this->withHeaders($headers)
            ->postJson('/api/v1/inventory/adjust', [
                'warehouse_id' => $inventory->warehouse_id,
                'product_id' => $inventory->product_id,
                'quantity_delta' => 1,
                'reason' => "bulk adjustment {$attempt}",
            ])
            ->assertOk();
    }

    $response = $this->withHeaders($headers)
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => 1,
            'reason' => 'one too many',
        ]);

    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('message', 'Too many requests');

    expect($response->json('retry_after'))->toBeInt()->toBeGreaterThan(0);
    expect((int) $response->headers->get('Retry-After'))
        ->toBe($response->json('retry_after'));

    // The throttled request must not have touched the balance.
    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $inventory->warehouse_id,
        'product_id' => $inventory->product_id,
        'quantity' => 100060,
    ]);
});

it('shares the mutation quota between adjust and transfer for the same user', function () {
    $manager = User::factory()->warehouseManager()->create();
    $headers = mutationHeadersFor($manager);

    $product = Product::factory()->create();
    $source = Warehouse::factory()->create();
    $target = Warehouse::factory()->create();

    Inventory::factory()->create([
        'warehouse_id' => $source->id,
        'product_id' => $product->id,
        'quantity' => 100000,
    ]);
    Inventory::factory()->create([
        'warehouse_id' => $target->id,
        'product_id' => $product->id,
        'quantity' => 0,
    ]);

    foreach (range(1, 60) as $attempt) {
        $this->withHeaders($headers)
            ->postJson('/api/v1/inventory/adjust', [
                'warehouse_id' => $source->id,
                'product_id' => $product->id,
                'quantity_delta' => 1,
                'reason' => "bulk adjustment {$attempt}",
            ])
            ->assertOk();
    }

    $this->withHeaders($headers)
        ->postJson('/api/v1/inventory/transfer', [
            'source_warehouse_id' => $source->id,
            'target_warehouse_id' => $target->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ])
        ->assertStatus(429);
});

it('keys the mutation limiter per user so another user is not blocked', function () {
    $inventory = seedMutationInventory();

    $first = User::factory()->warehouseManager()->create();
    $firstHeaders = mutationHeadersFor($first);

    foreach (range(1, 60) as $attempt) {
        $this->withHeaders($firstHeaders)
            ->postJson('/api/v1/inventory/adjust', [
                'warehouse_id' => $inventory->warehouse_id,
                'product_id' => $inventory->product_id,
                'quantity_delta' => 1,
                'reason' => "bulk adjustment {$attempt}",
            ])
            ->assertOk();
    }

    $this->withHeaders($firstHeaders)
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => 1,
            'reason' => 'exhausted',
        ])
        ->assertStatus(429);

    $second = User::factory()->admin()->create();

    $this->withHeaders(mutationHeadersFor($second))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => 1,
            'reason' => 'fresh quota',
        ])
        ->assertOk();
});

it('does not apply the mutation limiter to read endpoints', function () {
    $admin = User::factory()->admin()->create();
    $headers = mutationHeadersFor($admin);
    seedMutationInventory();

    foreach (range(1, 65) as $attempt) {
        $this->withHeaders($headers)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk();
    }
});
