<?php

namespace App\Models;

use Database\Factories\InventoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['warehouse_id', 'product_id', 'quantity'])]
class Inventory extends Model
{

    use HasFactory;

    public static function cacheKey(int $warehouseId, int $productId): string
    {
        return "inventory:warehouse:{$warehouseId}:product:{$productId}";
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }
}
