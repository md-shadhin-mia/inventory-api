<?php

namespace App\Listeners;

use App\Events\StockLevelChangedEvent;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LowStockAlertListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The queue connection the listener is pushed onto.
     *
     * @var string
     */
    public $connection = 'redis';

    /**
     * Log a low stock warning, at most once per hour per warehouse/product pair.
     */
    public function handle(StockLevelChangedEvent $event): void
    {
        $product = Product::find($event->productId);

        if (! $product || $event->newBalance >= $product->reorder_threshold) {
            return;
        }

        // Atomic set-if-absent: the first breach inside the window wins.
        if (! Cache::add("low-stock:{$event->warehouseId}:{$event->productId}", true, 3600)) {
            return;
        }

        Log::warning('Low stock threshold breached', [
            'warehouse_id' => $event->warehouseId,
            'product_id' => $event->productId,
            'new_balance' => $event->newBalance,
            'reorder_threshold' => $product->reorder_threshold,
        ]);
    }
}
