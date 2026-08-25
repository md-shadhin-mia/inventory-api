<?php

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Cache;

function summaryCacheTokenFor(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
}

function summaryCacheKeyFor(int $warehouseId, int $productId): string
{
    return "inventory:warehouse:{$warehouseId}:product:{$productId}";
}

function seedSummaryCacheInventory(int $quantity = 10): Inventory
{
    return Inventory::factory()->create([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => Product::factory()->create(['reorder_threshold' => 1])->id,
        'quantity' => $quantity,
    ]);
}

it('caches the summary under a per pair key when both filters are given', function () {
    $user = User::factory()->warehouseManager()->create();
    $inventory = seedSummaryCacheInventory(10);
    seedSummaryCacheInventory(99);

    $key = summaryCacheKeyFor($inventory->warehouse_id, $inventory->product_id);

    expect(Cache::has($key))->toBeFalse();

    $this->withHeaders(summaryCacheTokenFor($user))
        ->getJson('/api/v1/inventory/summary?warehouse_id='.$inventory->warehouse_id.'&product_id='.$inventory->product_id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity' => 10,
        ]);

    expect(Cache::has($key))->toBeTrue();
});

it('leaves the per pair key absent for an unfiltered summary request', function () {
    $user = User::factory()->warehouseManager()->create();
    $inventory = seedSummaryCacheInventory(10);

    $this->withHeaders(summaryCacheTokenFor($user))
        ->getJson('/api/v1/inventory/summary')
        ->assertOk();

    expect(Cache::has(summaryCacheKeyFor($inventory->warehouse_id, $inventory->product_id)))->toBeFalse();
});

it('leaves the per pair key absent for a half filtered summary request', function () {
    $user = User::factory()->warehouseManager()->create();
    $inventory = seedSummaryCacheInventory(10);

    $key = summaryCacheKeyFor($inventory->warehouse_id, $inventory->product_id);

    $this->withHeaders(summaryCacheTokenFor($user))
        ->getJson('/api/v1/inventory/summary?warehouse_id='.$inventory->warehouse_id)
        ->assertOk();

    expect(Cache::has($key))->toBeFalse('A warehouse_id-only request must not write the per-pair key.');

    $this->withHeaders(summaryCacheTokenFor($user))
        ->getJson('/api/v1/inventory/summary?product_id='.$inventory->product_id)
        ->assertOk();

    expect(Cache::has($key))->toBeFalse('A product_id-only request must not write the per-pair key.');
});

it('forgets the cached pair when stock is adjusted', function () {
    $user = User::factory()->warehouseManager()->create();
    $inventory = seedSummaryCacheInventory(10);
    $key = summaryCacheKeyFor($inventory->warehouse_id, $inventory->product_id);

    $this->withHeaders(summaryCacheTokenFor($user))
        ->getJson('/api/v1/inventory/summary?warehouse_id='.$inventory->warehouse_id.'&product_id='.$inventory->product_id)
        ->assertOk();

    expect(Cache::has($key))->toBeTrue();

    $this->withHeaders(summaryCacheTokenFor($user))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => -7,
            'reason' => 'shrinkage',
        ])
        ->assertOk();

    expect(Cache::has($key))->toBeFalse();
});

it('serves the new balance on the next summary request after an adjustment', function () {
    $user = User::factory()->warehouseManager()->create();
    $inventory = seedSummaryCacheInventory(10);
    $query = '/api/v1/inventory/summary?warehouse_id='.$inventory->warehouse_id.'&product_id='.$inventory->product_id;

    $this->withHeaders(summaryCacheTokenFor($user))
        ->getJson($query)
        ->assertOk()
        ->assertJsonFragment(['quantity' => 10]);

    $this->withHeaders(summaryCacheTokenFor($user))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => -7,
            'reason' => 'shrinkage',
        ])
        ->assertOk();

    $this->withHeaders(summaryCacheTokenFor($user))
        ->getJson($query)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity' => 3,
        ]);
});

it('forgets both cached pairs when stock is transferred', function () {
    $user = User::factory()->warehouseManager()->create();
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

    $sourceKey = summaryCacheKeyFor($source->warehouse_id, $product->id);
    $targetKey = summaryCacheKeyFor($target->warehouse_id, $product->id);

    $this->withHeaders(summaryCacheTokenFor($user))
        ->getJson('/api/v1/inventory/summary?warehouse_id='.$source->warehouse_id.'&product_id='.$product->id)
        ->assertOk();

    $this->withHeaders(summaryCacheTokenFor($user))
        ->getJson('/api/v1/inventory/summary?warehouse_id='.$target->warehouse_id.'&product_id='.$product->id)
        ->assertOk();

    expect(Cache::has($sourceKey))->toBeTrue()
        ->and(Cache::has($targetKey))->toBeTrue();

    $this->withHeaders(summaryCacheTokenFor($user))
        ->postJson('/api/v1/inventory/transfer', [
            'source_warehouse_id' => $source->warehouse_id,
            'target_warehouse_id' => $target->warehouse_id,
            'product_id' => $product->id,
            'quantity' => 10,
        ])
        ->assertOk();

    expect(Cache::has($sourceKey))->toBeFalse()
        ->and(Cache::has($targetKey))->toBeFalse();
});
