<?php

namespace App\Services\Contracts;

interface InventoryServiceInterface
{
    public function adjustStock(int $userId, int $warehouseId, int $productId, int $quantityDelta, string $reason): mixed;

    public function transferStock(int $userId, int $sourceWarehouseId, int $targetWarehouseId, int $productId, int $quantity): mixed;

    public function getStockSummary(?int $warehouseId = null, ?int $productId = null): mixed;
}
