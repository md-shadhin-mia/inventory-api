<?php

namespace Database\Seeders;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->seedUsers();

        [$main, $overflow] = $this->seedWarehouses();
        [$widget, $gadget, $sprocket] = $this->seedProducts();

        Inventory::create(['warehouse_id' => $main->id, 'product_id' => $widget->id, 'quantity' => 100]);

        Inventory::create(['warehouse_id' => $main->id, 'product_id' => $gadget->id, 'quantity' => 25]);

        Inventory::create(['warehouse_id' => $main->id, 'product_id' => $sprocket->id, 'quantity' => 60]);
        Inventory::create(['warehouse_id' => $overflow->id, 'product_id' => $sprocket->id, 'quantity' => 15]);
        Inventory::create(['warehouse_id' => $overflow->id, 'product_id' => $widget->id, 'quantity' => 40]);
    }

    private function seedUsers(): void
    {
        User::factory()->admin()->create([
            'name' => 'Ada Admin',
            'email' => 'admin@example.com',
        ]);

        User::factory()->warehouseManager()->create([
            'name' => 'Mo Manager',
            'email' => 'manager@example.com',
        ]);

        User::factory()->auditor()->create([
            'name' => 'Avi Auditor',
            'email' => 'auditor@example.com',
        ]);
    }

    private function seedWarehouses(): array
    {
        return [
            Warehouse::create(['name' => 'Main Warehouse']),
            Warehouse::create(['name' => 'Overflow Warehouse']),
        ];
    }

    private function seedProducts(): array
    {
        return [
            Product::create(['name' => 'Widget', 'sku' => 'SKU-WIDGET-0001', 'reorder_threshold' => 20]),
            Product::create(['name' => 'Gadget', 'sku' => 'SKU-GADGET-0002', 'reorder_threshold' => 10]),
            Product::create(['name' => 'Sprocket', 'sku' => 'SKU-SPROCKET-0003', 'reorder_threshold' => 5]),
        ];
    }
}
