<?php

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

function lowStockTokenFor(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
}

function seedLowStockInventory(int $quantity, int $threshold): Inventory
{
    return Inventory::factory()->create([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => Product::factory()->create(['reorder_threshold' => $threshold])->id,
        'quantity' => $quantity,
    ]);
}

function lowStockCooldownKey(Inventory $inventory): string
{
    return "low-stock:{$inventory->warehouse_id}:{$inventory->product_id}";
}

function adjustLowStock(object $test, User $user, Inventory $inventory, int $delta): void
{
    $test->withHeaders(lowStockTokenFor($user))
        ->postJson('/api/v1/inventory/adjust', [
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'quantity_delta' => $delta,
            'reason' => 'shrinkage',
        ])
        ->assertOk();
}

it('logs a warning when the new balance falls below the reorder threshold', function () {
    Log::spy();

    $manager = User::factory()->warehouseManager()->create();
    $inventory = seedLowStockInventory(quantity: 10, threshold: 5);

    adjustLowStock($this, $manager, $inventory, -7);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use ($inventory) {
            return $message === 'Low stock threshold breached'
                && $context['warehouse_id'] === $inventory->warehouse_id
                && $context['product_id'] === $inventory->product_id
                && $context['new_balance'] === 3
                && $context['reorder_threshold'] === 5;
        })
        ->once();
});

it('logs no warning when the balance stays at or above the reorder threshold', function () {
    Log::spy();

    $manager = User::factory()->warehouseManager()->create();
    $inventory = seedLowStockInventory(quantity: 10, threshold: 5);

    adjustLowStock($this, $manager, $inventory, -5);

    Log::shouldNotHaveReceived('warning');

    expect(Cache::has(lowStockCooldownKey($inventory)))->toBeFalse();
});

it('logs only once for two sub-threshold adjustments inside the cooldown window', function () {
    Log::spy();

    $manager = User::factory()->warehouseManager()->create();
    $inventory = seedLowStockInventory(quantity: 10, threshold: 5);

    adjustLowStock($this, $manager, $inventory, -7);
    adjustLowStock($this, $manager, $inventory, -1);

    Log::shouldHaveReceived('warning')->once();

    expect(Cache::has(lowStockCooldownKey($inventory)))->toBeTrue();
});

it('logs again once the one hour cooldown has expired', function () {
    Log::spy();

    $manager = User::factory()->warehouseManager()->create();
    $inventory = seedLowStockInventory(quantity: 10, threshold: 5);

    adjustLowStock($this, $manager, $inventory, -7);

    $this->travel(61)->minutes();

    adjustLowStock($this, $manager, $inventory, -1);

    Log::shouldHaveReceived('warning')->twice();
});

it('alerts independently for a different warehouse and product pair', function () {
    Log::spy();

    $manager = User::factory()->warehouseManager()->create();
    $first = seedLowStockInventory(quantity: 10, threshold: 5);
    $second = seedLowStockInventory(quantity: 10, threshold: 5);

    adjustLowStock($this, $manager, $first, -7);
    adjustLowStock($this, $manager, $second, -7);

    Log::shouldHaveReceived('warning')->twice();

    expect(Cache::has(lowStockCooldownKey($first)))->toBeTrue()
        ->and(Cache::has(lowStockCooldownKey($second)))->toBeTrue();
});
