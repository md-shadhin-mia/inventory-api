<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class StockLevelChangedEvent
{
    use Dispatchable;

    public function __construct(
        public int $userId,
        public int $warehouseId,
        public int $productId,
        public int $oldBalance,
        public int $newBalance,
        public string $type,
        public string $reason = '',
    ) {}
}
