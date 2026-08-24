<?php

namespace App\Services;

use App\Events\StockLevelChangedEvent;
use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use App\Services\Contracts\InventoryServiceInterface;
use Illuminate\Support\Facades\Cache;
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
            $reason,
        );

        return $inventory;
    }

    public function transferStock(int $userId, int $sourceWarehouseId, int $targetWarehouseId, int $productId, int $quantity): mixed
    {
        [$source, $target, $sourceOldBalance, $targetOldBalance] = DB::transaction(function () use ($sourceWarehouseId, $targetWarehouseId, $productId, $quantity) {
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

            $sourceOldBalance = $source->quantity;
            $targetOldBalance = $target->quantity;

            $source->update(['quantity' => $sourceOldBalance - $quantity]);
            $target->update(['quantity' => $targetOldBalance + $quantity]);

            return [$source, $target, $sourceOldBalance, $targetOldBalance];
        });

        // Dispatched only after the transaction has committed.
        StockLevelChangedEvent::dispatch(
            $userId,
            $sourceWarehouseId,
            $productId,
            $sourceOldBalance,
            $source->quantity,
            'transfer_out',
            "Transfer out to warehouse {$targetWarehouseId}",
        );

        StockLevelChangedEvent::dispatch(
            $userId,
            $targetWarehouseId,
            $productId,
            $targetOldBalance,
            $target->quantity,
            'transfer_in',
            "Transfer in from warehouse {$sourceWarehouseId}",
        );

        return ['source' => $source, 'target' => $target];
    }

    public function getStockSummary(?int $warehouseId = null, ?int $productId = null): mixed
    {
        if ($warehouseId !== null && $productId !== null) {
            return Cache::remember(
                Inventory::cacheKey($warehouseId, $productId),
                3600,
                fn () => Inventory::query()
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->orderBy('id')
                    ->get(['warehouse_id', 'product_id', 'quantity']),
            );
        }

        return Inventory::query()
            ->orderBy('id')
            ->get(['warehouse_id', 'product_id', 'quantity']);
    }
}
