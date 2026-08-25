<?php

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;

function summaryTokenFor(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
}

it('returns stock levels per warehouse and product', function () {
    $user = User::factory()->warehouseManager()->create();

    $w1 = Warehouse::factory()->create();
    $w2 = Warehouse::factory()->create();
    $product = Product::factory()->create();

    Inventory::factory()->create(['warehouse_id' => $w1->id, 'product_id' => $product->id, 'quantity' => 42]);
    Inventory::factory()->create(['warehouse_id' => $w2->id, 'product_id' => $product->id, 'quantity' => 7]);

    $this->withHeaders(summaryTokenFor($user))
        ->getJson('/api/v1/inventory/summary')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['warehouse_id' => $w1->id, 'product_id' => $product->id, 'quantity' => 42])
        ->assertJsonFragment(['warehouse_id' => $w2->id, 'product_id' => $product->id, 'quantity' => 7]);
});

it('is readable by every authenticated role', function (string $state) {
    $user = User::factory()->{$state}()->create();
    Inventory::factory()->create(['quantity' => 5]);

    $this->withHeaders(summaryTokenFor($user))
        ->getJson('/api/v1/inventory/summary')
        ->assertOk()
        ->assertJsonStructure(['data']);
})->with(['admin', 'warehouseManager', 'auditor']);

it('filters the summary to one warehouse when only warehouse_id is given', function () {
    $user = User::factory()->warehouseManager()->create();

    $w1 = Warehouse::factory()->create();
    $w2 = Warehouse::factory()->create();
    $product = Product::factory()->create();

    Inventory::factory()->create(['warehouse_id' => $w1->id, 'product_id' => $product->id, 'quantity' => 42]);
    Inventory::factory()->create(['warehouse_id' => $w2->id, 'product_id' => $product->id, 'quantity' => 7]);

    $this->withHeaders(summaryTokenFor($user))
        ->getJson('/api/v1/inventory/summary?warehouse_id='.$w1->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment(['warehouse_id' => $w1->id, 'product_id' => $product->id, 'quantity' => 42])
        ->assertJsonMissingExact(['warehouse_id' => $w2->id, 'product_id' => $product->id, 'quantity' => 7]);
});

it('filters the summary to one product when only product_id is given', function () {
    $user = User::factory()->warehouseManager()->create();

    $warehouse = Warehouse::factory()->create();
    $p1 = Product::factory()->create();
    $p2 = Product::factory()->create();

    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $p1->id, 'quantity' => 42]);
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $p2->id, 'quantity' => 7]);

    $this->withHeaders(summaryTokenFor($user))
        ->getJson('/api/v1/inventory/summary?product_id='.$p1->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment(['warehouse_id' => $warehouse->id, 'product_id' => $p1->id, 'quantity' => 42])
        ->assertJsonMissingExact(['warehouse_id' => $warehouse->id, 'product_id' => $p2->id, 'quantity' => 7]);
});

it('returns 401 for unauthenticated summary requests', function () {
    $this->getJson('/api/v1/inventory/summary')->assertUnauthorized();
});
