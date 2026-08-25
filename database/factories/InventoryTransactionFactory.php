<?php

namespace Database\Factories;

use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryTransactionFactory extends Factory
{
    protected $model = InventoryTransaction::class;

    public function definition(): array
    {
        $old = fake()->numberBetween(0, 200);
        $delta = fake()->numberBetween(-50, 50);

        return [
            'user_id' => User::factory(),
            'warehouse_id' => Warehouse::factory(),
            'product_id' => Product::factory(),
            'old_balance' => $old,
            'new_balance' => $old + $delta,
            'quantity_delta' => $delta,
            'type' => 'adjustment',
            'reason' => fake()->sentence(3),
            'created_at' => now(),
        ];
    }
}
