<?php

namespace Database\Seeders;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Demo dataset for local development and the README walkthrough.
 *
 * Every user's password is "password". Product reorder thresholds are set
 * explicitly (ProductFactory randomises them 5..50) so the low-stock alert is
 * predictable when demoing.
 *
 * NOTE: this seeder uses model factories, which depend on fakerphp/faker — a
 * require-dev package. It cannot run in the --no-dev production image; use the
 * `dev` build target (BUILD_TARGET=dev) when seeding in Docker.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->seedUsers();

        [$main, $overflow] = $this->seedWarehouses();
        [$widget, $gadget, $sprocket] = $this->seedProducts();

        // The plan.md demo scenario: stock = 100 in the main warehouse, so two
        // simultaneous -70 adjustments can be raced (one wins at 30, one is
        // rejected with 422).
        Inventory::create(['warehouse_id' => $main->id, 'product_id' => $widget->id, 'quantity' => 100]);

        // Sits above its threshold of 10 — adjust it down to trip the low-stock alert.
        Inventory::create(['warehouse_id' => $main->id, 'product_id' => $gadget->id, 'quantity' => 25]);

        // Present in both warehouses, so transfers have somewhere to go.
        Inventory::create(['warehouse_id' => $main->id, 'product_id' => $sprocket->id, 'quantity' => 60]);
        Inventory::create(['warehouse_id' => $overflow->id, 'product_id' => $sprocket->id, 'quantity' => 15]);
        Inventory::create(['warehouse_id' => $overflow->id, 'product_id' => $widget->id, 'quantity' => 40]);
    }

    /** One user per role, so the RBAC matrix can be exercised end to end. */
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

    /** @return array{0: Warehouse, 1: Warehouse} */
    private function seedWarehouses(): array
    {
        return [
            Warehouse::create(['name' => 'Main Warehouse']),
            Warehouse::create(['name' => 'Overflow Warehouse']),
        ];
    }

    /** @return array{0: Product, 1: Product, 2: Product} */
    private function seedProducts(): array
    {
        return [
            Product::create(['name' => 'Widget', 'sku' => 'SKU-WIDGET-0001', 'reorder_threshold' => 20]),
            Product::create(['name' => 'Gadget', 'sku' => 'SKU-GADGET-0002', 'reorder_threshold' => 10]),
            Product::create(['name' => 'Sprocket', 'sku' => 'SKU-SPROCKET-0003', 'reorder_threshold' => 5]),
        ];
    }
}
