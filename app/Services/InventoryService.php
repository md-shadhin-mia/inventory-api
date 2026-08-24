<?php

namespace App\Services;

use App\Events\StockLevelChangedEvent;
use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use App\Services\Contracts\InventoryServiceInterface;
use Illuminate\Support\Facades\DB;

class InventoryService implements InventoryServiceInterface
{
    public function adjustStock(int $userId, int $warehouseId, int $productId, int $quantityDelta, string $reason): mixed
    {
        [$inventory, $oldBalance] = DB::transaction(function () use ($warehouseId, $productId, $quantityDelta) {
            $inventory = Inventory::where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->firstOrFail();

            $oldBalance = $inventory->quantity;
            $newBalance = $oldBalance + $quantityDelta;

            if ($newBalance < 0) {
                throw new InsufficientStockException('Insufficient stock for this adjustment.');
            }

            $inventory->update(['quantity' => $newBalance]);

            return [$inventory, $oldBalance];
        });

        // Dispatched only after the transaction has committed.
        StockLevelChangedEvent::dispatch(
            $userId,
            $warehouseId,
            $productId,
            $oldBalance,
            $inventory->quantity,
            'adjustment',
        );

        return $inventory;
    }

    public function transferStock(int $userId, int $sourceWarehouseId, int $targetWarehouseId, int $productId, int $quantity): mixed
    {
        return DB::transaction(function () use ($sourceWarehouseId, $targetWarehouseId, $productId, $quantity) {
            // Deterministic lock order (lower warehouse_id first) to avoid deadlocks.
            [$firstWarehouseId, $secondWarehouseId] = $sourceWarehouseId < $targetWarehouseId
                ? [$sourceWarehouseId, $targetWarehouseId]
                : [$targetWarehouseId, $sourceWarehouseId];

            $first = Inventory::where('warehouse_id', $firstWarehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->firstOrFail();

            $second = Inventory::where('warehouse_id', $secondWarehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->firstOrFail();

            $source = $first->warehouse_id === $sourceWarehouseId ? $first : $second;
            $target = $first->warehouse_id === $sourceWarehouseId ? $second : $first;

            if ($source->quantity - $quantity < 0) {
                throw new InsufficientStockException('Insufficient stock in source warehouse for this transfer.');
            }

            $source->update(['quantity' => $source->quantity - $quantity]);
            $target->update(['quantity' => $target->quantity + $quantity]);

            // Phase 6: dispatch StockLevelChangedEvent for both rows.

            return ['source' => $source, 'target' => $target];
        });
    }

    public function getStockSummary(): mixed
    {
        return Inventory::query()
            ->orderBy('id')
            ->get(['warehouse_id', 'product_id', 'quantity']);
    }
}
