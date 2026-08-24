<?php

namespace App\Listeners;

use App\Events\StockLevelChangedEvent;
use App\Models\Inventory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

class InvalidateCacheListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The queue connection the listener is pushed onto.
     *
     * @var string
     */
    public $connection = 'redis';

    /**
     * Drop the cached summary for the affected warehouse/product pair.
     */
    public function handle(StockLevelChangedEvent $event): void
    {
        Cache::forget(Inventory::cacheKey($event->warehouseId, $event->productId));
    }
}
